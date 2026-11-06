<?php
/**
 * PipraPay - checkout template integration tests
 * ==============================================
 *
 * Renders the real custom checkout template in a child process (the template
 * issues exit() on the automatic-redirect path) and asserts the observable
 * behaviour:
 *
 *  1. A single eligible EPS gateway in the Cards category redirects straight to
 *     the hosted checkout without any user interaction.
 *  2. bKash / Nagad Personal in Mobile Banking do not block that behaviour.
 *  3. ?manual=1 renders the normal gateway selection screen.
 *  4. ?eps_error=session renders a customer-safe banner and no redirect.
 *
 * Usage: php tests/test_eps_checkout_template.php
 */

declare(strict_types=1);

$harness = __DIR__ . '/fixtures/checkout_template_harness.php';
$php = PHP_BINARY;

$GLOBALS['total'] = 0;
$GLOBALS['failed'] = [];

function check(string $name, bool $condition, string $detail = ''): void
{
    $GLOBALS['total']++;

    if ($condition) {
        echo "  PASS  {$name}\n";
        return;
    }

    $GLOBALS['failed'][] = $name;
    echo "  FAIL  {$name}" . ($detail !== '' ? "  ({$detail})" : '') . "\n";
}

function render(string $harness, string $php, string $scenario): string
{
    $command = escapeshellarg($php) . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($scenario) . ' 2>&1';

    return (string) shell_exec($command);
}

if (!file_exists($harness)) {
    fwrite(STDERR, "Harness not found: {$harness}\n");
    exit(1);
}

echo "\n[Checkout template - automatic EPS start]\n";

$auto = render($harness, $php, 'auto');
check('Template renders a redirect instead of the gateway grid', strpos($auto, 'http-equiv="refresh"') !== false, substr($auto, 0, 160));
check('Redirect targets the EPS gateway', strpos($auto, 'gateway=gw_eps') !== false);
check('Redirect keeps the Cards category', strpos($auto, 'category=cards') !== false);
check('Redirect keeps the transaction reference in the path', strpos($auto, '/payment/TXN-1?') !== false);
check('No gateway selection UI is rendered on the auto path', strpos($auto, 'gateway-card') === false);
check('No PHP warning or notice is emitted', stripos($auto, 'Warning:') === false && stripos($auto, 'Notice:') === false && stripos($auto, 'Deprecated:') === false);

echo "\n[Checkout template - manual fallback]\n";

$manual = render($harness, $php, 'manual');
check('Manual mode renders the gateway grid', strpos($manual, 'gateway-card') !== false);
check('Manual mode renders the Pay button', strpos($manual, 'pay-submit-btn') !== false);
check('Manual mode lists the EPS gateway', strpos($manual, 'data-gateway-id="gw_eps"') !== false);
check('Manual mode keeps bKash Personal available', strpos($manual, 'data-gateway-id="gw_bkash"') !== false);
check('Manual mode keeps Nagad Personal available', strpos($manual, 'data-gateway-id="gw_nagad"') !== false);
check('Manual mode does not redirect', strpos($manual, 'http-equiv="refresh"') === false);
check('Manual mode emits no PHP warning', stripos($manual, 'Warning:') === false && stripos($manual, 'Notice:') === false);

echo "\n[Checkout template - error banner]\n";

$error = render($harness, $php, 'error');
check('Error mode renders the alert banner', strpos($error, 'eps-checkout-alert') !== false);
check('Error banner carries a customer-safe message', strpos($error, 'did not create a valid payment session') !== false);
check('Error banner does not leak the internal code', strpos($error, 'EPS_INIT_') === false);
check('Error mode does not redirect', strpos($error, 'http-equiv="refresh"') === false);
check('Error mode still allows choosing another method', strpos($error, 'data-gateway-id="gw_bkash"') !== false);

echo "\n------------------------------------------------------------\n";
$total = (int) $GLOBALS['total'];
$failed = $GLOBALS['failed'];

echo "Assertions: {$total} | Passed: " . ($total - count($failed)) . " | Failed: " . count($failed) . "\n";

if (!empty($failed)) {
    echo "\nFailed assertions:\n";
    foreach ($failed as $name) {
        echo "  - {$name}\n";
    }
    echo "\nRESULT: FAIL\n";
    exit(1);
}

echo "RESULT: PASS\n";
exit(0);
