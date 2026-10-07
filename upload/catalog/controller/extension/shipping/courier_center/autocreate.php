<?php
/**
 * Auto-create voucher on order-status change (event catalog/model/checkout/order/addOrderHistory/after,
 * sort 50: before the tracking email). Port of courier_center_autocreate.php (OC4) on CCVoucherService.
 * A failure NEVER breaks the status change.
 */
require_once DIR_SYSTEM . 'library/couriercenter/oc3/CCControllerTrait.php';

use Opencart\Extension\Couriercenter\Oc3\CCErrorGuard;
use Opencart\Extension\Couriercenter\Oc3\CCVoucherService;

class ControllerExtensionShippingCourierCenterAutocreate extends Controller {
    use \Opencart\Extension\Couriercenter\Oc3\CCControllerTrait;

    /** $args = [order_id, order_status_id, comment, notify, override]. Returns nothing (spec 5.4). */
    public function autoCreate(&$route = '', &$args = array(), &$output = null) {
        if (!$this->ccPhpOk()) {
            return;
        }
        try {
            if ((string)$this->config->get('shipping_courier_center_auto_create_enabled') !== '1') {
                return;
            }
            $trigger = (int)$this->config->get('shipping_courier_center_auto_create_status_id');
            $order_id = (int)($args[0] ?? 0);
            if ($trigger <= 0 || $order_id <= 0 || (int)($args[1] ?? 0) !== $trigger) {
                return;
            }
            $this->ccBoot();
            CCErrorGuard::start($this->ccLogger());
            try {
                $svc = $this->ccService();
                $existing = $svc->getShipment($order_id);
                if (!empty($existing['voucher_number']) && empty($existing['is_voided'])) {
                    return;
                }
                $this->load->model('checkout/order');
                $order = $this->model_checkout_order->getOrder($order_id);
                if (!$order) {
                    return;
                }
                if (!$svc->isHandledOrder($order)) {
                    $svc->history()->add($order_id, '⚠️ Αυτόματη δημιουργία voucher παραλείφθηκε: η μέθοδος αποστολής δεν διαχειρίζεται από το plugin.');
                    return;
                }
                $res = $svc->createForOrder($order, array_merge(array('service_type' => 'next_day', 'mode' => 'auto'), CCVoucherService::boxnowFromOrder($order)));
                if (empty($res['success'])) {
                    $prefix = (isset($res['stage']) && $res['stage'] === 'api') ? '❌ Αυτόματη δημιουργία voucher απέτυχε: ' : '⚠️ Αυτόματη δημιουργία voucher παραλείφθηκε: ';
                    $svc->history()->add($order_id, $prefix . $res['error']);
                }
            } finally {
                CCErrorGuard::stop();
            }
        } catch (\Throwable $e) {
            $this->log->write('Courier Center auto-create voucher: ' . $e->getMessage());
        }
    }
}
