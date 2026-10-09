<?php
/**
 * Tracking inside the shop's own customer emails (like the WooCommerce plugin): the AWB and the
 * tracking link are added to the order confirmation (mail/order_add, HTML) and to every status
 * update (mail/order_edit, text) of an order with an active voucher. No separate email any more.
 */
require_once DIR_SYSTEM . 'library/couriercenter/oc3/CCControllerTrait.php';

use Opencart\Extension\Couriercenter\Library\CCMailContent;

class ControllerExtensionShippingCourierCenterEmail extends Controller {
    use \Opencart\Extension\Couriercenter\Oc3\CCControllerTrait;

    /** Event catalog/view/mail/order_add/after. Returns nothing. */
    public function orderAdd(&$route = '', &$data = array(), &$output = '') {
        $block = $this->block($data, true);
        if ($block === '' || !is_string($output)) {
            return;
        }
        // After the greeting, before the first table (order details), as WooCommerce does.
        $pos = stripos($output, '<table');
        if ($pos === false) {
            $pos = strripos($output, '</body>');
        }
        $output = $pos === false ? $output . $block : substr($output, 0, $pos) . $block . substr($output, $pos);
    }

    /** Event catalog/view/mail/order_edit/after (plain text). Returns nothing. */
    public function orderEdit(&$route = '', &$data = array(), &$output = '') {
        $block = $this->block($data, false);
        if ($block === '' || !is_string($output)) {
            return;
        }
        $footer = isset($data['text_footer']) ? (string)$data['text_footer'] : '';
        $pos = $footer !== '' ? strrpos($output, $footer) : false;
        $output = $pos === false ? rtrim($output) . "\n\n" . $block : substr($output, 0, $pos) . $block . "\n" . substr($output, $pos);
    }

    private function block($data, $html) {
        if (!is_array($data) || !$this->ccPhpOk()) {
            return '';
        }
        try {
            if ((string)$this->config->get('shipping_courier_center_email_tracking_enabled') === '0') {
                return '';
            }
            $order_id = (int)($data['order_id'] ?? 0);
            if ($order_id <= 0) {
                return '';
            }
            $this->ccBoot();
            $shipment = $this->ccService()->getShipment($order_id);
            foreach (array('voucher_number', 'tracking_number', 'boxnow_parcel_id') as $k) {
                if (isset($shipment[$k])) {
                    $shipment[$k] = $this->ccText($shipment[$k]);
                }
            }
            $template = $this->ccText($this->config->get('shipping_courier_center_tracking_url'));
            return $html ? CCMailContent::trackingHtml($shipment, $template) : CCMailContent::trackingText($shipment, $template);
        } catch (\Throwable $e) {
            $this->log->write('Courier Center tracking in email: ' . $e->getMessage());
            return '';
        }
    }
}
