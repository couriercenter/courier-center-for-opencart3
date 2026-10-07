<?php
/** Cron through the URL (wrong key 403, right key runs) and the CLI script (spec 5.8). */
return function (SmokeContext $c) {
    $s = $c->shop;
    $k = $s->row("SELECT `value` FROM `{p}setting` WHERE `key` = 'shipping_courier_center_cron_key'");
    $key = isset($k['value']) ? $k['value'] : '';
    $bad = $s->front->get($s->shopUrl('extension/shipping/courier_center/cron', array('key' => 'wrong')));
    $c->ok($bad['status'] === 403 && trim($bad['body']) === 'Forbidden', 'cron URL with a wrong key: 403');
    $s->exec("UPDATE `{p}cc_shipments` SET `last_checked_at` = NULL WHERE `is_voided` = 0");
    // The shop writes cron_last_run in ITS timezone (OpenCart 3 default: UTC), the harness reads it in its own:
    // compare before/after instead of against the harness clock.
    $s->exec("DELETE FROM `{p}setting` WHERE `key` = 'shipping_courier_center_cron_last_run'");
    $ok = $s->front->get($s->shopUrl('extension/shipping/courier_center/cron', array('key' => $key)));
    $c->ok($ok['status'] === 200 && strpos($ok['body'], 'checked=') !== false, 'cron URL: ' . trim((string)strtok($ok['body'], "\n")));
    $last = $s->row("SELECT `value` FROM `{p}setting` WHERE `key` = 'shipping_courier_center_cron_last_run'");
    $c->ok(isset($last['value']) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $last['value']) === 1, 'cron_last_run written by the run');
    list($code, $out) = cc_exec(array(cc_php($s->env['php']), $s->env['dir'] . '/system/library/couriercenter/cron_status_tracker.php'));
    $c->ok($code === 0 && strpos($out, 'checked=') !== false, 'cron CLI: ' . trim(substr($out, (int)strrpos($out, '['))));
    $web = $s->front->get($s->env['url'] . 'system/library/couriercenter/cron_status_tracker.php');
    $c->ok($web['status'] === 403 || $web['status'] === 404, 'CLI script refuses web requests (HTTP ' . $web['status'] . ')');
};
