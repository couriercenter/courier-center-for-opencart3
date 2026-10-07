<?php
/** Checkout quotes through the catalog API: normal + COD (cost + fee), OpenCart 3 format (spec 5.7). */
return function (SmokeContext $c) {
    $s = $c->shop;
    $t = $s->apiLogin();
    $s->api($t, 'api/customer', array('customer_id' => 0, 'customer_group_id' => 1, 'firstname' => 'Δοκιμή', 'lastname' => 'Smoke',
        'email' => 'customer@example.com', 'telephone' => '6900000000'));
    $s->api($t, 'api/cart/add', array('product_id' => 40, 'quantity' => 1));
    $a = $s->address();
    $s->api($t, 'api/payment/address', $a);
    $s->api($t, 'api/shipping/address', $a);
    $m = $s->api($t, 'api/shipping/methods');
    $cc = isset($m['shipping_methods']['courier_center']) ? $m['shipping_methods']['courier_center'] : array();
    $q = isset($cc['quote']) ? $cc['quote'] : array();
    $c->ok(isset($q['courier_center'], $q['courier_center_cod']), 'Courier Center + COD quotes offered');
    $c->ok(isset($q['courier_center']['cost'], $q['courier_center_cod']['cost']) && abs($q['courier_center']['cost'] - 3.5) < 0.001 && abs($q['courier_center_cod']['cost'] - 5.0) < 0.001,
        'costs 3.50 / 5.00 (cost + COD fee)');
    $c->ok(isset($cc['title'], $q['courier_center']['title']) && $cc['title'] === 'Courier Center' && $q['courier_center']['title'] !== '', 'titles from the language file');
};
