<?php
require_once dirname(__DIR__) . '/env/lib.php';
require_once __DIR__ . '/lib/Http.php';
require_once __DIR__ . '/lib/Shop.php';
require_once __DIR__ . '/lib/Context.php';

function smoke_shop($id) {
    return new SmokeShop(cc_env($id));
}
