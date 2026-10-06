<?php
/** One local OpenCart 3 shop, driven over HTTP like an admin, a customer and the admin "Add order" API client. */
class SmokeShop {
    public $id;
    public $env;
    public $admin;
    public $front;
    public $token = '';
    private $db = null;

    public function __construct(array $env) {
        $this->env = $env;
        $this->id = $env['id'];
        $this->admin = new SmokeHttp(cc_state_dir() . "/{$this->id}-admin.cookies");
        $this->front = new SmokeHttp(cc_state_dir() . "/{$this->id}-front.cookies");
    }

    public function adminBase() {
        return $this->env['url'] . $this->env['admin_dir'] . '/';
    }
    public function adminUrl($route, array $params = array()) {
        return $this->adminBase() . 'index.php?' . http_build_query(array_merge(array('route' => $route, 'user_token' => $this->token), $params));
    }
    public function shopUrl($route, array $params = array()) {
        return $this->env['url'] . 'index.php?' . http_build_query(array_merge(array('route' => $route), $params));
    }

    public function login($username = 'admin', $password = 'admin123') {
        $r = $this->admin->post($this->adminBase() . 'index.php?route=common/login', array('username' => $username, 'password' => $password));
        if (!preg_match('/user_token=([A-Za-z0-9]+)/', $r['location'], $m)) {
            throw new RuntimeException("Admin login failed for $username (HTTP {$r['status']})");
        }
        $this->token = $m[1];
        return $this;
    }
    public function adminGet($route, array $params = array()) {
        return $this->admin->get($this->adminUrl($route, $params));
    }
    public function adminPost($route, array $fields, array $params = array(), array $files = null) {
        return $this->admin->post($this->adminUrl($route, $params), $fields, $files);
    }
    public function adminJson($route, array $fields = array(), array $params = array()) {
        return self::json($this->adminPost($route, $fields, $params));
    }

    public function shopGet($route, array $params = array()) {
        return $this->front->get($this->shopUrl($route, $params));
    }
    public function shopPost($route, array $fields, array $params = array()) {
        return $this->front->post($this->shopUrl($route, $params), $fields);
    }

    // Catalog API: orders are created exactly like the admin "Add order" page does.
    public function apiLogin() {
        $res = $this->front->post($this->shopUrl('api/login'), array('username' => 'Default', 'key' => 'cc-smoke-api-key'));
        $r = json_decode($res['body'], true);
        if (is_array($r) && !empty($r['api_token'])) {
            return $r['api_token'];
        }
        // OpenCart 3.0.2.0: api/login has an SQL bug in the core (missing AND). The admin
        // "Add order" page never calls it: it opens the API session itself and prints the
        // token. Take the token the same way.
        if ($this->token === '') {
            $this->login();
        }
        $page = $this->adminGet('sale/order/add');
        if (preg_match('/api_token=([A-Za-z0-9]{20,})/', $page['body'], $m)) {
            return $m[1];
        }
        throw new RuntimeException('API login failed: ' . substr($res['body'], 0, 300));
    }
    public function api($token, $route, array $fields = null, array $params = array()) {
        $url = $this->shopUrl($route, array_merge(array('api_token' => $token), $params));
        $r = self::json($fields === null ? $this->front->get($url) : $this->front->post($url, $fields));
        if (!empty($r['error'])) {
            throw new RuntimeException("$route: " . json_encode($r['error'], JSON_UNESCAPED_UNICODE));
        }
        return $r;
    }
    public function address() {
        $country = $this->row("SELECT `country_id` FROM `{p}country` WHERE `iso_code_2` = 'GR'");
        $zone = $this->row("SELECT `zone_id` FROM `{p}zone` WHERE `country_id` = '" . (int)$country['country_id'] . "' ORDER BY `zone_id` LIMIT 1");
        return array('firstname' => 'Δοκιμή', 'lastname' => 'Smoke', 'company' => '', 'address_1' => 'Λεωφόρος Αλεξάνδρας 10',
            'address_2' => '', 'city' => 'Αθήνα', 'postcode' => '11473', 'country_id' => $country['country_id'], 'zone_id' => $zone['zone_id']);
    }
    /** @return int order_id */
    public function createApiOrder($shipping_method, $payment_method, array $products = array(40 => 1), $status_id = 1) {
        $t = $this->apiLogin();
        $this->api($t, 'api/customer', array('customer_id' => 0, 'customer_group_id' => 1, 'firstname' => 'Δοκιμή', 'lastname' => 'Smoke',
            'email' => 'customer@example.com', 'telephone' => '6900000000'));
        foreach ($products as $pid => $qty) {
            $this->api($t, 'api/cart/add', array('product_id' => $pid, 'quantity' => $qty));
        }
        $a = $this->address();
        $this->api($t, 'api/payment/address', $a);
        $this->api($t, 'api/shipping/address', $a);
        $this->api($t, 'api/shipping/methods');
        $this->api($t, 'api/payment/methods');
        $r = $this->api($t, 'api/order/add', array('shipping_method' => $shipping_method, 'payment_method' => $payment_method,
            'order_status_id' => $status_id, 'comment' => 'cc smoke'));
        if (empty($r['order_id'])) {
            throw new RuntimeException('api/order/add returned no order_id: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
        }
        return (int)$r['order_id'];
    }
    public function apiOrderHistory($order_id, $status_id, $notify, $comment = '') {
        $t = $this->apiLogin();
        return $this->api($t, 'api/order/history', array('order_status_id' => $status_id, 'notify' => $notify ? 1 : 0,
            'override' => 0, 'comment' => $comment), array('order_id' => (int)$order_id));
    }

    public function db() {
        if ($this->db === null) {
            $this->db = cc_mysql($this->env['db']);
        }
        return $this->db;
    }
    public function rows($sql) {
        $res = $this->db()->query(str_replace('{p}', $this->env['prefix'], $sql));
        if ($res === false) {
            throw new RuntimeException('SQL error: ' . $this->db()->error);
        }
        if ($res === true) {
            return array();
        }
        $out = array();
        while ($r = $res->fetch_assoc()) {
            $out[] = $r;
        }
        return $out;
    }
    public function row($sql) {
        $r = $this->rows($sql);
        return $r ? $r[0] : array();
    }
    public function exec($sql) {
        $this->rows($sql);
    }

    public function errorLogPath() {
        return $this->env['dir'] . '/system/storage/logs/error.log';
    }
    public function clearErrorLog() {
        @file_put_contents($this->errorLogPath(), '');
    }
    /** Log lines without the leading "Y-m-d H:i:s - " timestamp. */
    public function errorLogLines() {
        $raw = is_file($this->errorLogPath()) ? file_get_contents($this->errorLogPath()) : '';
        $lines = array();
        foreach (preg_split('/\R/', $raw) as $l) {
            $l = trim(preg_replace('/^\d{4}-\d{2}-\d{2} \d{1,2}:\d{2}:\d{2} - /', '', $l));
            if ($l !== '') {
                $lines[] = $l;
            }
        }
        return $lines;
    }

    public static function json(array $r) {
        $d = json_decode($r['body'], true);
        if (!is_array($d)) {
            throw new RuntimeException('Expected JSON (HTTP ' . $r['status'] . '): ' . substr($r['body'], 0, 400));
        }
        return $d;
    }
}
