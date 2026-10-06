<?php
namespace Opencart\Extension\Couriercenter\Oc3;

/** Usage ping + bug reports to the Courier Center dashboard (same endpoints as WooCommerce / OC4). */
class CCTelemetry {
    const DASHBOARD = 'https://courier-center-dashboard.onrender.com';
    const PING_INTERVAL = 43200;

    /** Dashboard base URL, or the local sink of a test shop (state dev_overrides). */
    public static function base(CCState $state) {
        $o = $state->devOverrides();
        return !empty($o['dashboard_base']) ? rtrim((string)$o['dashboard_base'], '/') : self::DASHBOARD;
    }

    public static function payload($site_url) {
        return array(
            'site_url'         => (string)$site_url,
            'platform'         => 'OpenCart',
            'opencart_version' => defined('VERSION') ? VERSION : 'N/A',
            'plugin_version'   => CCVersion::get(),
            'php_version'      => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
        );
    }

    /** Fire-and-forget usage ping, throttled to once / 12h. Never throws. */
    public static function ping(CCState $state, $site_url, $force = false) {
        try {
            $last = (int)$state->get('ping_sent_at', '0');
            if (!$force && $last > 0 && (time() - $last) < self::PING_INTERVAL) {
                return;
            }
            self::post(self::base($state) . '/api/ping', self::payload($site_url), 5);
            $state->set('ping_sent_at', (string)time());
        } catch (\Throwable $e) {
            // Telemetry must never break anything.
        }
    }

    /**
     * Bug report from the admin form. @param array $f title, description, severity, email (decoded text)
     * @return array ['success' => bool, 'error' => string]
     */
    public static function report(CCState $state, $site_url, array $f) {
        $payload = self::payload($site_url);
        $payload['title'] = (string)$f['title'];
        $payload['description'] = (string)$f['description'];
        $payload['severity'] = (string)$f['severity'];
        $payload['reporter_email'] = (string)$f['email'];
        list($code, $body, $err) = self::post(self::base($state) . '/api/report', $payload, 15);
        if ($err !== '') {
            return array('success' => false, 'error' => 'Σφάλμα σύνδεσης: ' . $err);
        }
        if ($code === 200 || $code === 201) {
            return array('success' => true, 'error' => '');
        }
        return array('success' => false, 'error' => 'Απόκριση dashboard: HTTP ' . $code . ' — ' . substr($body, 0, 200));
    }

    /** @return array [int http_code, string body, string curl_error] */
    public static function post($url, array $payload, $timeout) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
            CURLOPT_TIMEOUT        => (int)$timeout,
        ));
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = (string)curl_error($ch);
        if (PHP_VERSION_ID < 80000) { curl_close($ch); }
        return array($code, is_string($body) ? $body : '', $err);
    }
}
