<?php
/**
 * Courier Center - OpenCart 3 shipping extension (admin): settings page, install /
 * uninstall / repair and the order AJAX endpoints (Task 13).
 * Port of admin/controller/shipping/courier_center.php of the OpenCart 4 extension.
 * PHP 7.0 syntax: the PHP gate must be able to show its message on old hosts.
 */
require_once DIR_SYSTEM . 'library/couriercenter/oc3/CCControllerTrait.php';

use Opencart\Extension\Couriercenter\Library\CCApi;
use Opencart\Extension\Couriercenter\Library\CCAutofill;
use Opencart\Extension\Couriercenter\Library\CCDbSchema;
use Opencart\Extension\Couriercenter\Library\CCPdfScaler;
use Opencart\Extension\Couriercenter\Library\CCPickupPoints;
use Opencart\Extension\Couriercenter\Library\CCReturnsRules;
use Opencart\Extension\Couriercenter\Library\CCReturnsSettings;
use Opencart\Extension\Couriercenter\Library\CCStatusTracker;
use Opencart\Extension\Couriercenter\Oc3\CCErrorGuard;
use Opencart\Extension\Couriercenter\Library\CCVoucherService;
use Opencart\Extension\Couriercenter\Oc3\CCSchema;
use Opencart\Extension\Couriercenter\Oc3\CCState;
use Opencart\Extension\Couriercenter\Oc3\CCTelemetry;
use Opencart\Extension\Couriercenter\Oc3\CCVersion;

class ControllerExtensionShippingCourierCenter extends Controller {
    use \Opencart\Extension\Couriercenter\Oc3\CCControllerTrait;

    const ROUTE = 'extension/shipping/courier_center';
    const SECRET_FIELDS = array('credential_value', 'api_key');
    const DEFAULT_TRACKING_URL = 'https://www.courier.gr/track/result?tracknr={{tracking}}';

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
            $post = $this->request->post;
            $warnings = $this->prepareSettingsPost($post);
            $this->model_setting_setting->editSetting('shipping_courier_center', $post);
            $this->session->data['success'] = $this->language->get('text_success');
            if ($warnings) {
                // Stay on the page so the merchant sees what was ignored.
                $this->session->data['cc_warnings'] = $warnings;
                $this->response->redirect($this->ccLink(self::ROUTE));
            }
            $this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=shipping', true));
        }
        CCDbSchema::ensureOnce($this->db, DB_PREFIX);

        $state = $this->ccState();
        CCTelemetry::ping($state, $this->ccSiteUrl());

        $data = array();
        $data['error_warning'] = isset($this->error['warning']) ? $this->error['warning'] : '';
        $data['success'] = '';
        $data['warnings'] = array();
        if (isset($this->session->data['cc_warnings'])) {
            $data['warnings'] = (array)$this->session->data['cc_warnings'];
            $data['success'] = isset($this->session->data['success']) ? $this->session->data['success'] : '';
            unset($this->session->data['cc_warnings'], $this->session->data['success']);
        }
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
        // Secrets are never sent back to the browser: an empty field keeps the saved value.
        foreach (self::SECRET_FIELDS as $field) {
            $data[$field . '_saved'] = trim((string)$this->config->get($this->prefix . $field)) !== '';
            $data[$field] = '';
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

        // Pickup points (sub-codes) and the default point
        $points = CCPickupPoints::fromConfig($this->config);
        $data['pickup_points'] = $points->subs();
        $data['points_all'] = $points->all();
        $data['default_pickup_point'] = $points->defaultCode();

        // Returns
        $rs = new CCReturnsSettings($this->config);
        $data['returns'] = array(
            'enabled'              => $rs->enabled(),
            'require_approval'     => $rs->requireApproval(),
            'window_days'          => $rs->windowDays(),
            'method_dropoff'       => in_array(CCReturnsRules::METHOD_DROPOFF, $rs->enabledMethods(), true),
            'method_boxnow'        => in_array(CCReturnsRules::METHOD_BOXNOW, $rs->enabledMethods(), true),
            'weight'               => $rs->weight(),
            'accounts'             => $rs->accountRows(),
            'dropoff_instructions' => $this->ccText($this->config->get('shipping_courier_center_returns_dropoff_instructions')),
            'boxnow_instructions'  => $this->ccText($this->config->get('shipping_courier_center_returns_boxnow_instructions')),
        );
        $data['returns_defaults'] = array('dropoff' => CCReturnsSettings::DEFAULT_DROPOFF_TEXT, 'boxnow' => CCReturnsSettings::DEFAULT_BOXNOW_TEXT);
        $data['locker_sizes'] = CCReturnsRules::LOCKER_SIZES;
        $data['dropoff_shipper'] = CCReturnsRules::DROPOFF_SHIPPER;
        $data['url_returns'] = $this->ccLink(self::ROUTE . '/returns');

        $data['version'] = CCVersion::get();
        $data['cron_last_run'] = $state->get('cron_last_run', '') ?: '—';
        $data['cron_next_run'] = $state->get('cron_next_run', '') ?: '—';
        $data['cron_cli'] = 'php ' . str_replace('\\', '/', DIR_SYSTEM) . 'library/couriercenter/cron_status_tracker.php';
        $data['cron_url'] = $this->cronUrl($state->ensureCronKey());

        $data['action'] = $this->ccLink(self::ROUTE);
        $data['cancel'] = $this->ccLink('marketplace/extension', 'type=shipping');
        foreach (array('autofill', 'autofillPoint', 'clearSettings', 'repair', 'regenerateCronKey') as $method) {
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
        $r = CCAutofill::fetch($api, $this->ccContext($creds), $creds['billing_account']);
        if (!$r['ok'] && empty($r['account_ok'])) {
            $this->ccJson(array('success' => false, 'error' => $r['error']));
            return;
        }
        foreach ($creds as $k => $v) {
            CCState::upsertSetting($this->db, DB_PREFIX, 'shipping_courier_center', $this->prefix . $k, $v);
        }
        if (!$r['ok']) {
            $this->ccJson(array('success' => false, 'error' => $r['error']));
            return;
        }
        $found = array();
        foreach (array('name', 'address', 'postcode', 'city', 'phone') as $k) {
            $found['shipper_' . $k] = $r['data'][$k];
            if ($r['data'][$k] !== '') {
                CCState::upsertSetting($this->db, DB_PREFIX, 'shipping_courier_center', $this->prefix . 'shipper_' . $k, $r['data'][$k]);
            }
        }
        $out = array('success' => true) + $found;
        if ($r['warning'] !== '') {
            $out['warning'] = $r['warning'];
        }
        $this->ccJson($out);
    }

    /** Test & Auto-fill of ONE pickup point (sub-code): returns its sender data, writes nothing. */
    public function autofillPoint() {
        if (!$this->ccJsonStart()) {
            return;
        }
        if (!$this->ccCanModify()) {
            $this->ccDeny();
            return;
        }
        $code = trim($this->ccText(isset($this->request->post['code']) ? $this->request->post['code'] : ''));
        $creds = array();
        foreach (array('user_alias', 'credential_value', 'api_key', 'billing_account') as $k) {
            $creds[$k] = trim($this->ccText($this->config->get($this->prefix . $k)));
        }
        if ($creds['user_alias'] === '' || $creds['credential_value'] === '' || $creds['api_key'] === '') {
            $this->ccJson(array('success' => false, 'error' => 'Αποθήκευσε πρώτα τα API credentials (και κάνε Test & Auto-fill στον κεντρικό κωδικό).'));
            return;
        }
        if ($code !== '' && $code === $creds['billing_account']) {
            $this->ccJson(array('success' => false, 'error' => 'Αυτός είναι ο κεντρικός κωδικός· πρόσθεσε μόνο υποκωδικούς.'));
            return;
        }
        $api = new CCApi($creds['user_alias'], $creds['credential_value'], $creds['api_key'], $code);
        $r = CCAutofill::fetch($api, $this->ccContext($creds), $code);
        if (!$r['ok']) {
            $this->ccJson(array('success' => false, 'error' => $r['error']));
            return;
        }
        $this->ccJson(array('success' => true, 'data' => $r['data'], 'warning' => $r['warning']));
    }

    private function ccContext(array $creds) {
        return array('UserAlias' => $creds['user_alias'], 'CredentialValue' => $creds['credential_value'], 'ApiKey' => $creds['api_key']);
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

    // ── Order AJAX endpoints (order panel + bulk bar) ──────────────────────
    public function createVoucher() {
        if (!$this->ccJsonStart()) {
            return;
        }
        if (!$this->ccCanModify()) {
            $this->ccDeny();
            return;
        }
        $p = $this->request->post;
        $order_id = (int)($p['order_id'] ?? 0);
        $order = $this->loadOrder($order_id);
        if (!$order) {
            $this->ccJson(array('success' => false, 'error' => 'Παραγγελία δε βρέθηκε'));
            return;
        }
        $svc = $this->ccService();
        $existing = $svc->getShipment($order_id);
        if (!empty($existing['voucher_number']) && empty($existing['is_voided'])) {
            $this->ccJson(array('success' => false, 'error' => 'Η παραγγελία έχει ήδη ενεργό voucher (' . $existing['voucher_number'] . '). Ακύρωσέ το πρώτα.'));
            return;
        }
        if (empty($p['override']) && !$svc->isHandledOrder($order)) {
            $name = CCVoucherService::shippingName($order);
            $this->ccJson(array('success' => false, 'needs_override' => true,
                'error' => 'Η παραγγελία χρησιμοποιεί μέθοδο αποστολής «' . ($name !== '' ? $name : '—') . '» που δεν διαχειρίζεται το plugin. Δημιουργία αποστολής στην Courier Center ούτως ή άλλως;'));
            return;
        }
        $this->ccJson($svc->createForOrder($order, array(
            'service_type'  => (string)($p['service_type'] ?? 'next_day'),
            'parcel_count'  => (int)($p['parcel_count'] ?? 1),
            'return_option' => (string)($p['return_option'] ?? 'none'),
            'boxnow'        => !empty($p['boxnow']),
            'locker_id'     => $this->ccText($p['locker_id'] ?? ''),
            'locker_code'   => $this->ccText($p['locker_code'] ?? ''),
            'locker_name'   => $this->ccText($p['locker_name'] ?? ''),
            'pickup_point'  => trim($this->ccText($p['pickup_point'] ?? '')),
            'mode'          => 'manual',
        )));
    }

    /**
     * Bulk create (Next Day): BOX NOW is detected per order from its checkout choice. POST order_ids,
     * pickup_point ('' = the default point).
     */
    public function bulkCreate() {
        @set_time_limit(0);
        if (!$this->ccJsonStart()) {
            return;
        }
        if (!$this->ccCanModify()) {
            $this->ccDeny();
            return;
        }
        $ids = $this->parseOrderIds((string)($this->request->post['order_ids'] ?? ''));
        if (!$ids) {
            $this->ccJson(array('success' => false, 'error' => 'Δεν επιλέχθηκαν παραγγελίες'));
            return;
        }
        $svc = $this->ccService();
        $point_code = trim($this->ccText($this->request->post['pickup_point'] ?? ''));
        if ($point_code !== '' && $svc->points()->get($point_code) === null) {
            $this->ccJson(array('success' => false, 'error' => 'Το σημείο αποστολής ' . $point_code . ' δεν υπάρχει πια στις ρυθμίσεις. Ανανέωσε τη σελίδα.'));
            return;
        }
        $c = array('success' => 0, 'failed' => 0, 'skipped' => 0, 'boxnow' => 0, 'skipped_unmanaged' => 0, 'errors' => array());
        foreach ($ids as $order_id) {
            $existing = $svc->getShipment($order_id);
            if (!empty($existing['voucher_number']) && empty($existing['is_voided'])) {
                $c['skipped']++;
                continue;
            }
            $order = $this->loadOrder($order_id);
            if (!$order) {
                $c['failed']++;
                $c['errors'][] = '#' . $order_id . ': Παραγγελία δε βρέθηκε';
                continue;
            }
            if (!$svc->isHandledOrder($order)) {
                $c['skipped_unmanaged']++;
                continue;
            }
            $bn = CCVoucherService::boxnowFromOrder($order);
            try {
                $res = $svc->createForOrder($order, array_merge(array('service_type' => 'next_day', 'mode' => 'bulk', 'pickup_point' => $point_code), $bn));
            } catch (\Throwable $e) {
                $res = array('success' => false, 'error' => $e->getMessage());
            }
            if (!empty($res['success'])) {
                $c['success']++;
                if ($bn) {
                    $c['boxnow']++;
                }
            } elseif (isset($res['stage']) && $res['stage'] === 'exists') {
                $c['skipped']++;
            } else {
                $c['failed']++;
                $c['errors'][] = '#' . $order_id . ': ' . ($res['error'] ?? 'Άγνωστο σφάλμα');
            }
            usleep(250000);
        }
        $point = $svc->points()->get($point_code !== '' ? $point_code : $svc->points()->defaultCode());
        $msg = 'Σύνολο: ' . count($ids) . ' | ✅ Δημιουργήθηκαν: ' . $c['success'] . ($c['boxnow'] ? ' (από αυτά BOX NOW: ' . $c['boxnow'] . ')' : '');
        if ($svc->points()->hasSubs() && $point) {
            $msg .= ' | 📍 Σημείο: ' . CCPickupPoints::label($point);
        }
        if ($c['skipped']) {
            $msg .= ' | ⏭️ Έχουν ήδη voucher: ' . $c['skipped'];
        }
        if ($c['skipped_unmanaged']) {
            $msg .= ' | 🚫 Μη διαχειρίσιμη μέθοδος: ' . $c['skipped_unmanaged'];
        }
        if ($c['failed']) {
            $msg .= ' | ❌ Απέτυχαν: ' . $c['failed'];
        }
        if ($c['errors']) {
            $msg .= "\n\n" . implode("\n", array_slice($c['errors'], 0, 15));
        }
        $this->ccJson(array('success' => true, 'message' => $msg));
    }

    public function bulkStatus() {
        @set_time_limit(0);
        if (!$this->ccJsonStart()) {
            return;
        }
        if (!$this->ccCanModify()) {
            $this->ccDeny();
            return;
        }
        $ids = $this->parseOrderIds((string)($this->request->post['order_ids'] ?? ''));
        if (!$ids) {
            $this->ccJson(array('success' => false, 'error' => 'Δεν επιλέχθηκαν παραγγελίες'));
            return;
        }
        $svc = $this->ccService();
        $tracker = $svc->tracker();
        $updated = 0;
        $skipped = 0;
        $recent = 0;
        $failed = 0;
        $errors = array();
        foreach ($ids as $order_id) {
            $shipment = $svc->getShipment($order_id);
            if (empty($shipment['voucher_number']) || !empty($shipment['is_voided'])) {
                $skipped++;
                continue;
            }
            if (CCStatusTracker::wait_minutes($shipment) > 0) {
                $recent++; // same 60-minute limit as the order panel and the cron
                continue;
            }
            $r = $tracker->refresh($shipment, 'Bulk');
            if ($r['success']) {
                $updated++;
            } else {
                $failed++;
                $errors[] = '#' . $order_id . ': ' . $r['error'];
            }
            usleep(300000);
        }
        $msg = 'Σύνολο: ' . count($ids) . ' | 🔄 Ενημερώθηκαν: ' . $updated;
        if ($skipped) {
            $msg .= ' | ⏭️ Χωρίς voucher: ' . $skipped;
        }
        if ($recent) {
            $msg .= ' | ⏱️ Ενημερώθηκαν την τελευταία ώρα: ' . $recent;
        }
        if ($failed) {
            $msg .= ' | ❌ Απέτυχαν: ' . $failed;
        }
        if ($errors) {
            $msg .= "\n\n" . implode("\n", array_slice($errors, 0, 15));
        }
        $this->ccJson(array('success' => true, 'message' => $msg));
    }

    public function voidShipment() {
        if (!$this->ccJsonStart()) {
            return;
        }
        if (!$this->ccCanModify()) {
            $this->ccDeny();
            return;
        }
        $r = $this->ccService()->voidForOrder((int)($this->request->post['order_id'] ?? 0));
        $this->ccJson($r['success'] ? array('success' => true) : array('success' => false, 'error' => $r['error']));
    }

    public function removeBoxNow() {
        if (!$this->ccJsonStart()) {
            return;
        }
        if (!$this->ccCanModify()) {
            $this->ccDeny();
            return;
        }
        $order_id = (int)($this->request->post['order_id'] ?? 0);
        $shipment = $this->ccService()->getShipment($order_id);
        if (!empty($shipment['voucher_number']) && empty($shipment['is_voided'])) {
            $this->ccJson(array('success' => false, 'error' => 'Ακύρωσε πρώτα την αποστολή.'));
            return;
        }
        $this->load->model('extension/shipping/courier_center');
        $this->model_extension_shipping_courier_center->clearOrderBoxnow($order_id);
        if ($shipment) {
            $this->model_extension_shipping_courier_center->removeBoxNow($order_id);
        }
        $this->ccJson(array('success' => true));
    }

    public function updateStatus() {
        if (!$this->ccJsonStart()) {
            return;
        }
        if (!$this->ccCanModify()) {
            $this->ccDeny();
            return;
        }
        $order_id = (int)($this->request->post['order_id'] ?? 0);
        $svc = $this->ccService();
        $shipment = $svc->getShipment($order_id);
        if (empty($shipment['voucher_number'])) {
            $this->ccJson(array('success' => false, 'error' => 'Δεν υπάρχει αποστολή'));
            return;
        }
        $mins = CCStatusTracker::wait_minutes($shipment);
        if ($mins > 0) {
            $this->ccJson(array('success' => false, 'error' => 'Η αποστολή ενημερώθηκε πρόσφατα. Ξαναδοκίμασε σε ' . $mins . ' λεπτά.'));
            return;
        }
        $r = $svc->tracker()->refresh($shipment, '');
        $this->ccJson($r['success'] ? array('success' => true, 'code' => $r['code'], 'desc' => $r['desc']) : array('success' => false, 'error' => $r['error']));
    }

    /** GET order_id [&type=return]. Needs only "access" (warehouse staff can print, spec 5.12). */
    public function downloadVoucher() {
        if (!$this->ccPhpOk()) {
            $this->ccTextError(500, 'PHP 7.2+ required');
        }
        $this->ccBoot();
        CCErrorGuard::start($this->ccLogger());
        $svc = $this->ccService();
        $shipment = $svc->getShipment((int)($this->request->get['order_id'] ?? 0));
        if (empty($shipment['voucher_number'])) {
            $this->ccTextError(404, 'Δεν υπάρχει αποστολή για αυτή την παραγγελία.');
        }
        if (($this->request->get['type'] ?? '') === 'return') {
            if (empty($shipment['return_awb'])) {
                $this->ccTextError(404, 'Δεν υπάρχει επιστροφικό voucher για αυτή την παραγγελία.');
            }
            $awbs = array($shipment['return_awb']);
        } else {
            $awbs = array($shipment['voucher_number']);
            if (!empty($shipment['return_awb'])) {
                $awbs[] = $shipment['return_awb'];
            }
        }
        $this->ccSendPdf($this->fetchPdf($svc, $awbs, !empty($shipment['is_boxnow'])), 'voucher_' . $shipment['voucher_number'] . '.pdf');
    }

    public function bulkPrint() {
        $this->streamBulkPdf(false);
    }

    public function bulkPrintBoxnow() {
        $this->streamBulkPdf(true);
    }

    private function streamBulkPdf($boxnow_only) {
        if (!$this->ccPhpOk()) {
            $this->ccTextError(500, 'PHP 7.2+ required');
        }
        $this->ccBoot();
        CCErrorGuard::start($this->ccLogger());
        $ids = $this->parseOrderIds((string)($this->request->get['order_ids'] ?? ''));
        if (!$ids) {
            $this->ccTextError(400, 'Δεν επιλέχθηκαν παραγγελίες.');
        }
        $svc = $this->ccService();
        $awbs = array();
        foreach ($ids as $order_id) {
            $s = $svc->getShipment($order_id);
            if (empty($s['voucher_number']) || !empty($s['is_voided']) || $boxnow_only !== !empty($s['is_boxnow'])) {
                continue;
            }
            $awbs[] = $s['voucher_number'];
            if (!empty($s['return_awb'])) {
                $awbs[] = $s['return_awb'];
            }
        }
        if (!$awbs) {
            $this->ccTextError(404, $boxnow_only
                ? 'Καμία από τις επιλεγμένες παραγγελίες δεν έχει ενεργό BOX NOW voucher.'
                : 'Καμία από τις επιλεγμένες παραγγελίες δεν έχει ενεργό voucher.');
        }
        $this->ccSendPdf($this->fetchPdf($svc, $awbs, $boxnow_only), 'vouchers-' . date('Y-m-d-His') . '.pdf');
    }

    /** Voucher PDF with the configured template; custom 4-up and 95% scaling as in OC4. */
    private function fetchPdf($svc, array $awbs, $boxnow) {
        $raw = $boxnow ? ((string)$svc->setting('print_template_boxnow') ?: 'singlepdf_100x150_4up') : ((string)$svc->setting('print_template') ?: 'pdf');
        $use_4up = ($raw === 'singlepdf_100x150_4up');
        $api_template = $use_4up ? 'singlepdf_100x150' : $raw;
        $pdf = $svc->api()->get_voucher_pdf(implode(',', $awbs), $api_template);
        if (is_array($pdf)) {
            $this->ccTextError(500, 'Σφάλμα voucher: ' . ($pdf['error'] ?? 'Άγνωστο σφάλμα'));
        }
        try {
            if ($use_4up) {
                $pdf = CCPdfScaler::arrange_4up($pdf);
            } elseif (in_array($api_template, array('pdf', 'clean'), true)) {
                $pdf = CCPdfScaler::scale_pdf($pdf);
            }
        } catch (\Throwable $e) {
            $this->log->write('Courier Center PDF post-processing (original PDF served): ' . $e->getMessage());
        }
        return $pdf;
    }

    private function loadOrder($order_id) {
        if ((int)$order_id <= 0) {
            return array();
        }
        $this->load->model('sale/order');
        $order = $this->model_sale_order->getOrder((int)$order_id);
        return is_array($order) ? $order : array();
    }

    /** "1,2,3" -> unique positive ints */
    private function parseOrderIds($raw) {
        $ids = array();
        foreach (explode(',', $raw) as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }

    // ── Helpers ────────────────────────────────────────────────────────────
    private function validate() {
        if (!$this->user->hasPermission('modify', self::ROUTE)) {
            $this->error['warning'] = $this->language->get('error_permission');
        }
        return !$this->error;
    }

    /**
     * Normalise the settings form before editSetting() (which replaces EVERY key of the extension):
     * secrets left empty keep the saved value, the internal keys survive, pickup points and returns
     * are cleaned. @return string[] warnings for the merchant
     */
    private function prepareSettingsPost(array &$post) {
        $this->ccBoot();
        $p = $this->prefix;
        $warnings = array();
        foreach (self::SECRET_FIELDS as $field) {
            if (trim((string)($post[$p . $field] ?? '')) === '') {
                $post[$p . $field] = (string)$this->config->get($p . $field);
            }
        }
        $url = trim($this->ccText($post[$p . 'tracking_url'] ?? ''));
        if ($url === '' || strpos($url, '{{tracking}}') === false) {
            if ($url !== '') {
                $warnings[] = 'Το Tracking URL πρέπει να περιέχει το {{tracking}}· μπήκε ξανά το προεπιλεγμένο.';
            }
            $post[$p . 'tracking_url'] = self::DEFAULT_TRACKING_URL;
        }
        $main = trim($this->ccText($post[$p . 'billing_account'] ?? ''));
        $clean = CCPickupPoints::sanitizeRows($post[$p . 'pickup_points'] ?? array(), $main);
        $post[$p . 'pickup_points'] = $clean['rows'];
        $warnings = array_merge($warnings, $clean['errors']);
        $codes = array($main);
        foreach ($clean['rows'] as $row) {
            $codes[] = $row['code'];
        }
        $default = trim($this->ccText($post[$p . 'default_pickup_point'] ?? ''));
        $post[$p . 'default_pickup_point'] = in_array($default, $codes, true) ? $default : '';
        $warnings = array_merge($warnings, CCReturnsSettings::sanitizePost($post, array_values(array_filter($codes, 'strlen'))));
        return $warnings;
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
}
