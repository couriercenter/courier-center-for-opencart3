<?php
namespace Opencart\Extension\Couriercenter\Oc3;

/**
 * Internal state of the extension (cron key, timestamps, update cache, test overrides).
 * Stored in the setting table under its OWN code, so OpenCart's
 * editSetting('shipping_courier_center') - run on every Save - and the uninstall
 * never delete it, while $config->get() still sees it (spec 5.5).
 */
class CCState {
    const CODE   = 'courier_center_state';
    const PREFIX = 'shipping_courier_center_';

    private $db;
    private $prefix;

    public function __construct($db, $prefix) {
        $this->db = $db;
        $this->prefix = (string)$prefix;
    }

    public function get($key, $default = '') {
        $q = $this->db->query("SELECT `value` FROM `" . $this->prefix . "setting` WHERE `store_id` = '0' AND `key` = '" . $this->db->escape(self::PREFIX . $key) . "' LIMIT 1");
        return $q->num_rows ? (string)$q->row['value'] : (string)$default;
    }

    public function set($key, $value) {
        self::upsertSetting($this->db, $this->prefix, self::CODE, self::PREFIX . $key, $value);
    }

    public function delete($key) {
        $this->db->query("DELETE FROM `" . $this->prefix . "setting` WHERE `store_id` = '0' AND `code` = '" . self::CODE . "' AND `key` = '" . $this->db->escape(self::PREFIX . $key) . "'");
    }

    /** Test-shop overrides (dashboard / release endpoints), JSON in state key dev_overrides. */
    public function devOverrides() {
        $raw = $this->get('dev_overrides', '');
        $data = $raw !== '' ? json_decode($raw, true) : null;
        return is_array($data) ? $data : array();
    }

    /** Random 32-hex key of the cron URL, created once. */
    public function ensureCronKey() {
        $key = $this->get('cron_key', '');
        if (!preg_match('/^[a-f0-9]{32}$/', $key)) {
            $key = bin2hex(random_bytes(16));
            $this->set('cron_key', $key);
        }
        return $key;
    }

    /** Write ONE OpenCart setting (store 0) without touching the other keys of its code. */
    public static function upsertSetting($db, $prefix, $code, $key, $value) {
        $k = $db->escape($key);
        $db->query("DELETE FROM `" . $prefix . "setting` WHERE `store_id` = '0' AND `key` = '" . $k . "'");
        $db->query("INSERT INTO `" . $prefix . "setting` SET `store_id` = '0', `code` = '" . $db->escape($code) . "', `key` = '" . $k . "', `value` = '" . $db->escape((string)$value) . "', `serialized` = '0'");
    }
}
