<?php
require_once CC_REPO . '/tests/support/FakeDb.php';
require_once CC_REPO . '/tests/support/FakeApi.php';
require_once CC_LIB . '/CCOrderHistory.php';
require_once CC_LIB . '/CCStatusTracker.php';

use Opencart\Extension\Couriercenter\Library\CCStatusTracker;

/** GetShipmentDetails response as the WooCommerce plugin reads it. */
function cc_details($code, $desc, $action = '') {
    return array('ShipmentDetails' => array(array('ShipmentInfo' => array(
        'ShipmentStatus' => $code, 'ShipmentStatusDesc' => $desc, 'CollectionStatus' => $action))));
}
function cc_tracker_db($order_status = 2) {
    $db = new FakeDb();
    $db->on('/SELECT `order_status_id` FROM `oc_order`/', array(array('order_status_id' => (string)$order_status)));
    return $db;
}

function test_lib_tracker_reads_the_nested_shipment_info() {
    assert_same(array('code' => '29', 'desc' => 'Παραδόθηκε', 'action' => 'OK'), CCStatusTracker::parse_status(cc_details(29, 'Παραδόθηκε', 'OK')));
}
function test_lib_tracker_falls_back_to_top_level_keys() {
    assert_same(array('code' => '10', 'desc' => 'Σε διανομή', 'action' => ''), CCStatusTracker::parse_status(array('StatusCode' => '10', 'StatusDescription' => 'Σε διανομή')));
    assert_same(array('code' => '', 'desc' => '', 'action' => ''), CCStatusTracker::parse_status(array('Result' => 'Success')));
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
    $db->on('/SELECT \* FROM `oc_cc_shipments` WHERE `is_voided` = 0/', array(
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
