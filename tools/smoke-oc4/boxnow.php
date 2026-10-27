<?php
/**
 * BOX NOW on the OpenCart 4 checkout, driven over HTTP like the browser (1.1.1). OpenCart 4 writes the order
 * once and then REWRITES it (editOrder) whenever the customer changes something before "Confirm"; editOrder
 * resets custom_field, so the locker must survive that, and an unticked BOX NOW must disappear.
 * No voucher is created (no API calls). The test orders are deleted at the end.
 * Usage: php tools/smoke-oc4/boxnow.php [base_url]   (default http://127.0.0.1:8744/opencart4/)
 */
require dirname(__DIR__) . '/smoke/bootstrap.php';

$base = rtrim(isset($argv[1]) ? $argv[1] : 'http://127.0.0.1:8744/opencart4/', '/') . '/';
$cfg = cc_config();
$m = cc_mysql($cfg['oc4_db']);
$P = $cfg['oc4_prefix'];
$ok = 0;
$fail = array();
function c_ok($cond, $what) {
    global $ok, $fail;
    if ($cond) {
        $ok++;
        echo "  ok   $what\n";
    } else {
        $fail[] = $what;
        echo "  FAIL $what\n";
    }
}
function q1($sql) {
    global $m;
    $r = $m->query($sql);
    if ($r === false) {
        throw new RuntimeException($m->error . ' :: ' . $sql);
    }
    return $r === true ? null : $r->fetch_assoc();
}
function js($res) {
    $j = json_decode($res['body'], true);
    return is_array($j) ? $j : array('error' => 'NOT JSON: ' . substr($res['body'], 0, 300));
}

$orders = array();
$cf = function ($id) use ($P) {
    $r = q1("SELECT `custom_field` FROM `{$P}order` WHERE `order_id` = '" . (int)$id . "'");
    $c = json_decode(isset($r['custom_field']) ? (string)$r['custom_field'] : '', true);
    return is_array($c) ? $c : array();
};
$latest = function () use ($P) {
    $r = q1("SELECT MAX(`order_id`) AS id FROM `{$P}order`");
    return (int)$r['id'];
};
// One guest checkout session up to the confirm step with $payment; returns [http, url builder].
$checkout = function (array $boxnow = null, $payment = 'cod.cod') use ($base) {
    $h = new SmokeHttp(cc_state_dir() . '/oc4-boxnow-' . uniqid() . '.cookies');
    $u = function ($route) use ($base) {
        return $base . 'index.php?route=' . $route . '&language=en-gb';
    };
    $step = function ($what, array $j) {
        if (!empty($j['error']) || !empty($j['redirect'])) {
            throw new RuntimeException($what . ': ' . json_encode($j, JSON_UNESCAPED_UNICODE));
        }
    };
    $step('cart', js($h->post($u('checkout/cart.add'), array('product_id' => 40, 'quantity' => 1))));
    $h->get($u('checkout/checkout'));
    $step('register', js($h->post($u('checkout/register.save'), array(
        'account' => 0, 'customer_group_id' => 1, 'firstname' => 'Δοκιμή', 'lastname' => 'BoxNow', 'email' => 'boxnow-oc4@example.com',
        'telephone' => '6912345678', 'address_match' => 0, 'shipping_firstname' => 'Δοκιμή', 'shipping_lastname' => 'BoxNow',
        'shipping_company' => '', 'shipping_address_1' => 'Λεωφόρος Αλεξάνδρας 10', 'shipping_address_2' => '', 'shipping_city' => 'Αθήνα',
        'shipping_postcode' => '11473', 'shipping_country_id' => 84, 'shipping_zone_id' => 1280, 'password' => '', 'agree' => 0))));
    $step('shipping quote', js($h->get($u('checkout/shipping_method.quote'))));
    $step('shipping save', js($h->post($u('checkout/shipping_method.save'), array('shipping_method' => 'flat.flat'))));
    if ($boxnow !== null) {
        js($h->post($u('extension/couriercenter/shipping/courier_center_boxnow.saveSession'), $boxnow));
    }
    $step('payment methods', js($h->get($u('checkout/payment_method.getMethods'))));
    $step('payment save', js($h->post($u('checkout/payment_method.save'), array('payment_method' => $payment))));
    $h->get($u('checkout/confirm.confirm'));
    return array($h, $u);
};
$pick = array('selected' => '1', 'delivery_mode' => 'pick', 'locker_id' => 'L123', 'locker_code' => 'L123', 'locker_name' => 'Locker Α & Β');
$has_locker = function (array $c) {
    return isset($c['cc_locker_id'], $c['cc_delivery_mode'], $c['cc_locker_name']) && $c['cc_locker_id'] === 'L123'
        && $c['cc_delivery_mode'] === 'pick' && $c['cc_locker_name'] === 'Locker Α & Β';
};

try {
    echo "== admin: the settings page registers the events of the new files\n";
    $admin = new SmokeHttp(cc_state_dir() . '/oc4-boxnow-admin.cookies');
    $page = $admin->get($base . 'admin/index.php?route=common/login')['body'];
    preg_match('/login_token=([a-z0-9]+)/', $page, $lt);
    $r = js($admin->post($base . 'admin/index.php?route=common/login.login&login_token=' . (isset($lt[1]) ? $lt[1] : ''), array('username' => 'ccsmoke', 'password' => 'ccsmoke123')));
    preg_match('/user_token=([a-z0-9]+)/', isset($r['redirect']) ? $r['redirect'] : '', $ut);
    $token = isset($ut[1]) ? $ut[1] : '';
    c_ok($token !== '', 'admin login (temporary user ccsmoke)');
    $admin->get($base . 'admin/index.php?route=extension/couriercenter/shipping/courier_center&user_token=' . $token);
    foreach (array('courier_center_boxnow_order', 'courier_center_boxnow_edit', 'courier_center_boxnow_clear') as $code) {
        c_ok(q1("SELECT 1 AS x FROM `{$P}event` WHERE `code` = '$code' AND `status` = 1") !== null, "event $code");
    }

    echo "== storefront: the order is rewritten when the customer changes payment\n";
    list($h, $u) = $checkout($pick);
    $id = $latest();
    $orders[] = $id;
    c_ok($has_locker($cf($id)), "order #$id has the locker after the first confirm: " . json_encode($cf($id), JSON_UNESCAPED_UNICODE));
    js($h->post($u('checkout/payment_method.save'), array('payment_method' => 'bank_transfer.bank_transfer')));
    $h->get($u('checkout/confirm.confirm'));
    $row = q1("SELECT `payment_method` FROM `{$P}order` WHERE `order_id` = '$id'");
    c_ok($latest() === $id && strpos((string)$row['payment_method'], 'bank_transfer') !== false, "order #$id was rewritten (editOrder) with the new payment");
    c_ok($has_locker($cf($id)), "order #$id still has the locker after the payment change");

    echo "== storefront: BOX NOW unticked after the order was written\n";
    js($h->post($u('extension/couriercenter/shipping/courier_center_boxnow.saveSession'), array('selected' => '')));
    $h->get($u('checkout/confirm.confirm'));
    c_ok(!isset($cf($id)['cc_delivery_mode']), "order #$id has no BOX NOW after unticking it");

    echo "== storefront: BOX NOW ticked after the order was written\n";
    js($h->post($u('extension/couriercenter/shipping/courier_center_boxnow.saveSession'), array('selected' => '1', 'delivery_mode' => 'auto')));
    $h->get($u('checkout/confirm.confirm'));
    $c = $cf($id);
    c_ok(isset($c['cc_delivery_mode']) && $c['cc_delivery_mode'] === 'auto', "order #$id has BOX NOW «auto» after ticking it");

    echo "== storefront: checkout/success clears the choice for the next order\n";
    $h->post($u('extension/opencart/payment/bank_transfer.confirm'), array());
    $h->get($u('checkout/success'));
    js($h->post($u('checkout/cart.add'), array('product_id' => 40, 'quantity' => 1)));
    js($h->get($u('checkout/shipping_method.quote')));
    js($h->post($u('checkout/shipping_method.save'), array('shipping_method' => 'flat.flat')));
    js($h->get($u('checkout/payment_method.getMethods')));
    js($h->post($u('checkout/payment_method.save'), array('payment_method' => 'cod.cod')));
    $h->get($u('checkout/confirm.confirm'));
    $next = $latest();
    $orders[] = $next;
    c_ok($next > $id && !isset($cf($next)['cc_delivery_mode']), "the next order #$next has no BOX NOW");

    echo "== admin: Edit → Confirm (api/sale/order.confirm → editOrder) keeps the locker\n";
    $checkout($pick);
    $id = $latest();
    $orders[] = $id;
    q1("UPDATE `{$P}order` SET `order_status_id` = 1 WHERE `order_id` = '$id'");
    $admin->get($base . 'admin/index.php?route=sale/order.info&order_id=' . $id . '&user_token=' . $token);
    $r = js($admin->post($base . 'admin/index.php?route=sale/order.call&action=sale/order.confirm&order_id=' . $id . '&user_token=' . $token . '&language=en-gb',
        array('order_status_id' => 1)));
    c_ok(empty($r['error']) && !empty($r['success']) && isset($r['order_id']) && (int)$r['order_id'] === $id, "admin confirm rewrote order #$id: " . json_encode($r, JSON_UNESCAPED_UNICODE));
    c_ok($has_locker($cf($id)), "admin edit kept the locker of order #$id");
} catch (Throwable $e) {
    c_ok(false, get_class($e) . ': ' . $e->getMessage());
}

echo "== cleanup\n";
foreach ($orders as $id) {
    foreach (array('order', 'order_product', 'order_option', 'order_total', 'order_history', 'order_voucher', 'order_subscription') as $t) {
        if (q1("SHOW TABLES LIKE '{$P}{$t}'") !== null) {
            q1("DELETE FROM `{$P}{$t}` WHERE `order_id` = '" . (int)$id . "'");
        }
    }
}
echo "  test orders deleted: " . ($orders ? '#' . implode(', #', $orders) : '—') . "\n";
echo "\n[oc4 boxnow] $ok ok, " . count($fail) . " failed\n";
foreach ($fail as $f) {
    echo "  - $f\n";
}
exit($fail ? 1 : 0);
