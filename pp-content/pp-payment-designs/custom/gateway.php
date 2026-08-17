<?php
    /*
     * CUSTOM PAYMENT DESIGN
     * Replace presentation markup/styles here.
     * Do not move payment business logic into this template.
     */

    if (!defined('PipraPay_INIT')) {
        http_response_code(403);
        exit('Direct access not allowed');
    }

    if(isset($_GET['lang'])){
        if($_GET['lang'] !== ""){
            pp_set_lang($_GET['lang']);
            $catParam = isset($_GET['category']) ? '&category=' . urlencode($_GET['category']) : '';
?>
            <script>
                location.href = '<?php echo pp_checkout_address().'?gateway='.$_GET['gateway'].$catParam;?>';
            </script>
<?php
            exit();
        }
    }

    if(isset($_GET['gateway'])){
        $gateway_info = pp_gateway_info($_GET['gateway'], $data);

        if($gateway_info['status'] == false){
            http_response_code(403);
            exit('Direct access not allowed');
        }
    }else{
        http_response_code(403);
        exit('Direct access not allowed');
    }

    // Check if the selected gateway is a Personal Mobile Banking automation gateway
    $personalMeta = function_exists('pp_is_personal_mobile_banking_gateway')
        ? pp_is_personal_mobile_banking_gateway($gateway_info)
        : false;

    // Safety Fallback: If not Personal Mobile Banking (e.g. EPS, SSLCommerz, Stripe, Tokenized API, etc.),
    // delegate immediately to the original default gateway renderer.
    if (!$personalMeta) {
        include __DIR__ . '/../default/gateway.php';
        return;
    }

    // Handle AJAX session creation & polling requests
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_personal_session') {
        header('Content-Type: application/json; charset=utf-8');
        $payerNumber = trim((string)($_POST['payer_number'] ?? ''));
        $gatewayId = trim((string)($_GET['gateway'] ?? ''));
        $txRef = trim((string)($data['transaction']['ref'] ?? ''));
        $brandId = trim((string)($data['brand']['id'] ?? ''));

        $res = pp_create_or_update_personal_payment_session($txRef, $gatewayId, $payerNumber, $brandId);
        echo json_encode($res);
        exit();
    }

    if (isset($_GET['action']) && $_GET['action'] === 'check_session_status') {
        header('Content-Type: application/json; charset=utf-8');
        $txRef = trim((string)($data['transaction']['ref'] ?? ''));
        $res = pp_get_personal_payment_session_status($txRef);
        echo json_encode($res);
        exit();
    }

    // Extract gateway parameters from DB
    global $db_prefix;
    $gatewayOptions = [];
    $params = [':gateway_id' => $_GET['gateway']];
    $resParam = json_decode(getData($db_prefix . 'gateways_parameter', 'WHERE gateway_id = :gateway_id', '* FROM', $params), true);
    if (!empty($resParam['response'])) {
        foreach ($resParam['response'] as $field) {
            $val = $field['value'];
            if (!empty($field['multiple']) && !empty($val)) {
                $val = is_array($val) ? $val : json_decode($val, true);
            }
            $gatewayOptions[$field['option_name']] = $val;
        }
    }

    // Validate requested category strictly against allowed categories
    $allowedCategories = ['cards', 'mobile_banking', 'net_banking'];
    $currentCategory = isset($_GET['category']) ? strtolower(trim((string)$_GET['category'])) : '';
    if (!in_array($currentCategory, $allowedCategories, true)) {
        $currentCategory = function_exists('pp_custom_payment_category')
            ? pp_custom_payment_category($gateway_info)
            : 'mobile_banking';
        if (!in_array($currentCategory, $allowedCategories, true)) {
            $currentCategory = 'mobile_banking';
        }
    }

    $backToCheckoutUrl = pp_checkout_address() . '?category=' . urlencode($currentCategory);

    $senderKey = strtolower($personalMeta['sender_key'] ?? '');
    $gatewayName = $gateway_info['gateway']['display'] ?? $gateway_info['gateway']['name'] ?? 'Payment';

    // Dynamic presentation configuration keyed by verified gateway identity / sender_key
    $brandingMap = [
        'bkash' => [
            'theme'     => 'theme-bkash',
            'label'     => 'Your bKash Account Number',
            'appName'   => 'বিকাশ',
            'artwork'   => 'bkash',
        ],
        'nagad' => [
            'theme'     => 'theme-nagad',
            'label'     => 'Your Nagad Account Number',
            'appName'   => 'নগদ',
            'artwork'   => 'nagad',
        ],
        'rocket' => [
            'theme'     => 'theme-rocket',
            'label'     => 'Your Rocket Account Number',
            'appName'   => 'রকেট',
            'artwork'   => 'rocket',
        ],
        'upay' => [
            'theme'     => 'theme-upay',
            'label'     => 'Enter Your upay Account Number',
            'appName'   => 'উপায়',
            'artwork'   => 'upay',
        ],
    ];

    if (isset($brandingMap[$senderKey])) {
        $branding = $brandingMap[$senderKey];
        $themeClass = $branding['theme'];
        $accountLabel = $branding['label'];
        $appName = $branding['appName'];
        $artworkFolder = $branding['artwork'];
    } else {
        $themeClass = 'theme-default';
        $accountLabel = 'Your ' . htmlspecialchars($gatewayName) . ' Account Number';
        $appName = htmlspecialchars($gatewayName);
        $artworkFolder = 'bkash';
    }

    // Dynamic merchant number extraction
    $merchantNumber = $gatewayOptions['mobile_number'] ?? $gatewayOptions['account_number'] ?? $gatewayOptions['merchant_number'] ?? '';

    // Dynamic artwork URL
    $artworkUrl = pp_site_address() . 'pp-content/pp-payment-designs/custom/assets/' . $artworkFolder . '/instruction.svg';

    $txnAmount = money_round($data['transaction']['amount'], 2);
    $currency = htmlspecialchars($data['transaction']['currency'] ?? 'BDT');
    $currencySymbol = ($currency === 'BDT') ? '৳' : $currency;
    $chargeAmount = money_round($data['transaction']['processing_fee'] ?? 0, 2);
    $ref = htmlspecialchars($data['transaction']['ref'] ?? '');
    $companyName = htmlspecialchars($data['brand']['name'] ?? 'Your Company Name');

    // Detect if an active waiting session exists or if step=waiting requested
    $activeSess = function_exists('pp_get_personal_payment_session_status') ? pp_get_personal_payment_session_status($ref) : ['status' => 'none'];
    $isWaiting = (($activeSess['status'] ?? '') === 'waiting') || (isset($_GET['step']) && $_GET['step'] === 'waiting');
    $initialExpiresIn = ($isWaiting && !empty($activeSess['expires_in'])) ? (int)$activeSess['expires_in'] : 300;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?php echo htmlspecialchars($gatewayName); ?> - <?php echo $companyName; ?></title>
    <link rel="shortcut icon" href="<?php echo $data['brand']['favicon'];?>">
    <?php echo pp_assets('head'); ?>
    <link rel="stylesheet" href="<?php echo pp_site_address(); ?>pp-content/pp-payment-designs/custom/style.css">

    <?php
        $seoTitle = trim($data['options']['seo_title'] ?? '');
        $seoDesc  = trim($data['options']['seo_description'] ?? '');
        $seoKey   = trim($data['options']['seo_keywords'] ?? '');
        $analyticsCode = trim($data['options']['analytics_code'] ?? '');

        if ($seoTitle !== '' && $seoTitle !== '--') {
            echo '<title>' . htmlspecialchars($seoTitle) . '</title>' . PHP_EOL;
            echo '<meta name="title" content="' . htmlspecialchars($seoTitle) . '">' . PHP_EOL;
        }
        if ($seoDesc !== '' && $seoDesc !== '--') {
            echo '<meta name="description" content="' . htmlspecialchars($seoDesc) . '">' . PHP_EOL;
        }
        if ($seoKey !== '' && $seoKey !== '--') {
            echo '<meta name="keywords" content="' . htmlspecialchars($seoKey) . '">' . PHP_EOL;
        }
        if ($analyticsCode !== '' && $analyticsCode !== '--') {
            echo $analyticsCode;
        }
    ?>
</head>
<body class="pp-payment-custom" loading="lazy">
    <div class="custom-card-wrap">
        <div class="gateway-branded-panel <?php echo $themeClass; ?>">
            <?php if ($senderKey === 'nagad'): ?>
                <!-- ========================================================
                     NAGAD PERSONAL REFERENCE LAYOUT
                     ======================================================== -->
                <!-- SCREEN 1: Nagad Account Number Screen -->
                <div id="stepPayerNumber" style="<?php echo $isWaiting ? 'display: none;' : 'display: block;'; ?>">
                    <!-- Centered Cart Icon & Company Name -->
                    <div class="nagad-top-header">
                        <svg class="nagad-cart-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M6 19m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M17 19m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M17 17h-11v-14h-2" /><path d="M6 5l14 1l-1 7h-13" /></svg>
                        <h3 class="nagad-company-title"><?php echo $companyName; ?></h3>
                    </div>

                    <!-- Dark Inset Invoice Summary Panel -->
                    <div class="nagad-summary-box">
                        <div class="nagad-summary-row">
                            <span class="nagad-summary-label">Invoice No:</span>
                            <span class="nagad-summary-val font-monospace"><?php echo $ref; ?></span>
                        </div>
                        <div class="nagad-summary-row">
                            <span class="nagad-summary-label">Total Amount:</span>
                            <span class="nagad-summary-val"><?php echo $currency; ?> <?php echo $txnAmount; ?></span>
                        </div>
                        <div class="nagad-summary-row">
                            <span class="nagad-summary-label">Charge:</span>
                            <span class="nagad-summary-val"><?php echo $currency; ?> <?php echo $chargeAmount; ?></span>
                        </div>
                    </div>

                    <form class="personal-payment-form" id="formNagadPayment" method="POST" action="" onsubmit="return handlePaymentSubmit(event, 'nagad')">
                        <input type="hidden" name="action-v2" value="custom-personal-payment-start">
                        <input type="hidden" name="gateway-id" value="<?php echo htmlspecialchars($_GET['gateway']); ?>">
                        <input type="hidden" name="transaction-id" value="<?php echo htmlspecialchars($data['transaction']['ref']); ?>">
                        <input type="hidden" name="category" value="<?php echo htmlspecialchars($currentCategory); ?>">

                        <!-- Section Title -->
                        <div class="nagad-account-title"><?php echo $accountLabel; ?></div>

                        <!-- Segmented 11-digit Number Input -->
                        <div class="nagad-input-container" onclick="focusNagadInput()">
                            <input type="tel" id="nagadHiddenInput" name="payer_number" class="nagad-hidden-input" maxlength="11" autocomplete="tel" oninput="onNagadInput(this.value)">
                            <div class="nagad-digit-group-wrap">
                                <div class="nagad-digit-group">
                                    <div class="nagad-digit-box active" id="ndb-0"></div>
                                    <div class="nagad-digit-box" id="ndb-1"></div>
                                    <div class="nagad-digit-box" id="ndb-2"></div>
                                </div>
                                <div class="nagad-digit-dash">-</div>
                                <div class="nagad-digit-group">
                                    <div class="nagad-digit-box" id="ndb-3"></div>
                                    <div class="nagad-digit-box" id="ndb-4"></div>
                                    <div class="nagad-digit-box" id="ndb-5"></div>
                                    <div class="nagad-digit-box" id="ndb-6"></div>
                                </div>
                                <div class="nagad-digit-dash">-</div>
                                <div class="nagad-digit-group">
                                    <div class="nagad-digit-box" id="ndb-7"></div>
                                    <div class="nagad-digit-box" id="ndb-8"></div>
                                    <div class="nagad-digit-box" id="ndb-9"></div>
                                    <div class="nagad-digit-box" id="ndb-10"></div>
                                </div>
                            </div>
                        </div>

                        <!-- Terms & Conditions Notice -->
                        <div class="nagad-terms-text">
                            By clicking/tapping "Proceed" you are agreeing to our <u>Terms and Conditions</u>
                        </div>

                        <!-- Action Buttons -->
                        <div class="nagad-actions-row">
                            <button type="submit" id="btnNagadProceed" class="nagad-btn-proceed" disabled>
                                Proceed
                            </button>
                            <a href="<?php echo $backToCheckoutUrl; ?>" class="nagad-btn-close">
                                Close
                            </a>
                        </div>
                    </form>

                    <!-- Nagad Footer Branding -->
                    <div class="nagad-footer-brand">
                        <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="#ffffff"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></svg>
                        <span>নগদ</span>
                    </div>
                </div>

                <!-- SCREEN 2: Nagad Waiting / Instruction Screen -->
                <div id="stepInstructionWaiting" style="<?php echo $isWaiting ? 'display: block;' : 'display: none;'; ?>">
                    <!-- Centered Cart Icon & Company Name -->
                    <div class="nagad-top-header">
                        <svg class="nagad-cart-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M6 19m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M17 19m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M17 17h-11v-14h-2" /><path d="M6 5l14 1l-1 7h-13" /></svg>
                        <h3 class="nagad-company-title"><?php echo $companyName; ?></h3>
                    </div>

                    <!-- Inset Summary Table -->
                    <div class="nagad-summary-box">
                        <div class="nagad-summary-row">
                            <span class="nagad-summary-label">Invoice No:</span>
                            <span class="nagad-summary-val font-monospace"><?php echo $ref; ?></span>
                        </div>
                        <div class="nagad-summary-row">
                            <span class="nagad-summary-label">Total Amount:</span>
                            <span class="nagad-summary-val"><?php echo $currency; ?> <?php echo $txnAmount; ?></span>
                        </div>
                        <div class="nagad-summary-row">
                            <span class="nagad-summary-label">Charge:</span>
                            <span class="nagad-summary-val"><?php echo $currency; ?> <?php echo $chargeAmount; ?></span>
                        </div>
                    </div>

                    <!-- Inset Instruction Panel -->
                    <div class="nagad-inst-panel">
                        <div class="nagad-inst-step-1">নগদ অ্যাপে প্রবেশ করুন।</div>
                        <div class="nagad-inst-step-2">"Send Money" অপশন সিলেক্ট করুন।</div>

                        <!-- Instruction SVG Diagram -->
                        <img src="<?php echo $artworkUrl; ?>" alt="Nagad Send Money" class="nagad-inst-artwork">

                        <!-- Merchant Receiving Number -->
                        <?php if (!empty($merchantNumber)): ?>
                            <div class="nagad-inst-label">নিচের নাম্বারে টাকা পাঠান:</div>
                            <div class="nagad-merchant-pill">
                                <span class="nagad-merchant-num" id="merchantNumText"><?php echo htmlspecialchars($merchantNumber); ?></span>
                                <button type="button" class="nagad-copy-btn" id="btnCopyMerchant" onclick="copyMerchantNumber('<?php echo htmlspecialchars($merchantNumber); ?>')">
                                    Copy
                                </button>
                            </div>
                        <?php endif; ?>

                        <!-- Amount Highlight -->
                        <div class="nagad-amount-box">
                            <span>টাকার পরিমাণ লিখুন:</span>
                            <span class="nagad-amount-pill"><?php echo $currencySymbol; ?> <?php echo $txnAmount; ?></span>
                        </div>

                        <!-- Waiting Status -->
                        <div class="nagad-waiting-text">Waiting for payment.</div>

                        <!-- Countdown Pill -->
                        <div>
                            <div class="nagad-countdown-pill">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                <span>Session expires in <span id="countdownTimer">04:57</span></span>
                            </div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="nagad-actions-row">
                        <button type="button" id="btnAutoVerify" class="nagad-btn-autoverify" onclick="triggerAutoVerification()">
                            <svg class="pulse-dot" style="margin-right: 4px;"></svg>
                            Auto Verification
                        </button>
                        <button type="button" class="nagad-btn-back" onclick="backToPayerStep()">
                            Back
                        </button>
                    </div>

                    <!-- Nagad Footer Branding -->
                    <div class="nagad-footer-brand">
                        <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="#ffffff"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></svg>
                        <span>নগদ</span>
                    </div>
                </div>

            <?php else: ?>
                <!-- ========================================================
                     BKASH / ROCKET / UPAY / OTHER PERSONAL LAYOUT
                     ======================================================== -->
                <!-- SCREEN 2: Generic Personal Payer Number Input Step -->
                <div id="stepPayerNumber" style="<?php echo $isWaiting ? 'display: none;' : 'display: block;'; ?>">
                    <!-- Header -->
                    <div class="branded-header">
                        <div class="branded-logo-box">
                            <img src="<?php echo $gateway_info['gateway']['logo'];?>" alt="<?php echo htmlspecialchars($gatewayName); ?>">
                        </div>
                    </div>

                    <!-- Invoice Summary Card -->
                    <div class="invoice-summary-card branded-box">
                        <div class="invoice-summary-info">
                            <span class="fw-bold fs-6"><?php echo $companyName; ?></span>
                            <span class="small font-monospace branded-text-muted" style="opacity: 0.9;">Inv No: <?php echo substr($ref, 0, 16); ?></span>
                        </div>
                        <div class="invoice-summary-amount">
                            <?php echo $currencySymbol; ?> <?php echo $txnAmount; ?>
                        </div>
                    </div>

                    <form class="personal-payment-form" id="formGenericPayment" method="POST" action="" onsubmit="return handlePaymentSubmit(event, 'generic')">
                        <input type="hidden" name="action-v2" value="custom-personal-payment-start">
                        <input type="hidden" name="gateway-id" value="<?php echo htmlspecialchars($_GET['gateway']); ?>">
                        <input type="hidden" name="transaction-id" value="<?php echo htmlspecialchars($data['transaction']['ref']); ?>">
                        <input type="hidden" name="category" value="<?php echo htmlspecialchars($currentCategory); ?>">

                        <!-- Payer Number Form Section -->
                        <div class="payer-input-section">
                            <label class="payer-label" for="payerNumberInput"><?php echo $accountLabel; ?></label>
                            <input type="tel" id="payerNumberInput" name="payer_number" class="payer-input branded-input" placeholder="e.g 01XXXXXXXXX" maxlength="14" autocomplete="tel" oninput="validatePayerNumber()">
                            <div class="payer-terms branded-text-muted">Confirm and proceed, terms & conditions</div>
                        </div>

                        <!-- Footer Actions -->
                        <div class="branded-actions">
                            <a href="<?php echo $backToCheckoutUrl; ?>" class="branded-btn branded-btn-secondary">
                                Close
                            </a>
                            <button type="submit" id="btnConfirmPayer" class="branded-btn branded-btn-primary" disabled>
                                Confirm
                            </button>
                        </div>
                    </form>
                </div>

                <!-- SCREEN 3: Generic Personal Payment Instructions / Waiting Step -->
                <div id="stepInstructionWaiting" style="<?php echo $isWaiting ? 'display: block;' : 'display: none;'; ?>">
                    <!-- Header with Cart Icon & Brand -->
                    <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom" style="border-color: rgba(255,255,255,0.2) !important;">
                        <div class="d-flex align-items-center gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M6 19m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M17 19m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M17 17h-11v-14h-2" /><path d="M6 5l14 1l-1 7h-13" /></svg>
                            <span class="fw-bold"><?php echo $companyName; ?></span>
                        </div>
                        <div class="branded-logo-box" style="padding: 4px 10px; margin: 0;">
                            <img src="<?php echo $gateway_info['gateway']['logo'];?>" alt="" style="max-height: 24px; max-width: 60px;">
                        </div>
                    </div>

                    <!-- Invoice Breakdown Box -->
                    <div class="invoice-summary-card branded-box" style="margin-bottom: 14px;">
                        <div style="font-size: 0.8125rem; width: 100%;">
                            <div class="d-flex justify-content-between py-1">
                                <span class="branded-text-muted">Invoice No:</span>
                                <span class="font-monospace fw-semibold"><?php echo substr($ref, 0, 16); ?></span>
                            </div>
                            <div class="d-flex justify-content-between py-1">
                                <span class="branded-text-muted">Total Amount:</span>
                                <span class="fw-bold"><?php echo $currencySymbol; ?> <?php echo $txnAmount; ?></span>
                            </div>
                            <div class="d-flex justify-content-between py-1">
                                <span class="branded-text-muted">Charge:</span>
                                <span class="fw-semibold"><?php echo $currencySymbol; ?> <?php echo $chargeAmount; ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Instructions Card -->
                    <div class="instruction-card branded-box">
                        <ul class="instruction-steps">
                            <li>
                                <span>1.</span>
                                <span><strong><?php echo $appName; ?></strong> অ্যাপে প্রবেশ করুন।</span>
                            </li>
                            <li>
                                <span>2.</span>
                                <span><strong>"Send Money"</strong> অপশন সিলেক্ট করুন।</span>
                            </li>
                        </ul>

                        <!-- Instruction Illustration -->
                        <img src="<?php echo $artworkUrl; ?>" alt="Instruction" class="instruction-artwork">

                        <!-- Merchant Number + Copy Button -->
                        <?php if (!empty($merchantNumber)): ?>
                            <div style="font-size: 0.8125rem; margin-bottom: 4px; font-weight: 600;" class="branded-text-muted">নিচের নাম্বারে টাকা পাঠান:</div>
                            <div class="merchant-number-box">
                                <span class="merchant-number-text" id="merchantNumText"><?php echo htmlspecialchars($merchantNumber); ?></span>
                                <button type="button" class="copy-btn" id="btnCopyMerchant" onclick="copyMerchantNumber('<?php echo htmlspecialchars($merchantNumber); ?>')">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 9.667a2.667 2.667 0 0 1 2.667 -2.667h8.666a2.667 2.667 0 0 1 2.667 2.667v8.666a2.667 2.667 0 0 1 -2.667 2.667h-8.666a2.667 2.667 0 0 1 -2.667 -2.667l0 -8.666"/><path d="M4.012 16.737a2.005 2.005 0 0 1 -1.012 -1.737v-10c0 -1.1 .9 -2 2 -2h10c.75 0 1.158 .385 1.5 1"/></svg>
                                    <span>Copy</span>
                                </button>
                            </div>
                        <?php endif; ?>

                        <!-- Amount Confirmation -->
                        <div class="d-flex justify-content-between align-items-center pt-1" style="font-size: 0.875rem;">
                            <span class="branded-text-muted">Amount:</span>
                            <span class="fw-bold fs-6"><?php echo $currencySymbol; ?> <?php echo $txnAmount; ?></span>
                        </div>
                    </div>

                    <!-- Waiting Status & Countdown Box -->
                    <div class="waiting-status-box">
                        <div class="waiting-indicator">
                            <div class="pulse-dot"></div>
                            <span id="waitingLabel">Waiting for payment..</span>
                        </div>
                        <div>
                            <span class="countdown-pill" id="countdownTimer">Session expires in 05:00</span>
                        </div>
                    </div>

                    <!-- Footer Action Buttons -->
                    <div class="branded-actions mt-3">
                        <button type="button" class="branded-btn branded-btn-secondary" onclick="backToPayerStep()">
                            Back
                        </button>
                        <button type="button" id="btnAutoVerify" class="branded-btn branded-btn-primary" onclick="triggerAutoVerification()">
                            Auto Verification
                        </button>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="custom-footer">
            <?php echo $data['options']['watermark_text'] ?? 'Powered by PipraPay'; ?>
        </div>
    </div>

    <?php echo pp_assets('footer'); ?>

    <script data-cfasync="false">
        var isInitialWaiting = <?php echo $isWaiting ? 'true' : 'false'; ?>;
        var initialExpiresIn = <?php echo $initialExpiresIn; ?>;

        document.addEventListener('DOMContentLoaded', function() {
            if (isInitialWaiting) {
                startCountdown(initialExpiresIn);
                startPolling();
            }
        });

        function handlePaymentSubmit(e, type) {
            if (e) e.preventDefault();
            proceedToInstructionStep();
            return false;
        }

        // Segmented Nagad Input Support
        function focusNagadInput() {
            var inp = document.getElementById('nagadHiddenInput');
            if (inp) inp.focus();
        }

        function onNagadInput(val) {
            val = val.replace(/[^0-9]/g, '');
            var inp = document.getElementById('nagadHiddenInput');
            if (inp) inp.value = val;

            for (var i = 0; i < 11; i++) {
                var box = document.getElementById('ndb-' + i);
                if (box) {
                    box.textContent = val[i] || '';
                    if (i === val.length || (val.length === 11 && i === 10)) {
                        box.classList.add('active');
                    } else {
                        box.classList.remove('active');
                    }
                }
            }

            var btn = document.getElementById('btnNagadProceed');
            if (btn) {
                if (val.length >= 11) {
                    btn.disabled = false;
                    btn.classList.add('enabled');
                } else {
                    btn.disabled = true;
                    btn.classList.remove('enabled');
                }
            }
        }

        // Generic Payer Input Validation
        function validatePayerNumber() {
            var input = document.getElementById('payerNumberInput');
            if (!input) return;
            var val = input.value.replace(/[^0-9]/g, '');
            var btn = document.getElementById('btnConfirmPayer');
            if (btn) {
                if (val.length >= 11) {
                    btn.disabled = false;
                } else {
                    btn.disabled = true;
                }
            }
        }

        var pollInterval = null;
        var countdownInterval = null;
        var currentSessionId = null;
        var statusEndpoint = '<?php echo pp_checkout_address() . '?gateway=' . urlencode($_GET['gateway']); ?>';

        function proceedToInstructionStep() {
            var nagadInp = document.getElementById('nagadHiddenInput');
            var genInp = document.getElementById('payerNumberInput');
            var payerNum = '';

            if (nagadInp && nagadInp.value) {
                payerNum = nagadInp.value.trim();
            } else if (genInp && genInp.value) {
                payerNum = genInp.value.trim();
            }

            if (!payerNum || payerNum.length < 11) {
                if (nagadInp) nagadInp.focus();
                else if (genInp) genInp.focus();
                return;
            }

            var btnProceed = document.getElementById('btnNagadProceed') || document.getElementById('btnConfirmPayer');
            var origBtnText = btnProceed ? btnProceed.innerHTML : '';
            if (btnProceed) {
                btnProceed.disabled = true;
                btnProceed.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> Processing...';
            }

            var formData = new FormData();
            formData.append('action-v2', 'custom-personal-payment-start');
            formData.append('gateway-id', '<?php echo addslashes($_GET['gateway']); ?>');
            formData.append('transaction-id', '<?php echo addslashes($data['transaction']['ref']); ?>');
            formData.append('category', '<?php echo addslashes($currentCategory); ?>');
            formData.append('payer_number', payerNum);

            fetch(statusEndpoint, {
                method: 'POST',
                body: formData
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (btnProceed) {
                    btnProceed.disabled = false;
                    btnProceed.innerHTML = origBtnText;
                }
                if (data.status === 'true') {
                    currentSessionId = data.session_id;
                    document.getElementById('stepPayerNumber').style.display = 'none';
                    document.getElementById('stepInstructionWaiting').style.display = 'block';
                    startCountdown(data.expires_in || 300);
                    startPolling();
                } else {
                    alert(data.message || data.title || 'Unable to start payment session. Please check your number.');
                }
            })
            .catch(function(err) {
                if (btnProceed) {
                    btnProceed.disabled = false;
                    btnProceed.innerHTML = origBtnText;
                }
                console.error('Session creation error:', err);
                alert('Connection error. Please try again.');
            });
        }

        function startPolling() {
            if (pollInterval) clearInterval(pollInterval);
            pollInterval = setInterval(checkStatus, 2000);
        }

        function stopPolling() {
            if (pollInterval) {
                clearInterval(pollInterval);
                pollInterval = null;
            }
        }

        function checkStatus(callback) {
            fetch(statusEndpoint + '&action=check_session_status')
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.status === 'completed') {
                    stopPolling();
                    if (countdownInterval) clearInterval(countdownInterval);
                    var waitLbl = document.getElementById('waitingLabel');
                    if (waitLbl) waitLbl.textContent = 'Payment verified! Redirecting...';
                    var autoBtn = document.getElementById('btnAutoVerify');
                    if (autoBtn) {
                        autoBtn.disabled = true;
                        autoBtn.innerHTML = '<svg class="pulse-dot" style="margin-right: 4px;"></svg> Verified!';
                    }
                    setTimeout(function() {
                        window.location.href = data.return_url || '<?php echo pp_checkout_address(); ?>';
                    }, 800);
                } else if (data.status === 'expired') {
                    stopPolling();
                    var timerEl = document.getElementById('countdownTimer');
                    if (timerEl) timerEl.textContent = '00:00 (Expired)';
                    var autoBtn = document.getElementById('btnAutoVerify');
                    if (autoBtn) autoBtn.disabled = true;
                    var waitLbl = document.getElementById('waitingLabel');
                    if (waitLbl) waitLbl.textContent = 'Session expired. Please go back.';
                }
                if (typeof callback === 'function') {
                    callback(data);
                }
            })
            .catch(function(err) {
                console.error('Status check error:', err);
                if (typeof callback === 'function') {
                    callback({status: 'error'});
                }
            });
        }

        function backToPayerStep() {
            stopPolling();
            if (countdownInterval) clearInterval(countdownInterval);
            document.getElementById('stepInstructionWaiting').style.display = 'none';
            document.getElementById('stepPayerNumber').style.display = 'block';
            if (window.history && window.history.replaceState) {
                window.history.replaceState({}, document.title, '<?php echo pp_checkout_address() . '?gateway=' . urlencode($_GET['gateway']) . '&category=' . urlencode($currentCategory); ?>');
            }
        }

        function copyMerchantNumber(text) {
            if (!text) return;
            navigator.clipboard.writeText(text).then(function() {
                var btn = document.getElementById('btnCopyMerchant');
                if (btn) {
                    var originalHtml = btn.innerHTML;
                    btn.innerHTML = '<span>Copied!</span>';
                    setTimeout(function() {
                        btn.innerHTML = originalHtml;
                    }, 2000);
                }
            }).catch(function(err) {
                console.error('Copy failed', err);
            });
        }

        function startCountdown(durationSeconds) {
            if (countdownInterval) clearInterval(countdownInterval);
            var timer = durationSeconds;
            var el = document.getElementById('countdownTimer');
            var verifyBtn = document.getElementById('btnAutoVerify');

            function updateDisplay() {
                var minutes = parseInt(timer / 60, 10);
                var seconds = parseInt(timer % 60, 10);
                minutes = minutes < 10 ? "0" + minutes : minutes;
                seconds = seconds < 10 ? "0" + seconds : seconds;
                if (el) el.textContent = minutes + ":" + seconds;

                if (--timer < 0) {
                    clearInterval(countdownInterval);
                    if (el) el.textContent = "00:00 (Expired)";
                    if (verifyBtn) verifyBtn.disabled = true;
                }
            }
            updateDisplay();
            countdownInterval = setInterval(updateDisplay, 1000);
        }

        function triggerAutoVerification() {
            var btn = document.getElementById('btnAutoVerify');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> Checking...';
            }
            checkStatus(function(data) {
                if (data.status !== 'completed') {
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = '<svg class="pulse-dot" style="margin-right: 4px;"></svg> Auto Verification';
                    }
                }
            });
        }
    </script>
</body>
</html>
