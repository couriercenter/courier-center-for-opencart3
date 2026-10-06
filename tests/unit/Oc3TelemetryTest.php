<?php
require_once CC_REPO . '/tests/support/FakeDb.php';
require_once CC_OC3 . '/CCVersion.php';
require_once CC_OC3 . '/CCState.php';
require_once CC_OC3 . '/CCTelemetry.php';

use Opencart\Extension\Couriercenter\Oc3\CCState;
use Opencart\Extension\Couriercenter\Oc3\CCTelemetry;
use Opencart\Extension\Couriercenter\Oc3\CCVersion;

function test_oc3_telemetry_goes_to_the_test_sink_when_configured() {
    $db = new FakeDb();
    $db->on('/shipping_courier_center_dev_overrides/', array(array('value' => '{"dashboard_base":"http://127.0.0.1:8799/"}')));
    assert_same('http://127.0.0.1:8799', CCTelemetry::base(new CCState($db, 'oc_')));
    assert_same(CCTelemetry::DASHBOARD, CCTelemetry::base(new CCState(new FakeDb(), 'oc_')));
}
function test_oc3_telemetry_payload() {
    $p = CCTelemetry::payload('https://shop.gr/');
    assert_same('https://shop.gr/', $p['site_url']);
    assert_same('OpenCart', $p['platform']);
    assert_same(CCVersion::get(), $p['plugin_version']);
    assert_same(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, $p['php_version']);
}
