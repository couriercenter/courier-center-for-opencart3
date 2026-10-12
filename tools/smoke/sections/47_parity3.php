<?php
/**
 * Round 3 (full WooCommerce comparison), real API: separate street-number field (custom field → voucher address),
 * handled methods need at least one, BOX NOW-only print button, order-list badges, Courier Center admin submenu,
 * BOX NOW removal note, "cron without a cron job" (pseudo-cron). Every voucher is voided.
 */
return function (SmokeContext $c) {
    $s = $c->shop;
    $s->login();
    $set = function ($key, $value) use ($s) {
        $s->exec("DELETE FROM `{p}setting` WHERE `key` = 'shipping_courier_center_$key'");
        if ($value !== null) {
            $s->exec("INSERT INTO `{p}setting` SET `store_id` = 0, `code` = 'shipping_courier_center', `key` = 'shipping_courier_center_$key', `value` = '" . $s->db()->real_escape_string($value) . "', `serialized` = 0");
        }
    };
    $get = function ($key) use ($s) {
        $r = $s->row("SELECT `value` FROM `{p}setting` WHERE `key` = 'shipping_courier_center_$key'");
        return $r ? $r['value'] : null;
    };

    // 1. New fields on the settings page
    $page = $s->adminGet('extension/shipping/courier_center')['body'];
    foreach (array('street_number_field', 'auto_complete_notify', 'pseudo_cron', 'returns_email_created', 'returns_email_received', 'returns_email_rejected') as $name) {
        $c->ok(strpos($page, 'name="shipping_courier_center_' . $name . '"') !== false, "settings page has $name");
    }

    // 2. «Methods the plugin manages»: at least one. An empty submission keeps the saved list and says so.
    $fields = array();
    foreach ($s->rows("SELECT `key`, `value`, `serialized` FROM `{p}setting` WHERE `code` = 'shipping_courier_center'") as $row) {
        $fields[$row['key']] = $row['serialized'] ? json_decode($row['value'], true) : html_entity_decode($row['value'], ENT_QUOTES, 'UTF-8');
    }
    $cr = cc_oc4_credentials();
    $fields['shipping_courier_center_credential_value'] = '';
    $fields['shipping_courier_center_api_key'] = '';
    unset($fields['shipping_courier_center_handled_shipping_methods']);
    $before = $get('handled_shipping_methods');
    $res = $s->adminPost('extension/shipping/courier_center', $fields);
    $after = $s->adminGet('extension/shipping/courier_center')['body'];
    $c->ok($res['status'] === 302 && strpos($after, 'Επιλέξτε τουλάχιστον μία') !== false, 'empty handled-methods list → warning');
    $c->ok($get('handled_shipping_methods') === $before, 'the saved handled-methods list is kept');

    // 3. Street number from a separate address custom field → into the voucher address
    $s->exec("INSERT INTO `{p}custom_field` SET `type` = 'text', `value` = '', `validation` = '', `location` = 'address', `status` = 1, `sort_order` = 0");
    $cfid = (int)$s->db()->insert_id;
    $s->exec("INSERT INTO `{p}custom_field_description` SET `custom_field_id` = $cfid, `language_id` = 1, `name` = 'Αριθμός smoke'");
    $page = $s->adminGet('extension/shipping/courier_center')['body'];
    $c->ok(strpos($page, 'value="' . $cfid . '"') !== false && strpos($page, 'Αριθμός smoke') !== false, 'the address custom field is offered as the number field');
    $oldNumber = $get('street_number_field');
    $set('street_number_field', (string)$cfid);
    $order = $s->createApiOrder('courier_center.courier_center', 'bank_transfer', array(40 => 1));
    $s->exec("UPDATE `{p}order` SET `shipping_custom_field` = '" . $s->db()->real_escape_string(json_encode(array((string)$cfid => '77'))) . "' WHERE `order_id` = '$order'");
    $panel = $s->adminGet('sale/order/info', array('order_id' => $order))['body'];
    $c->ok(strpos($panel, 'Λεωφόρος Αλεξάνδρας 10 77,') !== false, 'the panel previews the address WITH the number');
    $r = $s->adminJson('extension/shipping/courier_center/createVoucher', array('order_id' => $order, 'service_type' => 'next_day'));
    $c->ok(!empty($r['success']), 'voucher with the number: ' . (isset($r['error']) ? $r['error'] : $r['voucher']));
    if (!empty($r['voucher'])) {
        $c->track($r['voucher']);
        require_once cc_repo_root() . '/upload/system/library/couriercenter/CCApi.php';
        $api = new \Opencart\Extension\Couriercenter\Library\CCApi($cr['user_alias'], $cr['credential_value'], $cr['api_key'], $cr['billing_account']);
        $d = $api->get_shipment_details($r['voucher']);
        $addr = isset($d['ShipmentDetails'][0]['ShipmentInfo']['Consignee']['Address']) ? $d['ShipmentDetails'][0]['ShipmentInfo']['Consignee']['Address'] : '';
        $c->ok(strpos($addr, '77') !== false, 'the API consignee address carries the number: ' . $addr);
        $v = $s->adminJson('extension/shipping/courier_center/voidShipment', array('order_id' => $order));
        $c->ok(!empty($v['success']), 'void');
    }
    $set('street_number_field', $oldNumber);
    $s->exec("DELETE FROM `{p}custom_field_description` WHERE `custom_field_id` = $cfid");
    $s->exec("DELETE FROM `{p}custom_field` WHERE `custom_field_id` = $cfid");

    // 4. BOX NOW removal leaves a note on the order (like WooCommerce)
    $bn = $s->createApiOrder('courier_center.courier_center', 'bank_transfer', array(40 => 1));
    $s->exec("UPDATE `{p}order` SET `custom_field` = '" . $s->db()->real_escape_string(json_encode(array('cc_delivery_mode' => 'auto'))) . "' WHERE `order_id` = '$bn'");
    $rm = $s->adminJson('extension/shipping/courier_center/removeBoxNow', array('order_id' => $bn));
    $note = $s->row("SELECT `comment` FROM `{p}order_history` WHERE `order_id` = '$bn' AND `comment` LIKE '%BOX NOW αφαιρέθηκε%' LIMIT 1");
    $c->ok(!empty($rm['success']) && $note, 'BOX NOW removal: success + order note');

    // 5. Order list: BOX NOW print button, RETURNS column data, status badges; Courier Center submenu
    $list = $s->adminGet('sale/order')['body'];
    $c->ok(strpos($list, 'Εκτύπωση BOX NOW') !== false && strpos($list, 'var RETURNS') !== false, 'order list: «Εκτύπωση BOX NOW» button + returns column');
    $c->ok(strpos($list, "badge('Νέα'") !== false && strpos($list, "'25', '99', '14', '95'") !== false, 'order list: «Νέα» badge and code 95 as cancelled');
    $dash = $s->adminGet('common/dashboard')['body'];
    foreach (array('Manifest Παραλαβής', 'Ρυθμίσεις', 'Οδηγίες', 'Αναφορά Προβλήματος') as $item) {
        $c->ok(strpos($dash, $item) !== false, "Courier Center submenu: $item");
    }

    // 6. Cron without a cron job: due → one request is fired; off or recent → nothing
    $dev = $get('dev_overrides');
    $s->exec("UPDATE `{p}setting` SET `value` = '" . $s->db()->real_escape_string(json_encode(array('dashboard_base' => 'http://127.0.0.1:8799', 'release_api' => 'http://127.0.0.1:8799/release.json'))) . "' WHERE `key` = 'shipping_courier_center_dev_overrides'");
    $old = time() - 20000;
    $state = function ($key, $value) use ($s) {
        $s->exec("DELETE FROM `{p}setting` WHERE `key` = 'shipping_courier_center_$key'");
        $s->exec("INSERT INTO `{p}setting` SET `store_id` = 0, `code` = 'courier_center_state', `key` = 'shipping_courier_center_$key', `value` = '" . $s->db()->real_escape_string((string)$value) . "', `serialized` = 0");
    };
    $oldPseudo = $get('pseudo_cron');
    $set('pseudo_cron', '0');
    $state('cron_last_run_ts', $old);
    $state('cron_fired_at', 0);
    $s->shopGet('common/home');
    $c->ok((int)$get('cron_fired_at') === 0, 'pseudo-cron off: nothing fired');
    $set('pseudo_cron', '1');
    $state('cron_last_run_ts', time() - 600);
    $s->shopGet('common/home');
    $c->ok((int)$get('cron_fired_at') === 0, 'ran 10 minutes ago: nothing fired');
    $state('cron_last_run_ts', $old);
    $s->shopGet('common/home');
    $fired = (int)$get('cron_fired_at');
    $c->ok($fired > 0, 'due: the visit fired the cron request');
    $ran = false;
    for ($i = 0; $i < 160 && !$ran; $i++) {
        usleep(500000);
        $ran = (int)$get('cron_last_run_ts') > $old + 100;
    }
    $c->ok($ran, 'the fired request ran the tracker (cron_last_run_ts updated)');
    $s->shopGet('common/home');
    $c->ok((int)$get('cron_fired_at') === $fired, 'no second trigger right after');
    $set('pseudo_cron', $oldPseudo);
    $s->exec("UPDATE `{p}setting` SET `value` = '" . $s->db()->real_escape_string((string)$dev) . "' WHERE `key` = 'shipping_courier_center_dev_overrides'");
};
