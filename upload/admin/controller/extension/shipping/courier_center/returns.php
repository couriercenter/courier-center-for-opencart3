<?php
/**
 * Courier Center returns (admin): list page with filters, the actions of a request (approve,
 * reject, create / retry, verify, refresh tracking), the return voucher PDF, «Νέα επιστροφή» of the
 * shop (add), the Sales menu entry with the counter and the admin notice.
 * Port of admin/class-cc-returns-admin.php (WooCommerce 1.6.0).
 */
require_once DIR_SYSTEM . 'library/couriercenter/oc3/CCControllerTrait.php';

use Opencart\Extension\Couriercenter\Library\CCDbSchema;
use Opencart\Extension\Couriercenter\Library\CCReturnsRules;
use Opencart\Extension\Couriercenter\Library\CCReturnsTracker;
use Opencart\Extension\Couriercenter\Oc3\CCErrorGuard;

class ControllerExtensionShippingCourierCenterReturns extends Controller {
    use \Opencart\Extension\Couriercenter\Oc3\CCControllerTrait;

    const ROUTE = 'extension/shipping/courier_center/returns';
    const PER_PAGE = 20;

    public function index() {
        if (!$this->ccPhpOk()) {
            $this->response->redirect($this->url->link('extension/shipping/courier_center', 'user_token=' . $this->session->data['user_token'], true));
            return;
        }
        $this->ccBoot();
        CCDbSchema::ensureOnce($this->db, DB_PREFIX);
        $this->document->setTitle('Επιστροφές — Courier Center');
        $svc = $this->ccReturns();
        $svc->releaseStaleLocks();

        $status = (string)($this->request->get['filter_status'] ?? '');
        $method = (string)($this->request->get['filter_method'] ?? '');
        $search = trim($this->ccText($this->request->get['filter_search'] ?? ''));
        $page = max(1, (int)($this->request->get['page'] ?? 1));
        $args = array('status' => $status, 'method' => $method, 'search' => $search);
        $total = $svc->repo()->count($args);
        $rows = $svc->repo()->query($args + array('limit' => self::PER_PAGE, 'offset' => ($page - 1) * self::PER_PAGE));

        $list = array();
        foreach ($rows as $r) {
            $order = $svc->getOrder((int)$r['order_id']);
            $list[] = $this->ccReturnRow($r) + array(
                'customer'  => trim($this->ccText(($order['firstname'] ?? '') . ' ' . ($order['lastname'] ?? ''))),
                'order_url' => $this->ccLink('sale/order/info', 'order_id=' . (int)$r['order_id']),
            );
        }
        $counts = $svc->repo()->countByStatus();
        $statuses = array();
        foreach (array(CCReturnsRules::ST_PENDING, CCReturnsRules::ST_APPROVED, CCReturnsRules::ST_CREATING, CCReturnsRules::ST_VERIFY, CCReturnsRules::ST_FAILED, CCReturnsRules::ST_CREATED, CCReturnsRules::ST_REJECTED) as $st) {
            $statuses[] = array('code' => $st, 'label' => CCReturnsRules::status_label($st), 'count' => $counts[$st] ?? 0);
        }
        $filter_url = 'filter_status=' . urlencode($status) . '&filter_method=' . urlencode($method) . '&filter_search=' . urlencode($search);
        $pagination = new Pagination();
        $pagination->total = $total;
        $pagination->page = $page;
        $pagination->limit = self::PER_PAGE;
        $pagination->url = $this->url->link(self::ROUTE, 'user_token=' . $this->session->data['user_token'] . '&' . $filter_url . '&page={page}', true);

        $data = array(
            'rows'          => $list,
            'statuses'      => $statuses,
            'methods'       => array_combine(CCReturnsRules::METHODS_ORDER, array_map(array(CCReturnsRules::class, 'method_label'), CCReturnsRules::METHODS_ORDER)),
            'filter_status' => $status,
            'filter_method' => $method,
            'filter_search' => $search,
            'total'         => $total,
            'pagination'    => $pagination->render(),
            'enabled'       => $svc->settings()->enabled(),
            'can_modify'    => $this->ccCanModify(),
            'user_token'    => $this->session->data['user_token'],
            'urls'          => $this->ccReturnActionUrls(),
            'url_settings'  => $this->ccLink('extension/shipping/courier_center'),
            'url_self'      => $this->ccLink(self::ROUTE),
        );
        $data['breadcrumbs'] = array(
            array('text' => 'Αρχική', 'href' => $this->ccLink('common/dashboard')),
            array('text' => 'Courier Center', 'href' => $data['url_settings']),
            array('text' => 'Επιστροφές', 'href' => $data['url_self']),
        );
        $data['returns_js'] = $this->load->view('extension/shipping/courier_center/returns_js', array('urls' => $data['urls']));
        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');
        $this->response->setOutput($this->load->view('extension/shipping/courier_center/returns', $data));
    }

    /** «Νέα επιστροφή» of the shop for one order (GET form, POST create). */
    public function add() {
        if (!$this->ccPhpOk()) {
            $this->response->redirect($this->ccLink('extension/shipping/courier_center'));
            return;
        }
        $this->ccBoot();
        CCDbSchema::ensureOnce($this->db, DB_PREFIX);
        $order_id = (int)($this->request->get['order_id'] ?? 0);
        $svc = $this->ccReturns();
        $order = $svc->getOrder($order_id);
        $order_url = $this->ccLink('sale/order/info', 'order_id=' . $order_id);
        $error = '';
        $posted = array();
        if ($this->request->server['REQUEST_METHOD'] === 'POST' && $order) {
            if (!$this->ccCanModify()) {
                $error = 'Δεν έχετε δικαίωμα τροποποίησης του Courier Center.';
            } else {
                $posted = $this->request->post;
                $items = array();
                foreach ((array)($posted['qty'] ?? array()) as $iid => $qty) {
                    if (is_scalar($qty) && (int)$qty > 0) {
                        $items[(int)$iid] = trim((string)$qty);
                    }
                }
                @set_time_limit(120);
                $r = $svc->shopCreate($order, (int)$this->user->getId(), array(
                    'request_uid'      => (string)($posted['request_uid'] ?? ''),
                    'method'           => (string)($posted['method'] ?? ''),
                    'items'            => $items,
                    'reason'           => $this->ccText($posted['reason'] ?? ''),
                    'name'             => $this->ccText($posted['name'] ?? ''),
                    'mobile'           => $this->ccText($posted['mobile'] ?? ''),
                    'landline'         => $this->ccText($posted['landline'] ?? ''),
                    'locker_size'      => (string)($posted['locker_size'] ?? ''),
                    'pickup_address'   => $this->ccText($posted['pickup_address'] ?? ''),
                    'pickup_city'      => $this->ccText($posted['pickup_city'] ?? ''),
                    'pickup_postcode'  => $this->ccText($posted['pickup_postcode'] ?? ''),
                    'pickup_date'      => (string)($posted['pickup_date'] ?? ''),
                    'pickup_time_from' => (string)($posted['pickup_time_from'] ?? ''),
                    'pickup_time_to'   => (string)($posted['pickup_time_to'] ?? ''),
                    'courier_notes'    => $this->ccText($posted['courier_notes'] ?? ''),
                    'notify'           => !empty($posted['notify']) ? '1' : '',
                ));
                if ($r['ok']) {
                    $row = $svc->repo()->get((int)$r['id']);
                    if ($row && $row['status'] === CCReturnsRules::ST_CREATED) {
                        $msg = sprintf('Η επιστροφή #%d δημιουργήθηκε. Αριθμός αποστολής επιστροφής: %s.', $r['id'], $row['return_awb'])
                            . (!empty($row['notify_customer']) ? ' Ο πελάτης ενημερώνεται με email.' : '');
                        $this->session->data['cc_return_notice'] = array('type' => 'success', 'text' => $msg);
                    } else {
                        $err = $row && trim((string)$row['last_error']) !== '' ? $row['last_error'] : CCReturnsRules::status_label($row ? $row['status'] : '');
                        $this->session->data['cc_return_notice'] = array('type' => 'warning',
                            'text' => sprintf('Η επιστροφή #%d καταχωρήθηκε, αλλά η δημιουργία της αποστολής δεν ολοκληρώθηκε: %s', $r['id'], $err));
                    }
                    $this->response->redirect($order_url);
                    return;
                }
                $error = $r['error'];
                if ($r['code'] === 'forbidden') {
                    unset($posted['request_uid']); // belongs to another order: a new uid
                }
            }
        }

        $data = array('error' => $error, 'order_id' => $order_id, 'order_url' => $order_url, 'found' => (bool)$order, 'active' => false);
        if ($order) {
            $data['active'] = $svc->hasActiveAwb($order_id);
            $this->load->model('extension/shipping/courier_center');
            $shipment = $this->model_extension_shipping_courier_center->getShipment($order_id);
            $defaults = $svc->customerDefaults($order);
            $items = array();
            foreach ($svc->availableItems($order) as $iid => $it) {
                $items[] = array('id' => $iid, 'name' => $it['name'], 'model' => $it['model'], 'ordered' => $it['ordered'], 'available' => $it['available'],
                    'value' => isset($posted['qty'][$iid]) ? (int)$posted['qty'][$iid] : 0);
            }
            $sizes = array();
            foreach (CCReturnsRules::LOCKER_SIZES as $code => $sz) {
                $sizes[] = array('code' => $code, 'label' => $sz['label'], 'dims' => $sz['dims']);
            }
            $methods = array();
            foreach (CCReturnsRules::METHODS_ORDER as $m) {
                $methods[] = array('code' => $m, 'label' => CCReturnsRules::method_label($m));
            }
            $method = (string)($posted['method'] ?? '');
            $data += array(
                'awb'         => (string)($shipment['voucher_number'] ?? ''),
                'email'       => (string)($order['email'] ?? ''),
                'action'      => $this->ccLink(self::ROUTE . '/add', 'order_id=' . $order_id),
                'request_uid' => preg_match('/^[A-Za-z0-9_-]{8,32}$/', (string)($posted['request_uid'] ?? '')) ? (string)$posted['request_uid'] : bin2hex(random_bytes(12)),
                'items'       => $items,
                'methods'     => $methods,
                'method'      => in_array($method, CCReturnsRules::METHODS_ORDER, true) ? $method : CCReturnsRules::METHOD_DROPOFF,
                'sizes'       => $sizes,
                'locker_size' => (string)($posted['locker_size'] ?? ''),
                'name'        => $posted ? $this->ccText($posted['name'] ?? '') : $defaults['name'],
                'mobile'      => $posted ? $this->ccText($posted['mobile'] ?? '') : $defaults['mobile'],
                'landline'    => $posted ? $this->ccText($posted['landline'] ?? '') : $defaults['landline'],
                'reason'      => $this->ccText($posted['reason'] ?? ''),
                'notify'      => $posted ? !empty($posted['notify']) : true,
                'boxnow'      => CCReturnsRules::METHOD_BOXNOW,
                'msg_phone'   => CCReturnsRules::MSG_PHONE,
                'msg_mobile'  => CCReturnsRules::MSG_MOBILE,
                'can_modify'  => $this->ccCanModify(),
            ) + $this->ccPickupView($posted, $defaults);
        }
        $this->document->setTitle('Νέα επιστροφή — Courier Center');
        $data['breadcrumbs'] = array(
            array('text' => 'Αρχική', 'href' => $this->ccLink('common/dashboard')),
            array('text' => 'Επιστροφές Courier Center', 'href' => $this->ccLink(self::ROUTE)),
            array('text' => 'Νέα επιστροφή #' . $order_id, 'href' => $this->ccLink(self::ROUTE . '/add', 'order_id=' . $order_id)),
        );
        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');
        $this->response->setOutput($this->load->view('extension/shipping/courier_center/returns_add', $data));
    }

    public function approve() {
        $this->act(function ($svc, $id) {
            return $svc->approve($id, (int)$this->user->getId());
        });
    }

    public function reject() {
        $this->act(function ($svc, $id) {
            return $svc->reject($id, (int)$this->user->getId());
        });
    }

    public function create() {
        $this->act(function ($svc, $id) {
            return $svc->create($id);
        });
    }

    public function verify() {
        $this->act(function ($svc, $id) {
            return $svc->verify($id);
        });
    }

    public function track() {
        $this->act(function ($svc, $id) {
            return (new CCReturnsTracker($svc))->refresh($id);
        });
    }

    /** POST id → JSON {success, error?, status_label}. */
    private function act($fn) {
        @set_time_limit(120);
        if (!$this->ccJsonStart()) {
            return;
        }
        if (!$this->ccCanModify()) {
            $this->ccDeny();
            return;
        }
        $id = (int)($this->request->post['id'] ?? 0);
        $svc = $this->ccReturns();
        if ($id <= 0 || !$svc->repo()->get($id)) {
            $this->ccJson(array('success' => false, 'error' => 'Το αίτημα επιστροφής δεν βρέθηκε.'));
            return;
        }
        $r = $fn($svc, $id);
        $row = $svc->repo()->get($id);
        $this->ccJson(array('success' => !empty($r['ok']), 'error' => empty($r['ok']) ? (string)($r['error'] ?? 'Σφάλμα') : '',
            'status_label' => $row ? CCReturnsRules::status_label($row['status']) : ''));
    }

    /** GET id: the return voucher PDF ("access" permission is enough, like the order vouchers). */
    public function voucher() {
        if (!$this->ccPhpOk()) {
            $this->ccTextError(500, 'PHP 7.2+ required');
        }
        $this->ccBoot();
        CCErrorGuard::start($this->ccLogger());
        $svc = $this->ccReturns();
        $row = $svc->repo()->get((int)($this->request->get['id'] ?? 0));
        if (!$row || $row['return_awb'] === '') {
            $this->ccTextError(404, 'Δεν υπάρχει αποστολή επιστροφής.');
        }
        $pdf = $svc->api()->get_voucher_pdf($row['return_awb'], 'singlepdf_100x150');
        if (is_array($pdf)) {
            $this->ccTextError(500, 'Σφάλμα voucher: ' . ($pdf['error'] ?? 'Άγνωστο σφάλμα'));
        }
        $this->ccSendPdf($pdf, 'return_' . $row['return_awb'] . '.pdf');
    }

    /**
     * Event admin/view/common/column_left/before: Sales → «Courier Center» with Manifest, Returns (with the count
     * of requests that wait for action), Settings, Guide and Bug report - the submenu of the WooCommerce plugin.
     * Returns nothing.
     */
    public function menu(&$route = '', &$data = array()) {
        if (!is_array($data) || !isset($data['menus']) || !is_array($data['menus']) || !$this->ccPhpOk()) {
            return;
        }
        try {
            if (!$this->user->hasPermission('access', 'extension/shipping/courier_center')) {
                return;
            }
            $this->ccBoot();
            $this->ccRememberAdminUrl();
            $n = $this->pendingCount();
            $base = 'extension/shipping/courier_center';
            $entry = array('name' => 'Courier Center' . ($n ? ' (' . $n . ')' : ''), 'href' => '', 'children' => array(
                array('name' => 'Manifest Παραλαβής', 'href' => $this->ccLink($base . '/manifest'), 'children' => array()),
                array('name' => 'Επιστροφές' . ($n ? ' (' . $n . ')' : ''), 'href' => $this->ccLink(self::ROUTE), 'children' => array()),
                array('name' => 'Ρυθμίσεις', 'href' => $this->ccLink($base), 'children' => array()),
                array('name' => 'Οδηγίες', 'href' => $this->ccLink($base . '/docs'), 'children' => array()),
                array('name' => 'Αναφορά Προβλήματος', 'href' => $this->ccLink($base . '/bug'), 'children' => array()),
            ));
            foreach ($data['menus'] as $i => $m) {
                if (($m['id'] ?? '') === 'menu-sale') {
                    $data['menus'][$i]['children'][] = $entry;
                    return;
                }
            }
        } catch (\Throwable $e) {
            $this->log->write('Courier Center returns menu: ' . $e->getMessage());
        }
    }

    /** Requests that need action (0 without the table). Cached per request (menu + notice). */
    private function pendingCount() {
        static $n = null;
        if ($n === null) {
            $n = 0;
            if ($this->db->query("SHOW TABLES LIKE '" . $this->db->escape(DB_PREFIX . 'cc_return_requests') . "'")->num_rows) {
                $n = (new \Opencart\Extension\Couriercenter\Library\CCReturnsRepo($this->db, DB_PREFIX))->pendingCount();
            }
        }
        return $n;
    }

    /**
     * Event admin/view/common/dashboard/after, sale/order_list/after, sale/order_info/after:
     * «Υπάρχουν N αιτήματα επιστροφής που χρειάζονται ενέργεια» above the page content. Returns nothing.
     */
    public function notice(&$route = '', &$data = array(), &$output = '') {
        if (!is_string($output) || $output === '' || !$this->ccPhpOk()) {
            return;
        }
        try {
            if (!$this->user->hasPermission('access', 'extension/shipping/courier_center') || (string)$this->config->get('shipping_courier_center_returns_enabled') !== '1') {
                return;
            }
            $this->ccBoot();
            $n = $this->pendingCount();
            if ($n < 1) {
                return;
            }
            $text = $n === 1 ? 'Υπάρχει 1 αίτημα επιστροφής που χρειάζεται ενέργεια.' : sprintf('Υπάρχουν %d αιτήματα επιστροφής που χρειάζονται ενέργεια.', $n);
            $html = '<div class="alert alert-warning" id="cc-returns-notice"><i class="fa fa-undo"></i> ' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8')
                . ' <a href="' . htmlspecialchars($this->ccLink(self::ROUTE), ENT_QUOTES, 'UTF-8') . '">Δες τα αιτήματα</a></div>';
            $marker = '<div class="container-fluid">';
            $head = strpos($output, '<div class="page-header">');
            $pos = $head === false ? false : strpos($output, $marker, strpos($output, '</div>', strpos($output, '</ul>', $head)));
            if ($pos !== false) {
                $pos += strlen($marker);
                $output = substr($output, 0, $pos) . $html . substr($output, $pos);
            }
        } catch (\Throwable $e) {
            $this->log->write('Courier Center returns notice: ' . $e->getMessage());
        }
    }
}
