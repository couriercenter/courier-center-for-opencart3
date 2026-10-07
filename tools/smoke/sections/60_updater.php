<?php
/** Updater from a local "9.9.9" release (sink), then back to the real version through the Extension Installer + auto-repair. */
return function (SmokeContext $c) {
    $s = $c->shop;
    $s->login();
    $root = cc_repo_root();
    $real = json_decode(file_get_contents("$root/upload/system/library/couriercenter/version.json"), true)['version'];
    $rel = cc_state_dir() . '/release';
    @mkdir($rel, 0777, true);
    copy("$root/dist/courier_center_oc3.ocmod.zip", "$rel/release.zip");
    $za = new ZipArchive();
    $za->open("$rel/release.zip");
    $za->addFromString('upload/system/library/couriercenter/version.json', json_encode(array('version' => '9.9.9')));
    $za->close();
    file_put_contents("$rel/release.json", json_encode(array('tag_name' => 'v9.9.9', 'body' => 'smoke release',
        'assets' => array(array('name' => 'courier_center_oc3.ocmod.zip', 'browser_download_url' => 'http://127.0.0.1:8799/release.zip')))));

    $chk = $s->adminJson('extension/shipping/courier_center/update/check');
    $c->ok(!empty($chk['update_available']) && $chk['latest'] === '9.9.9', 'check sees 9.9.9');
    $app = $s->adminJson('extension/shipping/courier_center/update/apply');
    $c->ok(!empty($app['success']), 'apply: ' . (isset($app['error']) ? $app['error'] : $app['message']));
    $ver = json_decode(file_get_contents($s->env['dir'] . '/system/library/couriercenter/version.json'), true);
    $c->ok($ver['version'] === '9.9.9', 'files updated to 9.9.9');
    $fin = $s->adminJson('extension/shipping/courier_center/update/finish');
    $c->ok(!empty($fin['success']), 'finish ran repair with the new code');
    $iv = $s->row("SELECT `value` FROM `{p}setting` WHERE `key` = 'shipping_courier_center_installed_version'");
    $c->ok($iv['value'] === '9.9.9', 'installed_version = 9.9.9');

    // Back to the real package through the Extension Installer; the header event repairs by itself.
    $j = SmokeShop::json($s->adminPost('marketplace/installer/upload', array(), array(), array('file' => "$root/dist/courier_center_oc3.ocmod.zip")));
    for ($i = 0; $i < 10 && empty($j['error']) && !empty($j['next']); $i++) {
        $j = SmokeShop::json($s->admin->get(str_replace('&amp;', '&', $j['next'])));
    }
    $s->adminGet('common/dashboard');
    $iv2 = $s->row("SELECT `value` FROM `{p}setting` WHERE `key` = 'shipping_courier_center_installed_version'");
    $c->ok($iv2['value'] === $real, "re-upload + any admin page: auto-repair back to $real");

    @unlink("$rel/release.json");
    @unlink("$rel/release.zip");
    $s->exec("DELETE FROM `{p}setting` WHERE `key` LIKE 'shipping\\_courier\\_center\\_update\\_%'");
};
