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
    foreach (array('courier_center_boxnow_order', 'courier_center_boxnow_edit', 'courier_center_boxnow_confirm', 'courier_center_boxnow_clear') as $code) {
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

    echo "== storefront: safety net — the first confirmation writes the current choice (1.1.2)\n";
    // a checkout that writes the order its own way: custom_field without the choice
    q1("UPDATE `{$P}order` SET `custom_field` = '[]' WHERE `order_id` = '$id'");
    $h->post($u('extension/opencart/payment/bank_transfer.confirm'), array());
    $c = $cf($id);
    $st = q1("SELECT `order_status_id` FROM `{$P}order` WHERE `order_id` = '$id'");
    c_ok((int)$st['order_status_id'] > 0 && isset($c['cc_delivery_mode']) && $c['cc_delivery_mode'] === 'auto', "first confirmation wrote BOX NOW «auto» into order #$id");

    echo "== storefront: checkout/success clears the choice for the next order\n";
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

    echo "== admin map (1.1.3): the merchant picks the locker the customer said on the phone\n";
    $checkout(null);
    $id = $latest();
    $orders[] = $id;
    q1("UPDATE `{$P}order` SET `order_status_id` = 1 WHERE `order_id` = '$id'"); // history notes need a confirmed order
    $info = function ($id) use ($admin, $base, $token) {
        return $admin->get($base . 'admin/index.php?route=sale/order.info&order_id=' . $id . '&user_token=' . $token)['body'];
    };
    $set = function (array $f) use ($admin, $base, $token) {
        return js($admin->post($base . 'admin/index.php?route=extension/couriercenter/shipping/courier_center.setLocker&user_token=' . $token, $f));
    };
    $page = $info($id);
    // 1.1.5: with the checkbox ticked & locked on this BOX NOW method the order is BOX NOW «auto» (map via «Επιλογή locker από χάρτη»)
    c_ok((strpos($page, 'name="cc_bn_mode" value="pick"') !== false || strpos($page, 'data-cc-action="change-locker"') !== false)
        && strpos($page, 'id="cc-bn-admin-overlay"') !== false, "order #$id without a locker: map choice + map modal");
    $r = $set(array('order_id' => $id, 'locker_id' => ''));
    c_ok(empty($r['success']), 'setLocker without a locker is refused');
    $r = $set(array('order_id' => $id, 'locker_id' => 'L123', 'locker_code' => 'L123', 'locker_name' => 'Locker Α & Β'));
    c_ok(!empty($r['success']) && $has_locker($cf($id)), "setLocker wrote the locker into order #$id: " . json_encode($r, JSON_UNESCAPED_UNICODE));
    $h = q1("SELECT `comment` FROM `{$P}order_history` WHERE `order_id` = '$id' ORDER BY `order_history_id` DESC LIMIT 1");
    c_ok(isset($h['comment']) && strpos($h['comment'], 'Locker Α & Β') !== false, 'order history note of the change');
    c_ok(strpos($info($id), 'data-cc-action="change-locker"') !== false, 'the order now shows the locker with «Αλλαγή locker»');
    // an active voucher (a row only, no API call): first void, then change
    q1("INSERT INTO `{$P}cc_shipments` SET `order_id` = '$id', `voucher_number` = 'SMOKE1', `is_voided` = 0");
    $r = $set(array('order_id' => $id, 'locker_id' => '999', 'locker_name' => 'X'));
    c_ok(empty($r['success']) && $has_locker($cf($id)), 'setLocker refused while the voucher is active');
    c_ok(strpos($info($id), 'data-cc-action="change-locker"') === false, 'no «Αλλαγή locker» with an active voucher');

    echo "== 1.1.5: the widget's AJAX writes the choice straight into the unconfirmed order\n";
    // the order exists (written before the choice arrived, like Journal 3), nothing rewrites it afterwards
    list($h, $u) = $checkout(null);
    $id = $latest();
    $orders[] = $id;
    c_ok(!isset($cf($id)['cc_delivery_mode']), "order #$id written without a choice");
    js($h->post($u('extension/couriercenter/shipping/courier_center_boxnow.saveSession'), $pick));
    c_ok($has_locker($cf($id)), "saveSession alone put the locker into order #$id: " . json_encode($cf($id), JSON_UNESCAPED_UNICODE));
    js($h->post($u('extension/couriercenter/shipping/courier_center_boxnow.saveSession'), array('selected' => '')));
    c_ok(!isset($cf($id)['cc_delivery_mode']), "unticking removed it from order #$id");
    q1("UPDATE `{$P}order` SET `order_status_id` = 1 WHERE `order_id` = '$id'");
    js($h->post($u('extension/couriercenter/shipping/courier_center_boxnow.saveSession'), $pick));
    c_ok(!isset($cf($id)['cc_delivery_mode']), "a confirmed order #$id is never touched by saveSession");

    echo "== 1.1.5: BOX NOW ticked & locked on a BOX NOW method = BOX NOW even without the choice\n";
    $setting = function ($key, $value) use ($P) {
        q1("DELETE FROM `{$P}setting` WHERE `key` = 'shipping_courier_center_$key'");
        if ($value !== null) {
            q1("INSERT INTO `{$P}setting` SET `store_id` = 0, `code` = 'shipping_courier_center', `key` = 'shipping_courier_center_$key', `value` = '"
                . (is_array($value) ? json_encode($value) : $value) . "', `serialized` = " . (is_array($value) ? 1 : 0));
        }
    };
    $saved = array();
    foreach (array('boxnow_enabled', 'boxnow_default_selected', 'boxnow_shipping_methods', 'handled_shipping_methods') as $k) {
        $saved[$k] = q1("SELECT `value`, `serialized` FROM `{$P}setting` WHERE `key` = 'shipping_courier_center_$k'");
    }
    try {
        $setting('boxnow_enabled', '1');
        $setting('boxnow_default_selected', '1');
        $setting('boxnow_shipping_methods', array('flat'));
        $setting('handled_shipping_methods', array('courier_center', 'flat'));
        $checkout(null, 'bank_transfer.bank_transfer'); // BOX NOW + αντικαταβολή is refused by design
        $id = $latest();
        $orders[] = $id;
        q1("UPDATE `{$P}order` SET `order_status_id` = 1, `custom_field` = '[]' WHERE `order_id` = '$id'");
        $page = $info($id);
        c_ok(strpos($page, 'data-cc-action="create-boxnow"') !== false, "order #$id (flat, no choice): order page offers «Δημιουργία BOX NOW Voucher»");
        $list = $admin->get($base . 'admin/index.php?route=sale/order.list&user_token=' . $token . '&filter_order_id=' . $id)['body']
            . $admin->get($base . 'admin/index.php?route=sale/order&user_token=' . $token . '&filter_order_id=' . $id)['body'];
        c_ok((bool)preg_match('/"' . $id . '":\{[^}]*"boxnow":true/', $list), "order list «Τύπος»: order #$id is BOX NOW");
        $r = js($admin->post($base . 'admin/index.php?route=extension/couriercenter/shipping/courier_center.bulkCreate&user_token=' . $token, array('order_ids' => (string)$id)));
        c_ok(!empty($r['success']) && strpos((string)($r['message'] ?? ''), 'BOX NOW: 1') !== false, 'bulk create: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
        $s = q1("SELECT `voucher_number`, `is_boxnow` FROM `{$P}cc_shipments` WHERE `order_id` = '$id'");
        c_ok(isset($s['is_boxnow']) && (int)$s['is_boxnow'] === 1, "order #$id got a BOX NOW voucher " . ($s['voucher_number'] ?? ''));
        if (!empty($s['voucher_number'])) {
            $v = js($admin->post($base . 'admin/index.php?route=extension/couriercenter/shipping/courier_center.voidShipment&user_token=' . $token, array('order_id' => $id)));
            c_ok(!empty($v['success']), 'test voucher voided ' . $s['voucher_number']);
        }
        $r = js($admin->post($base . 'admin/index.php?route=extension/couriercenter/shipping/courier_center.removeBoxNow&user_token=' . $token, array('order_id' => $id)));
        c_ok(!empty($r['success']) && ($cf($id)['cc_delivery_mode'] ?? '') === 'none', "«Αφαίρεση BOX NOW» stores «none» in order #$id");
        c_ok(strpos($info($id), 'data-cc-action="create-boxnow"') === false, "after the removal order #$id stays a normal shipment");

        echo "== 1.1.5: settings page has «Και από αυτά τα status»\n";
        $page = $admin->get($base . 'admin/index.php?route=extension/couriercenter/shipping/courier_center&user_token=' . $token)['body'];
        c_ok(strpos($page, 'name="shipping_courier_center_extra_active_statuses[]"') !== false, 'checkbox list rendered');
    } finally {
        foreach ($saved as $k => $row) {
            q1("DELETE FROM `{$P}setting` WHERE `key` = 'shipping_courier_center_$k'");
            if ($row !== null) {
                q1("INSERT INTO `{$P}setting` SET `store_id` = 0, `code` = 'shipping_courier_center', `key` = 'shipping_courier_center_$k', `value` = '"
                    . $m->real_escape_string($row['value']) . "', `serialized` = " . (int)$row['serialized']);
            }
        }
    }
} catch (Throwable $e) {
    c_ok(false, get_class($e) . ': ' . $e->getMessage());
}

echo "== cleanup\n";
foreach ($orders as $id) {
    foreach (array('order', 'order_product', 'order_option', 'order_total', 'order_history', 'order_voucher', 'order_subscription', 'cc_shipments') as $t) {
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
