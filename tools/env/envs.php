<?php
// Local OpenCart 3 test shops (spec 7.4). Admin login: admin / admin123.
return array(
    '3020' => array('version' => '3.0.2.0', 'php' => '7.2', 'dir' => 'C:/Users/python/oc3-envs/oc3020', 'url' => 'http://127.0.0.1:8720/',
        'serve' => 'builtin', 'port' => 8720, 'db' => 'cc_oc3020', 'prefix' => 'shop_', 'admin_dir' => 'admin'),
    '3038' => array('version' => '3.0.3.8', 'php' => '7.4', 'dir' => 'C:/Users/python/oc3-envs/oc3038', 'url' => 'http://127.0.0.1:8738/',
        'serve' => 'builtin', 'port' => 8738, 'db' => 'cc_oc3038', 'prefix' => 'oc_', 'admin_dir' => 'manage'),
    '3051' => array('version' => '3.0.5.1', 'php' => '8.2', 'dir' => 'C:/xampp/htdocs/oc3051', 'url' => 'http://localhost/oc3051/',
        'serve' => 'apache', 'port' => 80, 'db' => 'cc_oc3051', 'prefix' => 'oc_', 'admin_dir' => 'admin'),
);
