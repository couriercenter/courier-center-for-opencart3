<?php
/** Runs tests/run.php on every supported PHP. Usage: /c/xampp/php/php.exe tools/test-all.php [filter] */
require __DIR__ . '/lib/common.php';
$fail = 0;
foreach (array('7.2', '7.4', '8.1', '8.2', '8.5') as $v) {
    $argv_run = array(cc_php($v), cc_repo_root() . '/tests/run.php');
    if (isset($argv[1])) {
        $argv_run[] = $argv[1];
    }
    list($code, $out) = cc_exec($argv_run);
    echo "=== PHP $v ===\n$out\n";
    if ($code !== 0) {
        $fail++;
    }
}
exit($fail ? 1 : 0);
