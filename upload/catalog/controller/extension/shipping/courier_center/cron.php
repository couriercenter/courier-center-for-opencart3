<?php
/** Courier Center - status tracker through a URL with a secret key (spec 5.8). */
require_once DIR_SYSTEM . 'library/couriercenter/oc3/CCControllerTrait.php';

use Opencart\Extension\Couriercenter\Oc3\CCCronRunner;
use Opencart\Extension\Couriercenter\Oc3\CCErrorGuard;

class ControllerExtensionShippingCourierCenterCron extends Controller {
    use \Opencart\Extension\Couriercenter\Oc3\CCControllerTrait;

    public function index() {
        $this->response->addHeader('Content-Type: text/plain; charset=utf-8');
        $this->response->addHeader('Cache-Control: no-store');
        if (!$this->ccPhpOk()) {
            $this->response->setOutput('Courier Center needs PHP 7.2+');
            return;
        }
        $this->ccBoot();
        $given = isset($this->request->get['key']) ? $this->request->get['key'] : '';
        if (!CCCronRunner::keyMatches($this->ccState()->get('cron_key', ''), $given)) {
            $this->response->addHeader('HTTP/1.1 403 Forbidden');
            $this->response->setOutput('Forbidden');
            return;
        }
        @set_time_limit(0);
        CCErrorGuard::start($this->ccLogger());
        try {
            $site = (string)($this->config->get('config_ssl') ? $this->config->get('config_ssl') : $this->config->get('config_url'));
            $result = CCCronRunner::run($this->db, DB_PREFIX, $this->config, DIR_CACHE, $site, null, true, function ($event, $id) {
                $this->ccSendReturnMail($event, (int)$id);
            });
            $this->response->setOutput($result['message'] . "\n" . implode("\n", $result['lines']) . "\n");
        } finally {
            CCErrorGuard::stop();
        }
    }
}
