<?php
/** A user that can open Courier Center but not modify it can print, not void (spec 5.12). */
return function (SmokeContext $c) {
    $s = $c->shop;
    if (empty($c->data['orders']['bank'])) {
        throw new RuntimeException('run the "actions" section first');
    }
    $perm = json_encode(array('access' => array('extension/shipping/courier_center', 'sale/order'), 'modify' => array()));
    $s->exec("DELETE FROM `{p}user` WHERE `username` = 'ccreader'");
    $s->exec("DELETE FROM `{p}user_group` WHERE `name` = 'cc-readonly'");
    $s->exec("INSERT INTO `{p}user_group` SET `name` = 'cc-readonly', `permission` = '" . $s->db()->real_escape_string($perm) . "'");
    $gid = (int)$s->db()->insert_id;
    $s->exec("INSERT INTO `{p}user` SET `user_group_id` = '$gid', `username` = 'ccreader', `password` = '" . md5('reader123') . "', `salt` = '',
        `firstname` = 'Read', `lastname` = 'Only', `email` = 'reader@example.com', `image` = '', `code` = '', `ip` = '', `status` = 1, `date_added` = NOW()");
    $reader = new SmokeShop($s->env);
    $reader->login('ccreader', 'reader123');
    $deny = $reader->adminJson('extension/shipping/courier_center/voidShipment', array('order_id' => $c->data['orders']['bank']));
    $c->ok(empty($deny['success']) && strpos($deny['error'], 'δικαίωμα') !== false, 'read-only user cannot void (spec 10 #5)');
    $pdf = $reader->adminGet('extension/shipping/courier_center/downloadVoucher', array('order_id' => $c->data['orders']['bank']));
    $c->ok(substr($pdf['body'], 0, 4) === '%PDF', 'read-only user can print');
};
