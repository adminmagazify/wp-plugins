<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Merkezden gelen kampanyaları (indirim) WooCommerce ürünlerine uygular.
 * REPLACE mantığı: her push'ta önce daha önce kampanyayla indirilen ürünler eski haline döner,
 * sonra gelen aktif kampanyaların indirimi (sale_price + date_on_sale_from/to) uygulanır.
 * Süre WooCommerce tarafından yönetilir; end date geçince indirim otomatik kalkar.
 */
class WPD_Campaign {

    public static function apply($campaigns) {
        $cleared  = self::clear_all();
        $affected = 0;
        foreach ($campaigns as $c) {
            $affected += self::apply_one($c);
        }
        return ['affected' => $affected, 'cleared' => $cleared];
    }

    /** Daha önce kampanyayla indirilen ürünleri eski sale_price'ına döndürür. */
    private static function clear_all() {
        $q = new WP_Query([
            'post_type'      => 'product',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_key'       => '_wpd_campaign',
        ]);
        $n = 0;
        foreach ($q->posts as $pid) {
            $product = wc_get_product($pid);
            if (!$product) {
                continue;
            }
            $prev = get_post_meta($pid, '_wpd_precampaign_sale', true);
            $product->set_sale_price($prev !== '' ? $prev : '');
            $product->set_date_on_sale_from(null);
            $product->set_date_on_sale_to(null);
            $product->save();
            delete_post_meta($pid, '_wpd_campaign');
            delete_post_meta($pid, '_wpd_precampaign_sale');
            $n++;
        }
        return $n;
    }

    private static function apply_one($c) {
        $ids  = self::resolve_products($c);
        $type = isset($c['discountType']) ? $c['discountType'] : 'percent';
        $val  = isset($c['discountValue']) ? floatval($c['discountValue']) : 0;
        $from = isset($c['startAt']) ? self::to_datetime($c['startAt']) : '';
        $to   = isset($c['endAt']) ? self::to_datetime($c['endAt']) : '';
        $cid  = isset($c['id']) ? intval($c['id']) : 0;
        $n = 0;
        foreach ($ids as $pid) {
            $product = wc_get_product($pid);
            if (!$product) {
                continue;
            }
            $regular = floatval($product->get_regular_price());
            if ($regular <= 0) {
                continue;
            }
            $sale = ($type === 'fixed') ? max(0, $regular - $val) : round($regular * (1 - $val / 100), 2);
            if ($sale >= $regular) {
                continue;
            }
            // Kampanya öncesi sale_price'ı bir kez sakla (geri dönüş için)
            if (get_post_meta($pid, '_wpd_precampaign_sale', true) === '') {
                update_post_meta($pid, '_wpd_precampaign_sale', $product->get_sale_price());
            }
            $product->set_sale_price($sale);
            if ($from) $product->set_date_on_sale_from($from);
            if ($to)   $product->set_date_on_sale_to($to);
            $product->save();
            update_post_meta($pid, '_wpd_campaign', $cid);
            $n++;
        }
        return $n;
    }

    /** Kampanya kapsamındaki ürün ID'lerini bulur (all = tüm merkez ürünleri / categories / products). */
    private static function resolve_products($c) {
        $scope = isset($c['scope']) ? $c['scope'] : 'all';

        if ($scope === 'products' && !empty($c['productIds'])) {
            $ids = [];
            foreach ((array) $c['productIds'] as $central) {
                $pid = WPD_Product_Sync::find_by_central_id(intval($central));
                if ($pid) $ids[] = $pid;
            }
            return array_values(array_unique($ids));
        }

        $args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ];

        if ($scope === 'categories' && !empty($c['categoryNames'])) {
            $slugs = array_map('sanitize_title', (array) $c['categoryNames']);
            $args['tax_query'] = [[
                'taxonomy' => 'product_cat',
                'field'    => 'slug',
                'terms'    => $slugs,
            ]];
        } else {
            // scope 'all' → yalnızca merkez-yönetimli ürünler (_wpd_central_id meta'sı olanlar)
            $args['meta_key'] = '_wpd_central_id';
        }

        $q = new WP_Query($args);
        return $q->posts;
    }

    private static function to_datetime($iso) {
        $t = strtotime($iso);
        return $t ? date('Y-m-d H:i:s', $t) : '';
    }
}
