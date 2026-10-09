<?php
/** Void the AWBs given on the command line with the test account (UI tests). */
require dirname(__DIR__) . '/smoke/bootstrap.php';
require_once cc_repo_root() . '/upload/system/library/couriercenter/CCApi.php';
$cr = cc_oc4_credentials();
$api = new \Opencart\Extension\Couriercenter\Library\CCApi($cr['user_alias'], $cr['credential_value'], $cr['api_key'], $cr['billing_account']);
foreach (array_slice($argv, 1) as $awb) {
    $r = $api->void_shipment($awb);
    if ($r === true && isset($argv[0]) && getenv('CC_UI_DB')) {
        $m = cc_mysql(getenv('CC_UI_DB'));
        $m->query("UPDATE `oc_cc_shipments` SET `is_voided` = 1 WHERE `voucher_number` = '" . $m->real_escape_string($awb) . "'");
    }
    echo 'void ' . $awb . ': ' . ($r === true ? 'ok' : json_encode($r, JSON_UNESCAPED_UNICODE)) . "\n";
}
