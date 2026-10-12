<?php
/**
 * Courier Center updater (OpenCart 3): check / apply / finish, plus the header event that repairs
 * the install after the files changed and shows the "new version" banner (spec 5.9, 5.10).
 */
require_once DIR_SYSTEM . 'library/couriercenter/oc3/CCControllerTrait.php';

use Opencart\Extension\Couriercenter\Oc3\CCUpdater;
use Opencart\Extension\Couriercenter\Oc3\CCVersion;
use Opencart\Extension\Couriercenter\Oc3\CCSchema;

class ControllerExtensionShippingCourierCenterUpdate extends Controller {
    use \Opencart\Extension\Couriercenter\Oc3\CCControllerTrait;

    public function check() {
        if (!$this->ccJsonStart()) {
            return;
        }
        if (!$this->ccCanModify()) {
            $this->ccDeny();
            return;
        }
        $rel = CCUpdater::latestRelease($this->ccState());
        if (isset($rel['error'])) {
            $this->ccJson(array('success' => false, 'error' => $rel['error']));
            return;
        }
        $current = CCVersion::get();
        $this->ccJson(array('success' => true, 'current' => $current, 'latest' => $rel['version'],
            'update_available' => CCUpdater::isNewer($rel['version'], $current), 'notes' => $rel['notes']));
    }

    public function apply() {
        @set_time_limit(0);
        if (!$this->ccJsonStart()) {
            return;
        }
        if (!$this->ccCanModify()) {
            $this->ccDeny();
            return;
        }
        $tmp = rtrim(str_replace('\\', '/', DIR_STORAGE), '/') . '/cc_update';
        try {
            $state = $this->ccState();
            $rel = CCUpdater::latestRelease($state);
            if (isset($rel['error'])) {
                throw new \RuntimeException($rel['error']);
            }
            $current = CCVersion::get();
            if (!CCUpdater::isNewer($rel['version'], $current)) {
                throw new \RuntimeException('Δεν υπάρχει νεότερη έκδοση (τρέχουσα: ' . $current . ').');
            }
            if ($rel['zip_url'] === '') {
                throw new \RuntimeException('Το release δεν έχει το αρχείο ' . CCUpdater::ASSET_NAME . '.');
            }
            CCUpdater::rrmdir($tmp);
            CCUpdater::mkdirFor($tmp . '/x/.');
            CCUpdater::download($rel['zip_url'], $tmp . '/release.zip');
            $za = new \ZipArchive();
            if ($za->open($tmp . '/release.zip') !== true) {
                throw new \RuntimeException('Μη έγκυρο zip αρχείο.');
            }
            $entries = array();
            for ($i = 0; $i < $za->numFiles; $i++) {
                $entries[] = (string)$za->getNameIndex($i);
            }
            $files = CCUpdater::payloadFiles($entries);
            $za->extractTo($tmp . '/x');
            $za->close();
            $zip_version = CCUpdater::packageVersion($tmp . '/x');
            if ($zip_version !== $rel['version']) {
                throw new \RuntimeException('Η έκδοση μέσα στο zip (' . $zip_version . ') δεν ταιριάζει με το release (' . $rel['version'] . ').');
            }
            $dirs = array('application' => DIR_APPLICATION, 'catalog' => DIR_CATALOG, 'system' => DIR_SYSTEM, 'image' => DIR_IMAGE);
            CCUpdater::assertWritable($files, $dirs);
            $backup = rtrim(str_replace('\\', '/', DIR_STORAGE), '/') . '/cc_backup_' . date('YmdHis');
            CCUpdater::install($tmp . '/x', $files, $dirs, $backup);
            CCUpdater::rrmdir($tmp);
            CCUpdater::clearTemplateCache(DIR_CACHE);
            foreach (array('update_check_last', 'update_latest', 'update_available') as $key) {
                $state->delete($key);
            }
            $this->ccJson(array('success' => true, 'version' => $rel['version'],
                'message' => 'Ενημερώθηκε σε έκδοση ' . $rel['version'] . '. Backup: ' . basename($backup),
                'finish_url' => $this->ccLink('extension/shipping/courier_center/update/finish')));
        } catch (\Throwable $e) {
            CCUpdater::rrmdir($tmp);
            $this->ccJson(array('success' => false, 'error' => $e->getMessage()));
        }
    }

    /** Second request after apply(): it already runs the NEW code, so new events / columns get registered. */
    public function finish() {
        if (!$this->ccJsonStart()) {
            return;
        }
        if (!$this->ccCanModify()) {
            $this->ccDeny();
            return;
        }
        $this->ccJson(array('success' => true, 'done' => $this->ccRepair()));
    }

    /** Event admin/view/common/header/after. Returns nothing (spec 5.4). */
    public function notice(&$route = '', &$data = array(), &$output = '') {
        if (!is_string($output) || !$this->ccPhpOk()) {
            return;
        }
        try {
            if (!$this->user->isLogged()) {
                return;
            }
            $this->ccBoot();
            if (method_exists($this, 'ccPseudoCronTick')) { // (files are swapped one by one during an update)
                $this->ccPseudoCronTick();
            }
            if (!$this->user->hasPermission('access', 'extension/shipping/courier_center')) {
                return;
            }
            $state = $this->ccState();
            $installed = $state->get('installed_version', '');
            $stale = $installed !== CCVersion::get() || $state->get('installed_build', '') !== CCSchema::eventsSignature();
            if ($installed !== '' && $stale && $this->ccCanModify()) {
                $this->ccRepair(); // new files: updater, or a new zip uploaded in the Extension Installer (same version too)
            }
            $now = time();
            $latest = $state->get('update_latest', '');
            $available = $state->get('update_available', '') === '1';
            if ($now - (int)$state->get('update_check_last', '0') >= CCUpdater::CHECK_INTERVAL) {
                $rel = CCUpdater::latestRelease($state, 6);
                if (!isset($rel['error'])) {
                    $latest = $rel['version'];
                    $available = CCUpdater::isNewer($latest, CCVersion::get());
                }
                $state->set('update_check_last', (string)$now);
                $state->set('update_latest', $latest);
                $state->set('update_available', $available ? '1' : '0');
            }
            if ($available && CCUpdater::isNewer($latest, CCVersion::get())) {
                $output .= $this->load->view('extension/shipping/courier_center/update_banner', array(
                    'version' => $latest,
                    'url'     => $this->ccLink('extension/shipping/courier_center'),
                ));
            }
        } catch (\Throwable $e) {
            $this->log->write('Courier Center update notice: ' . $e->getMessage());
        }
    }
}
