<?php
namespace Opencart\Extension\Couriercenter\Oc3;

/** Tables (via the library's CCDbSchema) + the extension's OpenCart events: create / repair / remove (spec 5.4, 5.5, 5.9). */
class CCSchema {

    /** cc_shipments columns (the tables live in the shared library: CCDbSchema). */
    public static function columns() {
        return \Opencart\Extension\Couriercenter\Library\CCDbSchema::columns('cc_shipments');
    }

    /** Create the tables, or add the columns a newer version needs. @return string[] what was done */
    public static function ensure($db, $prefix) {
        return \Opencart\Extension\Couriercenter\Library\CCDbSchema::ensure($db, $prefix);
    }

    /** code => [trigger, action, sort_order] (spec 5.4) */
    public static function events() {
        return array(
            'courier_center_order_panel'   => array('admin/view/sale/order_info/before', 'extension/shipping/courier_center/order/orderTab', 0),
            'courier_center_order_list'    => array('admin/view/sale/order_list/after', 'extension/shipping/courier_center/order_list/injectColumns', 0),
            'courier_center_update_notice' => array('admin/view/common/header/after', 'extension/shipping/courier_center/update/notice', 0),
            'courier_center_auto_create'   => array('catalog/model/checkout/order/addOrderHistory/after', 'extension/shipping/courier_center/autocreate/autoCreate', 50),
            'courier_center_mail_add'      => array('catalog/view/mail/order_add/after', 'extension/shipping/courier_center/email/orderAdd', 0),
            'courier_center_mail_edit'     => array('catalog/view/mail/order_edit/after', 'extension/shipping/courier_center/email/orderEdit', 0),
            'courier_center_returns_menu'  => array('admin/view/common/column_left/before', 'extension/shipping/courier_center/returns/menu', 0),
            'courier_center_returns_order' => array('catalog/view/account/order_info/after', 'extension/shipping/courier_center/returns/orderInfo', 0),
            'courier_center_returns_orders' => array('catalog/view/account/order_list/after', 'extension/shipping/courier_center/returns/orderList', 0),
            // «N αιτήματα επιστροφής χρειάζονται ενέργεια» on the dashboard and the order pages
            'courier_center_returns_note_dash'  => array('admin/view/common/dashboard/after', 'extension/shipping/courier_center/returns/notice', 0),
            'courier_center_returns_note_list'  => array('admin/view/sale/order_list/after', 'extension/shipping/courier_center/returns/notice', 1),
            'courier_center_returns_note_info'  => array('admin/view/sale/order_info/after', 'extension/shipping/courier_center/returns/notice', 0),
            'courier_center_pseudo_cron'   => array('catalog/view/common/footer/after', 'extension/shipping/courier_center/cron/tick', 5),
            'courier_center_boxnow_widget' => array('catalog/view/common/footer/after', 'extension/shipping/courier_center/boxnow/widget', 0),
            'courier_center_boxnow_order'  => array('catalog/model/checkout/order/addOrder/after', 'extension/shipping/courier_center/boxnow/saveToOrder', 0),
            'courier_center_boxnow_edit'   => array('catalog/model/checkout/order/editOrder/before', 'extension/shipping/courier_center/boxnow/keepOnEdit', 0),
            'courier_center_boxnow_clear'  => array('catalog/controller/checkout/success/before', 'extension/shipping/courier_center/boxnow/clearSession', 0),
        );
    }

    /** Changes when the event list changes: a new zip of the same version still re-registers them. */
    public static function eventsSignature() {
        return md5(json_encode(self::events()));
    }

    /** Delete + insert our events (direct SQL: same table layout in every OpenCart 3.0.x). */
    public static function ensureEvents($db, $prefix) {
        self::removeEvents($db, $prefix);
        foreach (self::events() as $code => $e) {
            $db->query("INSERT INTO `" . $prefix . "event` SET `code` = '" . $db->escape($code) . "', `trigger` = '" . $db->escape($e[0]) . "', `action` = '" . $db->escape($e[1]) . "', `status` = '1', `sort_order` = '" . (int)$e[2] . "'");
        }
        return array(count(self::events()) . ' events registered');
    }

    public static function removeEvents($db, $prefix) {
        $codes = array();
        // + the codes older versions registered (1.0.0: the separate tracking email)
        foreach (array_merge(array_keys(self::events()), array('courier_center_email')) as $code) {
            $codes[] = "'" . $db->escape($code) . "'";
        }
        $db->query("DELETE FROM `" . $prefix . "event` WHERE `code` IN (" . implode(', ', $codes) . ")");
    }
}
