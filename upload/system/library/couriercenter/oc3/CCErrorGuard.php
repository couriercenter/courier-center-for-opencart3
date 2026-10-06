<?php
namespace Opencart\Extension\Couriercenter\Oc3;

/**
 * Keeps PHP notices / warnings / deprecations out of our JSON, PDF and cron output
 * (spec 5.11). OpenCart 3 prints them into the response when "Display errors" is on,
 * which is the default, and that corrupts JSON and PDF bodies.
 */
class CCErrorGuard {
    private static $depth = 0;
    private static $logger = null;

    /** @param callable|null $logger function (string $line), e.g. OpenCart's $log->write */
    public static function start($logger = null) {
        self::$logger = $logger;
        self::$depth++;
        set_error_handler(function ($errno, $errstr, $errfile, $errline) {
            if (!(error_reporting() & $errno)) {
                return false; // silenced with @
            }
            if (self::$logger) {
                call_user_func(self::$logger, 'Courier Center: PHP error ' . $errno . ': ' . $errstr . ' in ' . $errfile . ' on line ' . $errline);
            }
            return true; // handled: nothing reaches the output
        });
    }

    public static function stop() {
        if (self::$depth > 0) {
            self::$depth--;
            restore_error_handler();
        }
    }

    /** Drop anything already buffered (stray notices) before binary / JSON output. */
    public static function cleanBuffers() {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }

    /** A fatal error in a JSON endpoint answers JSON instead of an HTML page. */
    public static function jsonOnFatal() {
        register_shutdown_function(function () {
            $err = error_get_last();
            if ($err && in_array($err['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
                if (!headers_sent()) {
                    header('Content-Type: application/json');
                }
                echo json_encode(array('success' => false, 'error' => 'PHP Fatal: ' . $err['message'] . ' @ ' . basename($err['file']) . ':' . $err['line']));
            }
        });
    }
}
