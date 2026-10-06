<?php
/**
 * Lints the package (upload/): php -l on PHP 7.0 / 7.2 / 8.5 by tier, plus the
 * rules of tools/lib/lint_rules.php. Usage: /c/xampp/php/php.exe tools/lint.php
 */
require __DIR__ . '/lib/common.php';
require __DIR__ . '/lib/lint_rules.php';

function cc_tier($rel) {
    if (strpos($rel, 'upload/system/library/couriercenter/lib/') === 0) {
        return 'vendor';
    }
    if ($rel === 'upload/catalog/model/extension/shipping/courier_center.php'
        || strpos($rel, '/language/') !== false
        || $rel === 'upload/system/library/couriercenter/oc3/CCVersion.php') {
        return '5.4';
    }
    if (preg_match('#^upload/system/library/couriercenter/CC[A-Za-z]+\.php$#', $rel)) {
        return '7.2';
    }
    return '7.0';
}

$root = cc_repo_root();
$problems = array();
foreach (cc_files($root . '/upload', array('php')) as $rel) {
    $tier = cc_tier($rel);
    $versions = in_array($tier, array('5.4', '7.0'), true) ? array('7.0', '7.2', '8.5') : array('7.2', '8.5');
    foreach ($versions as $v) {
        list($code, $out) = cc_exec(array(cc_php($v), '-n', '-l', $root . '/' . $rel));
        if ($code !== 0) {
            $problems[] = "$rel [PHP $v]: " . trim($out);
        }
    }
    if ($tier !== 'vendor') {
        foreach (cc_lint_php_source(file_get_contents($root . '/' . $rel), $tier) as $p) {
            $problems[] = "$rel: $p";
        }
    }
}
foreach (cc_files($root . '/upload', array('twig')) as $rel) {
    foreach (cc_lint_twig_source(file_get_contents($root . '/' . $rel)) as $p) {
        $problems[] = "$rel: $p";
    }
}
if ($problems) {
    echo implode("\n", $problems), "\n\nLINT FAILED (", count($problems), ")\n";
    exit(1);
}
echo "Lint OK\n";
