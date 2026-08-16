<?php
    class OxapayGateway
    {
        public function info()
        {
            return [
                'title'       => 'OxaPay Gateway',
                'logo'        => 'assets/logo.jpg',
                'currency'        => 'USD',
                'tab'        => 'global',

                'gateway_type'        => 'api',
            ];
        }

        public function color()
        {
            return [
                'primary_color'        => '#1a34c2',
                'text_color'        => '#FFFFFF',
                'btn_color'        => '#1a34c2',
                'btn_text_color'        => '#FFFFFF',
            ];
        }

        public function fields()
        {
            return [
                [
                    'name'  => 'api_key',
                    'label' => 'Merchant Api Key',
                    'type'  => 'text',
                ],
                [
                    'name'  => 'fee_paid_by_payer',
                    'label' => 'Fee Paid By',
                    'type'  => 'select',
                    'options' => [
                        '0'  => 'Merchant',
                        '1' => 'Payer',
                    ],
                    'value' => '1',
                    'required' => true,
                    'multiple' => false,
                ],
                [
                    'name'  => 'under_paid_coverage',
                    'label' => 'Under Paid Coverage',
                    'type'  => 'text',
                    'value' => '0',
                ],
                [
                    'name'  => 'mixed_payment',
                    'label' => 'Mixed Payment',
                    'type'  => 'select',
                    'options' => [
                        'allow'  => 'Allow',
                        'disallow' => 'Disallow',
                    ],
                    'value' => 'disallow',
                    'required' => true,
                    'multiple' => false,
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

        function process_payment($data = []){
            echo '<center><div class="spinner-border text-primary m-3 loading-123412341234" role="status"><span class="visually-hidden">Loading...</span></div></center>';

            $url = "https://api.oxapay.com/v1/payment/invoice";

            $datas = [
                "amount" => $data['transaction']['local_net_amount'],
                "currency" => $data['transaction']['local_currency'],
                "lifetime" => 30,
                "fee_paid_by_payer" => ($data['options']['fee_paid_by_payer'] ?? '0'),
                "under_paid_coverage" => ($data['options']['under_paid_coverage'] ?? '0'),
                "to_currency" => "USDT",
                "auto_withdrawal" => false,
                "mixed_payment" => (($data['options']['mixed_payment'] ?? 'disallow') === 'allow') ? true : false,
                "callback_url" => pp_ipn_url($data['gateway']['gateway_id']),
                "return_url" => pp_callback_url(),
                "email" => $data['transaction']['customer']['email'],
                "order_id" => rand().'-BP-'.$data['transaction']['ref'],
                "sandbox" => (($data['options']['mode'] ?? 'sandbox') === 'live') ? false : true
            ];

            $ch = curl_init($url);

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($datas),
                CURLOPT_HTTPHEADER => [
                    "merchant_api_key: ".($data['options']['api_key'] ?? ''),
                    "Content-Type: application/json"
                ],
            ]);

            $response = curl_exec($ch);

            curl_close($ch);

            $response_de = json_decode($response, true);

            if(isset($response_de['data']['payment_url'])){
                set_env('oxapay-gateway-pp_'.$data['transaction']['ref'], $response_de['data']['track_id']);

                echo '<script>location.href="' . $response_de['data']['payment_url'] . '";</script>';
            }else{
                echo '<div class="alert alert-danger" role="alert">'.$response.'</div> <style>.loading-123412341234{display: none;}</style>';
            }
        }

        function callback($data = []){
            echo '<center><div class="spinner-border text-primary m-3 loading-123412341234" role="status"><span class="visually-hidden">Loading...</span></div></center>';

            $transaction_id = (get_env('oxapay-gateway-pp_'.$data['transaction']['ref']) == "") ? 0 : get_env('oxapay-gateway-pp_'.$data['transaction']['ref']);

            $url = "https://api.oxapay.com/v1/payment/" . $transaction_id;

            $ch = curl_init($url);

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => "GET",
                CURLOPT_HTTPHEADER     => [
                    "merchant_api_key: ".($data['options']['api_key'] ?? ''),
                    "Content-Type: application/json"
                ],
            ]);

            $response = curl_exec($ch);

            curl_close($ch);

            $response_de = json_decode($response, true);
            
            $status = $response_de['data']['status'] ?? '';

            if($status == "paid"){
                $parts = explode('-BP-', $response_de['data']['order_id']);
                $order_id = $parts[1] ?? '';

                $track_id = $response_de['data']['track_id'];

                if($data['transaction']['local_net_amount'] == $response_de['data']['amount']){
                    if($order_id == $data['transaction']['ref']){
                        pp_set_transaction_status($data['transaction']['ref'], 'completed', $data['gateway']['gateway_id'], $track_id);

                        echo "<script>location.reload();</script>";
                    }else{
                        echo "<center>Payment not completed or failed!</center> <style>.loading-123412341234{display: none;}</style>";
                    }
                }else{
                    echo '<div class="alert alert-danger" role="alert">Expected amount and paid amount do not match.</div> <style>.loading-123412341234{display: none;}</style>';
                }
            }else{
                echo '<div class="alert alert-danger" role="alert">Payment not completed or failed!</div> <style>.loading-123412341234{display: none;}</style>';
            }
        }

        function ipn($data = []){
            $context = $data;

            $postData = file_get_contents('php://input');
            $payload = json_decode($postData, true);

            if (!is_array($payload)) {
                http_response_code(400);
                echo 'Invalid payload';
                exit;
            }

            $apiSecretKey = $context['options']['api_key'] ?? ($context['gateway']['options']['api_key'] ?? '');
            if (empty($apiSecretKey) || !is_string($apiSecretKey)) {
                http_response_code(400);
                echo 'Merchant API key not configured';
                exit;
            }

            $receivedHmac = $_SERVER['HTTP_HMAC'] ?? ($_SERVER['HTTP_X_HMAC_SIGNATURE'] ?? '');
            if (empty($receivedHmac)) {
                http_response_code(400);
                echo 'No HMAC signature provided';
                exit;
            }

            $calculatedHmac = hash_hmac('sha512', $postData, $apiSecretKey);
            if (!hash_equals(strtolower($calculatedHmac), strtolower($receivedHmac))) {
                http_response_code(400);
                echo 'Invalid HMAC signature';
                exit;
            }

            $data_type = $payload['type'] ?? '';
            if ($data_type !== 'invoice') {
                http_response_code(400);
                echo 'Invalid data.type';
                exit;
            }

            $track_id = trim((string)($payload['track_id'] ?? ($payload['trackId'] ?? '')));
            if (empty($track_id)) {
                http_response_code(400);
                echo 'Invalid track_id';
                exit;
            }

            $url = "https://api.oxapay.com/v1/payment/" . urlencode($track_id);

            $ch = curl_init($url);

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => "GET",
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER     => [
                    "merchant_api_key: " . $apiSecretKey,
                    "Content-Type: application/json"
                ],
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            curl_close($ch);

            if (empty($response) || $httpCode >= 400) {
                http_response_code(400);
                echo 'Failed to verify payment with OxaPay';
                exit;
            }

            $response_de = json_decode($response, true);
            if (!is_array($response_de) || ($response_de['result'] ?? 0) != 100) {
                http_response_code(400);
                echo 'OxaPay verification failed';
                exit;
            }

            $verified_status = strtolower(trim((string)($response_de['data']['status'] ?? '')));
            if ($verified_status !== 'paid') {
                http_response_code(400);
                echo 'Payment not completed or failed';
                exit;
            }

            $verified_track_id = (string)($response_de['data']['track_id'] ?? '');
            if ($verified_track_id !== '' && $verified_track_id !== $track_id) {
                http_response_code(400);
                echo 'Track ID mismatch';
                exit;
            }

            $order_id_raw = (string)($response_de['data']['order_id'] ?? '');
            $parts = explode('-BP-', $order_id_raw);
            $order_id = end($parts);

            if (empty($order_id)) {
                http_response_code(400);
                echo 'Invalid order_id in payment details';
                exit;
            }

            global $db_prefix;
            $params = [':ref' => $order_id];
            $txResponse = json_decode(getData($db_prefix . 'transaction', 'WHERE ref = :ref', '* FROM', $params), true);

            if (!is_array($txResponse) || !($txResponse['status'] ?? false) || empty($txResponse['response'][0])) {
                http_response_code(400);
                echo 'Unknown local order';
                exit;
            }

            $localTx = $txResponse['response'][0];

            $trusted_gateway_id = $context['gateway']['gateway_id'] ?? ($context['gateway_id'] ?? '');

            if (!empty($trusted_gateway_id) && !empty($localTx['gateway_id']) && $localTx['gateway_id'] != $trusted_gateway_id) {
                http_response_code(400);
                echo 'Transaction gateway mismatch';
                exit;
            }

            if (!empty($context['brand_id']) && !empty($localTx['brand_id']) && $localTx['brand_id'] != $context['brand_id']) {
                http_response_code(400);
                echo 'Transaction brand mismatch';
                exit;
            }

            $expectedAmount = money_sanitize($localTx['amount'] ?? ($localTx['local_net_amount'] ?? '0'));
            $paidAmount     = money_sanitize($response_de['data']['amount'] ?? '0');

            if (bccomp($paidAmount, $expectedAmount, 8) < 0) {
                http_response_code(400);
                echo 'Paid amount is less than expected amount';
                exit;
            }

            if (!empty($response_de['data']['currency'])) {
                $paidCurrency = strtoupper(trim((string)$response_de['data']['currency']));
                $expectedCurrency = strtoupper(trim((string)($localTx['currency'] ?? ($localTx['local_currency'] ?? ''))));
                if (!empty($expectedCurrency) && $paidCurrency !== $expectedCurrency) {
                    http_response_code(400);
                    echo 'Currency mismatch';
                    exit;
                }
            }

            $statusUpdated = pp_set_transaction_status($order_id, 'completed', $trusted_gateway_id, $track_id);
            if ($statusUpdated === false) {
                http_response_code(500);
                echo 'Failed to update transaction status';
                exit;
            }

            http_response_code(200);
            echo 'OK';
        }
    }
