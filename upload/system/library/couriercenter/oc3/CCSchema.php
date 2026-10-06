<?php
namespace Opencart\Extension\Couriercenter\Oc3;

/** cc_shipments table + the extension's OpenCart events: create / repair / remove (spec 5.4, 5.5, 5.9). */
class CCSchema {

    /** Same columns as the OC4 extension. MySQL 5.5-safe: no DATETIME DEFAULT CURRENT_TIMESTAMP. */
    public static function columns() {
        return array(
            'id'                => "INT(11) NOT NULL AUTO_INCREMENT",
            'order_id'          => "INT(11) NOT NULL",
            'voucher_number'    => "VARCHAR(64) DEFAULT NULL",
            'tracking_number'   => "VARCHAR(64) DEFAULT NULL",
            'service_type'      => "VARCHAR(32) DEFAULT NULL",
            'return_option'     => "VARCHAR(16) NOT NULL DEFAULT 'none'",
            'return_awb'        => "VARCHAR(64) DEFAULT NULL",
            'is_boxnow'         => "TINYINT(1) NOT NULL DEFAULT 0",
            'locker_id'         => "VARCHAR(64) DEFAULT NULL",
            'locker_code'       => "VARCHAR(64) DEFAULT NULL",
            'locker_name'       => "VARCHAR(255) DEFAULT NULL",
            'status_code'       => "VARCHAR(8) DEFAULT NULL",
            'status_desc'       => "VARCHAR(255) DEFAULT NULL",
            'is_voided'         => "TINYINT(1) NOT NULL DEFAULT 0",
            'is_final'          => "TINYINT(1) NOT NULL DEFAULT 0",
            'last_checked_at'   => "INT(11) DEFAULT NULL",
            'status_updated_at' => "DATETIME DEFAULT NULL",
            'created_at'        => "DATETIME DEFAULT NULL",
        );
    }

    /** Create the table, or add the columns a newer version needs. @return string[] what was done */
    public static function ensure($db, $prefix) {
        $table = $prefix . 'cc_shipments';
        if (!$db->query("SHOW TABLES LIKE '" . $db->escape($table) . "'")->num_rows) {
            $cols = array();
            foreach (self::columns() as $name => $def) {
                $cols[] = '`' . $name . '` ' . $def;
            }
            $cols[] = 'PRIMARY KEY (`id`)';
            $cols[] = 'UNIQUE KEY `order_id` (`order_id`)';
            $db->query("CREATE TABLE IF NOT EXISTS `" . $table . "` (" . implode(', ', $cols) . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            return array('table created');
        }
        $have = array();
        foreach ($db->query("SHOW COLUMNS FROM `" . $table . "`")->rows as $row) {
            $have[strtolower($row['Field'])] = true;
        }
        $done = array();
        foreach (self::columns() as $name => $def) {
            if ($name !== 'id' && !isset($have[$name])) {
                $db->query("ALTER TABLE `" . $table . "` ADD `" . $name . "` " . $def);
                $done[] = 'column added: ' . $name;
            }
        }
        return $done;
    }

    /** code => [trigger, action, sort_order] (spec 5.4) */
    public static function events() {
        return array(
            'courier_center_order_panel'   => array('admin/view/sale/order_info/before', 'extension/shipping/courier_center/order/orderTab', 0),
            'courier_center_order_list'    => array('admin/view/sale/order_list/after', 'extension/shipping/courier_center/order_list/injectColumns', 0),
            'courier_center_update_notice' => array('admin/view/common/header/after', 'extension/shipping/courier_center/update/notice', 0),
            'courier_center_auto_create'   => array('catalog/model/checkout/order/addOrderHistory/after', 'extension/shipping/courier_center/autocreate/autoCreate', 50),
            'courier_center_email'         => array('catalog/model/checkout/order/addOrderHistory/after', 'extension/shipping/courier_center/email/sendTracking', 99),
            'courier_center_boxnow_widget' => array('catalog/view/common/footer/after', 'extension/shipping/courier_center/boxnow/widget', 0),
            'courier_center_boxnow_order'  => array('catalog/model/checkout/order/addOrder/after', 'extension/shipping/courier_center/boxnow/saveToOrder', 0),
            'courier_center_boxnow_clear'  => array('catalog/controller/checkout/success/before', 'extension/shipping/courier_center/boxnow/clearSession', 0),
        );
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
        foreach (array_keys(self::events()) as $code) {
            $codes[] = "'" . $db->escape($code) . "'";
        }
        $db->query("DELETE FROM `" . $prefix . "event` WHERE `code` IN (" . implode(', ', $codes) . ")");
    }
}
