<?php
/**
 * BOX NOW on the default OpenCart 3 checkout, driven over HTTP like the browser (spec 5.7, Review Focus 1):
 * widget only on checkout pages, the choice is written into EVERY order the confirm step creates,
 * cleared on checkout/success, a half choice becomes "auto", never applied to another shipping method.
 */
return function (SmokeContext $c) {
    $s = $c->shop;
    $s->front = new SmokeHttp(cc_state_dir() . "/{$s->id}-front.cookies");
    $guest = array_merge($s->address(), array('customer_group_id' => 1, 'email' => 'boxnow@example.com', 'telephone' => '6912345678', 'shipping_address' => 1));
    $latest = function () use ($s) {
        $r = $s->row("SELECT MAX(`order_id`) AS id FROM `{p}order`");
        return (int)$r['id'];
    };
    $custom = function ($id) use ($s) {
        $r = $s->row("SELECT `custom_field` FROM `{p}order` WHERE `order_id` = '" . (int)$id . "'");
        $cf = json_decode(isset($r['custom_field']) ? (string)$r['custom_field'] : '', true);
        return is_array($cf) ? $cf : array();
    };
    $checkout = function ($shipping, array $boxnow = null) use ($s, $guest) {
        SmokeShop::json($s->shopPost('checkout/cart/add', array('product_id' => 43, 'quantity' => 1)));
        SmokeShop::json($s->shopPost('checkout/guest/save', $guest));
        $s->shopGet('checkout/shipping_method');
        SmokeShop::json($s->shopPost('checkout/shipping_method/save', array('shipping_method' => $shipping, 'comment' => '')));
        if ($boxnow !== null) {
            SmokeShop::json($s->shopPost('extension/shipping/courier_center/boxnow/saveSession', $boxnow));
        }
        $s->shopGet('checkout/payment_method');
        SmokeShop::json($s->shopPost('checkout/payment_method/save', array('payment_method' => 'bank_transfer', 'comment' => '', 'agree' => '1')));
    };

    $c->ok(strpos($s->shopGet('checkout/cart')['body'], 'cc-boxnow-wrap') === false, 'no widget on the cart page');
    SmokeShop::json($s->shopPost('checkout/cart/add', array('product_id' => 43, 'quantity' => 1)));
    $page = $s->shopGet('checkout/checkout')['body'];
    $c->ok(strpos($page, 'cc-boxnow-wrap') !== false && strpos($page, 'widget-cdn.boxnow.gr') !== false, 'widget on the checkout page (footer event)');

    // 1. Pick + confirm loaded twice (customer went back): both orders carry the locker.
    $checkout('courier_center.courier_center', array('selected' => '1', 'delivery_mode' => 'pick', 'locker_id' => 'L123', 'locker_code' => 'L123', 'locker_name' => 'Locker Α & Β'));
    $s->shopGet('checkout/confirm');
    $first = $latest();
    $s->shopGet('checkout/confirm');
    $second = $latest();
    $c->ok($second > $first, 'the second confirm created a new order (OpenCart 3)');
    foreach (array($first, $second) as $id) {
        $cf = $custom($id);
        $c->ok(isset($cf['cc_locker_id'], $cf['cc_delivery_mode']) && $cf['cc_locker_id'] === 'L123' && $cf['cc_delivery_mode'] === 'pick' && $cf['cc_locker_name'] === 'Locker Α & Β', "order #$id has the locker");
    }
    $s->shopGet('extension/payment/bank_transfer/confirm');
    $s->shopGet('checkout/success');
    SmokeShop::json($s->shopPost('checkout/cart/add', array('product_id' => 43, 'quantity' => 1)));
    $c->ok(strpos($s->shopGet('checkout/checkout')['body'], 'id="cc_boxnow_delivery_mode" value=""') !== false, 'checkout/success cleared the choice');

    // 2. Half choice -> "auto".
    $checkout('courier_center.courier_center', array('selected' => '1', 'delivery_mode' => ''));
    $s->shopGet('checkout/confirm');
    $auto = $latest();
    $cf = $custom($auto);
    $c->ok(isset($cf['cc_delivery_mode']) && $cf['cc_delivery_mode'] === 'auto', 'a half choice became "auto"');

    // 3. Same session, customer switches to flat rate: no BOX NOW in that order.
    SmokeShop::json($s->shopPost('checkout/shipping_method/save', array('shipping_method' => 'flat.flat', 'comment' => '')));
    $s->shopGet('checkout/confirm');
    $flat = $latest();
    $flat_cf = $custom($flat);
    $c->ok(!isset($flat_cf['cc_delivery_mode']), 'no BOX NOW in a flat-rate order');
    $s->shopGet('extension/payment/bank_transfer/confirm');
    $s->shopGet('checkout/success');

    // 4. Admin: the "auto" order goes to "Δημιουργία BOX NOW" (real API).
    $s->exec("UPDATE `{p}order` SET `order_status_id` = 1 WHERE `order_id` = '$auto'");
    $s->login();
    $r = $s->adminJson('extension/shipping/courier_center/bulkCreateBoxnow', array('order_ids' => (string)$auto));
    $msg = isset($r['message']) ? str_replace("\n", ' ', $r['message']) : json_encode($r, JSON_UNESCAPED_UNICODE);
    $c->ok(!empty($r['success']) && strpos($msg, 'Όχι BOX NOW') === false, 'bulk BOX NOW took the auto order: ' . $msg);
    $row = $s->row("SELECT `voucher_number` FROM `{p}cc_shipments` WHERE `order_id` = '$auto'");
    $c->track(isset($row['voucher_number']) ? $row['voucher_number'] : '');
};
