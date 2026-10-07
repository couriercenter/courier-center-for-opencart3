<?php
/**
 * End-to-end smoke tests against a local OpenCart 3 shop, with the REAL Courier Center API.
 * Usage: /c/xampp/php/php.exe tools/smoke/run.php <3020|3038|3051> [section ...]
 * Sections = tools/smoke/sections/NN_name.php, run in order (default: all).
 * "cleanup" (voids every active test voucher) and "logcheck" always run.
 */
require __DIR__ . '/bootstrap.php';

$shop = smoke_shop(isset($argv[1]) ? $argv[1] : '');
$only = array_slice($argv, 2);
$ctx = new SmokeContext($shop);
// The cleanup of a normal run voids only what THIS run created: the vouchers that were already active when it started
// (e.g. made by hand in the test shop) stay. (A snapshot, not a timestamp: OpenCart writes dates in its own timezone.)
// Running "cleanup" explicitly voids every active voucher.
$ctx->data['preexisting'] = array();
if ($shop->rows("SHOW TABLES LIKE '" . $shop->env['prefix'] . "cc_shipments'")) {
    foreach ($shop->rows("SELECT `voucher_number` FROM `{p}cc_shipments` WHERE `is_voided` = 0 AND `voucher_number` <> ''") as $r) {
        $ctx->data['preexisting'][] = $r['voucher_number'];
    }
}
$ctx->data['cleanup_all'] = in_array('cleanup', $only, true);
$files = glob(__DIR__ . '/sections/*.php');
sort($files);
foreach ($files as $file) {
    $name = preg_replace('/^\d+_/', '', basename($file, '.php'));
    if ($only && !in_array($name, array('cleanup', 'logcheck'), true) && !in_array($name, $only, true)) {
        continue;
    }
    echo "== $name\n";
    try {
        $section = require $file;
        $section($ctx);
    } catch (Throwable $e) {
        $ctx->fail("$name: " . get_class($e) . ': ' . $e->getMessage());
    }
}
echo $ctx->summary();
exit($ctx->failures ? 1 : 0);
