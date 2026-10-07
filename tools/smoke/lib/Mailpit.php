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
