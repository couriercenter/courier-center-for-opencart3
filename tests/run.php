<?php
/**
 * Minimal test runner (PHP 7.2 to 8.5, no dependencies).
 * Every notice / warning / deprecation fails the test: zero-notice policy (spec 5.11).
 * Usage: php tests/run.php [name-filter]
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false; // deliberately silenced with @ (e.g. @copy() whose failure the code handles)
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
require __DIR__ . '/bootstrap.php';

$filter = isset($argv[1]) ? (string)$argv[1] : '';
$before = get_defined_functions();
$files = glob(__DIR__ . '/unit/*Test.php');
sort($files);
foreach ($files as $file) {
    require_once $file;
}
$after = get_defined_functions();
$tests = array_values(array_filter(array_diff($after['user'], $before['user']), function ($name) use ($filter) {
    return strpos($name, 'test_') === 0 && ($filter === '' || stripos($name, $filter) !== false);
}));
sort($tests);

$passed = 0;
$failed = array();
foreach ($tests as $test) {
    try {
        $test();
        $passed++;
        echo '.';
    } catch (Throwable $e) {
        $failed[] = array($test, $e);
        echo 'F';
    }
}
echo "\n\n";
foreach ($failed as $f) {
    echo 'FAIL ' . $f[0] . "\n  " . get_class($f[1]) . ': ' . str_replace("\n", "\n  ", $f[1]->getMessage())
        . "\n  at " . $f[1]->getFile() . ':' . $f[1]->getLine() . "\n\n";
}
printf("PHP %s: %d passed, %d failed\n", PHP_VERSION, $passed, count($failed));
exit($failed ? 1 : 0);
