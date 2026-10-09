<?php
/**
 * Status change through the catalog API -> auto-create voucher (sort 50). 1.1.0: the AWB travels inside the shop's own
 * order emails (order_edit / order_add), no separate "Στοιχεία Αποστολής" email (like WooCommerce).
 */
return function (SmokeContext $c) {
    $s = $c->shop;
    $set = function ($key, $value) use ($s) {
        $s->exec("DELETE FROM `{p}setting` WHERE `key` = 'shipping_courier_center_$key'");
        $s->exec("INSERT INTO `{p}setting` SET `store_id` = 0, `code` = 'shipping_courier_center', `key` = 'shipping_courier_center_$key', `value` = '" . $s->db()->real_escape_string($value) . "', `serialized` = 0");
    };
    $set('auto_create_enabled', '1');
    $set('auto_create_status_id', '2');
    cc_mailpit_clear();

    $order = $s->createApiOrder('courier_center.courier_center', 'bank_transfer', array(43 => 1));
    $flat = $s->createApiOrder('flat.flat', 'bank_transfer');
    $s->apiOrderHistory($order, 2, true, 'smoke auto-create');
    $s->apiOrderHistory($flat, 2, false);

    $row = $s->row("SELECT `voucher_number` FROM `{p}cc_shipments` WHERE `order_id` = '$order'");
    $awb = isset($row['voucher_number']) ? $row['voucher_number'] : '';
    $c->ok($awb !== '', 'auto-create made a voucher on the status change');
    $c->track($awb);
    $c->ok((bool)$s->row("SELECT 1 AS x FROM `{p}order_history` WHERE `order_id` = '$order' AND `comment` LIKE '%Αυτόματη δημιουργία voucher%'"), 'auto-create history note');
    $c->ok((bool)$s->row("SELECT 1 AS x FROM `{p}order_history` WHERE `order_id` = '$flat' AND `comment` LIKE '%δεν διαχειρίζεται%'"), 'flat-rate order skipped with a note');
    $c->ok(cc_mailpit_find('Στοιχεία Αποστολής — Παραγγελία #' . $order) === null, 'no separate tracking email any more');

    // The next status email of the order carries the AWB and the tracking link.
    $s->apiOrderHistory($order, 3, true, 'smoke shipped');
    $c->ok($awb !== '' && cc_mail_with(array($awb, 'Στοιχεία Αποστολής Courier Center', 'tracknr=' . $awb)), 'order status email contains the AWB + tracking link');

    $set('auto_create_enabled', '0');
};
