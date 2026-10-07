<?php
/** Docs + bug report pages; the report reaches the local sink with decoded text and the real version. */
return function (SmokeContext $c) {
    $s = $c->shop;
    $s->login();
    $ver = json_decode(file_get_contents(cc_repo_root() . '/upload/system/library/couriercenter/version.json'), true)['version'];
    $docs = $s->adminGet('extension/shipping/courier_center/docs')['body'];
    $c->ok(strpos($docs, 'v' . $ver) !== false && strpos($docs, 'OpenCart 3') !== false, 'docs page');
    $bug = $s->adminGet('extension/shipping/courier_center/bug')['body'];
    $c->ok(strpos($bug, 'cc-bug-submit') !== false && strpos($bug, '<code>' . $ver . '</code>') !== false, 'bug report page shows the real version (spec 10 #8)');
    @unlink(cc_state_dir() . '/sink.log');
    $r = $s->adminJson('extension/shipping/courier_center/bug/submit', array('title' => 'Smoke & "quotes"', 'description' => 'smoke', 'severity' => 'low', 'email' => 'admin@example.com'));
    $sink = (string)@file_get_contents(cc_state_dir() . '/sink.log');
    $c->ok(!empty($r['success']) && strpos($sink, '/api/report') !== false && strpos($sink, 'Smoke & \"quotes\"') !== false && strpos($sink, '"plugin_version":"' . $ver . '"') !== false,
        'bug report reached the sink with decoded text + version');
};
