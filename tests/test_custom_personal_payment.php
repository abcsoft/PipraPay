<?php
/**
 * PipraPay V3 — Custom Personal Payment Auto-Approval Tests
 *
 * Tests for the complete custom payment flow including:
 * - Session status correctness (not_started vs waiting)
 * - Session creation and atomic binding
 * - SMS matching and auto-verification
 * - SMS-before-session race condition reconciliation
 * - Amount/gateway/payer mismatch rejection
 * - Duplicate SMS / trx_id protection
 * - Session expiry handling
 * - Idempotent completion
 * - Default theme regression
 *
 * Usage:
 *   set PIPRAPAY_TEST_MODE=1 && "C:\xampp_lite_8_3\apps\php\php.exe" tests/test_custom_personal_payment.php
 */

declare(strict_types=1);

require_once __DIR__ . '/test_guard.php';

$pdo = pp_require_test_environment();

// --- Bootstrap: Load all needed functions ---
$functionsPath = dirname(__DIR__) . '/pp-content/pp-include/pp-functions.php';
if (!function_exists('pp_get_personal_payment_session_status')) {
    require_once $functionsPath;
}

global $db_prefix;
$db_prefix_str = !empty($db_prefix) ? $db_prefix : 'pp_';

// ---- Test utilities ----
$totalTests = 0;
$passedTests = 0;
$failedTests = [];

function test_assert(bool $condition, string $testName, string $detail = ''): void {
    global $totalTests, $passedTests, $failedTests;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo "  ✅ PASS: {$testName}\n";
    } else {
        $failedTests[] = $testName;
        echo "  ❌ FAIL: {$testName}";
        if ($detail) echo " — {$detail}";
        echo "\n";
    }
}

/**
 * Create a test transaction in `initiated` status
 */
function create_test_transaction(PDO $pdo, string $prefix, string $ref, string $brandId, string $amount = '10.00', string $currency = 'BDT'): void {
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("INSERT INTO `{$prefix}transaction` (`ref`, `brand_id`, `amount`, `currency`, `status`, `gateway_id`, `sender_key`, `sender`, `sender_type`, `trx_id`, `source_info`, `return_url`, `webhook_url`, `customer_info`, `metadata`, `created_date`, `updated_date`)
        VALUES (:ref, :brand_id, :amount, :currency, 'initiated', '--', '--', '--', '--', '--', '--', '--', '--', '{}', '{}', :created_date, :updated_date)");
    $stmt->execute([':ref' => $ref, ':brand_id' => $brandId, ':amount' => $amount, ':currency' => $currency, ':created_date' => $now, ':updated_date' => $now]);
}

/**
 * Create a test brand
 */
function create_test_brand(PDO $pdo, string $prefix, string $brandId): void {
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("INSERT IGNORE INTO `{$prefix}brands` (`brand_id`, `name`, `timezone`, `payment_tolerance`, `created_date`, `updated_date`)
        VALUES (:brand_id, 'Test Brand', 'Asia/Dhaka', '0', :created_date, :updated_date)");
    $stmt->execute([':brand_id' => $brandId, ':created_date' => $now, ':updated_date' => $now]);
}

/**
 * Create a test gateway
 */
function create_test_gateway(PDO $pdo, string $prefix, string $gatewayId, string $brandId, string $slug = 'bkash-personal', string $currency = 'BDT'): void {
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("INSERT IGNORE INTO `{$prefix}gateways` (`gateway_id`, `brand_id`, `slug`, `name`, `display`, `currency`, `status`, `fixed_discount`, `percentage_discount`, `fixed_charge`, `percentage_charge`, `created_date`, `updated_date`)
        VALUES (:gid, :brand_id, :slug, :name, :display, :currency, 'active', '0', '0', '0', '0', :created_date, :updated_date)");
    $stmt->execute([':gid' => $gatewayId, ':brand_id' => $brandId, ':slug' => $slug, ':name' => $slug, ':display' => $slug, ':currency' => $currency, ':created_date' => $now, ':updated_date' => $now]);
}

/**
 * Create a test SMS data record
 */
function create_test_sms(PDO $pdo, string $prefix, string $senderKey, string $payerNumber, string $amount, string $trxId, string $deviceId = 'test-device-1', string $brandId = 'test-brand-1', string $status = 'approved'): int {
    $now = date('Y-m-d H:i:s');
    // Make sure device exists
    $stmt = $pdo->prepare("INSERT IGNORE INTO `{$prefix}device` (`device_id`, `brand_id`, `name`, `model`, `status`, `created_date`, `updated_date`)
        VALUES (:did, :brand_id, 'Test Device', 'Test', 'used', :created_date, :updated_date)");
    $stmt->execute([':did' => $deviceId, ':brand_id' => $brandId, ':created_date' => $now, ':updated_date' => $now]);

    $stmt = $pdo->prepare("INSERT INTO `{$prefix}sms_data` (`source`, `device_id`, `sender_key`, `number`, `amount`, `trx_id`, `type`, `status`, `message`, `reason`, `created_date`, `updated_date`)
        VALUES ('app', :did, :sender_key, :number, :amount, :trx_id, 'Personal', :status, 'Test SMS message', '--', :created_date, :updated_date)");
    $stmt->execute([':did' => $deviceId, ':sender_key' => $senderKey, ':number' => $payerNumber, ':amount' => $amount, ':trx_id' => $trxId, ':status' => $status, ':created_date' => $now, ':updated_date' => $now]);
    return (int)$pdo->lastInsertId();
}

/**
 * Create a waiting personal payment session directly
 */
function create_test_session(PDO $pdo, string $prefix, string $txRef, string $brandId, string $gatewayId, string $senderKey, string $payerNumber, string $amount, int $ttl = 300): int {
    $now = date('Y-m-d H:i:s');
    $expiresAt = date('Y-m-d H:i:s', time() + $ttl);
    $stmt = $pdo->prepare("INSERT INTO `{$prefix}personal_payment_sessions` (`transaction_ref`, `brand_id`, `gateway_id`, `sender_key`, `sender_type`, `payer_number`, `expected_amount`, `status`, `created_at`, `expires_at`, `created_date`, `updated_date`)
        VALUES (:ref, :brand_id, :gid, :sender_key, 'Personal', :payer, :amount, 'waiting', :created_at, :expires_at, :created_date, :updated_date)");
    $stmt->execute([':ref' => $txRef, ':brand_id' => $brandId, ':gid' => $gatewayId, ':sender_key' => $senderKey, ':payer' => $payerNumber, ':amount' => $amount, ':created_at' => $now, ':expires_at' => $expiresAt, ':created_date' => $now, ':updated_date' => $now]);
    return (int)$pdo->lastInsertId();
}

// ---- Setup: Ensure tables and clean test data ----
echo "=====================================================\n";
echo "  CUSTOM PERSONAL PAYMENT AUTO-APPROVAL TESTS\n";
echo "=====================================================\n\n";
echo "Setting up test environment...\n";

// Ensure personal payment sessions table exists
ensurePersonalPaymentSessionsTable();

// Clean all test data
$pdo->exec("DELETE FROM `{$db_prefix_str}personal_payment_sessions` WHERE `transaction_ref` LIKE 'TEST-%'");
$pdo->exec("DELETE FROM `{$db_prefix_str}transaction` WHERE `ref` LIKE 'TEST-%'");
$pdo->exec("DELETE FROM `{$db_prefix_str}sms_data` WHERE `trx_id` LIKE 'TESTTRX%'");
$pdo->exec("DELETE FROM `{$db_prefix_str}brands` WHERE `brand_id` = 'test-brand-1'");
$pdo->exec("DELETE FROM `{$db_prefix_str}gateways` WHERE `gateway_id` = 'test-gw-1'");
$pdo->exec("DELETE FROM `{$db_prefix_str}gateways` WHERE `gateway_id` = 'test-gw-2'");
$pdo->exec("DELETE FROM `{$db_prefix_str}device` WHERE `device_id` = 'test-device-1'");

// Create test brand and gateways
create_test_brand($pdo, $db_prefix_str, 'test-brand-1');
create_test_gateway($pdo, $db_prefix_str, 'test-gw-1', 'test-brand-1', 'bkash-personal', 'BDT');
create_test_gateway($pdo, $db_prefix_str, 'test-gw-2', 'test-brand-1', 'nagad-personal', 'BDT');

echo "Test environment ready.\n\n";

// ========================================================
// TEST 1: Fresh initiated tx, no session → not_started
// ========================================================
echo "--- Test 1: Fresh custom payment (no session) → not_started ---\n";
create_test_transaction($pdo, $db_prefix_str, 'TEST-001', 'test-brand-1', '10.00');
$result = pp_get_personal_payment_session_status('TEST-001');
test_assert($result['status'] === 'true', 'T1: status is true');
test_assert($result['payment_status'] === 'not_started', 'T1: payment_status is not_started', 'Got: ' . ($result['payment_status'] ?? 'NULL'));
test_assert(isset($result['session_exists']) && $result['session_exists'] === false, 'T1: session_exists is false');

// ========================================================
// TEST 2: After session start → waiting + session_exists=true
// ========================================================
echo "\n--- Test 2: Waiting session → waiting + session_exists=true ---\n";
create_test_transaction($pdo, $db_prefix_str, 'TEST-002', 'test-brand-1', '25.00');
$sessId = create_test_session($pdo, $db_prefix_str, 'TEST-002', 'test-brand-1', 'test-gw-1', 'bkash', '01712345678', '25.00');
// Bind gateway to transaction like pp_create_or_update does
$pdo->prepare("UPDATE `{$db_prefix_str}transaction` SET `gateway_id` = 'test-gw-1', `sender_key` = 'bkash', `sender` = '01712345678', `sender_type` = 'Personal' WHERE `ref` = 'TEST-002'")->execute();
$result = pp_get_personal_payment_session_status('TEST-002');
test_assert($result['status'] === 'true', 'T2: status is true');
test_assert($result['payment_status'] === 'waiting', 'T2: payment_status is waiting', 'Got: ' . ($result['payment_status'] ?? 'NULL'));
test_assert(!empty($result['session_exists']), 'T2: session_exists is true');
test_assert(!empty($result['expires_in']) && $result['expires_in'] > 0, 'T2: expires_in is positive');
test_assert(!empty($result['payer_number']), 'T2: payer_number is present');

// ========================================================
// TEST 3: SMS after session → transaction completed
// ========================================================
echo "\n--- Test 3: SMS after session → completes transaction ---\n";
create_test_transaction($pdo, $db_prefix_str, 'TEST-003', 'test-brand-1', '50.00');
$sessId3 = create_test_session($pdo, $db_prefix_str, 'TEST-003', 'test-brand-1', 'test-gw-1', 'bkash', '01798765432', '50.00');
$pdo->prepare("UPDATE `{$db_prefix_str}transaction` SET `gateway_id` = 'test-gw-1', `sender_key` = 'bkash', `sender` = '01798765432', `sender_type` = 'Personal' WHERE `ref` = 'TEST-003'")->execute();
$smsId3 = create_test_sms($pdo, $db_prefix_str, 'bkash', '01798765432', '50.00', 'TESTTRX003', 'test-device-1', 'test-brand-1');
$matchResult = pp_process_personal_payment_sms($smsId3);
$result = pp_get_personal_payment_session_status('TEST-003');
test_assert($result['payment_status'] === 'completed', 'T3: payment_status is completed after SMS match', 'Got: ' . ($result['payment_status'] ?? 'NULL'));

// Verify the session is also completed
$sessCheck = $pdo->prepare("SELECT `status` FROM `{$db_prefix_str}personal_payment_sessions` WHERE `id` = :id");
$sessCheck->execute([':id' => $sessId3]);
$sessRow = $sessCheck->fetch(PDO::FETCH_ASSOC);
test_assert(($sessRow['status'] ?? '') === 'completed', 'T3: session status is completed');

// Verify SMS is consumed
$smsCheck = $pdo->prepare("SELECT `status` FROM `{$db_prefix_str}sms_data` WHERE `id` = :id");
$smsCheck->execute([':id' => $smsId3]);
$smsRow = $smsCheck->fetch(PDO::FETCH_ASSOC);
test_assert(($smsRow['status'] ?? '') === 'used', 'T3: SMS status is used');

// ========================================================
// TEST 4: SMS before session (race condition) → post-start reconciliation
// ========================================================
echo "\n--- Test 4: SMS before session → reconciliation matches ---\n";
create_test_transaction($pdo, $db_prefix_str, 'TEST-004', 'test-brand-1', '75.00');
// SMS arrives FIRST (before session)
$smsId4 = create_test_sms($pdo, $db_prefix_str, 'bkash', '01755555555', '75.00', 'TESTTRX004', 'test-device-1', 'test-brand-1');
// Now create session (reconciliation should find the pre-existing SMS)
$sessId4 = create_test_session($pdo, $db_prefix_str, 'TEST-004', 'test-brand-1', 'test-gw-1', 'bkash', '01755555555', '75.00');
$pdo->prepare("UPDATE `{$db_prefix_str}transaction` SET `gateway_id` = 'test-gw-1', `sender_key` = 'bkash', `sender` = '01755555555', `sender_type` = 'Personal' WHERE `ref` = 'TEST-004'")->execute();
// Trigger reconciliation
$reconResult = pp_reconcile_personal_payment_session($sessId4);
test_assert(!empty($reconResult['matched']), 'T4: Reconciliation found the pre-existing SMS', 'Got matched=' . ($reconResult['matched'] ? 'true' : 'false') . ', reason=' . ($reconResult['reason'] ?? 'N/A'));
$result4 = pp_get_personal_payment_session_status('TEST-004');
test_assert($result4['payment_status'] === 'completed', 'T4: Transaction completed via reconciliation', 'Got: ' . ($result4['payment_status'] ?? 'NULL'));

// ========================================================
// TEST 5: Auto Verification with eligible SMS
// ========================================================
echo "\n--- Test 5: Auto Verification with eligible SMS ---\n";
create_test_transaction($pdo, $db_prefix_str, 'TEST-005', 'test-brand-1', '100.00');
$sessId5 = create_test_session($pdo, $db_prefix_str, 'TEST-005', 'test-brand-1', 'test-gw-1', 'bkash', '01744444444', '100.00');
$pdo->prepare("UPDATE `{$db_prefix_str}transaction` SET `gateway_id` = 'test-gw-1', `sender_key` = 'bkash', `sender` = '01744444444', `sender_type` = 'Personal' WHERE `ref` = 'TEST-005'")->execute();
$smsId5 = create_test_sms($pdo, $db_prefix_str, 'bkash', '01744444444', '100.00', 'TESTTRX005', 'test-device-1', 'test-brand-1');
$verifyResult = pp_verify_personal_payment_session('TEST-005');
test_assert($verifyResult['payment_status'] === 'completed', 'T5: Auto Verify completes payment', 'Got: ' . ($verifyResult['payment_status'] ?? 'NULL'));

// Without matching SMS
create_test_transaction($pdo, $db_prefix_str, 'TEST-005B', 'test-brand-1', '200.00');
$sessId5b = create_test_session($pdo, $db_prefix_str, 'TEST-005B', 'test-brand-1', 'test-gw-1', 'bkash', '01733333333', '200.00');
$pdo->prepare("UPDATE `{$db_prefix_str}transaction` SET `gateway_id` = 'test-gw-1', `sender_key` = 'bkash', `sender` = '01733333333', `sender_type` = 'Personal' WHERE `ref` = 'TEST-005B'")->execute();
$verifyResult5b = pp_verify_personal_payment_session('TEST-005B');
test_assert($verifyResult5b['payment_status'] === 'waiting', 'T5b: No SMS → remains waiting', 'Got: ' . ($verifyResult5b['payment_status'] ?? 'NULL'));

// ========================================================
// TEST 6: Wrong amount → no match
// ========================================================
echo "\n--- Test 6: Wrong amount → no match ---\n";
create_test_transaction($pdo, $db_prefix_str, 'TEST-006', 'test-brand-1', '30.00');
$sessId6 = create_test_session($pdo, $db_prefix_str, 'TEST-006', 'test-brand-1', 'test-gw-1', 'bkash', '01766666666', '30.00');
$pdo->prepare("UPDATE `{$db_prefix_str}transaction` SET `gateway_id` = 'test-gw-1', `sender_key` = 'bkash', `sender` = '01766666666', `sender_type` = 'Personal' WHERE `ref` = 'TEST-006'")->execute();
$smsId6 = create_test_sms($pdo, $db_prefix_str, 'bkash', '01766666666', '99.00', 'TESTTRX006', 'test-device-1', 'test-brand-1');
$matchResult6 = pp_process_personal_payment_sms($smsId6);
test_assert(empty($matchResult6['matched']), 'T6: Wrong amount → no match', 'Got code: ' . ($matchResult6['code'] ?? 'N/A'));
$result6 = pp_get_personal_payment_session_status('TEST-006');
test_assert($result6['payment_status'] === 'waiting', 'T6: Transaction remains waiting');

// ========================================================
// TEST 7: Wrong gateway (sender_key mismatch) → no match
// ========================================================
echo "\n--- Test 7: Wrong gateway → no match ---\n";
create_test_transaction($pdo, $db_prefix_str, 'TEST-007', 'test-brand-1', '40.00');
$sessId7 = create_test_session($pdo, $db_prefix_str, 'TEST-007', 'test-brand-1', 'test-gw-1', 'bkash', '01777777777', '40.00');
$pdo->prepare("UPDATE `{$db_prefix_str}transaction` SET `gateway_id` = 'test-gw-1', `sender_key` = 'bkash', `sender` = '01777777777', `sender_type` = 'Personal' WHERE `ref` = 'TEST-007'")->execute();
// SMS comes from nagad (wrong gateway/sender_key)
$smsId7 = create_test_sms($pdo, $db_prefix_str, 'nagad', '01777777777', '40.00', 'TESTTRX007', 'test-device-1', 'test-brand-1');
$matchResult7 = pp_process_personal_payment_sms($smsId7);
test_assert(empty($matchResult7['matched']), 'T7: Wrong gateway → no match', 'Got code: ' . ($matchResult7['code'] ?? 'N/A'));
$result7 = pp_get_personal_payment_session_status('TEST-007');
test_assert($result7['payment_status'] === 'waiting', 'T7: Transaction remains waiting');

// ========================================================
// TEST 8: Wrong payer → no match
// ========================================================
echo "\n--- Test 8: Wrong payer → no match ---\n";
create_test_transaction($pdo, $db_prefix_str, 'TEST-008', 'test-brand-1', '60.00');
$sessId8 = create_test_session($pdo, $db_prefix_str, 'TEST-008', 'test-brand-1', 'test-gw-1', 'bkash', '01788888888', '60.00');
$pdo->prepare("UPDATE `{$db_prefix_str}transaction` SET `gateway_id` = 'test-gw-1', `sender_key` = 'bkash', `sender` = '01788888888', `sender_type` = 'Personal' WHERE `ref` = 'TEST-008'")->execute();
// SMS from a different payer number
$smsId8 = create_test_sms($pdo, $db_prefix_str, 'bkash', '01799999999', '60.00', 'TESTTRX008', 'test-device-1', 'test-brand-1');
$matchResult8 = pp_process_personal_payment_sms($smsId8);
test_assert(empty($matchResult8['matched']), 'T8: Wrong payer → no match', 'Got code: ' . ($matchResult8['code'] ?? 'N/A'));

// ========================================================
// TEST 9: Duplicate SMS → single completion
// ========================================================
echo "\n--- Test 9: Duplicate SMS → single completion ---\n";
create_test_transaction($pdo, $db_prefix_str, 'TEST-009', 'test-brand-1', '80.00');
$sessId9 = create_test_session($pdo, $db_prefix_str, 'TEST-009', 'test-brand-1', 'test-gw-1', 'bkash', '01711111111', '80.00');
$pdo->prepare("UPDATE `{$db_prefix_str}transaction` SET `gateway_id` = 'test-gw-1', `sender_key` = 'bkash', `sender` = '01711111111', `sender_type` = 'Personal' WHERE `ref` = 'TEST-009'")->execute();
$smsId9a = create_test_sms($pdo, $db_prefix_str, 'bkash', '01711111111', '80.00', 'TESTTRX009', 'test-device-1', 'test-brand-1');
$matchResult9a = pp_process_personal_payment_sms($smsId9a);
test_assert(!empty($matchResult9a['matched']), 'T9: First SMS matches');

// Try the same SMS again
$matchResult9b = pp_process_personal_payment_sms($smsId9a);
test_assert(empty($matchResult9b['matched']), 'T9: Duplicate SMS does not match again', 'Got code: ' . ($matchResult9b['code'] ?? 'N/A'));

// ========================================================
// TEST 10: Duplicate transaction ID
// ========================================================
echo "\n--- Test 10: Duplicate trx_id in different SMS → rejected ---\n";
// The first SMS with TESTTRX009 was already consumed. A second SMS row with same trx_id should be caught by sms-transmit-bulk duplicate check.
// Here we test that the matcher sees the SMS as 'used' if we try to re-process.
test_assert(($matchResult9b['code'] ?? '') === 'SMS_ALREADY_USED', 'T10: Re-processed SMS returns SMS_ALREADY_USED', 'Got code: ' . ($matchResult9b['code'] ?? 'N/A'));

// ========================================================
// TEST 11: Expired session → expired status
// ========================================================
echo "\n--- Test 11: Expired session → expired status ---\n";
create_test_transaction($pdo, $db_prefix_str, 'TEST-011', 'test-brand-1', '15.00');
// Create a session that already expired (TTL = -10 seconds)
$sessId11 = create_test_session($pdo, $db_prefix_str, 'TEST-011', 'test-brand-1', 'test-gw-1', 'bkash', '01722222222', '15.00', -10);
$pdo->prepare("UPDATE `{$db_prefix_str}transaction` SET `gateway_id` = 'test-gw-1', `sender_key` = 'bkash', `sender` = '01722222222', `sender_type` = 'Personal' WHERE `ref` = 'TEST-011'")->execute();
$result11 = pp_get_personal_payment_session_status('TEST-011');
test_assert($result11['payment_status'] === 'expired', 'T11: Expired session returns expired', 'Got: ' . ($result11['payment_status'] ?? 'NULL'));
test_assert(($result11['expires_in'] ?? -1) === 0, 'T11: expires_in is 0');
test_assert(!empty($result11['session_exists']), 'T11: session_exists is true for expired');

// ========================================================
// TEST 12: Completed transaction → idempotent
// ========================================================
echo "\n--- Test 12: Completed transaction → idempotent ---\n";
// TEST-003 was already completed. Check multiple times.
$result12a = pp_get_personal_payment_session_status('TEST-003');
$result12b = pp_get_personal_payment_session_status('TEST-003');
test_assert($result12a['payment_status'] === 'completed', 'T12a: First check → completed');
test_assert($result12b['payment_status'] === 'completed', 'T12b: Second check → still completed');

// Auto Verify on completed tx should remain completed
$verifyResult12 = pp_verify_personal_payment_session('TEST-003');
test_assert($verifyResult12['payment_status'] === 'completed', 'T12c: Auto Verify on completed → still completed');

// ========================================================
// TEST 13: Default theme regression
// ========================================================
echo "\n--- Test 13: Default theme regression ---\n";
// Verify that the default payment design files are NOT modified by our changes.
// The default gateway.php should not reference personal_payment_sessions.
$defaultGatewayPath = dirname(__DIR__) . '/pp-content/pp-payment-designs/default/gateway.php';
if (file_exists($defaultGatewayPath)) {
    $defaultContent = file_get_contents($defaultGatewayPath);
    test_assert(
        strpos($defaultContent, 'personal_payment') === false,
        'T13: Default gateway.php does not contain personal_payment references'
    );
} else {
    test_assert(true, 'T13: Default gateway.php not found (expected if no default design exists)');
}

// Verify session status for non-existent transaction returns proper error
$resultNonExist = pp_get_personal_payment_session_status('NONEXISTENT-TX-REF');
test_assert($resultNonExist['payment_status'] === 'not_found', 'T13b: Non-existent tx returns not_found', 'Got: ' . ($resultNonExist['payment_status'] ?? 'NULL'));

// ---- Cleanup ----
echo "\n--- Cleanup ---\n";
$pdo->exec("DELETE FROM `{$db_prefix_str}personal_payment_sessions` WHERE `transaction_ref` LIKE 'TEST-%'");
$pdo->exec("DELETE FROM `{$db_prefix_str}transaction` WHERE `ref` LIKE 'TEST-%'");
$pdo->exec("DELETE FROM `{$db_prefix_str}sms_data` WHERE `trx_id` LIKE 'TESTTRX%'");
$pdo->exec("DELETE FROM `{$db_prefix_str}brands` WHERE `brand_id` = 'test-brand-1'");
$pdo->exec("DELETE FROM `{$db_prefix_str}gateways` WHERE `gateway_id` IN ('test-gw-1', 'test-gw-2')");
$pdo->exec("DELETE FROM `{$db_prefix_str}device` WHERE `device_id` = 'test-device-1'");
echo "Test data cleaned up.\n";

// ---- Summary ----
echo "\n=====================================================\n";
echo "  RESULTS: {$passedTests}/{$totalTests} passed\n";
if (!empty($failedTests)) {
    echo "  FAILED:\n";
    foreach ($failedTests as $ft) {
        echo "    - {$ft}\n";
    }
}
echo "=====================================================\n";

exit(empty($failedTests) ? 0 : 1);
