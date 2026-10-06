<?php
/** Order fixtures shared by the golden generator (original OC4 library) and the tests. All OC4-shaped. */

function cc_fixture_settings() {
    return array(
        'user_alias' => 'alias', 'credential_value' => 'cred', 'api_key' => 'key', 'billing_account' => '100-0000-0000',
        'shipper_name' => 'Κατάστημα Δοκιμής', 'shipper_address' => 'Πατησίων 1', 'shipper_postal' => '10431',
        'shipper_city' => 'ΑΘΗΝΑ', 'shipper_phone' => '2101234567', 'shipper_station' => '096',
    );
}

function cc_fixture_order(array $overrides = array()) {
    return array_merge(array(
        'order_id' => 1001, 'firstname' => 'Γιώργος', 'lastname' => 'Παπαδόπουλος', 'email' => 'g@example.com', 'telephone' => '6900000000',
        'payment_firstname' => 'Γιώργος', 'payment_lastname' => 'Παπαδόπουλος', 'payment_company' => '',
        'payment_address_1' => 'Αλεξάνδρας 10', 'payment_address_2' => '', 'payment_city' => 'Αθήνα',
        'payment_postcode' => '11473', 'payment_iso_code_2' => 'GR',
        'shipping_firstname' => 'Γιώργος', 'shipping_lastname' => 'Παπαδόπουλος', 'shipping_company' => '',
        'shipping_address_1' => 'Αλεξάνδρας 10', 'shipping_address_2' => 'Διαμ. 3', 'shipping_city' => 'Αθήνα',
        'shipping_postcode' => '114 73', 'shipping_iso_code_2' => 'GR',
        'total' => 45.5,
        'payment_method' => array('name' => 'Αντικαταβολή', 'code' => 'cod.cod'),
        'shipping_method' => array('name' => 'Courier Center', 'code' => 'courier_center.courier_center'),
    ), $overrides);
}

function cc_fixture_cases() {
    $s = cc_fixture_settings();
    $bank = array('payment_method' => array('name' => 'Τράπεζα', 'code' => 'bank_transfer.bank_transfer'));
    $light = array(array('product_id' => 40, 'quantity' => 2, 'weight' => 0.5, 'length' => 0, 'width' => 0, 'height' => 0));
    $boxed = array(array('product_id' => 43, 'quantity' => 1, 'weight' => 1.5, 'length' => 30, 'width' => 20, 'height' => 5));
    $case = function ($order, $products, $parcels, $service, $boxnow, $return, $settings = null) use ($s) {
        return array('order' => $order, 'products' => $products, 'settings' => $settings === null ? $s : $settings,
            'parcels' => $parcels, 'service' => $service, 'boxnow' => $boxnow, 'return' => $return);
    };
    return array(
        'cod_athens_next_day'           => $case(cc_fixture_order(), $light, 1, 'next_day', false, 'none'),
        'bank_thessaloniki_optional_2p' => $case(cc_fixture_order(array_merge($bank, array('shipping_city' => 'Θεσσαλονίκη', 'shipping_postcode' => '54624'))), $boxed, 2, 'next_day', false, 'optional'),
        'bank_rhodes_mandatory_3h'      => $case(cc_fixture_order(array_merge($bank, array('shipping_city' => 'Ρόδος', 'shipping_postcode' => '85100'))), $boxed, 1, 'same_day_3h', false, 'mandatory'),
        'boxnow_unknown_postcode'       => $case(cc_fixture_order(array_merge($bank, array('shipping_postcode' => '99999'))), $light, 1, 'next_day', true, 'none'),
        'payment_method_json_string'    => $case(cc_fixture_order(array('payment_method' => '{"name":"Αντικαταβολή","code":"cod.cod"}')), $light, 1, 'next_day', false, 'none'),
        'no_products_default_weight'    => $case(cc_fixture_order(), array(), 1, 'same_day_5h', false, 'none'),
        'invalid_phone_and_postcode'    => $case(cc_fixture_order(array('telephone' => '', 'shipping_postcode' => 'ABC12')), $light, 1, 'next_day', false, 'none'),
        'invalid_missing_settings'      => $case(cc_fixture_order(), $light, 1, 'next_day', false, 'none', array_merge($s, array('api_key' => '', 'shipper_phone' => ''))),
        'too_heavy'                     => $case(cc_fixture_order(), array(array('product_id' => 1, 'quantity' => 4, 'weight' => 8.0)), 1, 'next_day', false, 'none'),
        'foreign_cod'                   => $case(cc_fixture_order(array('shipping_iso_code_2' => 'CY', 'shipping_postcode' => '10431')), $light, 1, 'next_day', false, 'none'),
    );
}
