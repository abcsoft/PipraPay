<?php
/**
 * PipraPay Centralized Test Environment Guard & Bootstrapper
 *
 * HARD PRODUCTION GUARDS:
 * 1. Requires explicit PIPRAPAY_TEST_MODE=1 in environment.
 * 2. Refuses execution on production database name or when test database criteria fail.
 * 3. Never auto-detects localhost, 127.0.0.1, or CLI mode as proof of safety.
 * 4. Isolates test DB connections strictly from production.
 */

declare(strict_types=1);

if (!defined('PipraPay_INIT')) {
    define('PipraPay_INIT', true);
}

function pp_require_test_environment(?array $customConfig = []): PDO {
    // 1. Check explicit test mode opt-in
    $testMode = getenv('PIPRAPAY_TEST_MODE');
    if ($testMode === false || $testMode === '') {
        $testMode = $_ENV['PIPRAPAY_TEST_MODE'] ?? ($_SERVER['PIPRAPAY_TEST_MODE'] ?? '0');
    }
    
    $testModeStr = strtolower(trim((string)$testMode));
    if ($testModeStr !== '1' && $testModeStr !== 'true') {
        fwrite(STDERR, "[SECURITY REFUSAL] Refusing to run test/destructive script: PIPRAPAY_TEST_MODE is not enabled (must be '1').\n");
        exit(1);
    }

    // 2. Load base config to know production DB name and defaults
    $configPath = dirname(__DIR__) . '/pp-config.php';
    if (!file_exists($configPath)) {
        fwrite(STDERR, "[SECURITY REFUSAL] Cannot locate pp-config.php.\n");
        exit(1);
    }
    
    global $db_host, $db_port, $db_user, $db_pass, $db_name, $db_prefix, $pp_token_secret;
    require $configPath;
    $prodDbName = $db_name;

    // 3. Determine target test database configuration
    $targetHost = $customConfig['db_host'] ?? (getenv('PIPRAPAY_TEST_DB_HOST') ?: ($db_host ?: '127.0.0.1'));
    $targetPort = $customConfig['db_port'] ?? (getenv('PIPRAPAY_TEST_DB_PORT') ?: ($db_port ?: '3306'));
    $targetUser = $customConfig['db_user'] ?? (getenv('PIPRAPAY_TEST_DB_USER') ?: ($db_user ?: 'root'));
    $targetPass = $customConfig['db_pass'] ?? (getenv('PIPRAPAY_TEST_DB_PASS') !== false ? getenv('PIPRAPAY_TEST_DB_PASS') : ($db_pass ?? ''));
    $targetPrefix = $customConfig['db_prefix'] ?? (getenv('PIPRAPAY_TEST_DB_PREFIX') ?: ($db_prefix ?: 'pp_'));

    $targetName = $customConfig['db_name'] ?? (getenv('PIPRAPAY_TEST_DB_NAME') ?: '');
    if (empty($targetName)) {
        $targetName = $prodDbName . '_test';
    }

    $targetNameLower = strtolower(trim((string)$targetName));
    $prodDbNameLower = strtolower(trim((string)$prodDbName));

    // 4. Hard safety check against production database name
    $forbiddenProdNames = ['piprapayv3', 'piprapay', 'production', 'prod', 'live', 'main', 'master', 'payabc', 'abcsoft'];
    
    if (in_array($targetNameLower, $forbiddenProdNames, true) || $targetNameLower === $prodDbNameLower) {
        fwrite(STDERR, sprintf("[SECURITY REFUSAL] Refusing to run test against production database '%s'. A dedicated test database name is strictly required.\n", $targetName));
        exit(1);
    }

    // Target database must satisfy test naming conventions
    $isTestNameValid = (
        str_ends_with($targetNameLower, '_test') ||
        str_contains($targetNameLower, 'test') ||
        str_ends_with($targetNameLower, '_testing') ||
        str_ends_with($targetNameLower, '_dev')
    );

    if (!$isTestNameValid) {
        fwrite(STDERR, sprintf("[SECURITY REFUSAL] Database '%s' does not satisfy test database naming conventions (must end with _test or contain 'test').\n", $targetName));
        exit(1);
    }

    // 5. Connect to test database
    try {
        $dsn = "mysql:host={$targetHost};port={$targetPort};dbname={$targetName};charset=utf8mb4";
        $pdo = new PDO($dsn, $targetUser, $targetPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]);
    } catch (PDOException $e) {
        fwrite(STDERR, sprintf("[SECURITY REFUSAL] Failed to connect to test database '%s': %s\n", $targetName, $e->getMessage()));
        exit(1);
    }

    // 6. Override globals so that any subsequent application functions use the test DB
    $db_host = $targetHost;
    $db_port = $targetPort;
    $db_user = $targetUser;
    $db_pass = $targetPass;
    $db_name = $targetName;
    $db_prefix = $targetPrefix;

    if (!defined('PIPRAPAY_TEST_ENVIRONMENT_ACTIVE')) {
        define('PIPRAPAY_TEST_ENVIRONMENT_ACTIVE', true);
    }

    // Load pp-functions if not yet loaded
    $functionsPath = dirname(__DIR__) . '/pp-content/pp-include/pp-functions.php';
    if (file_exists($functionsPath)) {
        require_once $functionsPath;
    }

    return $pdo;
}

function pp_is_test_environment_active(): bool {
    return defined('PIPRAPAY_TEST_ENVIRONMENT_ACTIVE') && PIPRAPAY_TEST_ENVIRONMENT_ACTIVE === true;
}
