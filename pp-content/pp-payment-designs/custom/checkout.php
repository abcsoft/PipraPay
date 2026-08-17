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
?>
            <script>
                location.href = '?lang=';
            </script>
<?php
            exit();
        }
    }

    if(isset($_GET['cancel'])){
        pp_set_transaction_status($data['transaction']['ref'], 'canceled');
?>
        <script>
            location.href = '<?php echo pp_checkout_address();?>';
        </script>
<?php
        exit();
    }

    // Collect all active gateways for the brand
    $allGateways = [];
    $mfsRes = pp_gateways('mfs', $data);
    if (!empty($mfsRes['gateway'])) {
        foreach ($mfsRes['gateway'] as $gw) {
            $allGateways[$gw['gateway_id']] = $gw;
        }
    }
    $bankRes = pp_gateways('bank', $data);
    if (!empty($bankRes['gateway'])) {
        foreach ($bankRes['gateway'] as $gw) {
            $allGateways[$gw['gateway_id']] = $gw;
        }
    }
    $globalRes = pp_gateways('global', $data);
    if (!empty($globalRes['gateway'])) {
        foreach ($globalRes['gateway'] as $gw) {
            $allGateways[$gw['gateway_id']] = $gw;
        }
    }

    // Classify into exact 3 categories: Cards, Mobile Banking, Net Banking
    $categorizedGateways = [
        'cards'          => [],
        'mobile_banking' => [],
        'net_banking'    => []
    ];

    foreach ($allGateways as $gw) {
        $cat = function_exists('pp_custom_payment_category')
            ? pp_custom_payment_category($gw)
            : 'cards';

        if (isset($categorizedGateways[$cat])) {
            $categorizedGateways[$cat][] = $gw;
        } else {
            $categorizedGateways['cards'][] = $gw;
        }
    }

    // Validate requested category strictly against exact 3 categories
    $allowedCategories = ['cards', 'mobile_banking', 'net_banking'];
    $requestedCategory = isset($_GET['category']) ? strtolower(trim((string)$_GET['category'])) : '';

    if (in_array($requestedCategory, $allowedCategories, true) && !empty($categorizedGateways[$requestedCategory])) {
        $activeCategory = $requestedCategory;
    } else {
        // Safe fallback to first available category with enabled gateways
        $activeCategory = '';
        foreach ($allowedCategories as $c) {
            if (!empty($categorizedGateways[$c])) {
                $activeCategory = $c;
                break;
            }
        }
        if (empty($activeCategory)) {
            $activeCategory = 'cards';
        }
    }

    $txnAmount = money_round($data['transaction']['amount'], 2);
    $currency = htmlspecialchars($data['transaction']['currency'] ?? 'BDT');
    $currencySymbol = ($currency === 'BDT') ? '৳' : $currency;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?php echo $data['lang']['checkout'] ?? 'Checkout'?> - <?php echo htmlspecialchars($data['brand']['name']);?></title>
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
        <div class="custom-card">
            <!-- Header Section -->
            <div class="custom-header">
                <!-- Subtle Close Button -->
                <button type="button" class="close-btn" onclick="location.href='<?php echo pp_checkout_address();?>?cancel'" title="Cancel">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>

                <!-- Company Logo & Name -->
                <img src="<?php echo $data['brand']['favicon'];?>" alt="" class="company-logo">
                <h3 class="company-name"><?php echo htmlspecialchars($data['brand']['name']);?></h3>

                <!-- View Details Pill with Toggle Chevron -->
                <div class="view-details-pill" id="detailsToggleBtn" onclick="toggleDetailsDrawer()">
                    <span>View Details</span>
                    <svg class="chevron-icon" xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                </div>
            </div>

            <!-- In-Card Expandable Transaction Details Accordion Drawer -->
            <div id="detailsDrawer" class="custom-details-drawer" style="display: none;">
                <div class="details-drawer-inner">
                    <div class="details-row">
                        <span class="details-label">Invoice Ref</span>
                        <span class="details-value font-monospace"><?php echo htmlspecialchars($data['transaction']['ref']); ?></span>
                    </div>
                    <div class="details-row">
                        <span class="details-label">Subtotal</span>
                        <span class="details-value"><?php echo $currencySymbol; ?><?php echo money_round(($data['transaction']['amount'] ?? 0) - ($data['transaction']['discount_amount'] ?? 0), 2); ?></span>
                    </div>
                    <div class="details-row">
                        <span class="details-label">Discount</span>
                        <span class="details-value text-success">-<?php echo $currencySymbol; ?><?php echo money_round($data['transaction']['discount_amount'] ?? 0, 2); ?></span>
                    </div>
                    <?php if (!empty($data['transaction']['processing_fee']) && bccomp(money_sanitize($data['transaction']['processing_fee']), '0', 2) > 0): ?>
                        <div class="details-row">
                            <span class="details-label">Charge</span>
                            <span class="details-value"><?php echo $currencySymbol; ?><?php echo money_round($data['transaction']['processing_fee'], 2); ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="details-row details-row-total">
                        <span class="details-label">Total Amount</span>
                        <span class="details-value"><?php echo $currencySymbol; ?><?php echo $txnAmount; ?> <?php echo $currency; ?></span>
                    </div>
                </div>
            </div>

            <!-- Modern Compact Category Navbar / Nav-Pills (Cards, Mobile Banking, Net Banking) -->
            <div class="category-tabs">
                <?php if (!empty($categorizedGateways['cards'])): ?>
                    <button type="button" class="category-tab-btn tab-cards <?php echo ($activeCategory === 'cards') ? 'active' : ''; ?>" data-tab="cards">
                        <svg class="category-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="2" y="5" width="20" height="14" rx="2"/>
                            <line x1="2" y1="10" x2="22" y2="10"/>
                        </svg>
                        <span>Cards</span>
                    </button>
                <?php endif; ?>

                <?php if (!empty($categorizedGateways['mobile_banking'])): ?>
                    <button type="button" class="category-tab-btn tab-mobile_banking <?php echo ($activeCategory === 'mobile_banking') ? 'active' : ''; ?>" data-tab="mobile_banking">
                        <svg class="category-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="7" y="2" width="10" height="20" rx="2" ry="2"/>
                            <line x1="11" y1="18" x2="13" y2="18"/>
                        </svg>
                        <span>Mobile Banking</span>
                    </button>
                <?php endif; ?>

                <?php if (!empty($categorizedGateways['net_banking'])): ?>
                    <button type="button" class="category-tab-btn tab-net_banking <?php echo ($activeCategory === 'net_banking') ? 'active' : ''; ?>" data-tab="net_banking">
                        <svg class="category-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3 21h18"/>
                            <path d="M3 10h18"/>
                            <path d="M5 6l7-3 7 3"/>
                            <path d="M4 10v11"/>
                            <path d="M20 10v11"/>
                            <path d="M8 14v3"/>
                            <path d="M12 14v3"/>
                            <path d="M16 14v3"/>
                        </svg>
                        <span>Net Banking</span>
                    </button>
                <?php endif; ?>
            </div>

            <!-- Gateway Grid Area -->
            <div class="gateway-grid-wrap">
                <!-- Cards Gateways (e.g. EPS, SSLCommerz, Stripe, PayPal, PayStation, etc.) -->
                <?php if (!empty($categorizedGateways['cards'])): ?>
                    <div id="gateways-cards" class="gateway-grid" style="<?php echo ($activeCategory === 'cards') ? 'display: grid;' : 'display: none;'; ?>">
                        <?php foreach($categorizedGateways['cards'] as $row): ?>
                            <div class="gateway-card" data-gateway-id="<?php echo $row['gateway_id']; ?>" data-category="cards" onclick="selectGateway('<?php echo $row['gateway_id']; ?>', 'cards')">
                                <div class="gateway-logo-wrap">
                                    <img src="<?php echo $row['logo']; ?>" alt="<?php echo htmlspecialchars($row['display']); ?>">
                                </div>
                                <p class="gateway-name"><?php echo htmlspecialchars($row['display']); ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Mobile Banking Gateways (e.g. bKash, Nagad, Rocket, Upay, CellFin, Tap, etc.) -->
                <?php if (!empty($categorizedGateways['mobile_banking'])): ?>
                    <div id="gateways-mobile_banking" class="gateway-grid" style="<?php echo ($activeCategory === 'mobile_banking') ? 'display: grid;' : 'display: none;'; ?>">
                        <?php foreach($categorizedGateways['mobile_banking'] as $row): ?>
                            <div class="gateway-card" data-gateway-id="<?php echo $row['gateway_id']; ?>" data-category="mobile_banking" onclick="selectGateway('<?php echo $row['gateway_id']; ?>', 'mobile_banking')">
                                <div class="gateway-logo-wrap">
                                    <img src="<?php echo $row['logo']; ?>" alt="<?php echo htmlspecialchars($row['display']); ?>">
                                </div>
                                <p class="gateway-name"><?php echo htmlspecialchars($row['display']); ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Net Banking Gateways (e.g. Bank Transfer, Internet Banking) -->
                <?php if (!empty($categorizedGateways['net_banking'])): ?>
                    <div id="gateways-net_banking" class="gateway-grid" style="<?php echo ($activeCategory === 'net_banking') ? 'display: grid;' : 'display: none;'; ?>">
                        <?php foreach($categorizedGateways['net_banking'] as $row): ?>
                            <div class="gateway-card" data-gateway-id="<?php echo $row['gateway_id']; ?>" data-category="net_banking" onclick="selectGateway('<?php echo $row['gateway_id']; ?>', 'net_banking')">
                                <div class="gateway-logo-wrap">
                                    <img src="<?php echo $row['logo']; ?>" alt="<?php echo htmlspecialchars($row['display']); ?>">
                                </div>
                                <p class="gateway-name"><?php echo htmlspecialchars($row['display']); ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Bottom Pay Button -->
            <div class="pay-action-wrap">
                <button type="button" id="mainPayBtn" class="pay-submit-btn" onclick="proceedToPayment()">
                    <span>Pay <?php echo $currencySymbol; ?><?php echo $txnAmount; ?></span>
                </button>
            </div>
        </div>

        <div class="custom-footer">
            <?php echo $data['options']['watermark_text'] ?? 'Powered by PipraPay'; ?>
        </div>
    </div>

    <?php echo pp_assets('footer'); ?>

    <script data-cfasync="false">
        var currentCategory = '<?php echo $activeCategory; ?>';
        var selectedGatewayId = null;

        function toggleDetailsDrawer() {
            var drawer = document.getElementById('detailsDrawer');
            var btn = document.getElementById('detailsToggleBtn');
            if (!drawer) return;
            if (drawer.style.display === 'none' || drawer.style.display === '') {
                drawer.style.display = 'block';
                if (btn) btn.classList.add('expanded');
            } else {
                drawer.style.display = 'none';
                if (btn) btn.classList.remove('expanded');
            }
        }

        function selectGateway(gatewayId, category) {
            selectedGatewayId = gatewayId;
            var cat = category || currentCategory || 'cards';
            document.querySelectorAll('.pp-payment-custom .gateway-card').forEach(function(card) {
                card.classList.remove('selected');
            });
            var el = document.querySelector('.pp-payment-custom .gateway-card[data-gateway-id="' + gatewayId + '"]');
            if (el) {
                el.classList.add('selected');
            }
            location.href = '<?php echo pp_checkout_address(); ?>?gateway=' + encodeURIComponent(gatewayId) + '&category=' + encodeURIComponent(cat);
        }

        function proceedToPayment() {
            if (selectedGatewayId) {
                selectGateway(selectedGatewayId, currentCategory);
            } else {
                var activeGrid = document.querySelector('.pp-payment-custom .gateway-grid:not([style*="none"])');
                var firstCard = activeGrid ? activeGrid.querySelector('.gateway-card') : null;
                if (firstCard && firstCard.dataset.gatewayId) {
                    selectGateway(firstCard.dataset.gatewayId, firstCard.dataset.category || currentCategory);
                }
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            var tabs = document.querySelectorAll('.pp-payment-custom .category-tab-btn');
            var grids = {
                'cards': document.getElementById('gateways-cards'),
                'mobile_banking': document.getElementById('gateways-mobile_banking'),
                'net_banking': document.getElementById('gateways-net_banking')
            };

            tabs.forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var tab = this.dataset.tab;
                    currentCategory = tab;
                    tabs.forEach(function(b) { b.classList.remove('active'); });
                    this.classList.add('active');

                    Object.keys(grids).forEach(function(k) {
                        if (grids[k]) grids[k].style.display = 'none';
                    });
                    if (grids[tab]) grids[tab].style.display = 'grid';
                });
            });
        });
    </script>
</body>
</html>
