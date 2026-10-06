<?php
/** No error-log line beyond the core baseline (spec 7.8). Always runs. */
return function (SmokeContext $c) {
    $s = $c->shop;
    $file = cc_state_dir() . '/' . $s->id . '-baseline.log';
    $baseline = is_file($file) ? array_filter(array_map('trim', file($file))) : array();
    $new = array_values(array_diff(array_unique($s->errorLogLines()), $baseline));
    $c->ok(!$new, 'error log: no new lines' . ($new ? ":\n      " . implode("\n      ", array_slice($new, 0, 20)) : ''));
};
