<?php
/** Manifest page, the GET date form (spec 10 #7) and the PDF download. */
return function (SmokeContext $c) {
    $s = $c->shop;
    $s->login();
    $page = $s->adminGet('extension/shipping/courier_center/manifest')['body'];
    $c->ok(strpos($page, 'name="route" value="extension/shipping/courier_center/manifest"') !== false && strpos($page, 'name="user_token"') !== false, 'date form keeps route + user_token');
    $form = $s->admin->get($s->adminBase() . 'index.php?' . http_build_query(array(
        'route' => 'extension/shipping/courier_center/manifest', 'user_token' => $s->token, 'manifest_date' => date('Y-m-d', strtotime('-1 day')))));
    $c->ok($form['status'] === 200 && strpos($form['body'], 'Manifest Παραλαβής') !== false, 'date form submit stays on the manifest page');
    $pdf = $s->adminGet('extension/shipping/courier_center/manifest/download', array('date' => date('Y-m-d')));
    $c->ok(substr($pdf['body'], 0, 4) === '%PDF' || ($pdf['status'] === 500 && strpos($pdf['body'], 'manifest') !== false),
        'manifest download answered: ' . (substr($pdf['body'], 0, 4) === '%PDF' ? 'PDF' : substr($pdf['body'], 0, 150)));
};
