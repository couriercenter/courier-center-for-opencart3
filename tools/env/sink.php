<?php
/**
 * Local stand-in for the Courier Center dashboard and for GitHub releases (test shops only).
 * Start: /c/xampp/php/php.exe -S 127.0.0.1:8799 tools/env/sink.php
 *   POST /api/ping, /api/report -> appended to tools/env/state/sink.log, answers {"success":true}
 *   GET  /release.json, /release.zip -> files in tools/env/state/release/
 */
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$state = __DIR__ . '/state';
if (!is_dir($state)) {
    mkdir($state, 0777, true);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    file_put_contents($state . '/sink.log', date('c') . ' ' . $uri . ' ' . file_get_contents('php://input') . "\n", FILE_APPEND);
    http_response_code($uri === '/api/report' ? 201 : 200);
    header('Content-Type: application/json');
    echo '{"success":true}';
    return true;
}
if (in_array($uri, array('/release.json', '/release.zip'), true) && is_file($state . '/release' . $uri)) {
    header('Content-Type: ' . ($uri === '/release.zip' ? 'application/zip' : 'application/json'));
    readfile($state . '/release' . $uri);
    return true;
}
http_response_code(404);
echo 'not found';
return true;
