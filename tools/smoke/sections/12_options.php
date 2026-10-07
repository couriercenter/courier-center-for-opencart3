<?php
/**
 * Voucher options accepted by the REAL API: same-day services, return voucher, several parcels, the amount of
 * a cash-on-delivery voucher (cost + COD fee, spec 5.7) and the BOX NOW + return refusal. Every voucher is voided
 * by "cleanup". Needs "install" first (shop settings: cost 3.50, COD fee 1.50).
 */
return function (SmokeContext $c) {
    $s = $c->shop;
    $s->login();
    require_once cc_repo_root() . '/upload/system/library/couriercenter/CCApi.php';
    $cr = cc_oc4_credentials();
    $api = new \Opencart\Extension\Couriercenter\Library\CCApi($cr['user_alias'], $cr['credential_value'], $cr['api_key'], $cr['billing_account']);
    $row = function ($id) use ($s) {
        return $s->row("SELECT * FROM `{p}cc_shipments` WHERE `order_id` = '" . (int)$id . "'");
    };
    $info = function ($awb) use ($api) {
        $d = $api->get_shipment_details($awb);
        return isset($d['ShipmentDetails'][0]['ShipmentInfo']) ? $d['ShipmentDetails'][0]['ShipmentInfo'] : array();
    };

    // 1. Same-day services (3h and 5h) with a return voucher and 2 parcels, bank transfer
    foreach (array('same_day_3h', 'same_day_5h') as $service) {
        $o = $s->createApiOrder('courier_center.courier_center', 'bank_transfer', array(43 => 1));
        $r = $s->adminJson('extension/shipping/courier_center/createVoucher', array('order_id' => $o, 'service_type' => $service, 'parcel_count' => 2, 'return_option' => 'optional'));
        $c->ok(!empty($r['success']), "$service + 2 parcels + optional return: " . (isset($r['error']) ? $r['error'] : $r['voucher']));
        $c->track(isset($r['voucher']) ? $r['voucher'] : '');
        $sh = $row($o);
        $c->ok(isset($sh['service_type']) && $sh['service_type'] === $service && $sh['return_option'] === 'optional', "$service stored with the return option");
        if (!empty($sh['voucher_number'])) {
            $i = $info($sh['voucher_number']);
            $c->ok(isset($i['NoOfItems']) && (int)$i['NoOfItems'] === 2, "$service: the carrier has 2 parcels (NoOfItems=" . (isset($i['NoOfItems']) ? $i['NoOfItems'] : '?') . ')');
        }
        if (!empty($sh['return_awb'])) {
            $c->track($sh['return_awb']);
            $pdf = $s->adminGet('extension/shipping/courier_center/downloadVoucher', array('order_id' => $o, 'type' => 'return'));
            $c->ok(substr($pdf['body'], 0, 4) === '%PDF', "$service: return voucher PDF");
        } else {
            echo "  (no separate return AWB stored for $service)\n";
        }
    }

    // 2. Mandatory return
    $o = $s->createApiOrder('courier_center.courier_center', 'bank_transfer', array(40 => 1));
    $r = $s->adminJson('extension/shipping/courier_center/createVoucher', array('order_id' => $o, 'service_type' => 'next_day', 'return_option' => 'mandatory'));
    $c->ok(!empty($r['success']), 'mandatory return: ' . (isset($r['error']) ? $r['error'] : $r['voucher']));
    $c->track(isset($r['voucher']) ? $r['voucher'] : '');

    // 3. COD voucher: the carrier collects the order total (cost + COD fee are part of it)
    $o = $s->createApiOrder('courier_center.courier_center_cod', 'cod', array(43 => 1));
    $total = (float)$s->row("SELECT `total` FROM `{p}order` WHERE `order_id` = '$o'")['total'];
    $r = $s->adminJson('extension/shipping/courier_center/createVoucher', array('order_id' => $o, 'service_type' => 'next_day'));
    $c->ok(!empty($r['success']), 'COD voucher: ' . (isset($r['error']) ? $r['error'] : $r['voucher']));
    $c->track(isset($r['voucher']) ? $r['voucher'] : '');
    if (!empty($r['voucher'])) {
        $i = $info($r['voucher']);
        $cod = isset($i['CODAmount']['Value']) ? (float)$i['CODAmount']['Value'] : -1.0;
        $c->ok(abs($cod - $total) < 0.01, "COD amount at the carrier = order total ($cod vs $total)");
    }

    // 4. BOX NOW is never combined with cash on delivery (refused before the API). A fresh order: the one above
    //    already has an active voucher, which would be refused first.
    $fresh = $s->createApiOrder('courier_center.courier_center_cod', 'cod', array(40 => 1));
    $r = $s->adminJson('extension/shipping/courier_center/createVoucher', array('order_id' => $fresh, 'boxnow' => 1));
    $c->ok(empty($r['success']) && strpos($r['error'], 'BOX NOW') !== false, 'BOX NOW + COD refused');
};
