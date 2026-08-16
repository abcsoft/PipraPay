<?php
    class PaystationGateway
    {
        public function info()
        {
            return [
                'title'       => 'PayStation Gateway',
                'logo'        => 'assets/logo.jpg',
                'currency'        => 'BDT',
                'tab'        => 'mfs',

                'gateway_type'        => 'api',
            ];
        }

        public function color()
        {
            return [
                'primary_color'        => '#351e53',
                'text_color'        => '#FFFFFF',
                'btn_color'        => '#351e53',
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
                    'name'  => 'merchant_password',
                    'label' => 'Merchant Password',
                    'type'  => 'text',
                ],
                [
                    'name'  => 'checkout_items',
                    'label' => 'Checkout items',
                    'type'  => 'text',
                ],
                [
                    'name'  => 'pay_with_charge',
                    'label' => 'Who pay fees?',
                    'type'  => 'select',
                    'options' => [
                        '0'  => 'Customer',
                        '1' => 'Merchant',
                    ],
                    'value' => '0',
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

            $base_url = (($data['options']['mode'] ?? 'sandbox') === 'live') ? 'https://api.paystation.com.bd' : 'https://sandbox.paystation.com.bd';

            $ref = $data['transaction']['ref'] ?? '';
            $brand_id = $data['transaction']['brand_id'] ?? ($data['brand']['brand_id'] ?? 'both');

            $invoiceNumber = (string) random_int(100000000, 999999999);
            if (!empty($ref)) {
                set_env('paystation-invoice-' . $ref, $invoiceNumber, $brand_id);
            }

            $curl = curl_init();
            
            $postFields = array(
                'invoice_number' => $invoiceNumber,
                'currency' => 'BDT',
                'payment_amount' => $data['transaction']['local_net_amount'],
                'reference' => $ref,
                'cust_name' => $data['transaction']['customer']['name'] ?? '',
                'cust_phone' => $data['transaction']['customer']['mobile'] ?? '',
                'cust_email' => $data['transaction']['customer']['email'] ?? '',
                'cust_address' => "Bangladesh",
                'pay_with_charge' => ($data['options']['pay_with_charge'] ?? '0'),
                'callback_url' => pp_callback_url(),
            
                'checkout_items' => ($data['options']['checkout_items'] ?? ''),
            
                'merchantId' => ($data['options']['merchant_id'] ?? ''),
                'password' => ($data['options']['merchant_password'] ?? '')
            );
            
            curl_setopt_array($curl, array(
                CURLOPT_URL => $base_url."/initiate-payment",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => $postFields,
            ));
            
            $response = curl_exec($curl);
            curl_close($curl);
            
            $response_curl = json_decode($response, true);

            if(isset($response_curl['payment_url'])){
               echo '<script>location.href="' . $response_curl['payment_url'] . '";</script>';
            }else{
                echo '<div class="alert alert-danger" role="alert">'.htmlspecialchars((string)$response, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</div> <style>.loading-123412341234{display: none;}</style>';
            }
        }

        function callback($data = []){
            echo '<center><div class="spinner-border text-primary m-3 loading-123412341234" role="status"><span class="visually-hidden">Loading...</span></div></center>';

            $base_url = (($data['options']['mode'] ?? 'sandbox') === 'live') ? 'https://api.paystation.com.bd' : 'https://sandbox.paystation.com.bd';

            $ref = $data['transaction']['ref'] ?? '';
            $brand_id = $data['transaction']['brand_id'] ?? ($data['brand']['brand_id'] ?? 'both');

            $txStatus = $data['transaction']['status'] ?? '';
            if ($txStatus === 'completed') {
                echo "<script>location.reload();</script>";
                return;
            }
            if ($txStatus !== 'initiated') {
                echo '<div class="alert alert-danger" role="alert">Transaction cannot be processed in current state.</div><style>.loading-123412341234{display: none;}</style>';
                return;
            }

            $status = $_GET['status'] ?? ($_POST['status'] ?? '');

            if($status == "Canceled"){
                echo '<div class="alert alert-danger" role="alert">Transaction Canceled.</div><style>.loading-123412341234{display: none;}</style>';
            }else{
                $expectedInvoice = get_env('paystation-invoice-' . $ref, $brand_id);
                $callbackInvoice = (string)($_GET['invoice_number'] ?? ($_POST['invoice_number'] ?? ''));

                if (empty($expectedInvoice) || empty($callbackInvoice) || !hash_equals((string)$expectedInvoice, $callbackInvoice)) {
                    echo '<div class="alert alert-danger" role="alert">Invalid or mismatched invoice number.</div><style>.loading-123412341234{display: none;}</style>';
                    return;
                }

                $header = array('merchantId:' . ($data['options']['merchant_id'] ?? ''));
                $body = array('invoice_number' => $expectedInvoice);

                $url = curl_init($base_url.'/transaction-status');
                curl_setopt($url, CURLOPT_HTTPHEADER, $header);
                curl_setopt($url, CURLOPT_CUSTOMREQUEST, "POST");
                curl_setopt($url, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($url, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($url, CURLOPT_SSL_VERIFYHOST, 2);
                curl_setopt($url, CURLOPT_POSTFIELDS, $body);
                curl_setopt($url, CURLOPT_FOLLOWLOCATION, false);
                curl_setopt($url, CURLOPT_TIMEOUT, 30);
                $responseData = curl_exec($url);
                curl_close($url);
                
                $decode_response = json_decode($responseData, true);
                
                if(isset($decode_response['status_code'], $decode_response['status']) && $decode_response['status_code'] == "200" && $decode_response['status'] == "success"){
                    $verified_order_id = (string)($decode_response['data']['invoice_number'] ?? '');

                    if (empty($verified_order_id) || !hash_equals((string)$expectedInvoice, $verified_order_id)) {
                        echo '<div class="alert alert-danger" role="alert">PayStation verified invoice mismatch.</div><style>.loading-123412341234{display: none;}</style>';
                        return;
                    }

                    $trx_status = $decode_response['data']['trx_status'] ?? '';
                    if($trx_status == "successful" || $trx_status == "Success"){
                        $respAmount = $decode_response['data']['payment_amount'] ?? ($decode_response['data']['amount'] ?? null);
                        if ($respAmount !== null && isset($data['transaction']['local_net_amount'])) {
                            if (money_sanitize($respAmount) !== money_sanitize($data['transaction']['local_net_amount'])) {
                                echo '<div class="alert alert-danger" role="alert">Payment amount mismatch.</div><style>.loading-123412341234{display: none;}</style>';
                                return;
                            }
                        }

                        $respCurrency = $decode_response['data']['currency'] ?? ($decode_response['data']['payment_currency'] ?? null);
                        if ($respCurrency !== null && !empty($respCurrency) && isset($data['transaction']['local_currency'])) {
                            if (strtoupper((string)$respCurrency) !== strtoupper((string)$data['transaction']['local_currency'])) {
                                echo '<div class="alert alert-danger" role="alert">Payment currency mismatch.</div><style>.loading-123412341234{display: none;}</style>';
                                return;
                            }
                        }

                        $verified_trx_id = (string)($decode_response['data']['trx_id'] ?? '');
                        if (empty($verified_trx_id)) {
                            echo '<div class="alert alert-danger" role="alert">Invalid transaction ID from PayStation.</div><style>.loading-123412341234{display: none;}</style>';
                            return;
                        }

                        $payer_mobile_no = $decode_response['data']['payer_mobile_no'] ?? '--';
                        $payment_method = $decode_response['data']['payment_method'] ?? '--';
                        
                        $moreinfo = [
                            [
                                'label' => 'Invoice Number',
                                'value' => $verified_order_id
                            ],
                            [
                                'label' => 'Payer Mobile Number',
                                'value' => $payer_mobile_no
                            ],
                            [
                                'label' => 'Financial Entity',
                                'value' => $payment_method
                            ]
                        ];

                        pp_set_transaction_status($ref, 'completed', $data['gateway']['gateway_id'], $verified_trx_id, $moreinfo);

                        echo "<script>location.reload();</script>";
                    }else{
                        echo '<div class="alert alert-danger" role="alert">'.htmlspecialchars((string)$responseData, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</div><style>.loading-123412341234{display: none;}</style>';
                    }
                }else{
                    echo '<div class="alert alert-danger" role="alert">'.htmlspecialchars((string)$responseData, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</div><style>.loading-123412341234{display: none;}</style>';
                }
            }
        }
    }
