<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Satış paneli — sipariş raporlama.
 * Sipariş "processing" veya "completed" durumuna geçince, merkez ürünü içeren
 * siparişi bir kez merkeze raporlar (satış/ciro/kâr dashboard'u için).
 */
class WPD_Orders {

    public static function on_status($order_id, $old_status, $new_status, $order = null) {
        // Ödeme onaylı (processing/completed) + havale/EFT (on-hold) siparişleri raporla
        if (!in_array($new_status, ['processing', 'completed', 'on-hold'], true)) {
            return;
        }
        if (!($order instanceof WC_Order)) {
            $order = wc_get_order($order_id);
        }
        if (!$order || $order->get_meta('_wpd_order_reported')) {
            return; // yok ya da zaten raporlandı
        }

        $items = [];
        foreach ($order->get_items() as $line) {
            $product = $line->get_product();
            if (!$product) {
                continue;
            }
            $is_var    = $product->is_type('variation');
            $parent_id = $is_var ? $product->get_parent_id() : $product->get_id();
            $central_id = (int) get_post_meta($parent_id, '_wpd_central_id', true);
            if (!$central_id) {
                continue; // merkez ürünü değil
            }
            $size = '';
            if ($is_var) {
                $attrs = $product->get_attributes();
                $size = isset($attrs['beden']) ? $attrs['beden'] : '';
            }
            $qty = (int) $line->get_quantity();
            $items[] = [
                'centralId' => $central_id,
                'sku'       => (string) $product->get_sku(),
                'size'      => $size,
                'name'      => $line->get_name(),
                'qty'       => $qty,
                'price'     => $qty > 0 ? round(((float) $line->get_total()) / $qty, 2) : (float) $line->get_total(),
            ];
        }

        if (empty($items)) {
            return; // merkez ürünü içermiyor, raporlama
        }

        $key = WPD_Api_Client::get_api_key();
        if (!$key) {
            return;
        }

        $payload = [
            'externalId'   => (string) $order->get_id(),
            'total'        => (float) $order->get_total(),
            'currency'     => $order->get_currency(),
            'status'       => $new_status,
            'customerName' => trim($order->get_formatted_billing_full_name()),
            'orderedAt'    => $order->get_date_created() ? $order->get_date_created()->date('c') : null,
            'items'        => $items,
        ];

        $res = wp_remote_post(WPD_Api_Client::central_url() . '/api/public/orders', [
            'timeout' => 10,
            'headers' => [
                'Content-Type'     => 'application/json',
                'X-API-Key'        => $key,
                'X-Plugin-Version' => WPD_VERSION,
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (!is_wp_error($res) && wp_remote_retrieve_response_code($res) < 300) {
            $order->update_meta_data('_wpd_order_reported', 1);
            $order->save();
        }
    }
}
