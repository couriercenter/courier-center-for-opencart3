<?php
/** Returns (WooCommerce 1.6.0 port): pure rules, payload, settings and email content. */
require_once CC_REPO . '/tests/support/FakeConfig.php';

use Opencart\Extension\Couriercenter\Library\CCMailContent;
use Opencart\Extension\Couriercenter\Library\CCReturnPayload;
use Opencart\Extension\Couriercenter\Library\CCReturnsRules as R;
use Opencart\Extension\Couriercenter\Library\CCReturnsSettings;

function test_lib_returns_phones() {
    assert_same('6912345678', R::normalize_phone('+30 691-234 5678'));
    assert_same('2101234567', R::normalize_phone('0030 210 1234567'));
    assert_true(R::is_gr_mobile('6912345678') && !R::is_gr_mobile('2101234567') && !R::is_gr_mobile("6912345678\n"));
    assert_true(R::is_gr_landline('2101234567') && !R::is_gr_landline('6912345678'));
    assert_same(array('error' => R::MSG_PHONE), R::pick_phone(R::METHOD_DROPOFF, '', ''));
    assert_same(array('phone' => '2101234567'), R::pick_phone(R::METHOD_DROPOFF, '', '210 1234567'));
    assert_same(array('phone' => '6912345678'), R::pick_phone(R::METHOD_DROPOFF, '6912345678', '2101234567'));
    assert_same(array('phone' => '2101234567'), R::pick_phone(R::METHOD_DROPOFF, '2101234567', ''), 'landline typed in the mobile field');
    assert_true(isset(R::pick_phone(R::METHOD_DROPOFF, '123', 'abc')['error']));
    assert_same(array('error' => R::MSG_MOBILE), R::pick_phone(R::METHOD_BOXNOW, '', '2101234567'));
    assert_same(array('phone' => '6912345678'), R::pick_phone(R::METHOD_BOXNOW, '6912345678', ''));
    assert_same(array('phone' => '6912345678'), R::pick_phone(R::METHOD_BOXNOW, '', '6912345678'), 'mobile typed in the landline field');
}
function test_lib_returns_lockers_codes_default_method() {
    assert_same(array('1', '2', '3', ''), array(R::locker_reference('small'), R::locker_reference('medium'), R::locker_reference('large'), R::locker_reference('xl')));
    assert_true(R::codes_equal('100-0120-0001', '100-120-0001'));
    assert_false(R::codes_equal('100-0120-0001', '100-120-0004'));
    assert_false(R::codes_equal('', ''));
    assert_true(R::is_valid_account_code('100-0120-0009') && !R::is_valid_account_code('100-12'));
    assert_same(R::METHOD_DROPOFF, R::default_method(array(R::METHOD_BOXNOW, R::METHOD_DROPOFF)));
    assert_same(R::METHOD_BOXNOW, R::default_method(array(R::METHOD_BOXNOW)));
    assert_same('', R::default_method(array()));
}
function test_lib_returns_askopi_matching_and_display_status() {
    foreach (array('ΑΣΚΟΠΗ', 'ασκοπη', 'Άσκοπη', 'ΑΣΚΟΠΗ ΠΡΟΣΕΛΕΥΣΗ', 'ΑΚΥΡΩΣΗ - ΑΣΚΟΠΗ', 'άσκοπή', 'AΣKOΠH') as $s) {
        assert_true(R::contains_askopi($s), "matches: $s");
    }
    foreach (array('', null, 'RETURN', 'ΣΚΟΠΟΣ', 'ΑΣΚΟΠ') as $s) {
        assert_false(R::contains_askopi($s), 'no match: ' . var_export($s, true));
    }
    assert_same(R::ASKOPI_STATUS, R::display_status('15', 'NewShipment', 10, array('013847541488', 'ΑΣΚΟΠΗ', '', '')), 'A');
    assert_same(R::ASKOPI_STATUS, R::display_status('24', 'X', '10', array('', '', 'ΑΚΥΡΩΣΗ ΑΣΚΟΠΗ ΠΡΟΣΕΛΕΥΣΗ', '')), 'B');
    assert_same('NewShipment', R::display_status('15', 'NewShipment', 14, array('', 'ΑΣΚΟΠΗ')), 'C');
    assert_same('NewShipment', R::display_status('15', 'NewShipment', 10, array('', 'RETURN')), 'D');
    assert_same('NewShipment', R::display_status('15', 'NewShipment', 10, array(null, null, null, null)));
}
function cc_return_req(array $o = array()) {
    return array_merge(array('method' => R::METHOD_DROPOFF, 'original_awb' => '013847541488', 'reference_token' => 'RRABC', 'return_account_code' => '100-0120-0009',
        'requestor_account' => '100-0120-0004', 'order_id' => 77, 'locker_reference' => '',
        'shipper_details' => array('name' => 'Μαρία Κ.', 'address' => 'Σόλωνος 5', 'phone' => '6912345678')), $o);
}
function test_lib_returns_dropoff_payload() {
    $p = CCReturnPayload::build(cc_return_req(), array('UserAlias' => 'u', 'CredentialValue' => 'c', 'ApiKey' => 'k'), 2.5);
    assert_same(array('ZipCode' => '91919', 'Area' => 'DROPOFF', 'City' => 'DROPOFF'), array_intersect_key($p['Shipper'], array('ZipCode' => 1, 'Area' => 1, 'City' => 1)));
    assert_same('Σόλωνος 5', $p['Shipper']['Address']);
    assert_same('6912345678', $p['Shipper']['Mobile1']);
    assert_same(array('CarrierBillingAccount' => '100-0120-0009'), $p['Consignee']);
    assert_same(array('CarrierBillingAccount' => '100-0120-0004'), $p['Requestor']);
    assert_same(array('013847541488', 'RRABC', 1), array($p['Reference1'], $p['Reference2'], $p['NoOfItems']));
    assert_count(1, $p['Items']);
    assert_same(2.5, $p['Items'][0]['Weight']['Value']);
    assert_false(isset($p['LockerPickupInfo']) || isset($p['BasicService']) || isset($p['Reference4']));
    assert_same('ΕΠΙΣΤΡΟΦΗ OC-77', $p['comments']);
}
function test_lib_returns_boxnow_payload_and_errors() {
    $p = CCReturnPayload::build(cc_return_req(array('method' => R::METHOD_BOXNOW, 'locker_reference' => '2')), array(), 1.0);
    assert_same(array('reference' => '2'), $p['LockerPickupInfo']);
    assert_same(array('BOX NOW ATH', '99981'), array($p['Shipper']['Address'], $p['Shipper']['ZipCode']));
    assert_false(isset($p['Shipper']['City']), 'no DROPOFF values on BOX NOW');
    assert_true(isset(CCReturnPayload::build(cc_return_req(array('method' => R::METHOD_BOXNOW, 'locker_reference' => 2)), array(), 1.0)['error']), 'reference must be a string');
    assert_true(isset(CCReturnPayload::build(cc_return_req(array('method' => R::METHOD_PICKUP)), array(), 1.0)['error']));
    assert_true(isset(CCReturnPayload::build(cc_return_req(array('return_account_code' => 'x')), array(), 1.0)['error']));
    assert_true(isset(CCReturnPayload::build(cc_return_req(array('shipper_details' => array('name' => 'A', 'address' => 'B', 'phone' => ''))), array(), 1.0)['error']));
}
function test_lib_returns_settings_defaults_and_sanitize() {
    $s = new CCReturnsSettings(new FakeConfig(array()));
    assert_false($s->enabled());
    assert_true($s->requireApproval());
    assert_same(14, $s->windowDays());
    assert_same(array(R::METHOD_DROPOFF), $s->enabledMethods());
    assert_same(1.0, $s->weight());
    assert_same(CCReturnsSettings::DEFAULT_BOXNOW_TEXT, $s->boxnowInstructions());
    $s2 = new CCReturnsSettings(new FakeConfig(array(
        'shipping_courier_center_returns_method_dropoff' => '0', 'shipping_courier_center_returns_method_boxnow' => '1',
        'shipping_courier_center_returns_weight' => '45', 'shipping_courier_center_returns_window_days' => '0',
        'shipping_courier_center_returns_accounts' => array(array('original' => '100-0120-0001', 'return' => '100-0120-0009')),
    )));
    assert_same(array(R::METHOD_BOXNOW), $s2->enabledMethods());
    assert_same(30.0, $s2->weight());
    assert_same(0, $s2->windowDays());
    assert_same('100-0120-0009', $s2->returnAccountFor('100-120-0001'));
    assert_same('', $s2->returnAccountFor('100-0120-0004'));
    $post = array('shipping_courier_center_returns_enabled' => 'on', 'shipping_courier_center_returns_weight' => '0,5',
        'shipping_courier_center_returns_accounts' => array(
            array('original' => '100-120-0001', 'return' => '100-0120-0009'),
            array('original' => '100-0120-0001', 'return' => '100-0120-0010'),
            array('original' => '999-0000-0000', 'return' => '100-0120-0011'),
            array('original' => '100-0120-0004', 'return' => 'bad'),
            array('original' => '100-0120-0004', 'return' => ''),
        ));
    $errors = CCReturnsSettings::sanitizePost($post, array('100-0120-0004', '100-0120-0001'));
    assert_same('1', $post['shipping_courier_center_returns_enabled']);
    assert_same('0', $post['shipping_courier_center_returns_method_boxnow']);
    assert_same('0.5', $post['shipping_courier_center_returns_weight']);
    assert_same(array(array('original' => '100-0120-0001', 'return' => '100-0120-0009')), $post['shipping_courier_center_returns_accounts']);
    assert_count(3, $errors);
}
function test_lib_mail_tracking_block_and_return_emails() {
    $sh = array('voucher_number' => 'AWB1', 'tracking_number' => 'AWB1', 'is_voided' => '0', 'boxnow_parcel_id' => 'P<9>');
    $html = CCMailContent::trackingHtml($sh, 'https://t.example/?n={{tracking}}');
    assert_contains('https://t.example/?n=AWB1', $html);
    assert_contains('P&lt;9&gt;', $html);
    assert_same('', CCMailContent::trackingHtml(array('voucher_number' => 'AWB1', 'is_voided' => '1'), ''));
    assert_contains('Παρακολούθηση: https://www.courier.gr/track/result?tracknr=AWB1', CCMailContent::trackingText($sh, ''));
    $row = array('order_id' => 7, 'method' => R::METHOD_BOXNOW, 'return_awb' => 'R1', 'return_shipment_number' => '9287', 'locker_size' => 'small',
        'items' => array(array('name' => 'Μπλούζα <b>', 'qty' => 2)));
    $m = CCMailContent::returnEmail('created', $row, array('order_id' => 7, 'firstname' => 'Μαρία'), 'Οδηγίες');
    assert_same('Επιστροφή παραγγελίας #7: δημιουργήθηκε η αποστολή', $m['subject']);
    assert_contains('Κωδικός BOX NOW επιστροφής: 9287', $m['html']);
    assert_contains('Μπλούζα &lt;b&gt; × 2', $m['html']);
    assert_contains('Γεια σας Μαρία,', $m['text']);
    assert_contains('Επιλεγμένο μέγεθος: Μικρό', $m['text']);
    assert_same('Γεια σας,', explode("\n", CCMailContent::returnEmail('rejected', $row, array('order_id' => 7))['text'])[2]);
    assert_same(array(), CCMailContent::returnEmail('nope', $row, array()));
}
