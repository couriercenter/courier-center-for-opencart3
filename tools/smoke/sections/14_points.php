<?php
/**
 * 1.1.0 pickup points (sub-codes) with the real API, on the test account: main 100-0120-0004 with the sub-codes
 * 100-0120-0001 and 100-0120-0003. Every test shipment is voided (autofill voids its own; cleanup the vouchers).
 */
return function (SmokeContext $c) {
    $s = $c->shop;
    $s->login();
    $main = cc_oc4_credentials()['billing_account'];
    if ($main !== '100-0120-0004') {
        echo "  (skipped: the sub-codes below belong to the test account 100-0120-0004)\n";
        return;
    }

    // Per-row Test & Auto-fill returns the sub-code's sender data and writes nothing.
    $before = $s->row("SELECT COUNT(*) AS n FROM `{p}setting` WHERE `key` LIKE 'shipping_courier_center_%'");
    $af = $s->adminJson('extension/shipping/courier_center/autofillPoint', array('code' => '100-0120-0001'));
    $c->ok(!empty($af['success']) && empty($af['warning']) && $af['data']['name'] !== '', 'autofill sub-code 0001: ' . (isset($af['error']) ? $af['error'] : $af['data']['name']));
    $after = $s->row("SELECT COUNT(*) AS n FROM `{p}setting` WHERE `key` LIKE 'shipping_courier_center_%'");
    $c->ok($before['n'] === $after['n'], 'point autofill writes no setting');
    $bad = $s->adminJson('extension/shipping/courier_center/autofillPoint', array('code' => '999-9999-9999'));
    $c->ok(empty($bad['success']) && $bad['error'] !== '', 'unknown sub-code → API error: ' . (isset($bad['error']) ? $bad['error'] : ''));

    // Save two points (+ a duplicate and the main code, which are dropped with warnings) and a default point.
    $settings = array();
    foreach ($s->rows("SELECT `key`, `value`, `serialized` FROM `{p}setting` WHERE `code` = 'shipping_courier_center'") as $r) {
        $settings[$r['key']] = $r['serialized'] ? json_decode($r['value'], true) : html_entity_decode($r['value'], ENT_QUOTES, 'UTF-8');
    }
    $d = isset($af['data']) ? $af['data'] : array('name' => '', 'address' => '', 'postcode' => '', 'city' => '', 'phone' => '');
    $settings['shipping_courier_center_pickup_points'] = array(
        array('code' => '100-0120-0001', 'label' => 'Κηφισός', 'bill_to' => 'main', 'name' => $d['name'], 'address' => $d['address'], 'postal' => $d['postcode'], 'city' => $d['city'], 'phone' => $d['phone']),
        array('code' => '100-0120-0001', 'label' => 'dup'),
        array('code' => $main, 'label' => 'main again'),
    );
    $settings['shipping_courier_center_default_pickup_point'] = '';
    $settings['shipping_courier_center_credential_value'] = '';
    $settings['shipping_courier_center_api_key'] = '';
    $save = $s->adminPost('extension/shipping/courier_center', $settings);
    $pp = $s->row("SELECT `value` FROM `{p}setting` WHERE `key` = 'shipping_courier_center_pickup_points'");
    $rows = isset($pp['value']) ? json_decode($pp['value'], true) : array();
    $c->ok(is_array($rows) && count($rows) === 1 && $rows[0]['code'] === '100-0120-0001' && $rows[0]['bill_to'] === 'main', 'points saved without the duplicate and the main code');
    $page = $s->adminGet('extension/shipping/courier_center')['body'];
    $c->ok(strpos($page, 'υπάρχει δύο φορές') !== false && strpos($page, 'ίδιος με τον κεντρικό') !== false, 'the ignored rows are reported on the settings page');

    // A voucher from the sub-code, billed to the main account: the API takes sender + pickup from the sub-code.
    $order = $s->createApiOrder('courier_center.courier_center', 'bank_transfer', array(43 => 1));
    $panel = $s->adminGet('sale/order/info', array('order_id' => $order))['body'];
    $c->ok(strpos($panel, 'id="cc-pickup-point"') !== false && strpos($panel, 'Κηφισός (100-0120-0001)') !== false, 'order tab shows the pickup point select');
    $r = $s->adminJson('extension/shipping/courier_center/createVoucher', array('order_id' => $order, 'service_type' => 'next_day', 'pickup_point' => '100-0120-0001'));
    $c->ok(!empty($r['success']), 'voucher from 0001: ' . (isset($r['error']) ? $r['error'] : $r['voucher']));
    $c->track(isset($r['voucher']) ? $r['voucher'] : '');
    $row = $s->row("SELECT * FROM `{p}cc_shipments` WHERE `order_id` = '$order'");
    $c->ok(isset($row['pickup_point']) && $row['pickup_point'] === '100-0120-0001' && $row['requestor_account'] === $main, 'point + billing code stored');
    if (!empty($r['voucher'])) {
        require_once cc_repo_root() . '/upload/system/library/couriercenter/CCApi.php';
        $cr = cc_oc4_credentials();
        $api = new \Opencart\Extension\Couriercenter\Library\CCApi($cr['user_alias'], $cr['credential_value'], $cr['api_key'], $cr['billing_account']);
        $det = $api->get_shipment_details($r['voucher']);
        $code = isset($det['ShipmentDetails'][0]['ShipmentInfo']['Shipper']['Code']) ? $det['ShipmentDetails'][0]['ShipmentInfo']['Shipper']['Code'] : '';
        $c->ok($code === '100-120-0001', 'API shipper of the voucher is the sub-code (' . $code . ')');
        $m = $s->adminGet('extension/shipping/courier_center/manifest/download', array('date' => date('Y-m-d'), 'account' => '100-0120-0001'));
        $c->ok(substr($m['body'], 0, 4) === '%PDF', 'manifest of the sub-code (CustomerAccount): ' . substr($m['body'], 0, 80));
    }
    $note = $s->row("SELECT `comment` FROM `{p}order_history` WHERE `order_id` = '$order' ORDER BY `order_history_id` DESC LIMIT 1");
    $c->ok(isset($note['comment']) && strpos($note['comment'], 'Σημείο: Κηφισός (100-0120-0001) — Χρέωση: ' . $main) !== false, 'history note names the point and the billing code');

    // Bulk create with the main point explicitly + manifest page with the point filter
    $order2 = $s->createApiOrder('courier_center.courier_center', 'bank_transfer', array(43 => 1));
    $b = $s->adminJson('extension/shipping/courier_center/bulkCreate', array('order_ids' => (string)$order2, 'pickup_point' => $main));
    $c->ok(!empty($b['success']) && strpos($b['message'], 'Σημείο: Έδρα') !== false, 'bulk from the main point: ' . (isset($b['message']) ? str_replace("\n", ' ', $b['message']) : ''));
    $row2 = $s->row("SELECT `voucher_number`, `pickup_point` FROM `{p}cc_shipments` WHERE `order_id` = '$order2'");
    $c->track(isset($row2['voucher_number']) ? $row2['voucher_number'] : '');
    $c->ok(isset($row2['pickup_point']) && $row2['pickup_point'] === $main, 'bulk voucher from the main code');
    $mp = $s->adminGet('extension/shipping/courier_center/manifest', array('account' => '100-0120-0001'))['body'];
    $c->ok(strpos($mp, 'name="account"') !== false && strpos($mp, '1 vouchers') !== false, 'manifest page: point filter + count of that point');
    $unknown = $s->adminJson('extension/shipping/courier_center/bulkCreate', array('order_ids' => (string)$order2, 'pickup_point' => 'GONE'));
    $c->ok(empty($unknown['success']) && strpos($unknown['error'], 'δεν υπάρχει πια') !== false, 'bulk with a removed point is refused');
};
