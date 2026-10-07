<?php
/** Order list: columns + bulk bar + manifest button scripts (spec 5.6). Needs "actions". */
return function (SmokeContext $c) {
    $s = $c->shop;
    if (empty($c->data['orders'])) {
        throw new RuntimeException('run the "actions" section first');
    }
    $s->login();
    $page = $s->adminGet('sale/order')['body'];
    $bank = $s->row("SELECT `voucher_number` FROM `{p}cc_shipments` WHERE `order_id` = '" . (int)$c->data['orders']['bank'] . "'");
    $c->ok(strpos($page, 'cc-bulk-bar') !== false && strpos($page, 'cc-manifest-btn') !== false, 'bulk bar + manifest button script injected');
    $c->ok(isset($bank['voucher_number']) && strpos($page, '"' . $bank['voucher_number'] . '"') !== false, 'voucher data of the visible orders in the page');
    $c->ok(strripos($page, '</body>') > strpos($page, 'cc-bulk-bar'), 'script placed before </body>');
};
