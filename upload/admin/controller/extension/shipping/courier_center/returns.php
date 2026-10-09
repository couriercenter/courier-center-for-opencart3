<?php
/**
 * Courier Center returns (admin): list page with filters, the actions of a request (approve,
 * reject, create / retry, verify, refresh tracking), the return voucher PDF and the Sales menu entry.
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
            'methods'       => array(CCReturnsRules::METHOD_DROPOFF => CCReturnsRules::method_label(CCReturnsRules::METHOD_DROPOFF), CCReturnsRules::METHOD_BOXNOW => CCReturnsRules::method_label(CCReturnsRules::METHOD_BOXNOW)),
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

    /** Event admin/view/common/column_left/before: «Επιστροφές Courier Center» under Sales. Returns nothing. */
    public function menu(&$route = '', &$data = array()) {
        if (!is_array($data) || !isset($data['menus']) || !is_array($data['menus']) || !$this->ccPhpOk()) {
            return;
        }
        try {
            if (!$this->user->hasPermission('access', 'extension/shipping/courier_center') || (string)$this->config->get('shipping_courier_center_returns_enabled') !== '1') {
                return;
            }
            $this->ccBoot();
            $n = 0;
            if ($this->db->query("SHOW TABLES LIKE '" . $this->db->escape(DB_PREFIX . 'cc_return_requests') . "'")->num_rows) {
                $q = $this->db->query("SELECT COUNT(*) AS n FROM `" . DB_PREFIX . "cc_return_requests` WHERE `status` IN ('" . CCReturnsRules::ST_PENDING . "','" . CCReturnsRules::ST_VERIFY . "','" . CCReturnsRules::ST_FAILED . "','" . CCReturnsRules::ST_APPROVED . "')");
                $n = (int)($q->row['n'] ?? 0);
            }
            $entry = array('name' => 'Επιστροφές Courier Center' . ($n ? ' (' . $n . ')' : ''), 'href' => $this->ccLink(self::ROUTE), 'children' => array());
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
}
