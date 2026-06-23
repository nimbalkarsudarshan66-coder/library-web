<?php
/**
 * Saraswati Library — Central Configuration (Production-Hardened)
 *
 * SECURITY RULES:
 *   - Never commit real credentials to git.
 *   - Set all secrets as server environment variables in production.
 *   - On cPanel / Shared Hosting: set ENV via cPanel › Software › PHP Config
 *   - On VPS / Linux: export in /etc/environment or web-server vhost config
 *   - The .env file is for LOCAL DEVELOPMENT ONLY — it is blocked by .htaccess
 *     and listed in .gitignore so it can never be committed or served publicly.
 *
 * This file is blocked by .htaccess from direct browser access.
 * It should only be included by other PHP files (api.php, index.php, etc.)
 */

// ── This file must never be accessed directly ────────────────────────────────
if (!defined('SARASWATI_INIT')) {
    http_response_code(403);
    exit('Direct access not permitted.');
}

// ── PHP production hardening ─────────────────────────────────────────────────
// Silenced in production — errors are logged, never displayed
$appEnvRaw = getenv('APP_ENV') ?: (isset($_ENV['APP_ENV']) ? $_ENV['APP_ENV'] : 'production');
$isDev     = ($appEnvRaw === 'development');

if (!$isDev) {
    error_reporting(0);
    ini_set('display_errors',         '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors',             '1');
    // Error log path — writable by web server, outside public root ideally
    // ini_set('error_log', dirname(__DIR__) . '/php_errors.log');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors',         '1');
    ini_set('display_startup_errors', '1');
}

// ── Timezone ─────────────────────────────────────────────────────────────────
date_default_timezone_set('Asia/Kolkata');  // IST — change if needed

// ── Load .env file (LOCAL/DEV convenience only) ──────────────────────────────
// On production: variables must be set as real server ENV variables.
// .htaccess blocks direct browser access to .env
$_envFile = __DIR__ . '/.env';
if (is_readable($_envFile)) {
    foreach (file($_envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $_line) {
        $_line = trim($_line);
        if ($_line === '' || $_line[0] === '#') continue;
        if (strpos($_line, '=') !== false) {
            [$_k, $_v] = explode('=', $_line, 2);
            $_k = trim($_k);
            $_v = trim($_v, " \t\"'");
            if (getenv($_k) === false) {
                putenv("$_k=$_v");
                $_ENV[$_k] = $_v;
            }
        }
    }
    unset($_line, $_k, $_v, $_envFile);
}

/**
 * Safely read an environment variable with optional default.
 */
function env(string $key, $default = null) {
    $v = getenv($key);
    if ($v !== false && $v !== '') return $v;
    return $_ENV[$key] ?? $default;
}

// ── Database ─────────────────────────────────────────────────────────────────
define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));
define('DB_NAME', env('DB_NAME', 'saraswati_library'));
define('DB_PORT', (int)env('DB_PORT', '3306'));
define('DB_CHARSET', 'utf8mb4');

// ── UPI Payment Details ───────────────────────────────────────────────────────
// SECURITY: UPI VPA stays server-side — it NEVER reaches frontend JS or HTML.
define('UPI_VPA',           env('UPI_VPA',           'pratikshingare2002@okicici'));
define('UPI_NAME',          env('UPI_NAME',           'Saraswati Library'));
define('UPI_MERCHANT_CODE', env('UPI_MERCHANT_CODE',  '0000'));

// ── Razorpay (optional) ───────────────────────────────────────────────────────
define('RAZORPAY_KEY_ID',         env('RAZORPAY_KEY_ID',         ''));
define('RAZORPAY_KEY_SECRET',     env('RAZORPAY_KEY_SECRET',     ''));
define('RAZORPAY_WEBHOOK_SECRET', env('RAZORPAY_WEBHOOK_SECRET', ''));

// ── Application ───────────────────────────────────────────────────────────────
define('APP_ENV',     $appEnvRaw);
define('APP_URL',     rtrim(env('APP_URL', ''), '/'));   // e.g. https://yourdomain.com
define('ADMIN_EMAIL', env('ADMIN_EMAIL', ''));

// ── Security ─────────────────────────────────────────────────────────────────
define('RATE_LIMIT_MAX',      (int)env('RATE_LIMIT_MAX',      '30'));   // requests per window
define('RATE_LIMIT_WINDOW',   (int)env('RATE_LIMIT_WINDOW',   '60'));   // seconds
define('PAYMENT_TIMEOUT_SEC', (int)env('PAYMENT_TIMEOUT_SEC', '900'));  // 15 min
define('SESSION_LIFETIME',    (int)env('SESSION_LIFETIME',    '3600')); // 1 hour
define('CSRF_TOKEN_BYTES',    32);

// ── CORS / Origin ─────────────────────────────────────────────────────────────
// Set ALLOWED_ORIGIN to your exact domain in production (e.g. https://yourdomain.com)
// Leave blank in development to allow localhost.
define('ALLOWED_ORIGIN', env('ALLOWED_ORIGIN', ''));

// ── Production validation ─────────────────────────────────────────────────────
// Warn administrators (via log, not browser) if critical env vars are missing.
if (!$isDev) {
    $missing = [];
    if (!DB_PASS)        $missing[] = 'DB_PASS';
    if (!APP_URL)        $missing[] = 'APP_URL';
    if (!ALLOWED_ORIGIN) $missing[] = 'ALLOWED_ORIGIN';
    if (!ADMIN_EMAIL)    $missing[] = 'ADMIN_EMAIL';
    if ($missing) {
        error_log('[Saraswati Library] WARNING: Missing production env vars: ' . implode(', ', $missing));
    }
}

// ── Global PHP error handler (production only) ────────────────────────────────
// Converts PHP warnings/notices into logged errors; prevents info leaks.
if (!$isDev) {
    set_error_handler(function(int $errno, string $errstr, string $errfile, int $errline): bool {
        // Only handle non-fatal errors (fatal errors are caught below)
        if (!(error_reporting() & $errno)) return false;
        error_log(sprintf('[PHP Error %d] %s in %s on line %d', $errno, $errstr, basename($errfile), $errline));
        return true; // suppress output
    });

    register_shutdown_function(function(): void {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            error_log(sprintf('[PHP Fatal] %s in %s on line %d', $e['message'], basename($e['file']), $e['line']));
            // If no output sent yet, serve JSON error for API calls
            if (!headers_sent() && (($_SERVER['REQUEST_URI'] ?? '') === '/api.php' || str_ends_with($_SERVER['REQUEST_URI'] ?? '', 'api.php'))) {
                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'message' => 'Server error. Please try again.']);
            }
        }
    });
}
