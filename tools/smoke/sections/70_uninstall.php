<?php
/** Uninstall keeps the voucher history and the cron key (spec 8 #1); reinstall brings the events back. */
return function (SmokeContext $c) {
    $s = $c->shop;
    $s->login();
    $before = count($s->rows("SELECT `id` FROM `{p}cc_shipments`"));
    $k = $s->row("SELECT `value` FROM `{p}setting` WHERE `key` = 'shipping_courier_center_cron_key'");
    $key = isset($k['value']) ? $k['value'] : '';
    $events = function () use ($s) {
        return count($s->rows("SELECT `code` FROM `{p}event` WHERE `code` LIKE 'courier\\_center\\_%'"));
    };

    $s->adminGet('extension/extension/shipping/uninstall', array('extension' => 'courier_center'));
    $c->ok($events() === 0, 'uninstall removes our events');
    $c->ok($before > 0 && count($s->rows("SELECT `id` FROM `{p}cc_shipments`")) === $before, 'uninstall keeps cc_shipments');
    $k2 = $s->row("SELECT `value` FROM `{p}setting` WHERE `key` = 'shipping_courier_center_cron_key'");
    $c->ok(isset($k2['value']) && $k2['value'] === $key, 'uninstall keeps the cron key');

    $s->adminGet('extension/extension/shipping/install', array('extension' => 'courier_center'));
    $c->ok($events() === 14, 'reinstall registers the events again');
};
