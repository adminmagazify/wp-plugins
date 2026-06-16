<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Merkezden gelen gönderim bölgelerini (shipping zones) WooCommerce'de oluşturur.
 *
 * Idempotent: her gönderimde önce DAHA ÖNCE merkezin oluşturduğu bölgeleri siler
 * (kendi ID'lerini bir option'da tutar), sonra payload'tan yeniden kurar. Sitenin
 * elle oluşturduğu bölgelere dokunmaz.
 */
class WPD_Shipping {

    const MAP_OPTION = 'wpd_shipping_zone_ids';
    const VALID_TYPES = ['flat_rate', 'free_shipping', 'local_pickup'];

    public static function apply($zones) {
        if (!class_exists('WC_Shipping_Zones') || !is_array($zones)) {
            return;
        }

        // Önceki merkez-yönetimli bölgeleri sil
        $prev = get_option(self::MAP_OPTION, []);
        if (is_array($prev)) {
            foreach ($prev as $zid) {
                WC_Shipping_Zones::delete_zone((int) $zid);
            }
        }

        $new_ids = [];
        $pos = 0;
        foreach ($zones as $zdata) {
            if (empty($zdata['name'])) {
                continue;
            }
            $zone = new WC_Shipping_Zone();
            $zone->set_zone_name(sanitize_text_field($zdata['name']));
            $zone->set_zone_order($pos++);

            $zone->clear_locations();
            $regions = isset($zdata['regions']) && is_array($zdata['regions']) ? $zdata['regions'] : [];
            foreach ($regions as $code) {
                $code = strtoupper(sanitize_text_field($code));
                if ($code !== '') {
                    $zone->add_location($code, 'country');
                }
            }

            $zone_id = $zone->save();
            $new_ids[] = $zone_id;

            // Yöntemler (kargo firmaları + fiyatlar)
            $methods = isset($zdata['methods']) && is_array($zdata['methods']) ? $zdata['methods'] : [];
            foreach ($methods as $m) {
                $type = isset($m['type']) ? $m['type'] : '';
                if (!in_array($type, self::VALID_TYPES, true)) {
                    continue;
                }
                $instance_id = $zone->add_shipping_method($type);
                if (!$instance_id) {
                    continue;
                }
                self::configure_method($type, $instance_id, $m);
            }
        }

        update_option(self::MAP_OPTION, $new_ids);
    }

    /** Yöntemin başlık/ücret ayarlarını WooCommerce option'ına yazar. */
    protected static function configure_method($type, $instance_id, $m) {
        $option   = 'woocommerce_' . $type . '_' . $instance_id . '_settings';
        $settings = get_option($option, []);
        if (!is_array($settings)) {
            $settings = [];
        }

        $title = isset($m['title']) ? sanitize_text_field($m['title']) : '';
        if ($title !== '') {
            $settings['title'] = $title;
        }

        if ($type === 'flat_rate') {
            $cost = (isset($m['cost']) && $m['cost'] !== null && $m['cost'] !== '') ? (string) floatval($m['cost']) : '0';
            $settings['cost'] = $cost;
        } elseif ($type === 'free_shipping') {
            $min = (isset($m['minAmount']) && $m['minAmount'] !== null && $m['minAmount'] !== '') ? (string) floatval($m['minAmount']) : '';
            if ($min !== '') {
                $settings['requires']   = 'min_amount';
                $settings['min_amount'] = $min;
            } else {
                $settings['requires'] = '';
            }
        }

        update_option($option, $settings);
    }
}
