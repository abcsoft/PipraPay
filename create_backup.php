<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('PipraPay_INIT', true);
require __DIR__ . '/pp-config.php';
require __DIR__ . '/pp-content/pp-include/pp-functions.php';

echo "=====================================================\n";
echo "       PIPRAPAY V3 COMPLETE SCRIPT & DATABASE BACKUP  \n";
echo "=====================================================\n\n";

$timestamp = date('Y-m-d_H-i-s');
$backupDir = __DIR__ . '/backups';

if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}

// Secure backup directory against web access
if (!file_exists($backupDir . '/.htaccess')) {
    file_put_contents($backupDir . '/.htaccess', "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
}
if (!file_exists($backupDir . '/index.php')) {
    file_put_contents($backupDir . '/index.php', "<?php\nhttp_response_code(403);\nexit;\n");
}

$sqlFilename = "db_dump_{$timestamp}.sql";
$sqlPath = $backupDir . '/' . $sqlFilename;

echo "[1/3] Dumping database '{$db_name}' to SQL file...\n";
$dbStartTime = microtime(true);
backupDatabasePDO($sqlPath);
$dbSizeMB = round(filesize($sqlPath) / (1024 * 1024), 2);
$dbDuration = round(microtime(true) - $dbStartTime, 2);
echo "      Database dump complete: {$sqlFilename} ({$dbSizeMB} MB in {$dbDuration}s)\n\n";

echo "[2/3] Archiving codebase files...\n";
$codeZipFilename = "codebase_{$timestamp}.zip";
$codeZipPath = $backupDir . '/' . $codeZipFilename;
$codeStartTime = microtime(true);

$zip = new ZipArchive();
if ($zip->open($codeZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
    $sourcePath = realpath(__DIR__);
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourcePath, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    $fileCount = 0;
    foreach ($files as $file) {
        $filePath = $file->getRealPath();
        $relativePath = substr($filePath, strlen($sourcePath) + 1);
        $relativePath = str_replace('\\', '/', $relativePath);

        // Exclude git repository, backup directories, and generated backup archives
        if (
            str_starts_with($relativePath, '.git/') || $relativePath === '.git' ||
            str_starts_with($relativePath, 'backups/') || $relativePath === 'backups' ||
            str_starts_with($relativePath, 'pp-media/storage/backup/') || $relativePath === 'pp-media/storage/backup'
        ) {
            continue;
        }

        if ($file->isDir()) {
            $zip->addEmptyDir($relativePath);
        } else {
            $zip->addFile($filePath, $relativePath);
            $fileCount++;
        }
    }
    $zip->close();
    $codeSizeMB = round(filesize($codeZipPath) / (1024 * 1024), 2);
    $codeDuration = round(microtime(true) - $codeStartTime, 2);
    echo "      Codebase archive complete: {$codeZipFilename} ({$fileCount} files, {$codeSizeMB} MB in {$codeDuration}s)\n\n";
} else {
    echo "ERROR: Failed to create codebase ZIP archive.\n";
    exit(1);
}

echo "[3/3] Creating unified full backup bundle...\n";
$bundleZipFilename = "piprapayv3_full_backup_{$timestamp}.zip";
$bundleZipPath = $backupDir . '/' . $bundleZipFilename;

$bundleZip = new ZipArchive();
if ($bundleZip->open($bundleZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
    $bundleZip->addFile($sqlPath, "database/{$sqlFilename}");
    $bundleZip->addFile($codeZipPath, "codebase/{$codeZipFilename}");
    $bundleZip->close();
    
    $bundleSizeMB = round(filesize($bundleZipPath) / (1024 * 1024), 2);
    echo "      Full backup bundle created: {$bundleZipFilename} ({$bundleSizeMB} MB)\n\n";
}

echo "=====================================================\n";
echo "SUCCESS: Full backup generated successfully!\n";
echo "Location: backups/\n";
echo "Files:\n";
echo "  1. SQL Dump:        backups/{$sqlFilename} ({$dbSizeMB} MB)\n";
echo "  2. Codebase Zip:    backups/{$codeZipFilename} ({$codeSizeMB} MB)\n";
echo "  3. Full Bundle Zip: backups/{$bundleZipFilename} ({$bundleSizeMB} MB)\n";
echo "=====================================================\n";
