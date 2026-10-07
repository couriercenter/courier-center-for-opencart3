<?php
/**
 * Courier Center - shipment status tracker (CLI). Every 2 hours, e.g. cPanel > Cron Jobs:
 *   0 *\/2 * * *  php /home/USER/public_html/system/library/couriercenter/cron_status_tracker.php
 * The same job can run from a URL with a secret key (see the extension settings page).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}
// system/library/couriercenter -> shop root, where the storefront config.php has the DB settings.
$root = dirname(__DIR__, 3);
if (!is_file($root . '/config.php')) {
    fwrite(STDERR, "ERROR: config.php not found in $root\n");
    exit(1);
}
require $root . '/config.php';
require_once __DIR__ . '/oc3/CCVersion.php';
if (!\Opencart\Extension\Couriercenter\Oc3\CCVersion::phpSupported()) {
    fwrite(STDERR, 'ERROR: Courier Center needs PHP 7.2+ (this is PHP ' . PHP_VERSION . ")\n");
    exit(1);
}
require_once __DIR__ . '/oc3/bootstrap.php';
// OpenCart's own DB class: same driver and session sql_mode as the shop.
require_once DIR_SYSTEM . 'library/db.php';
require_once DIR_SYSTEM . 'library/db/' . DB_DRIVER . '.php';
$db = new \DB(DB_DRIVER, DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, DB_PORT);

\Opencart\Extension\Couriercenter\Oc3\CCErrorGuard::start(function ($line) {
    fwrite(STDERR, $line . "\n");
});
$config = new \Opencart\Extension\Couriercenter\Oc3\CCConfigSnapshot($db, DB_PREFIX);
$site = (defined('HTTPS_SERVER') && HTTPS_SERVER) ? HTTPS_SERVER : (defined('HTTP_SERVER') ? HTTP_SERVER : '');
$result = \Opencart\Extension\Couriercenter\Oc3\CCCronRunner::run($db, DB_PREFIX, $config, DIR_CACHE, $site);
foreach ($result['lines'] as $line) {
    echo '  ' . $line . "\n";
}
echo '[' . date('Y-m-d H:i:s') . '] ' . $result['message'] . "\n";
exit(0);
