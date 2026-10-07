<?php
/** Order actions with the real API: single / void / new voucher / bulk / PDF / status (spec 7.5). */
return function (SmokeContext $c) {
    $s = $c->shop;
    $s->login();
    $cod  = $s->createApiOrder('courier_center.courier_center', 'cod', array(40 => 2));
    $bank = $s->createApiOrder('courier_center.courier_center', 'bank_transfer', array(43 => 1));
    $flat = $s->createApiOrder('flat.flat', 'cod');
    $cod2 = $s->createApiOrder('courier_center.courier_center', 'cod');
    $c->data['orders'] = array('cod' => $cod, 'bank' => $bank, 'flat' => $flat, 'cod2' => $cod2);
    $row = function ($id) use ($s) {
        return $s->row("SELECT * FROM `{p}cc_shipments` WHERE `order_id` = '" . (int)$id . "'");
    };

    // Single voucher (COD) -> history note -> PDF
    $r = $s->adminJson('extension/shipping/courier_center/createVoucher', array('order_id' => $cod, 'service_type' => 'next_day', 'parcel_count' => 1, 'return_option' => 'none', 'boxnow' => 0));
    $c->ok(!empty($r['success']), 'createVoucher COD: ' . (isset($r['error']) ? $r['error'] : $r['voucher']));
    $c->track(isset($r['voucher']) ? $r['voucher'] : '');
    $sh = $row($cod);
    $c->ok(isset($sh['is_voided']) && $sh['is_voided'] === '0' && $sh['created_at'] !== null, 'shipment row active with created_at');
    $note = $s->row("SELECT `comment` FROM `{p}order_history` WHERE `order_id` = '$cod' ORDER BY `order_history_id` DESC LIMIT 1");
    $c->ok(isset($note['comment']) && strpos($note['comment'], 'Courier Center voucher') !== false, 'history note written');
    $dup = $s->adminJson('extension/shipping/courier_center/createVoucher', array('order_id' => $cod));
    $c->ok(empty($dup['success']) && strpos($dup['error'], 'ενεργό voucher') !== false, 'no second voucher while one is active');
    $pdf = $s->adminGet('extension/shipping/courier_center/downloadVoucher', array('order_id' => $cod));
    $c->ok(substr($pdf['body'], 0, 4) === '%PDF', 'voucher PDF');

    // Status: first call talks to the API, the second is rate-limited (60 min)
    $st1 = $s->adminJson('extension/shipping/courier_center/updateStatus', array('order_id' => $cod));
    $c->ok(!empty($st1['success']) || strpos($st1['error'], 'status') !== false, 'status refresh answered: ' . json_encode($st1, JSON_UNESCAPED_UNICODE));
    $st2 = $s->adminJson('extension/shipping/courier_center/updateStatus', array('order_id' => $cod));
    $c->ok(empty($st2['success']) && strpos($st2['error'], 'πρόσφατα') !== false, 'status refresh is rate-limited');

    // Void -> new voucher on the same order is active again (spec 10 #3)
    $v = $s->adminJson('extension/shipping/courier_center/voidShipment', array('order_id' => $cod));
    $c->ok(!empty($v['success']), 'void: ' . (isset($v['error']) ? $v['error'] : 'ok'));
    $r2 = $s->adminJson('extension/shipping/courier_center/createVoucher', array('order_id' => $cod, 'service_type' => 'next_day'));
    $c->ok(!empty($r2['success']), 'new voucher after void');
    $c->track(isset($r2['voucher']) ? $r2['voucher'] : '');
    $sh2 = $row($cod);
    $c->ok($sh2['is_voided'] === '0' && $sh2['voucher_number'] === (isset($r2['voucher']) ? $r2['voucher'] : '-') && $sh2['status_code'] === '', 'new voucher row is active');

    // Validations without API calls
    $bnc = $s->adminJson('extension/shipping/courier_center/createVoucher', array('order_id' => $cod2, 'boxnow' => 1));
    $c->ok(empty($bnc['success']) && strpos($bnc['error'], 'BOX NOW') !== false, 'BOX NOW + COD refused');
    $fl = $s->adminJson('extension/shipping/courier_center/createVoucher', array('order_id' => $flat));
    $c->ok(!empty($fl['needs_override']), 'unmanaged shipping method asks for confirmation');

    // Bulk: bank created, flat skipped as unmanaged
    $b = $s->adminJson('extension/shipping/courier_center/bulkCreate', array('order_ids' => "$bank,$flat"));
    $msg = isset($b['message']) ? str_replace("\n", ' ', $b['message']) : json_encode($b, JSON_UNESCAPED_UNICODE);
    $c->ok(!empty($b['success']) && strpos($msg, 'Δημιουργήθηκαν: 1') !== false && strpos($msg, 'Μη διαχειρίσιμη μέθοδος: 1') !== false, 'bulk create: ' . $msg);
    $bank_row = $row($bank);
    $c->track(isset($bank_row['voucher_number']) ? $bank_row['voucher_number'] : '');
    $c->ok(isset($bank_row['service_type']) && $bank_row['service_type'] === 'next_day', 'bulk voucher stored');
    $bp = $s->adminGet('extension/shipping/courier_center/bulkPrint', array('order_ids' => "$cod,$bank"));
    $c->ok(substr($bp['body'], 0, 4) === '%PDF', 'bulk print PDF');
    $bs = $s->adminJson('extension/shipping/courier_center/bulkStatus', array('order_ids' => "$cod,$bank"));
    $c->ok(!empty($bs['success']), 'bulk status: ' . (isset($bs['message']) ? str_replace("\n", ' ', $bs['message']) : ''));
};
