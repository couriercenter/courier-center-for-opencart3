<?php
/**
 * Loads the Courier Center library + OpenCart 3 helpers. Call only after
 * CCVersion::phpSupported() (the library needs PHP 7.2+). Safe to include many times.
 */
$cc_dir = dirname(__DIR__) . '/';
require_once $cc_dir . 'CCApi.php';
require_once $cc_dir . 'CCCityScope.php';
require_once $cc_dir . 'CCOrderAdapter.php';
require_once $cc_dir . 'CCShipmentBuilder.php';
require_once $cc_dir . 'CCPdfScaler.php';
require_once $cc_dir . 'CCOrderHistory.php';
require_once $cc_dir . 'CCStatusTracker.php';
require_once __DIR__ . '/CCVersion.php';
require_once __DIR__ . '/CCState.php';
require_once __DIR__ . '/CCErrorGuard.php';
require_once __DIR__ . '/CCConfigSnapshot.php';
require_once __DIR__ . '/CCSchema.php';
unset($cc_dir);
