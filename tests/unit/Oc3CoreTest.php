<?php
require_once CC_REPO . '/tests/support/FakeDb.php';
require_once CC_OC3 . '/CCVersion.php';
require_once CC_OC3 . '/CCState.php';
require_once CC_OC3 . '/CCErrorGuard.php';
require_once CC_OC3 . '/CCConfigSnapshot.php';

use Opencart\Extension\Couriercenter\Oc3\CCConfigSnapshot;
use Opencart\Extension\Couriercenter\Oc3\CCErrorGuard;
use Opencart\Extension\Couriercenter\Oc3\CCState;
use Opencart\Extension\Couriercenter\Oc3\CCVersion;

function test_oc3_version_comes_from_version_json() {
    $json = json_decode(file_get_contents(CC_REPO . '/upload/system/library/couriercenter/version.json'), true);
    assert_same($json['version'], CCVersion::get());
    assert_true((bool)preg_match('/^\d+\.\d+\.\d+$/', CCVersion::get()));
}
function test_oc3_php_gate() {
    assert_false(CCVersion::phpSupported('7.1.33'));
    assert_true(CCVersion::phpSupported('7.2.0'));
    assert_true(CCVersion::phpSupported('8.5.1'));
    assert_true(CCVersion::phpSupported());
}
function test_oc3_state_set_replaces_the_row_under_its_own_code() {
    $db = new FakeDb();
    (new CCState($db, 'oc_'))->set('cron_last_run', '2026-10-02 10:00:00');
    assert_contains("DELETE FROM `oc_setting` WHERE `store_id` = '0' AND `key` = 'shipping_courier_center_cron_last_run'", $db->queries[0]);
    assert_contains("`code` = 'courier_center_state', `key` = 'shipping_courier_center_cron_last_run', `value` = '2026-10-02 10:00:00'", $db->queries[1]);
}
/** Review Focus 4: OpenCart's editSetting('shipping_courier_center') deletes by code; our rows use another code. */
function test_oc3_state_survives_edit_setting_of_user_code() {
    $db = new FakeDb();
    (new CCState($db, 'oc_'))->set('cron_key', 'abc');
    $insert = $db->matching('/INSERT INTO `oc_setting`/');
    assert_contains("`code` = 'courier_center_state'", $insert[0]);
    assert_not_contains("`code` = 'shipping_courier_center'", $insert[0]);
}
function test_oc3_state_get_returns_value_or_default() {
    $db = new FakeDb();
    $db->on("/`key` = 'shipping_courier_center_cron_key'/", array(array('value' => 'k1')));
    $s = new CCState($db, 'oc_');
    assert_same('k1', $s->get('cron_key'));
    assert_same('x', $s->get('missing', 'x'));
}
function test_oc3_state_creates_a_cron_key_once() {
    $db = new FakeDb();
    $s = new CCState($db, 'oc_');
    $key = $s->ensureCronKey();
    assert_true((bool)preg_match('/^[a-f0-9]{32}$/', $key));
    assert_count(1, $db->matching('/INSERT INTO `oc_setting`/'));
    $db2 = new FakeDb();
    $db2->on("/shipping_courier_center_cron_key/", array(array('value' => str_repeat('a', 32))));
    assert_same(str_repeat('a', 32), (new CCState($db2, 'oc_'))->ensureCronKey());
    assert_count(0, $db2->matching('/INSERT/'));
}
function test_oc3_state_dev_overrides_and_upsert_setting() {
    $db = new FakeDb();
    $db->on('/shipping_courier_center_dev_overrides/', array(array('value' => '{"dashboard_base":"http://127.0.0.1:8799"}')));
    assert_same(array('dashboard_base' => 'http://127.0.0.1:8799'), (new CCState($db, 'oc_'))->devOverrides());
    $db2 = new FakeDb();
    CCState::upsertSetting($db2, 'oc_', 'shipping_courier_center', 'shipping_courier_center_shipper_name', 'Ψ & Σ');
    assert_contains("`code` = 'shipping_courier_center', `key` = 'shipping_courier_center_shipper_name', `value` = 'Ψ & Σ', `serialized` = '0'", $db2->queries[1]);
}
/** Review Focus 3: with "Display errors" on, notices must not reach JSON/PDF output. */
function test_oc3_error_guard_keeps_notices_out_of_output() {
    $logged = array();
    ob_start();
    CCErrorGuard::start(function ($line) use (&$logged) {
        $logged[] = $line;
    });
    trigger_error('core deprecation', E_USER_DEPRECATED);
    trigger_error('other extension notice', E_USER_NOTICE);
    @trigger_error('silenced', E_USER_NOTICE);
    CCErrorGuard::stop();
    $output = ob_get_clean();
    assert_same('', $output);
    assert_count(2, $logged);
    assert_contains('core deprecation', $logged[0]);
    assert_throws('ErrorException', function () {
        trigger_error('after stop the previous handler is back', E_USER_NOTICE);
    });
}
function test_oc3_error_guard_clean_buffers() {
    ob_start();
    echo 'stray notice';
    ob_start();
    echo 'more';
    CCErrorGuard::cleanBuffers();
    assert_same(0, ob_get_level());
}
function test_oc3_config_snapshot_decodes_serialized_values() {
    $db = new FakeDb();
    $db->on('/FROM `oc_setting` WHERE `store_id` = \'0\'/', array(
        array('key' => 'shipping_courier_center_api_key', 'value' => 'k', 'serialized' => '0'),
        array('key' => 'shipping_courier_center_handled_shipping_methods', 'value' => '["courier_center"]', 'serialized' => '1'),
    ));
    $c = new CCConfigSnapshot($db, 'oc_');
    assert_same('k', $c->get('shipping_courier_center_api_key'));
    assert_same(array('courier_center'), $c->get('shipping_courier_center_handled_shipping_methods'));
    assert_same(null, $c->get('nope'));
}
function test_oc3_bootstrap_loads_every_library_class() {
    $boot = file_get_contents(CC_OC3 . '/bootstrap.php');
    foreach (glob(CC_LIB . '/CC*.php') as $f) {
        assert_contains("'" . basename($f) . "'", $boot, basename($f) . ' is required by oc3/bootstrap.php');
    }
}
