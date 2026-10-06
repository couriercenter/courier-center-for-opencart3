<?php
namespace Opencart\Extension\Couriercenter\Oc3;

/**
 * Extension version + PHP gate (spec 5.11). PHP 5.4 syntax on purpose: it runs
 * before we know whether the host can load the PHP 7.2 library.
 */
class CCVersion {
    const MIN_PHP = '7.2.0';

    /** Version from version.json, "0.0.0" when missing or broken. */
    public static function get() {
        $file = dirname(__DIR__) . '/version.json';
        if (!is_file($file)) {
            return '0.0.0';
        }
        $data = json_decode((string)file_get_contents($file), true);
        return (is_array($data) && !empty($data['version'])) ? (string)$data['version'] : '0.0.0';
    }

    /** @param string|null $php_version for tests; null = the running PHP */
    public static function phpSupported($php_version = null) {
        $version = $php_version === null ? PHP_VERSION : (string)$php_version;
        return version_compare($version, self::MIN_PHP, '>=');
    }
}
