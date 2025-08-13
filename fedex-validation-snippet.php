<?php
// 1. Add FedEx account field on cart and checkout
function add_fedex_account_field() {
    echo '<div class="fedex-account-wrapper" style="margin: 20px 0;">
        <label for="fedex_account"><strong>FedEx Account Number</strong> (optional):</label><br>
        <input type="text" id="fedex_account" name="fedex_account" style="padding: 8px; width: 100%;" />
    </div>';
}
add_action('woocommerce_before_checkout_form', 'add_fedex_account_field', 5);
add_action('woocommerce_before_cart', 'add_fedex_account_field');

// 2. Handle AJAX validation
add_action('wp_ajax_validate_fedex_account', 'handle_fedex_account_validation');
add_action('wp_ajax_nopriv_validate_fedex_account', 'handle_fedex_account_validation');

function handle_fedex_account_validation() {
    $account = sanitize_text_field($_POST['account_number'] ?? '');
    $result = fedex_account_is_valid($account);
    wp_send_json($result);
}

// 3. Validate using dummy FedEx request (must replace with real FedEx RateService WSDL)
function fedex_account_is_valid($account_number) {
    $key = 'l7ff7204500b9a414ea6fb6d62550ed83b';
    $password = '535e2c1833c04b44a0419082f3d689b7';
    $meter = 'YOUR_METER_NUMBER';
    $wsdl = get_template_directory() . '/php/RateService_v28.wsdl';

    if (!file_exists($wsdl)) {
        return ['valid' => false, 'error' => 'WSDL file not found'];
    }

    try {
        $client = new SoapClient($wsdl, ['trace' => true]);
        $request = [
            'WebAuthenticationDetail' => [
                'UserCredential' => ['Key' => $key, 'Password' => $password],
            ],
            'ClientDetail' => [
                'AccountNumber' => $account_number,
                'MeterNumber' => $meter,
            ],
            'TransactionDetail' => ['CustomerTransactionId' => 'Validate Account'],
            'Version' => ['ServiceId' => 'crs', 'Major' => 28, 'Intermediate' => 0, 'Minor' => 0],
            'RequestedShipment' => [
                'DropoffType' => 'REGULAR_PICKUP',
                'PackagingType' => 'YOUR_PACKAGING',
                'Shipper' => [
                    'Address' => [
                        'StreetLines' => ['10 FedEx Parkway'],
                        'City' => 'Memphis',
                        'StateOrProvinceCode' => 'TN',
                        'PostalCode' => '38115',
                        'CountryCode' => 'US'
                    ]
                ],
                'Recipient' => [
                    'Address' => [
                        'StreetLines' => ['Receiver Address'],
                        'City' => 'New York',
                        'StateOrProvinceCode' => 'NY',
                        'PostalCode' => '10001',
                        'CountryCode' => 'US'
                    ]
                ],
                'ShippingChargesPayment' => [
                    'PaymentType' => 'SENDER',
                    'Payor' => [
                        'ResponsibleParty' => ['AccountNumber' => $account_number]
                    ]
                ],
                'PackageCount' => 1,
                'RequestedPackageLineItems' => [
                    'SequenceNumber' => 1,
                    'GroupPackageCount' => 1,
                    'Weight' => ['Value' => 2.0, 'Units' => 'LB']
                ]
            ]
        ];

        $response = $client->getRates($request);
        return ['valid' => true];

    } catch (SoapFault $e) {
        return ['valid' => false, 'error' => $e->getMessage()];
    }
}

// 4. Enqueue JS
function enqueue_fedex_validation_script() {
    wp_enqueue_script('fedex-validator', get_stylesheet_directory_uri() . '/js/fedex-validation.js', [], null, true);
    wp_localize_script('fedex-validator', 'fedexValidation', [
        'ajax_url' => admin_url('admin-ajax.php')
    ]);
}
add_action('wp_enqueue_scripts', 'enqueue_fedex_validation_script');
