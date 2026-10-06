<?php
define('CC_REPO', str_replace('\\', '/', dirname(__DIR__)));
// CC_LIB_DIR lets Task 8 run the same tests against the OC4 copy of the library.
$cc_lib = getenv('CC_LIB_DIR');
define('CC_LIB', rtrim(str_replace('\\', '/', $cc_lib ? $cc_lib : CC_REPO . '/upload/system/library/couriercenter'), '/'));
define('CC_OC3', CC_REPO . '/upload/system/library/couriercenter/oc3');
date_default_timezone_set('Europe/Athens');
require_once __DIR__ . '/support/Assert.php';
