<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Çift yönlü stok — site → merkez bildirimi.
 * Sitede bir sipariş stoğu düşürünce (veya iade stoğu geri yükleyince) merkeze
 * delta gönderir. Merkez tek ortak havuzu günceller ve tüm sitelere yayar.
 *
 * Yalnızca merkezden gelen ürünleri (SKU "central-...") bildirir.
 */
class WPD_Stock {

    /** Sipariş stoğu düşürdü → satış (pozitif delta) */
    public static function on_reduce($order) {
        self::report($order, 1);
    }

    /** Sipariş stoğu geri yükledi → iptal/iade (negatif delta) */
    public static function on_restore($order) {
        self::report($order, -1);
    }

    protected static function report($order, $sign) {
        if (!($order instanceof WC_Order)) {
            $order = wc_get_order($order);
        }
        if (!$order) {
            return;
        }

        $items = [];
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }
            $qty = (int) $item->get_quantity();
            if ($qty <= 0) {
                continue;
            }

            $is_var    = $product->is_type('variation');
            $parent_id = $is_var ? $product->get_parent_id() : $product->get_id();
            $central_id = (int) get_post_meta($parent_id, '_wpd_central_id', true);

            // Varyasyonsa beden değerini al
            $size = '';
            if ($is_var) {
                $attrs = $product->get_attributes();
                $size = isset($attrs['beden']) ? $attrs['beden'] : '';
            }

            if ($central_id) {
                $items[] = ['centralId' => $central_id, 'size' => $size, 'delta' => $sign * $qty];
            } else {
                // Geriye dönük: _wpd_central_id yoksa eski central-{id} SKU'su
                $sku = (string) $product->get_sku();
                if (strpos($sku, 'central-') !== 0) {
                    continue; // merkez ürünü değil, atla
                }
                $items[] = ['sku' => $sku, 'delta' => $sign * $qty];
            }
        }

        if (empty($items)) {
            return;
        }

        self::send($items);
    }

    /** Merkeze fire-and-forget bildirim (checkout'u yavaşlatmaz) */
    protected static function send($items) {
        $key = WPD_Api_Client::get_api_key();
        if (!$key) {
            return;
        }
        wp_remote_post(WPD_Api_Client::central_url() . '/api/public/stock-report', [
            'timeout'  => 15,
            'blocking' => false, // yanıtı bekleme
            'headers'  => [
                'Content-Type' => 'application/json',
                'X-API-Key'    => $key,
            ],
            'body' => wp_json_encode(['items' => $items]),
        ]);
    }
}
