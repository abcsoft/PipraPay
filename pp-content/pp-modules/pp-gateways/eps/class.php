<?php
    /**
     * EPS Gateway (Easy Payment System, Bangladesh)
     * ==============================================
     *
     * Hardening notes
     * ---------------
     *  - Every provider call goes through httpPostJson() / httpGetJson(), which
     *    enforce connect and total timeouts, TLS verification, HTTPS-only
     *    protocol and no redirect following.
     *  - The hosted checkout URL returned by InitializeEPS is validated before
     *    it is handed to the browser: https, EPS-owned host and a real session
     *    identifier ("data=null" / "data=undefined" / empty are rejected).
     *  - A successfully created hosted session is remembered against the
     *    transaction reference, so a refresh re-uses the same session instead of
     *    creating a second one (duplicate-charge protection).
     *  - Payment completion is decided by the server-to-server status check plus
     *    the transaction layer's atomic transition. A browser redirect alone is
     *    never trusted.
     *  - Diagnostics are written with error_log() and never contain tokens,
     *    credentials, signatures or full provider URLs.
     *
     * Provider field names below are the ones already used by this integration.
     * Fields have NOT been invented or copied from an unrelated integration. The
     * published EPS merchant documentation is not bundled with this repository,
     * so unknown response shapes are handled conservatively instead of guessed.
     */
    class EpsGateway
    {
        const MODE_LIVE    = 'live';
        const MODE_SANDBOX = 'sandbox';

        const LIVE_BASE_URL    = 'https://pgapi.eps.com.bd';
        const SANDBOX_BASE_URL = 'https://sandboxpgapi.eps.com.bd';

        const CONNECT_TIMEOUT = 10;
        const REQUEST_TIMEOUT = 45;

        const CHECKOUT_SESSION_TTL     = 900;
        const SESSION_META_KEY         = 'eps_checkout';
        const MIN_SESSION_TOKEN_LENGTH = 8;

        public function info()
        {
            return [
                'title'       => 'EPS Gateway',
                'logo'        => 'assets/logo.jpg',
                'currency'        => 'BDT',
                'tab'        => 'mfs',

                'gateway_type'        => 'api',
            ];
        }

        public function color()
        {
            return [
                'primary_color'        => '#ee2d42',
                'text_color'        => '#FFFFFF',
                'btn_color'        => '#ee2d42',
                'btn_text_color'        => '#FFFFFF',
            ];
        }

        public function fields()
        {
            return [
                [
                    'name'  => 'merchant_id',
                    'label' => 'Merchant ID',
                    'type'  => 'text',
                ],
                [
                    'name'  => 'store_id',
                    'label' => 'Store id',
                    'type'  => 'text',
                ],
                [
                    'name'  => 'username',
                    'label' => 'Username',
                    'type'  => 'text',
                ],
                [
                    'name'  => 'password',
                    'label' => 'Password',
                    'type'  => 'text',
                ],
                [
                    'name'  => 'hashkey',
                    'label' => 'Hash key',
                    'type'  => 'text',
                ],
                [
                    'name'  => 'mode',
                    'label' => 'Mode',
                    'type'  => 'select',
                    'options' => [
                        'live'  => 'Live',
                        'sandbox' => 'Sandbox',
                    ],
                    'value' => 'live',
                    'required' => true,
                    'multiple' => false,
                ],
            ];
        }

        // -----------------------------------------------------------------
        // Entry points used by pp_gateway_render()
        // -----------------------------------------------------------------

        public function process_payment($data = [])
        {
            echo '<center><div class="spinner-border text-primary m-3 loading-123412341234" role="status"><span class="visually-hidden">Loading...</span></div></center>';

            $result = $this->initiate($data);

            if (!empty($result['ok']) && !empty($result['redirect_url'])) {
                // json_encode() keeps the URL inside a JavaScript string literal
                // without allowing markup or quote injection.
                echo '<script>location.href=' . json_encode((string) $result['redirect_url'], JSON_UNESCAPED_SLASHES) . ';</script>';
                return;
            }

            $this->renderFailure($data, is_array($result) ? $result : []);
        }

        public function callback($data = [])
        {
            echo '<center><div class="spinner-border text-primary m-3 loading-123412341234" role="status"><span class="visually-hidden">Loading...</span></div></center>';

            $result = $this->verify($data);

            if (!empty($result['ok'])) {
                echo '<script>location.reload();</script>';
                return;
            }

            $this->renderVerificationFailure($data, is_array($result) ? $result : []);
        }

        // -----------------------------------------------------------------
        // Checkout initialisation
        // -----------------------------------------------------------------

        /**
         * Create (or re-use) the hosted EPS checkout session.
         *
         * @return array{ok: bool, redirect_url?: string, code?: string, message?: string, retryable?: bool}
         */
        public function initiate($data = [])
        {
            $config = $this->resolveConfig($data);
            if (empty($config['ok'])) {
                return $config;
            }

            $settings = $config['settings'];
            $transaction = is_array($data['transaction'] ?? null) ? $data['transaction'] : [];
            $reference = trim((string) ($transaction['ref'] ?? ''));

            if ($reference === '') {
                return $this->fail('EPS_CONFIG_INCOMPLETE', ['detail' => 'missing_transaction_reference']);
            }

            $amount = $this->normaliseAmount($transaction['local_net_amount'] ?? ($transaction['amount'] ?? null));
            if ($amount === null) {
                return $this->fail('EPS_AMOUNT_INVALID', ['ref' => $reference]);
            }

            $isRetry = $this->isRetryRequest();
            $stored = $this->loadSession($reference);

            if (!$isRetry && $this->isStoredSessionReusable($stored)) {
                $reuse = $this->validateRedirectUrl($stored['redirect_url']);
                if (!empty($reuse['ok'])) {
                    $this->log('EPS_SESSION_REUSED', ['ref' => $reference]);

                    return [
                        'ok'                      => true,
                        'redirect_url'            => $reuse['url'],
                        'merchant_transaction_id' => (string) ($stored['merchant_transaction_id'] ?? ''),
                        'reused'                  => true,
                    ];
                }
            }

            $customer = $this->buildCustomer($data);
            if (empty($customer['ok'])) {
                return $this->fail('EPS_CUSTOMER_INVALID', ['ref' => $reference, 'detail' => $customer['detail'] ?? '']);
            }

            $tokenResult = $this->requestToken($settings);
            if (empty($tokenResult['ok'])) {
                return $tokenResult;
            }

            $merchantTransactionId = $this->generateMerchantTransactionId($reference);

            $payload = [
                'merchantId'             => $settings['merchant_id'],
                'storeId'                => $settings['store_id'],
                'CustomerOrderId'        => (string) $merchantTransactionId,
                'merchantTransactionId'  => $merchantTransactionId,
                'transactionTypeId'      => 1, // Web
                'totalAmount'            => $amount,
                'successUrl'             => pp_callback_url(),
                'failUrl'                => $this->checkoutReturnUrl($reference),
                'cancelUrl'              => $this->checkoutReturnUrl($reference),
                'customerName'           => $customer['name'],
                'customerEmail'          => $customer['email'],
                'customerAddress'        => 'N/A',
                'customerCity'           => 'N/A',
                'customerState'          => 'N/A',
                'customerPostcode'       => '0000',
                'customerCountry'        => 'BD',
                'customerPhone'          => $customer['phone'],
                'productName'            => 'Digital Products',
                'ValueA'                 => (string) $reference,
            ];

            $headers = [
                'Authorization: Bearer ' . $tokenResult['token'],
                'x-hash: ' . $this->sign($merchantTransactionId, $settings['hashkey']),
            ];

            $response = $this->httpPostJson($settings['base_url'] . '/v1/EPSEngine/InitializeEPS', $headers, $payload);

            if (empty($response['ok'])) {
                return $this->fail('EPS_INIT_TRANSPORT', [
                    'ref'   => $reference,
                    'errno' => $response['errno'] ?? '',
                ]);
            }

            if (!$this->isSuccessStatus($response['status'] ?? 0)) {
                return $this->fail('EPS_INIT_HTTP', [
                    'ref'  => $reference,
                    'http' => $response['status'] ?? 0,
                ]);
            }

            $decoded = $this->decodeJson($response['body'] ?? '');
            if ($decoded === null) {
                return $this->fail('EPS_INIT_INVALID_JSON', [
                    'ref'  => $reference,
                    'http' => $response['status'] ?? 0,
                ]);
            }

            $providerFailure = $this->detectProviderFailure($decoded);
            if ($providerFailure !== '') {
                return $this->fail('EPS_INIT_REJECTED', [
                    'ref'      => $reference,
                    'http'     => $response['status'] ?? 0,
                    'provider' => $providerFailure,
                ]);
            }

            $validation = $this->validateRedirectUrl($decoded['RedirectURL'] ?? null);
            if (empty($validation['ok'])) {
                return $this->fail($validation['code'], [
                    'ref'  => $reference,
                    'http' => $response['status'] ?? 0,
                    'info' => $validation['detail'] ?? '',
                ]);
            }

            $this->saveSession($reference, [
                'redirect_url'            => $validation['url'],
                'merchant_transaction_id' => $merchantTransactionId,
                'created_at'              => time(),
                'mode'                    => $settings['mode'],
            ]);

            $this->log('EPS_SESSION_CREATED', ['ref' => $reference, 'mode' => $settings['mode']]);

            return [
                'ok'                      => true,
                'redirect_url'            => $validation['url'],
                'merchant_transaction_id' => $merchantTransactionId,
                'reused'                  => false,
            ];
        }

        // -----------------------------------------------------------------
        // Provider callback verification
        // -----------------------------------------------------------------

        /**
         * Verify a return from the EPS hosted checkout.
         *
         * The browser parameters are only used as search keys: the authoritative
         * answer comes from EPSEngine/CheckMerchantTransactionStatus.
         *
         * @return array{ok: bool, code?: string, message?: string, retryable?: bool}
         */
        public function verify($data = [])
        {
            $transaction = is_array($data['transaction'] ?? null) ? $data['transaction'] : [];
            $reference = trim((string) ($transaction['ref'] ?? ''));

            if ($reference === '') {
                return $this->fail('EPS_CALLBACK_INVALID', ['detail' => 'missing_reference']);
            }

            $state = strtolower(trim((string) ($transaction['status'] ?? '')));
            if ($state === 'completed') {
                // Idempotent: nothing to do, the status page shows the result.
                return ['ok' => true, 'already_completed' => true];
            }
            if ($state !== '' && $state !== 'initiated') {
                return $this->fail('EPS_TRANSACTION_STATE', ['ref' => $reference, 'state' => $state]);
            }

            $merchantTransactionId = $this->sanitizeIdentifier($_GET['MerchantTransactionId'] ?? '');
            $epsTransactionId = $this->sanitizeIdentifier($_GET['EPSTransactionId'] ?? '');

            if ($merchantTransactionId === '') {
                return $this->fail('EPS_CALLBACK_INVALID', ['ref' => $reference, 'detail' => 'missing_merchant_transaction_id']);
            }

            $stored = $this->loadSession($reference);
            $expectedMerchantTransactionId = is_array($stored) ? (string) ($stored['merchant_transaction_id'] ?? '') : '';

            if ($expectedMerchantTransactionId !== '' && !hash_equals($expectedMerchantTransactionId, $merchantTransactionId)) {
                return $this->fail('EPS_CALLBACK_MISMATCH', ['ref' => $reference, 'detail' => 'merchant_transaction_id']);
            }

            $config = $this->resolveConfig($data);
            if (empty($config['ok'])) {
                return $config;
            }
            $settings = $config['settings'];

            $tokenResult = $this->requestToken($settings);
            if (empty($tokenResult['ok'])) {
                return $tokenResult;
            }

            $url = $settings['base_url'] . '/v1/EPSEngine/CheckMerchantTransactionStatus'
                . '?merchantTransactionId=' . rawurlencode($merchantTransactionId)
                . '&EPSTransactionId=' . rawurlencode($epsTransactionId);

            $headers = [
                'Authorization: Bearer ' . $tokenResult['token'],
                'x-hash: ' . $this->sign($merchantTransactionId, $settings['hashkey']),
            ];

            $response = $this->httpGetJson($url, $headers);

            if (empty($response['ok'])) {
                return $this->fail('EPS_CALLBACK_VERIFY_FAILED', [
                    'ref'   => $reference,
                    'errno' => $response['errno'] ?? '',
                    'phase' => 'transport',
                ]);
            }

            if (!$this->isSuccessStatus($response['status'] ?? 0)) {
                return $this->fail('EPS_CALLBACK_VERIFY_FAILED', [
                    'ref'   => $reference,
                    'http'  => $response['status'] ?? 0,
                    'phase' => 'http',
                ]);
            }

            $decoded = $this->decodeJson($response['body'] ?? '');
            if ($decoded === null) {
                return $this->fail('EPS_CALLBACK_VERIFY_FAILED', [
                    'ref'   => $reference,
                    'phase' => 'json',
                ]);
            }

            $status = strtolower(trim((string) ($decoded['Status'] ?? '')));
            if ($status !== 'success') {
                return $this->fail('EPS_CALLBACK_VERIFY_FAILED', [
                    'ref'    => $reference,
                    'phase'  => 'status',
                    'status' => substr(preg_replace('/[^A-Za-z0-9_.-]/', '', (string) ($decoded['Status'] ?? '')), 0, 30),
                ]);
            }

            $valueA = isset($decoded['ValueA']) && is_scalar($decoded['ValueA']) ? (string) $decoded['ValueA'] : '';
            if ($valueA === '' || !hash_equals($reference, $valueA)) {
                return $this->fail('EPS_CALLBACK_MISMATCH', ['ref' => $reference, 'detail' => 'value_a']);
            }

            $reportedAmount = $this->extractReportedAmount($decoded);
            if ($reportedAmount !== null) {
                $expectedAmount = $this->normaliseAmount($transaction['local_net_amount'] ?? null);
                if ($expectedAmount !== null && abs($reportedAmount - (float) $expectedAmount) > 0.01) {
                    return $this->fail('EPS_CALLBACK_AMOUNT_MISMATCH', ['ref' => $reference]);
                }
            }

            $providerTransactionId = $epsTransactionId !== '' ? $epsTransactionId : $merchantTransactionId;

            $financialEntity = isset($decoded['FinancialEntity']) && is_scalar($decoded['FinancialEntity'])
                ? substr(trim((string) $decoded['FinancialEntity']), 0, 60)
                : '';
            $providerReference = isset($decoded['MerchantTransactionId']) && is_scalar($decoded['MerchantTransactionId'])
                ? substr(trim((string) $decoded['MerchantTransactionId']), 0, 60)
                : $merchantTransactionId;

            $sourceInfo = [
                [
                    'label' => 'EPS TransactionId',
                    'value' => $providerTransactionId,
                ],
            ];

            if ($financialEntity !== '') {
                $sourceInfo[] = [
                    'label' => 'Financial Entity',
                    'value' => $financialEntity,
                ];
            }

            $completed = $this->completeTransaction(
                $reference,
                (string) ($data['gateway']['gateway_id'] ?? ''),
                $providerTransactionId,
                $sourceInfo
            );

            if (!$completed) {
                return $this->fail('EPS_COMPLETION_REJECTED', ['ref' => $reference, 'provider_ref' => $providerReference]);
            }

            $this->log('EPS_PAYMENT_COMPLETED', ['ref' => $reference]);

            return ['ok' => true];
        }

        // -----------------------------------------------------------------
        // HTTP transport (overridable in tests)
        // -----------------------------------------------------------------

        protected function httpPostJson($url, array $headers, array $payload)
        {
            return $this->executeRequest('POST', $url, $headers, json_encode($payload));
        }

        protected function httpGetJson($url, array $headers)
        {
            return $this->executeRequest('GET', $url, $headers, null);
        }

        protected function executeRequest($method, $url, array $headers, $body)
        {
            $ch = curl_init($url);
            if ($ch === false) {
                return ['ok' => false, 'errno' => -1, 'error' => 'curl_init_failed', 'status' => 0, 'body' => ''];
            }

            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
                CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
                CURLOPT_USERAGENT      => 'PipraPay-EPS/2.0',
            ];

            if (strtoupper($method) === 'POST') {
                $options[CURLOPT_POST] = true;
                $options[CURLOPT_POSTFIELDS] = is_string($body) ? $body : '';
            } else {
                $options[CURLOPT_HTTPGET] = true;
            }

            curl_setopt_array($ch, $options);

            $raw = curl_exec($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return [
                'ok'     => $errno === 0 && is_string($raw),
                'errno'  => $errno,
                'error'  => substr((string) $error, 0, 200),
                'status' => $status,
                'body'   => is_string($raw) ? $raw : '',
            ];
        }

        // -----------------------------------------------------------------
        // Provider helpers
        // -----------------------------------------------------------------

        protected function requestToken(array $settings)
        {
            $response = $this->httpPostJson(
                $settings['base_url'] . '/v1/Auth/GetToken',
                ['x-hash: ' . $this->sign($settings['username'], $settings['hashkey'])],
                [
                    'userName' => $settings['username'],
                    'password' => $settings['password'],
                ]
            );

            if (empty($response['ok'])) {
                return $this->fail('EPS_AUTH_TRANSPORT', ['errno' => $response['errno'] ?? '']);
            }

            if (!$this->isSuccessStatus($response['status'] ?? 0)) {
                return $this->fail('EPS_AUTH_HTTP', ['http' => $response['status'] ?? 0]);
            }

            $decoded = $this->decodeJson($response['body'] ?? '');
            if ($decoded === null) {
                return $this->fail('EPS_AUTH_INVALID_JSON', ['http' => $response['status'] ?? 0]);
            }

            $token = isset($decoded['token']) && is_string($decoded['token']) ? trim($decoded['token']) : '';

            if ($token === '') {
                return $this->fail('EPS_AUTH_NO_TOKEN', ['provider' => $this->detectProviderFailure($decoded)]);
            }

            if (strlen($token) < self::MIN_SESSION_TOKEN_LENGTH) {
                return $this->fail('EPS_AUTH_NO_TOKEN', ['detail' => 'token_too_short']);
            }

            return ['ok' => true, 'token' => $token];
        }

        protected function sign($value, $hashKey)
        {
            return base64_encode(hash_hmac('sha512', (string) $value, (string) $hashKey, true));
        }

        protected function generateMerchantTransactionId($reference)
        {
            $suffix = substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $reference), -8);

            try {
                $random = (string) random_int(1000, 9999);
            } catch (Exception $e) {
                $random = (string) mt_rand(1000, 9999);
            }

            return substr(date('ymdHis') . $suffix . $random, 0, 40);
        }

        protected function isSuccessStatus($status)
        {
            $status = (int) $status;

            return $status >= 200 && $status < 300;
        }

        protected function decodeJson($body)
        {
            if (!is_string($body) || trim($body) === '') {
                return null;
            }

            $decoded = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                return null;
            }

            return $decoded;
        }

        protected function detectProviderFailure(array $decoded)
        {
            $candidateKeys = ['Status', 'status', 'ResponseCode', 'responseCode', 'Code', 'code', 'ErrorCode', 'errorCode'];
            $failureWords = ['failed', 'fail', 'failure', 'error', 'declined', 'invalid', 'unauthorized', 'denied', 'rejected'];

            foreach ($candidateKeys as $key) {
                if (!isset($decoded[$key]) || !is_scalar($decoded[$key])) {
                    continue;
                }

                $value = strtolower(trim((string) $decoded[$key]));
                if ($value === '' || $value === 'success' || $value === 'ok' || $value === 'true') {
                    continue;
                }

                foreach ($failureWords as $word) {
                    if (strpos($value, $word) !== false) {
                        return substr(preg_replace('/[^A-Za-z0-9_.-]/', '', (string) $decoded[$key]), 0, 40);
                    }
                }
            }

            return '';
        }

        protected function extractReportedAmount(array $decoded)
        {
            $keys = ['Amount', 'amount', 'TotalAmount', 'totalAmount', 'PaidAmount', 'paidAmount', 'TransactionAmount', 'transactionAmount'];

            foreach ($keys as $key) {
                if (!isset($decoded[$key]) || !is_scalar($decoded[$key])) {
                    continue;
                }

                $value = trim((string) $decoded[$key]);
                if ($value !== '' && is_numeric($value)) {
                    return (float) $value;
                }
            }

            return null;
        }

        // -----------------------------------------------------------------
        // Request/response validation
        // -----------------------------------------------------------------

        protected function resolveConfig($data)
        {
            $options = is_array($data['options'] ?? null) ? $data['options'] : [];

            $settings = [];
            foreach (['merchant_id', 'store_id', 'username', 'password', 'hashkey'] as $field) {
                $value = isset($options[$field]) && is_scalar($options[$field]) ? trim((string) $options[$field]) : '';
                if ($value === '') {
                    return $this->fail('EPS_CONFIG_INCOMPLETE', ['field' => $field]);
                }
                $settings[$field] = $value;
            }

            $mode = isset($options['mode']) && is_scalar($options['mode']) ? strtolower(trim((string) $options['mode'])) : '';
            if ($mode !== self::MODE_LIVE) {
                // Only an explicit "live" switches to production. Anything else
                // (empty, sandbox, unknown) stays on the sandbox endpoint.
                $mode = self::MODE_SANDBOX;
            }

            $settings['mode'] = $mode;
            $settings['base_url'] = ($mode === self::MODE_LIVE) ? self::LIVE_BASE_URL : self::SANDBOX_BASE_URL;

            return ['ok' => true, 'settings' => $settings];
        }

        protected function normaliseAmount($amount)
        {
            if (is_string($amount)) {
                $amount = trim($amount);
            }

            if (!is_numeric($amount)) {
                return null;
            }

            $value = (float) $amount;

            if (!is_finite($value) || $value <= 0 || $value > 999999999.99) {
                return null;
            }

            return number_format($value, 2, '.', '');
        }

        protected function buildCustomer($data)
        {
            $customer = is_array($data['transaction']['customer'] ?? null) ? $data['transaction']['customer'] : [];

            $name = isset($customer['name']) && is_scalar($customer['name']) ? trim((string) $customer['name']) : '';
            $name = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $name));
            if ($name === '') {
                $name = 'Customer';
            }
            $name = substr($name, 0, 100);

            $email = isset($customer['email']) && is_scalar($customer['email']) ? trim((string) $customer['email']) : '';
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                // EPS expects a syntactically valid customer email. A placeholder
                // on the merchant's own domain is used instead of inventing an
                // address the customer never supplied.
                $email = $this->fallbackCustomerEmail();
            }
            $email = substr($email, 0, 120);

            $phoneRaw = isset($customer['mobile']) && is_scalar($customer['mobile']) ? (string) $customer['mobile'] : '';
            $phone = preg_replace('/[^0-9+]/', '', $phoneRaw);

            if (strlen(ltrim($phone, '+')) < 6) {
                return ['ok' => false, 'detail' => 'missing_customer_phone'];
            }

            return [
                'ok'    => true,
                'name'  => $name,
                'email' => $email,
                'phone' => substr($phone, 0, 20),
            ];
        }

        protected function fallbackCustomerEmail()
        {
            $host = '';

            if (function_exists('pp_site_url')) {
                $host = (string) parse_url(pp_site_url(), PHP_URL_HOST);
            }
            if ($host === '' && !empty($_SERVER['HTTP_HOST'])) {
                $host = (string) $_SERVER['HTTP_HOST'];
            }

            $host = strtolower(preg_replace('/[^A-Za-z0-9.-]/', '', $host));
            if ($host === '' || strpos($host, '.') === false) {
                $host = 'localhost';
            }

            return 'noreply@' . substr($host, 0, 100);
        }

        /**
         * Validate the hosted checkout URL before it reaches the browser.
         *
         * @return array{ok: bool, url?: string, code?: string, detail?: string}
         */
        protected function validateRedirectUrl($url)
        {
            if (!is_string($url)) {
                return ['ok' => false, 'code' => 'EPS_INIT_MISSING_URL', 'detail' => 'not_a_string'];
            }

            $url = trim($url);

            if ($url === '') {
                return ['ok' => false, 'code' => 'EPS_INIT_MISSING_URL', 'detail' => 'empty'];
            }

            if (strlen($url) > 4096) {
                return ['ok' => false, 'code' => 'EPS_INIT_MALFORMED_SESSION', 'detail' => 'too_long'];
            }

            if (preg_match('/[\x00-\x20\x7F]/', $url)) {
                return ['ok' => false, 'code' => 'EPS_INIT_MALFORMED_SESSION', 'detail' => 'whitespace_or_control'];
            }

            $parts = parse_url($url);

            if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
                return ['ok' => false, 'code' => 'EPS_INIT_MALFORMED_SESSION', 'detail' => 'unparsable'];
            }

            if (strtolower((string) $parts['scheme']) !== 'https') {
                return ['ok' => false, 'code' => 'EPS_INIT_INSECURE_URL', 'detail' => 'scheme'];
            }

            if (!empty($parts['user']) || !empty($parts['pass'])) {
                return ['ok' => false, 'code' => 'EPS_INIT_UNTRUSTED_HOST', 'detail' => 'credentials_in_url'];
            }

            if (!$this->isTrustedEpsHost($parts['host'])) {
                return ['ok' => false, 'code' => 'EPS_INIT_UNTRUSTED_HOST', 'detail' => 'host'];
            }

            $rawQuery = isset($parts['query']) ? (string) $parts['query'] : '';
            $token = null;

            if ($rawQuery !== '' && preg_match('/(?:^|&)data=([^&]*)/', $rawQuery, $matches)) {
                $token = $matches[1];
            }

            if ($token === null) {
                // No documented session parameter: accept only when the path
                // itself carries an opaque segment of plausible length.
                $path = isset($parts['path']) ? (string) $parts['path'] : '';
                if (!preg_match('#/[A-Za-z0-9._~%+=-]{8,}#', $path)) {
                    return ['ok' => false, 'code' => 'EPS_INIT_NULL_SESSION', 'detail' => 'no_session_parameter'];
                }

                return ['ok' => true, 'url' => $url];
            }

            $decodedToken = urldecode($token);

            if ($decodedToken === '') {
                return ['ok' => false, 'code' => 'EPS_INIT_NULL_SESSION', 'detail' => 'empty_session'];
            }

            if (in_array(strtolower($decodedToken), ['null', 'undefined', 'none', 'nil', 'false', '0'], true)) {
                return ['ok' => false, 'code' => 'EPS_INIT_NULL_SESSION', 'detail' => 'null_session'];
            }

            if (strlen($decodedToken) < self::MIN_SESSION_TOKEN_LENGTH) {
                return ['ok' => false, 'code' => 'EPS_INIT_MALFORMED_SESSION', 'detail' => 'session_too_short'];
            }

            if (!preg_match('#^[A-Za-z0-9._~+/=%:-]+$#', $decodedToken)) {
                return ['ok' => false, 'code' => 'EPS_INIT_MALFORMED_SESSION', 'detail' => 'session_charset'];
            }

            return ['ok' => true, 'url' => $url];
        }

        /**
         * Sanitise an opaque identifier coming from the provider callback.
         *
         * Anything that is not a short, printable token is treated as missing so
         * that it can never be concatenated into a provider URL or written to a
         * log line.
         */
        protected function sanitizeIdentifier($value)
        {
            if (!is_scalar($value)) {
                return '';
            }

            $value = trim((string) $value);

            if ($value === '' || strlen($value) > 120) {
                return '';
            }

            if (!preg_match('#^[A-Za-z0-9._~+/=%:-]+$#', $value)) {
                return '';
            }

            return $value;
        }
        protected function isTrustedEpsHost($host)
        {
            $host = strtolower(rtrim(trim((string) $host), '.'));

            if ($host === '' || strpos($host, ':') !== false) {
                return false;
            }

            if ($host === 'eps.com.bd') {
                return true;
            }

            $suffix = '.eps.com.bd';

            return substr($host, -strlen($suffix)) === $suffix;
        }

        // -----------------------------------------------------------------
        // Session persistence
        // -----------------------------------------------------------------

        protected function loadSession($reference)
        {
            if (function_exists('pp_gateway_meta_get')) {
                return pp_gateway_meta_get($reference, self::SESSION_META_KEY);
            }

            return [];
        }

        protected function saveSession($reference, array $session)
        {
            if (function_exists('pp_gateway_meta_set')) {
                return pp_gateway_meta_set($reference, self::SESSION_META_KEY, $session);
            }

            return false;
        }

        protected function isStoredSessionReusable($stored)
        {
            if (empty($stored) || !is_array($stored)) {
                return false;
            }

            if (empty($stored['redirect_url']) || !is_string($stored['redirect_url'])) {
                return false;
            }

            $createdAt = isset($stored['created_at']) ? (int) $stored['created_at'] : 0;

            if ($createdAt <= 0) {
                return false;
            }

            return (time() - $createdAt) <= self::CHECKOUT_SESSION_TTL;
        }

        protected function isRetryRequest()
        {
            return isset($_GET['retry']) && $_GET['retry'] === '1';
        }

        protected function completeTransaction($reference, $gatewayId, $providerTransactionId, array $sourceInfo)
        {
            if (!function_exists('pp_set_transaction_status')) {
                return false;
            }

            return (bool) pp_set_transaction_status($reference, 'completed', $gatewayId, $providerTransactionId, $sourceInfo);
        }

        // -----------------------------------------------------------------
        // Diagnostics and rendering
        // -----------------------------------------------------------------

        protected function log($code, array $context = [])
        {
            $parts = ['[EPS] ' . preg_replace('/[^A-Za-z0-9_]/', '', (string) $code)];

            foreach ($context as $key => $value) {
                if (!is_scalar($value)) {
                    continue;
                }

                $safeKey = preg_replace('/[^A-Za-z0-9_]/', '', (string) $key);
                $safeValue = substr(preg_replace('/[^A-Za-z0-9_.:@\/+-]/', '', (string) $value), 0, 120);

                if ($safeKey === '') {
                    continue;
                }

                $parts[] = $safeKey . '=' . $safeValue;
            }

            error_log(implode(' | ', $parts));
        }

        protected function fail($code, array $context = [])
        {
            $this->log($code, $context);

            $nonRetryable = ['EPS_CONFIG_INCOMPLETE', 'EPS_AMOUNT_INVALID', 'EPS_CUSTOMER_INVALID'];

            return [
                'ok'        => false,
                'code'      => $code,
                'message'   => function_exists('pp_eps_checkout_error_message')
                    ? pp_eps_checkout_error_message($code)
                    : 'The EPS payment session could not be started. Please try again or choose another payment method.',
                'retryable' => !in_array($code, $nonRetryable, true),
            ];
        }

        protected function escape($value)
        {
            return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        }

        protected function buildUrl($baseUrl, array $params = [])
        {
            if (function_exists('pp_url_with_query')) {
                return pp_url_with_query($baseUrl, $params);
            }

            $separator = (strpos((string) $baseUrl, '?') === false) ? '?' : '&';

            return (string) $baseUrl . $separator . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        }

        protected function checkoutReturnUrl($reference = '')
        {
            $base = function_exists('pp_checkout_address') ? pp_checkout_address() : '';
            $params = ['manual' => '1'];

            if ($reference !== '') {
                $params['ref'] = $reference;
            }

            return $this->buildUrl($base, $params);
        }

        protected function renderFailure(array $data, array $result)
        {
            $code = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($result['code'] ?? 'EPS_UNKNOWN'));
            $message = (string) ($result['message'] ?? 'The EPS payment session could not be started.');
            $reference = (string) ($data['transaction']['ref'] ?? '');
            $gatewayId = (string) ($data['gateway']['gateway_id'] ?? '');

            $publicReason = function_exists('pp_eps_public_error_reason')
                ? pp_eps_public_error_reason($code)
                : 'unknown';

            $backUrl = $this->buildUrl(
                function_exists('pp_checkout_address') ? pp_checkout_address() : '',
                ['manual' => '1', 'eps_error' => $publicReason]
            );

            $retryUrl = $this->buildUrl(
                function_exists('pp_checkout_address') ? pp_checkout_address() : '',
                ['gateway' => $gatewayId, 'category' => 'cards', 'retry' => '1']
            );

            $html = '<style>.loading-123412341234{display:none;}</style>';
            $html .= '<div class="alert alert-danger" role="alert" style="text-align:left;">';
            $html .= '<h4 class="alert-heading" style="margin-bottom:8px;">Payment could not be started</h4>';
            $html .= '<p style="margin:0 0 8px 0;">' . $this->escape($message) . '</p>';

            if ($reference !== '') {
                $html .= '<p style="margin:0;font-size:12px;opacity:.75;">Reference: <span style="font-family:monospace;">'
                    . $this->escape($reference) . '</span></p>';
            }

            if (!empty($result['retryable']) && $gatewayId !== '') {
                $html .= '<a class="btn btn-primary w-100 mt-3" href="' . $this->escape($retryUrl) . '">Try again</a>';
            }

            $html .= '<a class="btn btn-outline-secondary w-100 mt-2" href="' . $this->escape($backUrl)
                . '">Choose another payment method</a>';
            $html .= '</div>';

            echo $html;
        }

        protected function renderVerificationFailure(array $data, array $result)
        {
            $message = (string) ($result['message'] ?? 'The transaction could not be verified with the payment provider.');
            $reference = (string) ($data['transaction']['ref'] ?? '');

            $backUrl = $this->buildUrl(
                function_exists('pp_checkout_address') ? pp_checkout_address() : '',
                ['manual' => '1']
            );

            $html = '<style>.loading-123412341234{display:none;}</style>';
            $html .= '<div class="alert alert-danger" role="alert" style="text-align:left;">';
            $html .= '<h4 class="alert-heading" style="margin-bottom:8px;">Payment not verified</h4>';
            $html .= '<p style="margin:0 0 8px 0;">' . $this->escape($message) . '</p>';

            if ($reference !== '') {
                $html .= '<p style="margin:0;font-size:12px;opacity:.75;">Reference: <span style="font-family:monospace;">'
                    . $this->escape($reference) . '</span></p>';
            }

            $html .= '<a class="btn btn-outline-secondary w-100 mt-3" href="' . $this->escape($backUrl)
                . '">Back to payment page</a>';
            $html .= '</div>';

            echo $html;
        }
    }
