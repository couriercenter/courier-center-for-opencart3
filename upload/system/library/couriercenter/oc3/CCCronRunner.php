<?php
namespace Opencart\Extension\Couriercenter\Oc3;

use Opencart\Extension\Couriercenter\Library\CCApi;
use Opencart\Extension\Couriercenter\Library\CCStatusTracker;

/** One cron pass, shared by the CLI script and the URL endpoint (spec 5.8). */
class CCCronRunner {
    const LOCK_TTL = 1800;

    /**
     * @param object      $config   OpenCart Config or CCConfigSnapshot (get())
     * @param string      $lock_dir writable folder (DIR_CACHE)
     * @param object|null $tracker  tests pass a stand-in with run(); default: CCStatusTracker with the shop's credentials
     * @param bool        $ping     usage ping (tests pass false)
     * @return array ['ran' => bool, 'message' => string, 'lines' => string[]]
     */
    public static function run($db, $prefix, $config, $lock_dir, $site_url, $tracker = null, $ping = true) {
        $lock = rtrim(str_replace('\\', '/', $lock_dir), '/') . '/cc_cron.lock';
        if (is_file($lock) && (time() - (int)@filemtime($lock)) < self::LOCK_TTL) {
            return array('ran' => false, 'message' => 'Another run is in progress (' . basename($lock) . ')', 'lines' => array());
        }
        @file_put_contents($lock, (string)time());
        try {
            if ($tracker === null) {
                $text = function ($key) use ($config) {
                    return html_entity_decode((string)$config->get('shipping_courier_center_' . $key), ENT_QUOTES, 'UTF-8');
                };
                $api = new CCApi($text('user_alias'), $text('credential_value'), $text('api_key'), $text('billing_account'));
                $tracker = new CCStatusTracker($db, $prefix, $api, (int)$config->get('shipping_courier_center_auto_complete_status_id'));
            }
            $report = $tracker->run();
            $state = new CCState($db, $prefix);
            $state->set('cron_last_run', date('Y-m-d H:i:s'));
            $state->set('cron_next_run', date('Y-m-d H:i:s', time() + 7200));
            if ($ping) {
                CCTelemetry::ping($state, $site_url);
            }
            return array(
                'ran'     => true,
                'message' => sprintf('checked=%d, updated=%d, skipped=%d, failed=%d', $report['checked'] ?? 0, $report['updated'] ?? 0, $report['skipped'] ?? 0, $report['failed'] ?? 0),
                'lines'   => isset($report['lines']) ? $report['lines'] : array(),
            );
        } finally {
            @unlink($lock);
        }
    }

    public static function keyMatches($expected, $given) {
        return is_string($given) && preg_match('/^[a-f0-9]{32}$/', (string)$expected) === 1 && hash_equals((string)$expected, $given);
    }
}
