<?php
require_once dirname(__DIR__) . '/lib/common.php';

function cc_env($id) {
    $envs = require __DIR__ . '/envs.php';
    if (!isset($envs[$id])) {
        fwrite(STDERR, "Unknown env '$id' (use: " . implode(', ', array_keys($envs)) . ")\n");
        exit(2);
    }
    $env = $envs[$id];
    $env['id'] = (string)$id;
    return $env;
}

function cc_state_dir() {
    $dir = __DIR__ . '/state';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    return str_replace('\\', '/', $dir);
}

function cc_mysql($db = null) {
    $c = cc_config()['mysql'];
    mysqli_report(MYSQLI_REPORT_OFF);
    $m = @new mysqli($c['host'], $c['user'], $c['pass'], $db === null ? '' : $db, (int)$c['port']);
    if ($m->connect_error) {
        fwrite(STDERR, 'MySQL: ' . $m->connect_error . " (start MySQL from the XAMPP Control Panel)\n");
        exit(2);
    }
    $m->set_charset('utf8');
    return $m;
}

/** Upsert one OpenCart setting (store 0). */
function cc_setting(mysqli $db, $p, $code, $key, $value) {
    $c = $db->real_escape_string($code);
    $k = $db->real_escape_string($key);
    $v = $db->real_escape_string((string)$value);
    $db->query("DELETE FROM `{$p}setting` WHERE `store_id` = 0 AND `key` = '$k'");
    $db->query("INSERT INTO `{$p}setting` SET `store_id` = 0, `code` = '$c', `key` = '$k', `value` = '$v', `serialized` = 0");
}

/** Courier Center API credentials of the local OC4 shop. NEVER print them. */
function cc_oc4_credentials() {
    $cfg = cc_config();
    $m = cc_mysql($cfg['oc4_db']);
    $p = $cfg['oc4_prefix'];
    $out = array();
    foreach (array('user_alias', 'credential_value', 'api_key', 'billing_account') as $k) {
        $res = $m->query("SELECT `value` FROM `{$p}setting` WHERE `key` = 'shipping_courier_center_$k' LIMIT 1");
        $row = $res ? $res->fetch_assoc() : null;
        $out[$k] = $row ? html_entity_decode($row['value'], ENT_QUOTES, 'UTF-8') : '';
        if ($out[$k] === '') {
            fwrite(STDERR, "The local OC4 shop has no '$k' setting - fill the Courier Center settings in OC4 first\n");
            exit(2);
        }
    }
    return $out;
}

/** Download $url to $dest (follows redirects). */
function cc_download($url, $dest) {
    $fp = fopen($dest, 'wb');
    $ch = curl_init($url);
    curl_setopt_array($ch, array(CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true, CURLOPT_FAILONERROR => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (cc-setup)', CURLOPT_TIMEOUT => 900));
    $ok = curl_exec($ch);
    fclose($fp);
    if (!$ok) {
        @unlink($dest);
        fwrite(STDERR, "Download failed: $url\n");
        exit(1);
    }
}
