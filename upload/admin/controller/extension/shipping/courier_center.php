<?php
/**
 * Courier Center - OpenCart 3 shipping extension (admin): settings page, install /
 * uninstall / repair and the order AJAX endpoints (Task 13).
 * Port of admin/controller/shipping/courier_center.php of the OpenCart 4 extension.
 * PHP 7.0 syntax: the PHP gate must be able to show its message on old hosts.
 */
require_once DIR_SYSTEM . 'library/couriercenter/oc3/CCControllerTrait.php';

use Opencart\Extension\Couriercenter\Library\CCApi;
use Opencart\Extension\Couriercenter\Oc3\CCSchema;
use Opencart\Extension\Couriercenter\Oc3\CCState;
use Opencart\Extension\Couriercenter\Oc3\CCTelemetry;
use Opencart\Extension\Couriercenter\Oc3\CCVersion;

class ControllerExtensionShippingCourierCenter extends Controller {
    use \Opencart\Extension\Couriercenter\Oc3\CCControllerTrait;

    const ROUTE = 'extension/shipping/courier_center';

    private $error = array();
    private $prefix = 'shipping_courier_center_';

    // ── Settings page ──────────────────────────────────────────────────────
    public function index() {
        $this->load->language(self::ROUTE);
        $this->document->setTitle($this->language->get('heading_title'));
        if (!$this->ccPhpOk()) {
            $this->renderPhpGate();
            return;
        }
        $this->ccBoot();
        $this->load->model('setting/setting');

        if ($this->request->server['REQUEST_METHOD'] == 'POST' && $this->validate()) {
            $this->model_setting_setting->editSetting('shipping_courier_center', $this->request->post);
            $this->session->data['success'] = $this->language->get('text_success');
            $this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=shipping', true));
        }

        $state = $this->ccState();
        CCTelemetry::ping($state, $this->ccSiteUrl());

        $data = array();
        $data['error_warning'] = isset($this->error['warning']) ? $this->error['warning'] : '';
        $data['breadcrumbs'] = array(
            array('text' => $this->language->get('text_home'), 'href' => $this->ccLink('common/dashboard')),
            array('text' => $this->language->get('text_extension'), 'href' => $this->ccLink('marketplace/extension', 'type=shipping')),
            array('text' => $this->language->get('heading_title'), 'href' => $this->ccLink(self::ROUTE)),
        );

        $fields = array(
            'status', 'user_alias', 'credential_value', 'api_key', 'billing_account',
            'shipper_name', 'shipper_address', 'shipper_postcode', 'shipper_city', 'shipper_phone',
            'tracking_url', 'email_tracking_enabled',
            'boxnow_enabled', 'boxnow_default_selected', 'print_template', 'print_template_boxnow', 'auto_complete_status_id',
            'auto_create_enabled', 'auto_create_status_id',
            'cost', 'cod_fee', 'geo_zone_id', 'tax_class_id', 'sort_order',
        );
        foreach ($fields as $field) {
            $key = $this->prefix . $field;
            $value = isset($this->request->post[$key]) ? $this->request->post[$key] : $this->config->get($key);
            // Stored HTML-escaped by OpenCart; decoded here, escaped once by the template (|e).
            $data[$field] = $value === null ? '' : $this->ccText($value);
        }
        if ($data['tracking_url'] === '') {
            $data['tracking_url'] = 'https://www.courier.gr/track/result?tracknr={{tracking}}';
        }
        if ($data['email_tracking_enabled'] === '') {
            $data['email_tracking_enabled'] = '1';
        }
        if ($data['print_template'] === '') {
            $data['print_template'] = 'pdf';
        }
        if ($data['print_template_boxnow'] === '') {
            $data['print_template_boxnow'] = 'singlepdf_100x150_4up';
        }
        foreach (array('auto_complete_status_id', 'auto_create_enabled', 'auto_create_status_id', 'cost', 'cod_fee', 'sort_order') as $field) {
            if ($data[$field] === '') {
                $data[$field] = '0';
            }
        }

        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();
        $this->load->model('localisation/geo_zone');
        $data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();
        $this->load->model('localisation/tax_class');
        $data['tax_classes'] = $this->model_localisation_tax_class->getTaxClasses();

        $data['shipping_methods_list'] = $this->shippingMethodsList();
        $bn = $this->config->get($this->prefix . 'boxnow_shipping_methods');
        $data['boxnow_shipping_methods'] = (is_array($bn) && $bn) ? $bn : array('courier_center');
        $hm = $this->config->get($this->prefix . 'handled_shipping_methods');
        $data['handled_shipping_methods'] = is_array($hm) ? $hm : array();

        $data['version'] = CCVersion::get();
        $data['cron_last_run'] = $state->get('cron_last_run', '') ?: '—';
        $data['cron_next_run'] = $state->get('cron_next_run', '') ?: '—';
        $data['cron_cli'] = 'php ' . str_replace('\\', '/', DIR_SYSTEM) . 'library/couriercenter/cron_status_tracker.php';
        $data['cron_url'] = $this->cronUrl($state->ensureCronKey());

        $data['action'] = $this->ccLink(self::ROUTE);
        $data['cancel'] = $this->ccLink('marketplace/extension', 'type=shipping');
        foreach (array('autofill', 'clearSettings', 'repair', 'regenerateCronKey') as $method) {
            $data['url_' . $method] = $this->ccLink(self::ROUTE . '/' . $method);
        }
        $data['url_manifest'] = $this->ccLink(self::ROUTE . '/manifest');
        $data['url_bug_report'] = $this->ccLink(self::ROUTE . '/bug');
        $data['url_docs'] = $this->ccLink(self::ROUTE . '/docs');
        $data['url_update_check'] = $this->ccLink(self::ROUTE . '/update/check');
        $data['url_update_apply'] = $this->ccLink(self::ROUTE . '/update/apply');

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');
        $this->response->setOutput($this->load->view(self::ROUTE, $data));
    }

    // ── Install / uninstall / repair ───────────────────────────────────────
    public function install() {
        if (!$this->ccPhpOk()) {
            return; // nothing registered; the settings page explains the PHP requirement
        }
        $this->ccBoot();
        $this->ccRepair();
        CCTelemetry::ping($this->ccState(), $this->ccSiteUrl(), true);
    }

    public function uninstall() {
        if (!$this->ccPhpOk()) {
            return;
        }
        $this->ccBoot();
        CCSchema::removeEvents($this->db, DB_PREFIX);
        // cc_shipments and the courier_center_state rows stay on purpose (spec 8 #1): voucher history + cron key.
    }

    public function repair() {
        if (!$this->ccJsonStart()) {
            return;
        }
        if (!$this->ccCanModify()) {
            $this->ccDeny();
            return;
        }
        $this->ccJson(array('success' => true, 'done' => $this->ccRepair()));
    }

    // ── Settings tools ─────────────────────────────────────────────────────
    /** Test shipment -> read the account's sender data -> void (spec 10 #4: only these keys are written). */
    public function autofill() {
        if (!$this->ccJsonStart()) {
            return;
        }
        if (!$this->ccCanModify()) {
            $this->ccDeny();
            return;
        }
        $creds = array();
        foreach (array('user_alias', 'credential_value', 'api_key', 'billing_account') as $k) {
            $v = trim($this->ccText(isset($this->request->post[$k]) ? $this->request->post[$k] : ''));
            $creds[$k] = $v !== '' ? $v : trim($this->ccText($this->config->get($this->prefix . $k)));
        }
        if (in_array('', $creds, true)) {
            $this->ccJson(array('success' => false, 'error' => 'Συμπλήρωσε πρώτα τα API credentials'));
            return;
        }

        $api = new CCApi($creds['user_alias'], $creds['credential_value'], $creds['api_key'], $creds['billing_account']);
        $create = $api->create_shipment($this->autofillPayload($creds));
        if (isset($create['success']) && $create['success'] === false) {
            $this->ccJson(array('success' => false, 'error' => 'Βήμα 1 — ' . (isset($create['error']) ? $create['error'] : 'Αποτυχία δημιουργίας test αποστολής')));
            return;
        }
        $awb = (string)($create['ShipmentNumber'] ?? $create['VoucherNumber'] ?? $create['AwbNumber'] ?? '');
        if ($awb === '') {
            $this->ccJson(array('success' => false, 'error' => 'Το API δεν επέστρεψε αριθμό για την test αποστολή.'));
            return;
        }
        $details = $api->get_shipment_details($awb);
        $shipper = $details['ShipmentDetails'][0]['ShipmentInfo']['Shipper'] ?? array();
        $found = array(
            'shipper_name'     => trim((string)($shipper['CompanyName'] ?? '')),
            'shipper_address'  => trim((string)($shipper['Address'] ?? '')),
            'shipper_postcode' => trim((string)($shipper['ZipCode'] ?? '')),
            'shipper_city'     => trim((string)($shipper['City'] ?? '')),
            'shipper_phone'    => trim((string)($shipper['Phones'] ?? '')),
        );
        $voided = $api->void_shipment($awb) === true;

        foreach ($creds as $k => $v) {
            CCState::upsertSetting($this->db, DB_PREFIX, 'shipping_courier_center', $this->prefix . $k, $v);
        }
        foreach ($found as $k => $v) {
            if ($v !== '') {
                CCState::upsertSetting($this->db, DB_PREFIX, 'shipping_courier_center', $this->prefix . $k, $v);
            }
        }
        if (implode('', $found) === '') {
            $this->ccJson(array('success' => false, 'error' => 'Τα credentials δουλεύουν, αλλά το API δεν επέστρεψε στοιχεία αποστολέα. Συμπλήρωσέ τα χειροκίνητα.'));
            return;
        }
        $out = array('success' => true) + $found;
        if (!$voided) {
            $out['warning'] = 'Η test αποστολή ' . $awb . ' δεν ακυρώθηκε αυτόματα. Ακύρωσέ την από το Courier Center.';
        }
        $this->ccJson($out);
    }

    public function clearSettings() {
        if (!$this->ccJsonStart()) {
            return;
        }
        if (!$this->ccCanModify()) {
            $this->ccDeny();
            return;
        }
        $this->load->model('setting/setting');
        $this->model_setting_setting->deleteSetting('shipping_courier_center');
        $this->ccJson(array('success' => true));
    }

    public function regenerateCronKey() {
        if (!$this->ccJsonStart()) {
            return;
        }
        if (!$this->ccCanModify()) {
            $this->ccDeny();
            return;
        }
        $state = $this->ccState();
        $state->delete('cron_key');
        $this->ccJson(array('success' => true, 'cron_url' => $this->cronUrl($state->ensureCronKey())));
    }

    // ── Helpers ────────────────────────────────────────────────────────────
    private function validate() {
        if (!$this->user->hasPermission('modify', self::ROUTE)) {
            $this->error['warning'] = $this->language->get('error_permission');
        }
        return !$this->error;
    }

    private function renderPhpGate() {
        $data = array(
            'message'     => sprintf($this->language->get('error_php_version'), '7.2', PHP_VERSION),
            'header'      => $this->load->controller('common/header'),
            'column_left' => $this->load->controller('common/column_left'),
            'footer'      => $this->load->controller('common/footer'),
        );
        $this->response->setOutput($this->load->view('extension/shipping/courier_center/php_gate', $data));
    }

    private function cronUrl($key) {
        $base = (defined('HTTPS_CATALOG') && HTTPS_CATALOG) ? HTTPS_CATALOG : HTTP_CATALOG;
        return rtrim($base, '/') . '/index.php?route=extension/shipping/courier_center/cron&key=' . $key;
    }

    /** Installed + enabled shipping extensions, with their own names. */
    private function shippingMethodsList() {
        $this->load->model('setting/extension');
        $list = array();
        foreach ($this->model_setting_extension->getInstalled('shipping') as $code) {
            if (!$this->config->get('shipping_' . $code . '_status')) {
                continue;
            }
            $name = ucwords(str_replace('_', ' ', $code));
            if (is_file(DIR_LANGUAGE . 'en-gb/extension/shipping/' . $code . '.php')) {
                $this->load->language('extension/shipping/' . $code, 'cc_ext_' . $code);
                $title = trim(strip_tags($this->ccText($this->language->get('cc_ext_' . $code)->get('heading_title'))));
                if ($title !== '' && $title !== 'heading_title') {
                    $name = $title;
                }
            }
            $list[] = array('code' => $code, 'name' => $name);
        }
        return $list;
    }

    private function autofillPayload(array $c) {
        return array(
            'Context'      => array('UserAlias' => $c['user_alias'], 'CredentialValue' => $c['credential_value'], 'ApiKey' => $c['api_key']),
            'shipmentDate' => date('Y-m-d'),
            'comments'     => 'TEST AUTOFILL - DELETE',
            'Requestor'    => array('CarrierBillingAccount' => $c['billing_account']),
            'Shipper'      => array('CarrierBillingAccount' => $c['billing_account'], 'CompanyName' => 'TEST', 'Address' => 'TEST',
                'ZipCode' => '10431', 'City' => 'ΑΘΗΝΑ', 'Phones' => '2101234567', 'Country' => 'GREECE', 'CountryCode' => 'GR'),
            'Consignee'    => array('CompanyName' => 'TEST', 'ContactName' => 'TEST', 'Address' => 'ΑΘΗΝΑ', 'City' => 'ΑΘΗΝΑ',
                'Area' => 'ΑΘΗΝΑ', 'ZipCode' => '10431', 'Country' => 'GR', 'Mobile1' => '2101234567'),
            'BillTo'       => 'Requestor',
            'BasicService' => '211',
            'Reference1'   => 'TEST-AUTOFILL',
            'Items'        => array(array('GoodsType' => 'NoDocs', 'Content' => 'TEST', 'IsDangerousGoods' => false,
                'IsDryIce' => false, 'IsFragile' => false, 'Weight' => array('Unit' => 'kg', 'Value' => 1.0))),
        );
    }
}
