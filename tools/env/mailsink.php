<?php
/**
 * Minimal SMTP sink for the local test shops (stands in for Mailpit, whose .exe is
 * blocked by the endpoint security of the development PC).
 * Accepts every message on 127.0.0.1:1025 and stores it as JSON in tools/env/state/mail/.
 * Start: /c/xampp/php/php.exe tools/env/mailsink.php     (read it back through tools/env/mailapi.php)
 */
$dir = __DIR__ . '/state/mail';
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}
$server = stream_socket_server('tcp://127.0.0.1:1025', $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "mailsink: cannot listen on 127.0.0.1:1025: $errstr\n");
    exit(1);
}
echo "mailsink listening on 127.0.0.1:1025\n";

function cc_sink_header($raw, $name) {
    // Unfold continuation lines, then take the first matching header.
    $head = preg_replace("/\r?\n[ \t]+/", ' ', (string)strstr($raw . "\r\n\r\n", "\r\n\r\n", true));
    if (preg_match('/^' . preg_quote($name, '/') . ':\s*(.*)$/mi', $head, $m)) {
        $v = trim($m[1]);
        return function_exists('iconv_mime_decode') ? (string)iconv_mime_decode($v, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') : $v;
    }
    return '';
}

while (true) {
    $conn = @stream_socket_accept($server, -1);
    if (!$conn) {
        continue;
    }
    stream_set_timeout($conn, 30);
    fwrite($conn, "220 cc-mailsink ESMTP\r\n");
    $from = '';
    $to = array();
    while (($line = fgets($conn)) !== false) {
        $cmd = strtoupper(substr(trim($line), 0, 4));
        if ($cmd === 'EHLO') {
            fwrite($conn, "250-cc-mailsink\r\n250 8BITMIME\r\n");
        } elseif ($cmd === 'HELO') {
            fwrite($conn, "250 cc-mailsink\r\n");
        } elseif ($cmd === 'MAIL') {
            $from = preg_match('/<([^>]*)>/', $line, $m) ? $m[1] : '';
            $to = array();
            fwrite($conn, "250 OK\r\n");
        } elseif ($cmd === 'RCPT') {
            $to[] = preg_match('/<([^>]*)>/', $line, $m) ? $m[1] : '';
            fwrite($conn, "250 OK\r\n");
        } elseif ($cmd === 'DATA') {
            fwrite($conn, "354 End data with <CR><LF>.<CR><LF>\r\n");
            $raw = '';
            while (($l = fgets($conn)) !== false) {
                if (rtrim($l, "\r\n") === '.') {
                    break;
                }
                $raw .= (substr($l, 0, 2) === '..') ? substr($l, 1) : $l;
            }
            $id = date('YmdHis') . '-' . substr(md5(uniqid('', true)), 0, 8);
            $msg = array(
                'ID' => $id,
                'Created' => date('c'),
                'From' => array('Address' => $from),
                'To' => array_map(function ($a) { return array('Address' => $a); }, $to),
                'Subject' => cc_sink_header($raw, 'Subject'),
                'Raw' => $raw,
            );
            file_put_contents("$dir/$id.json", json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            echo date('H:i:s') . " stored $id: " . $msg['Subject'] . "\n";
            fwrite($conn, "250 OK: queued as $id\r\n");
        } elseif ($cmd === 'QUIT') {
            fwrite($conn, "221 Bye\r\n");
            break;
        } elseif ($cmd === 'RSET' || $cmd === 'NOOP') {
            fwrite($conn, "250 OK\r\n");
        } elseif ($cmd === 'AUTH') {
            fwrite($conn, "235 Authentication successful\r\n");
        } else {
            fwrite($conn, "250 OK\r\n");
        }
    }
    fclose($conn);
}
