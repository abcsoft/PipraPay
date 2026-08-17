<?php
/**
 * Test Suite: Test Environment Hard Guard Security & Isolation (Tests A - F)
 */

declare(strict_types=1);

$phpBinary = 'C:\\xampp_lite_8_3\\apps\\php\\php.exe';
$repoRoot = dirname(__DIR__);

echo "===================================================================\n";
echo "STARTING TEST ENVIRONMENT GUARD SECURITY SUITE (A - F)\n";
echo "===================================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertGuardTest(string $name, bool $condition, string $details = '') {
    global $passCount, $failCount;
    if ($condition) {
        echo "[PASS] {$name}\n";
        $passCount++;
    } else {
        echo "[FAIL] {$name} - Details: {$details}\n";
        $failCount++;
    }
}

function runPhpCommand(string $scriptPath, array $env = []): array {
    global $phpBinary, $repoRoot;

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];

    // Inherit full system env (including SystemRoot, PATH, etc.)
    $baseEnv = getenv();
    unset($baseEnv['PIPRAPAY_TEST_MODE'], $baseEnv['PIPRAPAY_TEST_DB_NAME'], $baseEnv['PIPRAPAY_TEST_DB_HOST'], $baseEnv['PIPRAPAY_TEST_DB_USER'], $baseEnv['PIPRAPAY_TEST_DB_PASS'], $baseEnv['PIPRAPAY_TEST_DB_PORT']);

    $mergedEnv = array_merge($baseEnv, $env);

    $process = proc_open("\"{$phpBinary}\" \"{$scriptPath}\"", $descriptors, $pipes, $repoRoot, $mergedEnv);
    if (!is_resource($process)) {
        return ['code' => -1, 'stdout' => '', 'stderr' => 'Failed to start process'];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $code = proc_close($process);

    return [
        'code'   => $code,
        'stdout' => $stdout,
        'stderr' => $stderr
    ];
}

$targetScript = $repoRoot . '/scratch/test_visibility_and_diagnostics.php';

// ----------------------------------------------------------------------
// TEST A: Normal environment (PIPRAPAY_TEST_MODE unset) -> REFUSED before DB mutation
// ----------------------------------------------------------------------
echo "--- TEST A: Unset PIPRAPAY_TEST_MODE ---\n";
$resA = runPhpCommand($targetScript, []);
assertGuardTest("Test A: Exit code is 1 (refused)", $resA['code'] === 1, "Got code: {$resA['code']}");
assertGuardTest("Test A: STDERR contains security refusal message", str_contains($resA['stderr'], '[SECURITY REFUSAL]') && str_contains($resA['stderr'], 'PIPRAPAY_TEST_MODE'), "STDERR: " . $resA['stderr']);

// ----------------------------------------------------------------------
// TEST B: PIPRAPAY_TEST_MODE=0 -> REFUSED
// ----------------------------------------------------------------------
echo "\n--- TEST B: PIPRAPAY_TEST_MODE=0 ---\n";
$resB = runPhpCommand($targetScript, ['PIPRAPAY_TEST_MODE' => '0']);
assertGuardTest("Test B: Exit code is 1 (refused)", $resB['code'] === 1, "Got code: {$resB['code']}");
assertGuardTest("Test B: STDERR contains refusal message", str_contains($resB['stderr'], '[SECURITY REFUSAL]'), "STDERR: " . $resB['stderr']);

// ----------------------------------------------------------------------
// TEST C: PIPRAPAY_TEST_MODE=1 but wrong / non-connectable test DB config -> REFUSED
// ----------------------------------------------------------------------
echo "\n--- TEST C: PIPRAPAY_TEST_MODE=1 with invalid/non-connectable DB ---\n";
$resC = runPhpCommand($targetScript, [
    'PIPRAPAY_TEST_MODE' => '1',
    'PIPRAPAY_TEST_DB_NAME' => 'nonexistent_test_db_9988_test'
]);
assertGuardTest("Test C: Exit code is 1 (refused/failed to connect)", $resC['code'] === 1, "Got code: {$resC['code']}");
assertGuardTest("Test C: STDERR contains connection failure", str_contains($resC['stderr'], '[SECURITY REFUSAL]') || str_contains($resC['stderr'], 'Failed to connect'), "STDERR: " . $resC['stderr']);

// ----------------------------------------------------------------------
// TEST D: Explicit test mode + valid dedicated test DB -> TESTS RUN
// ----------------------------------------------------------------------
echo "\n--- TEST D: PIPRAPAY_TEST_MODE=1 with valid test DB piprapayv3_test ---\n";
$resD = runPhpCommand($targetScript, [
    'PIPRAPAY_TEST_MODE' => '1',
    'PIPRAPAY_TEST_DB_NAME' => 'piprapayv3_test'
]);
assertGuardTest("Test D: Exit code is 0 (success)", $resD['code'] === 0, "Got code: {$resD['code']}, STDERR: {$resD['stderr']}");
assertGuardTest("Test D: Tests run and output passes", str_contains($resD['stdout'], 'ALL TESTS 1 - 10 PASSED PERFECTLY!'), "STDOUT: " . $resD['stdout']);

// ----------------------------------------------------------------------
// TEST E: Production-like DB name with test mode accidentally enabled -> REFUSED
// ----------------------------------------------------------------------
echo "\n--- TEST E: Production DB name (piprapayv3) with PIPRAPAY_TEST_MODE=1 ---\n";
$resE = runPhpCommand($targetScript, [
    'PIPRAPAY_TEST_MODE' => '1',
    'PIPRAPAY_TEST_DB_NAME' => 'piprapayv3'
]);
assertGuardTest("Test E: Refuses execution on production DB name", $resE['code'] === 1, "Got code: {$resE['code']}");
assertGuardTest("Test E: STDERR mentions production database refusal", str_contains($resE['stderr'], 'Refusing to run test against production database'), "STDERR: " . $resE['stderr']);

// Test E2: Non-test-compliant DB name (e.g. customer_billing)
$resE2 = runPhpCommand($targetScript, [
    'PIPRAPAY_TEST_MODE' => '1',
    'PIPRAPAY_TEST_DB_NAME' => 'customer_billing'
]);
assertGuardTest("Test E2: Refuses execution on non-test DB name", $resE2['code'] === 1, "Got code: {$resE2['code']}");

// ----------------------------------------------------------------------
// TEST F: Normal PipraPay production application unaffected
// ----------------------------------------------------------------------
echo "\n--- TEST F: Production Application Unaffected ---\n";
// Run a clean probe that bootstraps index/functions in normal mode without test guard
$prodProbeScript = $repoRoot . '/scratch/test_prod_app_probe.php';
file_put_contents($prodProbeScript, '<?php
define("PipraPay_INIT", true);
require_once __DIR__ . "/../pp-config.php";
require_once __DIR__ . "/../pp-content/pp-include/pp-functions.php";
$pdo = connectDatabase();
global $db_name;
echo "PROD_DB:" . $db_name . "\n";
echo "PROD_ACTIVE:" . (defined("PIPRAPAY_TEST_ENVIRONMENT_ACTIVE") ? "yes" : "no") . "\n";
');

$resF = runPhpCommand($prodProbeScript, []);
assertGuardTest("Test F: Production bootstrap succeeds (code 0)", $resF['code'] === 0, "Got code: {$resF['code']}, STDERR: {$resF['stderr']}");
assertGuardTest("Test F: Production DB remains piprapayv3", str_contains($resF['stdout'], 'PROD_DB:piprapayv3'), "STDOUT: " . $resF['stdout']);
assertGuardTest("Test F: Test mode is not active in production", str_contains($resF['stdout'], 'PROD_ACTIVE:no'), "STDOUT: " . $resF['stdout']);

@unlink($prodProbeScript);

echo "\n===================================================================\n";
echo "GUARD TEST RESULTS: {$passCount} PASSED, {$failCount} FAILED\n";
echo "===================================================================\n";
if ($failCount === 0) {
    echo "ALL TESTS A - F PASSED PERFECTLY!\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED.\n";
    exit(1);
}
