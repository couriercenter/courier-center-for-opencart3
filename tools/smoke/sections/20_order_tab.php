<?php
/** Native OpenCart 3 tab on the admin order page (spec 4.11, 5.6). Needs the "actions" section first. */
return function (SmokeContext $c) {
    $s = $c->shop;
    if (empty($c->data['orders'])) {
        throw new RuntimeException('run the "actions" section first');
    }
    $s->login();
    $o = $c->data['orders'];
    $page = $s->adminGet('sale/order/info', array('order_id' => $o['bank']))['body'];
    $c->ok(strpos($page, 'href="#tab-courier-center"') !== false && strpos($page, 'id="tab-courier-center"') !== false, 'native "Courier Center" tab');
    $c->ok(strpos($page, 'Voucher Δημιουργήθηκε') !== false && strpos($page, 'data-cc-action="void"') !== false, 'tab shows the active voucher + actions');
    $fresh = $s->adminGet('sale/order/info', array('order_id' => $o['cod2']))['body'];
    $c->ok(strpos($fresh, 'data-cc-action="create"') !== false, 'tab shows the create form for an order without voucher');
};
