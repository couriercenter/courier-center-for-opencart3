<?php
/**
 * Keeps the shared library identical in the OC3 and OC4 repos (spec 5.3).
 *   --check  (default) compare with the OC4 git ref in tools/config.php; exit 1 if different
 *   --to-oc4           copy the OC3 files into the OC4 working tree (be on the right branch!)
 */
require __DIR__ . '/lib/common.php';

$shared = array('CCApi.php', 'CCCityScope.php', 'CCOrderAdapter.php', 'CCPdfScaler.php', 'CCShipmentBuilder.php', 'CCOrderHistory.php', 'CCStatusTracker.php');
$mode = isset($argv[1]) ? $argv[1] : '--check';
$cfg = cc_config();
$oc3 = cc_repo_root() . '/upload/system/library/couriercenter';
$oc4_repo = rtrim($cfg['oc4_repo_dir'], '/');

if ($mode === '--to-oc4') {
    foreach ($shared as $f) {
        copy("$oc3/$f", "$oc4_repo/library/$f");
        echo "copied $f\n";
    }
    exit(0);
}

$ref = isset($cfg['oc4_library_ref']) ? $cfg['oc4_library_ref'] : 'main';
$diff = array();
foreach ($shared as $f) {
    list($code, $theirs) = cc_exec(array('git', '-C', $oc4_repo, 'show', "$ref:library/$f"));
    $ours = str_replace("\r\n", "\n", file_get_contents("$oc3/$f"));
    if ($code !== 0 || rtrim(str_replace("\r\n", "\n", $theirs)) !== rtrim($ours)) {
        $diff[] = $f;
    }
}
if ($diff) {
    echo "Shared library differs from OC4 ($ref): " . implode(', ', $diff) . "\n";
    exit(1);
}
echo "Shared library identical in OC3 and OC4 ($ref, " . count($shared) . " files)\n";
