<?php
/** Helpers shared by the developer tools (run with XAMPP PHP 8.2). */

function cc_repo_root() {
    return str_replace('\\', '/', dirname(__DIR__, 2));
}

function cc_config() {
    static $cfg = null;
    if ($cfg === null) {
        $file = dirname(__DIR__) . '/config.php';
        if (!is_file($file)) {
            fwrite(STDERR, "tools/config.php is missing - run: /c/xampp/php/php.exe tools/setup-php.php\n");
            exit(2);
        }
        $cfg = require $file;
    }
    return $cfg;
}

function cc_php($version) {
    $cfg = cc_config();
    if (empty($cfg['php'][$version]) || !is_file($cfg['php'][$version])) {
        fwrite(STDERR, "PHP $version is not configured in tools/config.php\n");
        exit(2);
    }
    return $cfg['php'][$version];
}

/** Run a command. @return array [int exit_code, string combined_output] */
function cc_exec(array $argv) {
    $cmd = implode(' ', array_map('escapeshellarg', $argv)) . ' 2>&1';
    $out = array();
    $code = 0;
    exec($cmd, $out, $code);
    return array($code, implode("\n", $out));
}

/** Files under $dir with one of $exts, repo-relative with forward slashes, sorted. */
function cc_files($dir, array $exts) {
    $root = cc_repo_root();
    $list = array();
    if (!is_dir($dir)) {
        return $list;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && in_array(strtolower($file->getExtension()), $exts, true)) {
            $list[] = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($root)), '/');
        }
    }
    sort($list);
    return $list;
}

function cc_rrmdir($dir) {
    if (!is_dir($dir)) {
        return;
    }
    foreach (array_diff(scandir($dir), array('.', '..')) as $f) {
        $p = $dir . '/' . $f;
        is_dir($p) && !is_link($p) ? cc_rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}
