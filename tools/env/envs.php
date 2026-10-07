<?php
// Local OpenCart 3 test shops (spec 7.4). Admin login: admin / admin123.
return array(
    '3020' => array('version' => '3.0.2.0', 'php' => '7.2', 'dir' => 'C:/Users/python/oc3-envs/oc3020', 'url' => 'http://127.0.0.1:8720/',
        'serve' => 'builtin', 'port' => 8720, 'db' => 'cc_oc3020', 'prefix' => 'shop_', 'admin_dir' => 'admin'),
    '3038' => array('version' => '3.0.3.8', 'php' => '7.4', 'dir' => 'C:/Users/python/oc3-envs/oc3038', 'url' => 'http://127.0.0.1:8738/',
        'serve' => 'builtin', 'port' => 8738, 'db' => 'cc_oc3038', 'prefix' => 'oc_', 'admin_dir' => 'manage'),
    '3051' => array('version' => '3.0.5.1', 'php' => '8.2', 'dir' => 'C:/xampp/htdocs/oc3051', 'url' => 'http://localhost/oc3051/',
        'serve' => 'apache', 'port' => 80, 'db' => 'cc_oc3051', 'prefix' => 'oc_', 'admin_dir' => 'admin'),
    // Extra runtimes (PHP 8.1 / 8.5 are only covered by unit tests on the three shops above).
    '3041' => array('version' => '3.0.4.1', 'php' => '8.1', 'dir' => 'C:/Users/python/oc3-envs/oc3041', 'url' => 'http://127.0.0.1:8741/',
        'serve' => 'builtin', 'port' => 8741, 'db' => 'cc_oc3041', 'prefix' => 'oc_', 'admin_dir' => 'admin'),
    '3051p85' => array('version' => '3.0.5.1', 'php' => '8.5', 'dir' => 'C:/Users/python/oc3-envs/oc3051p85', 'url' => 'http://127.0.0.1:8855/',
        'serve' => 'builtin', 'port' => 8855, 'db' => 'cc_oc3051p85', 'prefix' => 'oc_', 'admin_dir' => 'admin'),
);
