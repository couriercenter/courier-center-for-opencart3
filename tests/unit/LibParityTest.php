<?php
/** WooCommerce 1.5 parity in the shared library: pickup points, voucher lock, tracker rules, schema. */
require_once CC_REPO . '/tests/support/FakeDb.php';
require_once CC_REPO . '/tests/support/FakeConfig.php';
require_once CC_REPO . '/tests/support/FakeApi.php';
require_once CC_REPO . '/tests/unit/Oc3VoucherServiceTest.php';
require_once CC_REPO . '/tests/unit/LibStatusTrackerTest.php';

use Opencart\Extension\Couriercenter\Library\CCDbSchema;
use Opencart\Extension\Couriercenter\Library\CCLock;
use Opencart\Extension\Couriercenter\Library\CCOrderAdapter;
use Opencart\Extension\Couriercenter\Library\CCPickupPoints;
use Opencart\Extension\Couriercenter\Library\CCShipmentBuilder;
use Opencart\Extension\Couriercenter\Library\CCStatusTracker;
use Opencart\Extension\Couriercenter\Library\CCVoucherService;

function cc_points_settings(array $rows, $default = '') {
    return array(
        'shipping_courier_center_pickup_points' => $rows,
        'shipping_courier_center_default_pickup_point' => $default,
    );
}
function cc_sub_row($code, $bill_to = 'self', array $o = array()) {
    return array_merge(array('code' => $code, 'label' => 'Κηφισός', 'bill_to' => $bill_to, 'name' => 'CC ΚΗΦΙΣΟΣ',
        'address' => 'Κηφισού 46', 'postal' => '12241', 'city' => 'ΑΙΓΑΛΕΩ', 'phone' => '2107512000'), $o);
}

// ── CCPickupPoints ──────────────────────────────────────────────────────
function test_lib_points_main_first_requestor_per_bill_to() {
    $p = CCPickupPoints::fromConfig(new FakeConfig(array(
        'shipping_courier_center_billing_account' => '100-0120-0004',
        'shipping_courier_center_shipper_name' => 'Ψ &amp; Σ',
        'shipping_courier_center_pickup_points' => array(cc_sub_row('100-0120-0001', 'main'), cc_sub_row('100-0120-0003', 'self', array('label' => ''))),
    )));
    $all = $p->all();
    assert_same(array('100-0120-0004', '100-0120-0001', '100-0120-0003'), array_column($all, 'code'));
    assert_same('Ψ & Σ', $all[0]['name'], 'decoded');
    assert_same('100-0120-0004', $all[1]['requestor'], 'bill_to main → main code');
    assert_same('100-0120-0003', $all[2]['requestor'], 'bill_to self → own code');
    assert_same('100-0120-0003', $all[2]['label'], 'empty label → code');
    assert_true($p->hasSubs());
    assert_same('Έδρα (100-0120-0004)', CCPickupPoints::label($all[0]));
}
function test_lib_points_ignore_main_code_and_duplicates_and_fallback_default() {
    $p = new CCPickupPoints(array('billing_account' => 'M', 'pickup_points' => json_encode(array(cc_sub_row('M'), cc_sub_row('S1'), cc_sub_row('S1'))), 'default_pickup_point' => 'GONE'));
    assert_same(array('M', 'S1'), array_column($p->all(), 'code'));
    assert_same('M', $p->defaultCode(), 'deleted default point → main');
    $p2 = new CCPickupPoints(array('billing_account' => 'M', 'pickup_points' => array(cc_sub_row('S1')), 'default_pickup_point' => 'S1'));
    assert_same('S1', $p2->defaultCode());
    assert_same(null, $p2->get('X'));
    assert_false((new CCPickupPoints(array('billing_account' => 'M')))->hasSubs());
}
function test_lib_points_sanitize_rows() {
    $r = CCPickupPoints::sanitizeRows(array(
        array('code' => ' S1 ', 'label' => '<b>Α</b>', 'bill_to' => 'evil', 'name' => "x\ny"),
        array('code' => ''),
        array('code' => 'M'),
        array('code' => 'S1'),
        'junk',
    ), 'M');
    assert_count(1, $r['rows']);
    assert_same(array('code' => 'S1', 'label' => 'Α', 'bill_to' => 'self', 'name' => 'x y', 'address' => '', 'postal' => '', 'city' => '', 'phone' => ''), $r['rows'][0]);
    assert_count(2, $r['errors']);
    assert_contains('ίδιος με τον κεντρικό', $r['errors'][0]);
    assert_contains('δύο φορές', $r['errors'][1]);
}

// ── Builder ─────────────────────────────────────────────────────────────
function cc_builder($order = array(), $settings = array()) {
    list($svc) = cc_service();
    return new CCShipmentBuilder(new CCOrderAdapter(cc_oc3_order($order), array(array('weight' => 1, 'quantity' => 1))), array_merge($svc->builderSettings(), $settings));
}
function test_lib_builder_main_account_payload_is_unchanged() {
    $p = cc_builder()->build_payload();
    assert_same('100-1', $p['Requestor']['CarrierBillingAccount']);
    assert_same('100-1', $p['Shipper']['CarrierBillingAccount']);
    assert_same('', $p['comments'], 'no customer note → empty comments (order number is in Reference1), as in WooCommerce');
}
function test_lib_builder_customer_note_goes_to_comments() {
    $p = cc_builder(array('comment' => "Κουδούνι &amp; 2ος\n\n  όροφος " . str_repeat('α', 300)))->build_payload();
    assert_same(0, strpos($p['comments'], 'Κουδούνι & 2ος όροφος αα'));
    assert_same(200, mb_strlen($p['comments'], 'UTF-8'));
}
function test_lib_builder_pickup_point_sets_shipper_and_requestor() {
    $b = cc_builder();
    $b->use_pickup_point(array('code' => 'S1', 'requestor' => '100-1', 'name' => 'ΚΗΦΙΣΟΣ', 'address' => 'Κηφισού 46', 'postal' => '12241', 'city' => 'ΑΙΓΑΛΕΩ', 'phone' => ''));
    $p = $b->build_payload();
    assert_same('100-1', $p['Requestor']['CarrierBillingAccount']);
    assert_same('S1', $p['Shipper']['CarrierBillingAccount']);
    assert_same('ΚΗΦΙΣΟΣ', $p['Shipper']['CompanyName']);
    assert_same('2101234567', $p['Shipper']['Mobile1'], 'no point phone → main phone');
    assert_same(array('S1', '100-1'), array($b->shipper_account(), $b->requestor_account()));
    $b2 = cc_builder();
    $b2->use_pickup_point(array('code' => 'S2', 'requestor' => 'S2', 'name' => '', 'address' => '', 'postal' => '', 'city' => '', 'phone' => ''));
    assert_contains('Το σημείο αποστολής S2 δεν έχει στοιχεία αποστολέα', (string)$b2->validate_settings());
}

// ── CCLock ──────────────────────────────────────────────────────────────
function test_lib_lock_is_exclusive_and_expires() {
    $db = new FakeDb();
    $a = new CCLock($db, 'oc_');
    $b = new CCLock($db, 'oc_');
    assert_true($a->acquire('voucher_9', 1000));
    assert_false($b->acquire('voucher_9', 1100), 'held');
    $a->release('voucher_9');
    assert_true($b->acquire('voucher_9', 1101), 'free again');
    assert_true($a->acquire('voucher_9', 1101 + CCLock::TTL), 'stale lock taken over');
    $b->release('voucher_9');
    assert_count(1, $db->locks, 'b does not release a lock it lost');
}

// ── Voucher service ─────────────────────────────────────────────────────
function test_lib_service_refuses_while_another_request_holds_the_lock() {
    list($svc, $db, $api) = cc_service();
    $other = new CCLock($db, 'oc_');
    $other->acquire('voucher_55');
    $r = $svc->createForOrder(cc_oc3_order(), array());
    assert_same(array(false, 'busy'), array($r['success'], $r['stage']));
    assert_count(0, $api->calls);
}
function test_lib_service_rechecks_the_voucher_inside_the_lock() {
    list($svc, $db, $api) = cc_service();
    $db->on('/SELECT \* FROM `oc_cc_shipments` WHERE `order_id` = \'55\'/', array(array('order_id' => '55', 'voucher_number' => 'OLD', 'is_voided' => '0')));
    $r = $svc->createForOrder(cc_oc3_order(), array());
    assert_same(array(false, 'exists'), array($r['success'], $r['stage']));
    assert_contains('OLD', $r['error']);
    assert_count(0, $api->calls);
    assert_same(array(), $db->locks);
}
function test_lib_service_creates_from_a_sub_point_billed_to_main() {
    list($svc, $db, $api) = cc_service(cc_points_settings(array(cc_sub_row('S1', 'main'))));
    $r = $svc->createForOrder(cc_oc3_order(array('payment_code' => 'bank_transfer')), array('pickup_point' => 'S1'));
    assert_true($r['success'], json_encode($r, JSON_UNESCAPED_UNICODE));
    $p = cc_sent_payload($api);
    assert_same(array('100-1', 'S1'), array($p['Requestor']['CarrierBillingAccount'], $p['Shipper']['CarrierBillingAccount']));
    $save = implode("\n", $db->matching('/INTO `oc_cc_shipments`|UPDATE `oc_cc_shipments`/'));
    assert_contains("`pickup_point` = 'S1'", $save);
    assert_contains("`requestor_account` = '100-1'", $save);
    $hist = $db->matching('/INSERT INTO `oc_order_history`/');
    assert_contains('Σημείο: Κηφισός (S1) — Χρέωση: 100-1', $hist[0]);
}
function test_lib_service_default_point_and_unknown_point() {
    list($svc, $db, $api) = cc_service(cc_points_settings(array(cc_sub_row('S1')), 'S1'));
    $r = $svc->createForOrder(cc_oc3_order(array('payment_code' => 'bank_transfer')), array('mode' => 'auto'));
    assert_true($r['success']);
    assert_same('S1', cc_sent_payload($api)['Shipper']['CarrierBillingAccount'], 'empty pickup_point → default point');
    list($svc2, , $api2) = cc_service(cc_points_settings(array(cc_sub_row('S1'))));
    $r2 = $svc2->createForOrder(cc_oc3_order(), array('pickup_point' => 'NOPE'));
    assert_same(array(false, 'validation'), array($r2['success'], $r2['stage']));
    assert_count(0, $api2->calls);
}
function test_lib_service_stores_the_boxnow_parcel_id() {
    list($svc, $db) = cc_service(array(), array('create_shipment' => array('Result' => 'Success', 'ShipmentNumber' => 'B1', 'OutboundShipmentNumber' => 'PARCEL9', 'AssignedLockerCode' => 'L77')));
    $r = $svc->createForOrder(cc_oc3_order(array('payment_code' => 'bank_transfer')), array('boxnow' => 1, 'locker_id' => '12', 'locker_code' => 'L12'));
    assert_same('PARCEL9', $r['parcel_id']);
    assert_contains("`boxnow_parcel_id` = 'PARCEL9'", implode("\n", $db->matching('/oc_cc_shipments/')));
    $hist = $db->matching('/INSERT INTO `oc_order_history`/');
    assert_contains('📦 Locker: L77 (ζητήθηκε: L12) | Parcel ID: PARCEL9', $hist[0]);
}
function test_lib_service_transport_error_warns_the_result_is_uncertain() {
    list($svc) = cc_service(array(), array('create_shipment' => array('success' => false, 'error' => 'timeout', 'exception' => true)));
    $r = $svc->createForOrder(cc_oc3_order(), array('mode' => 'bulk'));
    assert_contains('αβέβαιο', $r['error']);
}
function test_lib_service_void_notes_a_failure() {
    list($svc, $db) = cc_service(array(), array('void_shipment' => array('success' => false, 'error' => 'Already delivered')));
    $db->on('/SELECT \* FROM `oc_cc_shipments`/', array(array('order_id' => '55', 'voucher_number' => 'V1', 'is_voided' => '0')));
    $r = $svc->voidForOrder(55);
    assert_same(array(false, 'Already delivered'), array($r['success'], $r['error']));
    assert_contains('❌ Αποτυχία ακύρωσης AWB V1: Already delivered', implode("\n", $db->matching('/oc_order_history/')));
    assert_count(0, $db->matching('/SET `is_voided` = 1/'));
}
function test_lib_service_shipping_code_and_name_for_oc4() {
    $oc402 = array('shipping_method' => array('name' => 'Courier &amp; Co', 'code' => 'courier_center.courier_center'));
    assert_same('courier_center', CCVoucherService::shippingCode($oc402));
    assert_same('Courier & Co', CCVoucherService::shippingName($oc402));
    assert_same('flat', CCVoucherService::shippingCode(array('shipping_method' => '{"code":"flat.flat","name":"Flat"}')));
    assert_same('Flat', CCVoucherService::shippingName(array('shipping_method' => 'Flat', 'shipping_code' => 'flat.flat')));
}
function test_lib_service_status_sets() {
    list($svc) = cc_service(array('config_order_status_id' => '1', 'config_processing_status' => array('2', '3'), 'config_complete_status' => array('5', '3')));
    assert_same(array('active' => array(1, 2), 'complete' => array(5, 3)), $svc->statusSets());
}

// ── Tracker ─────────────────────────────────────────────────────────────
function test_lib_tracker_picks_the_entry_of_the_requested_awb() {
    $res = array('ShipmentDetails' => array(
        array('ShipmentInfo' => array('ShipmentAwb' => 'RET1', 'ShipmentStatus' => '0', 'ShipmentStatusDesc' => 'Return')),
        array('ShipmentInfo' => array('ShipmentAwb' => 'ORIG', 'ShipmentStatus' => '29', 'ShipmentStatusDesc' => 'Παραδόθηκε')),
    ));
    assert_same('29', CCStatusTracker::parse_status($res, 'ORIG')['code']);
    assert_same('0', CCStatusTracker::parse_status($res, 'X')['code'], 'unknown AWB → first entry');
}
function test_lib_tracker_never_completes_a_cancelled_order() {
    $db = cc_tracker_db(7); // 7 = cancelled: neither active nor complete
    $api = new FakeApi(array('get_shipment_details' => cc_details('29', 'Παραδόθηκε')));
    $r = (new CCStatusTracker($db, 'oc_', $api, 5, array('active' => array(1, 2), 'complete' => array(5))))->refresh(array('order_id' => '7', 'voucher_number' => 'A', 'status_code' => '10'));
    assert_false($r['completed']);
    assert_count(0, $db->matching("/UPDATE `oc_order` SET `order_status_id`/"));
}
function test_lib_tracker_completes_a_stuck_delivered_order() {
    $db = cc_tracker_db(2);
    $api = new FakeApi(array('get_shipment_details' => cc_details('29', 'Παραδόθηκε')));
    $r = (new CCStatusTracker($db, 'oc_', $api, 5, array('active' => array(1, 2), 'complete' => array(5))))->refresh(array('order_id' => '7', 'voucher_number' => 'A', 'status_code' => '29'));
    assert_false($r['changed']);
    assert_true($r['completed'], 'same code, order still active → completed now');
}
function test_lib_tracker_cron_skips_inactive_orders_and_limits() {
    $db = new FakeDb();
    (new CCStatusTracker($db, 'oc_', new FakeApi(), 5, array('active' => array(1, 2), 'complete' => array(5))))->run(1800000000, 0);
    $sql = $db->queries[0];
    assert_contains('JOIN `oc_order` o ON o.`order_id` = s.`order_id`', $sql);
    assert_contains('o.`order_status_id` IN (1,2,5)', $sql);
    assert_contains('LIMIT 200', $sql);
}
function test_lib_tracker_wait_minutes() {
    assert_same(0, CCStatusTracker::wait_minutes(array('last_checked_at' => null), 5000));
    assert_same(59, CCStatusTracker::wait_minutes(array('last_checked_at' => 5000 - 60), 5000));
    assert_same(0, CCStatusTracker::wait_minutes(array('last_checked_at' => 5000 - 3600), 5000));
}

// ── Schema ──────────────────────────────────────────────────────────────
function test_lib_schema_ensure_once_skips_when_current() {
    $db = new FakeDb();
    $db->on("/SELECT `value` FROM `oc_setting` WHERE `store_id` = '0' AND `key` = 'shipping_courier_center_schema_version'/", array(array('value' => CCDbSchema::VERSION)));
    assert_same(array(), CCDbSchema::ensureOnce($db, 'oc_'));
    assert_count(1, $db->queries);
    $db2 = new FakeDb();
    assert_count(3, CCDbSchema::ensureOnce($db2, 'oc_'), 'no version → full ensure');
    $cols = CCDbSchema::columns('cc_shipments');
    foreach (array('pickup_point', 'requestor_account', 'boxnow_parcel_id') as $c) {
        assert_true(isset($cols[$c]), $c);
    }
}
