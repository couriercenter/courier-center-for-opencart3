<?php
/**
 * Customer returns (storefront): the «Επιστροφές» block on account/order/info (event
 * catalog/view/account/order_info/after) and the return request form.
 * Port of includes/returns/class-cc-returns-account.php (WooCommerce 1.6.0).
 */
require_once DIR_SYSTEM . 'library/couriercenter/oc3/CCControllerTrait.php';

use Opencart\Extension\Couriercenter\Library\CCReturnsRules;

class ControllerExtensionShippingCourierCenterReturns extends Controller {
    use \Opencart\Extension\Couriercenter\Oc3\CCControllerTrait;

    const ROUTE = 'extension/shipping/courier_center/returns';

    /** Event catalog/view/account/order_info/after. Returns nothing. */
    public function orderInfo(&$route = '', &$data = array(), &$output = '') {
        if (!is_string($output) || $output === '' || !$this->ccPhpOk() || !$this->customer->isLogged()) {
            return;
        }
        try {
            if ((string)$this->config->get('shipping_courier_center_returns_enabled') !== '1') {
                return;
            }
            $order_id = (int)($data['order_id'] ?? ($this->request->get['order_id'] ?? 0));
            if ($order_id <= 0) {
                return;
            }
            $this->ccBoot();
            $svc = $this->ccReturns();
            $order = $svc->getOrder($order_id);
            $customer_id = (int)$this->customer->getId();
            if (!$order || (int)$order['customer_id'] !== $customer_id) {
                return;
            }
            $requests = array();
            foreach ($svc->repo()->forOrder($order_id) as $r) {
                $requests[] = $this->customerRow($r, $svc);
            }
            $eligible = $svc->checkEligibility($order, $customer_id);
            $can_request = false;
            if ($eligible['ok']) {
                foreach ($svc->availableItems($order) as $it) {
                    if ($it['available'] > 0) {
                        $can_request = true;
                        break;
                    }
                }
            }
            if (!$requests && !$can_request) {
                return;
            }
            $flash = '';
            if (isset($this->session->data['cc_return_success'])) {
                $flash = (string)$this->session->data['cc_return_success'];
                unset($this->session->data['cc_return_success']);
            }
            $html = $this->load->view('extension/shipping/courier_center/returns_block', array(
                'requests'    => $requests,
                'can_request' => $can_request,
                'url_request' => $this->ccCatalogLink(self::ROUTE, 'order_id=' . $order_id),
                'flash'       => $flash,
            ));
            $pos = strrpos($output, '<div class="buttons clearfix">');
            if ($pos === false) {
                $pos = strripos($output, '<footer');
            }
            $output = $pos === false ? $output . $html : substr($output, 0, $pos) . $html . substr($output, $pos);
        } catch (\Throwable $e) {
            $this->log->write('Courier Center returns (account): ' . $e->getMessage());
        }
    }

    /** The return request form (GET) and its submission (POST). */
    public function index() {
        $order_id = (int)($this->request->get['order_id'] ?? 0);
        if (!$this->customer->isLogged()) {
            $this->session->data['redirect'] = $this->ccCatalogLink(self::ROUTE, 'order_id=' . $order_id);
            $this->response->redirect($this->url->link('account/login', '', true));
            return;
        }
        $back = $this->ccCatalogLink('account/order/info', 'order_id=' . $order_id);
        if (!$this->ccPhpOk()) {
            $this->response->redirect($back);
            return;
        }
        $this->ccBoot();
        $svc = $this->ccReturns();
        $order = $svc->getOrder($order_id);
        $customer_id = (int)$this->customer->getId();
        $ok = $svc->checkEligibility($order, $customer_id);
        if (!$ok['ok']) {
            $this->renderPage(array('error' => $ok['error'], 'blocked' => true, 'back' => $back, 'order_id' => $order_id));
            return;
        }

        $error = '';
        $posted = array();
        if ($this->request->server['REQUEST_METHOD'] === 'POST') {
            $posted = $this->request->post;
            $items = array();
            foreach ((array)($posted['qty'] ?? array()) as $iid => $qty) {
                $items[(int)$iid] = is_scalar($qty) ? trim((string)$qty) : '';
            }
            $r = $svc->submit($order, $customer_id, array(
                'request_uid' => (string)($posted['request_uid'] ?? ''),
                'method'      => (string)($posted['method'] ?? ''),
                'items'       => $items,
                'reason'      => $this->ccText($posted['reason'] ?? ''),
                'name'        => $this->ccText($posted['name'] ?? ''),
                'mobile'      => $this->ccText($posted['mobile'] ?? ''),
                'landline'    => $this->ccText($posted['landline'] ?? ''),
                'locker_size' => (string)($posted['locker_size'] ?? ''),
            ));
            if ($r['ok']) {
                $row = $svc->repo()->get((int)$r['id']);
                $this->session->data['cc_return_success'] = ($row && $row['status'] === CCReturnsRules::ST_CREATED)
                    ? 'Η επιστροφή σας δημιουργήθηκε επιτυχώς!'
                    : 'Το αίτημα επιστροφής καταχωρίστηκε. Θα ενημερωθείτε με email για την εξέλιξή του.';
                $this->response->redirect($back);
                return;
            }
            $error = $r['error'];
        }

        $methods = $svc->settings()->enabledMethods();
        $defaults = $svc->customerDefaults($order);
        $sizes = array();
        foreach (CCReturnsRules::LOCKER_SIZES as $code => $sz) {
            $sizes[] = array('code' => $code, 'label' => $sz['label'], 'dims' => $sz['dims']);
        }
        $items = array();
        foreach ($svc->availableItems($order) as $iid => $it) {
            $items[] = array('id' => $iid, 'name' => $it['name'], 'model' => $it['model'], 'ordered' => $it['ordered'], 'available' => $it['available'],
                'value' => isset($posted['qty'][$iid]) ? (int)$posted['qty'][$iid] : 0);
        }
        $method_list = array();
        foreach ($methods as $m) {
            $method_list[] = array('code' => $m, 'label' => CCReturnsRules::method_label($m));
        }
        $this->renderPage(array(
            'error'       => $error,
            'blocked'     => false,
            'back'        => $back,
            'order_id'    => $order_id,
            'action'      => $this->ccCatalogLink(self::ROUTE, 'order_id=' . $order_id),
            'request_uid' => preg_match('/^[A-Za-z0-9_-]{8,32}$/', (string)($posted['request_uid'] ?? '')) ? (string)$posted['request_uid'] : bin2hex(random_bytes(12)),
            'items'       => $items,
            'methods'     => $method_list,
            'method'      => in_array((string)($posted['method'] ?? ''), $methods, true) ? (string)$posted['method'] : CCReturnsRules::default_method($methods),
            'sizes'       => $sizes,
            'locker_size' => (string)($posted['locker_size'] ?? ''),
            'name'        => $posted ? $this->ccText($posted['name'] ?? '') : $defaults['name'],
            'mobile'      => $posted ? $this->ccText($posted['mobile'] ?? '') : $defaults['mobile'],
            'landline'    => $posted ? $this->ccText($posted['landline'] ?? '') : $defaults['landline'],
            'reason'      => $this->ccText($posted['reason'] ?? ''),
            'boxnow'      => CCReturnsRules::METHOD_BOXNOW,
            'msg_phone'   => CCReturnsRules::MSG_PHONE,
            'msg_mobile'  => CCReturnsRules::MSG_MOBILE,
        ));
    }

    private function renderPage(array $data) {
        $this->document->setTitle('Αίτημα επιστροφής');
        $data['breadcrumbs'] = array(
            array('text' => 'Αρχική', 'href' => $this->ccCatalogLink('common/home')),
            array('text' => 'Λογαριασμός', 'href' => $this->ccCatalogLink('account/account')),
            array('text' => 'Παραγγελία #' . (int)$data['order_id'], 'href' => $data['back']),
            array('text' => 'Αίτημα επιστροφής', 'href' => $data['back']),
        );
        foreach (array('column_left', 'column_right', 'content_top', 'content_bottom', 'footer', 'header') as $part) {
            $data[$part] = $this->load->controller('common/' . $part);
        }
        $this->response->setOutput($this->load->view('extension/shipping/courier_center/returns_form', $data));
    }

    /** What the customer sees of a request (internal creation states shown as one). */
    private function customerRow(array $r, $svc) {
        $st = $r['status'];
        $created = $st === CCReturnsRules::ST_CREATED;
        $parts = array();
        if ($created) {
            foreach (CCReturnsRules::creation_parts($r) as $p) {
                $parts[] = array('text' => $p[0], 'value' => $p[1], 'is_code' => $p[2]);
            }
        }
        $items = array();
        foreach ($r['items'] as $line) {
            $items[] = (string)($line['name'] ?? '') . ' × ' . (int)($line['qty'] ?? 0);
        }
        $tracking = (string)$r['display_status'];
        return array(
            'id'           => (int)$r['id'],
            'date'         => (string)$r['created_at'],
            'status_label' => CCReturnsRules::customer_status_label($st),
            'method_label' => CCReturnsRules::method_label($r['method']),
            'items'        => implode(', ', $items),
            'parts'        => $parts,
            'instructions' => $created ? ($r['method'] === CCReturnsRules::METHOD_BOXNOW ? $svc->settings()->boxnowInstructions() : $svc->settings()->dropoffInstructions()) : '',
            'tracking'     => ($created && $tracking !== '' && $tracking !== 'None' && (string)$r['shipment_status'] !== '0') ? $tracking : '',
        );
    }
}
