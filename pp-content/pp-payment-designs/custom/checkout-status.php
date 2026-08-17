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

    if(isset($_GET['receipt'])){
        pp_downloadReceiptPDF($data);
    }

    if(isset($_GET['lang'])){
        if($_GET['lang'] !== ""){
            pp_set_lang($_GET['lang']);
?>
            <script>
                location.href = '?lang=';
            </script>
<?php
            exit();
        }
    }

    if ((isset($_GET['action-v2']) && $_GET['action-v2'] === 'custom-personal-payment-status') ||
        (isset($_POST['action-v2']) && $_POST['action-v2'] === 'custom-personal-payment-status') ||
        (isset($_GET['action']) && $_GET['action'] === 'check_session_status')) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        $txRef = trim((string)($data['transaction']['ref'] ?? $_GET['transaction-id'] ?? $_POST['transaction-id'] ?? ''));
        $res = pp_get_personal_payment_session_status($txRef);
        echo json_encode($res);
        exit();
    }

    $status = strtolower($data['transaction']['status'] ?? 'pending');

    if ($status === 'initiated') {
        if (!empty($_GET['gateway'])) {
            include __DIR__ . '/gateway.php';
            return;
        } else {
            $txRef = $data['transaction']['ref'] ?? '';
            $sessRes = function_exists('pp_get_personal_payment_session_status') ? pp_get_personal_payment_session_status($txRef) : ['status' => 'none'];
            $pStatus = strtolower((string)($sessRes['payment_status'] ?? $sessRes['status'] ?? ''));
            if ($pStatus === 'waiting') {
                global $db_prefix;
                $db_prefix_str = !empty($db_prefix) ? $db_prefix : 'pp_';
                $sessRow = json_decode(getData($db_prefix_str . 'personal_payment_sessions', 'WHERE transaction_ref = :ref ORDER BY id DESC LIMIT 1', '* FROM', [':ref' => $txRef]), true);
                if (!empty($sessRow['response'][0]['gateway_id'])) {
                    $_GET['gateway'] = $sessRow['response'][0]['gateway_id'];
                    $_GET['category'] = 'mobile_banking';
                    $_GET['step'] = 'waiting';
                    include __DIR__ . '/gateway.php';
                    return;
                }
            }
        }
    }

    $statusMap = [
        'completed' => ['text' => $data['lang']['payment_successful'] ?? 'Payment Successful', 'color' => '#16a34a', 'bg' => 'rgba(22, 163, 74, 0.1)'],
        'pending'   => ['text' => $data['lang']['payment_pending'] ?? 'Payment Pending', 'color' => '#ca8a04', 'bg' => 'rgba(202, 138, 4, 0.1)'],
        'refunded'  => ['text' => $data['lang']['payment_refunded'] ?? 'Payment Refunded', 'color' => '#2563eb', 'bg' => 'rgba(37, 99, 235, 0.1)'],
        'canceled'  => ['text' => $data['lang']['payment_canceled'] ?? 'Payment Canceled', 'color' => '#dc2626', 'bg' => 'rgba(220, 38, 38, 0.1)'],
    ];

    $currentStatus = $statusMap[$status] ?? $statusMap['pending'];
    $txnAmount = money_round($data['transaction']['amount'] ?? 0, 2);
    $currency = htmlspecialchars($data['transaction']['currency'] ?? 'BDT');
    $currencySymbol = ($currency === 'BDT') ? '৳' : $currency;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?php echo $data['lang']['checkout'] ?? 'Status'?> - <?php echo htmlspecialchars($data['brand']['name']);?></title>
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
        <div class="custom-card" style="padding: 32px 24px; text-align: center;">
            <!-- Status Icon -->
            <div style="width: 72px; height: 72px; border-radius: 50%; background: <?php echo $currentStatus['bg']; ?>; color: <?php echo $currentStatus['color']; ?>; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 16px;">
                <?php if ($status === 'completed'): ?>
                    <svg xmlns="http://www.w3.org/2000/svg" width="38" height="38" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 12l5 5l10 -10" /></svg>
                <?php elseif ($status === 'canceled'): ?>
                    <svg xmlns="http://www.w3.org/2000/svg" width="38" height="38" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M18 6l-12 12" /><path d="M6 6l12 12" /></svg>
                <?php elseif ($status === 'refunded'): ?>
                    <svg xmlns="http://www.w3.org/2000/svg" width="38" height="38" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 14l-4 -4l4 -4" /><path d="M5 10h11a4 4 0 1 1 0 8h-1" /></svg>
                <?php else: ?>
                    <svg xmlns="http://www.w3.org/2000/svg" width="38" height="38" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M12 7v5l3 3" /></svg>
                <?php endif; ?>
            </div>

            <h3 class="fw-bold mb-1" style="color: <?php echo $currentStatus['color']; ?>;"><?php echo $currentStatus['text']; ?></h3>
            <p class="text-muted small mb-4">
                <?php
                switch($status){
                    case 'completed':
                        echo $data['lang']['change_status_completed'] ?? 'Thank you! Your payment has been processed successfully.';
                        break;
                    case 'pending':
                        echo $data['lang']['change_status_pending'] ?? 'Your transaction is pending verification.';
                        break;
                    case 'refunded':
                        echo $data['lang']['change_status_refunded'] ?? 'This transaction has been refunded.';
                        break;
                    case 'canceled':
                        echo $data['lang']['change_status_cancled'] ?? 'This transaction was canceled.';
                        break;
                }
                ?>
            </p>

            <!-- Table Breakdown -->
            <div style="border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; margin-bottom: 20px; text-align: left; font-size: 0.875rem;">
                <div class="d-flex justify-content-between p-3 border-bottom text-muted">
                    <span>Payment Method:</span>
                    <span class="fw-semibold text-dark"><?php echo htmlspecialchars($data['transaction']['payment_method'] ?? 'N/A'); ?></span>
                </div>
                <div class="d-flex justify-content-between p-3 border-bottom text-muted">
                    <span>Amount:</span>
                    <span class="fw-semibold text-dark"><?php echo $currencySymbol; ?> <?php echo $txnAmount; ?> <?php echo $currency; ?></span>
                </div>
                <div class="d-flex justify-content-between p-3 border-bottom text-muted">
                    <span>Discount:</span>
                    <span class="fw-semibold text-success">-<?php echo $currencySymbol; ?> <?php echo money_round($data['transaction']['discount_amount'] ?? 0, 2); ?></span>
                </div>
                <div class="d-flex justify-content-between p-3 border-bottom text-muted">
                    <span>Processing Fee:</span>
                    <span class="fw-semibold text-dark"><?php echo $currencySymbol; ?> <?php echo money_round($data['transaction']['processing_fee'] ?? 0, 2); ?></span>
                </div>
                <div class="d-flex justify-content-between p-3 bg-light fw-bold text-dark fs-6">
                    <span>Status:</span>
                    <span style="color: <?php echo $currentStatus['color']; ?>;"><?php echo ucfirst($status); ?></span>
                </div>
            </div>

            <!-- Actions -->
            <div class="d-flex gap-2 justify-content-center">
                <?php if(!empty($data['transaction']['return_url']) && $data['transaction']['return_url'] !== '--'): ?>
                    <a href="<?php echo $data['transaction']['return_url']; ?>" class="pay-submit-btn" style="flex:1; padding: 10px 16px; font-size: 0.9375rem;">
                        <?php echo $data['lang']['go_to_site'] ?? 'Return to Site'; ?>
                    </a>
                <?php endif; ?>

                <?php if($status == 'completed' || $status == 'pending' || $status == 'refunded'): ?>
                    <a href="<?php echo pp_checkout_address(); ?>?receipt" class="pay-submit-btn" style="flex:1; padding: 10px 16px; font-size: 0.9375rem; background: #16a34a;">
                        <?php echo $data['lang']['download_receipt'] ?? 'Download Receipt'; ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <div class="custom-footer">
            <?php echo $data['options']['watermark_text'] ?? 'Powered by PipraPay'; ?>
        </div>
    </div>

    <?php echo pp_assets('footer'); ?>
</body>
</html>
