<?php
/**
 * 1.1.0 returns with the real API: customer request from account/order/info (Drop Off, BOX NOW and Pickup),
 * admin approval → return shipment (Reference1 = original AWB), tracking, emails, shop notice / counter / order
 * list column, and a return the shop creates («Νέα επιστροφή»). Every return AWB is voided here.
 */
return function (SmokeContext $c) {
    $s = $c->shop;
    $s->login();
    $set = function ($key, $value) use ($s) {
        $s->exec("DELETE FROM `{p}setting` WHERE `key` = 'shipping_courier_center_$key'");
        $s->exec("INSERT INTO `{p}setting` SET `store_id` = 0, `code` = 'shipping_courier_center', `key` = 'shipping_courier_center_$key', `value` = '" . $s->db()->real_escape_string($value) . "', `serialized` = 0");
    };
    foreach (array('returns_enabled' => '1', 'returns_require_approval' => '1', 'returns_method_dropoff' => '1', 'returns_method_boxnow' => '1', 'returns_method_pickup' => '1',
        'returns_window_days' => '14', 'returns_admin_notify' => '1', 'returns_admin_emails' => 'shop-returns@example.com') as $k => $v) {
        $set($k, $v);
    }
    cc_mailpit_clear();

    // A customer account (OpenCart 3 accepts an MD5 password) that owns a completed order with an active voucher.
    $email = 'returns-' . $s->id . '@example.com';
    $s->exec("DELETE FROM `{p}customer` WHERE `email` = '$email'");
    $s->exec("INSERT INTO `{p}customer` SET `customer_group_id` = 1, `store_id` = 0, `language_id` = 1, `firstname` = 'Ρένα', `lastname` = 'Επιστροφή', `email` = '$email', `telephone` = '6912345678', `fax` = '', `password` = '" . md5('ret123') . "', `salt` = '', `newsletter` = 0, `address_id` = 0, `custom_field` = '', `ip` = '', `status` = 1, `safe` = 0, `token` = '', `code` = '', `date_added` = NOW()");
    $cust = $s->row("SELECT `customer_id` FROM `{p}customer` WHERE `email` = '$email'");
    $cid = (int)$cust['customer_id'];
    $order = $s->createApiOrder('courier_center.courier_center', 'bank_transfer', array(43 => 3, 40 => 2));
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
    $olist = $cli->get($s->shopUrl('account/order'))['body'];
    $c->ok(strpos($olist, 'cc-return-btn') !== false && strpos($olist, 'Αίτημα Επιστροφής') !== false, 'customer orders list: «Αίτημα Επιστροφής» button for the eligible order');

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
    require_once cc_repo_root() . '/upload/system/library/couriercenter/CCReturnsRules.php';
    $dates = \Opencart\Extension\Couriercenter\Library\CCReturnsRules::pickup_dates(\Opencart\Extension\Couriercenter\Library\CCReturnsRules::now_local());
    $form = $cli->get($s->shopUrl('extension/shipping/courier_center/returns', array('order_id' => $order)))['body'];
    $c->ok(strpos($form, 'id="cc-pickup"') !== false && strpos($form, 'value="' . $dates[0] . '"') !== false && strpos($form, 'name="pickup_time_from"') !== false, 'the form offers Pickup (dates from the next working day, window)');
    $pickupFields = array('pickup_address' => 'Ακαδημίας 10', 'pickup_city' => 'ΑΘΗΝΑ', 'pickup_postcode' => '10679', 'pickup_date' => $dates[1],
        'pickup_time_from' => '10:00', 'pickup_time_to' => '14:00', 'courier_notes' => 'smoke: 2ος όροφος');
    $short = $cli->post($s->shopUrl('extension/shipping/courier_center/returns', array('order_id' => $order)), array_merge($pickupFields,
        array('request_uid' => 'pkbad' . substr(md5((string)microtime(true)), 0, 10), 'method' => 'COURIER_CENTER_PICKUP', 'name' => 'Ρένα', 'mobile' => '6912345678', 'pickup_time_to' => '12:00', 'qty[' . (isset($qm[1]) ? $qm[1] : 0) . ']' => '1')));
    $c->ok($short['status'] === 200 && strpos($short['body'], 'τουλάχιστον 4 ώρες') !== false, 'Pickup window under 4 hours is refused');
    $pick = $request('COURIER_CENTER_PICKUP', $pickupFields);
    $prow = $pick ? $s->row("SELECT * FROM `{p}cc_return_requests` WHERE `id` = '$pick'") : array();
    $c->ok($prow && $prow['pickup_date'] === $dates[1] && $prow['pickup_time_from'] === '10:00' && strpos($prow['shipper_details'], '10679') !== false, 'Pickup request stored with date / window / address');
    $c->ok(cc_mail_with(array('Νέο αίτημα επιστροφής', '#' . $order, 'Παραλαβή:')), 'shop got the «Νέο αίτημα επιστροφής» email (with the pickup)');
    $info3 = $cli->get($s->shopUrl('account/order/info', array('order_id' => $order)))['body'];
    $c->ok(strpos($info3, 'Παραλαβή: ') !== false, 'customer sees the pickup date of the pending request');
    // Shop notifications while requests wait
    $dash = $s->adminGet('common/dashboard')['body'];
    $c->ok(strpos($dash, 'id="cc-returns-notice"') !== false && strpos($dash, 'αιτήματα επιστροφής') !== false, 'dashboard notice about the waiting requests');
    $c->ok(preg_match('/Courier Center \((\d+)\)/u', $dash, $mm) === 1 && (int)$mm[1] >= 3, 'menu counter (' . (isset($mm[1]) ? $mm[1] : '-') . ')');
    $olist = $s->adminGet('sale/order')['body'];
    $c->ok(preg_match('/var RETURNS = (\{.*?\});/', $olist, $rm) === 1 && strpos($rm[1], '"' . $order . '"') !== false, 'order list column «Επιστροφή»');

    // Someone else cannot open the form of this order.
    $other = new SmokeHttp(cc_state_dir() . "/{$s->id}-other.cookies");
    $foreign = $other->get($s->shopUrl('extension/shipping/courier_center/returns', array('order_id' => $order)));
    $c->ok($foreign['status'] === 302 && strpos($foreign['location'], 'account/login') !== false, 'guest is sent to the login page');

    // Admin: list page + approve → return shipment on the API
    $list = $s->adminGet('extension/shipping/courier_center/returns')['body'];
    $c->ok(strpos($list, 'Επιστροφές Courier Center') !== false && strpos($list, 'data-id="' . $drop . '"') !== false, 'admin returns page lists the requests');
    $created = array();
    foreach (array('Drop Off' => $drop, 'BOX NOW' => $box, 'Pickup' => $pick) as $label => $id) {
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
        $c->ok(strpos($panel, '↩️ Επιστροφές') !== false && strpos($panel, 'id="cc-add-return"') !== false && strpos($panel, 'Από: Πελάτης') !== false, 'order tab lists the returns + «Νέα επιστροφή»');
        if (isset($created['Pickup'])) {
            $c->ok(strpos($panel, 'Παραλαβή: <strong>') !== false, 'order tab shows the pickup date');
            $pd = $api->get_shipment_details($created['Pickup']['return_awb']);
            $pi = isset($pd['ShipmentDetails'][0]['ShipmentInfo']) ? $pd['ShipmentDetails'][0]['ShipmentInfo'] : array();
            $c->ok(isset($pi['Shipper']['ZipCode']) && trim((string)$pi['Shipper']['ZipCode']) === '10679', 'Pickup shipper = customer address on the API');
        }
        // «Νέα επιστροφή» of the shop (Drop Off, no customer email)
        $page = $s->adminGet('extension/shipping/courier_center/returns/add', array('order_id' => $order))['body'];
        if (preg_match('/name="request_uid" value="([^"]+)"/', $page, $su) && preg_match_all('/name="qty\[(\d+)\]"/', $page, $sq)) {
            cc_mailpit_clear();
            $post = array('request_uid' => $su[1], 'method' => 'COURIER_CENTER_DROPOFF', 'name' => 'Ρένα Επιστροφή', 'mobile' => '6912345678', 'reason' => 'smoke shop');
            $post['qty[' . end($sq[1]) . ']'] = '1';
            $res = $s->adminPost('extension/shipping/courier_center/returns/add', $post, array('order_id' => $order));
            $srow = $s->row("SELECT * FROM `{p}cc_return_requests` WHERE `request_uid` = '" . $s->db()->real_escape_string($su[1]) . "'");
            $c->ok($res['status'] === 302 && $srow && $srow['origin'] === 'shop' && $srow['status'] === 'shipment_created' && (int)$srow['notify_customer'] === 0,
                'shop return created at once: ' . ($srow ? $srow['return_awb'] . ' ' . $srow['last_error'] : 'no row'));
            if ($srow && $srow['return_awb'] !== '') {
                $created['Κατάστημα'] = $srow;
            }
            $after = $s->adminGet('sale/order/info', array('order_id' => $order))['body'];
            $c->ok(strpos($after, 'id="cc-return-notice"') !== false && strpos($after, 'Από: Κατάστημα') !== false, 'order tab: success notice + «Από: Κατάστημα»');
            $c->ok(!cc_mail_with(array('δημιουργήθηκε η αποστολή', '#' . $order)), 'no customer email without «Αποστολή email στον πελάτη»');
        } else {
            $c->fail('«Νέα επιστροφή» page did not render');
        }
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
