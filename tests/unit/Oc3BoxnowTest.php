<?php
require_once CC_OC3 . '/CCBoxnow.php';

use Opencart\Extension\Couriercenter\Oc3\CCBoxnow;

function test_oc3_boxnow_widget_only_on_checkout_pages() {
    foreach (array('checkout/checkout', 'extension/quickcheckout/checkout', 'journal3/checkout', 'checkout/onepagecheckout') as $route) {
        assert_true(CCBoxnow::routeWantsWidget($route), $route);
    }
    foreach (array('', 'common/home', 'checkout/cart', 'checkout/success', 'checkout/failure', 'checkout/voucher', 'product/product') as $route) {
        assert_false(CCBoxnow::routeWantsWidget($route), $route);
    }
}
/** Review Focus 1: an incomplete choice must not leave the order without a mode. */
function test_oc3_boxnow_half_selection_becomes_auto() {
    $a = CCBoxnow::sanitizeSelection(array('selected' => '1', 'delivery_mode' => ''));
    assert_same('auto', $a['delivery_mode']);
    $b = CCBoxnow::sanitizeSelection(array('selected' => '1', 'delivery_mode' => 'pick', 'locker_id' => ''));
    assert_same('auto', $b['delivery_mode']);
    assert_same(null, CCBoxnow::sanitizeSelection(array('selected' => '')));
    assert_same(null, CCBoxnow::sanitizeSelection(array()));
}
function test_oc3_boxnow_pick_keeps_the_locker_and_decodes_the_name() {
    $s = CCBoxnow::sanitizeSelection(array('selected' => '1', 'delivery_mode' => 'pick', 'locker_id' => '123', 'locker_name' => 'Σόλωνος 5 &amp; Ακαδημίας'));
    assert_same(array('selected' => true, 'delivery_mode' => 'pick', 'locker_id' => '123', 'locker_code' => '123', 'locker_name' => 'Σόλωνος 5 & Ακαδημίας'), $s);
}
function test_oc3_boxnow_clips_long_input() {
    $s = CCBoxnow::sanitizeSelection(array('selected' => '1', 'delivery_mode' => 'pick', 'locker_id' => str_repeat('9', 300), 'locker_name' => str_repeat('α', 400)));
    assert_same(64, strlen($s['locker_id']));
    assert_same(255, mb_strlen($s['locker_name'], 'UTF-8'));
}
function test_oc3_boxnow_merges_into_the_custom_field() {
    $cf = CCBoxnow::applyToCustomField('{"5":"ΑΦΜ"}', array('selected' => true, 'delivery_mode' => 'auto', 'locker_id' => '', 'locker_code' => '', 'locker_name' => ''));
    assert_same(array('5' => 'ΑΦΜ', 'cc_delivery_mode' => 'auto', 'cc_locker_id' => '', 'cc_locker_code' => '', 'cc_locker_name' => ''), $cf);
    assert_same('pick', CCBoxnow::applyToCustomField(null, array('selected' => true, 'delivery_mode' => 'pick', 'locker_id' => '1', 'locker_code' => '1', 'locker_name' => 'x'))['cc_delivery_mode']);
}
function test_oc3_boxnow_method_allowed() {
    assert_true(CCBoxnow::methodAllowed('courier_center.courier_center_cod', array('courier_center')));
    assert_false(CCBoxnow::methodAllowed('flat.flat', array('courier_center')));
    assert_false(CCBoxnow::methodAllowed('', array('courier_center')));
}
/** Admin order edit (api/order/edit → editOrder) rewrites custom_field from the customer data: keep the BOX NOW choice. */
function test_oc3_boxnow_kept_when_the_admin_edits_the_order() {
    $old = '{"5":"ΑΦΜ","cc_delivery_mode":"pick","cc_locker_id":"42","cc_locker_code":"42","cc_locker_name":"Σόλωνος 5"}';
    assert_same(array('5' => 'ΑΦΜ', 'cc_delivery_mode' => 'pick', 'cc_locker_id' => '42', 'cc_locker_code' => '42', 'cc_locker_name' => 'Σόλωνος 5'),
        CCBoxnow::keepOnEdit($old, array('5' => 'ΑΦΜ')));
    assert_same(array('cc_delivery_mode' => 'auto', 'cc_locker_id' => '', 'cc_locker_code' => '', 'cc_locker_name' => ''),
        CCBoxnow::keepOnEdit('{"cc_delivery_mode":"auto","cc_locker_id":"","cc_locker_code":"","cc_locker_name":""}', array()));
}
function test_oc3_boxnow_keep_on_edit_leaves_orders_without_box_now_alone() {
    assert_same(array('5' => 'ΑΦΜ'), CCBoxnow::keepOnEdit('{"5":"old"}', array('5' => 'ΑΦΜ')));
    assert_same(array(), CCBoxnow::keepOnEdit('', array()));
    assert_same(array(), CCBoxnow::keepOnEdit(null, null));
    // a choice already in the new data wins
    assert_same(array('cc_delivery_mode' => 'auto', 'cc_locker_id' => '', 'cc_locker_code' => '', 'cc_locker_name' => ''),
        CCBoxnow::keepOnEdit('{"cc_delivery_mode":"pick","cc_locker_id":"42","cc_locker_code":"42","cc_locker_name":"x"}',
            array('cc_delivery_mode' => 'auto', 'cc_locker_id' => '', 'cc_locker_code' => '', 'cc_locker_name' => '')));
}
