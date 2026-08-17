<?php
    if (!defined('PipraPay_INIT')) {
        http_response_code(403);
        exit('Direct access not allowed');
    }

    if (!canAccessPage(json_decode($global_response_permission['response'][0]['permission'], true), 'brand_settings', $global_user_response['response'][0]['role'])) {
        http_response_code(403);
        exit('Access denied. You need permission to perform this action. Please contact the admin.');
    }

    if (!hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'theme_settings', 'view', $global_user_response['response'][0]['role'])) {
        http_response_code(403);
        exit('Access denied. You need permission to perform this action. Please contact the admin.');
    }

    $brandId = $global_response_brand['response'][0]['brand_id'] ?? 'both';
    $activeDesign = get_env('payment_page_design', $brandId);
    if (empty($activeDesign) || $activeDesign === '--' || !in_array($activeDesign, ['default', 'custom'], true)) {
        $activeDesign = 'default';
    }

    $designs = [
        'default' => [
            'name'        => 'Default',
            'slug'        => 'default',
            'description' => 'Original PipraPay Checkout'
        ],
        'custom' => [
            'name'        => 'Custom',
            'slug'        => 'custom',
            'description' => 'Custom Payment Design'
        ]
    ];
?>

<div class="page-header d-print-none" aria-label="Page header">
    <div class="container-xl">
        <div class="row g-2 align-items-center">
            <div class="col">
                <div class="page-pretitle">
                    <ol class="breadcrumb breadcrumb-arrow mb-0">
                        <li class="breadcrumb-item"><a href="javascript:void(0)" onclick="load_content('Brand Settings','<?php echo $site_url.$path_admin ?>/brand-setting','nav-item-brand-setting')">Brand Settings</a></li>
                        <li class="breadcrumb-item active"><a href="javascript:void(0)">Payment Page Designs</a></li>
                    </ol>
                </div>
                <h2 class="page-title">Payment Page Designs</h2>
            </div>
        </div>
    </div>
</div>

<div class="page-body">
    <div class="container-xl">
        <div class="card">
            <div class="card-header">
                <div>
                    <h3 class="card-title">Payment Page Designs</h3>
                    <p class="card-subtitle text-muted mt-1">Select the active design for your public payment page (<code>/payment/{ref}</code>).</p>
                </div>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <?php
                        foreach ($designs as $slug => $design) {
                            $isCurrent = ($activeDesign === $slug);
                    ?>
                        <div class="col-md-6 mb-3 design-item design-<?php echo htmlspecialchars($slug); ?>">
                            <div class="card h-100 shadow-sm border <?php echo $isCurrent ? 'border-primary' : ''; ?>" style="border-radius: 8px;">
                                <div class="card-body d-flex flex-column">
                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                        <h3 class="card-title m-0 fw-bold"><?php echo htmlspecialchars($design['name']); ?></h3>
                                        <span class="badge bg-success-lt active-badge <?php echo $isCurrent ? '' : 'd-none'; ?>">Active</span>
                                    </div>
                                    <p class="text-muted mb-4"><?php echo htmlspecialchars($design['description']); ?></p>

                                    <div class="mt-auto">
                                        <a href="javascript:void(0)" onclick="activePaymentDesign('<?php echo htmlspecialchars($slug); ?>')" class="btn btn-primary w-100 activeBTN active-btn-design-<?php echo htmlspecialchars($slug); ?> <?php echo $isCurrent ? 'd-none' : ''; ?> <?= hasPermission(json_decode($global_response_permission['response'][0]['permission'], true), 'theme_settings', 'edit', $global_user_response['response'][0]['role']) ? '' : 'd-none' ?>">
                                            Activate
                                        </a>

                                        <button type="button" class="btn btn-outline-success w-100 current-btn <?php echo $isCurrent ? '' : 'd-none'; ?>" disabled>
                                            Currently Active
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php } ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script data-cfasync="false">
    function activePaymentDesign(slug) {
        var my_action_confirmation_btn = document.querySelector("#my-action-confirmation-btn").value;
        var csrf_token_default = $('input[name="csrf_token_default"]').val();
        var btnClass = 'active-btn-design-' + slug;

        if (my_action_confirmation_btn !== "") {
            var btn = document.querySelector('.' + btnClass);
            var originalHtml = btn ? btn.innerHTML : 'Activate';

            if (btn) {
                btn.innerHTML = '<div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Loading...</span></div>';
            }

            $.ajax({
                type: 'POST',
                url: '<?php echo $site_url.$path_admin ?>/dashboard',
                data: {
                    action: "payment-design-active",
                    csrf_token: csrf_token_default,
                    slug: slug
                },
                dataType: 'json',
                success: function (response) {
                    closeAllBootstrapModals();
                    document.querySelector("#my-action-confirmation-btn").value = '';

                    if (btn) {
                        btn.innerHTML = originalHtml;
                    }

                    if (response.csrf_token) {
                        document.querySelectorAll('input[name="csrf_token"], input[name="csrf_token_default"]').forEach(input => {
                            input.value = response.csrf_token;
                        });
                    }

                    if (response.status === 'true') {
                        createToast({
                            title: response.title,
                            description: response.message,
                            svg: `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#5f38f9" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-circle-check"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M9 12l2 2l4 -4" /></svg>`,
                            timeout: 6000,
                            top: 70
                        });

                        document.querySelectorAll('.design-item').forEach(function(item) {
                            var card = item.querySelector('.card');
                            var actBtn = item.querySelector('.activeBTN');
                            var curBtn = item.querySelector('.current-btn');
                            var badge = item.querySelector('.active-badge');

                            if (card) card.classList.remove('border-primary');
                            if (actBtn) actBtn.classList.remove('d-none');
                            if (curBtn) curBtn.classList.add('d-none');
                            if (badge) badge.classList.add('d-none');
                        });

                        document.querySelectorAll('.design-' + slug).forEach(function(item) {
                            var card = item.querySelector('.card');
                            var actBtn = item.querySelector('.activeBTN');
                            var curBtn = item.querySelector('.current-btn');
                            var badge = item.querySelector('.active-badge');

                            if (card) card.classList.add('border-primary');
                            if (actBtn) actBtn.classList.add('d-none');
                            if (curBtn) curBtn.classList.remove('d-none');
                            if (badge) badge.classList.remove('d-none');
                        });
                    } else {
                        createToast({
                            title: response.title || 'Error',
                            description: response.message || 'Failed to activate design',
                            svg: `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#d63939" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-exclamation-circle"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M12 9v4" /><path d="M12 16v.01" /></svg>`,
                            timeout: 6000,
                            top: 70
                        });
                    }
                },
                error: function () {
                    if (btn) btn.innerHTML = originalHtml;
                    createToast({
                        title: 'Error',
                        description: 'Failed to communicate with server.',
                        svg: `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#d63939" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-exclamation-circle"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M12 9v4" /><path d="M12 16v.01" /></svg>`,
                        timeout: 6000,
                        top: 70
                    });
                }
            });
        } else {
            show_action_confirmation_tab(btnClass, 'Activate Payment Design', 'Confirm', 'btn-primary');
        }
    }
</script>
