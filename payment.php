<?php
/**
 * payment.php — UPI QR generation, UTR validation, and optional Razorpay integration
 *
 * SECURITY:
 *  - UPI_VPA is defined in config.php (server-side only, never sent to JS)
 *  - UTR validation uses strict regex; duplicate UTRs are blocked at DB level
 *  - Razorpay signature verification uses HMAC-SHA256 (tamper-proof)
 */

define('SARASWATI_INIT', true);
require_once __DIR__ . '/config.php';

// ─────────────────────────────────────────────────────────────────────────────
//  UPI LINK GENERATION
//  Generates a standard UPI deep-link / QR payload string.
//  This is returned to the client as a string; QR image is rendered browser-side.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Build a UPI payment URL for QR code and deep-link buttons.
 *
 * @param int    $amount_inr  Amount in rupees (e.g. 1000)
 * @param string $ref         Unique order reference (e.g. SL-A3F2C1)
 * @return string             upi://pay?... string
 */
function generate_upi_link(int $amount_inr, string $ref): string {
    $params = [
        'pa'  => UPI_VPA,
        'pn'  => UPI_NAME,
        'am'  => number_format($amount_inr, 2, '.', ''),
        'tn'  => 'Saraswati Library ' . $ref,
        'cu'  => 'INR',
    ];
    if (UPI_MERCHANT_CODE !== '' && UPI_MERCHANT_CODE !== '0000') {
        $params['mc'] = UPI_MERCHANT_CODE;
    }
    return 'upi://pay?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

/**
 * Build a deep-link URL for a specific UPI app.
 *
 * @param string $app         'gpay' | 'phonepe' | 'paytm' | 'bhim'
 * @param string $upi_link    The generic upi://pay?... link
 * @return string             App-specific intent URL
 */
function get_app_upi_link(string $app, string $upi_link): string {
    // Extract query params from the generic link
    $query = substr($upi_link, strpos($upi_link, '?') + 1);
    switch ($app) {
        case 'gpay':
            // Google Pay uses tez:// scheme
            return 'tez://upi/pay?' . $query;
        case 'phonepe':
            return 'phonepe://pay?' . $query;
        case 'paytm':
            return 'paytmmp://pay?' . $query;
        case 'bhim':
        default:
            return $upi_link; // Standard UPI
    }
}

// ─────────────────────────────────────────────────────────────────────────────
//  UTR / REFERENCE NUMBER VALIDATION
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Validate a UPI Transaction Reference (UTR) number.
 * UTRs are alphanumeric, typically 12–22 characters.
 * We also reject obviously fake patterns.
 *
 * @param string $utr  Raw input from user
 * @return bool
 */
function validate_utr(string $utr): bool {
    $utr = trim(strtoupper($utr));
    if (strlen($utr) < 12 || strlen($utr) > 22) {
        return false;
    }
    // Must be alphanumeric only (no special chars)
    if (!preg_match('/^[A-Z0-9]+$/', $utr)) {
        return false;
    }
    // Reject obvious test/placeholder patterns
    $blocked = ['111111111111', '000000000000', 'AAAAAAAAAAAAA', 'TESTTRANSACTION'];
    foreach ($blocked as $b) {
        if (strpos($utr, $b) !== false) {
            return false;
        }
    }
    return true;
}

/**
 * Generate a unique order reference code.
 *
 * @return string  e.g. SLA3F2C19E
 */
function generate_order_ref(): string {
    return 'SL' . strtoupper(bin2hex(random_bytes(4)));
}

// ─────────────────────────────────────────────────────────────────────────────
//  RAZORPAY INTEGRATION (optional — only used if RAZORPAY_KEY_ID is set)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Create a Razorpay order via their REST API.
 * Returns the order object or ['error' => '...'].
 */
function razorpay_create_order(int $amount_paise, string $receipt_id): array {
    if (!RAZORPAY_KEY_ID || !RAZORPAY_KEY_SECRET) {
        return ['error' => 'Razorpay not configured'];
    }
    $payload = json_encode([
        'amount'   => $amount_paise,
        'currency' => 'INR',
        'receipt'  => $receipt_id,
    ]);
    $ch = curl_init('https://api.razorpay.com/v1/orders');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_USERPWD        => RAZORPAY_KEY_ID . ':' . RAZORPAY_KEY_SECRET,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($res, true) ?? [];
    if ($code !== 200) {
        return ['error' => $data['error']['description'] ?? 'Razorpay API error'];
    }
    return $data;
}

/**
 * Verify Razorpay payment signature (HMAC-SHA256).
 * MUST be called server-side before marking any payment as paid.
 *
 * @param string $order_id    Razorpay order ID
 * @param string $payment_id  Razorpay payment ID
 * @param string $signature   Signature from client
 * @return bool               True if signature is valid
 */
function razorpay_verify_signature(string $order_id, string $payment_id, string $signature): bool {
    if (!RAZORPAY_KEY_SECRET) {
        return false;
    }
    $expected = hash_hmac('sha256', $order_id . '|' . $payment_id, RAZORPAY_KEY_SECRET);
    return hash_equals($expected, $signature);
}

/**
 * Verify Razorpay webhook signature.
 *
 * @param string $raw_body  Raw POST body from webhook
 * @param string $signature X-Razorpay-Signature header
 * @return bool
 */
function razorpay_verify_webhook(string $raw_body, string $signature): bool {
    if (!RAZORPAY_WEBHOOK_SECRET) {
        return false;
    }
    $expected = hash_hmac('sha256', $raw_body, RAZORPAY_WEBHOOK_SECRET);
    return hash_equals($expected, $signature);
}

// ─────────────────────────────────────────────────────────────────────────────
//  LEGACY: PhonePe payload (kept for reference, not used in live flow)
// ─────────────────────────────────────────────────────────────────────────────
function phonepe_payload(array $reservation, string $redirectUrl, string $callbackUrl): array {
    return ['disabled' => true, 'message' => 'PhonePe integration replaced by UPI QR flow'];
}
