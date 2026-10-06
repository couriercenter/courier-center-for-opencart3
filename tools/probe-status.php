<?php
/**
 * Checks the real GetShipmentDetails response shape (spec 7.7) READ-ONLY: it asks the API
 * about AWBs that already exist in the local OC4 shop (cc_shipments) and creates nothing.
 * Prints key paths and parse_status() only: never credentials, never addresses.
 * Usage: /c/xampp/php/php.exe tools/probe-status.php [max=3]
 */
require __DIR__ . '/env/lib.php';
$lib = dirname(__DIR__) . '/upload/system/library/couriercenter';
require $lib . '/CCApi.php';
require $lib . '/CCOrderHistory.php';
require $lib . '/CCStatusTracker.php';

use Opencart\Extension\Couriercenter\Library\CCApi;
use Opencart\Extension\Couriercenter\Library\CCStatusTracker;

function cc_key_paths($value, $prefix, array &$out) {
    if (!is_array($value)) {
        $out[] = $prefix;
        return;
    }
    foreach ($value as $k => $v) {
        cc_key_paths($v, $prefix === '' ? (string)$k : "$prefix.$k", $out);
    }
}

$max = isset($argv[1]) ? max(1, (int)$argv[1]) : 3;
$cfg = cc_config();
$c = cc_oc4_credentials();
$api = new CCApi($c['user_alias'], $c['credential_value'], $c['api_key'], $c['billing_account']);
$res = cc_mysql($cfg['oc4_db'])->query("SELECT `voucher_number` FROM `{$cfg['oc4_prefix']}cc_shipments` WHERE `voucher_number` != '' ORDER BY `id` DESC LIMIT " . $max);
$awbs = array();
while ($res && ($row = $res->fetch_assoc())) {
    $awbs[] = $row['voucher_number'];
}
if (!$awbs) {
    echo "The local OC4 shop has no shipments to probe\n";
    exit(1);
}
$found = false;
foreach ($awbs as $i => $awb) {
    $details = $api->get_shipment_details($awb);
    if (isset($details['success']) && $details['success'] === false) {
        echo "$awb: API error: {$details['error']}\n";
        continue;
    }
    if ($i === 0) {
        $paths = array();
        cc_key_paths($details, '', $paths);
        $paths = array_values(array_filter($paths, function ($p) {
            return !preg_match('/Address|Phone|Mobile|Name|Email|Comment|AttentionTo|Reference/i', $p);
        }));
        echo "GetShipmentDetails key paths (" . count($paths) . ", personal data filtered):\n  " . implode("\n  ", array_slice($paths, 0, 120)) . "\n";
    }
    $found = $found || isset($details['ShipmentDetails'][0]['ShipmentInfo']['ShipmentStatus']);
    $s = CCStatusTracker::parse_status($details);
    echo "$awb: parse_status() code='{$s['code']}' desc='{$s['desc']}' action='{$s['action']}'\n";
}
echo $found ? "OK: ShipmentDetails.0.ShipmentInfo.ShipmentStatus present\n" : "ShipmentDetails.0.ShipmentInfo.ShipmentStatus NOT FOUND - stop and ask (spec 9)\n";
exit($found ? 0 : 1);
