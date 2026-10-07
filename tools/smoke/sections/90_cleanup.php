<?php
/**
 * Voids the active vouchers this run created (all of them when "cleanup" is requested explicitly), with the real API,
 * so no test shipment stays alive. Always runs.
 */
return function (SmokeContext $c) {
    $s = $c->shop;
    if (!$s->rows("SHOW TABLES LIKE '" . $s->env['prefix'] . "cc_shipments'")) {
        echo "  (no cc_shipments table)\n";
        return;
    }
    $rows = $s->rows("SELECT `order_id`, `voucher_number` FROM `{p}cc_shipments` WHERE `is_voided` = 0 AND `voucher_number` <> ''");
    if (empty($c->data['cleanup_all']) && !empty($c->data['preexisting'])) {
        $keep = array_flip($c->data['preexisting']);
        $kept = 0;
        $rows = array_values(array_filter($rows, function ($r) use ($keep, &$kept) {
            if (isset($keep[$r['voucher_number']])) {
                $kept++;
                return false;
            }
            return true;
        }));
        if ($kept) {
            echo "  ($kept voucher(s) that were active before this run stay untouched; run \"cleanup\" alone to void them)" . PHP_EOL;
        }
    }
    if (!$rows) {
        echo "  (no active vouchers)\n";
        return;
    }
    require_once cc_repo_root() . '/upload/system/library/couriercenter/CCApi.php';
    $cr = cc_oc4_credentials();
    $api = new \Opencart\Extension\Couriercenter\Library\CCApi($cr['user_alias'], $cr['credential_value'], $cr['api_key'], $cr['billing_account']);
    foreach ($rows as $r) {
        $res = $api->void_shipment($r['voucher_number']);
        if ($res === true) {
            $s->exec("UPDATE `{p}cc_shipments` SET `is_voided` = 1 WHERE `order_id` = '" . (int)$r['order_id'] . "'");
        }
        $c->ok($res === true, 'cleanup: voided ' . $r['voucher_number'] . ($res === true ? '' : ' -> ' . json_encode($res, JSON_UNESCAPED_UNICODE)));
    }
};
