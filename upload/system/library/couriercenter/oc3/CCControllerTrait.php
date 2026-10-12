<?php
namespace Opencart\Extension\Couriercenter\Oc3;

/**
 * Helpers shared by the extension's OpenCart 3 controllers (admin + catalog).
 * PHP 7.0 syntax: the PHP 7.2 library is loaded lazily (ccBoot), only after the PHP gate.
 * Uses OpenCart's $this->db / config / url / session / user / log / response / load.
 */
trait CCControllerTrait {

    /** PHP gate (spec 5.11). */
    protected function ccPhpOk() {
        require_once DIR_SYSTEM . 'library/couriercenter/oc3/CCVersion.php';
        return CCVersion::phpSupported();
    }

    protected function ccBoot() {
        require_once DIR_SYSTEM . 'library/couriercenter/oc3/bootstrap.php';
    }

    protected function ccState() {
        return new CCState($this->db, DB_PREFIX);
    }

    protected function ccService() {
        return new \Opencart\Extension\Couriercenter\Library\CCVoucherService($this->db, $this->config, DB_PREFIX);
    }

    /** Returns service with the shop's mail (customer emails) and log. */
    protected function ccReturns() {
        // Closures are bound to $this: they may call the protected mail helper.
        return new \Opencart\Extension\Couriercenter\Library\CCReturnsService($this->db, DB_PREFIX, $this->config, null,
            function ($event, $id) {
                $this->ccSendReturnMail($event, (int)$id);
            },
            function ($level, $message) {
                if ($level !== 'info') { // the shop's error log gets only problems
                    $this->log->write('Courier Center returns [' . $level . ']: ' . $message);
                }
            });
    }

    /**
     * One of the returns emails: received / created / rejected to the order's customer (the service
     * already filtered them), submitted = «Νέο αίτημα επιστροφής» to the shop. What to send is decided
     * by CCReturnsService::mailsFor(); this only delivers it.
     */
    protected function ccSendReturnMail($event, $id) {
        $svc = new \Opencart\Extension\Couriercenter\Library\CCReturnsService($this->db, DB_PREFIX, $this->config);
        $admin = $this->ccState()->get('admin_url', '');
        $links = $admin === '' ? array() : array(
            'requests' => $admin . 'index.php?route=extension/shipping/courier_center/returns',
            'order'    => $admin . 'index.php?route=sale/order/info&order_id=',
        );
        foreach ($svc->mailsFor($event, (int)$id, $links, (string)$this->config->get('config_email')) as $m) {
            $this->ccMail($m['to'], $m['subject'], $m['html'], $m['text']);
        }
    }

    /** «Η παραγγελία ολοκληρώθηκε» to the customer of an order that was completed because its shipment was delivered. */
    protected function ccSendCompletedMail($order_id) {
        $m = $this->ccService()->completedEmail((int)$order_id);
        if ($m) {
            $this->ccMail($m['to'], $m['subject'], $m['html'], $m['text']);
        }
    }

    /** Tracker of the shop's vouchers that emails the customer when a delivered order is completed. */
    protected function ccTracker() {
        $svc = $this->ccService();
        return $svc->tracker(function ($order_id) {
            $this->ccSendCompletedMail((int)$order_id);
        });
    }

    /**
     * "Cron without a cron job" (the WP-Cron of the WooCommerce plugin): when the status tracker has not run for
     * ~2 hours, fire one short non-blocking request to the cron URL. Cheap: it reads the settings OpenCart has
     * already loaded; nothing is queried and nothing is awaited. Never throws.
     */
    protected function ccPseudoCronTick() {
        try {
            if ($this->ccState()->devOverrides() && !empty($this->ccState()->devOverrides()['no_pseudo_cron'])) {
                return;
            }
            $last = (int)$this->config->get('shipping_courier_center_cron_last_run_ts');
            $fired = (int)$this->config->get('shipping_courier_center_cron_fired_at');
            $enabled = trim((string)$this->config->get('shipping_courier_center_pseudo_cron')) !== '0';
            if (!\Opencart\Extension\Couriercenter\Library\CCPseudoCron::due(time(), $last, $fired, $enabled)) {
                return;
            }
            $state = $this->ccState();
            $state->set('cron_fired_at', (string)time());
            \Opencart\Extension\Couriercenter\Library\CCPseudoCron::fire($this->ccCronUrl($state->ensureCronKey()));
        } catch (\Throwable $e) {
            // the status updates are a convenience: a failure here must never break a page
        }
    }

    /** The storefront URL of the cron endpoint (secret key). */
    protected function ccCronUrl($key) {
        if (defined('HTTP_CATALOG')) { // admin
            $base = (defined('HTTPS_CATALOG') && HTTPS_CATALOG) ? HTTPS_CATALOG : HTTP_CATALOG;
        } else {
            $base = $this->config->get('config_secure') ? $this->config->get('config_ssl') : $this->config->get('config_url');
        }
        return rtrim((string)$base, '/') . '/index.php?route=extension/shipping/courier_center/cron&key=' . $key;
    }

    /** Send with the shop's mail settings; failures go to the log, never into the page. */
    protected function ccMail($to, $subject, $html, $text) {
        CCErrorGuard::start($this->ccLogger());
        try {
            if (!CCMailer::send($this->config, $to, $subject, $html, $text, $error)) {
                $this->log->write('Courier Center mail: ' . $error);
            }
        } finally {
            CCErrorGuard::stop();
        }
    }

    /** The Pickup fields of the return forms (customer form and the admin «Νέα επιστροφή»). */
    protected function ccPickupView(array $posted, array $defaults): array {
        $dates = \Opencart\Extension\Couriercenter\Library\CCReturnsRules::pickup_dates(\Opencart\Extension\Couriercenter\Library\CCReturnsRules::now_local());
        $date = (string)($posted['pickup_date'] ?? '');
        $date_options = array();
        foreach ($dates as $d) {
            $date_options[] = array('value' => $d, 'label' => \Opencart\Extension\Couriercenter\Library\CCReturnsRules::pickup_date_label($d));
        }
        $text = function ($k, $def) use ($posted) {
            return $posted ? html_entity_decode((string)($posted[$k] ?? ''), ENT_QUOTES, 'UTF-8') : (string)$def;
        };
        return array(
            'pickup'          => \Opencart\Extension\Couriercenter\Library\CCReturnsRules::METHOD_PICKUP,
            'pickup_address'  => $text('pickup_address', $defaults['address'] ?? ''),
            'pickup_city'     => $text('pickup_city', $defaults['city'] ?? ''),
            'pickup_postcode' => $text('pickup_postcode', $defaults['postcode'] ?? ''),
            'pickup_dates'    => $date_options,
            'pickup_date'     => in_array($date, $dates, true) ? $date : $dates[0],
            'pickup_from'     => (string)($posted['pickup_time_from'] ?? ''),
            'pickup_to'       => (string)($posted['pickup_time_to'] ?? ''),
            'hours_from'      => \Opencart\Extension\Couriercenter\Library\CCReturnsRules::pickup_hours_from(),
            'hours_to'        => \Opencart\Extension\Couriercenter\Library\CCReturnsRules::pickup_hours_to(),
            'min_hours'       => \Opencart\Extension\Couriercenter\Library\CCReturnsRules::PICKUP_MIN_HOURS,
            'courier_notes'   => $text('courier_notes', ''),
            'notes_max'       => \Opencart\Extension\Couriercenter\Library\CCReturnsRules::COURIER_NOTES_MAX,
            'msg_pickup_half'   => \Opencart\Extension\Couriercenter\Library\CCReturnsRules::MSG_PICKUP_HALF,
            'msg_pickup_window' => \Opencart\Extension\Couriercenter\Library\CCReturnsRules::MSG_PICKUP_WINDOW,
        );
    }

    /** Remember the admin base URL (admin pages only): the storefront needs it for the links of the shop email. */
    protected function ccRememberAdminUrl() {
        if (!defined('HTTP_CATALOG')) {
            return;
        }
        $url = (defined('HTTPS_SERVER') && HTTPS_SERVER) ? HTTPS_SERVER : HTTP_SERVER;
        $state = $this->ccState();
        if ($url !== '' && $state->get('admin_url', '') !== $url) {
            $state->set('admin_url', $url);
        }
    }

    /** Request row for the views (list page + order tab). */
    protected function ccReturnRow(array $r) {
        $items = array();
        foreach ($r['items'] as $line) {
            $items[] = (string)($line['name'] ?? '') . ' × ' . (int)($line['qty'] ?? 0);
        }
        $st = $r['status'];
        return array(
            'id'            => (int)$r['id'],
            'order_id'      => (int)$r['order_id'],
            'status'        => $st,
            'status_label'  => \Opencart\Extension\Couriercenter\Library\CCReturnsRules::status_label($st),
            'method_label'  => \Opencart\Extension\Couriercenter\Library\CCReturnsRules::method_label($r['method']),
            'from_shop'     => ($r['origin'] ?? '') === 'shop',
            'pickup_label'  => $r['method'] === \Opencart\Extension\Couriercenter\Library\CCReturnsRules::METHOD_PICKUP ? \Opencart\Extension\Couriercenter\Library\CCReturnsService::pickupLabel($r) : '',
            'pickup_address' => $r['method'] === \Opencart\Extension\Couriercenter\Library\CCReturnsRules::METHOD_PICKUP ? \Opencart\Extension\Couriercenter\Library\CCReturnsRules::pickup_address($r['shipper_details']) : '',
            'courier_notes' => (string)($r['shipper_details']['courier_notes'] ?? ''),
            'is_boxnow'     => $r['method'] === \Opencart\Extension\Couriercenter\Library\CCReturnsRules::METHOD_BOXNOW,
            'locker_label'  => isset(\Opencart\Extension\Couriercenter\Library\CCReturnsRules::LOCKER_SIZES[$r['locker_size']]) ? \Opencart\Extension\Couriercenter\Library\CCReturnsRules::LOCKER_SIZES[$r['locker_size']]['label'] : '',
            'items'         => implode(', ', $items),
            'reason'        => (string)$r['reason'],
            'original_awb'  => (string)$r['original_awb'],
            'return_awb'    => (string)$r['return_awb'],
            'rsn'           => (string)$r['return_shipment_number'],
            'tracking'      => $r['display_status'] !== '' && $r['display_status'] !== 'None' ? (string)$r['display_status'] : '',
            'accounts'      => trim((string)$r['original_shipper_code'] . ($r['return_account_code'] !== '' && $r['return_account_code'] !== $r['original_shipper_code'] ? ' → ' . $r['return_account_code'] : '')),
            'last_error'    => (string)$r['last_error'],
            'created_at'    => (string)$r['created_at'],
            'can_approve'   => $st === \Opencart\Extension\Couriercenter\Library\CCReturnsRules::ST_PENDING,
            'can_reject'    => in_array($st, array(\Opencart\Extension\Couriercenter\Library\CCReturnsRules::ST_PENDING, \Opencart\Extension\Couriercenter\Library\CCReturnsRules::ST_APPROVED, \Opencart\Extension\Couriercenter\Library\CCReturnsRules::ST_FAILED), true),
            'can_create'    => in_array($st, array(\Opencart\Extension\Couriercenter\Library\CCReturnsRules::ST_APPROVED, \Opencart\Extension\Couriercenter\Library\CCReturnsRules::ST_FAILED), true),
            'can_verify'    => $st === \Opencart\Extension\Couriercenter\Library\CCReturnsRules::ST_VERIFY,
            'can_track'     => $st === \Opencart\Extension\Couriercenter\Library\CCReturnsRules::ST_CREATED,
            'print_url'     => $st === \Opencart\Extension\Couriercenter\Library\CCReturnsRules::ST_CREATED ? $this->ccLink('extension/shipping/courier_center/returns' . '/voucher', 'id=' . (int)$r['id']) : '',
        );
    }

    protected function ccReturnActionUrls() {
        $urls = array();
        foreach (array('approve', 'reject', 'create', 'verify', 'track') as $a) {
            $urls[$a] = $this->ccLink('extension/shipping/courier_center/returns' . '/' . $a);
        }
        return $urls;
    }

    protected function ccCanModify() {
        return $this->user->hasPermission('modify', 'extension/shipping/courier_center');
    }

    /** Admin URL with user_token and plain '&' (templates escape it: |e or |json_encode). */
    protected function ccLink($route, $args = '') {
        $token = isset($this->session->data['user_token']) ? $this->session->data['user_token'] : '';
        $args = 'user_token=' . $token . ($args !== '' ? '&' . ltrim($args, '&') : '');
        return str_replace('&amp;', '&', $this->url->link($route, $args, true));
    }

    /** Storefront URL with plain '&'. */
    protected function ccCatalogLink($route, $args = '') {
        return str_replace('&amp;', '&', $this->url->link($route, $args, true));
    }

    /** OpenCart stores form input HTML-escaped: decode before using it as text (spec 10 #13). */
    protected function ccText($value) {
        return html_entity_decode((string)$value, ENT_QUOTES, 'UTF-8');
    }

    protected function ccLogger() {
        $log = $this->log;
        return function ($line) use ($log) {
            $log->write($line);
        };
    }

    /** Start a JSON endpoint. Returns false (after answering) when PHP is too old. */
    protected function ccJsonStart() {
        $this->response->addHeader('Content-Type: application/json');
        if (!$this->ccPhpOk()) {
            $this->response->setOutput(json_encode(array('success' => false, 'error' => 'Το Courier Center χρειάζεται PHP 7.2 ή νεότερη.')));
            return false;
        }
        $this->ccBoot();
        CCErrorGuard::start($this->ccLogger());
        CCErrorGuard::jsonOnFatal();
        return true;
    }

    protected function ccJson(array $data) {
        $this->response->setOutput(json_encode($data));
        CCErrorGuard::stop();
    }

    protected function ccDeny() {
        $this->ccJson(array('success' => false, 'error' => 'Δεν έχετε δικαίωμα τροποποίησης του Courier Center.'));
    }

    protected function ccSiteUrl() {
        $url = (string)$this->config->get('config_url');
        if ($url === '' && defined('HTTP_CATALOG')) {
            $url = HTTP_CATALOG;
        }
        return $url;
    }

    /** Idempotent install / repair (spec 5.9). @return string[] what was done */
    protected function ccRepair() {
        $done = CCSchema::ensure($this->db, DB_PREFIX);
        $done = array_merge($done, CCSchema::ensureEvents($this->db, DB_PREFIX));
        $state = $this->ccState();
        $state->ensureCronKey();
        $state->set('installed_version', CCVersion::get());
        $state->set('installed_build', CCSchema::eventsSignature());
        $done[] = 'version ' . CCVersion::get();
        return $done;
    }

    /** Plain-text error, then exit (download endpoints). */
    protected function ccTextError($status, $message) {
        CCErrorGuard::cleanBuffers();
        if (!headers_sent()) {
            http_response_code((int)$status);
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo $message;
        exit;
    }

    /** Send a PDF, then exit (bypasses OpenCart's output compression, which would break Content-Length). */
    protected function ccSendPdf($pdf, $filename, $disposition = 'inline') {
        CCErrorGuard::cleanBuffers();
        $filename = preg_replace('/[^A-Za-z0-9_.-]/', '', (string)$filename);
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . ($disposition === 'attachment' ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf));
        header('Cache-Control: no-cache');
        echo $pdf;
        exit;
    }
}
