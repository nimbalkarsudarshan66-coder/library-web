<?php
define('SARASWATI_INIT', true);
require_once __DIR__ . '/config.php';

// Enable mysqli exceptions so errors throw rather than silently fail
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// ── Secure DB connection ─────────────────────────────────────────────────────
try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, '', DB_PORT);
    $conn->set_charset(DB_CHARSET);
} catch (Throwable $e) {
    error_log('[Saraswati DB] Connection failed: ' . $e->getMessage());
    http_response_code(503);
    die(json_encode(['ok' => false, 'message' => 'Database unavailable. Please try again later.']));
}

// ── Create or select database ─────────────────────────────────────────────────────
try {
    $dbname = DB_NAME;
    $result = $conn->query("SHOW DATABASES LIKE '$dbname'");
    if ($result && $result->num_rows === 0) {
        $conn->query("CREATE DATABASE `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }
    $conn->select_db($dbname);
} catch (Throwable $e) {
    error_log('[Saraswati DB] DB select failed: ' . $e->getMessage());
    http_response_code(503);
    die(json_encode(['ok' => false, 'message' => 'Database setup error. Please contact support.']));
}

// ── Create all tables ─────────────────────────────────────────────────────────
$conn->multi_query("
CREATE TABLE IF NOT EXISTS users (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name     VARCHAR(120)  NOT NULL,
  email         VARCHAR(160)  NOT NULL UNIQUE,
  phone         VARCHAR(20),
  password_hash VARCHAR(255)  NOT NULL,
  role          ENUM('user','admin') NOT NULL DEFAULT 'user',
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_email (email)
);
CREATE TABLE IF NOT EXISTS study_tables (
  id       INT UNSIGNED PRIMARY KEY,
  zone     ENUM('window','silent','group','power') NOT NULL,
  seats    TINYINT UNSIGNED NOT NULL,
  status   ENUM('available','booked','maintenance') NOT NULL DEFAULT 'available',
  features VARCHAR(255) NOT NULL
);
CREATE TABLE IF NOT EXISTS subscription_plans (
  id               TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name             VARCHAR(40) NOT NULL,
  duration_months  INT NOT NULL,
  price_inr        INT NOT NULL
);
CREATE TABLE IF NOT EXISTS reservations (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id               BIGINT UNSIGNED NULL,
  table_id              INT UNSIGNED NOT NULL,
  booking_date          DATE NOT NULL,
  time_slot             VARCHAR(60) NOT NULL,
  plan_id               TINYINT UNSIGNED NULL,
  amount_inr            INT NOT NULL DEFAULT 0,
  payment_status        ENUM('pending','pending_verification','paid','failed','refunded','expired') DEFAULT 'pending',
  reservation_status    ENUM('reserved','cancelled','completed') DEFAULT 'reserved',
  phonepe_transaction_id VARCHAR(120),
  order_ref             VARCHAR(30) UNIQUE,
  expires_at            TIMESTAMP NULL,
  created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_table_slot (table_id, booking_date, time_slot),
  FOREIGN KEY (table_id) REFERENCES study_tables(id) ON DELETE CASCADE,
  INDEX idx_order_ref (order_ref),
  INDEX idx_payment_status (payment_status)
);
CREATE TABLE IF NOT EXISTS upi_transactions (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reservation_id BIGINT UNSIGNED NOT NULL,
  order_ref      VARCHAR(30) NOT NULL,
  utr_number     VARCHAR(50)  NULL,
  upi_app        VARCHAR(30)  NULL,
  amount_inr     INT NOT NULL,
  status         ENUM('initiated','utr_submitted','verified','failed','expired') DEFAULT 'initiated',
  admin_notes    TEXT NULL,
  verified_by    BIGINT UNSIGNED NULL,
  verified_at    TIMESTAMP NULL,
  ip_address     VARCHAR(45) NOT NULL DEFAULT '',
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_utr (utr_number),
  INDEX idx_status (status),
  INDEX idx_order_ref (order_ref)
);
CREATE TABLE IF NOT EXISTS rate_limits (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip_address  VARCHAR(45) NOT NULL,
  action_key  VARCHAR(60) NOT NULL,
  request_count INT UNSIGNED NOT NULL DEFAULT 1,
  window_start  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_ip_action (ip_address, action_key)
);
CREATE TABLE IF NOT EXISTS admin_permissions (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_user_id   BIGINT UNSIGNED NOT NULL,
  permission_key  VARCHAR(80) NOT NULL,
  is_allowed      TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uniq_admin_perm (admin_user_id, permission_key)
);
");
while ($conn->more_results() && $conn->next_result()) {}

// ── Seed study tables ─────────────────────────────────────────────────────────
$seed = (int)($conn->query("SELECT COUNT(*) c FROM study_tables")->fetch_assoc()['c'] ?? 0);
if ($seed === 0) {
    $stmt = $conn->prepare("INSERT INTO study_tables (id, zone, seats, status, features) VALUES (?, ?, ?, ?, ?)");
    for ($i = 1; $i <= 39; $i++) {
        $zone    = $i <= 10 ? 'window' : ($i <= 22 ? 'silent' : ($i <= 30 ? 'group' : 'power'));
        $seats   = $zone === 'silent' ? 1 : ($zone === 'group' ? 4 : 2);
        $status  = in_array($i, [3,6,12,15,18,19,23,26,27,33,36]) ? 'booked'
                 : (in_array($i, [9,30]) ? 'maintenance' : 'available');
        $features = 'AC room, spotlight, free WiFi, laptop charging point'
                  . ($zone === 'window' ? ', window side'      : '')
                  . ($zone === 'silent' ? ', silent study'     : '')
                  . ($zone === 'group'  ? ', group discussion' : '')
                  . ($zone === 'power'  ? ', power zone'       : '');
        $stmt->bind_param('isiss', $i, $zone, $seats, $status, $features);
        $stmt->execute();
    }
}

// ── Seed subscription plans ───────────────────────────────────────────────────
$conn->query("INSERT IGNORE INTO subscription_plans (id,name,duration_months,price_inr) VALUES
  (1,'1 Month',1,1000),(2,'3 Months',3,2700),(3,'1 Year',12,10000)");

// ── Seed admin user (change password in production!) ─────────────────────────
$adminHash  = password_hash('admin123', PASSWORD_DEFAULT);
$adminStmt  = $conn->prepare("INSERT IGNORE INTO users (id, full_name, email, phone, password_hash, role) VALUES (1,?,?,?,?,'admin')");
$adminName  = 'Library Admin';
$adminEmail = 'admin@saraswati.local';
$adminPhone = '9999999999';
$adminStmt->bind_param('ssss', $adminName, $adminEmail, $adminPhone, $adminHash);
$adminStmt->execute();

// ── Auto-expire stale pending reservations ────────────────────────────────────
$conn->query("
  UPDATE reservations
     SET payment_status = 'expired',
         reservation_status = 'cancelled'
   WHERE payment_status IN ('pending', 'pending_verification')
     AND expires_at IS NOT NULL
     AND expires_at < NOW()
");
$conn->query("
  UPDATE upi_transactions
     SET status = 'expired'
   WHERE status = 'initiated'
     AND created_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
");
