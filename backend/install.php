<?php
/**
 * One-click installer for cPanel deployment.
 * Prompts for MySQL credentials, writes backend/.env, and initializes the database.
 */

$baseDir = dirname(__DIR__);
$backendDir = $baseDir . '/backend';
$envPath = $backendDir . '/.env';
$schemaPath = $backendDir . '/database/migrations/001_initial_schema.sql';

function prompt(string $label, string $default = '', bool $required = true): string {
    $value = trim((string) fgets(STDIN));
    if ($value === '' && $default !== '') {
        return $default;
    }
    if ($required && $value === '') {
        fwrite(STDERR, "{$label} is required.\n");
        exit(1);
    }
    return $value;
}

function writeEnv(string $path, array $vars): void {
    $lines = [];
    foreach ($vars as $key => $value) {
        $lines[] = $key . '=' . str_replace(["\r", "\n"], ['', ''], $value);
    }
    file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL);
}

function createDatabase(PDO $pdo, string $dbName): void {
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$dbName}`");
}

function splitSqlStatements(string $sql): array {
    $lines = preg_split('/\r\n|\r|\n/', $sql) ?: [];
    $statements = [];
    $current = '';
    $delimiter = ';';

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if ($trimmed === '') {
            continue;
        }

        if (preg_match('/^DELIMITER\s+(.+)$/i', $trimmed, $matches)) {
            $delimiter = trim($matches[1]);
            continue;
        }

        if (preg_match('/^--/', $trimmed)) {
            continue;
        }

        $current .= $line . PHP_EOL;

        if (str_ends_with(rtrim($line), $delimiter)) {
            $statement = trim($current);
            $statement = rtrim($statement, PHP_EOL);
            $statement = rtrim($statement, "\n\r");
            if ($delimiter !== ';') {
                $statement = rtrim($statement, $delimiter);
            }
            $statement = trim($statement);
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $current = '';
        }
    }

    $tail = trim($current);
    if ($tail !== '') {
        $statements[] = $tail;
    }

    return array_values(array_filter($statements, static fn (string $statement): bool => trim($statement) !== ''));
}

function importSql(PDO $pdo, string $sqlFile): void {
    $sql = file_get_contents($sqlFile);
    if ($sql === false) {
        throw new RuntimeException("Unable to read schema file: {$sqlFile}");
    }

    foreach (splitSqlStatements($sql) as $statement) {
        if (trim($statement) === '') {
            continue;
        }
        $pdo->exec($statement);
    }
}

echo "=== VCMS cPanel Installer ===\n";
echo "This script will create backend/.env and initialize the database.\n";

echo "Press Enter to accept defaults where shown.\n";

echo "\nMySQL host [localhost]: ";
$host = prompt('MySQL host', 'localhost');
echo "MySQL port [3306]: ";
$port = prompt('MySQL port', '3306');
echo "MySQL username [root]: ";
$user = prompt('MySQL username', 'root');
echo "MySQL password: ";
$pass = prompt('MySQL password', '', false);
echo "Database name [vcms]: ";
$dbName = prompt('Database name', 'vcms');
echo "Application URL [https://bachat.gdn.com.np/backend/public]: ";
$baseUrl = prompt('Application URL', 'https://bachat.gdn.com.np/backend/public');
echo "Allowed origin [https://bachat.gdn.com.np]: ";
$allowedOrigin = prompt('Allowed origin', 'https://bachat.gdn.com.np');

echo "\nTesting MySQL connection...\n";
$dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $host, $port);
try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, "MySQL connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

createDatabase($pdo, $dbName);
$pdo->exec("USE `{$dbName}`");
try {
    importSql($pdo, $schemaPath);
} catch (Throwable $e) {
    fwrite(STDERR, "Database initialization failed: " . $e->getMessage() . "\n");
    exit(1);
}

$envVars = [
    'APP_ENV' => 'production',
    'APP_NAME' => 'VCMS',
    'BASE_URL' => $baseUrl,
    'ALLOWED_ORIGINS' => $allowedOrigin,
    'DB_HOST' => $host,
    'DB_PORT' => $port,
    'DB_NAME' => $dbName,
    'DB_USER' => $user,
    'DB_PASS' => $pass,
];

writeEnv($envPath, $envVars);

echo "\nInstallation completed successfully.\n";
echo "Config written to: {$envPath}\n";
echo "Database initialized: {$dbName}\n";
echo "Admin login: admin / admin123\n";
