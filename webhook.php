<?php
/**
 * webhook.php — Razorpay Payment Webhook Handler
 *
 * SECURITY:
 *  - Only Razorpay's known IP ranges are allowed (whitelist enforced)
 *  - HMAC-SHA256 signature verified before ANY DB update
 *  - Raw body read before any parsing (signature must match raw input)
 *  - All DB operations use prepared statements
 *  - No session required — uses webhook secret only
 *
 * Setup:
 *  1. Add your Razorpay Webhook Secret to .env as RAZORPAY_WEBHOOK_SECRET
 *  2. In Razorpay Dashboard → Settings → Webhooks, add:
 *       URL: https://yourdomain.com/webhook.php
 *       Events: payment.captured, payment.failed
 */

define('SARASWATI_INIT', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/payment.php';
require_once __DIR__ . '/db.php';

// ── Security headers ──────────────────────────────────────────────────────────
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// ── IP Whitelist (Razorpay's documented IPs, June 2024) ──────────────────────
$RAZORPAY_IPS = [
    '52.74.209.149',
    '54.169.55.79',
    '52.76.64.83',
    '3.6.245.221',
    // Add more from: https://razorpay.com/docs/webhooks/
];

// Only enforce whitelist in production mode
if (APP_ENV === 'production') {
    $remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($remoteIp, $RAZORPAY_IPS, true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Forbidden']);
        exit;
    }
}

// ── Read raw body BEFORE any parsing ─────────────────────────────────────────
$rawBody   = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';

// ── Verify webhook signature ──────────────────────────────────────────────────
if (!razorpay_verify_webhook($rawBody, $signature)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Invalid signature']);
    exit;
}

// ── Parse event ───────────────────────────────────────────────────────────────
$event = json_decode($rawBody, true);
if (!$event || !isset($event['event'], $event['payload']['payment']['entity'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid payload']);
    exit;
}

$entity    = $event['payload']['payment']['entity'];
$paymentId = $entity['id']          ?? '';
$orderId   = $entity['order_id']    ?? '';
$amount    = (int)($entity['amount'] ?? 0); // in paise
$status    = $entity['status']      ?? '';

// ── Handle event ──────────────────────────────────────────────────────────────
switch ($event['event']) {
    case 'payment.captured':
        // Mark reservation paid, store Razorpay payment ID
        $s = $conn->prepare(
            "UPDATE reservations
                SET payment_status = 'paid',
                    phonepe_transaction_id = ?
              WHERE order_ref = ? AND payment_status = 'pending'"
        );
        $s->bind_param('ss', $paymentId, $orderId);
        $s->execute();

        $txn = $conn->prepare(
            "UPDATE upi_transactions
                SET status = 'verified',
                    utr_number = ?,
                    verified_at = NOW()
              WHERE order_ref = ?"
        );
        $txn->bind_param('ss', $paymentId, $orderId);
        $txn->execute();

        http_response_code(200);
        echo json_encode(['ok' => true, 'event' => 'payment.captured']);
        break;

    case 'payment.failed':
        $s = $conn->prepare(
            "UPDATE reservations
                SET payment_status = 'failed',
                    reservation_status = 'cancelled'
              WHERE order_ref = ? AND payment_status = 'pending'"
        );
        $s->bind_param('s', $orderId);
        $s->execute();

        $txn = $conn->prepare("UPDATE upi_transactions SET status='failed' WHERE order_ref=?");
        $txn->bind_param('s', $orderId);
        $txn->execute();

        http_response_code(200);
        echo json_encode(['ok' => true, 'event' => 'payment.failed']);
        break;

    default:
        // Unknown event — acknowledge but do nothing
        http_response_code(200);
        echo json_encode(['ok' => true, 'event' => 'ignored']);
}
