<?php
/**
 * PHP gate (spec 5.11): shop 3020 (extension installed) served by PHP 7.0 on port 8700 must not break.
 * Start first:  <php7.0>/php.exe -S 127.0.0.1:8700 -t C:/Users/python/oc3-envs/oc3020
 * Usage: /c/xampp/php/php.exe tools/smoke/php_gate.php
 */
require __DIR__ . '/bootstrap.php';
$env = cc_env('3020');
$env['url'] = 'http://127.0.0.1:8700/';
$env['id'] = '3020p70';
$s = new SmokeShop($env);
$ok = function ($c, $what) { echo ($c ? '  ok   ' : '  FAIL ') . $what . "\n"; };
$s->clearErrorLog();
$ver = $s->shopGet('common/home');
$ok($ver['status'] === 200 && strpos($ver['body'], 'Fatal') === false, 'storefront home on PHP 7.0 loads');
$ok(strpos($ver['body'], 'cc-boxnow-wrap') === false, 'no BOX NOW widget on the home page');
$cart = $s->shopPost('checkout/cart/add', array('product_id' => 43, 'quantity' => 1));
$ok($cart['status'] === 200, 'add to cart works');
$co = $s->shopGet('checkout/checkout');
$ok($co['status'] === 200 && strpos($co['body'], 'Fatal') === false && strpos($co['body'], 'Parse error') === false, 'checkout page loads (no fatal from our files)');
$ok(strpos($co['body'], 'cc-boxnow-wrap') === false, 'no BOX NOW widget when PHP < 7.2 (gate)');
$s->shopPost('checkout/guest/save', array_merge($s->address(), array('customer_group_id' => 1, 'email' => 'p70@example.com', 'telephone' => '6900000000', 'shipping_address' => 1)));
$sm = $s->shopGet('checkout/shipping_method');
$ok($sm['status'] === 200 && strpos($sm['body'], 'courier_center') === false && strpos($sm['body'], 'Fatal') === false, 'shipping step loads, Courier Center is NOT offered below PHP 7.2');
$ok(strpos($sm['body'], 'flat.flat') !== false, 'other shipping methods still offered');
$s->login();
$settings = $s->adminGet('extension/shipping/courier_center');
$ok($settings['status'] === 200 && strpos($settings['body'], 'PHP 7.2') !== false && strpos($settings['body'], 'form-courier-center') === false, 'settings page shows the PHP 7.2 message instead of the form');
$list = $s->adminGet('sale/order');
$ok($list['status'] === 200 && strpos($list['body'], 'Fatal') === false, 'admin order list loads');
$info = $s->adminGet('sale/order/info', array('order_id' => 1));
$ok($info['status'] === 200 && strpos($info['body'], 'Fatal') === false, 'admin order page loads');
$dash = $s->adminGet('common/dashboard');
$ok($dash['status'] === 200 && strpos($dash['body'], 'Fatal') === false, 'admin dashboard (header event) loads');
$r = $s->admin->post($s->adminUrl('extension/shipping/courier_center/createVoucher'), array('order_id' => 1));
$j = json_decode($r['body'], true);
$ok(is_array($j) && empty($j['success']) && strpos($j['error'], '7.2') !== false, 'AJAX endpoints answer JSON with the PHP message');
$log = $s->errorLogLines();
$ours = array_values(array_filter($log, function ($l) { return stripos($l, 'couriercenter') !== false || stripos($l, 'courier_center') !== false; }));
$ok(!$ours, 'error log: nothing from our files' . ($ours ? ': ' . implode(' / ', array_slice($ours, 0, 3)) : ''));
echo 'core lines in log: ' . count($log) . "\n";
