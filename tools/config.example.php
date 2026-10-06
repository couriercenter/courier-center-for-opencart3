<?php
// Machine-specific paths. tools/setup-php.php writes tools/config.php (not committed).
return array(
    'php' => array(
        '7.0' => 'C:/Users/python/php-versions/php70/php.exe',
        '7.2' => 'C:/Users/python/php-versions/php72/php.exe',
        '7.4' => 'C:/Users/python/php-versions/php74/php.exe',
        '8.1' => 'C:/Users/python/php-versions/php81/php.exe',
        '8.2' => 'C:/xampp/php/php.exe',
        '8.5' => 'C:/Users/python/php-versions/php85/php.exe',
    ),
    'oc4_library_dir' => 'C:/xampp/htdocs/opencart4/extension/couriercenter/library',
    'oc4_repo_dir'    => 'C:/xampp/htdocs/opencart4/extension/couriercenter',
    // Git ref of the OC4 repo that must hold the same library (spec 5.3).
    // 'library-oc3-compat' until the user merges that branch, then 'main'.
    'oc4_library_ref' => 'library-oc3-compat',
    'oc4_db'          => 'opencart4',
    'oc4_prefix'      => 'oc_',
    'mysql'           => array('host' => '127.0.0.1', 'user' => 'root', 'pass' => '', 'port' => 3306),
);
