<?php
require_once CC_REPO . '/tests/support/FakeDb.php';
require_once CC_REPO . '/tests/support/FakeConfig.php';
foreach (array('CCVersion', 'CCState', 'CCTelemetry', 'CCCronRunner') as $cc_class) {
    require_once CC_OC3 . "/$cc_class.php";
}

use Opencart\Extension\Couriercenter\Oc3\CCCronRunner;

/** Stands in for CCStatusTracker. */
class FakeTracker {
    public $runs = 0;
    public function run() {
        $this->runs++;
        return array('checked' => 2, 'updated' => 1, 'skipped' => 3, 'failed' => 1, 'lines' => array('UPDATE order #1: 10 — x'));
    }
}
function cc_cron_lock_dir() {
    $dir = str_replace('\\', '/', sys_get_temp_dir()) . '/cc_cron_' . getmypid();
    @mkdir($dir, 0777, true);
    @unlink($dir . '/cc_cron.lock');
    return $dir;
}

function test_oc3_cron_runs_records_the_time_and_releases_the_lock() {
    $db = new FakeDb();
    $dir = cc_cron_lock_dir();
    $tracker = new FakeTracker();
    $r = CCCronRunner::run($db, 'oc_', new FakeConfig(), $dir, 'http://shop/', $tracker, false);
    assert_true($r['ran']);
    assert_same('checked=2, updated=1, skipped=3, failed=1', $r['message']);
    assert_same(array('UPDATE order #1: 10 — x'), $r['lines']);
    assert_count(1, $db->matching("/INSERT INTO `oc_setting` .*`key` = 'shipping_courier_center_cron_last_run'/"));
    assert_count(1, $db->matching("/INSERT INTO `oc_setting` .*`key` = 'shipping_courier_center_cron_next_run'/"));
    assert_false(is_file($dir . '/cc_cron.lock'), 'lock released');
}
function test_oc3_cron_skips_while_another_run_holds_the_lock() {
    $dir = cc_cron_lock_dir();
    file_put_contents($dir . '/cc_cron.lock', (string)time());
    $tracker = new FakeTracker();
    $r = CCCronRunner::run(new FakeDb(), 'oc_', new FakeConfig(), $dir, '', $tracker, false);
    assert_false($r['ran']);
    assert_same(0, $tracker->runs);
    @unlink($dir . '/cc_cron.lock');
}
function test_oc3_cron_ignores_a_stale_lock() {
    $dir = cc_cron_lock_dir();
    file_put_contents($dir . '/cc_cron.lock', 'old');
    touch($dir . '/cc_cron.lock', time() - 3600);
    $r = CCCronRunner::run(new FakeDb(), 'oc_', new FakeConfig(), $dir, '', new FakeTracker(), false);
    assert_true($r['ran']);
}
function test_oc3_cron_key_check() {
    $key = str_repeat('a1', 16);
    assert_true(CCCronRunner::keyMatches($key, $key));
    assert_false(CCCronRunner::keyMatches($key, 'wrong'));
    assert_false(CCCronRunner::keyMatches('', ''));
    assert_false(CCCronRunner::keyMatches($key, array($key)));
}
