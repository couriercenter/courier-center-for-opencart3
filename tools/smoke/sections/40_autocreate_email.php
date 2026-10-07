<?php
/** Status change through the catalog API -> auto-create voucher (sort 50) -> tracking email (sort 99) (spec 5.7). */
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
    $c->ok(!empty($row['voucher_number']), 'auto-create made a voucher on the status change');
    $c->track(isset($row['voucher_number']) ? $row['voucher_number'] : '');
    $c->ok((bool)$s->row("SELECT 1 AS x FROM `{p}order_history` WHERE `order_id` = '$order' AND `comment` LIKE '%Αυτόματη δημιουργία voucher%'"), 'auto-create history note');
    $c->ok((bool)$s->row("SELECT 1 AS x FROM `{p}order_history` WHERE `order_id` = '$flat' AND `comment` LIKE '%δεν διαχειρίζεται%'"), 'flat-rate order skipped with a note');
    $mail = cc_mailpit_find('Στοιχεία Αποστολής — Παραγγελία #' . $order);
    $c->ok($mail !== null && strpos(json_encode(isset($mail['To']) ? $mail['To'] : array()), 'customer@example.com') !== false, 'tracking email reached the customer (Mailpit)');

    $set('auto_create_enabled', '0');
};
