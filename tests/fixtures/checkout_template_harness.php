<?php
/**
 * Checkout template harness.
 *
 * Renders pp-content/pp-payment-designs/custom/checkout.php with stubbed
 * application functions and prints the resulting output, so that the automatic
 * EPS checkout behaviour can be asserted without a web server or a database.
 *
 * Usage: php tests/fixtures/checkout_template_harness.php <auto|manual|error>
 */

declare(strict_types=1);

define('PipraPay_INIT', true);

$scenario = $argv[1] ?? 'manual';

$GLOBALS['pp_checkout_url'] = 'https://pay.example.test/payment/TXN-1';

function pp_gateways($tab = '', $data = [])
{
    $rows = [
        'mfs' => [
            ['gateway_id' => 'gw_eps', 'slug' => 'eps', 'name' => 'eps', 'display' => 'EPS Gateway', 'logo' => 'eps.jpg', 'currency' => 'BDT'],
            ['gateway_id' => 'gw_bkash', 'slug' => 'bkash-personal', 'name' => 'bkash', 'display' => 'bKash Personal', 'logo' => 'bkash.jpg', 'currency' => 'BDT'],
            ['gateway_id' => 'gw_nagad', 'slug' => 'nagad-personal', 'name' => 'nagad', 'display' => 'Nagad Personal', 'logo' => 'nagad.jpg', 'currency' => 'BDT'],
        ],
        'bank'   => [],
        'global' => [],
    ];

    return ['status' => true, 'gateway' => $rows[$tab] ?? []];
}

function money_round($amount, $precision = 2) { return number_format((float) $amount, (int) $precision, '.', ''); }
function money_sanitize($amount) { return is_numeric($amount) ? (string) $amount : '0'; }
function pp_assets($position = '') { return ''; }
function pp_site_address() { return 'https://pay.example.test/'; }
function pp_checkout_address($paymentid = '') { return $GLOBALS['pp_checkout_url']; }
function pp_set_lang($lang) { $_SESSION['ui_language'] = $lang; }
function pp_set_transaction_status($ref, $status = '', $gatewayId = '', $trxId = '', $sourceInfo = []) { return true; }

require_once dirname(__DIR__, 2) . '/pp-content/pp-include/pp-checkout-helpers.php';

$data = [
    'transaction' => [
        'ref'               => 'TXN-1',
        'amount'            => '100.00',
        'discount_amount'   => '0.00',
        'processing_fee'    => '0.00',
        'currency'          => 'BDT',
        'status'            => 'initiated',
        'customer'          => ['name' => 'Test Customer', 'email' => 'c@example.test', 'mobile' => '01711111111'],
    ],
    'brand' => [
        'id'      => 'BRAND-1',
        'name'    => 'Example Brand',
        'favicon' => 'favicon.png',
        'logo'    => 'logo.png',
    ],
    'lang' => ['checkout' => 'Checkout'],
    'options' => [
        'watermark_text' => 'Powered by PipraPay',
        'seo_title'      => '',
        'seo_description'=> '',
        'seo_keywords'   => '',
        'analytics_code' => '',
    ],
];

switch ($scenario) {
    case 'auto':
        $_GET = [];
        break;
    case 'error':
        $_GET = ['manual' => '1', 'eps_error' => 'session'];
        break;
    case 'manual':
    default:
        $_GET = ['manual' => '1'];
        break;
}

include dirname(__DIR__, 2) . '/pp-content/pp-payment-designs/custom/checkout.php';
