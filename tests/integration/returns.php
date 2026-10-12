<?php
/**
 * Integration test of the shared library on a REAL MySQL/MariaDB (XAMPP): schema, lock, voucher
 * service and the returns flow, with a fake API. Uses (and drops) the database cc_parity_test.
 * Usage: php tests/integration/returns.php
 */
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
require dirname(__DIR__) . '/bootstrap.php';
require CC_REPO . '/tests/support/FakeConfig.php';
require CC_REPO . '/tests/support/FakeApi.php';
require CC_REPO . '/tools/lib/common.php';

use Opencart\Extension\Couriercenter\Library\CCDbSchema;
use Opencart\Extension\Couriercenter\Library\CCLock;
use Opencart\Extension\Couriercenter\Library\CCReturnsRules as R;
use Opencart\Extension\Couriercenter\Library\CCReturnsService;
use Opencart\Extension\Couriercenter\Library\CCReturnsTracker;
use Opencart\Extension\Couriercenter\Library\CCVoucherService;

/** OpenCart-shaped DB on mysqli (query() throws on error, like OpenCart's drivers). */
class ItDb {
    public $c;
    public function __construct(array $m, $db) {
        mysqli_report(MYSQLI_REPORT_OFF);
        $this->c = new mysqli($m['host'], $m['user'], $m['pass'], '', (int)$m['port']);
        $this->c->set_charset('utf8mb4');
        $this->c->query("DROP DATABASE IF EXISTS `$db`");
        $this->c->query("CREATE DATABASE `$db` CHARACTER SET utf8mb4");
        $this->c->select_db($db);
    }
    public function query($sql) {
        $r = $this->c->query($sql);
        if ($r === false) {
            throw new Exception('Error: ' . $this->c->error . ' Error No: ' . $this->c->errno . ' ' . $sql);
        }
        $o = new stdClass();
        $o->rows = $r instanceof mysqli_result ? $r->fetch_all(MYSQLI_ASSOC) : array();
        $o->row = $o->rows ? $o->rows[0] : array();
        $o->num_rows = count($o->rows);
        return $o;
    }
    public function escape($v) {
        return $this->c->real_escape_string((string)$v);
    }
    public function getLastId() {
        return $this->c->insert_id;
    }
    public function countAffected() {
        return $this->c->affected_rows;
    }
}

$ok = 0;
$fail = 0;
function check($cond, $label) {
    global $ok, $fail;
    if ($cond) {
        $ok++;
        echo "  ok   $label\n";
    } else {
        $fail++;
        echo "  FAIL $label\n";
    }
}

$cfg = cc_config();
$db = new ItDb($cfg['mysql'], 'cc_parity_test');
$P = 'oc_';
$db->query("CREATE TABLE `{$P}setting` (`setting_id` INT AUTO_INCREMENT PRIMARY KEY, `store_id` INT NOT NULL DEFAULT 0, `code` VARCHAR(128) NOT NULL, `key` VARCHAR(128) NOT NULL, `value` TEXT NOT NULL, `serialized` TINYINT(1) NOT NULL DEFAULT 0)");
$db->query("CREATE TABLE `{$P}order` (`order_id` INT AUTO_INCREMENT PRIMARY KEY, `customer_id` INT NOT NULL DEFAULT 0, `order_status_id` INT NOT NULL DEFAULT 0, `firstname` VARCHAR(32), `lastname` VARCHAR(32), `email` VARCHAR(96), `telephone` VARCHAR(32),
    `shipping_firstname` VARCHAR(32), `shipping_lastname` VARCHAR(32), `shipping_company` VARCHAR(60) DEFAULT '', `shipping_address_1` VARCHAR(128), `shipping_address_2` VARCHAR(128) DEFAULT '', `shipping_city` VARCHAR(128), `shipping_postcode` VARCHAR(10),
    `payment_code` VARCHAR(128) DEFAULT 'bank_transfer', `comment` TEXT, `total` DECIMAL(15,4) DEFAULT 10, `date_added` DATETIME, `date_modified` DATETIME)");
$db->query("CREATE TABLE `{$P}order_product` (`order_product_id` INT AUTO_INCREMENT PRIMARY KEY, `order_id` INT, `product_id` INT, `name` VARCHAR(255), `model` VARCHAR(64), `quantity` INT)");
$db->query("CREATE TABLE `{$P}product` (`product_id` INT AUTO_INCREMENT PRIMARY KEY, `weight` DECIMAL(15,8) DEFAULT 0.5, `weight_class_id` INT DEFAULT 1, `length` DECIMAL(15,8) DEFAULT 0, `width` DECIMAL(15,8) DEFAULT 0, `height` DECIMAL(15,8) DEFAULT 0, `length_class_id` INT DEFAULT 1)");
$db->query("INSERT INTO `{$P}product` (`product_id`) VALUES (1), (2)");
foreach (array('weight_class' => 'kg', 'length_class' => 'cm') as $t => $unit) {
    $db->query("CREATE TABLE `{$P}$t` (`{$t}_id` INT PRIMARY KEY, `value` DECIMAL(15,8))");
    $db->query("CREATE TABLE `{$P}{$t}_description` (`{$t}_id` INT, `language_id` INT, `title` VARCHAR(32), `unit` VARCHAR(4))");
    $db->query("INSERT INTO `{$P}$t` VALUES (1, 1)");
    $db->query("INSERT INTO `{$P}{$t}_description` VALUES (1, 1, '$unit', '$unit')");
}
$db->query("CREATE TABLE `{$P}order_history`(`order_history_id` INT AUTO_INCREMENT PRIMARY KEY, `order_id` INT, `order_status_id` INT, `notify` TINYINT(1), `comment` TEXT, `date_added` DATETIME)");

echo "Schema\n";
$done = CCDbSchema::ensure($db, $P);
check(count($done) === 3, 'ensure creates 3 tables on MySQL');
check(CCDbSchema::ensureOnce($db, $P) === array(), 'ensureOnce is a no-op afterwards');
$db->query("ALTER TABLE `{$P}cc_shipments` DROP COLUMN `boxnow_parcel_id`");
check(CCDbSchema::ensure($db, $P) === array('column added: cc_shipments.boxnow_parcel_id'), 'missing column re-added');

echo "Lock\n";
$l1 = new CCLock($db, $P);
$l2 = new CCLock($db, $P);
check($l1->acquire('voucher_1') && !$l2->acquire('voucher_1'), 'second acquire refused');
$l1->release('voucher_1');
check($l2->acquire('voucher_1'), 'free after release');
$db->query("UPDATE `{$P}cc_locks` SET `locked_at` = `locked_at` - 999");
check($l1->acquire('voucher_1'), 'stale lock taken over');

$config = new FakeConfig(array(
    'config_order_status_id' => '1', 'config_processing_status' => array('2'), 'config_complete_status' => array('5'),
    'shipping_courier_center_user_alias' => 'alias', 'shipping_courier_center_credential_value' => 'secret', 'shipping_courier_center_api_key' => 'key',
    'shipping_courier_center_billing_account' => '100-0120-0004', 'shipping_courier_center_shipper_name' => 'SHOP', 'shipping_courier_center_shipper_address' => 'Οδός 1',
    'shipping_courier_center_shipper_postcode' => '12241', 'shipping_courier_center_shipper_city' => 'ΑΙΓΑΛΕΩ', 'shipping_courier_center_shipper_phone' => '2101234567',
    'shipping_courier_center_pickup_points' => array(array('code' => '100-0120-0001', 'label' => 'Κηφισός', 'bill_to' => 'main', 'name' => 'ΚΗΦΙΣΟΣ', 'address' => 'Κηφισού 46', 'postal' => '12241', 'city' => 'ΑΙΓΑΛΕΩ', 'phone' => '')),
    'shipping_courier_center_returns_enabled' => '1', 'shipping_courier_center_returns_require_approval' => '1',
    'shipping_courier_center_returns_method_dropoff' => '1', 'shipping_courier_center_returns_method_boxnow' => '1',
    'shipping_courier_center_returns_accounts' => array(array('original' => '100-0120-0001', 'return' => '100-0120-0009')),
));

echo "Voucher service\n";
$db->query("INSERT INTO `{$P}order` SET `order_id` = 10, `customer_id` = 3, `order_status_id` = 2, `firstname` = 'Μαρία', `lastname` = 'Κ', `email` = 'm@x.gr', `telephone` = '6912345678', `shipping_firstname` = 'Μαρία', `shipping_lastname` = 'Κ',
    `shipping_address_1` = 'Σόλωνος 5', `shipping_city` = 'ΑΘΗΝΑ', `shipping_postcode` = '10671', `comment` = 'Κουδούνι Κ', `date_added` = NOW(), `date_modified` = NOW()");
$db->query("INSERT INTO `{$P}order_product` SET `order_id` = 10, `product_id` = 1, `name` = 'Μπλούζα', `model` = 'B1', `quantity` = 3");
$db->query("INSERT INTO `{$P}order_product` SET `order_id` = 10, `product_id` = 2, `name` = 'Παντελόνι', `model` = 'P1', `quantity` = 1");
$api = new FakeApi(array('create_shipment' => array('Result' => 'Success', 'ShipmentNumber' => 'AWB10')));
$svc = new CCVoucherService($db, $config, $P, $api);
$order = $db->query("SELECT * FROM `{$P}order` WHERE `order_id` = 10")->row;
$r = $svc->createForOrder($order, array('pickup_point' => '100-0120-0001'));
check($r['success'] && $r['pickup_point'] === '100-0120-0001', 'voucher from sub-code');
$sh = $svc->getShipment(10);
check($sh['pickup_point'] === '100-0120-0001' && $sh['requestor_account'] === '100-0120-0004', 'point + requestor stored');
check($api->calls[0][1][0]['comments'] === 'Κουδούνι Κ', 'customer note in comments');
$r2 = $svc->createForOrder($order, array());
check(!$r2['success'] && $r2['stage'] === 'exists', 'second voucher refused (exists)');
check((int)$db->query("SELECT COUNT(*) n FROM `{$P}cc_locks`")->row['n'] === 1, 'only the stale test lock remains (voucher lock released)');

echo "Returns: eligibility + submit\n";
$events = array();
$logs = array();
$api = new FakeApi();
$mk = function () use ($db, $P, $config, &$api, &$events, &$logs) {
    return new CCReturnsService($db, $P, $config, $api, function ($e, $id) use (&$events) {
        $events[] = "$e:$id";
    }, function ($level, $m) use (&$logs) {
        $logs[] = "$level $m";
    });
};
$rs = $mk();
$order = $db->query("SELECT * FROM `{$P}order` WHERE `order_id` = 10")->row;
check($rs->checkEligibility($order, 3)['code'] === 'not_completed', 'processing order not eligible');
$db->query("UPDATE `{$P}order` SET `order_status_id` = 5 WHERE `order_id` = 10");
$db->query("INSERT INTO `{$P}order_history` SET `order_id` = 10, `order_status_id` = 5, `notify` = 0, `comment` = '', `date_added` = NOW()");
$order = $db->query("SELECT * FROM `{$P}order` WHERE `order_id` = 10")->row;
check($rs->checkEligibility($order, 4)['code'] === 'forbidden', 'other customer forbidden');
check($rs->checkEligibility($order, 3)['ok'] === true, 'complete + active AWB eligible');
$db->query("UPDATE `{$P}order_history` SET `date_added` = NOW() - INTERVAL 20 DAY");
check($rs->checkEligibility($order, 3)['code'] === 'expired', 'window of 14 days expired');
$db->query("UPDATE `{$P}order_history` SET `date_added` = NOW()");
$items = $rs->availableItems($order);
$ids = array_keys($items);
$bad = $rs->submit($order, 3, array('request_uid' => 'uid-000001', 'method' => R::METHOD_DROPOFF, 'items' => array($ids[0] => '4'), 'name' => 'Μαρία', 'mobile' => '6912345678'));
check(!$bad['ok'] && $bad['code'] === 'qty_exceeds', 'quantity above ordered refused');
$s1 = $rs->submit($order, 3, array('request_uid' => 'uid-000001', 'method' => R::METHOD_DROPOFF, 'items' => array($ids[0] => '2', $ids[1] => '0'), 'name' => 'Μαρία', 'mobile' => '', 'landline' => '2101234567'));
check($s1['ok'] && $s1['id'] > 0, 'drop off request submitted');
$again = $rs->submit($order, 3, array('request_uid' => 'uid-000001', 'method' => R::METHOD_DROPOFF, 'items' => array($ids[0] => '1'), 'name' => 'Μαρία', 'mobile' => '6912345678'));
check($again['ok'] && $again['id'] === $s1['id'], 'same uid → same request');
check($rs->availableItems($order)[$ids[0]]['available'] === 1, '2 of 3 reserved');
check($events === array('received:' . $s1['id'], 'submitted:' . $s1['id']), 'received (customer) + submitted (shop) notifications');
$row = $rs->repo()->get($s1['id']);
check($row['status'] === R::ST_PENDING && $row['original_shipper_code'] === '100-0120-0001' && $row['original_awb'] === 'AWB10', 'pending row with original AWB + point');

echo "Returns: approve + create\n";
$details = function ($code) {
    return array('Result' => 'Success', 'ShipmentDetails' => array(array('ShipmentInfo' => array('ShipmentAwb' => 'AWB10', 'Shipper' => array('Code' => $code)))));
};
$api = new FakeApi(array('get_shipment_details' => $details('100-120-0001'), 'create_shipment' => array('Result' => 'Success', 'ShipmentNumber' => 'RET1')));
$rs = $mk();
$a = $rs->approve($s1['id'], 1);
check($a['ok'] && $a['row']['status'] === R::ST_CREATED && $a['row']['return_awb'] === 'RET1', 'approved → created');
$sent = $api->calls[1][1][0];
check($sent['Consignee'] === array('CarrierBillingAccount' => '100-0120-0009') && $sent['Requestor']['CarrierBillingAccount'] === '100-0120-0004', 'mapped consignee, main requestor');
check($sent['Shipper']['ZipCode'] === '91919' && $sent['Shipper']['Mobile1'] === '2101234567' && $sent['Reference1'] === 'AWB10', 'drop off shipper + reference1');
check(in_array('created:' . $s1['id'], $events, true), 'created notification');
check(!$rs->approve($s1['id'], 1)['ok'], 'approve twice refused');

echo "Returns: timeout → verification → retry\n";
$s2 = $rs->submit($order, 3, array('request_uid' => 'uid-000002', 'method' => R::METHOD_BOXNOW, 'items' => array($ids[0] => '1'), 'name' => 'Μαρία', 'mobile' => '6912345678', 'locker_size' => 'medium'));
check($s2['ok'], 'boxnow request submitted');
$api = new FakeApi(array(
    'get_shipment_details' => function ($id) use ($details) {
        return $id === 'AWB10' ? $details('100-0120-0004') : array('Result' => 'Failure', 'Errors' => array(array('Message' => 'No shipments found with identifier ' . $id)));
    },
    'create_shipment' => array('success' => false, 'error' => 'Operation timed out', 'exception' => true),
));
$rs = $mk();
$c = $rs->approve($s2['id'], 1);
$row = $rs->repo()->get($s2['id']);
check(!$c['ok'] && $row['status'] === R::ST_VERIFY && strpos($row['last_error'], 'timed out') !== false, 'timeout → needs_verification (recent: kept)');
check($rs->create($s2['id'])['code'] === 'unverified', 'create on needs_verification only verifies');
$db->query("UPDATE `{$P}cc_return_requests` SET `locked_at` = UTC_TIMESTAMP() - INTERVAL 10 MINUTE WHERE `id` = " . (int)$s2['id']);
$v = $rs->verify($s2['id']);
check(!$v['ok'] && $rs->repo()->get($s2['id'])['status'] === R::ST_FAILED, 'not found after TTL → creation_failed');
$api = new FakeApi(array(
    'get_shipment_details' => function ($id) use ($details) {
        return $id === 'AWB10' ? $details('100-0120-0004') : array('Result' => 'Success', 'ShipmentDetails' => array(array('ShipmentInfo' => array('ShipmentAwb' => 'RET2', 'CollectionStatus' => 2, 'Reference4' => '5500486590', 'InboundAwb' => 'PARCELID5500486590'))));
    },
));
$rs = $mk();
$c = $rs->create($s2['id']);
check($c['ok'] && $c['row']['return_awb'] === 'RET2' && $c['row']['return_shipment_number'] === '5500486590', 'retry finds the late shipment by token (no 2nd create)');
check(count(array_filter($api->calls, function ($x) { return $x[0] === 'create_shipment'; })) === 0, 'no create_shipment on retry');

echo "Returns: reject + no approval + tracking\n";
$s3 = $rs->submit($order, 3, array('request_uid' => 'uid-000003', 'method' => R::METHOD_DROPOFF, 'items' => array($ids[1] => '1'), 'name' => 'Μαρία', 'mobile' => '6912345678'));
check($rs->reject($s3['id'], 1)['ok'] && $rs->availableItems($order)[$ids[1]]['available'] === 1, 'rejected request frees the quantity');
$config->set('shipping_courier_center_returns_require_approval', '0');
$api = new FakeApi(array('get_shipment_details' => $details('100-0120-0004'), 'create_shipment' => array('Result' => 'Success', 'ShipmentNumber' => 'RET4')));
$events = array();
$rs = $mk();
$s4 = $rs->submit($order, 3, array('request_uid' => 'uid-000004', 'method' => R::METHOD_DROPOFF, 'items' => array($ids[1] => '1'), 'name' => 'Μαρία', 'mobile' => '6912345678'));
check($rs->repo()->get($s4['id'])['status'] === R::ST_CREATED && $events === array('created:' . $s4['id'], 'submitted:' . $s4['id']), 'no approval: created at once, only the created email (+ shop email)');
$api->responses['get_shipment_details'] = array('Result' => 'Success', 'ShipmentDetails' => array(array('ShipmentInfo' => array('ShipmentAwb' => 'RET4', 'ShipmentStatus' => '15', 'ShipmentStatusDesc' => 'X', 'CollectionStatus' => '10', 'Reference3' => 'άσκοπη'))));
$t = (new CCReturnsTracker($rs))->refresh($s4['id']);
check($t['ok'] && $t['row']['display_status'] === R::ASKOPI_STATUS, 'tracking applies ΑΣΚΟΠΗ ΠΡΟΣΕΛΕΥΣΗ');
check(strpos(implode("\n", $logs), 'secret') === false, 'credentials never logged');
$hist = $db->query("SELECT `comment` FROM `{$P}order_history` WHERE `order_id` = 10 ORDER BY `order_history_id`")->rows;
check(strpos(implode("\n", array_column($hist, 'comment')), '↩️ Επιστροφή #' . $s1['id'] . ': AWB RET1') !== false, 'order history notes');

echo "Returns: Pickup\n";
$config->set('shipping_courier_center_returns_require_approval', '1');
$config->set('shipping_courier_center_returns_method_pickup', '1');
$db->query("INSERT INTO `{$P}order_product` SET `order_id` = 10, `product_id` = 2, `name` = 'Κάλτσες', `model` = 'K1', `quantity` = 5");
$order = $db->query("SELECT * FROM `{$P}order` WHERE `order_id` = 10")->row;
$ids = array_keys($rs->availableItems($order));
$dates = R::pickup_dates(R::now_local());
$api = new FakeApi(array('get_shipment_details' => $details('100-0120-0001'), 'create_shipment' => array('Result' => 'Success', 'ShipmentNumber' => 'RET5')));
$events = array();
$rs = $mk();
$pk = array('request_uid' => 'uid-000005', 'method' => R::METHOD_PICKUP, 'items' => array($ids[2] => '2'), 'name' => 'Μαρία', 'mobile' => '6912345678',
    'pickup_address' => 'Ακαδημίας 10', 'pickup_city' => 'Αθήνα', 'pickup_postcode' => '106 79', 'pickup_date' => $dates[1], 'pickup_time_from' => '10:00', 'pickup_time_to' => '14:00',
    'courier_notes' => 'Κουδούνι <b>Κ</b>');
check($rs->submit($order, 3, array_merge($pk, array('pickup_date' => '2020-01-01')))['code'] === 'pickup_date', 'past pickup date refused');
check($rs->submit($order, 3, array_merge($pk, array('pickup_time_to' => '12:00')))['code'] === 'pickup_window', 'window under 4 hours refused');
check($rs->submit($order, 3, array_merge($pk, array('pickup_postcode' => '123')))['code'] === 'invalid_postcode', 'bad postcode refused');
$s5 = $rs->submit($order, 3, $pk);
$row = $rs->repo()->get($s5['id']);
check($s5['ok'] && $row['pickup_date'] === $dates[1] && $row['pickup_time_from'] === '10:00' && $row['shipper_details']['postcode'] === '10679'
    && $row['shipper_details']['courier_notes'] === 'Κουδούνι Κ', 'pickup request stored (address, date, window, notes)');
// Late approval: the stored date has passed → the next working day
$db->query("UPDATE `{$P}cc_return_requests` SET `pickup_date` = '2020-01-06' WHERE `id` = " . (int)$s5['id']);
$a = $rs->approve($s5['id'], 1);
$sent = $api->calls[1][1][0];
check($a['ok'] && $a['row']['pickup_date'] === R::next_working_day(R::now_local()) && $sent['ShipmentDate'] === $a['row']['pickup_date'], 'late approval: pickup date moved to the next working day');
check($sent['CollectionInstructions'] === array('TimeFrom' => '10:00', 'TimeTo' => '14:00', 'Comments' => 'Κουδούνι Κ') && $sent['Shipper']['ZipCode'] === '10679', 'pickup payload');
check($sent['BillTo'] === 'Requestor', 'point billed to the main code → Requestor');
$cfgPoints = $config->get('shipping_courier_center_pickup_points');
$cfgPoints[0]['bill_to'] = 'self';
$config->set('shipping_courier_center_pickup_points', $cfgPoints);
$s6 = $rs->submit($order, 3, array_merge($pk, array('request_uid' => 'uid-000006', 'items' => array($ids[2] => '1'))));
$api = new FakeApi(array('get_shipment_details' => $details('100-0120-0001'), 'create_shipment' => array('Result' => 'Success', 'ShipmentNumber' => 'RET6')));
$rs = $mk();
$rs->approve($s6['id'], 1);
check($api->calls[1][1][0]['BillTo'] === 'Consignee', 'sub-code billed to itself → Consignee');
$cfgPoints[0]['bill_to'] = 'main';
$config->set('shipping_courier_center_pickup_points', $cfgPoints);

echo "Returns: shop-created\n";
$db->query("INSERT INTO `{$P}order` SET `order_id` = 11, `customer_id` = 0, `order_status_id` = 2, `firstname` = 'Γιώργος', `lastname` = 'Π', `email` = 'g@x.gr', `telephone` = '2101234567', `shipping_firstname` = 'Γιώργος', `shipping_lastname` = 'Π',
    `shipping_address_1` = 'Πατησίων 1', `shipping_city` = 'ΑΘΗΝΑ', `shipping_postcode` = '10431', `comment` = '', `date_added` = NOW(), `date_modified` = NOW()");
$db->query("INSERT INTO `{$P}order_product` SET `order_id` = 11, `product_id` = 1, `name` = 'Μπλούζα', `model` = 'B1', `quantity` = 1");
$order11 = $db->query("SELECT * FROM `{$P}order` WHERE `order_id` = 11")->row;
$i11 = array_keys($rs->availableItems($order11));
$sc = array('request_uid' => 'shop-000001', 'method' => R::METHOD_BOXNOW, 'items' => array($i11[0] => '1'), 'name' => 'Γιώργος', 'mobile' => '6987654321', 'locker_size' => 'small', 'notify' => '0');
check($rs->shopCreate($order11, 1, $sc)['code'] === 'no_shipment', 'shop return needs an active voucher');
$db->query("INSERT INTO `{$P}cc_shipments` SET `order_id` = 11, `voucher_number` = 'AWB11', `is_voided` = 1, `created_at` = NOW()");
check($rs->shopCreate($order11, 1, $sc)['code'] === 'no_shipment', 'voided voucher is not enough');
check(!$rs->hasActiveAwb(11), 'hasActiveAwb false for voided');
$db->query("UPDATE `{$P}cc_shipments` SET `is_voided` = 0, `pickup_point` = '' WHERE `order_id` = 11");
$config->set('shipping_courier_center_returns_enabled', '0'); // the shop does not depend on the customer's settings
$api = new FakeApi(array('get_shipment_details' => array('Result' => 'Success', 'ShipmentDetails' => array(array('ShipmentInfo' => array('ShipmentAwb' => 'AWB11', 'Shipper' => array('Code' => '100-0120-0004'))))),
    'create_shipment' => array('Result' => 'Success', 'ShipmentNumber' => 'RET7', 'ReturnShipmentNumber' => '9999')));
$events = array();
$rs = $mk();
$c = $rs->shopCreate($order11, 7, $sc);
$row = $rs->repo()->get($c['id']);
check($c['ok'] && $row['status'] === R::ST_CREATED && $row['origin'] === 'shop' && $row['notify_customer'] === 0 && $row['decided_by'] === 7, 'shop return created at once (origin shop, no approval, processing order)');
check($events === array(), 'notify off → no customer email, no shop email');
$again = $rs->shopCreate($order11, 7, $sc);
check($again['ok'] && $again['id'] === $c['id'], 'same uid → same shop return');
$db->query("INSERT INTO `{$P}order_product` SET `order_id` = 11, `product_id` = 2, `name` = 'Παντελόνι', `model` = 'P1', `quantity` = 1");
$i11 = array_keys($rs->availableItems($order11));
$api = new FakeApi(array('get_shipment_details' => array('Result' => 'Failure', 'Errors' => array(array('Message' => 'down')))));
$rs = $mk();
$c2 = $rs->shopCreate($order11, 7, array_merge($sc, array('request_uid' => 'shop-000002', 'method' => R::METHOD_DROPOFF, 'items' => array($i11[1] => '1'), 'notify' => '1')));
check($c2['ok'] && $rs->repo()->get($c2['id'])['status'] === R::ST_FAILED, 'shop return kept when the creation fails');
check($rs->reject($c2['id'], 7)['ok'] && $events === array(), 'rejecting a shop return sends no "rejected" email');
check($rs->repo()->pendingCount() === 0, 'pendingCount');
$db->query("UPDATE `{$P}cc_return_requests` SET `status` = '" . R::ST_PENDING . "' WHERE `id` = " . (int)$c2['id']);
check($rs->repo()->pendingCount() === 1, 'pendingCount counts pending');
$sum = $rs->repo()->summaryForOrders(array(10, 11, 12));
check($sum[11]['count'] === 2 && $sum[11]['status'] === R::ST_PENDING && !isset($sum[12]) && $sum[10]['count'] === 6, 'summaryForOrders: newest status + count');

echo "Schema v2 → v3 upgrade\n";
$db->query("ALTER TABLE `{$P}cc_return_requests` DROP COLUMN `origin`, DROP COLUMN `notify_customer`, DROP COLUMN `pickup_date`, DROP COLUMN `pickup_time_from`, DROP COLUMN `pickup_time_to`");
$db->query("UPDATE `{$P}setting` SET `value` = '2' WHERE `key` = '" . CCDbSchema::STATE_KEY . "'");
$up = CCDbSchema::ensureOnce($db, $P);
check(count($up) === 5 && $db->query("SELECT `origin`, `notify_customer` FROM `{$P}cc_return_requests` WHERE `id` = " . (int)$s1['id'])->row === array('origin' => 'customer', 'notify_customer' => '1'), 'upgrade adds 5 columns, old rows = customer + notify');

$db->query("DROP DATABASE `cc_parity_test`");
echo "\n$ok ok, $fail failed\n";
exit($fail ? 1 : 0);
