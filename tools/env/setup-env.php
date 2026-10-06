<?php
/**
 * Builds a local OpenCart 3 test shop from scratch (spec 7.4).
 * Usage: /c/xampp/php/php.exe tools/env/setup-env.php <3020|3038|3051> [--force]
 */
require __DIR__ . '/lib.php';

$e = cc_env(isset($argv[1]) ? $argv[1] : '');
$force = in_array('--force', $argv, true);
$src = 'C:/Users/python/oc3-envs/_src';
@mkdir($src, 0777, true);

// The release asset is preferred: some tag source zips (e.g. 3.0.3.8) lack vendor packages
// that the composer autoloader requires (react/promise is an empty folder there).
$zip = "$src/opencart-{$e['version']}.zip";
if (!is_file($zip)) {
    $ch = curl_init("https://github.com/opencart/opencart/releases/download/{$e['version']}/opencart-{$e['version']}.zip");
    curl_setopt_array($ch, array(CURLOPT_NOBODY => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'Mozilla/5.0 (cc-setup)', CURLOPT_TIMEOUT => 60));
    curl_exec($ch);
    $has_asset = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
    cc_download($has_asset
        ? "https://github.com/opencart/opencart/releases/download/{$e['version']}/opencart-{$e['version']}.zip"
        : "https://codeload.github.com/opencart/opencart/zip/refs/tags/{$e['version']}", $zip);
}

$dir = $e['dir'];
if (is_dir($dir)) {
    if (!$force) {
        fwrite(STDERR, "$dir exists (add --force to rebuild)\n");
        exit(2);
    }
    cc_rrmdir($dir);
}
$m = cc_mysql();
$m->query("DROP DATABASE IF EXISTS `{$e['db']}`");
$m->query("CREATE DATABASE `{$e['db']}` CHARACTER SET utf8 COLLATE utf8_general_ci");

// 1. Files: upload/* (release asset) or opencart-<v>/upload/* (tag source) -> $dir
$tmp = "$src/x-{$e['id']}";
cc_rrmdir($tmp);
$za = new ZipArchive();
$za->open($zip);
$za->extractTo($tmp);
$za->close();
@mkdir(dirname($dir), 0777, true);
rename(is_dir("$tmp/upload") ? "$tmp/upload" : "$tmp/opencart-{$e['version']}/upload", $dir);
cc_rrmdir($tmp);
rename("$dir/config-dist.php", "$dir/config.php");
rename("$dir/admin/config-dist.php", "$dir/admin/config.php");

// 2. Install with the shop's own PHP version.
list($code, $out) = cc_exec(array(cc_php($e['php']), "$dir/install/cli_install.php", 'install',
    '--db_hostname', '127.0.0.1', '--db_username', 'root', '--db_password', '', '--db_database', $e['db'],
    '--db_driver', 'mysqli', '--db_port', '3306', '--db_prefix', $e['prefix'],
    '--username', 'admin', '--password', 'admin123', '--email', 'admin@example.com',
    '--http_server', $e['url']));
if ($code !== 0 || stripos($out, 'SUCCESS') === false) {
    echo $out, "\n";
    exit(1);
}
cc_rrmdir("$dir/install");

// 3. Renamed admin folder (spec 4.1: installer maps admin/ to DIR_APPLICATION).
if ($e['admin_dir'] !== 'admin') {
    rename("$dir/admin", "$dir/{$e['admin_dir']}");
    $cfg = "$dir/{$e['admin_dir']}/config.php";
    file_put_contents($cfg, str_replace('/admin/', '/' . $e['admin_dir'] . '/', file_get_contents($cfg)));
}

// 4. Test data and settings.
$db = cc_mysql($e['db']);
$p = $e['prefix'];
$api = $db->query("SELECT `api_id` FROM `{$p}api` WHERE `username` = 'Default' LIMIT 1")->fetch_assoc();
$api_id = (int)$api['api_id'];
$db->query("UPDATE `{$p}api` SET `key` = 'cc-smoke-api-key', `status` = 1 WHERE `api_id` = $api_id");
$db->query("DELETE FROM `{$p}api_ip` WHERE `api_id` = $api_id");
foreach (array('127.0.0.1', '::1') as $ip) {
    $db->query("INSERT INTO `{$p}api_ip` SET `api_id` = $api_id, `ip` = '$ip'");
}
foreach (array(
    'config_mail_engine' => 'smtp', 'config_mail_smtp_hostname' => '127.0.0.1', 'config_mail_smtp_port' => '1025',
    'config_mail_smtp_timeout' => '5', 'config_mail_smtp_username' => '', 'config_mail_smtp_password' => '',
    'config_email' => 'shop@example.com', 'config_error_display' => '1', 'config_error_log' => '1',
) as $k => $v) {
    cc_setting($db, $p, 'config', $k, $v);
}
// A non-COD payment method (BOX NOW does not allow COD).
$db->query("INSERT INTO `{$p}extension` SET `type` = 'payment', `code` = 'bank_transfer'");
foreach (array('payment_bank_transfer_status' => '1', 'payment_bank_transfer_order_status_id' => '1',
    'payment_bank_transfer_bank1' => 'Test bank (cc smoke)', 'payment_bank_transfer_sort_order' => '1') as $k => $v) {
    cc_setting($db, $p, 'payment_bank_transfer', $k, $v);
}
// Test products: 40 = 0.5 kg, no dimensions. 43 = 1500 g, 30x20x5 cm (unit conversion).
$kg = $db->query("SELECT `weight_class_id` FROM `{$p}weight_class_description` WHERE `unit` = 'kg' LIMIT 1")->fetch_assoc()['weight_class_id'];
$g  = $db->query("SELECT `weight_class_id` FROM `{$p}weight_class_description` WHERE `unit` = 'g' LIMIT 1")->fetch_assoc()['weight_class_id'];
$cm = $db->query("SELECT `length_class_id` FROM `{$p}length_class_description` WHERE `unit` = 'cm' LIMIT 1")->fetch_assoc()['length_class_id'];
$db->query("UPDATE `{$p}product` SET `weight` = 0.5, `weight_class_id` = $kg, `length` = 0, `width` = 0, `height` = 0, `quantity` = 1000, `subtract` = 0, `status` = 1, `shipping` = 1 WHERE `product_id` = 40");
$db->query("UPDATE `{$p}product` SET `weight` = 1500, `weight_class_id` = $g, `length` = 30, `width` = 20, `height` = 5, `length_class_id` = $cm, `quantity` = 1000, `subtract` = 0, `status` = 1, `shipping` = 1 WHERE `product_id` = 43");
// Telemetry / bug reports / updater of the extension go to the local sink, never to the real dashboard.
cc_setting($db, $p, 'courier_center_state', 'shipping_courier_center_dev_overrides',
    json_encode(array('dashboard_base' => 'http://127.0.0.1:8799', 'release_api' => 'http://127.0.0.1:8799/release.json')));

echo "OK {$e['id']}: {$e['url']}  admin: {$e['url']}{$e['admin_dir']}/  (admin / admin123)\n";
