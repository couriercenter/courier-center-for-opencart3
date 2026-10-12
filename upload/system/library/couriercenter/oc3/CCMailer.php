<?php
namespace Opencart\Extension\Couriercenter\Oc3;

/**
 * Sends one email with the shop's own mail settings. Used by the web controllers and by the cron job from the
 * command line (where OpenCart's classes are not loaded): it loads OpenCart's Mail class and the adaptor itself.
 */
class CCMailer {

    /**
     * @param object      $config OpenCart Config or CCConfigSnapshot (get())
     * @param string|null $error  set to the failure text (null on success)
     * @return bool
     */
    public static function send($config, $to, $subject, $html, $text, &$error = null) {
        $error = null;
        try {
            if (!class_exists('Mail', false)) {
                require_once DIR_SYSTEM . 'library/mail.php';
            }
            $engine = trim((string)$config->get('config_mail_engine'));
            $engine = $engine !== '' ? $engine : 'mail';
            $adaptor = DIR_SYSTEM . 'library/mail/' . basename($engine) . '.php';
            if (is_file($adaptor)) {
                require_once $adaptor;
            }
            $mail = new \Mail($engine);
            $mail->parameter = $config->get('config_mail_parameter');
            $mail->smtp_hostname = $config->get('config_mail_smtp_hostname');
            $mail->smtp_username = $config->get('config_mail_smtp_username');
            $mail->smtp_password = html_entity_decode((string)$config->get('config_mail_smtp_password'), ENT_QUOTES, 'UTF-8');
            $mail->smtp_port = $config->get('config_mail_smtp_port');
            $mail->smtp_timeout = $config->get('config_mail_smtp_timeout');
            $mail->setTo($to);
            $mail->setFrom($config->get('config_email'));
            $mail->setSender(html_entity_decode((string)$config->get('config_name'), ENT_QUOTES, 'UTF-8'));
            $mail->setSubject($subject);
            $mail->setHtml($html);
            $mail->setText($text);
            $mail->send();
            return true;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            return false;
        }
    }
}
