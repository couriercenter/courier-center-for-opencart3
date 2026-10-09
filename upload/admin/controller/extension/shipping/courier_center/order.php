<?php
/**
 * "Courier Center" tab of the admin order page (event admin/view/sale/order_info/before).
 * Port of courier_center_order.php::orderPanel (OC4) as a NATIVE OpenCart 3 tab: the core
 * template renders $data['tabs'] (spec 4.11), so no JavaScript has to move HTML around.
 */
require_once DIR_SYSTEM . 'library/couriercenter/oc3/CCControllerTrait.php';

use Opencart\Extension\Couriercenter\Library\CCDbSchema;
use Opencart\Extension\Couriercenter\Library\CCOrderAdapter;
use Opencart\Extension\Couriercenter\Library\CCPickupPoints;
use Opencart\Extension\Couriercenter\Library\CCVoucherService;

class ControllerExtensionShippingCourierCenterOrder extends Controller {
    use \Opencart\Extension\Couriercenter\Oc3\CCControllerTrait;

    /** Returns nothing: a value would stop the other extensions' handlers (spec 5.4). */
    public function orderTab(&$route = '', &$data = array(), &$code = '') {
        if (!is_array($data) || !$this->ccPhpOk()) {
            return;
        }
        try {
            $order_id = (int)($this->request->get['order_id'] ?? 0);
            if ($order_id <= 0) {
                return;
            }
            $this->ccBoot();
            CCDbSchema::ensureOnce($this->db, DB_PREFIX);
            $this->load->model('extension/shipping/courier_center');
            $shipment = array();
            foreach ($this->model_extension_shipping_courier_center->getShipment($order_id) as $k => $v) {
                $shipment[$k] = $this->ccText($v);
            }
            $cf = $this->model_extension_shipping_courier_center->getOrderCustomField($order_id);

            $status = (string)($shipment['status_code'] ?? '');
            $status_class = '';
            if (in_array($status, array('29', '87'), true)) {
                $status_class = 'cc-delivered';
            } elseif (in_array($status, array('28', '30'), true)) {
                $status_class = 'cc-failed';
            } elseif (in_array($status, array('25', '99', '14'), true)) {
                $status_class = 'cc-returned';
            }
            $return_labels = array('optional' => 'Προαιρετικό', 'mandatory' => 'Υποχρεωτικό');
            $route_base = 'extension/shipping/courier_center/';

            // Pickup points: the select appears only with sub-codes; after a void the old point is preselected.
            $points = CCPickupPoints::fromConfig($this->config);
            $point_options = array();
            foreach ($points->all() as $pt) {
                $point_options[] = array('code' => $pt['code'], 'label' => CCPickupPoints::label($pt));
            }
            $current_point = (string)($shipment['pickup_point'] ?? '');
            $selected_point = ($current_point !== '' && $points->get($current_point)) ? $current_point : $points->defaultCode();
            $point_info = '';
            if ($points->hasSubs() && $current_point !== '') {
                $pt = $points->get($current_point);
                $point_info = $pt ? CCPickupPoints::label($pt) : $current_point;
                if (!empty($shipment['requestor_account']) && $shipment['requestor_account'] !== $current_point) {
                    $point_info .= ' — Χρέωση: ' . $shipment['requestor_account'];
                }
            }

            // Consignee preview (shipping address, per-field fallback to the payment address)
            $this->load->model('sale/order');
            $order = $this->model_sale_order->getOrder($order_id);
            $consignee = '';
            if ($order) {
                $a = new CCOrderAdapter($order);
                $consignee = trim($a->get_billing_first_name() . ' ' . $a->get_billing_last_name()) . ', '
                    . trim($a->get_billing_address_1() . ' ' . $a->get_billing_address_2()) . ', '
                    . $a->get_billing_postcode() . ' ' . $a->get_billing_city() . ' · ' . $a->get_billing_phone();
            }

            // Customer returns of this order
            $returns = array();
            $svc_ret = $this->ccReturns();
            foreach ($svc_ret->repo()->forOrder($order_id) as $r) {
                $returns[] = $this->ccReturnRow($r);
            }

            $panel = array(
                'order_id'            => $order_id,
                'shipment'            => $shipment,
                'has_active'          => !empty($shipment['voucher_number']) && empty($shipment['is_voided']),
                'was_voided'          => !empty($shipment['voucher_number']) && !empty($shipment['is_voided']),
                'status_class'        => $status_class,
                'service_label'       => CCVoucherService::serviceLabel((string)($shipment['service_type'] ?? '')),
                'return_label'        => $return_labels[$shipment['return_option'] ?? ''] ?? '',
                'locker_id'           => $this->ccText($cf['cc_locker_id'] ?? ''),
                'locker_code'         => $this->ccText($cf['cc_locker_code'] ?? ''),
                'locker_name'         => $this->ccText($cf['cc_locker_name'] ?? ''),
                'delivery_mode'       => (string)($cf['cc_delivery_mode'] ?? ''),
                'url_create'          => $this->ccLink($route_base . 'createVoucher'),
                'url_void'            => $this->ccLink($route_base . 'voidShipment'),
                'url_status'          => $this->ccLink($route_base . 'updateStatus'),
                'url_remove_bn'       => $this->ccLink($route_base . 'removeBoxNow'),
                'url_download'        => $this->ccLink($route_base . 'downloadVoucher', 'order_id=' . $order_id),
                'url_download_return' => $this->ccLink($route_base . 'downloadVoucher', 'order_id=' . $order_id . '&type=return'),
                'has_points'          => $points->hasSubs(),
                'point_options'       => $point_options,
                'selected_point'      => $selected_point,
                'point_info'          => $point_info,
                'consignee'           => $this->ccText($consignee),
                'returns'             => $returns,
                'returns_js'          => $returns ? $this->load->view('extension/shipping/courier_center/returns_js', array('urls' => $this->ccReturnActionUrls())) : '',
            );
            if (!isset($data['tabs']) || !is_array($data['tabs'])) {
                $data['tabs'] = array();
            }
            $data['tabs'][] = array(
                'code'    => 'courier-center',
                'title'   => 'Courier Center',
                'content' => $this->load->view('extension/shipping/courier_center/order_panel', $panel),
            );
        } catch (\Throwable $e) {
            $this->log->write('Courier Center order tab: ' . $e->getMessage());
        }
    }
}
