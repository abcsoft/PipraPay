<?php
/**
 * PipraPay - EPS gateway regression tests
 * =======================================
 *
 * DB-free, network-free test-suite for the EPS integration and the automatic
 * checkout decision. All provider traffic is stubbed through a subclass, so no
 * real payment, no credentials and no database are required.
 *
 * Usage:
 *   php tests/test_eps_gateway.php
 *
 * Exit code 0 means every assertion passed.
 */

declare(strict_types=1);

define('PipraPay_INIT', true);

// ---------------------------------------------------------------------------
// Minimal application stubs (only the functions the gateway really calls).
// ---------------------------------------------------------------------------

$GLOBALS['pp_test_checkout_base'] = 'https://pay.example.test/payment/TXN-1';
$GLOBALS['pp_test_completions'] = [];

function pp_site_url($type = 'Full') { return 'https://pay.example.test'; }
function pp_site_address() { return 'https://pay.example.test/'; }
function pp_callback_url() { return 'https://pay.example.test/?pp_callback'; }
function pp_checkout_address($paymentid = '') { return $GLOBALS['pp_test_checkout_base']; }

function pp_set_transaction_status($transactionid, $status = '', $gateway_id = '', $trxid = '', $source_info = [])
{
    $GLOBALS['pp_test_completions'][] = [
        'ref'        => $transactionid,
        'status'     => $status,
        'gateway_id' => $gateway_id,
        'trxid'      => $trxid,
        'source'     => $source_info,
    ];

    return true;
}

require_once dirname(__DIR__) . '/pp-content/pp-include/pp-checkout-helpers.php';
require_once dirname(__DIR__) . '/pp-content/pp-modules/pp-gateways/eps/class.php';

// ---------------------------------------------------------------------------
// Test double
// ---------------------------------------------------------------------------

class TestEpsGateway extends EpsGateway
{
    public array $postQueue = [];
    public array $getQueue = [];
    public array $postCalls = [];
    public array $getCalls = [];
    public array $sessions = [];
    public array $completions = [];
    public array $logs = [];
    public bool $completionResult = true;

    protected function httpPostJson($url, array $headers, array $payload)
    {
        $this->postCalls[] = ['url' => $url, 'headers' => $headers, 'payload' => $payload];

        if (empty($this->postQueue)) {
            return eps_transport_failure();
        }

        return array_shift($this->postQueue);
    }

    protected function httpGetJson($url, array $headers)
    {
        $this->getCalls[] = ['url' => $url, 'headers' => $headers];

        if (empty($this->getQueue)) {
            return eps_transport_failure();
        }

        return array_shift($this->getQueue);
    }

    protected function loadSession($reference)
    {
        return $this->sessions[$reference] ?? [];
    }

    protected function saveSession($reference, array $session)
    {
        $this->sessions[$reference] = $session;

        return true;
    }

    protected function completeTransaction($reference, $gatewayId, $providerTransactionId, array $sourceInfo)
    {
        $this->completions[] = [
            'ref'        => $reference,
            'gateway_id' => $gatewayId,
            'trxid'      => $providerTransactionId,
            'source'     => $sourceInfo,
        ];

        return $this->completionResult;
    }

    protected function log($code, array $context = [])
    {
        $this->logs[] = $code . '|' . json_encode($context);
    }

    public function validateUrl($url)
    {
        return $this->validateRedirectUrl($url);
    }

    public function payloadFor(array $data)
    {
        $this->postQueue = [eps_ok(['token' => 'stub-token-0123456789']), eps_ok(['RedirectURL' => 'https://pg.eps.com.bd/PG?data=valid-session-token-9876543210'])];
        $this->initiate($data);

        return $this->postCalls[1]['payload'] ?? [];
    }
}

// ---------------------------------------------------------------------------
// Response factories
// ---------------------------------------------------------------------------

function eps_ok(array $body): array
{
    return ['ok' => true, 'errno' => 0, 'error' => '', 'status' => 200, 'body' => json_encode($body)];
}

function eps_http(int $status, $body = ''): array
{
    return [
        'ok'     => true,
        'errno'  => 0,
        'error'  => '',
        'status' => $status,
        'body'   => is_string($body) ? $body : json_encode($body),
    ];
}

function eps_transport_failure(): array
{
    return ['ok' => false, 'errno' => 7, 'error' => 'could not connect', 'status' => 0, 'body' => ''];
}

function eps_token(): array
{
    return eps_ok(['token' => 'stub-token-0123456789']);
}

function eps_init(string $url): array
{
    return eps_ok(['RedirectURL' => $url]);
}

function eps_status(string $ref, array $extra = []): array
{
    return eps_ok(array_merge([
        'Status'                 => 'Success',
        'ValueA'                 => $ref,
        'MerchantTransactionId'  => 'mtx-1',
        'EPSTransactionId'       => 'eps-1',
        'FinancialEntity'        => 'BKASH',
    ], $extra));
}

function eps_payload_data(array $overrides = []): array
{
    $data = [
        'options' => [
            'merchant_id' => 'MERCHANT-1',
            'store_id'    => 'STORE-1',
            'username'    => 'eps-user',
            'password'    => 'eps-pass',
            'hashkey'     => 'eps-hash-key',
            'mode'        => 'sandbox',
        ],
        'transaction' => [
            'ref'              => 'TXN-1',
            'amount'           => '100.00',
            'local_net_amount' => '100.00',
            'local_currency'   => 'BDT',
            'status'           => 'initiated',
            'customer'         => [
                'name'   => 'Test Customer',
                'email'  => 'customer@example.test',
                'mobile' => '01711111111',
            ],
        ],
        'gateway' => [
            'gateway_id' => 'gw_eps',
            'slug'       => 'eps',
        ],
        'brand' => ['id' => 'BRAND-1'],
    ];

    return array_replace_recursive($data, $overrides);
}

function eps_gateway_rows(array $rows): array
{
    return $rows;
}

// ---------------------------------------------------------------------------
// Assertion helper
// ---------------------------------------------------------------------------

$GLOBALS['pp_total'] = 0;
$GLOBALS['pp_failed'] = [];

function check(string $name, bool $condition, string $detail = ''): void
{
    $GLOBALS['pp_total']++;

    if ($condition) {
        echo "  PASS  {$name}\n";
        return;
    }

    $GLOBALS['pp_failed'][] = $name;
    echo "  FAIL  {$name}" . ($detail !== '' ? "  ({$detail})" : '') . "\n";
}

function reset_get(array $params = []): void
{
    $_GET = $params;
}

// ---------------------------------------------------------------------------
// 1. Automatic checkout decision (Cards category)
// ---------------------------------------------------------------------------

echo "\n[Automatic checkout decision]\n";

$base = 'https://pay.example.test/payment/TXN-1';

$epsOnlyCards = [
    'cards'          => [['gateway_id' => 'gw_eps', 'slug' => 'eps']],
    'mobile_banking' => [],
    'net_banking'    => [],
];

$target = pp_custom_auto_checkout_target($epsOnlyCards, $base, []);
check('EPS as the only Cards gateway auto-starts the hosted checkout', $target['auto'] === true, $target['reason']);
check('Auto target keeps the transaction reference in the checkout URL', strpos($target['url'], $base . '?') === 0, $target['url']);
check(
    'Auto target passes the gateway id and Cards category',
    strpos($target['url'], 'gateway=gw_eps') !== false && strpos($target['url'], 'category=cards') !== false,
    $target['url']
);

$bkashActive = $epsOnlyCards;
$bkashActive['mobile_banking'] = [['gateway_id' => 'gw_bkash', 'slug' => 'bkash-personal']];
$target = pp_custom_auto_checkout_target($bkashActive, $base, []);
check('EPS + active bKash Personal still auto-starts EPS', $target['auto'] === true, $target['reason']);

$nagadActive = $epsOnlyCards;
$nagadActive['mobile_banking'] = [['gateway_id' => 'gw_nagad', 'slug' => 'nagad-personal']];
$target = pp_custom_auto_checkout_target($nagadActive, $base, []);
check('EPS + active Nagad Personal still auto-starts EPS', $target['auto'] === true, $target['reason']);

$multipleCards = [
    'cards' => [
        ['gateway_id' => 'gw_eps', 'slug' => 'eps'],
        ['gateway_id' => 'gw_ssl', 'slug' => 'sslcommerz'],
    ],
    'mobile_banking' => [],
    'net_banking'    => [],
];
$target = pp_custom_auto_checkout_target($multipleCards, $base, []);
check(
    'Multiple Cards gateways keep the normal selection screen',
    $target['auto'] === false && $target['reason'] === 'multiple_card_gateways',
    $target['reason']
);

$target = pp_custom_auto_checkout_target(['cards' => [], 'mobile_banking' => [], 'net_banking' => []], $base, []);
check('No eligible Cards gateway does not auto-start', $target['auto'] === false && $target['reason'] === 'no_card_gateway', $target['reason']);

// Inactive EPS: pp_gateways() only returns active rows, so the Cards bucket is empty.
$inactiveEps = ['cards' => [], 'mobile_banking' => [['gateway_id' => 'gw_bkash', 'slug' => 'bkash-personal']], 'net_banking' => []];
$target = pp_custom_auto_checkout_target($inactiveEps, $base, []);
check('Inactive EPS is never auto-selected', $target['auto'] === false, $target['reason']);

// Amount out of range: pp_gateways() filters the row out before classification.
$outOfRange = ['cards' => [], 'mobile_banking' => [], 'net_banking' => []];
$target = pp_custom_auto_checkout_target($outOfRange, $base, ['category' => 'cards']);
check('EPS filtered out by amount limits does not auto-start', $target['auto'] === false, $target['reason']);

$malformedId = [
    'cards'          => [['gateway_id' => 'bad id!"><script>', 'slug' => 'eps']],
    'mobile_banking' => [],
    'net_banking'    => [],
];
$target = pp_custom_auto_checkout_target($malformedId, $base, []);
check('Malformed gateway id is rejected', $target['auto'] === false && $target['reason'] === 'invalid_gateway_id', $target['reason']);

$target = pp_custom_auto_checkout_target($epsOnlyCards, $base, ['manual' => '1']);
check('Manual override (?manual=1) disables the automatic start', $target['auto'] === false && $target['reason'] === 'manual_opt_out', $target['reason']);

$target = pp_custom_auto_checkout_target($epsOnlyCards, $base, ['eps_error' => 'EPS_INIT_HTTP']);
check('A recent failure prevents a redirect loop', $target['auto'] === false && $target['reason'] === 'recent_failure', $target['reason']);

$target = pp_custom_auto_checkout_target($epsOnlyCards, $base, ['pp_callback' => '']);
check(
    'A provider callback is never auto-redirected',
    $target['auto'] === false && $target['reason'] === 'provider_callback',
    $target['reason']
);

$target = pp_custom_auto_checkout_target($epsOnlyCards, $base . '?lang=bn', ['lang' => 'bn']);
check(
    'Existing query parameters are preserved when building the auto URL',
    strpos($target['url'], 'lang=bn') !== false && strpos($target['url'], 'gateway=gw_eps') !== false,
    $target['url']
);

// ---------------------------------------------------------------------------
// 2. Category classification regression (bKash / Nagad / EPS)
// ---------------------------------------------------------------------------

echo "\n[Category classification]\n";

$categorized = pp_custom_payment_categories([
    ['gateway_id' => 'gw_eps', 'slug' => 'eps', 'tab' => 'mfs'],
    ['gateway_id' => 'gw_bkash', 'slug' => 'bkash-personal', 'tab' => 'mfs'],
    ['gateway_id' => 'gw_nagad', 'slug' => 'nagad-personal', 'tab' => 'mfs'],
]);

check('EPS is offered in the Cards category despite tab=mfs', count($categorized['cards']) === 1 && $categorized['cards'][0]['slug'] === 'eps');
check('bKash Personal stays in Mobile Banking', count($categorized['mobile_banking']) === 2 && in_array('bkash-personal', array_column($categorized['mobile_banking'], 'slug'), true));
check('Nagad Personal stays in Mobile Banking', in_array('nagad-personal', array_column($categorized['mobile_banking'], 'slug'), true));
check('Nothing leaks into Net Banking', count($categorized['net_banking']) === 0);

$emptyIdRows = pp_custom_payment_categories([
    ['gateway_id' => '', 'slug' => 'eps'],
    ['slug' => 'eps'],
]);
check('Rows without a gateway_id are ignored', count($emptyIdRows['cards']) === 0);

// ---------------------------------------------------------------------------
// 3. Hosted URL validation (data=null and friends)
// ---------------------------------------------------------------------------

echo "\n[Hosted checkout URL validation]\n";

$gw = new TestEpsGateway();

$valid = $gw->validateUrl('https://pg.eps.com.bd/PG?data=AbCdEfGhIjKlMnOp1234567890');
check('Valid EPS URL is accepted', $valid['ok'] === true, $valid['detail'] ?? '');

$nullUrl = $gw->validateUrl('https://pg.eps.com.bd/PG?data=null');
check('data=null is rejected', $nullUrl['ok'] === false && $nullUrl['code'] === 'EPS_INIT_NULL_SESSION', $nullUrl['code'] ?? '');

$undefinedUrl = $gw->validateUrl('https://pg.eps.com.bd/PG?data=undefined');
check('data=undefined is rejected', $undefinedUrl['ok'] === false && $undefinedUrl['code'] === 'EPS_INIT_NULL_SESSION', $undefinedUrl['code'] ?? '');

$emptyDataUrl = $gw->validateUrl('https://pg.eps.com.bd/PG?data=');
check('data= (empty) is rejected', $emptyDataUrl['ok'] === false && $emptyDataUrl['code'] === 'EPS_INIT_NULL_SESSION', $emptyDataUrl['code'] ?? '');

$shortDataUrl = $gw->validateUrl('https://pg.eps.com.bd/PG?data=abc');
check('Too-short session identifier is rejected', $shortDataUrl['ok'] === false && $shortDataUrl['code'] === 'EPS_INIT_MALFORMED_SESSION', $shortDataUrl['code'] ?? '');

$badCharsetUrl = $gw->validateUrl('https://pg.eps.com.bd/PG?data=' . urlencode('<script>alert(1)</script>'));
check('Session identifier with markup is rejected', $badCharsetUrl['ok'] === false && $badCharsetUrl['code'] === 'EPS_INIT_MALFORMED_SESSION', $badCharsetUrl['code'] ?? '');

$untrusted = $gw->validateUrl('https://evil.example.com/PG?data=AbCdEfGhIjKlMnOp1234567890');
check('Untrusted redirect host is rejected', $untrusted['ok'] === false && $untrusted['code'] === 'EPS_INIT_UNTRUSTED_HOST', $untrusted['code'] ?? '');

$lookalike = $gw->validateUrl('https://pg.eps.com.bd.evil.example/PG?data=AbCdEfGhIjKlMnOp1234567890');
check('Look-alike host (eps.com.bd.evil.example) is rejected', $lookalike['ok'] === false && $lookalike['code'] === 'EPS_INIT_UNTRUSTED_HOST', $lookalike['code'] ?? '');

$insecure = $gw->validateUrl('http://pg.eps.com.bd/PG?data=AbCdEfGhIjKlMnOp1234567890');
check('http:// checkout URL is rejected', $insecure['ok'] === false && $insecure['code'] === 'EPS_INIT_INSECURE_URL', $insecure['code'] ?? '');

$credentials = $gw->validateUrl('https://user:pass@pg.eps.com.bd/PG?data=AbCdEfGhIjKlMnOp1234567890');
check('URLs embedding credentials are rejected', $credentials['ok'] === false && $credentials['code'] === 'EPS_INIT_UNTRUSTED_HOST', $credentials['code'] ?? '');

$headerInjection = $gw->validateUrl("https://pg.eps.com.bd/PG?data=AbCdEfGhIjKl\r\nLocation: https://evil.example");
check('Control characters in the URL are rejected', $headerInjection['ok'] === false, $headerInjection['code'] ?? '');

$missing = $gw->validateUrl('');
check('Empty RedirectURL is rejected', $missing['ok'] === false && $missing['code'] === 'EPS_INIT_MISSING_URL', $missing['code'] ?? '');

$notString = $gw->validateUrl(null);
check('Non-string RedirectURL is rejected', $notString['ok'] === false && $notString['code'] === 'EPS_INIT_MISSING_URL', $notString['code'] ?? '');

// ---------------------------------------------------------------------------
// 4. Initialisation failures
// ---------------------------------------------------------------------------

echo "\n[Initialisation failures]\n";

// Failed authentication (HTTP 401)
$gw = new TestEpsGateway();
$gw->postQueue = [eps_http(401, ['error' => 'unauthorized'])];
$result = $gw->initiate(eps_payload_data());
check('Failed EPS authentication is reported', $result['ok'] === false && $result['code'] === 'EPS_AUTH_HTTP', $result['code'] ?? '');
check('No checkout redirect is produced after an auth failure', empty($result['redirect_url']));

// Authentication rejected with a 200 but no token
$gw = new TestEpsGateway();
$gw->postQueue = [eps_ok(['Status' => 'Failed', 'message' => 'invalid credentials'])];
$result = $gw->initiate(eps_payload_data());
check('Missing token in an HTTP 200 response is reported', $result['ok'] === false && $result['code'] === 'EPS_AUTH_NO_TOKEN', $result['code'] ?? '');

// Transport failure / timeout
$gw = new TestEpsGateway();
$gw->postQueue = [eps_transport_failure()];
$result = $gw->initiate(eps_payload_data());
check('cURL transport failure is reported as EPS_AUTH_TRANSPORT', $result['ok'] === false && $result['code'] === 'EPS_AUTH_TRANSPORT', $result['code'] ?? '');

// Failed initialisation (HTTP 500)
$gw = new TestEpsGateway();
$gw->postQueue = [eps_token(), eps_http(500, 'gateway error')];
$result = $gw->initiate(eps_payload_data());
check('HTTP 5xx from InitializeEPS is reported', $result['ok'] === false && $result['code'] === 'EPS_INIT_HTTP', $result['code'] ?? '');

// Failed initialisation (HTTP 400)
$gw = new TestEpsGateway();
$gw->postQueue = [eps_token(), eps_http(400, ['Status' => 'Failed'])];
$result = $gw->initiate(eps_payload_data());
check('HTTP 4xx from InitializeEPS is reported', $result['ok'] === false && $result['code'] === 'EPS_INIT_HTTP', $result['code'] ?? '');

// Invalid JSON
$gw = new TestEpsGateway();
$gw->postQueue = [eps_token(), eps_http(200, '<html>not json</html>')];
$result = $gw->initiate(eps_payload_data());
check('Non-JSON initialisation response is reported', $result['ok'] === false && $result['code'] === 'EPS_INIT_INVALID_JSON', $result['code'] ?? '');

// HTTP 200 with a provider failure envelope
$gw = new TestEpsGateway();
$gw->postQueue = [eps_token(), eps_ok(['Status' => 'Failed', 'RedirectURL' => 'https://pg.eps.com.bd/PG?data=AbCdEfGhIjKlMnOp1234567890'])];
$result = $gw->initiate(eps_payload_data());
check('Provider failure status blocks the redirect', $result['ok'] === false && $result['code'] === 'EPS_INIT_REJECTED', $result['code'] ?? '');

// data=null end to end
$gw = new TestEpsGateway();
$gw->postQueue = [eps_token(), eps_init('https://pg.eps.com.bd/PG?data=null')];
$result = $gw->initiate(eps_payload_data());
check('data=null never reaches the customer', $result['ok'] === false && $result['code'] === 'EPS_INIT_NULL_SESSION', $result['code'] ?? '');
$nullLog = implode("\n", $gw->logs);
check(
    'Rejected URL is described by shape only',
    strpos($nullLog, 'null_like') !== false
        && strpos($nullLog, 'host=pg.eps.com.bd') !== false
        && strpos($nullLog, 'https://') === false,
    $nullLog
);

// Empty RedirectURL end to end
$gw = new TestEpsGateway();
$gw->postQueue = [eps_token(), eps_ok(['RedirectURL' => ''])];
$result = $gw->initiate(eps_payload_data());
check('Empty RedirectURL never reaches the customer', $result['ok'] === false && $result['code'] === 'EPS_INIT_MISSING_URL', $result['code'] ?? '');

// Amount guard
$gw = new TestEpsGateway();
$result = $gw->initiate(eps_payload_data(['transaction' => ['local_net_amount' => '0.00']]));
check('Zero amount is rejected before any provider call', $result['ok'] === false && $result['code'] === 'EPS_AMOUNT_INVALID', $result['code'] ?? '');
check('Rejected amount makes no network call', count($gw->postCalls) === 0);

// Incomplete configuration
$gw = new TestEpsGateway();
$result = $gw->initiate(eps_payload_data(['options' => ['hashkey' => '']]));
check('Incomplete gateway configuration is reported', $result['ok'] === false && $result['code'] === 'EPS_CONFIG_INCOMPLETE', $result['code'] ?? '');

// Missing customer mobile
$gw = new TestEpsGateway();
$result = $gw->initiate(eps_payload_data(['transaction' => ['customer' => ['mobile' => '']]]));
check('Missing customer phone is reported', $result['ok'] === false && $result['code'] === 'EPS_CUSTOMER_INVALID', $result['code'] ?? '');

// ---------------------------------------------------------------------------
// 5. Successful initialisation, refresh and retry behaviour
// ---------------------------------------------------------------------------

echo "\n[Successful initialisation]\n";

$redirectUrl = 'https://pg.eps.com.bd/PG?data=AbCdEfGhIjKlMnOp1234567890';

$gw = new TestEpsGateway();
$gw->postQueue = [eps_token(), eps_init($redirectUrl)];
$result = $gw->initiate(eps_payload_data());

check('Valid session creates a redirect URL', $result['ok'] === true && $result['redirect_url'] === $redirectUrl);

$payload = $gw->postCalls[1]['payload'] ?? [];
check('Amount is sent with two decimals', ($payload['totalAmount'] ?? '') === '100.00', (string) ($payload['totalAmount'] ?? ''));
check('Transaction reference is bound through ValueA', ($payload['ValueA'] ?? '') === 'TXN-1', (string) ($payload['ValueA'] ?? ''));
check('Merchant and store ids are forwarded', ($payload['merchantId'] ?? '') === 'MERCHANT-1' && ($payload['storeId'] ?? '') === 'STORE-1');
check('Callback URL is sent as successUrl', ($payload['successUrl'] ?? '') === 'https://pay.example.test/?pp_callback');
check('Cancel and failure URLs opt out of automatic retry', strpos((string) ($payload['cancelUrl'] ?? ''), 'manual=1') !== false && strpos((string) ($payload['failUrl'] ?? ''), 'manual=1') !== false);
check('Authorization header uses the bearer token', in_array('Authorization: Bearer stub-token-0123456789', $gw->postCalls[1]['headers'] ?? [], true));
check('x-hash header is present on the initialisation call', (bool) preg_grep('/^x-hash: ./', $gw->postCalls[1]['headers'] ?? []));
check('Sandbox mode uses the sandbox API host', strpos($gw->postCalls[0]['url'], 'https://sandboxpgapi.eps.com.bd/v1/Auth/GetToken') === 0, $gw->postCalls[0]['url']);
check('Sandbox mode uses the sandbox initialisation endpoint', strpos($gw->postCalls[1]['url'], 'https://sandboxpgapi.eps.com.bd/v1/EPSEngine/InitializeEPS') === 0, $gw->postCalls[1]['url']);
$initLog = implode("\n", $gw->logs);
check(
    'URL shape is logged on success without the token',
    strpos($initLog, 'EPS_SESSION_CREATED') !== false
        && strpos($initLog, 'query_token') !== false
        && strpos($initLog, 'data_len') !== false
        && strpos($initLog, 'AbCdEfGh') === false,
    $initLog
);

// Refresh / back-button: the stored session is re-used, no new provider call.
$postCallsBefore = count($gw->postCalls);
$again = $gw->initiate(eps_payload_data());
check('Refresh re-uses the stored hosted session', $again['ok'] === true && $again['redirect_url'] === $redirectUrl);
check('Refresh does not create a second session at the provider', count($gw->postCalls) === $postCallsBefore);

// Distinct merchant transaction ids across transactions.
$firstMtx = $result['merchant_transaction_id'];
$gw2 = new TestEpsGateway();
$gw2->postQueue = [eps_token(), eps_init($redirectUrl)];
$second = $gw2->initiate(eps_payload_data(['transaction' => ['ref' => 'TXN-2']]));
check(
    'Duplicate merchant transaction ids are avoided across transactions',
    $second['merchant_transaction_id'] !== $firstMtx,
    $firstMtx . ' vs ' . $second['merchant_transaction_id']
);

// Explicit retry creates a new session.
$gw->postQueue = [eps_token(), eps_init('https://pg.eps.com.bd/PG?data=SecondSessionAbcdef1234567890')];
reset_get(['retry' => '1']);
$retry = $gw->initiate(eps_payload_data());
reset_get();
check('Explicit retry creates a fresh hosted session', $retry['ok'] === true && $retry['redirect_url'] !== $redirectUrl);

// Expired stored session is replaced.
$gw3 = new TestEpsGateway();
$gw3->sessions['TXN-1'] = [
    'redirect_url'            => $redirectUrl,
    'merchant_transaction_id' => 'old-merchant-transaction',
    'created_at'              => time() - (EpsGateway::CHECKOUT_SESSION_TTL + 60),
];
$gw3->postQueue = [eps_token(), eps_init('https://pg.eps.com.bd/PG?data=FreshSessionAbcdef1234567890')];
$expired = $gw3->initiate(eps_payload_data());
check('Expired stored session is not re-used', $expired['ok'] === true && count($gw3->postCalls) === 2);

// ---------------------------------------------------------------------------
// 6. Callback verification
// ---------------------------------------------------------------------------

echo "\n[Callback verification]\n";

$callbackData = eps_payload_data();
$callbackData['transaction']['local_net_amount'] = '100.00';

$gw = new TestEpsGateway();
$gw->sessions['TXN-1'] = ['merchant_transaction_id' => 'mtx-1', 'created_at' => time()];
$gw->postQueue = [eps_token()];
$gw->getQueue = [eps_status('TXN-1')];
reset_get(['MerchantTransactionId' => 'mtx-1', 'EPSTransactionId' => 'eps-1']);
$result = $gw->verify($callbackData);
reset_get();

check('Verified successful payment completes the transaction', $result['ok'] === true);
check('Completion uses the EPS transaction id', ($gw->completions[0]['trxid'] ?? '') === 'eps-1', (string) ($gw->completions[0]['trxid'] ?? ''));
check('Completion records the gateway id', ($gw->completions[0]['gateway_id'] ?? '') === 'gw_eps');
check('Status check is a server-side call', count($gw->getCalls) === 1 && strpos($gw->getCalls[0]['url'], 'CheckMerchantTransactionStatus') !== false);
check('Stored hosted session is cleared before completion', empty($gw->sessions['TXN-1']));

// Duplicate callback: transaction already completed
$gw = new TestEpsGateway();
$completedData = $callbackData;
$completedData['transaction']['status'] = 'completed';
reset_get(['MerchantTransactionId' => 'mtx-1', 'EPSTransactionId' => 'eps-1']);
$duplicate = $gw->verify($completedData);
reset_get();
check('Duplicate callback on a completed transaction is idempotent', $duplicate['ok'] === true && !empty($duplicate['already_completed']));
check('Duplicate callback performs no provider call and no completion', count($gw->getCalls) === 0 && count($gw->completions) === 0);

// Duplicate callback where the atomic update loses the race
$gw = new TestEpsGateway();
$gw->completionResult = false;
$gw->postQueue = [eps_token()];
$gw->getQueue = [eps_status('TXN-1')];
reset_get(['MerchantTransactionId' => 'mtx-1', 'EPSTransactionId' => 'eps-1']);
$rejected = $gw->verify($callbackData);
reset_get();
check('Lost completion race does not report success', $rejected['ok'] === false && $rejected['code'] === 'EPS_COMPLETION_REJECTED', $rejected['code'] ?? '');

// Provider says the payment failed
$gw = new TestEpsGateway();
$gw->sessions['TXN-1'] = ['merchant_transaction_id' => 'mtx-1', 'created_at' => time()];
$gw->postQueue = [eps_token()];
$gw->getQueue = [eps_status('TXN-1', ['Status' => 'Failed'])];
reset_get(['MerchantTransactionId' => 'mtx-1', 'EPSTransactionId' => 'eps-1']);
$failed = $gw->verify($callbackData);
reset_get();
check('Failed payment is not marked completed', $failed['ok'] === false && count($gw->completions) === 0);
check('Failed payment keeps the hosted session for retry', !empty($gw->sessions['TXN-1']));

// Browser claims success but the provider disagrees (spoofed redirect)
$gw = new TestEpsGateway();
$gw->postQueue = [eps_token()];
$gw->getQueue = [eps_status('TXN-1', ['Status' => 'Pending'])];
reset_get(['MerchantTransactionId' => 'mtx-1', 'EPSTransactionId' => 'eps-1', 'Status' => 'Success']);
$spoofed = $gw->verify($callbackData);
reset_get();
check('A browser redirect alone never completes a payment', $spoofed['ok'] === false && count($gw->completions) === 0);

// ValueA mismatch
$gw = new TestEpsGateway();
$gw->postQueue = [eps_token()];
$gw->getQueue = [eps_status('OTHER-TXN')];
reset_get(['MerchantTransactionId' => 'mtx-1', 'EPSTransactionId' => 'eps-1']);
$mismatch = $gw->verify($callbackData);
reset_get();
check('ValueA for another transaction is rejected', $mismatch['ok'] === false && $mismatch['code'] === 'EPS_CALLBACK_MISMATCH', $mismatch['code'] ?? '');

// Merchant transaction id mismatch against the stored session
$gw = new TestEpsGateway();
$gw->sessions['TXN-1'] = ['merchant_transaction_id' => 'mtx-expected', 'created_at' => time()];
reset_get(['MerchantTransactionId' => 'mtx-other', 'EPSTransactionId' => 'eps-1']);
$sessionMismatch = $gw->verify($callbackData);
reset_get();
check('Callback for a different merchant transaction id is rejected', $sessionMismatch['ok'] === false && $sessionMismatch['code'] === 'EPS_CALLBACK_MISMATCH', $sessionMismatch['code'] ?? '');
check('Mismatched callback performs no provider call', count($gw->getCalls) === 0);

// Missing callback parameters
$gw = new TestEpsGateway();
reset_get([]);
$invalid = $gw->verify($callbackData);
reset_get();
check('Callback without transaction identifiers is rejected', $invalid['ok'] === false && $invalid['code'] === 'EPS_CALLBACK_INVALID', $invalid['code'] ?? '');

// Amount mismatch
$gw = new TestEpsGateway();
$gw->postQueue = [eps_token()];
$gw->getQueue = [eps_status('TXN-1', ['Amount' => '1.00'])];
reset_get(['MerchantTransactionId' => 'mtx-1', 'EPSTransactionId' => 'eps-1']);
$amountMismatch = $gw->verify($callbackData);
reset_get();
check('Amount mismatch is rejected', $amountMismatch['ok'] === false && $amountMismatch['code'] === 'EPS_CALLBACK_AMOUNT_MISMATCH', $amountMismatch['code'] ?? '');

// Verification endpoint HTTP failure
$gw = new TestEpsGateway();
$gw->postQueue = [eps_token()];
$gw->getQueue = [eps_http(502, 'bad gateway')];
reset_get(['MerchantTransactionId' => 'mtx-1', 'EPSTransactionId' => 'eps-1']);
$verifyHttp = $gw->verify($callbackData);
reset_get();
check('Verification endpoint HTTP failure is reported', $verifyHttp['ok'] === false && $verifyHttp['code'] === 'EPS_CALLBACK_VERIFY_FAILED', $verifyHttp['code'] ?? '');

// Verification endpoint transport failure
$gw = new TestEpsGateway();
$gw->postQueue = [eps_token()];
$gw->getQueue = [eps_transport_failure()];
reset_get(['MerchantTransactionId' => 'mtx-1', 'EPSTransactionId' => 'eps-1']);
$verifyTransport = $gw->verify($callbackData);
reset_get();
check('Verification transport failure is reported', $verifyTransport['ok'] === false && $verifyTransport['code'] === 'EPS_CALLBACK_VERIFY_FAILED');

// ---------------------------------------------------------------------------
// 7. Customer-facing failure page
// ---------------------------------------------------------------------------

echo "\n[Customer fallback page]\n";

$gw = new TestEpsGateway();
$gw->postQueue = [eps_token(), eps_init('https://pg.eps.com.bd/PG?data=null')];
reset_get();
ob_start();
$gw->process_payment(eps_payload_data());
$html = (string) ob_get_clean();

check('Failure page does not redirect to the provider', strpos($html, 'location.href') === false);
check('Failure page offers a manual fallback', strpos($html, 'manual=1') !== false);
check('Failure page shows a customer-safe message', strpos($html, 'did not create a valid payment session') !== false);
check('Failure page never leaks the internal code to the customer', strpos($html, 'EPS_INIT_NULL_SESSION') === false);
check('Failure page offers no automatic retry loop', strpos($html, 'http-equiv="refresh"') === false);

// ---------------------------------------------------------------------------
// 7b. Failure screen layout
// ---------------------------------------------------------------------------

echo "\n[Failure screen layout]\n";

$layoutGw = new TestEpsGateway();
$layoutGw->postQueue = [eps_token(), eps_http(500, 'provider error')];
ob_start();
$layoutGw->process_payment(eps_payload_data());
$failureHtml = (string) ob_get_clean();

check('Failure screen uses the scoped component', strpos($failureHtml, 'class="eps-error') !== false && strpos($failureHtml, 'role="alert"') !== false);
check('Failure screen no longer uses the framework alert', strpos($failureHtml, 'alert alert-danger') === false);
check('Failure screen no longer uses framework button utilities', strpos($failureHtml, 'btn btn-primary') === false);

$actionsPos = strpos($failureHtml, 'eps-error__actions');
$retryPos = strpos($failureHtml, 'Try again');
$fallbackPos = strpos($failureHtml, 'Choose another payment method');
check(
    'Both controls live inside the actions container, in order',
    $actionsPos !== false && $retryPos !== false && $fallbackPos !== false
        && $retryPos > $actionsPos && $fallbackPos > $retryPos
);
check('Controls are block-level full-width buttons', substr_count($failureHtml, 'eps-error__btn') >= 4);

$stylePos = strpos($failureHtml, '.eps-error{display:flex;flex-direction:column');
check('Error layout is a vertical flex stack', $stylePos !== false);
check('Buttons are styled as block elements', strpos($failureHtml, '.eps-error__btn{display:block;width:100%') !== false);
check('A mobile breakpoint is declared', strpos($failureHtml, '@media (max-width:480px)') !== false);

$escapeGw = new TestEpsGateway();
$escapeGw->postQueue = [eps_token(), eps_http(500)];
$escapeData = eps_payload_data(['transaction' => ['ref' => 'TXN<X>"&\'END']]);
ob_start();
$escapeGw->process_payment($escapeData);
$escapeHtml = (string) ob_get_clean();
check(
    'Transaction reference is HTML-escaped in the error screen',
    strpos($escapeHtml, 'TXN<X>') === false && strpos($escapeHtml, 'TXN&lt;X&gt;') !== false
);
// ---------------------------------------------------------------------------
// 8. Gateway contract (legacy compatibility)
// ---------------------------------------------------------------------------

echo "\n[Gateway contract]\n";

$contract = new EpsGateway();
$info = $contract->info();

check('Gateway title is unchanged', ($info['title'] ?? '') === 'EPS Gateway');
check('Gateway currency is unchanged', ($info['currency'] ?? '') === 'BDT');
check('Gateway type stays api', ($info['gateway_type'] ?? '') === 'api');
check('Gateway tab stays mfs for admin compatibility', ($info['tab'] ?? '') === 'mfs');

$fieldNames = array_column($contract->fields(), 'name');
check(
    'Gateway configuration fields are unchanged',
    $fieldNames === ['merchant_id', 'store_id', 'username', 'password', 'hashkey', 'mode'],
    implode(',', $fieldNames)
);
check('process_payment and callback remain callable', method_exists($contract, 'process_payment') && method_exists($contract, 'callback'));

// ---------------------------------------------------------------------------
// 9. URL helper
// ---------------------------------------------------------------------------

echo "\n[URL helper]\n";

check(
    'pp_url_with_query keeps existing parameters',
    pp_url_with_query('https://pay.test/p/TXN-1?lang=bn', ['gateway' => 'gw_eps']) === 'https://pay.test/p/TXN-1?lang=bn&gateway=gw_eps',
    pp_url_with_query('https://pay.test/p/TXN-1?lang=bn', ['gateway' => 'gw_eps'])
);
check(
    'pp_url_with_query encodes values',
    pp_url_with_query('https://pay.test/p/TXN-1', ['eps_error' => 'A B&C']) === 'https://pay.test/p/TXN-1?eps_error=A%20B%26C',
    pp_url_with_query('https://pay.test/p/TXN-1', ['eps_error' => 'A B&C'])
);
check(
    'pp_url_with_query keeps the fragment last',
    pp_url_with_query('https://pay.test/p/TXN-1#step', ['manual' => '1']) === 'https://pay.test/p/TXN-1?manual=1#step'
);
check(
    'pp_url_with_query removes parameters for null values',
    pp_url_with_query('https://pay.test/p/TXN-1?cancel=1', ['cancel' => null]) === 'https://pay.test/p/TXN-1'
);

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

$total = (int) $GLOBALS['pp_total'];
$failed = $GLOBALS['pp_failed'];

echo "\n------------------------------------------------------------\n";
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
