<?php
/** Install through the real Extension Installer + Extensions > Shipping > Install; settings; autofill (spec 7.5). */
return function (SmokeContext $c) {
    $s = $c->shop;
    $zip = cc_repo_root() . '/dist/courier_center_oc3.ocmod.zip';
    if (!is_file($zip)) {
        throw new RuntimeException('dist/courier_center_oc3.ocmod.zip is missing: run tools/build.php');
    }
    $s->login();
    if ($s->row("SELECT 1 AS x FROM `{p}extension` WHERE `type` = 'shipping' AND `code` = 'courier_center'")) {
        $s->adminGet('extension/extension/shipping/uninstall', array('extension' => 'courier_center'));
    }

    // 1. Extension Installer: upload, then follow the "next" steps like the browser does.
    $j = SmokeShop::json($s->adminPost('marketplace/installer/upload', array(), array(), array('file' => $zip)));
    for ($i = 0; $i < 10 && empty($j['error']) && !empty($j['next']); $i++) {
        $j = SmokeShop::json($s->admin->get(str_replace('&amp;', '&', $j['next'])));
    }
    $c->ok(empty($j['error']), 'installer: ' . (isset($j['error']) ? json_encode($j['error'], JSON_UNESCAPED_UNICODE) : 'ok'));

    // 2. Extensions > Shipping > Install
    @unlink(cc_state_dir() . '/sink.log');
    $s->adminGet('extension/extension/shipping/install', array('extension' => 'courier_center'));
    $c->ok((bool)$s->row("SELECT 1 AS x FROM `{p}extension` WHERE `type` = 'shipping' AND `code` = 'courier_center'"), 'extension installed');
    $c->ok(count($s->rows("SELECT `code` FROM `{p}event` WHERE `code` LIKE 'courier\\_center\\_%'")) === 14, '14 events registered (1.1.0)');
    $c->ok(!$s->rows("SELECT `code` FROM `{p}event` WHERE `code` = 'courier_center_email'"), 'the 1.0.0 separate tracking email event is gone');
    foreach (array('cc_shipments', 'cc_locks', 'cc_return_requests') as $t) {
        $c->ok((bool)$s->rows("SHOW TABLES LIKE '" . $s->env['prefix'] . $t . "'"), $t . ' table');
    }
    $key = $s->row("SELECT `value` FROM `{p}setting` WHERE `key` = 'shipping_courier_center_cron_key' AND `code` = 'courier_center_state'");
    $c->ok(isset($key['value']) && preg_match('/^[a-f0-9]{32}$/', $key['value']), 'cron key created');
    $c->data['cron_key'] = isset($key['value']) ? $key['value'] : '';
    $sink = is_file(cc_state_dir() . '/sink.log') ? file_get_contents(cc_state_dir() . '/sink.log') : '';
    $c->ok(strpos($sink, '/api/ping') !== false, 'install ping went to the local sink (not the real dashboard)');

    // 3. One shipping method in the list: helper controllers are not "phantom" methods (spec 4.7)
    $list = $s->adminGet('extension/extension/shipping')['body'];
    $phantoms = array();
    foreach (array('order', 'order_list', 'manifest', 'bug', 'docs', 'update') as $helper) {
        if (strpos($list, 'extension=' . $helper) !== false) {
            $phantoms[] = $helper;
        }
    }
    $c->ok(strpos($list, 'extension=courier_center') !== false && !$phantoms, 'shipping list: no phantom entries ' . implode(',', $phantoms));

    // 4. Settings page + Save with the OC4 test account (never printed)
    $page = $s->adminGet('extension/shipping/courier_center')['body'];
    $c->ok(strpos($page, 'id="form-courier-center"') !== false && strpos($page, 'cc-cron-url') !== false, 'settings page renders');
    $fields = array(
        'shipping_courier_center_status' => '1', 'shipping_courier_center_cost' => '3.50', 'shipping_courier_center_cod_fee' => '1.50',
        'shipping_courier_center_tax_class_id' => '0', 'shipping_courier_center_geo_zone_id' => '0', 'shipping_courier_center_sort_order' => '1',
        'shipping_courier_center_tracking_url' => 'https://www.courier.gr/track/result?tracknr={{tracking}}',
        'shipping_courier_center_email_tracking_enabled' => '1',
        'shipping_courier_center_boxnow_enabled' => '1', 'shipping_courier_center_boxnow_default_selected' => '0',
        'shipping_courier_center_boxnow_shipping_methods' => array('courier_center'),
        'shipping_courier_center_handled_shipping_methods' => array('courier_center'),
        'shipping_courier_center_print_template' => 'pdf', 'shipping_courier_center_print_template_boxnow' => 'singlepdf_100x150_4up',
        'shipping_courier_center_auto_complete_status_id' => '5',
        'shipping_courier_center_auto_create_enabled' => '0', 'shipping_courier_center_auto_create_status_id' => '0',
    );
    foreach (cc_oc4_credentials() as $k => $v) {
        $fields['shipping_courier_center_' . $k] = $v;
    }
    $save = $s->adminPost('extension/shipping/courier_center', $fields);
    $c->ok($save['status'] === 302 && strpos($save['location'], 'marketplace/extension') !== false, 'settings saved');
    $key2 = $s->row("SELECT `value` FROM `{p}setting` WHERE `key` = 'shipping_courier_center_cron_key'");
    $c->ok(isset($key2['value']) && $key2['value'] === $c->data['cron_key'], 'Save keeps the cron key (Review Focus 4)');

    // 1.1.0: secrets never sent back; an empty field keeps them; a tracking URL without {{tracking}} is replaced
    $cr = cc_oc4_credentials();
    $page = $s->adminGet('extension/shipping/courier_center')['body'];
    // (the test account's User Alias equals its Credential Value, and the alias stays visible: check the fields themselves)
    $c->ok(strpos($page, $cr['api_key']) === false && preg_match('/name="shipping_courier_center_credential_value" value=""/', $page) === 1, 'settings page sends neither the API key nor the credential value');
    $c->ok(strpos($page, 'type="password" name="shipping_courier_center_credential_value"') !== false, 'credential value is a password field');
    $again = $fields;
    $again['shipping_courier_center_credential_value'] = '';
    $again['shipping_courier_center_api_key'] = '';
    $again['shipping_courier_center_tracking_url'] = 'https://example.com/track';
    $save2 = $s->adminPost('extension/shipping/courier_center', $again);
    $cv = $s->row("SELECT `value` FROM `{p}setting` WHERE `key` = 'shipping_courier_center_credential_value'");
    $tu = $s->row("SELECT `value` FROM `{p}setting` WHERE `key` = 'shipping_courier_center_tracking_url'");
    $c->ok(isset($cv['value']) && html_entity_decode($cv['value'], ENT_QUOTES, 'UTF-8') === $cr['credential_value'], 'empty secret field keeps the saved value');
    $c->ok(isset($tu['value']) && strpos($tu['value'], '{{tracking}}') !== false, 'tracking URL without {{tracking}} → default');
    $c->ok($save2['status'] === 302 && strpos($save2['location'], 'extension/shipping/courier_center') !== false, 'with a warning the save stays on the settings page');

    // 5. Test & Auto-fill: real API test shipment, voided by the endpoint; other settings untouched
    $af = $s->adminJson('extension/shipping/courier_center/autofill');
    $c->ok(!empty($af['success']) && empty($af['warning']), 'autofill: ' . (isset($af['error']) ? $af['error'] : 'sender data read, test shipment voided'));
    $cost = $s->row("SELECT `value` FROM `{p}setting` WHERE `key` = 'shipping_courier_center_cost'");
    $bn = $s->row("SELECT `value` FROM `{p}setting` WHERE `key` = 'shipping_courier_center_boxnow_enabled'");
    $c->ok(isset($cost['value'], $bn['value']) && $cost['value'] === '3.50' && $bn['value'] === '1', 'autofill keeps the other settings (spec 10 #4)');

    // 6. Repair
    $rep = $s->adminJson('extension/shipping/courier_center/repair');
    $c->ok(!empty($rep['success']), 'repair: ' . implode(' · ', isset($rep['done']) ? $rep['done'] : array()));
};
