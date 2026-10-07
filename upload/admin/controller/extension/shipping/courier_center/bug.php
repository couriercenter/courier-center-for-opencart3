<?php
/** Bug report to the Courier Center dashboard (same endpoint as WooCommerce / OC4). */
require_once DIR_SYSTEM . 'library/couriercenter/oc3/CCControllerTrait.php';

use Opencart\Extension\Couriercenter\Oc3\CCTelemetry;
use Opencart\Extension\Couriercenter\Oc3\CCVersion;

class ControllerExtensionShippingCourierCenterBug extends Controller {
    use \Opencart\Extension\Couriercenter\Oc3\CCControllerTrait;

    public function index() {
        if (!$this->ccPhpOk()) {
            $this->response->redirect($this->url->link('extension/shipping/courier_center', 'user_token=' . $this->session->data['user_token'], true));
            return;
        }
        $this->ccBoot();
        $this->document->setTitle('Αναφορά Προβλήματος — Courier Center');
        $data = array(
            'site_url'         => $this->ccSiteUrl(),
            'plugin_version'   => CCVersion::get(),
            'opencart_version' => defined('VERSION') ? VERSION : 'N/A',
            'php_version'      => PHP_VERSION,
            'user_email'       => '',
            'url_submit'       => $this->ccLink('extension/shipping/courier_center/bug/submit'),
            'url_settings'     => $this->ccLink('extension/shipping/courier_center'),
        );
        $uid = (int)$this->user->getId();
        if ($uid > 0) {
            $q = $this->db->query("SELECT `email` FROM `" . DB_PREFIX . "user` WHERE `user_id` = '" . $uid . "' LIMIT 1");
            if ($q->num_rows) {
                $data['user_email'] = $this->ccText($q->row['email']);
            }
        }
        $data['breadcrumbs'] = array(
            array('text' => 'Αρχική', 'href' => $this->ccLink('common/dashboard')),
            array('text' => 'Courier Center', 'href' => $data['url_settings']),
            array('text' => 'Αναφορά Προβλήματος', 'href' => $this->ccLink('extension/shipping/courier_center/bug')),
        );
        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');
        $this->response->setOutput($this->load->view('extension/shipping/courier_center/bug_report', $data));
    }

    public function submit() {
        if (!$this->ccJsonStart()) {
            return;
        }
        if (!$this->ccCanModify()) {
            $this->ccDeny();
            return;
        }
        $f = array();
        foreach (array('title', 'description', 'email') as $k) {
            $f[$k] = trim($this->ccText($this->request->post[$k] ?? ''));
        }
        $severity = (string)($this->request->post['severity'] ?? 'low');
        $f['severity'] = in_array($severity, array('low', 'medium', 'critical'), true) ? $severity : 'low';
        if ($f['title'] === '' || $f['description'] === '' || $f['email'] === '') {
            $this->ccJson(array('success' => false, 'error' => 'Συμπλήρωσε τίτλο, περιγραφή και email επικοινωνίας.'));
            return;
        }
        if (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) {
            $this->ccJson(array('success' => false, 'error' => 'Μη έγκυρο email επικοινωνίας.'));
            return;
        }
        $this->ccJson(CCTelemetry::report($this->ccState(), $this->ccSiteUrl(), $f));
    }
}
