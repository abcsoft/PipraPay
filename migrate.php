<?php
/**
 * PipraPay Production Database Migration Runner
 *
 * Usage: php migrate.php
 *
 * Limitations:
 * - Standard SQL statements separated by semicolons (;) only.
 * - Blank lines and comment lines starting with '--' or '#' are ignored.
 * - Stored procedures, triggers with custom DELIMITER statements, and complex scripting are not supported.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('PipraPay_INIT', true);

$configPath = __DIR__ . '/pp-config.php';
$functionsPath = __DIR__ . '/pp-content/pp-include/pp-functions.php';

if (!file_exists($configPath) || !file_exists($functionsPath)) {
    fwrite(STDERR, "Error: Project configuration or functions file missing.\n");
    exit(1);
}

require_once $configPath;
require_once $functionsPath;

global $db_prefix;
$prefix = !empty($db_prefix) ? $db_prefix : 'pp_';

try {
    $pdo = connectDatabase();
} catch (Throwable $e) {
    fwrite(STDERR, "Database connection failed.\n");
    exit(1);
}

$trackingTable = $prefix . 'migrations';

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$trackingTable}` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `migration` VARCHAR(255) NOT NULL,
        `applied_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_migration` (`migration`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Throwable $e) {
    fwrite(STDERR, "Failed to initialize tracking table: " . $e->getMessage() . "\n");
    exit(1);
}

$migrationsDir = __DIR__ . '/pp-content/pp-migrations';
if (!is_dir($migrationsDir)) {
    @mkdir($migrationsDir, 0755, true);
}

$stmt = $pdo->query("SELECT `migration` FROM `{$trackingTable}`");
$applied = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
$appliedMap = array_flip($applied);

$files = glob($migrationsDir . '/*.sql');
if ($files === false) {
    $files = [];
}
sort($files, SORT_STRING);

function parseSqlStatements(string $sql, string $prefix): array {
    $sql = str_replace('{PREFIX}', $prefix, $sql);
    $lines = explode("\n", $sql);
    $cleanLines = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
            continue;
        }
        $cleanLines[] = $line;
    }
    $cleanSql = implode("\n", $cleanLines);
    $rawStatements = explode(';', $cleanSql);
    $statements = [];
    foreach ($rawStatements as $stmt) {
        $trimmedStmt = trim($stmt);
        if ($trimmedStmt !== '') {
            $statements[] = $trimmedStmt;
        }
    }
    return $statements;
}

foreach ($files as $filePath) {
    $filename = basename($filePath);

    if (isset($appliedMap[$filename])) {
        echo "[SKIP] {$filename}\n";
        continue;
    }

    echo "[RUN] {$filename}\n";
    $sqlContent = file_get_contents($filePath);
    if ($sqlContent === false) {
        echo "[ERROR] {$filename}\n";
        fwrite(STDERR, "Error: Unable to read migration file {$filename}.\n");
        exit(1);
    }

    $statements = parseSqlStatements($sqlContent, $prefix);

    try {
        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }

        $ins = $pdo->prepare("INSERT INTO `{$trackingTable}` (`migration`, `applied_at`) VALUES (:migration, NOW())");
        $ins->execute([':migration' => $filename]);
        echo "[OK] {$filename}\n";
    } catch (Throwable $e) {
        echo "[ERROR] {$filename}\n";
        fwrite(STDERR, "Database Error during {$filename}: " . $e->getMessage() . "\n");
        exit(1);
    }
}

echo "All migrations completed successfully.\n";
exit(0);
