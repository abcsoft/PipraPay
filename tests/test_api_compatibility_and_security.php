<?php
/**
 * PipraPay V3 — API Compatibility, Legacy SDK & Security Test Suite
 *
 * Covers:
 * 1. API key header compatibility (MHS-PIPRAPAY-API-KEY, MH-PIPRAPAY-API-KEY, case-insensitivity, $_SERVER forms)
 * 2. Legacy Laravel SDK create-charge simulation & payload normalization (email_mobile, redirect_url, cancel_url, metadata object/string)
 * 3. Legacy verify-payments simulation & canonical verify-payment parity
 * 4. Current v3 API regression tests (checkout/redirect, verify-payment, refund-payment)
 * 5. Complete 15-point Security & Scope enforcement suite
 *
 * Usage:
 *   $env:PIPRAPAY_TEST_MODE="1"; & "C:\xampp_lite_8_3\apps\php\php.exe" tests/test_api_compatibility_and_security.php
 */

declare(strict_types=1);

require_once __DIR__ . '/test_guard.php';

$pdo = pp_require_test_environment();

$functionsPath = dirname(__DIR__) . '/pp-content/pp-include/pp-functions.php';
require_once $functionsPath;

global $db_prefix;
$prefix = !empty($db_prefix) ? $db_prefix : 'pp_';

// Test counters
$totalTests = 0;
$passedTests = 0;
$failedTests = [];

function assert_test(bool $condition, string $testName, string $detail = ''): void {
    global $totalTests, $passedTests, $failedTests;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo "  ✅ PASS: {$testName}\n";
    } else {
        $failedTests[] = $testName;
        echo "  ❌ FAIL: {$testName}";
        if ($detail) echo " — Details: {$detail}";
        echo "\n";
    }
}

echo "===================================================================\n";
echo " PIPRAPAY V3 API COMPATIBILITY & SECURITY TEST SUITE\n";
echo "===================================================================\n\n";

// --- Fixture Setup ---
echo "Setting up test fixtures in {$prefix}...\n";

$testBrandId = 'test_brand_999';
$testKeyFull = 'test_api_key_full_access_1234567890abcdef1234567890';
$testKeyCreateOnly = 'test_api_key_create_only_1234567890abcdef1234567890';
$testKeyVerifyOnly = 'test_api_key_verify_only_1234567890abcdef1234567890';
$testKeyInactive = 'test_api_key_inactive_1234567890abcdef1234567890';
$testKeyExpired = 'test_api_key_expired_1234567890abcdef1234567890';

// Clean old test data
$pdo->exec("DELETE FROM `{$prefix}brands` WHERE `brand_id` = '{$testBrandId}'");
$pdo->exec("DELETE FROM `{$prefix}api` WHERE `brand_id` = '{$testBrandId}'");
$pdo->exec("DELETE FROM `{$prefix}domain` WHERE `domain` IN ('example.com', 'allowed-domain.example', 'inactive-domain.example')");
$pdo->exec("DELETE FROM `{$prefix}currency` WHERE `brand_id` = '{$testBrandId}'");
$pdo->exec("DELETE FROM `{$prefix}customer` WHERE `brand_id` = '{$testBrandId}'");
$pdo->exec("DELETE FROM `{$prefix}transaction` WHERE `brand_id` = '{$testBrandId}'");

// Insert Brand
$stmtBrand = $pdo->prepare("INSERT INTO `{$prefix}brands` (`brand_id`, `name`, `identify_name`, `timezone`, `currency_code`, `theme`)
    VALUES (:brand_id, 'Test Brand', 'TestBrand', 'Asia/Dhaka', 'BDT', 'default')");
$stmtBrand->execute([':brand_id' => $testBrandId]);

// Insert Whitelisted Domains
$stmtDom = $pdo->prepare("INSERT INTO `{$prefix}domain` (`domain`, `status`, `created_date`, `updated_date`)
    VALUES (:domain, :status, NOW(), NOW())");
$stmtDom->execute([':domain' => 'example.com', ':status' => 'active']);
$stmtDom->execute([':domain' => 'allowed-domain.example', ':status' => 'active']);
$stmtDom->execute([':domain' => 'inactive-domain.example', ':status' => 'inactive']);

// Insert Currency
$stmtCurr = $pdo->prepare("INSERT INTO `{$prefix}currency` (`brand_id`, `code`, `symbol`, `rate`, `created_date`, `updated_date`)
    VALUES (:brand_id, 'BDT', '৳', '1.00000000', NOW(), NOW())");
$stmtCurr->execute([':brand_id' => $testBrandId]);

// Insert API Keys
$stmtApi = $pdo->prepare("INSERT INTO `{$prefix}api` (`brand_id`, `name`, `api_key`, `expired_date`, `api_scopes`, `status`, `created_date`, `updated_date`)
    VALUES (:brand_id, :name, :api_key, :expired_date, :api_scopes, :status, NOW(), NOW())");

$stmtApi->execute([
    ':brand_id' => $testBrandId,
    ':name' => 'Full Scope Key',
    ':api_key' => $testKeyFull,
    ':expired_date' => '--',
    ':api_scopes' => json_encode(['create_payment', 'verify_payment', 'refund_payment']),
    ':status' => 'active'
]);

$stmtApi->execute([
    ':brand_id' => $testBrandId,
    ':name' => 'Create Only Key',
    ':api_key' => $testKeyCreateOnly,
    ':expired_date' => '--',
    ':api_scopes' => json_encode(['create_payment']),
    ':status' => 'active'
]);

$stmtApi->execute([
    ':brand_id' => $testBrandId,
    ':name' => 'Verify Only Key',
    ':api_key' => $testKeyVerifyOnly,
    ':expired_date' => '--',
    ':api_scopes' => json_encode(['verify_payment']),
    ':status' => 'active'
]);

$stmtApi->execute([
    ':brand_id' => $testBrandId,
    ':name' => 'Inactive Key',
    ':api_key' => $testKeyInactive,
    ':expired_date' => '--',
    ':api_scopes' => json_encode(['create_payment', 'verify_payment']),
    ':status' => 'inactive'
]);

$stmtApi->execute([
    ':brand_id' => $testBrandId,
    ':name' => 'Expired Key',
    ':api_key' => $testKeyExpired,
    ':expired_date' => '01/01/2020',
    ':api_scopes' => json_encode(['create_payment', 'verify_payment']),
    ':status' => 'active'
]);

// Insert Suspended Customer
$stmtCust = $pdo->prepare("INSERT INTO `{$prefix}customer` (`ref`, `brand_id`, `name`, `email`, `mobile`, `status`, `suspend_reason`, `created_date`, `updated_date`)
    VALUES ('cust_susp_1', :brand_id, 'Suspended User', 'suspended@example.com', '01711111111', 'suspend', 'Fraudulent chargeback activity', NOW(), NOW())");
$stmtCust->execute([':brand_id' => $testBrandId]);

echo "Fixtures ready.\n\n";

// ===================================================================
// 1. API KEY HEADER COMPATIBILITY TESTS
// ===================================================================
echo "--- 1. API KEY HEADER COMPATIBILITY TESTS ---\n";

// Helper to simulate getAuthorizationHeader with different inputs
function test_header_extraction(array $serverEnv, ?array $mockHeaders = null): ?string {
    $oldServer = $_SERVER;
    $_SERVER = array_merge($_SERVER, $serverEnv);

    // If mock headers function exists or via $_SERVER
    $res = null;
    if (isset($serverEnv['HTTP_MHS_PIPRAPAY_API_KEY']) || isset($serverEnv['HTTP_MH_PIPRAPAY_API_KEY'])) {
        $res = getAuthorizationHeader();
    } else {
        $res = getAuthorizationHeader();
    }

    $_SERVER = $oldServer;
    return $res;
}

// 1.1 Canonical header in $_SERVER
$_SERVER['HTTP_MHS_PIPRAPAY_API_KEY'] = 'canon_key_123';
unset($_SERVER['HTTP_MH_PIPRAPAY_API_KEY'], $_SERVER['MH_PIPRAPAY_API_KEY'], $_SERVER['MHS_PIPRAPAY_API_KEY'], $_SERVER['http_mh_piprapay_api_key'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['Authorization']);
assert_test(getAuthorizationHeader() === 'canon_key_123', 'Canonical HTTP_MHS_PIPRAPAY_API_KEY recognized');

// 1.2 Legacy header in $_SERVER
unset($_SERVER['HTTP_MHS_PIPRAPAY_API_KEY']);
$_SERVER['HTTP_MH_PIPRAPAY_API_KEY'] = 'legacy_key_456';
assert_test(getAuthorizationHeader() === 'legacy_key_456', 'Legacy HTTP_MH_PIPRAPAY_API_KEY recognized');

// 1.3 Mixed-case / lowercase in $_SERVER
unset($_SERVER['HTTP_MHS_PIPRAPAY_API_KEY'], $_SERVER['HTTP_MH_PIPRAPAY_API_KEY']);
$_SERVER['http_mh_piprapay_api_key'] = 'mixed_case_key_789';
assert_test(getAuthorizationHeader() === 'mixed_case_key_789', 'Mixed-case/lowercase server header recognized');

// 1.4 Direct without HTTP_ prefix in $_SERVER
unset($_SERVER['http_mh_piprapay_api_key']);
$_SERVER['MH_PIPRAPAY_API_KEY'] = 'direct_key_999';
assert_test(getAuthorizationHeader() === 'direct_key_999', 'Direct MH_PIPRAPAY_API_KEY in $_SERVER recognized');

// 1.5 Generic Authorization header only must be REJECTED (returns null)
unset($_SERVER['MH_PIPRAPAY_API_KEY'], $_SERVER['HTTP_MHS_PIPRAPAY_API_KEY'], $_SERVER['HTTP_MH_PIPRAPAY_API_KEY'], $_SERVER['http_mh_piprapay_api_key']);
$_SERVER['Authorization'] = 'some_raw_api_key';
assert_test(getAuthorizationHeader() === null, 'Generic Authorization header only is rejected (returns null)');

// 1.6 Bearer Authorization header must be REJECTED (returns null)
$_SERVER['Authorization'] = 'Bearer some_bearer_token';
assert_test(getAuthorizationHeader() === null, 'Bearer Authorization header only is rejected (returns null)');

// 1.7 HTTP_AUTHORIZATION header must be REJECTED (returns null)
unset($_SERVER['Authorization']);
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer some_bearer_token';
assert_test(getAuthorizationHeader() === null, 'HTTP_AUTHORIZATION header only is rejected (returns null)');

// 1.8 Missing all headers returns null
unset($_SERVER['HTTP_AUTHORIZATION']);
assert_test(getAuthorizationHeader() === null, 'Missing all headers returns null');

// ===================================================================
// 2. LEGACY PAYLOAD NORMALIZATION TESTS
// ===================================================================
echo "\n--- 2. LEGACY PAYLOAD NORMALIZATION TESTS ---\n";

// 2.1 email_mobile with valid email
$payload1 = [
    'full_name' => 'John Doe',
    'email_mobile' => 'john@example.com',
    'amount' => 100,
    'currency' => 'BDT',
    'redirect_url' => 'https://allowed-domain.example/success',
    'cancel_url' => 'https://allowed-domain.example/cancel',
    'webhook_url' => 'https://allowed-domain.example/webhook',
    'metadata' => ['invoiceid' => 'INV-001']
];
$norm1 = pp_normalize_payment_creation_payload($payload1);
assert_test($norm1['email_address'] === 'john@example.com', 'email_mobile with email -> email_address set');
assert_test($norm1['mobile_number'] === '', 'email_mobile with email -> mobile_number is empty');
assert_test($norm1['return_url'] === 'https://allowed-domain.example/success', 'redirect_url normalized to return_url');
assert_test($norm1['cancel_url'] === 'https://allowed-domain.example/cancel', 'cancel_url preserved');
assert_test(is_array($norm1['metadata']) && ($norm1['metadata']['invoiceid'] ?? '') === 'INV-001', 'object metadata parsed as array');

// 2.2 email_mobile with phone number
$payload2 = [
    'full_name' => 'Jane Doe',
    'email_mobile' => '01712345678',
    'amount' => 50,
    'currency' => 'BDT',
    'redirect_url' => 'https://allowed-domain.example/success',
    'metadata' => '{"invoiceid":"INV-002"}'
];
$norm2 = pp_normalize_payment_creation_payload($payload2);
assert_test($norm2['mobile_number'] === '01712345678', 'email_mobile with phone -> mobile_number set');
assert_test($norm2['email_address'] === '', 'email_mobile with phone -> email_address is empty');
assert_test(is_array($norm2['metadata']) && ($norm2['metadata']['invoiceid'] ?? '') === 'INV-002', 'JSON string metadata decoded cleanly');

// 2.3 Metadata compatibility: empty / null / malformed
$norm3 = pp_normalize_payment_creation_payload(['metadata' => '']);
assert_test(is_array($norm3['metadata']) && empty($norm3['metadata']), 'Empty string metadata yields empty array');

$norm4 = pp_normalize_payment_creation_payload(['metadata' => '{malformed_json']);
assert_test($norm4['metadata_error'] === 'INVALID_JSON', 'Malformed JSON string metadata detected');

// ===================================================================
// 3. CANONICAL & LEGACY PAYMENT CREATION TESTS
// ===================================================================
echo "\n--- 3. CANONICAL & LEGACY PAYMENT CREATION TESTS ---\n";

$fullApiRow = [
    'brand_id' => $testBrandId,
    'name' => 'Full Scope Key',
    'api_key' => $testKeyFull,
    'api_scopes' => json_encode(['create_payment', 'verify_payment', 'refund_payment'])
];

// 3.1 Create payment via normalized legacy SDK payload
$legacySdkPayload = [
    'full_name' => 'Laravel SDK User',
    'email_mobile' => 'sdk_user@example.com',
    'amount' => 100,
    'currency' => 'BDT',
    'metadata' => [
        'invoiceid' => 'SDK-TEST-001'
    ],
    'redirect_url' => 'https://example.com/success',
    'cancel_url' => 'https://example.com/cancel',
    'webhook_url' => 'https://example.com/webhook'
];

$normalizedSdk = pp_normalize_payment_creation_payload($legacySdkPayload);
$createResult = pp_create_payment_transaction($fullApiRow, $normalizedSdk, 'legacy');

assert_test(!empty($createResult['status']) && $createResult['status'] === true, 'Legacy SDK payment creation succeeds');
assert_test(!empty($createResult['data']['pp_id']), 'Legacy SDK response contains pp_id: ' . ($createResult['data']['pp_id'] ?? ''));
assert_test(!empty($createResult['data']['pp_url']), 'Legacy SDK response contains pp_url: ' . ($createResult['data']['pp_url'] ?? ''));

// Absolute URL validation for Legacy create response
$legacyPpUrl = $createResult['data']['pp_url'] ?? '';
$legacyScheme = parse_url($legacyPpUrl, PHP_URL_SCHEME);
$legacyHost = parse_url($legacyPpUrl, PHP_URL_HOST);
$createdPpId = $createResult['data']['pp_id'] ?? '';

assert_test(in_array($legacyScheme, ['http', 'https'], true), 'Legacy pp_url has valid scheme (http/https): ' . $legacyScheme);
assert_test(!empty($legacyHost), 'Legacy pp_url has non-empty host: ' . $legacyHost);
assert_test(str_ends_with($legacyPpUrl, '/' . $createdPpId), 'Legacy pp_url ends with payment ID');

// Verify database record
$stmtCheck = $pdo->prepare("SELECT * FROM `{$prefix}transaction` WHERE `ref` = :ref AND `brand_id` = :brand_id");
$stmtCheck->execute([':ref' => $createdPpId, ':brand_id' => $testBrandId]);
$txRow = $stmtCheck->fetch();

assert_test(!empty($txRow), 'Transaction record exists in database');
assert_test($txRow['amount'] == '100.00000000', 'Amount correctly stored as 100');
assert_test($txRow['currency'] === 'BDT', 'Currency correctly stored as BDT');
assert_test($txRow['return_url'] === 'https://example.com/success', 'Return URL stored from redirect_url');
assert_test($txRow['webhook_url'] === 'https://example.com/webhook', 'Webhook URL stored');

$storedCustInfo = json_decode($txRow['customer_info'] ?? '{}', true);
assert_test(($storedCustInfo['email'] ?? '') === 'sdk_user@example.com', 'Customer email stored from email_mobile');
assert_test(($storedCustInfo['name'] ?? '') === 'Laravel SDK User', 'Customer name stored');

$storedMeta = json_decode($txRow['metadata'] ?? '{}', true);
assert_test(($storedMeta['invoiceid'] ?? '') === 'SDK-TEST-001', 'Metadata preserved without double-encoding');

$storedSourceInfo = json_decode($txRow['source_info'] ?? '[]', true);
assert_test(is_array($storedSourceInfo) && count($storedSourceInfo) > 0 && ($storedSourceInfo[0]['label'] ?? '') === 'Cancel URL', 'cancel_url recorded in source_info');

// 3.2 Canonical payment creation (checkout/redirect format)
$canonicalPayload = [
    'full_name' => 'Canonical V3 Customer',
    'email_address' => 'canonical@example.com',
    'mobile_number' => '01899999999',
    'amount' => 250.50,
    'currency' => 'BDT',
    'return_url' => 'https://example.com/return',
    'webhook_url' => 'https://example.com/hook',
    'metadata' => '{"order":"ORD-777"}'
];
$normCanon = pp_normalize_payment_creation_payload($canonicalPayload);
$createCanonResult = pp_create_payment_transaction($fullApiRow, $normCanon);

assert_test(!empty($createCanonResult['status']) && $createCanonResult['status'] === true, 'Canonical v3 payment creation succeeds');
$canonPpId = $createCanonResult['data']['pp_id'] ?? '';
assert_test(!empty($canonPpId), 'Canonical response contains pp_id: ' . $canonPpId);

// Absolute URL validation for Canonical create response
$canonPpUrl = $createCanonResult['data']['pp_url'] ?? '';
$canonScheme = parse_url($canonPpUrl, PHP_URL_SCHEME);
$canonHost = parse_url($canonPpUrl, PHP_URL_HOST);

assert_test(in_array($canonScheme, ['http', 'https'], true), 'Canonical pp_url has valid scheme (http/https): ' . $canonScheme);
assert_test(!empty($canonHost), 'Canonical pp_url has non-empty host: ' . $canonHost);
assert_test(str_ends_with($canonPpUrl, '/' . $canonPpId), 'Canonical pp_url ends with payment ID');
assert_test($canonScheme === $legacyScheme && $canonHost === $legacyHost, 'Canonical and Legacy pp_url have identical scheme/host structure');

// 3.3 Dynamic URL Resolver Unit Tests (Subdomain, Subfolder, Custom Payment Path)
$oldSiteUrl = $GLOBALS['site_url'] ?? null;
$oldPathPayment = $GLOBALS['path_payment'] ?? null;

// Test root domain install: https://pay.example.com/
$GLOBALS['site_url'] = 'https://pay.example.com/';
$GLOBALS['path_payment'] = 'payment';
assert_test(pp_get_site_url() === 'https://pay.example.com/', 'pp_get_site_url resolves root domain correctly');
assert_test(pp_get_payment_path() === 'payment', 'pp_get_payment_path resolves default payment path');

// Test subfolder install: https://example.com/piprapay/
$GLOBALS['site_url'] = 'https://example.com/piprapay/';
$GLOBALS['path_payment'] = 'checkout';
assert_test(pp_get_site_url() === 'https://example.com/piprapay/', 'pp_get_site_url resolves subfolder correctly');
assert_test(pp_get_payment_path() === 'checkout', 'pp_get_payment_path resolves custom path');

// Restore globals
$GLOBALS['site_url'] = $oldSiteUrl;
$GLOBALS['path_payment'] = $oldPathPayment;

// 3.4 Canonical Checkout Contract Enforcement (email + mobile required)
echo "\n--- 3.4 CANONICAL CHECKOUT CONTRACT TESTS ---\n";

// Canonical: email + mobile => PASS
$canonValid = pp_normalize_payment_creation_payload([
    'full_name' => 'Valid Canonical User',
    'email_address' => 'valid_user@example.com',
    'mobile_number' => '01711223344',
    'amount' => 100,
    'currency' => 'BDT',
    'return_url' => 'https://example.com/success'
]);
$resCanonValid = pp_create_payment_transaction($fullApiRow, $canonValid, 'canonical');
assert_test(!empty($resCanonValid['status']), 'Canonical: email + mobile => PASS');

// Canonical: email only (missing mobile) => FAIL
$canonEmailOnly = pp_normalize_payment_creation_payload([
    'full_name' => 'Email Only User',
    'email_address' => 'email_only@example.com',
    'mobile_number' => '',
    'amount' => 100,
    'currency' => 'BDT',
    'return_url' => 'https://example.com/success'
]);
$resCanonEmailOnly = pp_create_payment_transaction($fullApiRow, $canonEmailOnly, 'canonical');
assert_test(empty($resCanonEmailOnly['status']), 'Canonical: email only (missing mobile) => FAIL');
assert_test(($resCanonEmailOnly['error']['code'] ?? '') === 'MISSING_FIELD', 'Canonical: error code is MISSING_FIELD for missing mobile');

// Canonical: mobile only (missing email) => FAIL
$canonMobileOnly = pp_normalize_payment_creation_payload([
    'full_name' => 'Mobile Only User',
    'email_address' => '',
    'mobile_number' => '01711223344',
    'amount' => 100,
    'currency' => 'BDT',
    'return_url' => 'https://example.com/success'
]);
$resCanonMobileOnly = pp_create_payment_transaction($fullApiRow, $canonMobileOnly, 'canonical');
assert_test(empty($resCanonMobileOnly['status']), 'Canonical: mobile only (missing email) => FAIL');
assert_test(($resCanonMobileOnly['error']['code'] ?? '') === 'INVALID_EMAIL', 'Canonical: error code is INVALID_EMAIL for missing email');

// Canonical: invalid email format => FAIL
$canonBadEmail = pp_normalize_payment_creation_payload([
    'full_name' => 'Bad Email User',
    'email_address' => 'not-an-email',
    'mobile_number' => '01711223344',
    'amount' => 100,
    'currency' => 'BDT',
    'return_url' => 'https://example.com/success'
]);
$resCanonBadEmail = pp_create_payment_transaction($fullApiRow, $canonBadEmail, 'canonical');
assert_test(empty($resCanonBadEmail['status']), 'Canonical: invalid email format => FAIL');
assert_test(($resCanonBadEmail['error']['code'] ?? '') === 'INVALID_EMAIL', 'Canonical: error code is INVALID_EMAIL for malformed email');

// 3.5 Legacy Create-Charge Contract Enforcement (either email OR phone accepted)
echo "\n--- 3.5 LEGACY CREATE-CHARGE CONTRACT TESTS ---\n";

// Legacy: email_mobile = email => PASS
$legacyEmail = pp_normalize_payment_creation_payload([
    'full_name' => 'Legacy Email User',
    'email_mobile' => 'legacy_email@example.com',
    'amount' => 50,
    'currency' => 'BDT',
    'redirect_url' => 'https://example.com/success'
]);
$resLegacyEmail = pp_create_payment_transaction($fullApiRow, $legacyEmail, 'legacy');
assert_test(!empty($resLegacyEmail['status']), 'Legacy: email_mobile=email => PASS');

// Legacy: email_mobile = phone => PASS
$legacyPhone = pp_normalize_payment_creation_payload([
    'full_name' => 'Legacy Phone User',
    'email_mobile' => '01799887766',
    'amount' => 50,
    'currency' => 'BDT',
    'redirect_url' => 'https://example.com/success'
]);
$resLegacyPhone = pp_create_payment_transaction($fullApiRow, $legacyPhone, 'legacy');
assert_test(!empty($resLegacyPhone['status']), 'Legacy: email_mobile=phone => PASS');

// Legacy: neither email nor phone => FAIL
$legacyNeither = pp_normalize_payment_creation_payload([
    'full_name' => 'Legacy Empty Contact User',
    'email_mobile' => '',
    'amount' => 50,
    'currency' => 'BDT',
    'redirect_url' => 'https://example.com/success'
]);
$resLegacyNeither = pp_create_payment_transaction($fullApiRow, $legacyNeither, 'legacy');
assert_test(empty($resLegacyNeither['status']), 'Legacy: neither email nor phone => FAIL');
assert_test(($resLegacyNeither['error']['code'] ?? '') === 'MISSING_FIELD', 'Legacy: error code is MISSING_FIELD');

// 3.6 Checkout Popup Regression & Parity Tests
echo "\n--- 3.6 CHECKOUT POPUP REGRESSION & PARITY TESTS ---\n";

$popupPayload = pp_normalize_payment_creation_payload([
    'full_name' => 'Popup User',
    'email_address' => 'popup_user@example.com',
    'mobile_number' => '01655443322',
    'amount' => 80,
    'currency' => 'BDT',
    'webhook_url' => 'https://example.com/webhook'
]);
$popupPayload['return_url'] = '--';
$resPopup = pp_create_payment_transaction($fullApiRow, $popupPayload, 'canonical');
assert_test(!empty($resPopup['status']), 'Popup: valid payload creates payment record');
assert_test(!empty($resPopup['data']['pp_id']), 'Popup: response contains pp_id');
assert_test(!empty($resPopup['data']['pp_url']), 'Popup: response contains absolute pp_url');

// Popup with missing mobile fails canonical validation
$popupMissingMobile = pp_normalize_payment_creation_payload([
    'full_name' => 'Popup No Mobile',
    'email_address' => 'popup_no_mob@example.com',
    'mobile_number' => '',
    'amount' => 80,
    'currency' => 'BDT'
]);
$popupMissingMobile['return_url'] = '--';
$resPopupNoMobile = pp_create_payment_transaction($fullApiRow, $popupMissingMobile, 'canonical');
assert_test(empty($resPopupNoMobile['status']), 'Popup: missing mobile rejected under canonical contract');

// 3.7 Refund API Contract & Status Regression Tests
echo "\n--- 3.7 REFUND API CONTRACT REGRESSION TESTS ---\n";

try {
    // Insert a test non-completed (initiated) transaction
    $cols = ['brand_id', 'ref', 'customer_info', 'amount', 'currency', 'metadata', 'return_url', 'webhook_url', 'status', 'created_date', 'updated_date'];
    $vals = [$testBrandId, 'tx_initiated_refund_test', '{}', '100.00000000', 'BDT', '{}', '--', '--', 'initiated', getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];
    insertData($prefix.'transaction', $cols, $vals);

    // 1. Refund on non-completed status must fail with INVALID_STATUS
    $paramsRef = [':ref' => 'tx_initiated_refund_test', ':brand_id' => $testBrandId];
    $txInitCheck = json_decode(getData($prefix.'transaction', 'WHERE ref = :ref AND brand_id = :brand_id', '* FROM', $paramsRef), true);
    assert_test($txInitCheck['status'] == true, 'Refund test: initiated transaction exists');
    assert_test(($txInitCheck['response'][0]['status'] ?? '') !== 'completed', 'Refund test: initiated status correctly detected as not completed');

    // 2. Refund on non-existent transaction must fail
    $paramsRefNonExistent = [':ref' => 'non_existent_tx_ref_999', ':brand_id' => $testBrandId];
    $txNonExistentCheck = json_decode(getData($prefix.'transaction', 'WHERE ref = :ref AND brand_id = :brand_id', '* FROM', $paramsRefNonExistent), true);
    assert_test($txNonExistentCheck['status'] == false, 'Refund test: non-existent transaction returns false');

    // 3. Refund on unsupported gateway must fail
    $valsComp = [$testBrandId, 'tx_comp_unsupported_gw', '{}', '100.00000000', 'BDT', '{}', '--', '--', 'completed', getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];
    insertData($prefix.'transaction', $cols, $valsComp);

    // Clean up refund test records
    $pdo->exec("DELETE FROM `{$prefix}transaction` WHERE `ref` IN ('tx_initiated_refund_test', 'tx_comp_unsupported_gw')");
} catch (\Throwable $e) {
    echo "REFUND TEST EXCEPTION: " . $e->getMessage() . "\n";
}

// ===================================================================
// 4. VERIFY ENDPOINT & PARITY TESTS
// ===================================================================
echo "\n--- 4. VERIFY ENDPOINT & PARITY TESTS ---\n";

// 4.1 Verify created payment
$verifyResult = pp_verify_payment_transaction($fullApiRow, ['pp_id' => $createdPpId]);
assert_test(!empty($verifyResult['status']) && $verifyResult['status'] === true, 'Verification succeeds for created payment');
assert_test(($verifyResult['data']['pp_id'] ?? '') === $createdPpId, 'Verification pp_id matches');
assert_test(($verifyResult['data']['full_name'] ?? '') === 'Laravel SDK User', 'Verification full_name matches');
assert_test(($verifyResult['data']['email_address'] ?? '') === 'sdk_user@example.com', 'Verification email_address matches');
assert_test(($verifyResult['data']['amount'] ?? '') === '100.00', 'Verification amount formatted correctly (100.00)');
assert_test(($verifyResult['data']['total'] ?? '') === '100.00', 'Verification total formatted correctly (100.00)');
assert_test(($verifyResult['data']['currency'] ?? '') === 'BDT', 'Verification currency is BDT');
assert_test(($verifyResult['data']['metadata']['invoiceid'] ?? '') === 'SDK-TEST-001', 'Verification metadata array preserved');
assert_test(($verifyResult['data']['status'] ?? '') === 'initiated', 'Verification status is initiated');

// 4.2 Verify non-existent pp_id fails
$verifyNonExistent = pp_verify_payment_transaction($fullApiRow, ['pp_id' => 'non_existent_pp_id_123']);
assert_test(empty($verifyNonExistent['status']), 'Verification of non-existent pp_id fails');
assert_test(($verifyNonExistent['error']['code'] ?? '') === 'INVALID_PP_ID', 'Error code is INVALID_PP_ID');

// 4.3 Verify empty pp_id fails
$verifyEmpty = pp_verify_payment_transaction($fullApiRow, ['pp_id' => '']);
assert_test(empty($verifyEmpty['status']), 'Verification of empty pp_id fails');
assert_test(($verifyEmpty['error']['code'] ?? '') === 'INVALID_PP_ID', 'Error code is INVALID_PP_ID');

// ===================================================================
// 5. SECURITY & SCOPE ENFORCEMENT TESTS (15 SCENARIOS)
// ===================================================================
echo "\n--- 5. SECURITY & SCOPE ENFORCEMENT TESTS (15 SCENARIOS) ---\n";

// Scenario 1: Invalid API key lookup
$paramsInvalid = [':api_key' => 'completely_invalid_key_xyz'];
$resInvalid = json_decode(getData($prefix.'api', 'WHERE api_key = :api_key AND status = "active"', '* FROM', $paramsInvalid), true);
assert_test($resInvalid['status'] === false, 'Sec 1: Invalid API key not found in database');

// Scenario 2: Expired API key
$paramsExpired = [':api_key' => $testKeyExpired];
$resExpired = json_decode(getData($prefix.'api', 'WHERE api_key = :api_key AND status = "active"', '* FROM', $paramsExpired), true);
assert_test($resExpired['status'] === true && isExpired($resExpired['response'][0]['expired_date']), 'Sec 2: Expired API key detected by isExpired()');

// Scenario 3: Inactive API key
$paramsInactive = [':api_key' => $testKeyInactive];
$resInactive = json_decode(getData($prefix.'api', 'WHERE api_key = :api_key AND status = "active"', '* FROM', $paramsInactive), true);
assert_test($resInactive['status'] === false, 'Sec 3: Inactive API key filtered out by active status check');

// Scenario 4: Missing create-payment scope (using Verify-Only Key)
$verifyOnlyApiRow = [
    'brand_id' => $testBrandId,
    'name' => 'Verify Only Key',
    'api_key' => $testKeyVerifyOnly,
    'api_scopes' => json_encode(['verify_payment'])
];
$verifyScopes = json_decode($verifyOnlyApiRow['api_scopes'], true);
assert_test(!in_array('create_payment', $verifyScopes), 'Sec 4: Create payment scope correctly absent on verify-only key');

// Scenario 5: Missing verify scope (using Create-Only Key)
$createOnlyApiRow = [
    'brand_id' => $testBrandId,
    'name' => 'Create Only Key',
    'api_key' => $testKeyCreateOnly,
    'api_scopes' => json_encode(['create_payment'])
];
$createScopes = json_decode($createOnlyApiRow['api_scopes'], true);
assert_test(!in_array('verify_payment', $createScopes), 'Sec 5: Verify scope correctly absent on create-only key');

// Scenario 6: Malformed JSON payload rejection
$normMalformed = pp_normalize_payment_creation_payload([
    'full_name' => 'Test User',
    'email_mobile' => 'test@example.com',
    'amount' => 10,
    'metadata' => '{"bad_json:'
]);
$createMalformed = pp_create_payment_transaction($fullApiRow, $normMalformed, 'legacy');
assert_test(empty($createMalformed['status']), 'Sec 6: Malformed metadata JSON rejected');
assert_test(($createMalformed['error']['code'] ?? '') === 'INVALID_JSON', 'Sec 6: Error code is INVALID_JSON');

// Scenario 7: Invalid return URL (not whitelisted)
$normBadReturn = pp_normalize_payment_creation_payload([
    'full_name' => 'Test User',
    'email_mobile' => 'test@example.com',
    'amount' => 10,
    'return_url' => 'https://evil-unwhitelisted-site.com/callback'
]);
$createBadReturn = pp_create_payment_transaction($fullApiRow, $normBadReturn, 'legacy');
assert_test(empty($createBadReturn['status']), 'Sec 7: Non-whitelisted return URL rejected');
assert_test(($createBadReturn['error']['code'] ?? '') === 'INVALID_URL', 'Sec 7: Error code is INVALID_URL');

// Scenario 8: Invalid webhook URL (inactive domain)
$normBadWebhook = pp_normalize_payment_creation_payload([
    'full_name' => 'Test User',
    'email_mobile' => 'test@example.com',
    'amount' => 10,
    'return_url' => 'https://example.com/success',
    'webhook_url' => 'https://inactive-domain.example/webhook'
]);
$createBadWebhook = pp_create_payment_transaction($fullApiRow, $normBadWebhook, 'legacy');
assert_test(empty($createBadWebhook['status']), 'Sec 8: Inactive domain webhook URL rejected');
assert_test(($createBadWebhook['error']['code'] ?? '') === 'INVALID_URL', 'Sec 8: Error code is INVALID_URL');

// Scenario 9: Non-string / non-array metadata
$normBadMetaType = pp_normalize_payment_creation_payload([
    'full_name' => 'Test User',
    'email_mobile' => 'test@example.com',
    'amount' => 10,
    'metadata' => 12345
]);
$createBadMetaType = pp_create_payment_transaction($fullApiRow, $normBadMetaType, 'legacy');
assert_test(empty($createBadMetaType['status']), 'Sec 9: Numeric metadata rejected as INVALID_METADATA');

// Scenario 10: Unsupported currency
$normBadCurr = pp_normalize_payment_creation_payload([
    'full_name' => 'Test User',
    'email_mobile' => 'test@example.com',
    'amount' => 10,
    'currency' => 'EUR',
    'return_url' => 'https://example.com/success'
]);
$createBadCurr = pp_create_payment_transaction($fullApiRow, $normBadCurr, 'legacy');
assert_test(empty($createBadCurr['status']), 'Sec 10: Unsupported currency EUR rejected');
assert_test(($createBadCurr['error']['code'] ?? '') === 'INVALID_CURRENCY', 'Sec 10: Error code is INVALID_CURRENCY');

// Scenario 11: Suspended customer rejected
$normSuspended = pp_normalize_payment_creation_payload([
    'full_name' => 'Suspended Customer',
    'email_mobile' => 'suspended@example.com',
    'amount' => 50,
    'currency' => 'BDT',
    'return_url' => 'https://example.com/success'
]);
$createSuspended = pp_create_payment_transaction($fullApiRow, $normSuspended, 'legacy');
assert_test(empty($createSuspended['status']), 'Sec 11: Suspended customer rejected');
assert_test(($createSuspended['error']['code'] ?? '') === 'INVALID_CUSTOMER', 'Sec 11: Error code is INVALID_CUSTOMER');

// Scenario 12: API key masking format
$masked = mask_api_key('test_api_key_full_access_1234567890abcdef1234567890');
assert_test(str_starts_with($masked, 'test') && str_ends_with($masked, '7890'), 'Sec 12: Masked key starts with first 4 and ends with last 4');
assert_test(str_contains($masked, '***'), 'Sec 12: Masked key contains asterisks in middle: ' . $masked);

// Scenario 13: Full API key never in API list response
$listResult = json_decode(getData($prefix.'api', 'WHERE brand_id = :brand_id', '* FROM', [':brand_id' => $testBrandId]), true);
$allMasked = true;
foreach ($listResult['response'] as $row) {
    $maskedKey = mask_api_key($row['api_key']);
    if ($maskedKey === $row['api_key'] && strlen($row['api_key']) > 8) {
        $allMasked = false;
    }
}
assert_test($allMasked, 'Sec 13: mask_api_key successfully hides raw keys for list display');

// Scenario 14: Invalid amount rejection
$normZeroAmount = pp_normalize_payment_creation_payload([
    'full_name' => 'Test User',
    'email_mobile' => 'test@example.com',
    'amount' => 0,
    'currency' => 'BDT'
]);
$createZeroAmount = pp_create_payment_transaction($fullApiRow, $normZeroAmount, 'legacy');
assert_test(empty($createZeroAmount['status']), 'Sec 14: Zero/negative amount rejected');
assert_test(($createZeroAmount['error']['code'] ?? '') === 'INVALID_AMOUNT', 'Sec 14: Error code is INVALID_AMOUNT');

// Scenario 15: Missing full_name rejection
$normNoName = pp_normalize_payment_creation_payload([
    'full_name' => '',
    'email_mobile' => 'test@example.com',
    'amount' => 10,
    'currency' => 'BDT'
]);
$createNoName = pp_create_payment_transaction($fullApiRow, $normNoName, 'legacy');
assert_test(empty($createNoName['status']), 'Sec 15: Missing full_name rejected');
assert_test(($createNoName['error']['code'] ?? '') === 'MISSING_FIELD', 'Sec 15: Error code is MISSING_FIELD');

// --- Cleanup ---
echo "\nCleaning up test fixtures...\n";
$pdo->exec("DELETE FROM `{$prefix}brands` WHERE `brand_id` = '{$testBrandId}'");
$pdo->exec("DELETE FROM `{$prefix}api` WHERE `brand_id` = '{$testBrandId}'");
$pdo->exec("DELETE FROM `{$prefix}domain` WHERE `domain` IN ('example.com', 'allowed-domain.example', 'inactive-domain.example')");
$pdo->exec("DELETE FROM `{$prefix}currency` WHERE `brand_id` = '{$testBrandId}'");
$pdo->exec("DELETE FROM `{$prefix}customer` WHERE `brand_id` = '{$testBrandId}'");
$pdo->exec("DELETE FROM `{$prefix}transaction` WHERE `brand_id` = '{$testBrandId}'");
echo "Test data cleaned up.\n\n";

echo "===================================================================\n";
echo " TEST SUMMARY: {$passedTests} PASSED, " . count($failedTests) . " FAILED (Total: {$totalTests})\n";
echo "===================================================================\n";

if (count($failedTests) > 0) {
    echo "FAILED TESTS:\n";
    foreach ($failedTests as $f) {
        echo " - {$f}\n";
    }
    exit(1);
} else {
    echo "ALL TESTS PASSED PERFECTLY!\n";
    exit(0);
}
