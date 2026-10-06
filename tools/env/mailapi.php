<?php
/**
 * Read side of tools/env/mailsink.php, shaped like the Mailpit API used by the smoke tests.
 * Start: /c/xampp/php/php.exe -S 127.0.0.1:8025 tools/env/mailapi.php
 *   GET    /api/v1/messages -> {"total": N, "messages": [{ID, Created, From, To, Subject}, ...]} (newest first)
 *   DELETE /api/v1/messages -> deletes every stored message
 *   GET    /                -> HTML list (subject, recipients, raw message)
 */
$dir = __DIR__ . '/state/mail';
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$files = is_dir($dir) ? glob($dir . '/*.json') : array();
rsort($files);

if ($uri === '/api/v1/messages') {
    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        foreach ($files as $f) {
            @unlink($f);
        }
        header('Content-Type: application/json');
        echo '{"success":true}';
        return true;
    }
    $messages = array();
    foreach ($files as $f) {
        $m = json_decode((string)file_get_contents($f), true);
        if (is_array($m)) {
            unset($m['Raw']);
            $messages[] = $m;
        }
    }
    header('Content-Type: application/json');
    echo json_encode(array('total' => count($messages), 'messages' => $messages), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return true;
}

header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><meta charset="utf-8"><title>cc mail sink</title><h1>Local mail sink (' . count($files) . ')</h1>';
foreach ($files as $f) {
    $m = json_decode((string)file_get_contents($f), true);
    $to = implode(', ', array_map(function ($a) { return $a['Address']; }, isset($m['To']) ? $m['To'] : array()));
    echo '<details><summary><b>' . htmlspecialchars((string)$m['Subject']) . '</b> → ' . htmlspecialchars($to)
        . ' <small>' . htmlspecialchars((string)$m['Created']) . '</small></summary><pre>'
        . htmlspecialchars((string)$m['Raw']) . '</pre></details>';
}
return true;
