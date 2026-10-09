<?php
/**
 * 1.1.0 customer returns with the real API: customer request from account/order/info (Drop Off and BOX NOW),
 * admin approval → return shipment (Reference1 = original AWB), tracking, emails. Every return AWB is voided here.
 */
return function (SmokeContext $c) {
    $s = $c->shop;
    $s->login();
    $set = function ($key, $value) use ($s) {
        $s->exec("DELETE FROM `{p}setting` WHERE `key` = 'shipping_courier_center_$key'");
        $s->exec("INSERT INTO `{p}setting` SET `store_id` = 0, `code` = 'shipping_courier_center', `key` = 'shipping_courier_center_$key', `value` = '" . $s->db()->real_escape_string($value) . "', `serialized` = 0");
    };
    foreach (array('returns_enabled' => '1', 'returns_require_approval' => '1', 'returns_method_dropoff' => '1', 'returns_method_boxnow' => '1', 'returns_window_days' => '14') as $k => $v) {
        $set($k, $v);
    }
    cc_mailpit_clear();

    // A customer account (OpenCart 3 accepts an MD5 password) that owns a completed order with an active voucher.
    $email = 'returns-' . $s->id . '@example.com';
    $s->exec("DELETE FROM `{p}customer` WHERE `email` = '$email'");
    $s->exec("INSERT INTO `{p}customer` SET `customer_group_id` = 1, `store_id` = 0, `language_id` = 1, `firstname` = 'Ρένα', `lastname` = 'Επιστροφή', `email` = '$email', `telephone` = '6912345678', `fax` = '', `password` = '" . md5('ret123') . "', `salt` = '', `newsletter` = 0, `address_id` = 0, `custom_field` = '', `ip` = '', `status` = 1, `safe` = 0, `token` = '', `code` = '', `date_added` = NOW()");
    $cust = $s->row("SELECT `customer_id` FROM `{p}customer` WHERE `email` = '$email'");
    $cid = (int)$cust['customer_id'];
    $order = $s->createApiOrder('courier_center.courier_center', 'bank_transfer', array(43 => 2, 40 => 1));
    $s->exec("UPDATE `{p}order` SET `customer_id` = '$cid', `email` = '$email' WHERE `order_id` = '$order'");
    $r = $s->adminJson('extension/shipping/courier_center/createVoucher', array('order_id' => $order, 'service_type' => 'next_day'));
    $c->ok(!empty($r['success']), 'original voucher: ' . (isset($r['error']) ? $r['error'] : $r['voucher']));
    $awb = isset($r['voucher']) ? $r['voucher'] : '';
    $c->track($awb);
    $complete = $s->row("SELECT `value` FROM `{p}setting` WHERE `key` = 'config_complete_status'");
    $ids = $complete ? json_decode($complete['value'], true) : array(5);
    $done = (int)(is_array($ids) && $ids ? reset($ids) : 5);
    $s->exec("UPDATE `{p}order` SET `order_status_id` = '$done' WHERE `order_id` = '$order'");
    $s->exec("INSERT INTO `{p}order_history` SET `order_id` = '$order', `order_status_id` = '$done', `notify` = 0, `comment` = 'smoke complete', `date_added` = NOW()");

    $cli = new SmokeHttp(cc_state_dir() . "/{$s->id}-customer.cookies");
    $cli->post($s->shopUrl('account/login'), array('email' => $email, 'password' => 'ret123'));
    $info = $cli->get($s->shopUrl('account/order/info', array('order_id' => $order)))['body'];
    $c->ok(strpos($info, 'id="cc-returns"') !== false && strpos($info, 'Αίτημα επιστροφής') !== false, 'account order page offers the return request');

    $returns = array();
    $request = function ($method, array $extra) use ($cli, $s, $order, &$returns, $c) {
        $form = $cli->get($s->shopUrl('extension/shipping/courier_center/returns', array('order_id' => $order)))['body'];
        if (!preg_match('/name="request_uid" value="([^"]+)"/', $form, $u) || !preg_match_all('/name="qty\[(\d+)\]"/', $form, $q)) {
            $c->fail("$method: return form did not render");
            return 0;
        }
        $fields = array_merge(array('request_uid' => $u[1], 'method' => $method, 'name' => 'Ρένα Επιστροφή', 'mobile' => '6912345678', 'landline' => '', 'reason' => 'smoke <b>test</b>'), $extra);
        $fields['qty[' . $q[1][0] . ']'] = '1';
        $res = $cli->post($s->shopUrl('extension/shipping/courier_center/returns', array('order_id' => $order)), $fields);
        $again = $cli->post($s->shopUrl('extension/shipping/courier_center/returns', array('order_id' => $order)), $fields);
        $row = $s->row("SELECT * FROM `{p}cc_return_requests` WHERE `request_uid` = '" . $s->db()->real_escape_string($u[1]) . "'");
        $c->ok($res['status'] === 302 && $again['status'] === 302 && $row && $row['status'] === 'pending_approval', "$method request submitted (double submit → one request)");
        $returns[] = $row ? (int)$row['id'] : 0;
        return $row ? (int)$row['id'] : 0;
    };
    $drop = $request('COURIER_CENTER_DROPOFF', array());
    $form = $cli->get($s->shopUrl('extension/shipping/courier_center/returns', array('order_id' => $order)))['body'];
    preg_match('/name="qty\[(\d+)\]"/', $form, $qm);
    $bad = $cli->post($s->shopUrl('extension/shipping/courier_center/returns', array('order_id' => $order)), array('request_uid' => 'abcdefgh12345', 'method' => 'BOXNOW', 'name' => 'X', 'mobile' => '2101234567', 'locker_size' => 'small', 'qty[' . (isset($qm[1]) ? $qm[1] : 0) . ']' => '1'));
    $c->ok($bad['status'] === 200 && strpos($bad['body'], 'κινητό') !== false, 'BOX NOW without a mobile is refused');
    $box = $request('BOXNOW', array('locker_size' => 'medium'));
    $c->ok(cc_mail_with(array('το αίτημα ελήφθη', '#' . $order)), 'customer got the «ελήφθη» email');

    // Someone else cannot open the form of this order.
    $other = new SmokeHttp(cc_state_dir() . "/{$s->id}-other.cookies");
    $foreign = $other->get($s->shopUrl('extension/shipping/courier_center/returns', array('order_id' => $order)));
    $c->ok($foreign['status'] === 302 && strpos($foreign['location'], 'account/login') !== false, 'guest is sent to the login page');

    // Admin: list page + approve → return shipment on the API
    $list = $s->adminGet('extension/shipping/courier_center/returns')['body'];
    $c->ok(strpos($list, 'Επιστροφές Courier Center') !== false && strpos($list, 'data-id="' . $drop . '"') !== false, 'admin returns page lists the requests');
    $created = array();
    foreach (array('Drop Off' => $drop, 'BOX NOW' => $box) as $label => $id) {
        if (!$id) {
            continue;
        }
        $a = $s->adminJson('extension/shipping/courier_center/returns/approve', array('id' => $id));
        $row = $s->row("SELECT * FROM `{p}cc_return_requests` WHERE `id` = '$id'");
        $c->ok(!empty($a['success']) && $row['status'] === 'shipment_created' && $row['return_awb'] !== '', "$label approved → return AWB " . (isset($row['return_awb']) ? $row['return_awb'] : '') . (empty($a['error']) ? '' : ' / ' . $a['error']));
        if ($row && $row['return_awb'] !== '') {
            $created[$label] = $row;
        }
    }
    if (isset($created['BOX NOW'])) {
        $c->ok($created['BOX NOW']['return_shipment_number'] !== '', 'BOX NOW return code stored: ' . $created['BOX NOW']['return_shipment_number']);
    }
    require_once cc_repo_root() . '/upload/system/library/couriercenter/CCApi.php';
    $cr = cc_oc4_credentials();
    $api = new \Opencart\Extension\Couriercenter\Library\CCApi($cr['user_alias'], $cr['credential_value'], $cr['api_key'], $cr['billing_account']);
    try {
        if (isset($created['Drop Off'])) {
            $det = $api->get_shipment_details($created['Drop Off']['return_awb']);
            $info0 = isset($det['ShipmentDetails'][0]['ShipmentInfo']) ? $det['ShipmentDetails'][0]['ShipmentInfo'] : array();
            $c->ok(isset($info0['Reference1']) && $info0['Reference1'] === $awb, 'Reference1 of the return = original AWB');
            $t = $s->adminJson('extension/shipping/courier_center/returns/track', array('id' => $drop));
            $c->ok(!empty($t['success']), 'return tracking refresh: ' . (isset($t['error']) ? $t['error'] : 'ok'));
            $pdf = $s->adminGet('extension/shipping/courier_center/returns/voucher', array('id' => $drop));
            $c->ok(substr($pdf['body'], 0, 4) === '%PDF', 'return voucher PDF');
        }
        $c->ok(cc_mail_with(array('δημιουργήθηκε η αποστολή', '#' . $order)), 'customer got the «δημιουργήθηκε» email');
        $info2 = $cli->get($s->shopUrl('account/order/info', array('order_id' => $order)))['body'];
        $c->ok(strpos($info2, 'Η επιστροφή σας δημιουργήθηκε επιτυχώς!') !== false, 'customer sees the created return on the order page');
        $panel = $s->adminGet('sale/order/info', array('order_id' => $order))['body'];
        $c->ok(strpos($panel, 'Επιστροφές πελάτη') !== false, 'order tab lists the returns');
        // The original voucher's status must still be the ORIGINAL's (its details also list the returns).
        $st = $api->get_shipment_details($awb);
        require_once cc_repo_root() . '/upload/system/library/couriercenter/CCStatusTracker.php';
        $parsed = \Opencart\Extension\Couriercenter\Library\CCStatusTracker::parse_status($st, $awb);
        $c->ok($parsed['code'] !== '', 'original AWB status parsed (' . count(isset($st['ShipmentDetails']) ? $st['ShipmentDetails'] : array()) . ' entries in its details)');
        // Reject is refused once created
        $rj = $s->adminJson('extension/shipping/courier_center/returns/reject', array('id' => $drop));
        $c->ok(empty($rj['success']), 'a created return cannot be rejected');
    } finally {
        foreach ($created as $label => $row) {
            $v = $api->void_shipment($row['return_awb']);
            $c->ok($v === true, "void return $label " . $row['return_awb'] . ($v === true ? '' : ' -> ' . json_encode($v, JSON_UNESCAPED_UNICODE)));
        }
    }
    $set('returns_enabled', '0');
};
