<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Config: real values live in config.local.php (not in git)
// ---------------------------------------------------------------------------
$configFile = __DIR__ . '/config.local.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Missing app/config.local.php (copy app/config.example.php).');
}
$CONFIG = require $configFile;

function config(string $key, $default = null)
{
    global $CONFIG;
    return $CONFIG[$key] ?? $default;
}

// Never show PHP errors to visitors; log them instead.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('America/Curacao');

// ---------------------------------------------------------------------------
// Preview lock (hidden until launch)
// ---------------------------------------------------------------------------
// The Stripe webhook is exempt: Stripe can't log in, and every call is signature-checked.
$isWebhook = basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) === 'stripe-webhook.php';
if (config('preview_pass') && !$isWebhook) {
    header('X-Robots-Tag: noindex, nofollow');
    $u = $_SERVER['PHP_AUTH_USER'] ?? '';
    $p = $_SERVER['PHP_AUTH_PW'] ?? '';
    $okUser = hash_equals((string) config('preview_user', 'preview'), $u);
    $okPass = hash_equals((string) config('preview_pass'), $p);
    if (!($okUser && $okPass) && PHP_SAPI !== 'cli') {
        header('WWW-Authenticate: Basic realm="Djakimita preview"');
        http_response_code(401);
        exit('Preview: login required.');
    }
}

// ---------------------------------------------------------------------------
// Session (secure cookie)
// ---------------------------------------------------------------------------
if (session_status() !== PHP_SESSION_ACTIVE && PHP_SAPI !== 'cli') {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax', // Lax keeps the login after returning from iDEAL/Stripe
    ]);
    session_start();
}

// ---------------------------------------------------------------------------
// Database
// ---------------------------------------------------------------------------
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', config('db_host'), config('db_name'));
        $pdo = new PDO($dsn, (string) config('db_user'), (string) config('db_pass'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function e($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = '', array $query = []): string
{
    $q = $query ? '?' . http_build_query($query) : '';
    return '/' . ltrim($path, '/') . $q;
}

function money(float $amount, string $currency = 'eur'): string
{
    $symbol = $currency === 'usd' ? '$' : '€';
    $formatted = fmod($amount, 1.0) == 0.0 ? number_format($amount, 0) : number_format($amount, 2);
    return $symbol . $formatted;
}

require __DIR__ . '/i18n.php';
require __DIR__ . '/i18n_checkout.php';
require __DIR__ . '/data.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/payments.php';
