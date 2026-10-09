<?php
/**
 * OC4 smoke: the AWB inside the shop's own order status email (event catalog/view/mail/order_history/after)
 * and the auto-create on a status change. Puts a TEMPORARY test controller into the local OC4 shop
 * (catalog/controller/ccsmoke.php, removed at the end), uses a copy of order #11 (removed) and voids its voucher.
 * Usage: php tools/smoke-oc4/email.php [base_url]
 */
require dirname(__DIR__) . '/smoke/bootstrap.php';
require_once cc_repo_root() . '/upload/system/library/couriercenter/CCApi.php';
require_once __DIR__ . '/mail.php';

$base = rtrim(isset($argv[1]) ? $argv[1] : 'http://127.0.0.1:8744/opencart4/', '/') . '/';
$root = 'C:/xampp/htdocs/opencart4/';
$cfg = cc_config();
$m = cc_mysql($cfg['oc4_db']);
$P = $cfg['oc4_prefix'];
$secret = bin2hex(random_bytes(8));
$ctrl = $root . 'catalog/controller/ccsmoke.php';
$fails = 0;
function e_ok($c, $w) {
    global $fails;
    echo ($c ? '  ok   ' : '  FAIL ') . $w . "\n";
    $fails += $c ? 0 : 1;
}
$q = function ($sql) use ($m) {
    $r = $m->query($sql);
    if ($r === false) {
        throw new RuntimeException($m->error . ' :: ' . $sql);
    }
    return $r === true ? array() : $r->fetch_all(MYSQLI_ASSOC);
};
file_put_contents($ctrl, "<?php\nnamespace Opencart\\Catalog\\Controller;\nclass Ccsmoke extends \\Opencart\\System\\Engine\\Controller {\n"
    . "    public function index(): void {\n        if ((\$this->request->get['k'] ?? '') !== '$secret') { return; }\n"
    . "        \$this->load->model('checkout/order');\n"
    . "        \$this->model_checkout_order->addHistory((int)\$this->request->get['order_id'], (int)\$this->request->get['status'], 'cc smoke', true);\n"
    . "        \$this->response->setOutput('done');\n    }\n}\n");
$backup = $q("SELECT * FROM `{$P}setting` WHERE `key` IN ('shipping_courier_center_auto_create_enabled', 'shipping_courier_center_auto_create_status_id')");
$id = 0;
$awb = '';
try {
    cc_mail_smtp_on($m, $P);
    $q("DELETE FROM `{$P}setting` WHERE `key` IN ('shipping_courier_center_auto_create_enabled', 'shipping_courier_center_auto_create_status_id')");
    $q("INSERT INTO `{$P}setting` SET `store_id` = 0, `code` = 'shipping_courier_center', `key` = 'shipping_courier_center_auto_create_enabled', `value` = '1', `serialized` = 0");
    $q("INSERT INTO `{$P}setting` SET `store_id` = 0, `code` = 'shipping_courier_center', `key` = 'shipping_courier_center_auto_create_status_id', `value` = '2', `serialized` = 0");
    $src = $q("SELECT * FROM `{$P}order` WHERE `order_id` = 11")[0];
    $row = array_merge($src, array('telephone' => '6912345678', 'email' => 'email-oc4@example.com', 'order_status_id' => 1));
    unset($row['order_id'], $row['date_added'], $row['date_modified']);
    $set = array();
    foreach ($row as $k => $v) {
        $set[] = '`' . $k . "` = '" . $m->real_escape_string((string)$v) . "'";
    }
    $q("INSERT INTO `{$P}order` SET " . implode(', ', $set) . ", `date_added` = NOW(), `date_modified` = NOW()");
    $id = (int)$m->insert_id;
    $q("INSERT INTO `{$P}order_product` (`order_id`, `product_id`, `master_id`, `name`, `model`, `quantity`, `price`, `total`, `tax`, `reward`) SELECT $id, `product_id`, `master_id`, `name`, `model`, 1, `price`, `total`, `tax`, `reward` FROM `{$P}order_product` WHERE `order_id` = 11");
    cc_mailpit_clear();
    $h = new SmokeHttp(cc_state_dir() . '/oc4-email.cookies');
    $r = $h->get($base . 'index.php?route=ccsmoke&k=' . $secret . '&order_id=' . $id . '&status=2');
    e_ok(trim($r['body']) === 'done', 'status change → 2 (auto-create trigger): ' . substr($r['body'], 0, 120));
    $sh = $q("SELECT * FROM `{$P}cc_shipments` WHERE `order_id` = $id");
    $awb = $sh ? (string)$sh[0]['voucher_number'] : '';
    e_ok($awb !== '', 'auto-create made voucher ' . $awb);
    $hist = $q("SELECT `comment` FROM `{$P}order_history` WHERE `order_id` = $id");
    e_ok(strpos(implode("\n", array_column($hist, 'comment')), 'Αυτόματη δημιουργία voucher') !== false, 'auto-create history note');
    $r2 = $h->get($base . 'index.php?route=ccsmoke&k=' . $secret . '&order_id=' . $id . '&status=3');
    e_ok($awb !== '' && cc_mail_with(array($awb, 'Στοιχεία Αποστολής Courier Center')), 'order status email contains the AWB + tracking block');
} catch (Throwable $e) {
    e_ok(false, get_class($e) . ': ' . $e->getMessage());
} finally {
    @unlink($ctrl);
    if ($awb !== '') {
        $cr = cc_oc4_credentials();
        $api = new \Opencart\Extension\Couriercenter\Library\CCApi($cr['user_alias'], $cr['credential_value'], $cr['api_key'], $cr['billing_account']);
        $v = $api->void_shipment($awb);
        echo '  void ' . $awb . ': ' . ($v === true ? 'ok' : json_encode($v)) . "\n";
    }
    if ($id) {
        foreach (array('order', 'order_product', 'order_history', 'order_total', 'cc_shipments') as $t) {
            $q("DELETE FROM `{$P}$t` WHERE `order_id` = $id");
        }
    }
    cc_mail_smtp_off($m, $P);
    $q("DELETE FROM `{$P}setting` WHERE `key` IN ('shipping_courier_center_auto_create_enabled', 'shipping_courier_center_auto_create_status_id')");
    foreach ($backup as $b) {
        $q("INSERT INTO `{$P}setting` SET `store_id` = '" . (int)$b['store_id'] . "', `code` = '" . $m->real_escape_string($b['code']) . "', `key` = '" . $m->real_escape_string($b['key']) . "', `value` = '" . $m->real_escape_string($b['value']) . "', `serialized` = '" . (int)$b['serialized'] . "'");
    }
    echo "  test controller removed: " . (is_file($ctrl) ? 'NO' : 'yes') . "\n";
}
echo $fails ? "[OC4 email] $fails failed\n" : "[OC4 email] all ok\n";
exit($fails ? 1 : 0);
