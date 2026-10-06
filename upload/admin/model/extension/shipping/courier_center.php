<?php
/**
 * Courier Center - admin model (OpenCart 3). Port of admin/model/shipping/courier_center.php (OC4)
 * without install()/uninstall(): the schema lives in oc3/CCSchema (spec 5.9).
 */
class ModelExtensionShippingCourierCenter extends Model {

    public function getShipment($order_id) {
        $q = $this->db->query("SELECT * FROM `" . DB_PREFIX . "cc_shipments` WHERE `order_id` = '" . (int)$order_id . "' LIMIT 1");
        return $q->num_rows ? $q->row : array();
    }

    /** @return array order_id => cc_shipments row */
    public function getShipmentsByOrderIds(array $order_ids) {
        $ids = $this->intList($order_ids);
        if ($ids === '') {
            return array();
        }
        $out = array();
        foreach ($this->db->query("SELECT * FROM `" . DB_PREFIX . "cc_shipments` WHERE `order_id` IN (" . $ids . ")")->rows as $row) {
            $out[(int)$row['order_id']] = $row;
        }
        return $out;
    }

    /** @return array order_id => ['shipping_method' => title, 'shipping_code' => code] */
    public function getOrderShippingRows(array $order_ids) {
        $ids = $this->intList($order_ids);
        if ($ids === '') {
            return array();
        }
        $out = array();
        foreach ($this->db->query("SELECT `order_id`, `shipping_method`, `shipping_code` FROM `" . DB_PREFIX . "order` WHERE `order_id` IN (" . $ids . ")")->rows as $row) {
            $out[(int)$row['order_id']] = $row;
        }
        return $out;
    }

    public function getOrderCustomField($order_id) {
        $q = $this->db->query("SELECT `custom_field` FROM `" . DB_PREFIX . "order` WHERE `order_id` = '" . (int)$order_id . "' LIMIT 1");
        $cf = $q->num_rows ? json_decode((string)$q->row['custom_field'], true) : array();
        return is_array($cf) ? $cf : array();
    }

    /** Remove the customer's BOX NOW choice from the order (custom_field). */
    public function clearOrderBoxnow($order_id) {
        $cf = $this->getOrderCustomField($order_id);
        foreach (array('cc_locker_id', 'cc_locker_name', 'cc_locker_code', 'cc_delivery_mode') as $key) {
            unset($cf[$key]);
        }
        $this->db->query("UPDATE `" . DB_PREFIX . "order` SET `custom_field` = '" . $this->db->escape(json_encode($cf)) . "' WHERE `order_id` = '" . (int)$order_id . "'");
    }

    public function voidShipment($order_id) {
        $this->db->query("UPDATE `" . DB_PREFIX . "cc_shipments` SET `is_voided` = 1 WHERE `order_id` = '" . (int)$order_id . "'");
    }

    public function removeBoxNow($order_id) {
        $this->db->query("UPDATE `" . DB_PREFIX . "cc_shipments` SET `is_boxnow` = 0, `locker_id` = '', `locker_code` = '', `locker_name` = '' WHERE `order_id` = '" . (int)$order_id . "'");
    }

    public function countShipmentsForDate($date) {
        $q = $this->db->query("SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "cc_shipments` WHERE DATE(`created_at`) = '" . $this->db->escape($date) . "' AND `voucher_number` != '' AND `is_voided` = 0");
        return (int)($q->row['total'] ?? 0);
    }

    private function intList(array $ids) {
        $clean = array();
        foreach ($ids as $id) {
            if ((int)$id > 0) {
                $clean[(int)$id] = (int)$id;
            }
        }
        return implode(',', $clean);
    }
}
