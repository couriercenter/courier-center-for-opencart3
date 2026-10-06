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
        return new CCVoucherService($this->db, $this->config, DB_PREFIX);
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
