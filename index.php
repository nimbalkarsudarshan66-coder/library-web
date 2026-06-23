<?php
/**
 * index.php — Saraswati Library Entry Point
 *
 * This is the PHP entry point.  It:
 *  1. Starts a secure session
 *  2. Sets security headers
 *  3. Includes the HTML application (index.html)
 *
 * The HTML app loads its assets (style.css, app.js) statically.
 * All dynamic data is fetched via api.php (AJAX).
 */

define('SARASWATI_INIT', true);
require_once __DIR__ . '/config.php';

// ── Secure session ────────────────────────────────────────────────────────────
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

// ── Security / Caching headers ────────────────────────────────────────────────
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// ── Serve the HTML application ─────────────────────────────────────────────────
// index.html is pure HTML (no PHP tags), so include simply outputs it.
include __DIR__ . '/index.html';