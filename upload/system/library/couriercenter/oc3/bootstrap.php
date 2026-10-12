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
require_once $cc_dir . 'CCPickupPoints.php';
require_once $cc_dir . 'CCLock.php';
require_once $cc_dir . 'CCDbSchema.php';
require_once $cc_dir . 'CCVoucherService.php';
require_once $cc_dir . 'CCAutofill.php';
require_once $cc_dir . 'CCMailContent.php';
require_once $cc_dir . 'CCReturnsRules.php';
require_once $cc_dir . 'CCReturnsSettings.php';
require_once $cc_dir . 'CCReturnsRepo.php';
require_once $cc_dir . 'CCReturnPayload.php';
require_once $cc_dir . 'CCReturnsService.php';
require_once $cc_dir . 'CCReturnsTracker.php';
require_once $cc_dir . 'CCPseudoCron.php';
require_once __DIR__ . '/CCVersion.php';
require_once __DIR__ . '/CCState.php';
require_once __DIR__ . '/CCErrorGuard.php';
require_once __DIR__ . '/CCConfigSnapshot.php';
require_once __DIR__ . '/CCSchema.php';
require_once __DIR__ . '/CCTelemetry.php';
require_once __DIR__ . '/CCMailer.php';
require_once __DIR__ . '/CCUpdater.php';
require_once __DIR__ . '/CCBoxnow.php';
require_once __DIR__ . '/CCCronRunner.php';
unset($cc_dir);
