<?php
/**
 * End-to-end smoke test of the OpenCart 4 extension (1.1.0) against the local OC4 shop, with the REAL API
 * (test account 100-0120-0004 + sub-codes 0001/0003). Uses copies of an existing order (removed at the end)
 * and voids every voucher / return it creates. The shop's own orders and vouchers are never touched.
 * Usage: php tools/smoke-oc4/run.php [base_url]   (default http://127.0.0.1:8744/opencart4/)
 * Needs: the OC4 shop served at base_url, a temporary admin user ccsmoke/ccsmoke123, the local mail sink.
 */
require dirname(__DIR__) . '/smoke/bootstrap.php';
require_once cc_repo_root() . '/upload/system/library/couriercenter/CCApi.php';
require_once __DIR__ . '/mail.php';

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
function q($sql) {
    global $m;
    $r = $m->query($sql);
    if ($r === false) {
        throw new RuntimeException($m->error . ' :: ' . $sql);
    }
    return $r === true ? array() : $r->fetch_all(MYSQLI_ASSOC);
}
function q1($sql) {
    $r = q($sql);
    return $r ? $r[0] : null;
}
function js($res) {
    $j = json_decode($res['body'], true);
    return is_array($j) ? $j : array('success' => false, 'error' => 'NOT JSON: ' . substr($res['body'], 0, 200));
}

$cr = cc_oc4_credentials();
$api = new \Opencart\Extension\Couriercenter\Library\CCApi($cr['user_alias'], $cr['credential_value'], $cr['api_key'], $cr['billing_account']);
$created_awbs = array();
$clones = array();
$customer_id = 0;
$settings_backup = q("SELECT * FROM `{$P}setting` WHERE `code` = 'shipping_courier_center'");

$admin = new SmokeHttp(cc_state_dir() . '/oc4-admin.cookies');
$token = '';
$a = function ($route, array $params = array()) use (&$token, $base) {
    return $base . 'admin/index.php?' . http_build_query(array_merge(array('route' => $route, 'user_token' => $token), $params));
};

try {
    echo "== login\n";
    $page = $admin->get($base . 'admin/index.php?route=common/login')['body'];
    preg_match('/login_token=([a-z0-9]+)/', $page, $lt);
    $r = js($admin->post($base . 'admin/index.php?route=common/login.login&login_token=' . $lt[1], array('username' => 'ccsmoke', 'password' => 'ccsmoke123')));
    preg_match('/user_token=([a-z0-9]+)/', isset($r['redirect']) ? $r['redirect'] : '', $ut);
    $token = isset($ut[1]) ? $ut[1] : '';
    c_ok($token !== '', 'admin login');

    echo "== settings\n";
    $sp = $admin->get($a('extension/couriercenter/shipping/courier_center'))['body'];
    c_ok(strpos($sp, 'id="form-courier-center"') !== false && strpos($sp, 'cc-points-table') !== false, 'settings page renders (1.1.0)');
    c_ok(strpos($sp, $cr['api_key']) === false && preg_match('/name="shipping_courier_center_credential_value" value=""/', $sp) === 1, 'no API key / credential in the page');
    $fields = array();
    foreach ($settings_backup as $row) {
        $fields[$row['key']] = $row['serialized'] ? json_decode($row['value'], true) : html_entity_decode($row['value'], ENT_QUOTES, 'UTF-8');
    }
    $fields['shipping_courier_center_credential_value'] = '';
    $fields['shipping_courier_center_api_key'] = '';
    $fields['shipping_courier_center_tracking_url'] = 'https://example.com/no-placeholder';
    $fields['shipping_courier_center_pickup_points'] = array(
        array('code' => '100-0120-0001', 'label' => 'Κηφισός', 'bill_to' => 'main'),
        array('code' => '100-0120-0001', 'label' => 'dup'),
    );
    foreach (array('returns_enabled' => '1', 'returns_require_approval' => '1', 'returns_method_dropoff' => '1', 'returns_method_boxnow' => '1', 'returns_method_pickup' => '1',
        'returns_window_days' => '14', 'returns_weight' => '1', 'returns_admin_notify' => '1', 'returns_admin_emails' => 'shop-returns-oc4@example.com') as $k => $v) {
        $fields['shipping_courier_center_' . $k] = $v;
    }
    $save = $admin->post($a('extension/couriercenter/shipping/courier_center'), $fields);
    c_ok($save['status'] === 302 && strpos($save['location'], 'courier_center') !== false, 'save with warnings stays on the settings page');
    $cv = q1("SELECT `value` FROM `{$P}setting` WHERE `key` = 'shipping_courier_center_credential_value'");
    c_ok($cv && html_entity_decode($cv['value'], ENT_QUOTES, 'UTF-8') === $cr['credential_value'], 'empty secret keeps the saved credential');
    $tu = q1("SELECT `value` FROM `{$P}setting` WHERE `key` = 'shipping_courier_center_tracking_url'");
    c_ok($tu && strpos($tu['value'], '{{tracking}}') !== false, 'tracking URL without {{tracking}} → default');
    $sp2 = $admin->get($a('extension/couriercenter/shipping/courier_center'))['body'];
    c_ok(strpos($sp2, 'υπάρχει δύο φορές') !== false && strpos($sp2, 'Tracking URL πρέπει') !== false, 'warnings shown after the save');

    $af = js($admin->post($a('extension/couriercenter/shipping/courier_center.autofill'), array()));
    c_ok(!empty($af['success']) && empty($af['warning']), 'main autofill: ' . (isset($af['error']) ? $af['error'] : $af['shipper_name']));
    $ap = js($admin->post($a('extension/couriercenter/shipping/courier_center.autofillPoint'), array('code' => '100-0120-0001')));
    c_ok(!empty($ap['success']) && $ap['data']['name'] !== '', 'sub-code autofill: ' . (isset($ap['error']) ? $ap['error'] : $ap['data']['name']));
    if (!empty($ap['success'])) {
        $d = $ap['data'];
        $fields['shipping_courier_center_pickup_points'] = array(array('code' => '100-0120-0001', 'label' => 'Κηφισός', 'bill_to' => 'main',
            'name' => $d['name'], 'address' => $d['address'], 'postal' => $d['postcode'], 'city' => $d['city'], 'phone' => $d['phone']));
        $fields['shipping_courier_center_tracking_url'] = 'https://www.courier.gr/track/result?tracknr={{tracking}}';
        $admin->post($a('extension/couriercenter/shipping/courier_center'), $fields);
    }
    $rep = js($admin->post($a('extension/couriercenter/shipping/courier_center.repair'), array()));
    c_ok(!empty($rep['success']), 'repair: ' . implode(' · ', isset($rep['done']) ? $rep['done'] : array()));
    c_ok(count(q("SELECT `code` FROM `{$P}event` WHERE `code` LIKE 'courier\\_center\\_%'")) === 15 && !q("SELECT 1 FROM `{$P}event` WHERE `code` = 'courier_center_email'"), '15 events, no separate tracking email');
    foreach (array('street_number_field', 'auto_complete_notify', 'pseudo_cron', 'returns_email_created') as $name) {
        c_ok(strpos($sp2, 'name="shipping_courier_center_' . $name . '"') !== false, "settings page has $name");
    }

    echo "== orders (copies of order #11)\n";
    $src = q1("SELECT * FROM `{$P}order` WHERE `order_id` = 11");
    $mk = function (array $over) use ($src, $P, &$clones) {
        $row = array_merge($src, array('telephone' => '6912345678'), $over);
        unset($row['order_id'], $row['date_added'], $row['date_modified']);
        $set = array();
        foreach ($row as $k => $v) {
            $set[] = '`' . $k . '` = ' . ($v === null ? 'NULL' : "'" . $GLOBALS['m']->real_escape_string((string)$v) . "'");
        }
        q("INSERT INTO `{$P}order` SET " . implode(', ', $set) . ", `date_added` = NOW(), `date_modified` = NOW()");
        $id = (int)$GLOBALS['m']->insert_id;
        q("INSERT INTO `{$P}order_product` (`order_id`, `product_id`, `master_id`, `name`, `model`, `quantity`, `price`, `total`, `tax`, `reward`) SELECT $id, `product_id`, `master_id`, `name`, `model`, 3, `price`, `total`, `tax`, `reward` FROM `{$P}order_product` WHERE `order_id` = 11");
        q("INSERT INTO `{$P}order_history` SET `order_id` = $id, `order_status_id` = '" . (int)$row['order_status_id'] . "', `notify` = 0, `comment` = 'cc smoke copy', `date_added` = NOW()");
        $clones[] = $id;
        return $id;
    };
    $o1 = $mk(array('comment' => 'Κουδούνι Smoke'));
    $o2 = $mk(array());

    $r = js($admin->post($a('extension/couriercenter/shipping/courier_center.createVoucher'), array('order_id' => $o1, 'service_type' => 'next_day')));
    c_ok(!empty($r['success']), 'create voucher (default point = main): ' . (isset($r['error']) ? $r['error'] : $r['voucher']));
    if (!empty($r['voucher'])) {
        $created_awbs[] = $r['voucher'];
    }
    $dup = js($admin->post($a('extension/couriercenter/shipping/courier_center.createVoucher'), array('order_id' => $o1)));
    c_ok(empty($dup['success']) && strpos($dup['error'], 'ενεργό voucher') !== false, 'no second voucher');
    $panel = $admin->get($a('sale/order.info', array('order_id' => $o1)))['body'];
    c_ok(strpos($panel, 'id="cc-panel"') !== false && strpos($panel, 'Εκτύπωση Voucher') !== false, 'order panel shows the active voucher');
    $pdf = $admin->get($a('extension/couriercenter/shipping/courier_center.downloadVoucher', array('order_id' => $o1)));
    c_ok(substr($pdf['body'], 0, 4) === '%PDF', 'voucher PDF');
    $st1 = js($admin->post($a('extension/couriercenter/shipping/courier_center.updateStatus'), array('order_id' => $o1)));
    $st2 = js($admin->post($a('extension/couriercenter/shipping/courier_center.updateStatus'), array('order_id' => $o1)));
    c_ok(empty($st2['success']) && strpos($st2['error'], 'πρόσφατα') !== false, 'status refresh rate-limited (' . json_encode($st1, JSON_UNESCAPED_UNICODE) . ')');
    $v = js($admin->post($a('extension/couriercenter/shipping/courier_center.voidShipment'), array('order_id' => $o1)));
    c_ok(!empty($v['success']), 'void');
    $vp = $admin->get($a('sale/order.info', array('order_id' => $o1)))['body'];
    c_ok(strpos($vp, 'Αποστολή Ακυρωμένη') !== false && strpos($vp, 'data-cc-action="create"') !== false, 'after a void the panel offers a new voucher');
    $r2 = js($admin->post($a('extension/couriercenter/shipping/courier_center.createVoucher'), array('order_id' => $o1, 'pickup_point' => '100-0120-0001')));
    c_ok(!empty($r2['success']), 'new voucher from sub-code 0001: ' . (isset($r2['error']) ? $r2['error'] : $r2['voucher']));
    if (!empty($r2['voucher'])) {
        $created_awbs[] = $r2['voucher'];
        $det = $api->get_shipment_details($r2['voucher']);
        $code = isset($det['ShipmentDetails'][0]['ShipmentInfo']['Shipper']['Code']) ? $det['ShipmentDetails'][0]['ShipmentInfo']['Shipper']['Code'] : '';
        c_ok($code === '100-120-0001', 'API shipper = sub-code (' . $code . ')');
        $cm = isset($det['ShipmentDetails'][0]['ShipmentInfo']['Comments']) ? $det['ShipmentDetails'][0]['ShipmentInfo']['Comments'] : '';
    }
    $row1 = q1("SELECT * FROM `{$P}cc_shipments` WHERE `order_id` = $o1");
    c_ok($row1 && $row1['pickup_point'] === '100-0120-0001' && $row1['requestor_account'] === '100-0120-0004', 'point + billing stored');

    $b = js($admin->post($a('extension/couriercenter/shipping/courier_center.bulkCreate'), array('order_ids' => "$o1,$o2", 'pickup_point' => '')));
    c_ok(!empty($b['success']) && strpos($b['message'], 'Δημιουργήθηκαν: 1') !== false && strpos($b['message'], 'Έχουν ήδη voucher: 1') !== false, 'bulk create: ' . (isset($b['message']) ? str_replace("\n", ' ', $b['message']) : json_encode($b, JSON_UNESCAPED_UNICODE)));
    $row2 = q1("SELECT * FROM `{$P}cc_shipments` WHERE `order_id` = $o2");
    if ($row2 && $row2['voucher_number']) {
        $created_awbs[] = $row2['voucher_number'];
    }
    $bp = $admin->get($a('extension/couriercenter/shipping/courier_center.bulkPrint', array('order_ids' => "$o1,$o2")));
    c_ok(substr($bp['body'], 0, 4) === '%PDF', 'bulk print PDF');
    $bs = js($admin->post($a('extension/couriercenter/shipping/courier_center.bulkStatus'), array('order_ids' => "$o1,$o2")));
    c_ok(!empty($bs['success']), 'bulk status: ' . (isset($bs['message']) ? str_replace("\n", ' ', $bs['message']) : ''));
    $list = $admin->get($a('sale/order.list'))['body'];
    c_ok(strpos($list, 'cc-bulk-bar') !== false && strpos($list, 'ccListData') !== false && strpos($list, 'cc-bulk-point') !== false, 'order list: bulk bar with the point select');
    $day = q1("SELECT DATE(`created_at`) AS d FROM `{$P}cc_shipments` WHERE `order_id` = $o1");
    $mp = $admin->get($a('extension/couriercenter/shipping/courier_center_manifest', array('account' => '100-0120-0001', 'manifest_date' => $day['d'])))['body'];
    c_ok(strpos($mp, 'name="account"') !== false && strpos($mp, '1 vouchers') !== false && strpos($mp, 'name="route"') !== false, 'manifest page: point filter, count, working form');
    $mpdf = $admin->get($a('extension/couriercenter/shipping/courier_center_manifest.download', array('date' => date('Y-m-d'), 'account' => '100-0120-0001')));
    c_ok(substr($mpdf['body'], 0, 4) === '%PDF', 'manifest PDF of the sub-code: ' . substr($mpdf['body'], 0, 60));

    echo "== returns\n";
    cc_mail_smtp_on($m, $P);
    cc_mailpit_clear();
    $email = 'returns-oc4@example.com';
    q("DELETE FROM `{$P}customer` WHERE `email` = '$email'");
    q("INSERT INTO `{$P}customer` SET `customer_group_id` = 1, `store_id` = 0, `language_id` = 1, `firstname` = 'Ρένα', `lastname` = 'OC4', `email` = '$email', `telephone` = '6912345678', `password` = '" . password_hash('ret123', PASSWORD_DEFAULT) . "', `custom_field` = '', `newsletter` = 0, `ip` = '', `status` = 1, `safe` = 0, `token` = '', `code` = '', `date_added` = NOW()");
    $customer_id = (int)q1("SELECT `customer_id` FROM `{$P}customer` WHERE `email` = '$email'")['customer_id'];
    $o3 = $mk(array('customer_id' => $customer_id, 'email' => $email, 'order_status_id' => 5));
    $r3 = js($admin->post($a('extension/couriercenter/shipping/courier_center.createVoucher'), array('order_id' => $o3)));
    c_ok(!empty($r3['success']), 'original voucher for the return: ' . (isset($r3['error']) ? $r3['error'] : $r3['voucher']));
    $awb3 = isset($r3['voucher']) ? $r3['voucher'] : '';
    if ($awb3 !== '') {
        $created_awbs[] = $awb3;
    }
    $cust = new SmokeHttp(cc_state_dir() . '/oc4-customer.cookies');
    $lp = $cust->get($base . 'index.php?route=account/login&language=en-gb')['body'];
    preg_match('/login_token=([a-z0-9]+)/', $lp, $clt);
    $cl = js($cust->post($base . 'index.php?route=account/login.login&language=en-gb&login_token=' . (isset($clt[1]) ? $clt[1] : ''), array('email' => $email, 'password' => 'ret123')));
    preg_match('/customer_token=([a-z0-9]+)/', isset($cl['redirect']) ? $cl['redirect'] : '', $ct);
    $ctok = isset($ct[1]) ? $ct[1] : '';
    c_ok($ctok !== '', 'customer login');
    $info = $cust->get($base . 'index.php?' . http_build_query(array('route' => 'account/order.info', 'language' => 'en-gb', 'customer_token' => $ctok, 'order_id' => $o3)))['body'];
    c_ok(strpos($info, 'id="cc-returns"') !== false && strpos($info, 'Αίτημα επιστροφής') !== false, 'account order page offers the return request');
    $form_url = $base . 'index.php?' . http_build_query(array('route' => 'extension/couriercenter/shipping/courier_center_returns', 'language' => 'en-gb', 'customer_token' => $ctok, 'order_id' => $o3));
    $form = $cust->get($form_url)['body'];
    preg_match('/name="request_uid" value="([^"]+)"/', $form, $u);
    preg_match('/name="qty\[(\d+)\]"/', $form, $qi);
    $fields_r = array('request_uid' => isset($u[1]) ? $u[1] : '', 'method' => 'BOXNOW', 'locker_size' => 'small', 'name' => 'Ρένα OC4', 'mobile' => '6912345678', 'qty[' . (isset($qi[1]) ? $qi[1] : 0) . ']' => '1');
    $s1 = $cust->post($form_url, $fields_r);
    $s2 = $cust->post($form_url, $fields_r);
    $rq = q1("SELECT * FROM `{$P}cc_return_requests` WHERE `order_id` = $o3");
    c_ok($s1['status'] === 302 && $s2['status'] === 302 && $rq && $rq['status'] === 'pending_approval' && count(q("SELECT id FROM `{$P}cc_return_requests` WHERE `order_id` = $o3")) === 1, 'BOX NOW return requested (double submit → one)');
    $nologin = (new SmokeHttp(cc_state_dir() . '/oc4-anon.cookies'))->get($form_url);
    c_ok($nologin['status'] === 302 && strpos($nologin['location'], 'account/login') !== false, 'guest → login page');
    // Pickup request (date from the next working day, 4-hour window)
    require_once cc_repo_root() . '/upload/system/library/couriercenter/CCReturnsRules.php';
    $dates = \Opencart\Extension\Couriercenter\Library\CCReturnsRules::pickup_dates(\Opencart\Extension\Couriercenter\Library\CCReturnsRules::now_local());
    $form = $cust->get($form_url)['body'];
    c_ok(strpos($form, 'id="cc-pickup"') !== false && strpos($form, 'value="' . $dates[0] . '"') !== false, 'the form offers Pickup');
    preg_match('/name="request_uid" value="([^"]+)"/', $form, $u2);
    preg_match_all('/name="qty\[(\d+)\]"/', $form, $qall);
    $pk_item = isset($qall[1][0]) ? $qall[1][0] : 0;
    $pfields = array('request_uid' => isset($u2[1]) ? $u2[1] : '', 'method' => 'COURIER_CENTER_PICKUP', 'name' => 'Ρένα OC4', 'mobile' => '6912345678',
        'pickup_address' => 'Ακαδημίας 10', 'pickup_city' => 'ΑΘΗΝΑ', 'pickup_postcode' => '10679', 'pickup_date' => $dates[1], 'pickup_time_from' => '09:00', 'pickup_time_to' => '13:00',
        'courier_notes' => 'smoke oc4', 'qty[' . $pk_item . ']' => '1');
    $bad = $cust->post($form_url, array_merge($pfields, array('pickup_time_to' => '11:00')));
    c_ok($bad['status'] === 200 && strpos($bad['body'], 'τουλάχιστον 4 ώρες') !== false, 'Pickup window under 4 hours refused');
    $sp1 = $cust->post($form_url, $pfields);
    $prq = q1("SELECT * FROM `{$P}cc_return_requests` WHERE `order_id` = $o3 AND `method` = 'COURIER_CENTER_PICKUP'");
    c_ok($sp1['status'] === 302 && $prq && $prq['pickup_date'] === $dates[1] && $prq['pickup_time_to'] === '13:00', 'Pickup return requested');
    c_ok(cc_mail_with(array('Νέο αίτημα επιστροφής', 'Παραλαβή:')), 'shop got the «Νέο αίτημα επιστροφής» email');
    $dash = $admin->get($a('common/dashboard'))['body'];
    c_ok(strpos($dash, 'id="cc-returns-notice"') !== false, 'dashboard notice about the waiting requests');
    c_ok(preg_match('/Courier Center \((\d+)\)/u', $dash, $mc) === 1 && (int)$mc[1] >= 2, 'menu counter (' . (isset($mc[1]) ? $mc[1] : '-') . ')');
    $ol = $admin->get($a('sale/order'))['body'];
    c_ok(strpos($ol, 'id="cc-returns-notice"') !== false && preg_match('/var RETURNS = (\{.*?\});/', $ol, $rm) === 1 && strpos($rm[1], '"' . $o3 . '"') !== false, 'orders page: notice + «Επιστροφή» column');

    $rp = $admin->get($a('extension/couriercenter/shipping/courier_center_returns'))['body'];
    c_ok($rq && strpos($rp, 'data-id="' . $rq['id'] . '"') !== false && strpos($rp, 'Παραλαβή: ') !== false, 'admin returns page lists them (with the pickup date)');
    $menu = $admin->get($a('common/dashboard'))['body'];
    foreach (array('Manifest Παραλαβής', 'Οδηγίες', 'Αναφορά Προβλήματος') as $item) {
        c_ok(strpos($menu, $item) !== false, "Courier Center submenu: $item");
    }
    $ordlist = $cust->get($base . 'index.php?' . http_build_query(array('route' => 'account/order', 'language' => 'en-gb', 'customer_token' => $ctok)))['body'];
    c_ok(strpos($ordlist, 'cc-return-btn') !== false, 'customer orders list: «Αίτημα Επιστροφής» button');
    $ret_awb = '';
    if ($rq) {
        $ap2 = js($admin->post($a('extension/couriercenter/shipping/courier_center_returns.approve'), array('id' => $rq['id'])));
        $rq2 = q1("SELECT * FROM `{$P}cc_return_requests` WHERE `id` = " . (int)$rq['id']);
        $ret_awb = $rq2 ? $rq2['return_awb'] : '';
        c_ok(!empty($ap2['success']) && $rq2['status'] === 'shipment_created' && $rq2['return_shipment_number'] !== '', 'approved → BOX NOW return ' . $ret_awb . ' code ' . ($rq2 ? $rq2['return_shipment_number'] : '') . (empty($ap2['error']) ? '' : ' / ' . $ap2['error']));
        $tr = js($admin->post($a('extension/couriercenter/shipping/courier_center_returns.track'), array('id' => $rq['id'])));
        c_ok(!empty($tr['success']), 'return tracking: ' . (isset($tr['error']) ? $tr['error'] : 'ok'));
        $info2 = $cust->get($base . 'index.php?' . http_build_query(array('route' => 'account/order.info', 'language' => 'en-gb', 'customer_token' => $ctok, 'order_id' => $o3)))['body'];
        c_ok(strpos($info2, 'Κωδικός BOX NOW επιστροφής') !== false, 'customer sees the BOX NOW code');
        c_ok(cc_mail_with(array('το αίτημα ελήφθη')) && cc_mail_with(array('δημιουργήθηκε η αποστολή')), 'returns emails («ελήφθη», «δημιουργήθηκε») sent');
        if ($prq) {
            $ap3 = js($admin->post($a('extension/couriercenter/shipping/courier_center_returns.approve'), array('id' => $prq['id'])));
            $prq2 = q1("SELECT * FROM `{$P}cc_return_requests` WHERE `id` = " . (int)$prq['id']);
            c_ok(!empty($ap3['success']) && $prq2['status'] === 'shipment_created', 'Pickup approved → ' . ($prq2 ? $prq2['return_awb'] : '') . (empty($ap3['error']) ? '' : ' / ' . $ap3['error']));
            if ($prq2 && $prq2['return_awb'] !== '') {
                $created_awbs[] = $prq2['return_awb'];
                $pd = $api->get_shipment_details($prq2['return_awb']);
                $pi = isset($pd['ShipmentDetails'][0]['ShipmentInfo']) ? $pd['ShipmentDetails'][0]['ShipmentInfo'] : array();
                c_ok(isset($pi['Shipper']['ZipCode']) && trim((string)$pi['Shipper']['ZipCode']) === '10679', 'Pickup shipper = customer address on the API');
            }
        }
        $pp = $admin->get($a('sale/order.info', array('order_id' => $o3)))['body'];
        c_ok(strpos($pp, '↩️ Επιστροφές') !== false && strpos($pp, 'id="cc-add-return"') !== false && strpos($pp, 'Παραλαβή: <strong>') !== false, 'order panel lists the returns + «Νέα επιστροφή»');
        // «Νέα επιστροφή» of the shop (Drop Off, no customer email)
        $np = $admin->get($a('extension/couriercenter/shipping/courier_center_returns.add', array('order_id' => $o3)))['body'];
        if (preg_match('/name="request_uid" value="([^"]+)"/', $np, $su) && preg_match_all('/name="qty\[(\d+)\]"/', $np, $sq)) {
            cc_mailpit_clear();
            $post = array('request_uid' => $su[1], 'method' => 'COURIER_CENTER_DROPOFF', 'name' => 'Ρένα OC4', 'mobile' => '6912345678', 'reason' => 'smoke shop');
            $post['qty[' . end($sq[1]) . ']'] = '1';
            $res = $admin->post($a('extension/couriercenter/shipping/courier_center_returns.add', array('order_id' => $o3)), $post);
            $srow = q1("SELECT * FROM `{$P}cc_return_requests` WHERE `request_uid` = '" . $m->real_escape_string($su[1]) . "'");
            c_ok($res['status'] === 302 && $srow && $srow['origin'] === 'shop' && $srow['status'] === 'shipment_created' && (int)$srow['notify_customer'] === 0,
                'shop return created at once: ' . ($srow ? $srow['return_awb'] . ' ' . $srow['last_error'] : 'no row' . (preg_match('/id="cc-add-error">(.*?)<\/div>/s', $res['body'], $er) ? ' — ' . trim(strip_tags($er[1])) : ' (HTTP ' . $res['status'] . ')')));
            if ($srow && $srow['return_awb'] !== '') {
                $created_awbs[] = $srow['return_awb'];
            }
            $after = $admin->get($a('sale/order.info', array('order_id' => $o3)))['body'];
            c_ok(strpos($after, 'id="cc-return-notice"') !== false && strpos($after, 'Από: Κατάστημα') !== false, 'order panel: success notice + «Από: Κατάστημα»');
            c_ok(!cc_mail_with(array('δημιουργήθηκε η αποστολή')), 'no customer email without «Αποστολή email στον πελάτη»');
        } else {
            c_ok(false, '«Νέα επιστροφή» page did not render');
        }
    }

    echo "== round 3\n";
    $olist = $admin->get($a('sale/order'))['body'];
    c_ok(strpos($olist, 'Εκτύπωση BOX NOW') !== false && strpos($olist, "badge('Νέα'") !== false, 'order list: «Εκτύπωση BOX NOW» button + «Νέα» badge');
    // BOX NOW removal note
    $bn = $mk(array());
    q("UPDATE `{$P}order` SET `custom_field` = '" . $m->real_escape_string(json_encode(array('cc_delivery_mode' => 'auto'))) . "' WHERE `order_id` = $bn");
    $rm = js($admin->post($a('extension/couriercenter/shipping/courier_center.removeBoxNow'), array('order_id' => $bn)));
    c_ok(!empty($rm['success']) && q1("SELECT 1 AS x FROM `{$P}order_history` WHERE `order_id` = $bn AND `comment` LIKE '%BOX NOW αφαιρέθηκε%'"), 'BOX NOW removal: success + order note');
    // Street number from a custom field → voucher address
    q("INSERT INTO `{$P}custom_field` SET `type` = 'text', `value` = '', `validation` = '', `location` = 'address', `status` = 1, `sort_order` = 0");
    $cfid = (int)$m->insert_id;
    q("INSERT INTO `{$P}custom_field_description` SET `custom_field_id` = $cfid, `language_id` = 1, `name` = 'Αριθμός smoke'");
    $spg = $admin->get($a('extension/couriercenter/shipping/courier_center'))['body'];
    c_ok(strpos($spg, 'Αριθμός smoke') !== false, 'the address custom field is offered as the number field');
    q("DELETE FROM `{$P}setting` WHERE `key` = 'shipping_courier_center_street_number_field'");
    q("INSERT INTO `{$P}setting` SET `store_id` = 0, `code` = 'shipping_courier_center', `key` = 'shipping_courier_center_street_number_field', `value` = '$cfid', `serialized` = 0");
    $on = $mk(array());
    q("UPDATE `{$P}order` SET `shipping_custom_field` = '" . $m->real_escape_string(json_encode(array((string)$cfid => '77'))) . "' WHERE `order_id` = $on");
    $pp2 = $admin->get($a('sale/order.info', array('order_id' => $on)))['body'];
    c_ok(preg_match('/ 77,/', $pp2) === 1, 'the panel previews the address WITH the number');
    $vn = js($admin->post($a('extension/couriercenter/shipping/courier_center.createVoucher'), array('order_id' => $on)));
    c_ok(!empty($vn['success']), 'voucher with the number: ' . (isset($vn['error']) ? $vn['error'] : $vn['voucher']));
    if (!empty($vn['voucher'])) {
        $created_awbs[] = $vn['voucher'];
        $dn = $api->get_shipment_details($vn['voucher']);
        c_ok(strpos((string)($dn['ShipmentDetails'][0]['ShipmentInfo']['Consignee']['Address'] ?? ''), '77') !== false, 'the API consignee address carries the number');
    }
    q("DELETE FROM `{$P}setting` WHERE `key` = 'shipping_courier_center_street_number_field'");
    q("DELETE FROM `{$P}custom_field_description` WHERE `custom_field_id` = $cfid");
    q("DELETE FROM `{$P}custom_field` WHERE `custom_field_id` = $cfid");
    // Pseudo-cron: due → the storefront visit fires one request which runs the tracker
    $devrow = q1("SELECT `value` FROM `{$P}setting` WHERE `key` = 'shipping_courier_center_dev_overrides'");
    q("DELETE FROM `{$P}setting` WHERE `key` = 'shipping_courier_center_dev_overrides'");
    $stset = function ($k, $v) use ($P, $m) {
        q("DELETE FROM `{$P}setting` WHERE `key` = 'shipping_courier_center_$k'");
        q("INSERT INTO `{$P}setting` SET `store_id` = 0, `code` = 'courier_center_state', `key` = 'shipping_courier_center_$k', `value` = '" . $m->real_escape_string((string)$v) . "', `serialized` = 0");
    };
    $stget = function ($k) use ($P) {
        $r = q1("SELECT `value` FROM `{$P}setting` WHERE `key` = 'shipping_courier_center_$k'");
        return $r ? (int)$r['value'] : 0;
    };
    // (the shop's own URL points at port 80, taken by another server: for this check the storefront config points at the test server)
    $rootcfg = 'C:/xampp/htdocs/opencart4/config.php';
    $rootcfg_orig = file_get_contents($rootcfg);
    file_put_contents($rootcfg, str_replace('http://localhost/opencart4/', $base, $rootcfg_orig));
    $old = time() - 20000;
    $stset('cron_last_run_ts', $old);
    $stset('cron_fired_at', 0);
    $stset('pseudo_cron', 1);
    (new SmokeHttp(cc_state_dir() . '/oc4-visit.cookies'))->get($base . 'index.php?route=common/home&language=en-gb');
    c_ok($stget('cron_fired_at') > 0, 'pseudo-cron: the visit fired the cron request');
    $ran = false;
    for ($i = 0; $i < 80 && !$ran; $i++) {
        usleep(500000);
        $ran = $stget('cron_last_run_ts') > $old + 100;
    }
    c_ok($ran, 'the fired request ran the tracker (cron_last_run_ts updated)');
    file_put_contents($rootcfg, $rootcfg_orig);
    q("DELETE FROM `{$P}setting` WHERE `key` = 'shipping_courier_center_pseudo_cron'");
    $stset('dev_overrides', $devrow ? $devrow['value'] : json_encode(array('no_pseudo_cron' => true)));

    echo "== cron\n";
    $key = q1("SELECT `value` FROM `{$P}setting` WHERE `key` = 'shipping_courier_center_cron_key'");
    $bad = (new SmokeHttp(cc_state_dir() . '/oc4-cron.cookies'))->get($base . 'index.php?route=extension/couriercenter/shipping/courier_center_cron&key=wrong');
    c_ok($bad['status'] === 403, 'cron URL with a wrong key: 403');
    $cr2 = (new SmokeHttp(cc_state_dir() . '/oc4-cron.cookies'))->get($base . 'index.php?route=extension/couriercenter/shipping/courier_center_cron&key=' . ($key ? $key['value'] : ''));
    c_ok(strpos($cr2['body'], 'checked=') !== false, 'cron URL: ' . trim(strtok($cr2['body'], "\n")));
    list($code, $out) = cc_exec(array(cc_php('8.2'), 'C:/xampp/htdocs/opencart4/extension/couriercenter/cron_status_tracker.php'));
    c_ok($code === 0 && strpos($out, 'checked=') !== false, 'cron CLI: ' . trim(substr($out, strrpos($out, '[') ?: 0)));

    if ($ret_awb !== '') {
        $vr = $api->void_shipment($ret_awb);
        c_ok($vr === true, 'void return ' . $ret_awb);
    }
} catch (Throwable $e) {
    c_ok(false, get_class($e) . ': ' . $e->getMessage());
} finally {
    if (isset($rootcfg, $rootcfg_orig)) {
        file_put_contents($rootcfg, $rootcfg_orig); // never leave the storefront config pointing at the test server
    }
    echo "== cleanup\n";
    foreach ($created_awbs as $awb) {
        $res = $api->void_shipment($awb);
        echo '  void ' . $awb . ': ' . ($res === true ? 'ok' : json_encode($res, JSON_UNESCAPED_UNICODE)) . "\n";
    }
    foreach ($clones as $id) {
        foreach (array('order', 'order_product', 'order_history', 'order_total', 'cc_shipments', 'cc_return_requests') as $t) {
            if (q("SHOW TABLES LIKE '{$P}$t'")) {
                q("DELETE FROM `{$P}$t` WHERE `order_id` = " . (int)$id);
            }
        }
    }
    if ($customer_id) {
        q("DELETE FROM `{$P}customer` WHERE `customer_id` = $customer_id");
    }
    cc_mail_smtp_off($m, $P);
    // Settings back as they were (+ nothing of the test pickup points / returns).
    q("DELETE FROM `{$P}setting` WHERE `code` = 'shipping_courier_center'");
    foreach ($settings_backup as $row) {
        $set = array();
        foreach ($row as $k => $v) {
            if ($k !== 'setting_id') {
                $set[] = '`' . $k . "` = '" . $GLOBALS['m']->real_escape_string((string)$v) . "'";
            }
        }
        q("INSERT INTO `{$P}setting` SET " . implode(', ', $set));
    }
    echo "  removed " . count($clones) . " order copies, test customer, settings restored\n";
}
printf("\n[OC4] %d ok, %d failed\n", $ok, count($fail));
foreach ($fail as $f) {
    echo "  - $f\n";
}
exit($fail ? 1 : 0);
