<?php
/** Mailpit API helpers (local SMTP sink, http://127.0.0.1:8025). */
function cc_mailpit_clear() {
    $ch = curl_init('http://127.0.0.1:8025/api/v1/messages');
    curl_setopt_array($ch, array(CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10));
    curl_exec($ch);
}

/** First message with exactly this subject (waits up to 5 s), or null. */
function cc_mailpit_find($subject) {
    for ($i = 0; $i < 10; $i++) {
        $json = json_decode((string)@file_get_contents('http://127.0.0.1:8025/api/v1/messages'), true);
        foreach ((isset($json['messages']) ? $json['messages'] : array()) as $m) {
            if (isset($m['Subject']) && $m['Subject'] === $subject) {
                return $m;
            }
        }
        usleep(500000);
    }
    return null;
}

/** Decoded text of every stored message (local sink files; base64 parts decoded). @return string[] */
function cc_mail_bodies() {
    $out = array();
    foreach (glob(cc_state_dir() . '/mail/*.json') ?: array() as $f) {
        $m = json_decode((string)file_get_contents($f), true);
        $raw = isset($m['Raw']) ? (string)$m['Raw'] : '';
        $text = $raw;
        if (preg_match_all('/(?:^[A-Za-z0-9+\/=]{16,}\r?\n)+/m', $raw, $mm)) {
            foreach ($mm[0] as $chunk) {
                $text .= "\n" . base64_decode(preg_replace('/\s+/', '', $chunk));
            }
        }
        $out[] = (isset($m['Subject']) ? $m['Subject'] : '') . "\n" . $text;
    }
    return $out;
}

/** Is there a message whose (decoded) body contains every needle? Waits up to 5 s. */
function cc_mail_with(array $needles) {
    for ($i = 0; $i < 10; $i++) {
        foreach (cc_mail_bodies() as $b) {
            $all = true;
            foreach ($needles as $n) {
                if (strpos($b, $n) === false) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                return true;
            }
        }
        usleep(500000);
    }
    return false;
}
