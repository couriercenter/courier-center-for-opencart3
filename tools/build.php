<?php
/**
 * Builds dist/courier_center_oc3.ocmod.zip (spec 6). Fails on any problem.
 * Usage: /c/xampp/php/php.exe tools/build.php
 */
require __DIR__ . '/lib/common.php';
require __DIR__ . '/lib/package.php';

function cc_fail($message) {
    fwrite(STDERR, "BUILD FAILED: $message\n");
    exit(1);
}

$root = cc_repo_root();

// 1. version.json == first CHANGELOG entry
$v = json_decode((string)file_get_contents("$root/upload/system/library/couriercenter/version.json"), true);
$version = isset($v['version']) ? (string)$v['version'] : '';
if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
    cc_fail("version.json has no valid version ('$version')");
}
if (!preg_match('/^##\s*v?(\d+\.\d+\.\d+)/m', (string)@file_get_contents("$root/CHANGELOG.md"), $m) || $m[1] !== $version) {
    cc_fail("the first entry of CHANGELOG.md must be v$version");
}

// 2. lint + identical shared library (spec 5.3)
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/tools/lint.php"), $code);
if ($code !== 0) {
    cc_fail('lint');
}
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/tools/sync-library.php") . ' --check', $code);
if ($code !== 0) {
    cc_fail('shared library differs from the OC4 repo');
}

// 3. zip upload/ (installer allow-list), size limit
@mkdir("$root/dist", 0777, true);
$zip_file = "$root/dist/courier_center_oc3.ocmod.zip";
@unlink($zip_file);
$za = new ZipArchive();
if ($za->open($zip_file, ZipArchive::CREATE) !== true) {
    cc_fail("cannot create $zip_file");
}
$count = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/upload", FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $rel = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen("$root/upload")), '/');
    if (!cc_path_allowed($rel)) {
        cc_fail("not allowed by the OpenCart installer: upload/$rel");
    }
    $za->addFile($file->getPathname(), 'upload/' . $rel);
    $count++;
}
$za->close();
$size = filesize($zip_file);
if ($size > 2 * 1024 * 1024) {
    cc_fail('zip is ' . round($size / 1048576, 2) . ' MB (max 2 MB, spec 6)');
}
echo "Built dist/courier_center_oc3.ocmod.zip (v$version, $count files, " . round($size / 1024) . " KB)\n";
