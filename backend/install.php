<?php
/**
 * VCMS — CLI installer
 * Called by install.sh — runs the SQL migration and seeds initial data.
 *
 * Usage:
 *   php backend/install.php \
 *     --db-host=localhost --db-port=3306 \
 *     --db-name=vcms --db-user=root --db-pass=secret \
 *     --coop-name="My Cooperative" \
 *     --admin-user=admin --admin-pass=admin123 \
 *     --site-url=https://bachat.gdn.com.np
 */

declare(strict_types=1);

// ── Parse CLI arguments ──────────────────────────────────────────────────────
$opts = getopt('', [
    'db-host:', 'db-port:', 'db-name:', 'db-user:', 'db-pass:',
    'coop-name:', 'admin-user:', 'admin-pass:', 'site-url:',
]);

$dbHost   = $opts['db-host']    ?? getenv('DB_HOST') ?: 'localhost';
$dbPort   = $opts['db-port']    ?? getenv('DB_PORT') ?: '3306';
$dbName   = $opts['db-name']    ?? getenv('DB_NAME') ?: '';
$dbUser   = $opts['db-user']    ?? getenv('DB_USER') ?: '';
$dbPass   = $opts['db-pass']    ?? getenv('DB_PASS') ?: '';
$coopName = $opts['coop-name']  ?? 'My Cooperative';
$adminUser = $opts['admin-user'] ?? 'admin';
$adminPass = $opts['admin-pass'] ?? 'admin123';
$siteUrl  = $opts['site-url']   ?? '';

if (empty($dbName) || empty($dbUser)) {
    fwrite(STDERR, "ERROR: --db-name and --db-user are required.\n");
    exit(1);
}

// ── Connect ──────────────────────────────────────────────────────────────────
try {
    $pdo = new PDO(
        "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    // Create DB if it doesn't exist
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$dbName}`");
    echo "[VCMS] Connected to MySQL and selected database '{$dbName}'.\n";
} catch (PDOException $e) {
    fwrite(STDERR, "ERROR: Cannot connect to MySQL: " . $e->getMessage() . "\n");
    exit(1);
}

// ── Run migration SQL ────────────────────────────────────────────────────────
$migrationFile = __DIR__ . '/database/migrations/001_initial_schema.sql';
if (!file_exists($migrationFile)) {
    fwrite(STDERR, "ERROR: Migration file not found: {$migrationFile}\n");
    exit(1);
}

echo "[VCMS] Running database migration...\n";

// Split on statement delimiter and run each statement
$sql = file_get_contents($migrationFile);

// Handle DELIMITER $$ blocks used for triggers
$sql = preg_replace_callback(
    '/DELIMITER\s*\$\$(.*?)DELIMITER\s*;/s',
    function ($m) {
        // Remove $$ delimiter markers; individual statements separated by $$
        return str_replace('$$', ';', $m[1]);
    },
    $sql
);

// Split on semicolons, skip empty statements
$statements = array_filter(
    array_map('trim', explode(';', $sql)),
    fn($s) => $s !== '' && !preg_match('/^\s*--/m', $s) || strlen(trim($s)) > 5
);

foreach ($statements as $statement) {
    $statement = trim($statement);
    if ($statement === '') continue;
    try {
        $pdo->exec($statement);
    } catch (PDOException $e) {
        // Ignore "table already exists" — idempotent
        if ($e->getCode() !== '42S01') {
            echo "[VCMS] Warning: " . $e->getMessage() . "\n";
        }
    }
}
echo "[VCMS] Migration complete.\n";

// ── Update cooperative name ──────────────────────────────────────────────────
if ($coopName !== 'My Cooperative') {
    $stmt = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'cooperative_name'");
    $stmt->execute([$coopName]);
    echo "[VCMS] Cooperative name set to: {$coopName}\n";
}

// ── Create/update Super Admin ────────────────────────────────────────────────
$hash = password_hash($adminPass, PASSWORD_BCRYPT, ['cost' => 12]);

// Check if admin already exists
$check = $pdo->prepare("SELECT id FROM admins WHERE username = ?");
$check->execute([$adminUser]);

if ($check->fetch()) {
    $stmt = $pdo->prepare("UPDATE admins SET password_hash = ? WHERE username = ?");
    $stmt->execute([$hash, $adminUser]);
    echo "[VCMS] Updated Super Admin password for: {$adminUser}\n";
} else {
    $stmt = $pdo->prepare(
        "INSERT INTO admins (name, username, password_hash, role, status) VALUES (?, ?, ?, 'Super_Admin', 1)"
    );
    $stmt->execute(['System Admin', $adminUser, $hash]);
    echo "[VCMS] Created Super Admin: {$adminUser}\n";
}

// ── Write .env if site URL provided ─────────────────────────────────────────
if ($siteUrl) {
    $envFile = __DIR__ . '/.env';
    if (!file_exists($envFile)) {
        $envContent = <<<ENV
APP_ENV=production
APP_NAME=VCMS
BASE_URL={$siteUrl}
ALLOWED_ORIGINS={$siteUrl}
DB_HOST={$dbHost}
DB_PORT={$dbPort}
DB_NAME={$dbName}
DB_USER={$dbUser}
DB_PASS={$dbPass}
ENV;
        file_put_contents($envFile, $envContent);
        echo "[VCMS] Written backend/.env\n";
    }
}

// ── Check if cycle/period seed needed ────────────────────────────────────────
$cycleCheck = $pdo->query("SELECT COUNT(*) FROM cycles")->fetchColumn();
if ((int)$cycleCheck === 0) {
    // Seed initial cycle and accounting period (BS 2081 Baishakh as default start)
    $pdo->exec(
        "INSERT INTO cycles (cycle_number, name, cycle_status, started_at_bs_year, started_at_bs_month)
         VALUES (1, 'Cycle 1', 'Active', 2081, 1)"
    );
    $cycleId = $pdo->lastInsertId();
    $pdo->exec(
        "INSERT INTO accounting_periods (cycle_id, bs_year, bs_month, period_status)
         VALUES ({$cycleId}, 2081, 1, 'OPEN')"
    );
    echo "[VCMS] Seeded initial Cycle 1 (BS 2081 Baishakh) as OPEN.\n";
}

echo "[VCMS] Installation complete!\n";
echo "[VCMS] Login: {$adminUser} / {$adminPass}\n";
