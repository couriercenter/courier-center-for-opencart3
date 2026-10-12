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
    assert_same('1', $post['shipping_courier_center_returns_require_approval'], 'not posted → approval stays on');
    assert_same('1', $post['shipping_courier_center_returns_method_dropoff'], 'not posted → Drop Off on');
    $off = array('shipping_courier_center_returns_require_approval' => '0');
    CCReturnsSettings::sanitizePost($off, array());
    assert_same('0', $off['shipping_courier_center_returns_require_approval'], 'explicit 0 is kept');
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
function test_lib_returns_pickup_dates_and_window() {
    $tz = new DateTimeZone('Europe/Athens');
    // Friday evening → Monday; Saturday → Monday; Monday → Tuesday
    assert_same('2026-10-19', R::next_working_day(new DateTimeImmutable('2026-10-16 22:30', $tz)));
    assert_same('2026-10-19', R::next_working_day(new DateTimeImmutable('2026-10-17 09:00', $tz)));
    assert_same('2026-10-13', R::next_working_day(new DateTimeImmutable('2026-10-12 00:01', $tz)));
    $dates = R::pickup_dates(new DateTimeImmutable('2026-10-16 10:00', $tz));
    assert_count(R::PICKUP_DAYS, $dates);
    assert_same('2026-10-19', $dates[0]);
    assert_same('2026-10-30', $dates[9], 'ten working days, no weekends');
    $now = new DateTimeImmutable('2026-10-16 10:00', $tz);
    assert_same('', R::pickup_date_error('2026-10-20', $now));
    assert_same(R::MSG_PICKUP_DATE, R::pickup_date_error('2026-10-24', $now), 'Saturday');
    assert_same(R::MSG_PICKUP_DATE, R::pickup_date_error('2026-10-16', $now), 'today');
    assert_same('', R::pickup_window_error('', ''));
    assert_same('', R::pickup_window_error('09:00', '13:00'));
    assert_same('', R::pickup_window_error('13:00', '17:00'));
    assert_same(R::MSG_PICKUP_HALF, R::pickup_window_error('09:00', ''));
    assert_same(R::MSG_PICKUP_WINDOW, R::pickup_window_error('10:00', '13:00'), '3 hours');
    assert_same(R::MSG_PICKUP_WINDOW, R::pickup_window_error('08:00', '13:00'));
    assert_same(R::MSG_PICKUP_WINDOW, R::pickup_window_error('14:00', '18:00'));
    assert_same(R::MSG_PICKUP_WINDOW, R::pickup_window_error('09:30', '13:30'));
    assert_same(array('09:00', '10:00', '11:00', '12:00', '13:00'), R::pickup_hours_from());
    assert_same(array('13:00', '14:00', '15:00', '16:00', '17:00'), R::pickup_hours_to());
    assert_same('Τρίτη 13/10', R::pickup_date_label('2026-10-13'));
    assert_same('', R::pickup_date_label('2026-02-30'));
    assert_same('Τρίτη 13/10/2026, 09:00–13:00', R::pickup_text('2026-10-13', '09:00', '13:00'));
    assert_same('Τρίτη 13/10/2026, οποιαδήποτε ώρα 09:00–17:00', R::pickup_text('2026-10-13', null, null));
    assert_same('', R::pickup_text(null, '09:00', '13:00'));
}
function test_lib_returns_bill_to() {
    assert_same('Requestor', R::bill_to(null, '100-0120-0004'));
    assert_same('Requestor', R::bill_to(array('code' => '100-120-0004', 'bill_to' => 'self'), '100-0120-0004'), 'main code');
    assert_same('Requestor', R::bill_to(array('code' => '100-0120-0001', 'bill_to' => 'main'), '100-0120-0004'));
    assert_same('Consignee', R::bill_to(array('code' => '100-0120-0001', 'bill_to' => 'self'), '100-0120-0004'));
}
function test_lib_returns_pickup_payload() {
    $req = cc_return_req(array('method' => R::METHOD_PICKUP, 'bill_to' => 'Consignee', 'pickup_date' => '2026-10-20', 'pickup_time_from' => '09:00', 'pickup_time_to' => '13:00',
        'shipper_details' => array('name' => 'Μαρία Κ.', 'address' => 'Σόλωνος 5', 'city' => 'Αθήνα', 'postcode' => '106 71', 'phone' => '2101234567', 'courier_notes' => "3ος\n όροφος")));
    $p = CCReturnPayload::build($req, array(), 1.0);
    assert_same(array('Σόλωνος 5', 'Αθήνα', 'Αθήνα', '10671'), array($p['Shipper']['Address'], $p['Shipper']['City'], $p['Shipper']['Area'], $p['Shipper']['ZipCode']));
    assert_same('2026-10-20', $p['ShipmentDate']);
    assert_false(isset($p['shipmentDate']), 'only one date key');
    assert_same(array('TimeFrom' => '09:00', 'TimeTo' => '13:00', 'Comments' => '3ος όροφος'), $p['CollectionInstructions']);
    assert_same('Consignee', $p['BillTo']);
    assert_false(isset($p['LockerPickupInfo']));
    $req['pickup_time_from'] = null;
    $req['pickup_time_to'] = null;
    $p = CCReturnPayload::build($req, array(), 1.0);
    assert_same(array('09:00', '17:00'), array($p['CollectionInstructions']['TimeFrom'], $p['CollectionInstructions']['TimeTo']));
    assert_same('Requestor', CCReturnPayload::build(cc_return_req(), array(), 1.0)['BillTo']);
    foreach (array(array('pickup_date' => '2026-13-01'), array('pickup_time_from' => '10:00', 'pickup_time_to' => '12:00')) as $bad) {
        assert_true(isset(CCReturnPayload::build(array_merge($req, $bad), array(), 1.0)['error']), json_encode($bad));
    }
    $noZip = $req;
    $noZip['shipper_details']['postcode'] = '1234';
    assert_true(isset(CCReturnPayload::build($noZip, array(), 1.0)['error']));
}
function test_lib_returns_pickup_settings_and_parts() {
    $s = new CCReturnsSettings(new FakeConfig(array('shipping_courier_center_returns_method_pickup' => '1', 'shipping_courier_center_returns_method_boxnow' => '1')));
    assert_same(array(R::METHOD_DROPOFF, R::METHOD_PICKUP, R::METHOD_BOXNOW), $s->enabledMethods());
    assert_same(CCReturnsSettings::DEFAULT_PICKUP_TEXT, $s->instructionsFor(R::METHOD_PICKUP));
    assert_same(CCReturnsSettings::DEFAULT_DROPOFF_TEXT, $s->instructionsFor(R::METHOD_DROPOFF));
    assert_true($s->adminNotify());
    assert_same(array('shop@example.gr'), $s->adminEmails('shop@example.gr'));
    $s2 = new CCReturnsSettings(new FakeConfig(array('shipping_courier_center_returns_admin_emails' => 'a@x.gr, b@x.gr;bad', 'shipping_courier_center_returns_admin_notify' => '0')));
    assert_same(array('a@x.gr', 'b@x.gr'), $s2->adminEmails('shop@example.gr'));
    assert_false($s2->adminNotify());
    $post = array('shipping_courier_center_returns_admin_emails' => 'a@x.gr, nope, a@x.gr');
    $errors = CCReturnsSettings::sanitizePost($post, array());
    assert_same('0', $post['shipping_courier_center_returns_method_pickup']);
    assert_same('1', $post['shipping_courier_center_returns_admin_notify']);
    assert_same('a@x.gr', $post['shipping_courier_center_returns_admin_emails']);
    assert_count(1, $errors);
    $parts = R::creation_parts(array('method' => R::METHOD_PICKUP, 'return_awb' => 'R9', 'pickup_date' => '2026-10-13', 'pickup_time_from' => '09:00', 'pickup_time_to' => '13:00',
        'shipper_details' => array('address' => 'Σόλωνος 5', 'city' => 'Αθήνα', 'postcode' => '10671')));
    assert_same(array('Ο courier θα παραλάβει το δέμα από τη διεύθυνση: ', 'Σόλωνος 5, Αθήνα 10671', false), $parts[1]);
    assert_same(array('Παραλαβή: ', 'Τρίτη 13/10/2026, 09:00–13:00', false), $parts[2]);
    assert_same('R9', $parts[3][1]);
}
function test_lib_returns_customer_mail_filter_and_admin_email() {
    $svc = 'Opencart\Extension\Couriercenter\Library\CCReturnsService';
    assert_true($svc::customerMailAllowed('created', array('origin' => 'customer', 'notify_customer' => 1)));
    assert_true($svc::customerMailAllowed('rejected', array('origin' => 'customer', 'notify_customer' => 1)));
    assert_false($svc::customerMailAllowed('rejected', array('origin' => 'shop', 'notify_customer' => 1)));
    assert_true($svc::customerMailAllowed('created', array('origin' => 'shop', 'notify_customer' => 1)));
    assert_false($svc::customerMailAllowed('created', array('origin' => 'shop', 'notify_customer' => 0)));
    $row = array('order_id' => 7, 'method' => R::METHOD_PICKUP, 'status' => R::ST_PENDING, 'reason' => 'Μικρό <νούμερο>', 'return_awb' => '',
        'pickup_date' => '2026-10-13', 'pickup_time_from' => null, 'pickup_time_to' => null, 'items' => array(array('name' => 'Μπλούζα', 'qty' => 1)));
    $m = CCMailContent::adminNewReturnEmail($row, array('order_id' => 7, 'firstname' => 'Μαρία', 'lastname' => 'Κ.'), 'https://shop/admin?a=1&b=2', 'https://shop/admin/order');
    assert_same('Νέο αίτημα επιστροφής για την παραγγελία #7', $m['subject']);
    assert_contains('Ο πελάτης Μαρία Κ. ζήτησε επιστροφή για την παραγγελία #7.', $m['text']);
    assert_contains('Παραλαβή: Τρίτη 13/10/2026, οποιαδήποτε ώρα 09:00–17:00', $m['text']);
    assert_contains('Περιμένει την έγκρισή σας.', $m['text']);
    assert_contains('Μικρό &lt;νούμερο&gt;', $m['html']);
    assert_contains('a=1&amp;b=2', $m['html']);
    $row['status'] = R::ST_CREATED;
    $row['return_awb'] = 'R1';
    assert_contains('Αριθμός αποστολής επιστροφής: R1', CCMailContent::adminNewReturnEmail($row, array('order_id' => 7), 'u', '')['text']);
}
