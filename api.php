<?php
/**
 * api.php — Saraswati Library REST API
 *
 * SECURITY LAYERS:
 *  1. Security response headers on every request
 *  2. CORS locked to ALLOWED_ORIGIN (not wildcard on live)
 *  3. PHP session-based CSRF token validation on state-changing POST requests
 *  4. IP-based rate limiting via DB (prevents brute force + abuse)
 *  5. All inputs sanitised + validated; all queries use prepared statements
 *  6. UPI VPA never returned to frontend (server-side only)
 *  7. Duplicate UTRs blocked at DB UNIQUE KEY level
 *  8. Payment expiry enforced server-side (not trusting client timers)
 *  9. Admin actions require session role = 'admin'
 */

// ── Security Headers ──────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// ── Prevent direct access to config.php / db.php ───────────────────────────
define('SARASWATI_INIT', true);

// ── CORS ──────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/config.php';

$allowedOrigin = ALLOWED_ORIGIN;
if ($allowedOrigin !== '') {
    header("Access-Control-Allow-Origin: $allowedOrigin");
    header('Vary: Origin');
} else {
    // Dev fallback — same origin or localhost only
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && (
        strpos($origin, 'localhost') !== false ||
        strpos($origin, '127.0.0.1') !== false
    )) {
        header("Access-Control-Allow-Origin: $origin");
    }
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/payment.php';

// ── Session (secure settings) ─────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'secure'   => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

// ── Helper functions ──────────────────────────────────────────────────────────

function out(array $data): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function client_ip(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * IP-based rate limiter using the rate_limits DB table.
 * Resets counter every RATE_LIMIT_WINDOW seconds.
 */
function check_rate_limit(mysqli $conn, string $action, string $ip): void {
    $max    = RATE_LIMIT_MAX;
    $window = RATE_LIMIT_WINDOW;

    // Remove stale windows
    $conn->query("DELETE FROM rate_limits WHERE window_start < DATE_SUB(NOW(), INTERVAL $window SECOND)");

    $s = $conn->prepare("SELECT request_count, window_start FROM rate_limits WHERE ip_address = ? AND action_key = ?");
    $s->bind_param('ss', $ip, $action);
    $s->execute();
    $row = $s->get_result()->fetch_assoc();

    if (!$row) {
        $ins = $conn->prepare("INSERT INTO rate_limits (ip_address, action_key, request_count, window_start) VALUES (?, ?, 1, NOW())");
        $ins->bind_param('ss', $ip, $action);
        $ins->execute();
    } else {
        if ((int)$row['request_count'] >= $max) {
            http_response_code(429);
            out(['ok' => false, 'message' => 'Too many requests. Please wait a moment and try again.', 'rate_limited' => true]);
        }
        $upd = $conn->prepare("UPDATE rate_limits SET request_count = request_count + 1 WHERE ip_address = ? AND action_key = ?");
        $upd->bind_param('ss', $ip, $action);
        $upd->execute();
    }
}

/** Generate or return existing CSRF token for this session. */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(CSRF_TOKEN_BYTES));
    }
    return $_SESSION['csrf_token'];
}

/** Validate CSRF token from POST header or field. */
function validate_csrf(): void {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN']
          ?? $_POST['csrf_token']
          ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        out(['ok' => false, 'message' => 'Security token mismatch. Please refresh and try again.']);
    }
}

/** Check admin role in session. */
function require_admin(): void {
    if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
        http_response_code(403);
        out(['ok' => false, 'message' => 'Admin access required.']);
    }
}

// ── Route request ──────────────────────────────────────────────────────────────
$action = trim($_POST['action'] ?? $_GET['action'] ?? '');
$ip     = client_ip();

// Apply rate limiting to all non-trivial actions
if (!in_array($action, ['csrf_token', 'tables'], true)) {
    check_rate_limit($conn, $action, $ip);
}

// ═════════════════════════════════════════════════════════════════════════════
//  GET CSRF TOKEN  (safe, GET, no CSRF check needed here)
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'csrf_token') {
    out(['ok' => true, 'token' => csrf_token()]);
}

// ═════════════════════════════════════════════════════════════════════════════
//  GET TABLES
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'tables') {
    $date = $_GET['date'] ?? date('Y-m-d');
    $slot = $_GET['slot'] ?? '';

    // Validate date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = date('Y-m-d');
    }

    $sql = "SELECT t.id, t.zone, t.seats, t.features,
                   CASE WHEN r.id IS NOT NULL THEN 'booked' ELSE t.status END AS live_status
            FROM study_tables t
            LEFT JOIN reservations r
              ON r.table_id = t.id
             AND r.booking_date = ?
             AND r.time_slot = ?
             AND r.reservation_status = 'reserved'
            ORDER BY t.id";
    $s = $conn->prepare($sql);
    $s->bind_param('ss', $date, $slot);
    $s->execute();
    out(['ok' => true, 'tables' => $s->get_result()->fetch_all(MYSQLI_ASSOC)]);
}

// ═════════════════════════════════════════════════════════════════════════════
//  REGISTER
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'register') {
    validate_csrf();

    $name  = trim($_POST['name']     ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $phone = trim($_POST['phone']    ?? '');
    $pass  = $_POST['password']      ?? '';

    if (!$name || !$email || !$pass) {
        out(['ok' => false, 'message' => 'Name, email and password are required.']);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        out(['ok' => false, 'message' => 'Please enter a valid email address.']);
    }
    if (strlen($pass) < 8) {
        out(['ok' => false, 'message' => 'Password must be at least 8 characters.']);
    }
    if ($phone && !preg_match('/^\+?[0-9\s\-]{7,15}$/', $phone)) {
        out(['ok' => false, 'message' => 'Please enter a valid phone number.']);
    }

    // Strip HTML/script from name
    $name = htmlspecialchars(strip_tags($name), ENT_QUOTES, 'UTF-8');

    $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
    $s    = $conn->prepare("INSERT INTO users (full_name, email, phone, password_hash) VALUES (?,?,?,?)");
    try {
        $s->bind_param('ssss', $name, $email, $phone, $hash);
        $s->execute();
        $uid = $conn->insert_id;
        // Log into session
        session_regenerate_id(true);
        $_SESSION['user_id']   = $uid;
        $_SESSION['user_role'] = 'user';
        out(['ok' => true, 'user' => ['id' => $uid, 'name' => $name, 'email' => $email, 'role' => 'user']]);
    } catch (Throwable $e) {
        out(['ok' => false, 'message' => 'This email is already registered. Please sign in.']);
    }
}

// ═════════════════════════════════════════════════════════════════════════════
//  LOGIN
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'login') {
    validate_csrf();

    $email = strtolower(trim($_POST['email'] ?? ''));
    $pass  = $_POST['password'] ?? '';

    if (!$email || !$pass) {
        out(['ok' => false, 'message' => 'Email and password are required.']);
    }

    $s = $conn->prepare("SELECT id, full_name, email, password_hash, role FROM users WHERE email = ?");
    $s->bind_param('s', $email);
    $s->execute();
    $u = $s->get_result()->fetch_assoc();

    // Use constant-time comparison to prevent timing attacks
    if (!$u || !password_verify($pass, $u['password_hash'])) {
        // Delay to prevent brute-force enumeration
        usleep(random_int(200000, 500000));
        out(['ok' => false, 'message' => 'Incorrect email or password.']);
    }

    // Rehash if needed (e.g. cost factor change)
    if (password_needs_rehash($u['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
        $newHash    = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
        $rehashStmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $rehashStmt->bind_param('si', $newHash, $u['id']);
        $rehashStmt->execute();
    }

    session_regenerate_id(true);
    $_SESSION['user_id']   = $u['id'];
    $_SESSION['user_role'] = $u['role'];
    out(['ok' => true, 'user' => ['id' => $u['id'], 'name' => $u['full_name'], 'email' => $u['email'], 'role' => $u['role']]]);
}

// ═════════════════════════════════════════════════════════════════════════════
//  LOGOUT
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'logout') {
    $_SESSION = [];
    session_destroy();
    out(['ok' => true]);
}

// ═════════════════════════════════════════════════════════════════════════════
//  INIT PAYMENT — creates a pending reservation and returns UPI QR data
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'init_payment') {
    validate_csrf();

    $uid   = (int)($_POST['user_id']  ?? 0);
    $table = (int)($_POST['table_id'] ?? 0);
    $date  = trim($_POST['date']      ?? '');
    $slot  = trim($_POST['slot']      ?? '');
    $plan  = (int)($_POST['plan_id']  ?? 1);

    // Validate inputs
    if (!$table || !$date || !$slot) {
        out(['ok' => false, 'message' => 'Table, date and time slot are required.']);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        out(['ok' => false, 'message' => 'Invalid date format.']);
    }
    if (strtotime($date) < strtotime(date('Y-m-d'))) {
        out(['ok' => false, 'message' => 'Cannot book a table in the past.']);
    }

    // Get plan price
    $ps = $conn->prepare("SELECT price_inr FROM subscription_plans WHERE id = ?");
    $ps->bind_param('i', $plan);
    $ps->execute();
    $p      = $ps->get_result()->fetch_assoc();
    $amount = (int)($p['price_inr'] ?? 1000);

    // Check table is actually available
    $avail = $conn->prepare(
        "SELECT t.status, r.id AS booked_id
           FROM study_tables t
           LEFT JOIN reservations r
             ON r.table_id = t.id
            AND r.booking_date = ?
            AND r.time_slot = ?
            AND r.reservation_status = 'reserved'
          WHERE t.id = ?"
    );
    $avail->bind_param('ssi', $date, $slot, $table);
    $avail->execute();
    $avrow = $avail->get_result()->fetch_assoc();
    if (!$avrow) {
        out(['ok' => false, 'message' => 'Table not found.']);
    }
    if ($avrow['status'] === 'maintenance') {
        out(['ok' => false, 'message' => 'This table is under maintenance.']);
    }
    if ($avrow['booked_id']) {
        out(['ok' => false, 'message' => 'Sorry, this table was just booked by someone else. Please choose another.']);
    }

    // Generate unique order reference
    $orderRef  = generate_order_ref();
    $expiresAt = date('Y-m-d H:i:s', time() + PAYMENT_TIMEOUT_SEC);

    // Insert pending reservation (single correct statement)
    $s = $conn->prepare(
        "INSERT INTO reservations
           (user_id, table_id, booking_date, time_slot, plan_id, amount_inr, payment_status, order_ref, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?)"
    );
    try {
        $s->bind_param('iissiiss', $uid, $table, $date, $slot, $plan, $amount, $orderRef, $expiresAt);
        $s->execute();
        $rid = $conn->insert_id;
    } catch (Throwable $e) {
        out(['ok' => false, 'message' => 'This slot was just taken. Please pick another table or time.']);
    }

    // Log the UPI transaction initiation
    $txnStmt = $conn->prepare(
        "INSERT INTO upi_transactions (reservation_id, order_ref, amount_inr, status, ip_address)
         VALUES (?, ?, ?, 'initiated', ?)"
    );
    $txnStmt->bind_param('isis', $rid, $orderRef, $amount, $ip);
    $txnStmt->execute();

    // Build UPI link (VPA stays server-side; client gets only the upi:// string)
    $upiLink = generate_upi_link($amount, $orderRef);

    out([
        'ok'             => true,
        'reservation_id' => $rid,
        'order_ref'      => $orderRef,
        'amount'         => $amount,
        'upi_link'       => $upiLink,                    // safe to share — standard UPI format
        'payee_name'     => UPI_NAME,                    // display name only
        'expires_in'     => PAYMENT_TIMEOUT_SEC,
        'expires_at'     => $expiresAt,
        'message'        => 'Payment initiated. Scan QR code or tap your UPI app to pay.',
    ]);
}

// ═════════════════════════════════════════════════════════════════════════════
//  SUBMIT UTR — user enters their UPI reference number after paying
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'submit_utr') {
    validate_csrf();

    $orderRef = trim($_POST['order_ref'] ?? '');
    $utr      = strtoupper(trim($_POST['utr'] ?? ''));
    $app      = trim($_POST['upi_app']   ?? 'unknown');

    if (!$orderRef || !$utr) {
        out(['ok' => false, 'message' => 'Order reference and UTR number are required.']);
    }

    // Validate UTR format
    if (!validate_utr($utr)) {
        out(['ok' => false, 'message' => 'Invalid UTR / reference number. Please check and re-enter the 12-digit reference shown in your UPI app.']);
    }

    // Sanitise app name
    $allowedApps = ['gpay', 'phonepe', 'paytm', 'bhim', 'other'];
    $app = in_array(strtolower($app), $allowedApps, true) ? strtolower($app) : 'other';

    // Fetch reservation
    $s = $conn->prepare(
        "SELECT id, payment_status, expires_at, amount_inr
           FROM reservations
          WHERE order_ref = ?"
    );
    $s->bind_param('s', $orderRef);
    $s->execute();
    $res = $s->get_result()->fetch_assoc();

    if (!$res) {
        out(['ok' => false, 'message' => 'Order not found. Please start a new booking.']);
    }
    if ($res['payment_status'] === 'paid') {
        out(['ok' => false, 'message' => 'This order is already marked as paid.']);
    }
    if ($res['payment_status'] === 'expired') {
        out(['ok' => false, 'message' => 'This payment session has expired. Please start a new booking.']);
    }
    if (strtotime($res['expires_at']) < time()) {
        // Mark expired
        $conn->prepare("UPDATE reservations SET payment_status='expired', reservation_status='cancelled' WHERE order_ref=?")
             ->bind_param('s', $orderRef)->execute();
        out(['ok' => false, 'message' => 'Your 15-minute payment window has expired. Please book again.']);
    }
    if (!in_array($res['payment_status'], ['pending', 'pending_verification'], true)) {
        out(['ok' => false, 'message' => 'Invalid order status: ' . htmlspecialchars($res['payment_status'])]);
    }

    // Update reservation to pending_verification
    $upd = $conn->prepare(
        "UPDATE reservations SET payment_status='pending_verification' WHERE order_ref=?"
    );
    $upd->bind_param('s', $orderRef);
    $upd->execute();

    // Save UTR (UNIQUE constraint in DB prevents duplicate UTR reuse)
    try {
        $txn = $conn->prepare(
            "UPDATE upi_transactions
                SET utr_number = ?,
                    upi_app = ?,
                    status = 'utr_submitted'
              WHERE order_ref = ?"
        );
        $txn->bind_param('sss', $utr, $app, $orderRef);
        $txn->execute();
    } catch (Throwable $e) {
        out(['ok' => false, 'message' => 'This UTR number has already been submitted. If you paid, please contact support.']);
    }

    out([
        'ok'        => true,
        'order_ref' => $orderRef,
        'message'   => 'Payment details submitted for verification. Your booking is held while we confirm payment. This usually takes a few minutes.',
        'status'    => 'pending_verification',
    ]);
}

// ═════════════════════════════════════════════════════════════════════════════
//  PAYMENT STATUS — frontend polls this
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'payment_status') {
    $orderRef = trim($_GET['order_ref'] ?? '');

    if (!$orderRef || !preg_match('/^SL[A-F0-9]{8}$/i', $orderRef)) {
        out(['ok' => false, 'message' => 'Invalid order reference.']);
    }

    $s = $conn->prepare(
        "SELECT r.id, r.payment_status, r.reservation_status, r.amount_inr,
                r.table_id, r.booking_date, r.time_slot, r.created_at, r.expires_at,
                r.order_ref,
                u.full_name AS student_name,
                ut.utr_number, ut.upi_app, ut.status AS txn_status
           FROM reservations r
           LEFT JOIN users u  ON u.id  = r.user_id
           LEFT JOIN upi_transactions ut ON ut.order_ref = r.order_ref
          WHERE r.order_ref = ?
          LIMIT 1"
    );
    $s->bind_param('s', $orderRef);
    $s->execute();
    $row = $s->get_result()->fetch_assoc();

    if (!$row) {
        out(['ok' => false, 'message' => 'Order not found.']);
    }

    out([
        'ok'             => true,
        'order_ref'      => $orderRef,
        'status'         => $row['payment_status'],
        'txn_status'     => $row['txn_status'] ?? 'initiated',
        'amount'         => $row['amount_inr'],
        'table_id'       => $row['table_id'],
        'booking_date'   => $row['booking_date'],
        'time_slot'      => $row['time_slot'],
        'student_name'   => $row['student_name'] ?? 'Member',
        'utr_number'     => $row['utr_number']   ?? null,
        'upi_app'        => $row['upi_app']       ?? null,
        'expires_at'     => $row['expires_at'],
        'reservation_id' => $row['id'],
    ]);
}

// ═════════════════════════════════════════════════════════════════════════════
//  OLD: BOOK (kept for compatibility — now redirected to init_payment)
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'book') {
    // Redirect old clients to new flow
    out(['ok' => false, 'message' => 'Please use action=init_payment for the new secure payment flow.', 'redirect' => 'init_payment']);
}

// ═════════════════════════════════════════════════════════════════════════════
//  ADMIN: VERIFY PAYMENT — admin marks UTR as verified / rejected
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'admin_verify_payment') {
    validate_csrf();
    require_admin();

    $orderRef = trim($_POST['order_ref'] ?? '');
    $decision = trim($_POST['decision']  ?? ''); // 'approve' | 'reject'
    $notes    = trim($_POST['notes']     ?? '');
    $adminId  = (int)$_SESSION['user_id'];

    if (!$orderRef || !in_array($decision, ['approve', 'reject'], true)) {
        out(['ok' => false, 'message' => 'Order reference and decision are required.']);
    }

    $notes = htmlspecialchars(strip_tags($notes), ENT_QUOTES, 'UTF-8');

    if ($decision === 'approve') {
        $upd1 = $conn->prepare("UPDATE reservations SET payment_status='paid' WHERE order_ref=? AND payment_status='pending_verification'");
        $upd1->bind_param('s', $orderRef);
        $upd1->execute();

        $upd2 = $conn->prepare("UPDATE upi_transactions SET status='verified', admin_notes=?, verified_by=?, verified_at=NOW() WHERE order_ref=?");
        $upd2->bind_param('sis', $notes, $adminId, $orderRef);
        $upd2->execute();

        // Fetch details for response
        $detailStmt = $conn->prepare("SELECT table_id, booking_date, time_slot FROM reservations WHERE order_ref=?");
        $detailStmt->bind_param('s', $orderRef);
        $detailStmt->execute();
        $r = $detailStmt->get_result()->fetch_assoc();
        out(['ok' => true, 'message' => "Payment for $orderRef approved. Table {$r['table_id']} confirmed.", 'reservation' => $r]);

    } else { // reject
        $rej1 = $conn->prepare("UPDATE reservations SET payment_status='failed', reservation_status='cancelled' WHERE order_ref=? AND payment_status='pending_verification'");
        $rej1->bind_param('s', $orderRef);
        $rej1->execute();

        $rej2 = $conn->prepare("UPDATE upi_transactions SET status='failed', admin_notes=?, verified_by=?, verified_at=NOW() WHERE order_ref=?");
        $rej2->bind_param('sis', $notes, $adminId, $orderRef);
        $rej2->execute();

        out(['ok' => true, 'message' => "Payment for $orderRef rejected. Booking cancelled."]);
    }
}

// ═════════════════════════════════════════════════════════════════════════════
//  ADMIN: GET ALL DATA (tables + reservations + pending payments)
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'admin_data') {
    require_admin();

    $tables = $conn->query("SELECT * FROM study_tables ORDER BY id")->fetch_all(MYSQLI_ASSOC);

    $reservations = $conn->query(
        "SELECT r.*, u.full_name, u.phone, u.email,
                ut.utr_number, ut.upi_app, ut.status AS txn_status
           FROM reservations r
           LEFT JOIN users u ON u.id = r.user_id
           LEFT JOIN upi_transactions ut ON ut.reservation_id = r.id
          ORDER BY r.created_at DESC
          LIMIT 100"
    )->fetch_all(MYSQLI_ASSOC);

    $pending_payments = $conn->query(
        "SELECT r.id, r.order_ref, r.amount_inr, r.booking_date, r.time_slot, r.table_id, r.created_at,
                u.full_name, u.phone,
                ut.utr_number, ut.upi_app
           FROM reservations r
           LEFT JOIN users u ON u.id = r.user_id
           LEFT JOIN upi_transactions ut ON ut.reservation_id = r.id
          WHERE r.payment_status = 'pending_verification'
          ORDER BY r.created_at DESC"
    )->fetch_all(MYSQLI_ASSOC);

    out([
        'ok'              => true,
        'tables'          => $tables,
        'reservations'    => $reservations,
        'pending_payments'=> $pending_payments,
    ]);
}

// ═════════════════════════════════════════════════════════════════════════════
//  ADMIN: UPDATE TABLE STATUS
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'admin_table') {
    validate_csrf();
    require_admin();

    $id     = (int)($_POST['table_id'] ?? 0);
    $status = trim($_POST['status']    ?? '');
    if (!$id || !in_array($status, ['available', 'booked', 'maintenance'], true)) {
        out(['ok' => false, 'message' => 'Invalid table ID or status.']);
    }
    $s = $conn->prepare("UPDATE study_tables SET status = ? WHERE id = ?");
    $s->bind_param('si', $status, $id);
    $s->execute();
    out(['ok' => true, 'message' => "Table $id updated to $status."]);
}

// ═════════════════════════════════════════════════════════════════════════════
//  ADMIN: CANCEL RESERVATION
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'admin_cancel') {
    validate_csrf();
    require_admin();

    $rid = (int)($_POST['reservation_id'] ?? 0);
    if (!$rid) {
        out(['ok' => false, 'message' => 'Reservation ID required.']);
    }
    $s = $conn->prepare("UPDATE reservations SET reservation_status='cancelled' WHERE id=?");
    $s->bind_param('i', $rid);
    $s->execute();
    out(['ok' => true, 'message' => "Reservation #$rid cancelled."]);
}

// ═════════════════════════════════════════════════════════════════════════════
//  ME — validate current PHP session (used on page-load for session restore)
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'me') {
    if (!empty($_SESSION['user_id'])) {
        $s = $conn->prepare("SELECT id, full_name, email, role FROM users WHERE id = ?");
        $s->bind_param('i', $_SESSION['user_id']);
        $s->execute();
        $u = $s->get_result()->fetch_assoc();
        if ($u) {
            out(['ok' => true, 'user' => [
                'id'    => (int)$u['id'],
                'name'  => $u['full_name'],
                'email' => $u['email'],
                'role'  => $u['role'],
            ]]);
        }
    }
    out(['ok' => false, 'message' => 'No active session.']);
}

// ═════════════════════════════════════════════════════════════════════════════
//  MY BOOKINGS — logged-in user's reservation history
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'my_bookings') {
    if (empty($_SESSION['user_id'])) {
        out(['ok' => false, 'message' => 'Please sign in to view your bookings.', 'login_required' => true]);
    }
    $uid = (int)$_SESSION['user_id'];
    $s = $conn->prepare(
        "SELECT r.id, r.order_ref, r.table_id, r.booking_date, r.time_slot,
                r.amount_inr, r.payment_status, r.reservation_status,
                r.created_at, r.expires_at,
                sp.name AS plan_name,
                t.zone,
                ut.utr_number, ut.upi_app, ut.status AS txn_status
           FROM reservations r
           LEFT JOIN subscription_plans sp ON sp.id = r.plan_id
           LEFT JOIN study_tables t ON t.id = r.table_id
           LEFT JOIN upi_transactions ut ON ut.reservation_id = r.id
          WHERE r.user_id = ?
          ORDER BY r.created_at DESC
          LIMIT 30"
    );
    $s->bind_param('i', $uid);
    $s->execute();
    out(['ok' => true, 'bookings' => $s->get_result()->fetch_all(MYSQLI_ASSOC)]);
}

// ── Catch-all ──────────────────────────────────────────────────────────────────
http_response_code(400);
out(['ok' => false, 'message' => 'Unknown action.']);
