<?php
require_once CC_REPO . '/tests/support/FakeDb.php';
require_once CC_OC3 . '/CCSchema.php';

use Opencart\Extension\Couriercenter\Oc3\CCSchema;

function test_oc3_schema_creates_the_table_mysql55_safe() {
    $db = new FakeDb();
    $done = CCSchema::ensure($db, 'shop_');
    $create = $db->matching('/CREATE TABLE IF NOT EXISTS `shop_cc_shipments`/');
    assert_count(1, $create);
    foreach (array('`order_id` INT(11) NOT NULL', '`created_at` DATETIME DEFAULT NULL', 'UNIQUE KEY `order_id` (`order_id`)', 'PRIMARY KEY (`id`)', "`return_option` VARCHAR(16) NOT NULL DEFAULT 'none'") as $part) {
        assert_contains($part, $create[0]);
    }
    assert_not_contains('CURRENT_TIMESTAMP', $create[0]);
    assert_same(array('table created: cc_shipments', 'table created: cc_locks', 'table created: cc_return_requests'), $done);
    assert_count(1, $db->matching("/INSERT INTO `shop_setting` SET `store_id` = '0', `code` = 'courier_center_state', `key` = 'shipping_courier_center_schema_version'/"));
}
function test_oc3_schema_adds_only_missing_columns() {
    $db = new FakeDb();
    $db->on("/SHOW TABLES LIKE 'oc_cc_/", array(array('t' => 'exists')));
    $db->on('/SHOW COLUMNS FROM `oc_cc_(locks|return_requests)`/', function ($sql, $m) {
        $rows = array();
        foreach (array_keys(\Opencart\Extension\Couriercenter\Library\CCDbSchema::columns('cc_' . $m[1])) as $c) {
            $rows[] = array('Field' => $c);
        }
        return $rows;
    });
    $have = array();
    foreach (array_keys(CCSchema::columns()) as $c) {
        if (!in_array($c, array('locker_name', 'created_at'), true)) {
            $have[] = array('Field' => $c);
        }
    }
    $db->on('/SHOW COLUMNS FROM `oc_cc_shipments`/', $have);
    $done = CCSchema::ensure($db, 'oc_');
    assert_count(0, $db->matching('/CREATE TABLE/'));
    assert_count(2, $db->matching('/ALTER TABLE `oc_cc_shipments` ADD/'));
    assert_same(array('column added: cc_shipments.locker_name', 'column added: cc_shipments.created_at'), $done);
}
function test_oc3_schema_events_match_the_spec() {
    $events = CCSchema::events();
    assert_count(16, $events);
    assert_same(array('admin/view/sale/order_info/before', 'extension/shipping/courier_center/order/orderTab', 0), $events['courier_center_order_panel']);
    assert_same(array('catalog/model/checkout/order/addOrderHistory/after', 'extension/shipping/courier_center/autocreate/autoCreate', 50), $events['courier_center_auto_create']);
    assert_same(array('catalog/view/mail/order_add/after', 'extension/shipping/courier_center/email/orderAdd', 0), $events['courier_center_mail_add']);
    assert_same(array('catalog/view/mail/order_edit/after', 'extension/shipping/courier_center/email/orderEdit', 0), $events['courier_center_mail_edit']);
    assert_same(array('catalog/view/account/order_info/after', 'extension/shipping/courier_center/returns/orderInfo', 0), $events['courier_center_returns_order']);
    assert_false(isset($events['courier_center_email']), '1.1.0: no separate tracking email');
    assert_same(array('catalog/view/common/footer/after', 'extension/shipping/courier_center/boxnow/widget', 0), $events['courier_center_boxnow_widget']);
    assert_same(array('catalog/controller/checkout/success/before', 'extension/shipping/courier_center/boxnow/clearSession', 0), $events['courier_center_boxnow_clear']);
    foreach ($events as $code => $e) {
        assert_same(0, strpos($e[1], 'extension/shipping/courier_center/'), $code);
        assert_false(strpos($e[1], '.') !== false, "$code: OpenCart 3 actions use '/', not '.'");
    }
}
function test_oc3_schema_reregisters_only_our_events() {
    $db = new FakeDb();
    CCSchema::ensureEvents($db, 'oc_');
    $del = $db->matching('/DELETE FROM `oc_event`/');
    assert_count(1, $del);
    assert_contains("'courier_center_order_panel'", $del[0]);
    assert_not_contains('%', $del[0]);
    $ins = $db->matching('/INSERT INTO `oc_event`/');
    assert_count(16, $ins);
    assert_contains("'courier_center_email'", $del[0], 'the 1.0.0 email event is removed on repair');
    assert_contains("`status` = '1'", $ins[0]);
    CCSchema::removeEvents($db, 'oc_');
    assert_count(2, $db->matching('/DELETE FROM `oc_event`/'));
}
