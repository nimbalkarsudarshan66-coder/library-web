<?php
/**
 * error.php — Custom error page for Saraswati Library
 * Served by .htaccess for 400 / 403 / 404 / 429 / 500 errors.
 * Never exposes stack traces or server info.
 */

$code    = (int)($_SERVER['REDIRECT_STATUS'] ?? http_response_code() ?: 404);
$titles  = [
    400 => 'Bad Request',
    403 => 'Access Denied',
    404 => 'Page Not Found',
    429 => 'Too Many Requests',
    500 => 'Server Error',
];
$icons   = [ 400 => '⚠️', 403 => '🔒', 404 => '🔍', 429 => '⏱️', 500 => '🛠️' ];
$msgs    = [
    400 => 'The request could not be understood by the server.',
    403 => 'You do not have permission to access this page.',
    404 => 'The page you are looking for does not exist or has been moved.',
    429 => 'You\'ve made too many requests. Please wait a moment and try again.',
    500 => 'Something went wrong on our end. We\'re working on it.',
];

$title = $titles[$code] ?? 'Error';
$icon  = $icons[$code]  ?? '❌';
$msg   = $msgs[$code]   ?? 'An unexpected error occurred.';

if (!headers_sent()) {
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
}
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title><?= $code . ' — ' . htmlspecialchars($title) ?> · Saraswati Library</title>
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;600;700&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Space Grotesk', sans-serif;
      background: #0a0a12;
      color: #f1f0ff;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
    }
    .card {
      background: linear-gradient(135deg, #13131f, #1c1c2e);
      border: 1px solid rgba(139,92,246,0.25);
      border-radius: 24px;
      padding: 56px 48px;
      max-width: 480px;
      width: 100%;
      text-align: center;
      box-shadow: 0 0 80px rgba(124,58,237,0.12);
    }
    .icon { font-size: 64px; margin-bottom: 16px; display: block; }
    .code {
      font-size: 96px;
      font-weight: 700;
      background: linear-gradient(135deg, #7c3aed, #a78bfa);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
      line-height: 1;
      margin-bottom: 8px;
    }
    h1 { font-size: 24px; font-weight: 700; margin-bottom: 12px; }
    p  { color: #8b8aad; line-height: 1.6; margin-bottom: 32px; }
    a  {
      display: inline-flex; align-items: center; gap: 8px;
      padding: 14px 28px;
      background: linear-gradient(135deg, #7c3aed, #6d28d9);
      color: #fff; text-decoration: none;
      border-radius: 12px; font-weight: 600; font-size: 15px;
      transition: opacity 0.2s, transform 0.2s;
    }
    a:hover { opacity: 0.85; transform: translateY(-2px); }
  </style>
</head>
<body>
  <div class="card">
    <span class="icon"><?= $icon ?></span>
    <div class="code"><?= $code ?></div>
    <h1><?= htmlspecialchars($title) ?></h1>
    <p><?= htmlspecialchars($msg) ?></p>
    <a href="/">← Back to Home</a>
  </div>
</body>
</html>
