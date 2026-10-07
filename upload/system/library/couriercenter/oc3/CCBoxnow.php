<?php
namespace Opencart\Extension\Couriercenter\Oc3;

/** BOX NOW checkout rules for OpenCart 3 (pure functions, spec 5.7). */
class CCBoxnow {
    const PARTNER_ID = 10853;

    /** Checkout pages get the widget; the cart (shipping estimate), success, failure and voucher pages do not. */
    public static function routeWantsWidget($route) {
        $route = strtolower(trim((string)$route));
        if ($route === '' || strpos($route, 'checkout') === false) {
            return false;
        }
        foreach (array('checkout/cart', 'checkout/success', 'checkout/failure', 'checkout/voucher') as $skip) {
            if (strpos($route, $skip) === 0) {
                return false;
            }
        }
        return true;
    }

    /**
     * Normalise a saveSession POST (or the stored session value). null = no BOX NOW.
     * A choice without a complete mode becomes "auto" (nearest locker): OpenCart 3 writes
     * the order when the confirm step LOADS, before any "Confirm" click could complete it.
     */
    public static function sanitizeSelection(array $in) {
        if (empty($in['selected'])) {
            return null;
        }
        $id = self::clip(trim((string)($in['locker_id'] ?? '')), 64);
        if ((string)($in['delivery_mode'] ?? '') !== 'pick' || $id === '') {
            return array('selected' => true, 'delivery_mode' => 'auto', 'locker_id' => '', 'locker_code' => '', 'locker_name' => '');
        }
        $code = self::clip(trim((string)($in['locker_code'] ?? '')), 64);
        // OpenCart HTML-escapes POST data and the BOX NOW SDK sends entities too: store plain text.
        $name = self::clip(trim(html_entity_decode((string)($in['locker_name'] ?? ''), ENT_QUOTES, 'UTF-8')), 255);
        return array('selected' => true, 'delivery_mode' => 'pick', 'locker_id' => $id, 'locker_code' => $code !== '' ? $code : $id, 'locker_name' => $name);
    }

    /** Merge a selection into an order's custom_field (array or JSON string). */
    public static function applyToCustomField($custom_field, array $sel) {
        $cf = is_array($custom_field) ? $custom_field : json_decode((string)$custom_field, true);
        if (!is_array($cf)) {
            $cf = array();
        }
        $cf['cc_delivery_mode'] = (string)$sel['delivery_mode'];
        $cf['cc_locker_id'] = (string)$sel['locker_id'];
        $cf['cc_locker_code'] = (string)$sel['locker_code'];
        $cf['cc_locker_name'] = (string)$sel['locker_name'];
        return $cf;
    }

    /** Is BOX NOW offered for this shipping code ("courier_center.courier_center")? */
    public static function methodAllowed($shipping_code, array $allowed) {
        $code = (string)$shipping_code;
        $dot = strpos($code, '.');
        $prefix = $dot === false ? $code : substr($code, 0, $dot);
        return $prefix !== '' && in_array($prefix, array_map('strval', $allowed), true);
    }

    private static function clip($s, $max) {
        return function_exists('mb_substr') ? mb_substr($s, 0, $max, 'UTF-8') : substr($s, 0, $max);
    }
}
