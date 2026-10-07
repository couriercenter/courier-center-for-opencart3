<?php
require_once CC_OC3 . '/CCUpdater.php';
require_once CC_REPO . '/tools/lib/package.php';

use Opencart\Extension\Couriercenter\Oc3\CCUpdater;

function cc_updater_tmp($name) {
    $dir = str_replace('\\', '/', sys_get_temp_dir()) . '/cc_upd_' . $name . '_' . getmypid();
    CCUpdater::rrmdir($dir);
    mkdir($dir, 0777, true);
    return $dir;
}
function cc_updater_dirs($shop) {
    return array('application' => "$shop/admin", 'catalog' => "$shop/catalog", 'system' => "$shop/system", 'image' => "$shop/image");
}

function test_oc3_updater_allow_list_matches_the_build_tool() {
    assert_same(cc_allowed_prefixes(), CCUpdater::allowedPrefixes());
}
function test_oc3_updater_accepts_the_package_layout() {
    $files = CCUpdater::payloadFiles(array('upload/', 'upload/admin/', 'upload/admin/controller/extension/shipping/courier_center.php', 'upload/system/library/couriercenter/version.json'));
    assert_same(array('admin/controller/extension/shipping/courier_center.php', 'system/library/couriercenter/version.json'), $files);
}
function test_oc3_updater_rejects_unsafe_or_foreign_zips() {
    foreach (array(
        array('install.xml', 'upload/system/library/couriercenter/version.json'),
        array('upload/admin/index.php', 'upload/system/library/couriercenter/version.json'),
        array('upload/system/library/../../config.php', 'upload/system/library/couriercenter/version.json'),
        array('upload/admin/controller/extension/x.php'),
    ) as $entries) {
        assert_throws('RuntimeException', function () use ($entries) {
            CCUpdater::payloadFiles($entries);
        }, implode(', ', $entries));
    }
}
/** Review Focus 2: a renamed admin folder (e.g. "manage") must receive the admin files. */
function test_oc3_updater_target_follows_renamed_admin_folder() {
    $dirs = array('application' => 'C:/shop/manage/', 'catalog' => 'C:/shop/catalog/', 'system' => 'C:/shop/system/', 'image' => 'C:/shop/image/');
    assert_same('C:/shop/manage/controller/extension/shipping/courier_center.php', CCUpdater::target('admin/controller/extension/shipping/courier_center.php', $dirs));
    assert_same('C:/shop/system/library/couriercenter/CCApi.php', CCUpdater::target('system/library/couriercenter/CCApi.php', $dirs));
}
function test_oc3_updater_installs_and_keeps_a_backup() {
    $src = cc_updater_tmp('src');
    $shop = cc_updater_tmp('shop');
    $bak = cc_updater_tmp('bak');
    mkdir("$src/upload/system/library/couriercenter", 0777, true);
    file_put_contents("$src/upload/system/library/couriercenter/version.json", '{"version":"2.0.0"}');
    mkdir("$shop/system/library/couriercenter", 0777, true);
    file_put_contents("$shop/system/library/couriercenter/version.json", '{"version":"1.0.0"}');
    CCUpdater::install($src, array('system/library/couriercenter/version.json'), cc_updater_dirs($shop), $bak);
    assert_same('{"version":"2.0.0"}', file_get_contents("$shop/system/library/couriercenter/version.json"));
    assert_same('{"version":"1.0.0"}', file_get_contents("$bak/system/library/couriercenter/version.json"));
    assert_same('2.0.0', CCUpdater::packageVersion($src));
}
function test_oc3_updater_rolls_back_when_a_copy_fails() {
    $src = cc_updater_tmp('src2');
    $shop = cc_updater_tmp('shop2');
    $bak = cc_updater_tmp('bak2');
    mkdir("$src/upload/system/library/x", 0777, true);
    file_put_contents("$src/upload/system/library/x/a.php", 'NEW A');
    file_put_contents("$src/upload/system/library/x/b.php", 'NEW B');
    mkdir("$shop/system/library/x/b.php", 0777, true); // a folder where a file must go: the copy fails
    file_put_contents("$shop/system/library/x/a.php", 'OLD A');
    assert_throws('RuntimeException', function () use ($src, $shop, $bak) {
        CCUpdater::install($src, array('system/library/x/a.php', 'system/library/x/b.php'), cc_updater_dirs($shop), $bak);
    });
    assert_same('OLD A', file_get_contents("$shop/system/library/x/a.php"), 'a.php restored from the backup');
}
function test_oc3_updater_clears_compiled_templates_only() {
    $cache = cc_updater_tmp('cache');
    mkdir("$cache/template/ab", 0777, true);
    file_put_contents("$cache/template/ab/x.php", 'x');
    mkdir("$cache/3f", 0777, true);
    file_put_contents("$cache/3f/y.php", 'y');
    file_put_contents("$cache/cache.currency.123", 'keep');
    CCUpdater::clearTemplateCache($cache);
    assert_false(is_dir("$cache/template"));
    assert_false(is_dir("$cache/3f"));
    assert_true(is_file("$cache/cache.currency.123"));
}
function test_oc3_updater_version_compare() {
    assert_true(CCUpdater::isNewer('1.0.1', '1.0.0'));
    assert_false(CCUpdater::isNewer('1.0.0', '1.0.0'));
    assert_false(CCUpdater::isNewer('', '1.0.0'));
}
