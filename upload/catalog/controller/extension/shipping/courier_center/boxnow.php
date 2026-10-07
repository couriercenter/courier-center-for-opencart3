<?php
/**
 * BOX NOW on the storefront checkout (OpenCart 3). Port of courier_center_boxnow.php (OC4) with the
 * OpenCart 3 rules of spec 5.7: widget through the footer on checkout pages, the choice is written
 * into EVERY order created while it is selected, cleared on checkout/success.
 */
require_once DIR_SYSTEM . 'library/couriercenter/oc3/CCControllerTrait.php';

use Opencart\Extension\Couriercenter\Oc3\CCBoxnow;

class ControllerExtensionShippingCourierCenterBoxnow extends Controller {
    use \Opencart\Extension\Couriercenter\Oc3\CCControllerTrait;

    /** Event catalog/view/common/footer/after. Returns nothing (spec 5.4). */
    public function widget(&$route = '', &$data = array(), &$output = '') {
        if (!is_string($output) || $output === '' || !$this->ccPhpOk()) {
            return;
        }
        try {
            if ((string)$this->config->get('shipping_courier_center_boxnow_enabled') !== '1') {
                return;
            }
            $this->ccBoot();
            $page = isset($this->request->get['route']) ? (string)$this->request->get['route'] : '';
            if (!CCBoxnow::routeWantsWidget($page) || strpos($output, 'cc-boxnow-wrap') !== false) {
                return;
            }
            $sel = (isset($this->session->data['cc_boxnow']) && is_array($this->session->data['cc_boxnow'])) ? $this->session->data['cc_boxnow'] : array();
            $html = $this->load->view('extension/shipping/courier_center/boxnow_widget', array(
                'partner_id'       => CCBoxnow::PARTNER_ID,
                'save_url'         => $this->ccCatalogLink('extension/shipping/courier_center/boxnow/saveSession'),
                'allowed_shipping' => $this->allowedMethods(),
                'default_selected' => (string)$this->config->get('shipping_courier_center_boxnow_default_selected') === '1',
                'sel_selected'     => !empty($sel['selected']),
                'sel_mode'         => (string)($sel['delivery_mode'] ?? ''),
                'sel_locker_id'    => (string)($sel['locker_id'] ?? ''),
                'sel_locker_code'  => (string)($sel['locker_code'] ?? ''),
                'sel_locker_name'  => (string)($sel['locker_name'] ?? ''),
            ));
            $pos = strripos($output, '</body>');
            $output = $pos === false ? $output . $html : substr($output, 0, $pos) . $html . substr($output, $pos);
        } catch (\Throwable $e) {
            $this->log->write('Courier Center BOX NOW widget: ' . $e->getMessage());
        }
    }

    /** AJAX from the widget: store / clear the choice in the session. */
    public function saveSession() {
        $this->response->addHeader('Content-Type: application/json');
        if (!$this->ccPhpOk()) {
            $this->response->setOutput(json_encode(array('success' => false)));
            return;
        }
        $this->ccBoot();
        $sel = CCBoxnow::sanitizeSelection($this->request->post);
        if ($sel === null) {
            unset($this->session->data['cc_boxnow']);
        } else {
            $this->session->data['cc_boxnow'] = $sel;
        }
        $this->response->setOutput(json_encode(array('success' => true, 'selection' => $sel)));
    }

    /** Event catalog/model/checkout/order/addOrder/after: $args[0] = order data, $output = new order_id. */
    public function saveToOrder(&$route = '', &$args = array(), &$output = null) {
        if (!$this->ccPhpOk()) {
            return;
        }
        try {
            $order_id = (int)$output;
            $stored = (isset($this->session->data['cc_boxnow']) && is_array($this->session->data['cc_boxnow'])) ? $this->session->data['cc_boxnow'] : array();
            if ($order_id <= 0 || empty($stored['selected'])) {
                return;
            }
            $this->ccBoot();
            $shipping_code = isset($args[0]['shipping_code']) ? (string)$args[0]['shipping_code'] : '';
            if (!CCBoxnow::methodAllowed($shipping_code, $this->allowedMethods())) {
                return; // choice made for another shipping method
            }
            $q = $this->db->query("SELECT `custom_field` FROM `" . DB_PREFIX . "order` WHERE `order_id` = '" . $order_id . "' LIMIT 1");
            if (!$q->num_rows) {
                return;
            }
            $cf = CCBoxnow::applyToCustomField($q->row['custom_field'], CCBoxnow::sanitizeSelection($stored));
            $this->db->query("UPDATE `" . DB_PREFIX . "order` SET `custom_field` = '" . $this->db->escape(json_encode($cf)) . "' WHERE `order_id` = '" . $order_id . "'");
            // NOT cleared here: OpenCart 3 creates a new order every time the confirm step loads (spec 4.9).
        } catch (\Throwable $e) {
            $this->log->write('Courier Center BOX NOW order: ' . $e->getMessage());
        }
    }

    /** Event catalog/controller/checkout/success/before. */
    public function clearSession(&$route = '', &$data = array()) {
        unset($this->session->data['cc_boxnow']);
    }

    private function allowedMethods() {
        $allowed = $this->config->get('shipping_courier_center_boxnow_shipping_methods');
        return (is_array($allowed) && $allowed) ? array_values(array_map('strval', $allowed)) : array('courier_center');
    }
}
