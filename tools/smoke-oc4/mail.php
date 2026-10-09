<?php
/** Point the local OC4 shop's mail at the local SMTP sink for a test, then put the shop's own values back. */
function cc_mail_smtp_on($m, $P) {
    $GLOBALS['cc_mail_backup'] = array();
    $want = array('config_mail_engine' => 'smtp', 'config_mail_smtp_hostname' => '127.0.0.1', 'config_mail_smtp_port' => '1025', 'config_mail_smtp_timeout' => '5', 'config_mail_smtp_username' => 'sink', 'config_mail_smtp_password' => 'sink');
    foreach ($want as $k => $v) {
        $r = $m->query("SELECT * FROM `{$P}setting` WHERE `store_id` = 0 AND `key` = '$k'")->fetch_all(MYSQLI_ASSOC);
        $GLOBALS['cc_mail_backup'][$k] = $r ? $r[0] : null;
        $m->query("DELETE FROM `{$P}setting` WHERE `store_id` = 0 AND `key` = '$k'");
        $m->query("INSERT INTO `{$P}setting` SET `store_id` = 0, `code` = 'config', `key` = '$k', `value` = '$v', `serialized` = 0");
    }
}
function cc_mail_smtp_off($m, $P) {
    foreach (isset($GLOBALS['cc_mail_backup']) ? $GLOBALS['cc_mail_backup'] : array() as $k => $row) {
        $m->query("DELETE FROM `{$P}setting` WHERE `store_id` = 0 AND `key` = '$k'");
        if ($row) {
            $m->query("INSERT INTO `{$P}setting` SET `store_id` = 0, `code` = '" . $m->real_escape_string($row['code']) . "', `key` = '$k', `value` = '" . $m->real_escape_string($row['value']) . "', `serialized` = '" . (int)$row['serialized'] . "'");
        }
    }
}
