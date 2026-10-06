<?php
namespace Opencart\Extension\Couriercenter\Oc3;

/** Read-only config (store 0) for CLI scripts, where OpenCart's Config is not loaded. */
class CCConfigSnapshot {
    private $data = array();

    public function __construct($db, $prefix) {
        foreach ($db->query("SELECT `key`, `value`, `serialized` FROM `" . $prefix . "setting` WHERE `store_id` = '0'")->rows as $row) {
            $this->data[$row['key']] = !empty($row['serialized']) ? json_decode($row['value'], true) : $row['value'];
        }
    }

    public function get($key) {
        return array_key_exists($key, $this->data) ? $this->data[$key] : null;
    }
}
