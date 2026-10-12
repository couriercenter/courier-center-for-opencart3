<?php
/**
 * Courier Center on the admin order list (event admin/view/sale/order_list/after):
 * Manifest button, bulk bar, Method / CC Voucher / CC Status columns.
 * Port of courier_center_order_list.php (OC4). Values go in with textContent (spec 10 #9).
 */
require_once DIR_SYSTEM . 'library/couriercenter/oc3/CCControllerTrait.php';

class ControllerExtensionShippingCourierCenterOrderList extends Controller {
    use \Opencart\Extension\Couriercenter\Oc3\CCControllerTrait;

    public function injectColumns(&$route = '', &$data = array(), &$output = '') {
        if (!is_string($output) || $output === '' || !$this->ccPhpOk()) {
            return;
        }
        try {
            $this->ccBoot();
            \Opencart\Extension\Couriercenter\Library\CCDbSchema::ensureOnce($this->db, DB_PREFIX);
            preg_match_all('/name="selected\[\]"\s+value="(\d+)"/', $output, $m);
            $ids = array_values(array_unique(array_map('intval', $m[1])));
            $shipments = array();
            $methods = array();
            $returns = array();
            $svc = $this->ccService();
            if ($ids) {
                $this->load->model('extension/shipping/courier_center');
                foreach ($this->model_extension_shipping_courier_center->getShipmentsByOrderIds($ids) as $id => $row) {
                    $shipments[(string)$id] = array(
                        'voucher_number'  => $this->ccText($row['voucher_number']),
                        'tracking_number' => $this->ccText($row['tracking_number']),
                        'status_code'     => (string)$row['status_code'],
                        'status_desc'     => $this->ccText($row['status_desc']),
                        'is_voided'       => (int)$row['is_voided'],
                        'is_boxnow'       => (int)$row['is_boxnow'],
                        'parcel_id'       => $this->ccText($row['boxnow_parcel_id'] ?? ''),
                    );
                }
                foreach ($this->model_extension_shipping_courier_center->getOrderShippingRows($ids) as $id => $row) {
                    $methods[(string)$id] = array('name' => \Opencart\Extension\Couriercenter\Library\CCVoucherService::shippingName($row), 'managed' => $svc->isHandledOrder($row));
                }
                // «Επιστροφή»: status of the newest return request (+N when there are more)
                $repo = new \Opencart\Extension\Couriercenter\Library\CCReturnsRepo($this->db, DB_PREFIX);
                foreach ($repo->summaryForOrders($ids) as $id => $r) {
                    $returns[(string)$id] = \Opencart\Extension\Couriercenter\Library\CCReturnsRules::status_label($r['status']) . ($r['count'] > 1 ? ' (+' . ($r['count'] - 1) . ')' : '');
                }
            }
            $tracking = (string)$svc->setting('tracking_url');
            $points = array();
            if ($svc->points()->hasSubs()) {
                foreach ($svc->points()->all() as $pt) {
                    $points[] = array('code' => $pt['code'], 'label' => \Opencart\Extension\Couriercenter\Library\CCPickupPoints::label($pt));
                }
            }
            $base = 'extension/shipping/courier_center/';
            $script = $this->load->view('extension/shipping/courier_center/order_list_inject', array(
                'shipments'    => (object)$shipments,
                'methods'      => (object)$methods,
                'returns'      => (object)$returns,
                'tracking_url' => $tracking !== '' ? $tracking : 'https://www.courier.gr/track/result?tracknr={{tracking}}',
                'points'       => $points,
                'default_point' => $svc->points()->defaultCode(),
                'urls'         => array(
                    'create'   => $this->ccLink($base . 'bulkCreate'),
                    'status'   => $this->ccLink($base . 'bulkStatus'),
                    'print'    => $this->ccLink($base . 'bulkPrint'),
                    'printbn'  => $this->ccLink($base . 'bulkPrintBoxnow'),
                ),
                'url_manifest' => $this->ccLink($base . 'manifest'),
            ));
            $pos = strripos($output, '</body>');
            $output = $pos === false ? $output . $script : substr($output, 0, $pos) . $script . substr($output, $pos);
        } catch (\Throwable $e) {
            $this->log->write('Courier Center order list: ' . $e->getMessage());
        }
    }
}
