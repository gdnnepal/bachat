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

// ─── Session configuration ───────────────────────────────────────────────────
ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_samesite', 'Lax');

if (APP_ENV === 'production') {
    ini_set('session.cookie_secure', '1');
}

session_name('VCMS_SESSION');
session_start();

// ─── CORS headers (adjust origins for production) ────────────────────────────
$allowedOrigins = defined('ALLOWED_ORIGINS') ? explode(',', ALLOWED_ORIGINS) : ['http://localhost:5173'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, X-Requested-With');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
}

// Handle pre-flight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ─── Dispatch to router ──────────────────────────────────────────────────────
require APP_PATH . '/routes/api.php';
