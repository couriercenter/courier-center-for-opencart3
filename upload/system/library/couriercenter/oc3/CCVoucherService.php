<?php
namespace Opencart\Extension\Couriercenter\Oc3;

use Opencart\Extension\Couriercenter\Library\CCApi;
use Opencart\Extension\Couriercenter\Library\CCCityScope;
use Opencart\Extension\Couriercenter\Library\CCOrderAdapter;
use Opencart\Extension\Couriercenter\Library\CCOrderHistory;
use Opencart\Extension\Couriercenter\Library\CCShipmentBuilder;
use Opencart\Extension\Couriercenter\Library\CCStatusTracker;

/**
 * Voucher creation for OpenCart 3: ONE implementation for the order panel, the bulk
 * actions and the auto-create event (the OC4 extension keeps two diverging copies).
 * Ports ccCreateVoucherForOrder / ccEnrichProducts / ccConvertClass / ccIsHandledOrder /
 * ccBoxnowFromOrder of admin/controller/shipping/courier_center.php (OC4).
 */
class CCVoucherService {
    const P = 'shipping_courier_center_';

    private $db;
    private $config;
    private $prefix;
    private $api;
    private $class_cache = array();

    /** @param object $db OpenCart DB  @param object $config OpenCart Config  @param CCApi|null $api tests inject a fake */
    public function __construct($db, $config, $prefix, $api = null) {
        $this->db = $db;
        $this->config = $config;
        $this->prefix = (string)$prefix;
        $this->api = $api;
    }

    /** Setting value; strings are HTML-decoded (OpenCart stores form input escaped, spec 10 #13). */
    public function setting($key) {
        $v = $this->config->get(self::P . $key);
        if ($v === null) {
            return '';
        }
        return is_array($v) ? $v : html_entity_decode((string)$v, ENT_QUOTES, 'UTF-8');
    }

    public function api() {
        if ($this->api === null) {
            $this->api = new CCApi((string)$this->setting('user_alias'), (string)$this->setting('credential_value'), (string)$this->setting('api_key'), (string)$this->setting('billing_account'));
        }
        return $this->api;
    }

    public function builderSettings() {
        $postcode = (string)$this->setting('shipper_postcode');
        return array(
            'user_alias'       => (string)$this->setting('user_alias'),
            'credential_value' => (string)$this->setting('credential_value'),
            'api_key'          => (string)$this->setting('api_key'),
            'billing_account'  => (string)$this->setting('billing_account'),
            'shipper_name'     => (string)$this->setting('shipper_name'),
            'shipper_address'  => (string)$this->setting('shipper_address'),
            'shipper_postal'   => $postcode,
            'shipper_city'     => (string)$this->setting('shipper_city'),
            'shipper_phone'    => (string)$this->setting('shipper_phone'),
            'shipper_station'  => CCCityScope::get_station_for_postcode($postcode),
        );
    }

    /** "courier_center.courier_center" -> "courier_center" (OpenCart 3 keeps it in shipping_code). */
    public static function shippingCode(array $order) {
        $full = (string)($order['shipping_code'] ?? '');
        $dot = strpos($full, '.');
        return $dot === false ? $full : substr($full, 0, $dot);
    }

    public function handledMethods() {
        $h = $this->setting('handled_shipping_methods');
        if (!is_array($h)) {
            return array();
        }
        return array_values(array_filter(array_map('strval', $h), 'strlen'));
    }

    /** Does the extension manage this order's shipping method? Empty setting = every order. */
    public function isHandledOrder(array $order) {
        $handled = $this->handledMethods();
        return empty($handled) || in_array(self::shippingCode($order), $handled, true);
    }

    /** BOX NOW choice saved at checkout in the order's custom_field (array or JSON string). */
    public static function boxnowFromOrder(array $order) {
        $cf = $order['custom_field'] ?? array();
        if (!is_array($cf)) {
            $cf = json_decode((string)$cf, true);
            if (!is_array($cf)) {
                $cf = array();
            }
        }
        $locker_id = (string)($cf['cc_locker_id'] ?? '');
        if ($locker_id === '' && (string)($cf['cc_delivery_mode'] ?? '') !== 'auto') {
            return array();
        }
        return array(
            'boxnow'      => true,
            'locker_id'   => $locker_id,
            'locker_code' => (string)($cf['cc_locker_code'] ?? ''),
            'locker_name' => (string)($cf['cc_locker_name'] ?? ''),
        );
    }

    public function orderProducts($order_id) {
        return $this->db->query("SELECT `product_id`, `quantity` FROM `" . $this->prefix . "order_product` WHERE `order_id` = '" . (int)$order_id . "'")->rows;
    }

    /** Weight in kg and dimensions in cm from the product table (oc_order_product has none). */
    public function enrichProducts(array $products) {
        foreach ($products as $i => $p) {
            $pid = (int)($p['product_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $q = $this->db->query("SELECT `weight`, `weight_class_id`, `length`, `width`, `height`, `length_class_id` FROM `" . $this->prefix . "product` WHERE `product_id` = '" . $pid . "' LIMIT 1");
            if (!$q->num_rows) {
                continue;
            }
            $wc = (int)$q->row['weight_class_id'];
            $lc = (int)$q->row['length_class_id'];
            $products[$i]['weight'] = $this->convertClass($q->row['weight'], $wc, 'weight_class', 'kg');
            $products[$i]['length'] = $this->convertClass($q->row['length'], $lc, 'length_class', 'cm');
            $products[$i]['width']  = $this->convertClass($q->row['width'], $lc, 'length_class', 'cm');
            $products[$i]['height'] = $this->convertClass($q->row['height'], $lc, 'length_class', 'cm');
        }
        return $products;
    }

    /** Convert from an OpenCart weight/length class to kg / cm; raw value when a class is unknown. */
    public function convertClass($value, $from_class_id, $table, $unit) {
        $value = (float)$value;
        $from_class_id = (int)$from_class_id;
        if ($value <= 0 || $from_class_id <= 0) {
            return $value;
        }
        $key = $table . '|' . $unit;
        if (!array_key_exists($key, $this->class_cache)) {
            $r = $this->db->query("SELECT c.`" . $table . "_id` AS id, c.`value` AS val FROM `" . $this->prefix . $table . "` c JOIN `" . $this->prefix . $table . "_description` d ON c.`" . $table . "_id` = d.`" . $table . "_id` WHERE LOWER(TRIM(d.`unit`)) = '" . $this->db->escape($unit) . "' LIMIT 1");
            $this->class_cache[$key] = $r->num_rows ? array('id' => (int)$r->row['id'], 'val' => (float)$r->row['val']) : null;
        }
        $target = $this->class_cache[$key];
        if (!$target || $from_class_id === $target['id']) {
            return $value;
        }
        $f = $this->db->query("SELECT `value` FROM `" . $this->prefix . $table . "` WHERE `" . $table . "_id` = '" . $from_class_id . "' LIMIT 1");
        $from_val = $f->num_rows ? (float)$f->row['value'] : 0.0;
        return $from_val > 0 ? $value * ($target['val'] / $from_val) : $value;
    }

    public function getShipment($order_id) {
        $q = $this->db->query("SELECT * FROM `" . $this->prefix . "cc_shipments` WHERE `order_id` = '" . (int)$order_id . "' LIMIT 1");
        return $q->num_rows ? $q->row : array();
    }

    /** Store a NEW voucher. The row always starts active (spec 5.5 / 10 #3). */
    public function saveShipment($order_id, array $d) {
        $order_id = (int)$order_id;
        $set = array();
        foreach (array('voucher_number' => '', 'tracking_number' => '', 'service_type' => 'next_day', 'return_option' => 'none',
            'return_awb' => '', 'locker_id' => '', 'locker_code' => '', 'locker_name' => '') as $col => $default) {
            $set[] = '`' . $col . "` = '" . $this->db->escape((string)($d[$col] ?? $default)) . "'";
        }
        $set[] = "`is_boxnow` = '" . (!empty($d['is_boxnow']) ? 1 : 0) . "'";
        $set[] = "`is_voided` = '0'";
        $set[] = "`is_final` = '0'";
        $set[] = "`status_code` = ''";
        $set[] = "`status_desc` = ''";
        $set[] = '`last_checked_at` = NULL';
        $set[] = '`status_updated_at` = NULL';
        $set[] = '`created_at` = NOW()';
        if ($this->db->query("SELECT `id` FROM `" . $this->prefix . "cc_shipments` WHERE `order_id` = '" . $order_id . "' LIMIT 1")->num_rows) {
            $this->db->query("UPDATE `" . $this->prefix . "cc_shipments` SET " . implode(', ', $set) . " WHERE `order_id` = '" . $order_id . "'");
        } else {
            $this->db->query("INSERT INTO `" . $this->prefix . "cc_shipments` SET `order_id` = '" . $order_id . "', " . implode(', ', $set));
        }
    }

    public function history() {
        return new CCOrderHistory($this->db, $this->prefix);
    }

    public function tracker() {
        return new CCStatusTracker($this->db, $this->prefix, $this->api(), (int)$this->setting('auto_complete_status_id'));
    }

    /**
     * Create a voucher for one order.
     * @param array $order OpenCart 3 getOrder() row (admin or catalog model)
     * @param array $opts  service_type, parcel_count, return_option, boxnow, locker_id, locker_code, locker_name,
     *                     mode: 'manual' (order panel / bulk, default) | 'auto' (status-change event)
     * @return array ['success'=>true,'voucher','tracking','return_awb'] | ['success'=>false,'error','stage'=>'validation'|'api']
     */
    public function createForOrder(array $order, array $opts) {
        $order_id = (int)($order['order_id'] ?? 0);
        $codes = CCShipmentBuilder::SERVICE_CODES;
        $service = (string)($opts['service_type'] ?? 'next_day');
        if (!isset($codes[$service])) {
            $service = 'next_day';
        }
        $parcels = max(1, min(99, (int)($opts['parcel_count'] ?? 1)));
        $return_option = (string)($opts['return_option'] ?? 'none');
        if (!in_array($return_option, array('none', 'optional', 'mandatory'), true)) {
            $return_option = 'none';
        }
        $boxnow = !empty($opts['boxnow']);
        $locker_id = trim((string)($opts['locker_id'] ?? ''));
        $locker_code = trim((string)($opts['locker_code'] ?? ''));
        $locker_name = trim((string)($opts['locker_name'] ?? ''));
        $mode = (string)($opts['mode'] ?? 'manual');

        if ($order_id <= 0) {
            return array('success' => false, 'error' => 'Παραγγελία δε βρέθηκε', 'stage' => 'validation');
        }
        $adapter = new CCOrderAdapter($order, $this->enrichProducts($this->orderProducts($order_id)));
        if ($boxnow && $adapter->is_cod()) {
            return array('success' => false, 'stage' => 'validation',
                'error' => 'Το BOX NOW δεν υποστηρίζει αντικαταβολή. Άλλαξε τρόπο πληρωμής ή απενεργοποίησε το BOX NOW Locker.');
        }
        $builder = new CCShipmentBuilder($adapter, $this->builderSettings(), $parcels);
        foreach (array($builder->validate_settings(), $builder->validate_order()) as $check) {
            if ($check !== true) {
                return array('success' => false, 'error' => (string)$check, 'stage' => 'validation');
            }
        }

        $payload = $builder->build_payload($service, $boxnow, $return_option);
        if ($boxnow && $locker_id !== '') {
            $payload['LockerDeliveryInfo'] = array('Prefix' => 'ATH', 'Code' => $locker_id);
        }

        $result = $this->api()->create_shipment($payload);
        if (isset($result['success']) && $result['success'] === false) {
            return array('success' => false, 'error' => (string)($result['error'] ?? 'Άγνωστο σφάλμα από το API'), 'stage' => 'api');
        }

        $voucher = (string)($result['VoucherNumber'] ?? $result['AwbNumber'] ?? $result['ShipmentNumber'] ?? '');
        $tracking = (string)($result['TrackingNumber'] ?? $result['TrackingNo'] ?? ($result['TrackingNumbers'][0] ?? ''));
        if ($tracking === '') {
            $tracking = $voucher;
        }
        if ($voucher === '') {
            return array('success' => false, 'error' => 'Το API δεν επέστρεψε αριθμό voucher', 'stage' => 'api');
        }
        $return_awb = '';
        if ($return_option !== 'none') {
            $return_awb = (string)($result['ReturnShipmentNumber'] ?? $result['ReturnAWB'] ?? $result['ReturnVoucherNumber']
                ?? ($result['ReturnTrackingNumbers'][0] ?? null) ?? ($result['TrackingNumbers'][1] ?? ''));
        }

        // BOX NOW: "no locker found" fallback (door-to-door) or the locker the carrier assigned.
        $note_extra = '';
        if ($boxnow) {
            $contractor_note = (string)($result['ContractorResultNote'] ?? '');
            if (stripos($contractor_note, 'No locker found') !== false) {
                $note_extra = ' | ⚠️ Δεν βρέθηκε locker — αποστέλλεται door-to-door';
            } else {
                $assigned = (string)($result['AssignedLockerCode'] ?? $result['LockerCode'] ?? $result['DestinationLockerCode'] ?? ($result['LockerDeliveryInfo']['Code'] ?? ''));
                if ($assigned === '' && $contractor_note !== '' && preg_match('/locker[:\s#]*(\w+)/i', $contractor_note, $m)) {
                    $assigned = $m[1];
                }
                if ($assigned !== '') {
                    $note_extra = ' | 📦 Locker: ' . $assigned;
                    if ($locker_code === '') {
                        $locker_code = $assigned;
                    }
                }
            }
        }

        $this->saveShipment($order_id, array(
            'voucher_number' => $voucher, 'tracking_number' => $tracking, 'service_type' => $service,
            'return_option' => $return_option, 'return_awb' => $return_awb, 'is_boxnow' => $boxnow ? 1 : 0,
            'locker_id' => $locker_id, 'locker_code' => $locker_code, 'locker_name' => $locker_name,
        ));

        if ($mode === 'auto') {
            $note = '🤖 Αυτόματη δημιουργία voucher (αλλαγή status): ' . $voucher . ' | Υπηρεσία: ' . self::serviceLabel($service) . ($boxnow ? ' + BOX NOW' : '');
        } else {
            $note = '✅ Courier Center voucher: ' . $voucher . ' | Υπηρεσία: ' . self::serviceLabel($service)
                . ($boxnow ? ' + BOX NOW' : '') . ($return_option !== 'none' ? ' + Επιστροφικό' : '');
            if ($return_awb !== '') {
                $note .= ' | Return AWB: ' . $return_awb;
            }
        }
        $this->history()->add($order_id, $note . $note_extra);

        return array('success' => true, 'voucher' => $voucher, 'tracking' => $tracking, 'return_awb' => $return_awb);
    }

    public static function serviceLabel($service) {
        $labels = array('next_day' => 'Επόμενη Μέρα', 'same_day_3h' => 'Αυθημερόν 3ω', 'same_day_5h' => 'Αυθημερόν 5ω');
        return isset($labels[$service]) ? $labels[$service] : (string)$service;
    }
}
