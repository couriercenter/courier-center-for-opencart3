<?php
/**
 * Tracking email (event catalog/model/checkout/order/addOrderHistory/after, sort 99).
 * When the merchant changes the status with "Notify" and the order has an active voucher,
 * the customer gets the AWB + tracking link. Port of courier_center_email.php (OC4).
 */
require_once DIR_SYSTEM . 'library/couriercenter/oc3/CCControllerTrait.php';

use Opencart\Extension\Couriercenter\Oc3\CCErrorGuard;

class ControllerExtensionShippingCourierCenterEmail extends Controller {
    use \Opencart\Extension\Couriercenter\Oc3\CCControllerTrait;

    /** $args = [order_id, order_status_id, comment, notify, override]. Returns nothing (spec 5.4). */
    public function sendTracking(&$route = '', &$args = array(), &$output = null) {
        if (!$this->ccPhpOk()) {
            return;
        }
        try {
            if ((string)$this->config->get('shipping_courier_center_email_tracking_enabled') === '0') {
                return;
            }
            $order_id = (int)($args[0] ?? 0);
            if ($order_id <= 0 || empty($args[3])) {
                return;
            }
            $this->ccBoot();
            $shipment = $this->ccService()->getShipment($order_id);
            if (empty($shipment['voucher_number']) || !empty($shipment['is_voided'])) {
                return;
            }
            $o = $this->db->query("SELECT `email`, `firstname`, `lastname` FROM `" . DB_PREFIX . "order` WHERE `order_id` = '" . $order_id . "' LIMIT 1");
            if (!$o->num_rows || $o->row['email'] === '') {
                return;
            }
            $awb = $this->ccText($shipment['voucher_number']);
            $code = $this->ccText($shipment['tracking_number'] !== '' ? $shipment['tracking_number'] : $shipment['voucher_number']);
            $template = $this->ccText($this->config->get('shipping_courier_center_tracking_url'));
            if ($template === '') {
                $template = 'https://www.courier.gr/track/result?tracknr={{tracking}}';
            }
            $url = str_replace('{{tracking}}', urlencode($code), $template);
            $name = trim($this->ccText($o->row['firstname']) . ' ' . $this->ccText($o->row['lastname']));

            $engine = (string)$this->config->get('config_mail_engine');
            CCErrorGuard::start($this->ccLogger()); // mail() warnings go to the log, never into the page
            try {
                $mail = new Mail($engine !== '' ? $engine : 'mail');
                $mail->parameter = $this->config->get('config_mail_parameter');
                $mail->smtp_hostname = $this->config->get('config_mail_smtp_hostname');
                $mail->smtp_username = $this->config->get('config_mail_smtp_username');
                $mail->smtp_password = html_entity_decode((string)$this->config->get('config_mail_smtp_password'), ENT_QUOTES, 'UTF-8');
                $mail->smtp_port = $this->config->get('config_mail_smtp_port');
                $mail->smtp_timeout = $this->config->get('config_mail_smtp_timeout');
                $mail->setTo($o->row['email']);
                $mail->setFrom($this->config->get('config_email'));
                $mail->setSender($this->ccText($this->config->get('config_name')));
                $mail->setSubject('Στοιχεία Αποστολής — Παραγγελία #' . $order_id);
                $mail->setHtml($this->htmlBody($awb, $url, $name));
                $mail->setText($this->textBody($awb, $url));
                $mail->send();
            } catch (\Throwable $e) {
                $this->log->write('Courier Center tracking email: ' . $e->getMessage());
            } finally {
                CCErrorGuard::stop();
            }
        } catch (\Throwable $e) {
            $this->log->write('Courier Center tracking email: ' . $e->getMessage());
        }
    }

    private function htmlBody($awb, $url, $name) {
        $n = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $a = htmlspecialchars($awb, ENT_QUOTES, 'UTF-8');
        $u = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        return <<<HTML
<div style="margin:0 0 30px; padding:20px; background:#f6f9fc; border-left:4px solid #2271b1; border-radius:4px; font-family:Arial,sans-serif;">
  <p style="margin:0 0 12px; font-size:16px; font-weight:bold; color:#1d2327; border-bottom:1px solid #dce3e8; padding-bottom:10px;">Στοιχεία Αποστολής Courier Center</p>
  <p style="margin:0 0 10px; color:#2c3338; font-size:14px;">Αγαπητέ/ή <strong>$n</strong>,<br>η παραγγελία σας έχει δρομολογηθεί μέσω <strong>Courier Center</strong>.</p>
  <table cellpadding="0" cellspacing="0" border="0" style="width:100%; margin:10px 0;">
    <tr>
      <td style="padding:6px 0; color:#50575e; font-size:14px; width:180px;">Αριθμός Αποστολής:</td>
      <td style="padding:6px 0; color:#1d2327; font-size:14px; font-weight:bold;">$a</td>
    </tr>
  </table>
  <p style="margin:15px 0 0;"><a href="$u" style="display:inline-block; padding:10px 20px; background:#2271b1; color:#ffffff; text-decoration:none; border-radius:4px; font-size:14px;">Παρακολούθηση Αποστολής &rarr;</a></p>
  <p style="margin:15px 0 0; color:#787c82; font-size:12px;">Μπορείτε να παρακολουθείτε την αποστολή σας με τον αριθμό αποστολής στο <a href="https://www.courier.gr" style="color:#2271b1;">courier.gr</a>.</p>
</div>
HTML;
    }

    private function textBody($awb, $url) {
        return "\n=====================================\nΣΤΟΙΧΕΙΑ ΑΠΟΣΤΟΛΗΣ - COURIER CENTER\n=====================================\n"
            . "Αριθμός Αποστολής: $awb\nΠαρακολούθηση: $url\n=====================================\n\n";
    }
}
