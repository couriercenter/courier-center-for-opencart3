<?php
/** Daily pickup manifest (Courier Center). Port of courier_center_manifest.php (OC4). */
require_once DIR_SYSTEM . 'library/couriercenter/oc3/CCControllerTrait.php';

use Opencart\Extension\Couriercenter\Oc3\CCErrorGuard;

class ControllerExtensionShippingCourierCenterManifest extends Controller {
    use \Opencart\Extension\Couriercenter\Oc3\CCControllerTrait;

    public function index() {
        if (!$this->ccPhpOk()) {
            $this->response->redirect($this->url->link('extension/shipping/courier_center', 'user_token=' . $this->session->data['user_token'], true));
            return;
        }
        $this->ccBoot();
        $this->document->setTitle('Manifest Παραλαβής — Courier Center');

        $today = date('Y-m-d');
        $selected = trim((string)($this->request->get['manifest_date'] ?? $today));
        $d = \DateTime::createFromFormat('Y-m-d', $selected);
        if (!$d || $d->format('Y-m-d') !== $selected) {
            $selected = $today;
        }
        $this->load->model('extension/shipping/courier_center');
        $points = \Opencart\Extension\Couriercenter\Library\CCPickupPoints::fromConfig($this->config);
        $account = $this->knownAccount($points, (string)($this->request->get['account'] ?? ''));
        $main = $points->main()['code'];
        $point_options = array();
        if ($points->hasSubs()) {
            foreach ($points->all() as $pt) {
                $point_options[] = array('code' => $pt['code'], 'label' => \Opencart\Extension\Couriercenter\Library\CCPickupPoints::label($pt));
            }
        }
        $args = 'date=' . $selected . ($account !== '' ? '&account=' . urlencode($account) : '');

        $data = array(
            'selected_date'     => $selected,
            'selected_account'  => $account,
            'point_options'     => $point_options,
            'today'             => $today,
            'tomorrow'          => date('Y-m-d', strtotime('+1 day')),
            'yesterday'         => date('Y-m-d', strtotime('-1 day')),
            'day_before'        => date('Y-m-d', strtotime('-2 days')),
            'vouchers_count'    => $this->model_extension_shipping_courier_center->countShipmentsForDate($selected, $account, $account !== '' && $account === $main),
            'greek_date'        => $this->formatGreekDate($selected),
            'user_token'        => $this->session->data['user_token'],
            'url_download'      => $this->ccLink('extension/shipping/courier_center/manifest/download', $args),
            'url_download_save' => $this->ccLink('extension/shipping/courier_center/manifest/download', $args . '&save=1'),
            'url_self'          => $this->ccLink('extension/shipping/courier_center/manifest', $account !== '' ? 'account=' . urlencode($account) : ''),
            'url_settings'      => $this->ccLink('extension/shipping/courier_center'),
        );
        $data['breadcrumbs'] = array(
            array('text' => 'Αρχική', 'href' => $this->ccLink('common/dashboard')),
            array('text' => 'Courier Center', 'href' => $data['url_settings']),
            array('text' => 'Manifest Παραλαβής', 'href' => $data['url_self']),
        );
        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');
        $this->response->setOutput($this->load->view('extension/shipping/courier_center/manifest', $data));
    }

    public function download() {
        if (!$this->ccPhpOk()) {
            $this->ccTextError(500, 'PHP 7.2+ required');
        }
        $this->ccBoot();
        CCErrorGuard::start($this->ccLogger());
        $date = trim((string)($this->request->get['date'] ?? date('Y-m-d')));
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            $date = date('Y-m-d');
        }
        $svc = $this->ccService();
        $account = $this->knownAccount($svc->points(), (string)($this->request->get['account'] ?? ''));
        $pdf = $svc->api()->get_manifest($date, $account);
        if (is_array($pdf)) {
            $this->ccTextError(500, 'Σφάλμα λήψης manifest: ' . ($pdf['error'] ?? 'Άγνωστο σφάλμα'));
        }
        $this->ccSendPdf($pdf, 'manifest-' . $date . ($account !== '' ? '-' . $account : '') . '.pdf', !empty($this->request->get['save']) ? 'attachment' : 'inline');
    }

    /** A pickup point code the shop knows, else '' (= all points, no filter). */
    private function knownAccount($points, $raw) {
        $raw = trim($this->ccText($raw));
        return ($raw !== '' && $points->hasSubs() && $points->get($raw) !== null) ? $raw : '';
    }

    private function formatGreekDate($date) {
        $months = array('01' => 'Ιανουαρίου', '02' => 'Φεβρουαρίου', '03' => 'Μαρτίου', '04' => 'Απριλίου', '05' => 'Μαΐου', '06' => 'Ιουνίου',
            '07' => 'Ιουλίου', '08' => 'Αυγούστου', '09' => 'Σεπτεμβρίου', '10' => 'Οκτωβρίου', '11' => 'Νοεμβρίου', '12' => 'Δεκεμβρίου');
        $parts = explode('-', $date);
        if (count($parts) !== 3) {
            return $date;
        }
        return (int)$parts[2] . ' ' . ($months[$parts[1]] ?? $parts[1]) . ' ' . $parts[0];
    }
}
