<?php
/** In-admin user guide + changelog (Courier Center → Οδηγίες). Update the "Τι νέο" section of docs.twig on every release. */
require_once DIR_SYSTEM . 'library/couriercenter/oc3/CCControllerTrait.php';

use Opencart\Extension\Couriercenter\Oc3\CCVersion;

class ControllerExtensionShippingCourierCenterDocs extends Controller {
    use \Opencart\Extension\Couriercenter\Oc3\CCControllerTrait;

    public function index() {
        require_once DIR_SYSTEM . 'library/couriercenter/oc3/CCVersion.php';
        $this->document->setTitle('Οδηγίες — Courier Center');
        $data = array(
            'version'      => CCVersion::get(),
            'url_settings' => $this->ccLink('extension/shipping/courier_center'),
            'url_report'   => $this->ccLink('extension/shipping/courier_center/bug'),
            'url_manifest' => $this->ccLink('extension/shipping/courier_center/manifest'),
        );
        $data['breadcrumbs'] = array(
            array('text' => 'Αρχική', 'href' => $this->ccLink('common/dashboard')),
            array('text' => 'Courier Center', 'href' => $data['url_settings']),
            array('text' => 'Οδηγίες', 'href' => $this->ccLink('extension/shipping/courier_center/docs')),
        );
        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');
        $this->response->setOutput($this->load->view('extension/shipping/courier_center/docs', $data));
    }
}
