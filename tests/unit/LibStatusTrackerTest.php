<?php
require_once CC_REPO . '/tests/support/FakeDb.php';
require_once CC_REPO . '/tests/support/FakeApi.php';
require_once CC_LIB . '/CCOrderHistory.php';
require_once CC_LIB . '/CCReturnsRules.php';
require_once CC_LIB . '/CCStatusTracker.php';

use Opencart\Extension\Couriercenter\Library\CCStatusTracker;

/** GetShipmentDetails response as the WooCommerce plugin reads it. */
function cc_details($code, $desc, $action = '', array $extra = array()) {
    return array('ShipmentDetails' => array(array('ShipmentInfo' => $extra + array(
        'ShipmentStatus' => $code, 'ShipmentStatusDesc' => $desc, 'CollectionStatus' => $action))));
}
function cc_tracker_db($order_status = 2) {
    $db = new FakeDb();
    $db->on('/SELECT `order_status_id` FROM `oc_order`/', array(array('order_status_id' => (string)$order_status)));
    return $db;
}

function test_lib_tracker_reads_the_nested_shipment_info() {
    assert_same(array('code' => '29', 'desc' => 'Παραδόθηκε', 'action' => 'OK', 'futile' => false), CCStatusTracker::parse_status(cc_details(29, 'Παραδόθηκε', 'OK')));
}
function test_lib_tracker_falls_back_to_top_level_keys() {
    assert_same(array('code' => '10', 'desc' => 'Σε διανομή', 'action' => '', 'futile' => false), CCStatusTracker::parse_status(array('StatusCode' => '10', 'StatusDescription' => 'Σε διανομή')));
    assert_same(array('code' => '', 'desc' => '', 'action' => '', 'futile' => false), CCStatusTracker::parse_status(array('Result' => 'Success')));
}
function test_lib_tracker_stores_a_changed_status_writes_history_and_auto_completes() {
    $db = cc_tracker_db(2);
    $api = new FakeApi(array('get_shipment_details' => cc_details('29', 'Παραδόθηκε')));
    $r = (new CCStatusTracker($db, 'oc_', $api, 5))->refresh(array('order_id' => '7', 'voucher_number' => 'AWB1', 'status_code' => '10'), 'Bulk');
    assert_true($r['success'] && $r['changed'] && $r['final'] && $r['completed']);
    $upd = $db->matching('/UPDATE `oc_cc_shipments`/');
    assert_contains("`status_code` = '29'", $upd[0]);
    assert_contains("`is_final` = '1'", $upd[0]);
    $hist = $db->matching('/INSERT INTO `oc_order_history`/');
    assert_count(2, $hist);
    assert_contains('📍 [Bulk] Courier Center status: 29 — Παραδόθηκε', $hist[0]);
    assert_contains("`order_status_id` = '5'", $hist[1]);
    assert_count(1, $db->matching("/UPDATE `oc_order` SET `order_status_id` = '5'/"));
}
function test_lib_tracker_same_status_adds_no_history() {
    $db = cc_tracker_db(2);
    $api = new FakeApi(array('get_shipment_details' => cc_details('10', 'Σε διανομή')));
    $r = (new CCStatusTracker($db, 'oc_', $api, 5))->refresh(array('order_id' => 7, 'voucher_number' => 'AWB1', 'status_code' => '10'));
    assert_true($r['success']);
    assert_false($r['changed']);
    assert_count(0, $db->matching('/INSERT INTO `oc_order_history`/'));
}
function test_lib_tracker_no_auto_complete_when_disabled_or_already_there() {
    $api = new FakeApi(array('get_shipment_details' => cc_details('87', 'Παραδόθηκε σε locker')));
    $db = cc_tracker_db(2);
    assert_false((new CCStatusTracker($db, 'oc_', $api, 0))->refresh(array('order_id' => 7, 'voucher_number' => 'A'))['completed']);
    $db = cc_tracker_db(5);
    assert_false((new CCStatusTracker($db, 'oc_', $api, 5))->refresh(array('order_id' => 7, 'voucher_number' => 'A'))['completed']);
}
function test_lib_tracker_api_error_changes_nothing() {
    $db = cc_tracker_db();
    $api = new FakeApi(array('get_shipment_details' => array('success' => false, 'error' => 'HTTP 500')));
    $r = (new CCStatusTracker($db, 'oc_', $api))->refresh(array('order_id' => 7, 'voucher_number' => 'A'));
    assert_false($r['success']);
    assert_same('HTTP 500', $r['error']);
    assert_count(0, $db->matching('/UPDATE/'));
}
function test_lib_tracker_empty_status_only_marks_the_check() {
    $db = cc_tracker_db();
    $api = new FakeApi(array('get_shipment_details' => array('Result' => 'Success')));
    $r = (new CCStatusTracker($db, 'oc_', $api))->refresh(array('order_id' => 7, 'voucher_number' => 'A', 'status_code' => '10'));
    assert_false($r['success']);
    $upd = $db->matching('/UPDATE `oc_cc_shipments`/');
    assert_count(1, $upd);
    assert_contains('`last_checked_at`', $upd[0]);
    assert_not_contains('`status_code`', $upd[0]);
}
function test_lib_tracker_run_skips_recent_checks() {
    $now = 1800000000;
    $db = cc_tracker_db();
    $db->on('/SELECT s\.\* FROM `oc_cc_shipments` s WHERE s\.`is_voided` = 0/', array(
        array('order_id' => '1', 'voucher_number' => 'A', 'status_code' => '', 'last_checked_at' => (string)($now - 100)),
        array('order_id' => '2', 'voucher_number' => 'B', 'status_code' => '', 'last_checked_at' => (string)($now - 4000)),
        array('order_id' => '3', 'voucher_number' => 'C', 'status_code' => '', 'last_checked_at' => null),
    ));
    $api = new FakeApi(array('get_shipment_details' => cc_details('10', 'Σε διανομή')));
    $r = (new CCStatusTracker($db, 'oc_', $api))->run($now, 0);
    assert_same(array(2, 2, 1, 0), array($r['checked'], $r['updated'], $r['skipped'], $r['failed']));
    assert_count(2, $api->calls);
    assert_contains('[Cron]', implode("\n", $db->matching('/INSERT INTO `oc_order_history`/')));
}
function test_lib_tracker_static_code_helpers_match_oc4_1_0_1_call_sites() {
    // OC4 v1.0.1 controllers and cron call these statics; the shared file must keep them.
    assert_true(CCStatusTracker::is_final('29') && CCStatusTracker::is_final('95'));
    assert_false(CCStatusTracker::is_final('10') || CCStatusTracker::is_final(''));
    assert_true(CCStatusTracker::is_delivered('87'));
    assert_false(CCStatusTracker::is_delivered('25'));
}

// ── «Κατάσταση όταν παραλαμβάνει ο courier» (1.1.3) ──
function cc_pickup_tracker($db, $api, array $pickup, $auto = 5, array $sets = array('active' => array(1, 2), 'complete' => array(5))) {
    return new CCStatusTracker($db, 'oc_', $api, $auto, $sets, null, $pickup);
}
function cc_recent_shipment(array $extra = array()) {
    return $extra + array('order_id' => '7', 'voucher_number' => 'AWB1', 'status_code' => '0', 'picked_up_at' => null,
        'created_at' => date('Y-m-d H:i:s', time() - 3600));
}
function test_lib_tracker_pickup_off_leaves_the_order_alone() {
    $db = cc_tracker_db(2);
    $api = new FakeApi(array('get_shipment_details' => cc_details('15', 'NewShipment', '10')));
    $r = (new CCStatusTracker($db, 'oc_', $api, 5))->refresh(cc_recent_shipment());
    assert_false($r['picked_up']);
    assert_count(0, $db->matching('/`picked_up_at` = NOW\(\)/'));
    assert_count(0, $db->matching('/UPDATE `oc_order` SET/'));
}
function test_lib_tracker_pickup_moves_the_order_once_and_notifies() {
    $db = cc_tracker_db(2);
    $api = new FakeApi(array('get_shipment_details' => cc_details('15', 'NewShipment', '10')));
    $sent = array();
    $r = cc_pickup_tracker($db, $api, array('status_id' => 3, 'on_picked_up' => function ($id) use (&$sent) { $sent[] = $id; }))
        ->refresh(cc_recent_shipment(), 'Cron');
    assert_true($r['picked_up']);
    assert_same(array(7), $sent);
    assert_count(1, $db->matching("/UPDATE `oc_cc_shipments` SET `picked_up_at` = NOW\(\) WHERE `order_id` = '7'/"));
    assert_count(1, $db->matching("/UPDATE `oc_order` SET `order_status_id` = '3'/"));
    $hist = $db->matching("/INSERT INTO `oc_order_history`.*`order_status_id` = '3'/");
    assert_count(1, $hist);
    assert_contains("`notify` = '1'", $hist[0]);
    assert_contains('[Cron] Courier Center: Ο courier παρέλαβε την αποστολή AWB1', $hist[0]);
}
function test_lib_tracker_pickup_happens_once_per_shipment() {
    $db = cc_tracker_db(2);
    $api = new FakeApi(array('get_shipment_details' => cc_details('20', 'Στον σταθμό', '10')));
    $r = cc_pickup_tracker($db, $api, array('status_id' => 3))->refresh(cc_recent_shipment(array('picked_up_at' => '2026-10-29 10:00:00')));
    assert_false($r['picked_up']);
    assert_count(0, $db->matching('/`picked_up_at` = NOW\(\)/'));
    assert_count(0, $db->matching('/UPDATE `oc_order` SET/'));
}
function test_lib_tracker_pickup_without_notify_and_for_old_vouchers_sends_no_email() {
    $api = new FakeApi(array('get_shipment_details' => cc_details('15', 'NewShipment', '10')));
    $sent = 0;
    $cb = function () use (&$sent) { $sent++; };
    $db = cc_tracker_db(2);
    assert_true(cc_pickup_tracker($db, $api, array('status_id' => 3))->refresh(cc_recent_shipment())['picked_up']);
    assert_contains("`notify` = '0'", $db->matching("/INSERT INTO `oc_order_history`/")[0]);
    $db = cc_tracker_db(2);
    $old = cc_recent_shipment(array('created_at' => date('Y-m-d H:i:s', time() - 8 * 86400)));
    assert_true(cc_pickup_tracker($db, $api, array('status_id' => 3, 'on_picked_up' => $cb))->refresh($old)['picked_up']);
    assert_same(0, $sent);
    assert_contains("`notify` = '0'", $db->matching("/INSERT INTO `oc_order_history`/")[0]);
}
function test_lib_tracker_pickup_skips_orders_that_are_not_active() {
    $api = new FakeApi(array('get_shipment_details' => cc_details('15', 'NewShipment', '10')));
    foreach (array(7, 5, 3) as $status) { // cancelled, complete, already in the pickup status
        $db = cc_tracker_db($status);
        assert_false(cc_pickup_tracker($db, $api, array('status_id' => 3))->refresh(cc_recent_shipment())['picked_up']);
        assert_count(0, $db->matching('/UPDATE `oc_order` SET/'));
        assert_count(1, $db->matching('/`picked_up_at` = NOW\(\)/')); // remembered: a later manual change is never undone
    }
}
function test_lib_tracker_pickup_ignores_empty_and_cancelled_codes() {
    foreach (array('0', '99', '14', '25') as $code) {
        $db = cc_tracker_db(2);
        $api = new FakeApi(array('get_shipment_details' => cc_details($code, 'x', $code === '0' ? '2' : '14')));
        assert_false(cc_pickup_tracker($db, $api, array('status_id' => 3))->refresh(cc_recent_shipment())['picked_up']);
        assert_count(0, $db->matching('/`picked_up_at` = NOW\(\)/'));
    }
}
function test_lib_tracker_delivered_first_completes_without_the_pickup_step() {
    $db = cc_tracker_db(2);
    $api = new FakeApi(array('get_shipment_details' => cc_details('29', 'Παραδόθηκε')));
    $sent = 0;
    $r = cc_pickup_tracker($db, $api, array('status_id' => 3, 'on_picked_up' => function () use (&$sent) { $sent++; }))->refresh(cc_recent_shipment());
    assert_false($r['picked_up']);
    assert_true($r['completed']);
    assert_same(0, $sent);
    assert_count(1, $db->matching('/`picked_up_at` = NOW\(\)/'));
    assert_count(0, $db->matching("/UPDATE `oc_order` SET `order_status_id` = '3'/"));
}
function test_lib_tracker_an_order_in_the_pickup_status_is_still_completed_on_delivery() {
    // «Εστάλη» is neither an active nor a complete status of this shop: the delivery still completes it
    $db = cc_tracker_db(3);
    $api = new FakeApi(array('get_shipment_details' => cc_details('29', 'Παραδόθηκε')));
    $r = cc_pickup_tracker($db, $api, array('status_id' => 3))->refresh(cc_recent_shipment(array('picked_up_at' => '2026-10-29 10:00:00')));
    assert_true($r['completed']);
    $db->on('/SELECT s\.\* FROM `oc_cc_shipments` s JOIN/', array());
    cc_pickup_tracker($db, $api, array('status_id' => 3))->run(time(), 0);
    assert_contains("IN (1,2,3,5)", implode("\n", $db->matching('/SELECT s\.\* FROM `oc_cc_shipments` s JOIN/')));
}
function test_lib_tracker_pickup_needs_the_collection_closed() {
    // Live 29/10/2026: every voucher is 15 «NewShipment» a few minutes after creation, with CollectionStatus
    // 2 «ConfirmedOrder» until the courier scans it (then 10 «Close»); voided ones are 15 with 14 «Cancel».
    foreach (array('2', '14', '') as $collection) {
        $db = cc_tracker_db(2);
        $api = new FakeApi(array('get_shipment_details' => cc_details('15', 'NewShipment', $collection)));
        assert_false(cc_pickup_tracker($db, $api, array('status_id' => 3))->refresh(cc_recent_shipment())['picked_up']);
        assert_count(0, $db->matching('/`picked_up_at` = NOW\(\)/'));
        assert_count(0, $db->matching('/UPDATE `oc_order` SET/'));
    }
}
function test_lib_tracker_a_futile_visit_is_not_a_pickup() {
    $db = cc_tracker_db(2);
    $api = new FakeApi(array('get_shipment_details' => cc_details('15', 'NewShipment', '10', array('Reference2' => 'Ασκοπη προσελευση'))));
    assert_false(cc_pickup_tracker($db, $api, array('status_id' => 3))->refresh(cc_recent_shipment())['picked_up']);
    assert_count(0, $db->matching('/`picked_up_at` = NOW\(\)/'));
}
function test_lib_tracker_is_picked_up_rules() {
    assert_true(CCStatusTracker::is_picked_up('15', '10'));
    assert_true(CCStatusTracker::is_picked_up('29', '2'));
    assert_true(CCStatusTracker::is_picked_up('87'));
    assert_false(CCStatusTracker::is_picked_up('15', '2'));
    assert_false(CCStatusTracker::is_picked_up('15'));
    assert_false(CCStatusTracker::is_picked_up('15', '10', true));
    assert_false(CCStatusTracker::is_picked_up('99', '10'));
    assert_false(CCStatusTracker::is_picked_up('0', '10'));
}
