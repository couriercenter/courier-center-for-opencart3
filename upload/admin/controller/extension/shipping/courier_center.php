<?php
/**
 * Courier Center - OpenCart 3 shipping extension (admin): settings page, install /
 * uninstall / repair and the order AJAX endpoints (Task 13).
 * Port of admin/controller/shipping/courier_center.php of the OpenCart 4 extension.
 * PHP 7.0 syntax: the PHP gate must be able to show its message on old hosts.
 */
require_once DIR_SYSTEM . 'library/couriercenter/oc3/CCControllerTrait.php';

use Opencart\Extension\Couriercenter\Library\CCApi;
use Opencart\Extension\Couriercenter\Library\CCPdfScaler;
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
            $name = $this->ccText($order['shipping_method'] ?? '');
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
            'mode'          => 'manual',
        )));
    }

    public function bulkCreate() {
        $this->bulkCreateRun(false);
    }

    public function bulkCreateBoxnow() {
        $this->bulkCreateRun(true);
    }

    /** Each button handles only its own type, like the bulk printing (OC4 ccRunBulkCreate). */
    private function bulkCreateRun($boxnow_only) {
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
        $c = array('success' => 0, 'failed' => 0, 'skipped' => 0, 'skipped_type' => 0, 'skipped_unmanaged' => 0, 'errors' => array());
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
            if ($boxnow_only !== !empty($bn)) {
                $c['skipped_type']++;
                continue;
            }
            try {
                $res = $svc->createForOrder($order, array_merge(array('service_type' => 'next_day', 'mode' => 'manual'), $bn));
            } catch (\Throwable $e) {
                $res = array('success' => false, 'error' => $e->getMessage());
            }
            if (!empty($res['success'])) {
                $c['success']++;
            } else {
                $c['failed']++;
                $c['errors'][] = '#' . $order_id . ': ' . ($res['error'] ?? 'Άγνωστο σφάλμα');
            }
            usleep(250000);
        }
        $msg = ($boxnow_only ? '📦 BOX NOW — ' : '') . 'Σύνολο: ' . count($ids) . ' | ✅ Δημιουργήθηκαν: ' . $c['success'];
        if ($c['skipped']) {
            $msg .= ' | ⏭️ Έχουν ήδη voucher: ' . $c['skipped'];
        }
        if ($c['skipped_type']) {
            $msg .= $boxnow_only ? ' | ➡️ Όχι BOX NOW (χρησ. «Δημιουργία Vouchers»): ' . $c['skipped_type'] : ' | 📦 BOX NOW (χρησ. «Δημιουργία BOX NOW»): ' . $c['skipped_type'];
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
        $failed = 0;
        $errors = array();
        foreach ($ids as $order_id) {
            $shipment = $svc->getShipment($order_id);
            if (empty($shipment['voucher_number']) || !empty($shipment['is_voided'])) {
                $skipped++;
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
        $order_id = (int)($this->request->post['order_id'] ?? 0);
        $svc = $this->ccService();
        $shipment = $svc->getShipment($order_id);
        if (empty($shipment['voucher_number'])) {
            $this->ccJson(array('success' => false, 'error' => 'Δεν υπάρχει αποστολή για ακύρωση'));
            return;
        }
        if (!empty($shipment['is_voided'])) {
            $this->ccJson(array('success' => false, 'error' => 'Η αποστολή έχει ήδη ακυρωθεί.'));
            return;
        }
        $result = $svc->api()->void_shipment($shipment['voucher_number']);
        if ($result !== true) {
            $this->ccJson(array('success' => false, 'error' => is_array($result) && isset($result['error']) ? $result['error'] : 'Αποτυχία ακύρωσης'));
            return;
        }
        $this->load->model('extension/shipping/courier_center');
        $this->model_extension_shipping_courier_center->voidShipment($order_id);
        $svc->history()->add($order_id, '❌ Courier Center: Αποστολή ακυρώθηκε — AWB ' . $shipment['voucher_number']);
        $this->ccJson(array('success' => true));
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
        $last = (int)($shipment['last_checked_at'] ?? 0);
        $elapsed = time() - $last;
        if ($last > 0 && $elapsed < CCStatusTracker::MIN_INTERVAL) {
            $mins = max(1, (int)ceil((CCStatusTracker::MIN_INTERVAL - $elapsed) / 60));
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
