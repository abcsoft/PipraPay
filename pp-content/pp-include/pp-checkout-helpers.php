<?php
    /**
     * PipraPay checkout helpers (pure, dependency free).
     *
     * These helpers are shared by the checkout templates, the gateway layer and
     * the automated test-suite. They must stay free of database access and of
     * network side effects so that they can be unit tested in isolation.
     */

    if (!defined('PipraPay_INIT')) {
        http_response_code(403);
        exit('Direct access not allowed');
    }

    /**
     * Classify a gateway row into cards / mobile_banking / net_banking.
     *
     * The classification deliberately ignores the database "tab" column for
     * EPS: the provider is offered as a card gateway even though it used to be
     * stored under the mobile-financial-services tab.
     */
    function pp_custom_payment_category($gateway) {
        if (empty($gateway)) {
            return 'cards';
        }

        $slug = '';
        $tab = '';
        if (is_string($gateway)) {
            $slug = trim($gateway);
        } elseif (isset($gateway['slug'])) {
            $slug = trim($gateway['slug']);
            $tab = strtolower(trim((string)($gateway['tab'] ?? '')));
        } elseif (isset($gateway['gateway']['slug'])) {
            $slug = trim($gateway['gateway']['slug']);
            $tab = strtolower(trim((string)($gateway['gateway']['tab'] ?? '')));
        }

        $slug = strtolower($slug);

        if ($tab === 'bank' || $slug === 'bank' || strpos($slug, 'bank') !== false) {
            return 'net_banking';
        }

        $gatewayFile = __DIR__ . '/../pp-modules/pp-gateways/' . $slug . '/class.php';
        $info = [];
        if (file_exists($gatewayFile)) {
            require_once $gatewayFile;
            $class = str_replace(' ', '', ucwords(str_replace('-', ' ', $slug))) . 'Gateway';
            if (class_exists($class)) {
                $instance = new $class();
                if (method_exists($instance, 'info')) {
                    $info = $instance->info() ?? [];
                }
            }
        }

        $senderKey = strtolower(trim((string)($info['sender_key'] ?? '')));
        $gwTab     = strtolower(trim((string)($info['tab'] ?? $tab)));

        if ($gwTab === 'bank') {
            return 'net_banking';
        }

        $knownMfsKeys = [
            'bkash', 'nagad', 'rocket', 'upay', 'cellfin', 'tap', 
            'ipay', 'mcash', 'okwallet', 'pathaopay', 'telecash'
        ];
        $knownMfsSlugs = [
            'bkash-personal', 'bkash-merchant', 'bkash-agent', 'bkash-api-tokenized',
            'nagad-personal', 'nagad-merchant', 'nagad-agent', 'nagad-merchant-api',
            'rocket-personal', 'rocket-merchant', 'rocket-agent',
            'upay-personal', 'upay-merchant', 'upay-agent',
            'cellfin-personal', 'cellfin-merchant',
            'tap-personal', 'tap-merchant', 'tap-agent',
            'ipay-personal', 'ipay-merchant',
            'mcash-personal', 'mcash-merchant', 'mcash-agent',
            'okwallet-personal', 'okwallet-merchant', 'okwallet-agent',
            'pathaopay-personal', 'pathaopay-merchant', 'pathaopay-merchant-api',
            'telecash-personal', 'telecash-merchant', 'telecash-agent'
        ];

        if (in_array($senderKey, $knownMfsKeys, true) || in_array($slug, $knownMfsSlugs, true)) {
            return 'mobile_banking';
        }

        return 'cards';
    }

    if (!function_exists('pp_url_with_query')) {
        /**
         * Merge parameters into a URL query string without dropping existing
         * parameters, duplicated separators or the fragment.
         *
         * - null values remove the parameter
         * - booleans are normalised to "1" / "0"
         * - the fragment is always placed last
         *
         * Repeated keys in the incoming query string collapse to their last
         * value; PipraPay never relies on repeated keys for payment state.
         */
        function pp_url_with_query($url, array $params = [])
        {
            $url = (string) $url;

            $fragment = '';
            $fragmentPos = strpos($url, '#');
            if ($fragmentPos !== false) {
                $fragment = substr($url, $fragmentPos);
                $url = substr($url, 0, $fragmentPos);
            }

            $query = [];
            $queryPos = strpos($url, '?');
            if ($queryPos !== false) {
                $rawQuery = substr($url, $queryPos + 1);
                $url = substr($url, 0, $queryPos);

                if ($rawQuery !== '') {
                    foreach (explode('&', $rawQuery) as $pair) {
                        if ($pair === '') {
                            continue;
                        }

                        $segments = explode('=', $pair, 2);
                        $key = urldecode($segments[0]);

                        if ($key === '') {
                            continue;
                        }

                        $query[$key] = isset($segments[1]) ? urldecode($segments[1]) : '';
                    }
                }
            }

            foreach ($params as $key => $value) {
                if ($value === null) {
                    unset($query[$key]);
                    continue;
                }

                if (is_bool($value)) {
                    $value = $value ? '1' : '0';
                }

                if (!is_scalar($value)) {
                    continue;
                }

                $query[$key] = (string) $value;
            }

            $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

            return $url . ($queryString !== '' ? '?' . $queryString : '') . $fragment;
        }
    }

    if (!function_exists('pp_custom_payment_categories')) {
        /**
         * Classify gateway rows into the three checkout categories.
         *
         * Rows without a usable gateway_id are ignored: they could never be
         * selected and must not influence the automatic-checkout decision.
         *
         * @param array $gateways Rows as returned by pp_gateways().
         * @return array{cards: array, mobile_banking: array, net_banking: array}
         */
        function pp_custom_payment_categories(array $gateways)
        {
            $categorized = [
                'cards'          => [],
                'mobile_banking' => [],
                'net_banking'    => [],
            ];

            foreach ($gateways as $gateway) {
                if (!is_array($gateway) || empty($gateway['gateway_id'])) {
                    continue;
                }

                $category = pp_custom_payment_category($gateway);

                if (!isset($categorized[$category])) {
                    $category = 'cards';
                }

                $categorized[$category][] = $gateway;
            }

            return $categorized;
        }
    }

    if (!function_exists('pp_custom_auto_checkout_slugs')) {
        /**
         * Gateway slugs that may bypass the gateway-selection screen when they
         * are the only eligible gateway of their category.
         *
         * Keep this list explicit: automatic initiation is only safe for hosted
         * checkouts that always redirect the customer to the provider.
         */
        function pp_custom_auto_checkout_slugs()
        {
            return ['eps'];
        }
    }

    if (!function_exists('pp_custom_auto_checkout_target')) {
        /**
         * Decide whether the checkout page must start a hosted payment session
         * immediately instead of rendering the gateway grid.
         *
         * The decision never inspects the database "tab" column: it uses the
         * same classification as the checkout UI, and the caller only passes
         * gateways that are already active, brand-scoped and amount-eligible.
         *
         * @param array  $categorizedGateways Output of pp_custom_payment_categories().
         * @param string $checkoutUrl         Base checkout URL (no query string).
         * @param array  $query               Current request query parameters.
         * @return array{auto: bool, reason: string, slug: string, gateway_id: string, url: string}
         */
        function pp_custom_auto_checkout_target(array $categorizedGateways, $checkoutUrl, array $query = [])
        {
            $result = [
                'auto'       => false,
                'reason'     => '',
                'slug'       => '',
                'gateway_id' => '',
                'url'        => '',
            ];

            $manual = (isset($query['manual']) && is_string($query['manual']))
                ? strtolower(trim($query['manual']))
                : '';

            if (in_array($manual, ['1', 'true', 'yes', 'on'], true)) {
                $result['reason'] = 'manual_opt_out';
                return $result;
            }

            // A provider return must never be turned into another redirect: the
            // callback handler lives behind the gateway parameters, and a second
            // 302 would drop `pp_callback` and loop.
            if (array_key_exists('pp_callback', $query)) {
                $result['reason'] = 'provider_callback';
                return $result;
            }

            if (isset($query['eps_error']) && $query['eps_error'] !== '' && $query['eps_error'] !== null) {
                $result['reason'] = 'recent_failure';
                return $result;
            }

            if (!empty($query['gateway'])) {
                $result['reason'] = 'gateway_already_selected';
                return $result;
            }

            $cards = isset($categorizedGateways['cards']) && is_array($categorizedGateways['cards'])
                ? array_values($categorizedGateways['cards'])
                : [];

            if (count($cards) !== 1) {
                $result['reason'] = count($cards) > 1 ? 'multiple_card_gateways' : 'no_card_gateway';
                return $result;
            }

            $gateway = $cards[0];

            if (!is_array($gateway)) {
                $result['reason'] = 'invalid_gateway_row';
                return $result;
            }

            $slug = strtolower(trim((string) ($gateway['slug'] ?? '')));
            $gatewayId = trim((string) ($gateway['gateway_id'] ?? ''));

            if ($slug === '' || !in_array($slug, pp_custom_auto_checkout_slugs(), true)) {
                $result['reason'] = 'not_auto_checkout_gateway';
                return $result;
            }

            if ($gatewayId === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $gatewayId)) {
                $result['reason'] = 'invalid_gateway_id';
                return $result;
            }

            $result['auto'] = true;
            $result['reason'] = 'single_eligible_card_gateway';
            $result['slug'] = $slug;
            $result['gateway_id'] = $gatewayId;
            $result['url'] = pp_url_with_query($checkoutUrl, [
                'gateway'  => $gatewayId,
                'category' => 'cards',
            ]);

            return $result;
        }
    }

    if (!function_exists('pp_eps_public_error_reason')) {
        /**
         * Map an internal EPS diagnostic code to a coarse, non-sensitive reason.
         *
         * Only these reasons may be placed in a checkout URL: they carry no
         * provider detail, no HTTP status and no configuration information.
         * Values that are already public reasons are passed through unchanged.
         */
        function pp_eps_public_error_reason($code)
        {
            $value = strtolower(preg_replace('/[^A-Za-z0-9_]/', '', (string) $code));

            $public = ['configuration', 'amount', 'customer', 'unreachable', 'authentication', 'provider_response', 'rejected', 'session', 'verification', 'unknown'];

            if (in_array($value, $public, true)) {
                return $value;
            }

            $map = [
                'EPS_CONFIG_INCOMPLETE'        => 'configuration',
                'EPS_AMOUNT_INVALID'           => 'amount',
                'EPS_CUSTOMER_INVALID'         => 'customer',
                'EPS_AUTH_TRANSPORT'           => 'unreachable',
                'EPS_INIT_TRANSPORT'           => 'unreachable',
                'EPS_AUTH_HTTP'                => 'authentication',
                'EPS_AUTH_NO_TOKEN'            => 'authentication',
                'EPS_AUTH_INVALID_JSON'        => 'provider_response',
                'EPS_INIT_HTTP'                => 'provider_response',
                'EPS_INIT_INVALID_JSON'        => 'provider_response',
                'EPS_INIT_REJECTED'            => 'rejected',
                'EPS_INIT_MISSING_URL'         => 'session',
                'EPS_INIT_INSECURE_URL'        => 'session',
                'EPS_INIT_UNTRUSTED_HOST'      => 'session',
                'EPS_INIT_NULL_SESSION'        => 'session',
                'EPS_INIT_MALFORMED_SESSION'   => 'session',
                'EPS_CALLBACK_INVALID'         => 'verification',
                'EPS_CALLBACK_MISMATCH'        => 'verification',
                'EPS_CALLBACK_VERIFY_FAILED'   => 'verification',
                'EPS_CALLBACK_AMOUNT_MISMATCH' => 'verification',
                'EPS_COMPLETION_REJECTED'      => 'verification',
                'EPS_TRANSACTION_STATE'        => 'verification',
            ];

            return $map[strtoupper($value)] ?? 'unknown';
        }
    }

    if (!function_exists('pp_eps_checkout_error_message')) {
        /**
         * Customer-facing message for an EPS public reason (or a legacy
         * internal diagnostic code).
         *
         * Never include provider payloads, HTTP statuses or credentials here.
         */
        function pp_eps_checkout_error_message($reasonOrCode)
        {
            $reason = pp_eps_public_error_reason($reasonOrCode);

            $messages = [
                'configuration'     => 'The EPS gateway is not fully configured yet. Please choose another payment method or contact the merchant.',
                'amount'            => 'This amount cannot be processed by EPS. Please choose another payment method.',
                'customer'          => 'Some customer details required by EPS are missing. Please choose another payment method or contact the merchant.',
                'unreachable'       => 'The payment provider could not be reached. Please try again or choose another payment method.',
                'authentication'    => 'The payment provider rejected the authentication request. Please try again later or choose another payment method.',
                'provider_response' => 'The payment provider returned an unusable response. Please try again or choose another payment method.',
                'rejected'          => 'The payment provider rejected this payment request. Please choose another payment method.',
                'session'           => 'The payment provider did not create a valid payment session. Please try again or choose another payment method.',
                'verification'      => 'The payment could not be verified. Please contact the merchant if money was deducted.',
                'unknown'           => 'The EPS payment session could not be started. Please try again or choose another payment method.',
            ];

            return $messages[$reason];
        }
    }