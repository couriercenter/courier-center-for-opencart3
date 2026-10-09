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

    /** One of the returns emails (received / created / rejected) to the order's customer. */
    protected function ccSendReturnMail($event, $id) {
        $svc = new \Opencart\Extension\Couriercenter\Library\CCReturnsService($this->db, DB_PREFIX, $this->config);
        $row = $svc->repo()->get((int)$id);
        if (!$row) {
            return;
        }
        $order = $svc->getOrder((int)$row['order_id']);
        $to = trim((string)($order['email'] ?? ''));
        if ($to === '') {
            return;
        }
        $instructions = $row['method'] === \Opencart\Extension\Couriercenter\Library\CCReturnsRules::METHOD_BOXNOW
            ? $svc->settings()->boxnowInstructions() : $svc->settings()->dropoffInstructions();
        $m = \Opencart\Extension\Couriercenter\Library\CCMailContent::returnEmail($event, $row, $order, $instructions);
        if (!$m) {
            return;
        }
        $this->ccMail($to, $m['subject'], $m['html'], $m['text']);
    }

    /** Send with the shop's mail settings; failures go to the log, never into the page. */
    protected function ccMail($to, $subject, $html, $text) {
        CCErrorGuard::start($this->ccLogger());
        try {
            $engine = (string)$this->config->get('config_mail_engine');
            $mail = new \Mail($engine !== '' ? $engine : 'mail');
            $mail->parameter = $this->config->get('config_mail_parameter');
            $mail->smtp_hostname = $this->config->get('config_mail_smtp_hostname');
            $mail->smtp_username = $this->config->get('config_mail_smtp_username');
            $mail->smtp_password = html_entity_decode((string)$this->config->get('config_mail_smtp_password'), ENT_QUOTES, 'UTF-8');
            $mail->smtp_port = $this->config->get('config_mail_smtp_port');
            $mail->smtp_timeout = $this->config->get('config_mail_smtp_timeout');
            $mail->setTo($to);
            $mail->setFrom($this->config->get('config_email'));
            $mail->setSender(html_entity_decode((string)$this->config->get('config_name'), ENT_QUOTES, 'UTF-8'));
            $mail->setSubject($subject);
            $mail->setHtml($html);
            $mail->setText($text);
            $mail->send();
        } catch (\Throwable $e) {
            $this->log->write('Courier Center mail: ' . $e->getMessage());
        } finally {
            CCErrorGuard::stop();
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
