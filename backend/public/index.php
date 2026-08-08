<?php
/**
 * VCMS — Village Cooperative Management System
 * public/index.php — Sole entry point: bootstrap, session start, dispatcher
 */

declare(strict_types=1);

// ─── Error reporting (disable in production) ────────────────────────────────
define('APP_ENV', getenv('APP_ENV') ?: 'development');

if (APP_ENV === 'development') {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(0);
}

// ─── Define root paths ───────────────────────────────────────────────────────
define('BASE_PATH', dirname(__DIR__));
define('APP_PATH',  BASE_PATH . '/app');
define('LANG_PATH', BASE_PATH . '/lang');
define('PUBLIC_PATH', __DIR__);

// ─── Timezone ────────────────────────────────────────────────────────────────
date_default_timezone_set('UTC');

// ─── Autoloader (PSR-4 style, composer-generated) ────────────────────────────  
$autoloader = BASE_PATH . '/vendor/autoload.php';
if (file_exists($autoloader)) {
    require $autoloader;
}

// ─── Manual class-map autoloader (fallback before composer install) ──────────
spl_autoload_register(function (string $class): void {
    // Map namespace prefixes to directories
    $prefixMap = [
        'App\\Config\\'      => APP_PATH . '/config/',
        'App\\Middleware\\'  => APP_PATH . '/middleware/',
        'App\\Controllers\\' => APP_PATH . '/controllers/',
        'App\\Models\\'      => APP_PATH . '/models/',
        'App\\Services\\'    => APP_PATH . '/services/',
        'App\\Helpers\\'     => APP_PATH . '/helpers/',
    ];

    foreach ($prefixMap as $prefix => $baseDir) {
        if (str_starts_with($class, $prefix)) {
            $relative = substr($class, strlen($prefix));
            $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
            if (file_exists($file)) {
                require $file;
            }
            return;
        }
    }
});

// ─── Load application config ─────────────────────────────────────────────────
require APP_PATH . '/config/App.php';

// ─── CORS — must run before session_start() so headers are sent on every response ──
$allowedOrigins = [];
if (defined('ALLOWED_ORIGINS') && ALLOWED_ORIGINS !== '') {
    // Trim whitespace from each origin in case of formatting issues in .env
    $allowedOrigins = array_map('trim', explode(',', ALLOWED_ORIGINS));
}
// Always allow localhost in development
if (APP_ENV !== 'production') {
    $allowedOrigins[] = 'http://localhost:5173';
    $allowedOrigins[] = 'http://localhost:3000';
}

$origin = trim($_SERVER['HTTP_ORIGIN'] ?? '');
$originAllowed = !empty($origin) && in_array(rtrim($origin, '/'), array_map(fn($o) => rtrim($o, '/'), $allowedOrigins), true);

if ($originAllowed) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, X-Requested-With, Authorization');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Max-Age: 86400');
    header('Vary: Origin');
}

// Handle preflight OPTIONS — respond immediately before any PHP processing
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ─── Session configuration ───────────────────────────────────────────────────
ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');

// Cross-origin (Vercel → cPanel): SameSite=None + Secure required
if ($originAllowed && $origin !== (defined('BASE_URL') ? rtrim(BASE_URL, '/') : '')) {
    ini_set('session.cookie_samesite', 'None');
    ini_set('session.cookie_secure', '1');
} elseif (APP_ENV === 'production') {
    ini_set('session.cookie_samesite', 'None');
    ini_set('session.cookie_secure', '1');
} else {
    ini_set('session.cookie_samesite', 'Lax');
}

session_name('VCMS_SESSION');
session_start();

// ─── Dispatch to router ──────────────────────────────────────────────────────
require APP_PATH . '/routes/api.php';
