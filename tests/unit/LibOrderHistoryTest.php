<?php
require_once CC_REPO . '/tests/support/FakeDb.php';
require_once CC_LIB . '/CCOrderHistory.php';

use Opencart\Extension\Couriercenter\Library\CCOrderHistory;

/** FakeDb whose order has $status (null = order not found). */
function cc_history_db($status) {
    $db = new FakeDb();
    $db->on('/SELECT `order_status_id` FROM `oc_order`/', $status === null ? array() : array(array('order_status_id' => (string)$status)));
    return $db;
}

function test_lib_history_note_keeps_the_current_status() {
    $db = cc_history_db(2);
    assert_true((new CCOrderHistory($db, 'oc_'))->add(7, 'σημείωση'));
    $ins = $db->matching('/INSERT INTO `oc_order_history`/');
    assert_count(1, $ins);
    assert_contains("`order_id` = '7'", $ins[0]);
    assert_contains("`order_status_id` = '2'", $ins[0]);
    assert_contains("`notify` = '0'", $ins[0]);
    assert_contains('σημείωση', $ins[0]);
    assert_count(0, $db->matching('/UPDATE `oc_order`/'));
}
function test_lib_history_with_a_new_status_moves_the_order() {
    $db = cc_history_db(2);
    assert_true((new CCOrderHistory($db, 'oc_'))->add(7, 'παραδόθηκε', 5));
    $ins = $db->matching('/INSERT INTO `oc_order_history`/');
    assert_contains("`order_status_id` = '5'", $ins[0]);
    $upd = $db->matching('/UPDATE `oc_order` SET `order_status_id`/');
    assert_count(1, $upd);
    assert_contains("`order_status_id` = '5'", $upd[0]);
}
function test_lib_history_same_status_does_not_touch_the_order() {
    $db = cc_history_db(5);
    (new CCOrderHistory($db, 'oc_'))->add(7, 'x', 5);
    assert_count(0, $db->matching('/UPDATE `oc_order`/'));
}
function test_lib_history_skips_missing_and_abandoned_orders() {
    assert_false((new CCOrderHistory(cc_history_db(null), 'oc_'))->add(7, 'x'));
    $db = cc_history_db(0);
    assert_false((new CCOrderHistory($db, 'oc_'))->add(7, 'x'));
    assert_count(0, $db->matching('/INSERT/'));
    assert_false((new CCOrderHistory(cc_history_db(2), 'oc_'))->add(0, 'x'));
}
function test_lib_history_uses_the_table_prefix_and_escapes_the_comment() {
    $db = new FakeDb();
    $db->on('/FROM `shop_order`/', array(array('order_status_id' => '1')));
    (new CCOrderHistory($db, 'shop_'))->add(3, "O'Reilly");
    $ins = $db->matching('/INSERT INTO `shop_order_history`/');
    assert_contains("O\\'Reilly", $ins[0]);
}
