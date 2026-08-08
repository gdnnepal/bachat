<?php

/**
 * VCMS — Village Cooperative Management System
 * app/config/App.php — Application constants and environment bootstrapping
 *
 * This file is required (not autoloaded) by public/index.php very early in the
 * bootstrap sequence so that constants are available everywhere.
 */

declare(strict_types=1);

namespace App\Config;

// ─── Timezone ────────────────────────────────────────────────────────────────
date_default_timezone_set('UTC');

// ─── Environment ─────────────────────────────────────────────────────────────
if (!defined('APP_ENV')) {
    define('APP_ENV', getenv('APP_ENV') ?: 'development');
}

// ─── Application identity ────────────────────────────────────────────────────
if (!defined('APP_NAME')) {
    define('APP_NAME', getenv('APP_NAME') ?: 'VCMS');
}

if (!defined('APP_VERSION')) {
    define('APP_VERSION', '1.0.0');
}

// ─── Base URL ────────────────────────────────────────────────────────────────
if (!defined('BASE_URL')) {
    define('BASE_URL', getenv('BASE_URL') ?: 'http://localhost/Bachat/backend/public');
}

// ─── CORS / Allowed origins ──────────────────────────────────────────────────
if (!defined('ALLOWED_ORIGINS')) {
    define('ALLOWED_ORIGINS', getenv('ALLOWED_ORIGINS') ?: 'http://localhost:5173');
}
