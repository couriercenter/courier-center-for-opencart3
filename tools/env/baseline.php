<?php
/**
 * Records the OpenCart core's own error-log lines BEFORE the extension is installed
 * (spec 7.8). Usage: /c/xampp/php/php.exe tools/env/baseline.php <id>
 */
require dirname(__DIR__) . '/smoke/bootstrap.php';
$shop = smoke_shop(isset($argv[1]) ? $argv[1] : '');
$shop->clearErrorLog();

// The same core pages the smoke sections visit, without the extension.
$shop->login();
foreach (array(
    array('common/dashboard', array()), array('marketplace/installer', array()),
    array('marketplace/extension', array('type' => 'shipping')), array('extension/extension/shipping', array()),
    array('sale/order', array()),
) as $page) {
    $shop->adminGet($page[0], $page[1]);
}
$order_id = $shop->createApiOrder('flat.flat', 'cod');
$shop->adminGet('sale/order/info', array('order_id' => $order_id));
$shop->apiOrderHistory($order_id, 2, true, 'baseline');

// Storefront: guest checkout (flat rate + bank transfer) up to the success page.
$shop->shopGet('common/home');
$shop->shopGet('product/product', array('product_id' => 40));
$shop->shopGet('checkout/cart');
SmokeShop::json($shop->shopPost('checkout/cart/add', array('product_id' => 43, 'quantity' => 1)));
$shop->shopGet('checkout/checkout');
SmokeShop::json($shop->shopPost('checkout/guest/save', array_merge($shop->address(), array(
    'customer_group_id' => 1, 'email' => 'baseline@example.com', 'telephone' => '6912345678', 'shipping_address' => 1))));
$shop->shopGet('checkout/shipping_method');
SmokeShop::json($shop->shopPost('checkout/shipping_method/save', array('shipping_method' => 'flat.flat', 'comment' => '')));
$shop->shopGet('checkout/payment_method');
SmokeShop::json($shop->shopPost('checkout/payment_method/save', array('payment_method' => 'bank_transfer', 'comment' => '', 'agree' => '1')));
$shop->shopGet('checkout/confirm');
$shop->shopGet('extension/payment/bank_transfer/confirm');
$shop->shopGet('checkout/success');

$lines = $shop->errorLogLines();
file_put_contents(cc_state_dir() . "/{$shop->id}-baseline.log", implode("\n", $lines) . "\n");
echo "Baseline {$shop->id}: order #$order_id, " . count($lines) . " core log line(s)\n";
