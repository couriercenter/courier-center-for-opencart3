<?php
/** Order list «Τύπος» before a voucher (1.1.3): the customer's BOX NOW choice goes to the page. No API calls. */
return function (SmokeContext $c) {
    $s = $c->shop;
    $s->login();
    $page = $s->adminGet('sale/order')['body'];
    preg_match_all('/name="selected\[\]"\s+value="(\d+)"/', $page, $m);
    $ids = array_map('intval', $m[1]);
    $free = 0;
    foreach ($ids as $id) {
        if (!$s->row("SELECT `order_id` FROM `{p}cc_shipments` WHERE `order_id` = '" . $id . "' AND `voucher_number` <> '' AND `is_voided` = 0")) {
            $free = $id;
            break;
        }
    }
    if (!$free) {
        $c->fail('no order without an active voucher on the first list page');
        return;
    }
    $cf = $s->row("SELECT `custom_field` FROM `{p}order` WHERE `order_id` = '" . $free . "'")['custom_field'];
    $methods = function () use ($s) {
        $body = $s->adminGet('sale/order')['body'];
        return preg_match('/var METHODS = (\{.*?\});/', $body, $mm) ? json_decode($mm[1], true) : null;
    };
    try {
        $bn = json_decode((string)$cf, true);
        $bn = is_array($bn) ? $bn : array();
        $bn['cc_delivery_mode'] = 'auto';
        $s->exec("UPDATE `{p}order` SET `custom_field` = '" . $s->db()->real_escape_string(json_encode($bn)) . "' WHERE `order_id` = '" . $free . "'");
        $m1 = $methods();
        $c->ok(isset($m1[(string)$free]) && $m1[(string)$free]['boxnow'] === true, "order #$free with BOX NOW choice: boxnow true in the list data");
        $plain = $bn;
        unset($plain['cc_delivery_mode']);
        $s->exec("UPDATE `{p}order` SET `custom_field` = '" . $s->db()->real_escape_string(json_encode($plain)) . "' WHERE `order_id` = '" . $free . "'");
        $m2 = $methods();
        $c->ok(isset($m2[(string)$free]) && $m2[(string)$free]['boxnow'] === false, "order #$free without BOX NOW: boxnow false");
        $c->ok(strpos($page, 'tdPending') !== false && strpos($page, '1px dashed') !== false, 'list script shows the type before a voucher (dashed badge)');
    } finally {
        $s->exec("UPDATE `{p}order` SET `custom_field` = '" . $s->db()->real_escape_string((string)$cf) . "' WHERE `order_id` = '" . $free . "'");
    }
};
