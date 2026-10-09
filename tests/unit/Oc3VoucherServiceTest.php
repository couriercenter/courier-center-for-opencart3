<?php
require_once CC_REPO . '/tests/support/FakeDb.php';
require_once CC_REPO . '/tests/support/FakeConfig.php';
require_once CC_REPO . '/tests/support/FakeApi.php';
foreach (array('CCCityScope', 'CCOrderAdapter', 'CCShipmentBuilder', 'CCOrderHistory', 'CCStatusTracker') as $cc_class) {
    require_once CC_LIB . "/$cc_class.php";
}
require_once CC_LIB . '/CCVoucherService.php';

use Opencart\Extension\Couriercenter\Library\CCVoucherService;

/** OpenCart 3 getOrder() shape (spec 4.8). */
function cc_oc3_order(array $o = array()) {
    return array_merge(array(
        'order_id' => 55, 'firstname' => 'Μαρία', 'lastname' => 'Κ.', 'email' => 'm@example.com', 'telephone' => '6911111111',
        'shipping_firstname' => 'Μαρία', 'shipping_lastname' => 'Κ.', 'shipping_company' => '', 'shipping_address_1' => 'Σόλωνος 5',
        'shipping_address_2' => '', 'shipping_city' => 'Αθήνα', 'shipping_postcode' => '106 71', 'shipping_iso_code_2' => 'GR',
        'payment_firstname' => 'Μαρία', 'payment_lastname' => 'Κ.', 'payment_city' => 'Αθήνα', 'payment_postcode' => '10671', 'payment_iso_code_2' => 'GR',
        'payment_code' => 'cod', 'payment_method' => 'Αντικαταβολή',
        'shipping_code' => 'courier_center.courier_center', 'shipping_method' => 'Courier Center',
        'total' => '30.0000', 'custom_field' => array(),
    ), $o);
}
/** @return array [CCVoucherService, FakeDb, FakeApi] */
function cc_service(array $settings = array(), array $api = array()) {
    $config = new FakeConfig(array_merge(array(
        'shipping_courier_center_user_alias' => 'alias', 'shipping_courier_center_credential_value' => 'p&amp;ss',
        'shipping_courier_center_api_key' => 'key', 'shipping_courier_center_billing_account' => '100-1',
        'shipping_courier_center_shipper_name' => 'Ψ &amp; Σ Α.Ε.', 'shipping_courier_center_shipper_address' => 'Πατησίων 1',
        'shipping_courier_center_shipper_postcode' => '10431', 'shipping_courier_center_shipper_city' => 'ΑΘΗΝΑ',
        'shipping_courier_center_shipper_phone' => '2101234567',
    ), $settings));
    $db = new FakeDb();
    $db->on('/FROM `oc_order_product`/', array(array('product_id' => '40', 'quantity' => '2')));
    $db->on('/SELECT `weight`, `weight_class_id`/', array(array('weight' => '500', 'weight_class_id' => '2', 'length' => '0', 'width' => '0', 'height' => '0', 'length_class_id' => '1')));
    $db->on("/LOWER\(TRIM\(d\.`unit`\)\) = 'kg'/", array(array('id' => '1', 'val' => '1.00000000')));
    $db->on("/LOWER\(TRIM\(d\.`unit`\)\) = 'cm'/", array(array('id' => '1', 'val' => '1.00000000')));
    $db->on("/FROM `oc_weight_class` WHERE `weight_class_id` = '2'/", array(array('value' => '1000.00000000')));
    $db->on('/SELECT `order_status_id` FROM `oc_order`/', array(array('order_status_id' => '1')));
    $fapi = new FakeApi(array_merge(array(
        'create_shipment' => array('Result' => 'Success', 'ShipmentNumber' => 'AWB123', 'TrackingNumbers' => array('AWB123')),
    ), $api));
    return array(new CCVoucherService($db, $config, 'oc_', $fapi), $db, $fapi);
}
function cc_sent_payload(FakeApi $api) {
    foreach ($api->calls as $call) {
        if ($call[0] === 'create_shipment') {
            return $call[1][0];
        }
    }
    return null;
}

function test_oc3_service_creates_a_cod_voucher_for_an_oc3_order() {
    list($svc, $db, $api) = cc_service();
    $r = $svc->createForOrder(cc_oc3_order(), array('service_type' => 'next_day'));
    assert_true($r['success'], json_encode($r, JSON_UNESCAPED_UNICODE));
    assert_same('AWB123', $r['voucher']);
    $p = cc_sent_payload($api);
    assert_same(30.0, $p['CODs'][0]['Amount']['Value'], 'COD found through payment_code (spec 5.3)');
    assert_same(1.0, $p['Items'][0]['Weight']['Value'], '2 x 500 g = 1 kg (weight class conversion)');
    assert_same('10671', $p['Consignee']['ZipCode']);
    $ins = $db->matching('/INSERT INTO `oc_cc_shipments`/');
    assert_count(1, $ins);
    assert_contains("`is_voided` = '0'", $ins[0]);
    assert_contains('`created_at` = NOW()', $ins[0]);
    $hist = $db->matching('/INSERT INTO `oc_order_history`/');
    assert_contains('✅ Courier Center voucher: AWB123 | Υπηρεσία: Επόμενη Μέρα', $hist[0]);
}
/** Review Focus 5: settings are stored HTML-escaped by OpenCart; the API must get the real text. */
function test_oc3_service_decodes_html_escaped_settings() {
    list($svc, , $api) = cc_service();
    assert_same('p&ss', $svc->builderSettings()['credential_value']);
    $svc->createForOrder(cc_oc3_order(), array());
    $p = cc_sent_payload($api);
    assert_same('p&ss', $p['Context']['CredentialValue']);
    assert_same('Ψ & Σ Α.Ε.', $p['Shipper']['CompanyName']);
}
function test_oc3_service_refuses_boxnow_with_cod_without_calling_the_api() {
    list($svc, , $api) = cc_service();
    $r = $svc->createForOrder(cc_oc3_order(), array('boxnow' => true));
    assert_false($r['success']);
    assert_same('validation', $r['stage']);
    assert_contains('BOX NOW', $r['error']);
    assert_count(0, $api->calls);
}
function test_oc3_service_reports_an_invalid_order_without_calling_the_api() {
    list($svc, , $api) = cc_service();
    $r = $svc->createForOrder(cc_oc3_order(array('telephone' => '')), array());
    assert_same(array(false, 'validation', 'Λείπει το τηλέφωνο παραλήπτη.'), array($r['success'], $r['stage'], $r['error']));
    assert_count(0, $api->calls);
}
function test_oc3_service_resets_a_voided_row_for_a_new_voucher() {
    list($svc, $db) = cc_service();
    $db->on('/SELECT `id` FROM `oc_cc_shipments`/', array(array('id' => '9')));
    $svc->createForOrder(cc_oc3_order(), array());
    $upd = $db->matching('/UPDATE `oc_cc_shipments` SET/');
    assert_count(1, $upd);
    foreach (array("`is_voided` = '0'", "`is_final` = '0'", "`status_code` = ''", '`last_checked_at` = NULL', '`created_at` = NOW()') as $part) {
        assert_contains($part, $upd[0], 'spec 10 #3');
    }
}
function test_oc3_service_boxnow_pick_sends_the_customer_locker() {
    list($svc, $db, $api) = cc_service();
    $order = cc_oc3_order(array('payment_code' => 'bank_transfer', 'payment_method' => 'Τράπεζα'));
    $r = $svc->createForOrder($order, array('boxnow' => true, 'locker_id' => 'L42', 'locker_name' => 'Locker Σόλωνος'));
    assert_true($r['success']);
    assert_same(array('Prefix' => 'ATH', 'Code' => 'L42'), cc_sent_payload($api)['LockerDeliveryInfo']);
    $ins = $db->matching('/INSERT INTO `oc_cc_shipments`/');
    assert_contains("`is_boxnow` = '1'", $ins[0]);
    assert_contains("`locker_name` = 'Locker Σόλωνος'", $ins[0]);
}
function test_oc3_service_api_error_has_stage_api_and_saves_nothing() {
    list($svc, $db) = cc_service(array(), array('create_shipment' => array('success' => false, 'error' => 'Invalid zip')));
    $r = $svc->createForOrder(cc_oc3_order(), array());
    assert_same(array(false, 'api', 'Invalid zip'), array($r['success'], $r['stage'], $r['error']));
    assert_count(0, $db->matching('/(INSERT INTO|UPDATE) `oc_cc_shipments`/'));
    $hist = $db->matching('/INSERT INTO `oc_order_history`/');
    assert_count(1, $hist, 'manual API failure leaves a note');
    assert_contains('❌ Αποτυχία δημιουργίας voucher: Invalid zip', $hist[0]);
    assert_same(array(), $db->locks, 'lock released');
}
function test_oc3_service_saves_the_return_awb_and_auto_note() {
    list($svc, $db) = cc_service(array(), array('create_shipment' => array('Result' => 'Success', 'ShipmentNumber' => 'A1', 'TrackingNumbers' => array('A1', 'R1'))));
    $r = $svc->createForOrder(cc_oc3_order(), array('return_option' => 'optional', 'mode' => 'auto'));
    assert_same('R1', $r['return_awb']);
    $hist = $db->matching('/INSERT INTO `oc_order_history`/');
    assert_contains('🤖 Αυτόματη δημιουργία voucher (αλλαγή status): A1', $hist[0]);
}
function test_oc3_service_handled_methods_use_the_oc3_shipping_code() {
    list($svc) = cc_service();
    assert_true($svc->isHandledOrder(cc_oc3_order(array('shipping_code' => 'flat.flat'))), 'empty setting = all orders');
    list($svc2) = cc_service(array('shipping_courier_center_handled_shipping_methods' => array('courier_center')));
    assert_true($svc2->isHandledOrder(cc_oc3_order()));
    assert_false($svc2->isHandledOrder(cc_oc3_order(array('shipping_code' => 'flat.flat'))));
    assert_same('courier_center', CCVoucherService::shippingCode(cc_oc3_order()));
}
function test_oc3_service_reads_boxnow_from_custom_field() {
    assert_same(array(), CCVoucherService::boxnowFromOrder(cc_oc3_order()));
    $pick = CCVoucherService::boxnowFromOrder(cc_oc3_order(array('custom_field' => array('cc_delivery_mode' => 'pick', 'cc_locker_id' => 'L1', 'cc_locker_code' => 'C1', 'cc_locker_name' => 'N'))));
    assert_same(array('boxnow' => true, 'locker_id' => 'L1', 'locker_code' => 'C1', 'locker_name' => 'N'), $pick);
    $auto = CCVoucherService::boxnowFromOrder(cc_oc3_order(array('custom_field' => '{"cc_delivery_mode":"auto"}')));
    assert_true($auto['boxnow']);
    assert_same('', $auto['locker_id']);
}
