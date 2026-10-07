<?php
/**
 * Courier Center - checkout quotes (OpenCart 3). Port of catalog/model/shipping/courier_center.php (OC4).
 * PHP 5.4 syntax on purpose: OpenCart loads this file on every checkout once the extension row exists,
 * so it must never break a shop, not even on PHP 5.x (spec 5.2, 5.11).
 */
class ModelExtensionShippingCourierCenter extends Model {
    public function getQuote($address) {
        if (!$this->config->get('shipping_courier_center_status')) {
            return array();
        }
        if (version_compare(PHP_VERSION, '7.2.0', '<')) {
            return array(); // the extension needs PHP 7.2+
        }
        $this->load->language('extension/shipping/courier_center');

        $geo_zone_id = (int)$this->config->get('shipping_courier_center_geo_zone_id');
        if ($geo_zone_id) {
            $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "zone_to_geo_zone` WHERE `geo_zone_id` = '" . $geo_zone_id . "' AND `country_id` = '" . (int)$address['country_id'] . "' AND (`zone_id` = '" . (int)$address['zone_id'] . "' OR `zone_id` = '0')");
            if (!$query->num_rows) {
                return array();
            }
        }

        $tax_class_id = (int)$this->config->get('shipping_courier_center_tax_class_id');
        $cost = (float)$this->config->get('shipping_courier_center_cost');
        $cod_fee = (float)$this->config->get('shipping_courier_center_cod_fee');

        $quote_data = array();
        $quote_data['courier_center'] = array(
            'code'         => 'courier_center.courier_center',
            'title'        => $this->language->get('text_delivery'),
            'cost'         => $cost,
            'tax_class_id' => $tax_class_id,
            'text'         => $this->currency->format($this->tax->calculate($cost, $tax_class_id, $this->config->get('config_tax')), $this->session->data['currency']),
        );
        // OpenCart asks for the shipping method before the payment method, so cash on
        // delivery is a separate quote that includes the surcharge (same as OC4).
        if ($cod_fee > 0) {
            $cod_cost = $cost + $cod_fee;
            $quote_data['courier_center_cod'] = array(
                'code'         => 'courier_center.courier_center_cod',
                'title'        => $this->language->get('text_delivery_cod'),
                'cost'         => $cod_cost,
                'tax_class_id' => $tax_class_id,
                'text'         => $this->currency->format($this->tax->calculate($cod_cost, $tax_class_id, $this->config->get('config_tax')), $this->session->data['currency']),
            );
        }

        return array(
            'code'       => 'courier_center',
            'title'      => $this->language->get('heading_title'),
            'quote'      => $quote_data,
            'sort_order' => $this->config->get('shipping_courier_center_sort_order'),
            'error'      => false,
        );
    }
}
