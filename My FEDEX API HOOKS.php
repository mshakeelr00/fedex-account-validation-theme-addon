<?php
//pws task

// Enqueue FedEx validation script
function enqueue_fedex_validator_script() {
    wp_enqueue_script(
        'fedex-validation',
        get_stylesheet_directory_uri() . '/js/fedex-validation.js',
        [],
        null,
        true
    );

    wp_localize_script('fedex-validation', 'fedexValidation', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('fedex_validate_account'),
    ]);
}
add_action('wp_enqueue_scripts', 'enqueue_fedex_validator_script');

// AJAX handlers
add_action('wp_ajax_validate_fedex_account', 'ajax_validate_fedex_account');
add_action('wp_ajax_nopriv_validate_fedex_account', 'ajax_validate_fedex_account');

function ajax_validate_fedex_account() {
    // Nonce check
    check_ajax_referer('fedex_validate_account', 'nonce');

    $account        = sanitize_text_field($_POST['account_number'] ?? '');
    $customer_name  = sanitize_text_field($_POST['customer_name'] ?? '');
    $customer_email = sanitize_email($_POST['customer_email'] ?? '');
    $customer_phone = sanitize_text_field($_POST['customer_phone'] ?? '');
    $customer_address  = sanitize_text_field($_POST['customer_address'] ?? '');
    $customer_city  = sanitize_text_field($_POST['customer_city'] ?? '');
    $customer_zip   = sanitize_text_field($_POST['customer_zip'] ?? '');
    $customer_state = sanitize_text_field($_POST['customer_state'] ?? '');

    if (empty($account)) {
        wp_send_json_error([
            'valid' => false,
            'error' => 'Account number is required',
        ], 400);
    }

    $result = validate_fedex_account_rest($account);

    // Normalize responses into success/error for cleaner JS handling
    if (!empty($result['valid'])) {
        wp_send_json_success([
            'valid'          => true,
            'message'        => $result['message'] ?? 'Account validated successfully',
            'account_number' => $account,
            'customer'       => [
                'name'  => $customer_name,
                'email' => $customer_email,
                'phone' => $customer_phone,
                'address'  => $customer_address,
                'city'  => $customer_city,
                'zip'   => $customer_zip,
                'state' => $customer_state,
            ],
            'raw'            => $result,
        ], 200);
    } else {
        wp_send_json_error([
            'valid'         => false,
            'error'         => $result['error'] ?? 'Unable to validate account',
            'account_error' => $result['account_error'] ?? false,
            'response_code' => $result['response_code'] ?? null,
            'raw'           => $result,
        ], 200);
    }
}

// Core FedEx REST API validation function
function validate_fedex_account_rest($account_number) {
    // TODO: store securely (e.g. in wp-config or options)
    $api_key    = 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'; // enter your keys
    $api_secret = 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';

    // Step 1: Get OAuth Token
    $auth_response = wp_remote_post('https://apis.fedex.com/oauth/token', [
        'headers' => [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ],
        'body'    => [
            'grant_type'    => 'client_credentials',
            'client_id'     => $api_key,
            'client_secret' => $api_secret,
        ],
        'timeout' => 20,
    ]);

    if (is_wp_error($auth_response)) {
        return ['valid' => false, 'error' => 'Auth failed: ' . $auth_response->get_error_message()];
    }

    $auth_data    = json_decode(wp_remote_retrieve_body($auth_response), true);
    $access_token = $auth_data['access_token'] ?? null;

    if (!$access_token) {
        return ['valid' => false, 'error' => 'Token missing'];
    }

    // Step 2: Try a shipment create to validate account (as third-party payer)
    $body = [
        'accountNumber'          => ['value' => '200356075'], // your shipper account (static)
        'labelResponseOptions'   => 'URL_ONLY',
        'requestedShipment'      => [
            'shipper' => [
                'contact' => [
                    'personName'  => 'Companay name',
                    'phoneNumber' => 'Company phone',
                ],
                'address' => [
                    'streetLines'         => ['company address'],
                    'city'                => 'company city',
                    'stateOrProvinceCode' => 'compnay state',
                    'postalCode'          => 'company zip',
                    'countryCode'         => 'US',
                ],
            ],
            'recipients' => [[
                'contact' => [
                    'personName'  => $customer['name'] ?? 'vincent sottosanti',
                    'phoneNumber' => $customer['phone'] ?? '2547189275',
                ],
                'address' => [
                    'streetLines'         => $customer['address'] ?? ['ZB SOUTH TEXAS 2820 west ave'],
                    'city'                => $customer['city'] ?? 'TEMPLE',
                    'stateOrProvinceCode' => $customer['state'] ?? 'TX',
                    'postalCode'          => $customer['zip'] ?? '77459',
                    'countryCode'         => 'US',
                ],
            ]],
            'serviceType'               => 'STANDARD_OVERNIGHT',
            'packagingType'             => 'YOUR_PACKAGING',
            'pickupType'                => 'DROPOFF_AT_FEDEX_LOCATION',
            'shippingChargesPayment'    => [
                'paymentType' => 'THIRD_PARTY',
                'payor'       => [
                    'responsibleParty' => [
                        'accountNumber' => ['value' => $account_number],
                    ],
                    'address'          => [
                        'streetLines'         => ['company address'],
                        'city'                => 'company city',
                        'stateOrProvinceCode' => 'compnay state',
                        'postalCode'          => 'company zip',
                        'countryCode'         => 'US',
                    ],
                ],
            ],
            'labelSpecification'        => [
                'imageType'      => 'PDF',
                'labelStockType' => 'PAPER_85X11_TOP_HALF_LABEL',
            ],
            'requestedPackageLineItems' => [[
                'weight' => ['units' => 'LB', 'value' => 20],
            ]],
        ],
    ];

    $rate_response = wp_remote_post('https://apis.fedex.com/ship/v1/shipments', [
        'headers' => [
            'Authorization' => 'Bearer ' . $access_token,
            'Content-Type'  => 'application/json',
            'X-locale'      => 'en_US',
        ],
        'body'    => wp_json_encode($body),
        'timeout' => 30,
    ]);

    if (is_wp_error($rate_response)) {
        return ['valid' => false, 'error' => 'Request failed: ' . $rate_response->get_error_message()];
    }

    $response_code = wp_remote_retrieve_response_code($rate_response);
    $rate_body     = json_decode(wp_remote_retrieve_body($rate_response), true);

    if ($response_code === 200 && !empty($rate_body['output'])) {
        return [
            'valid'          => true,
            'message'        => 'Account validated successfully',
            'account_number' => $account_number,
            'fedex'          => $rate_body,
        ];
    }

    // Error handling/heuristics
    $error_msg       = 'Unable to validate account';
    $is_account_err  = false;

    if (!empty($rate_body['errors'])) {
        $first = $rate_body['errors'][0];
        $msg   = $first['message'] ?? $error_msg;
        $code  = $first['code'] ?? '';

        if (
            stripos($msg, 'unauthorized') !== false ||
            stripos($msg, 'account') !== false ||
            stripos($code, 'UNAUTHORIZED') !== false ||
            stripos($code, 'ACCOUNT') !== false
        ) {
            $is_account_err = true;
        }

        $error_msg = $msg;
    }

    return [
        'valid'         => false,
        'error'         => $error_msg,
        'account_error' => $is_account_err,
        'response_code' => $response_code,
        'fedex'         => $rate_body,
    ];
}