<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Merkezin ürünleri push ettiği REST endpoint.
 * URL: POST /wp-json/wp-distributor/v1/sync
 * Doğrulama: X-Central-Key + X-Central-Secret header'ları saklı değerlerle eşleşmeli.
 */
class WPD_Rest_Endpoint {

    public static function register_routes() {
        register_rest_route('wp-distributor/v1', '/sync', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_sync'],
            'permission_callback' => [__CLASS__, 'check_auth'],
        ]);
    }

    /** Gelen isteğin merkezden geldiğini doğrular */
    public static function check_auth($request) {
        $key    = (string) $request->get_header('x-central-key');
        $secret = (string) $request->get_header('x-central-secret');

        $stored_key    = WPD_Api_Client::get_api_key();
        $stored_secret = WPD_Api_Client::get_api_secret();

        if (!$stored_key || !$stored_secret) {
            return false;
        }
        return hash_equals($stored_key, $key) && hash_equals($stored_secret, $secret);
    }

    /** Ürünleri ekler/günceller ve silinmesi gerekenleri kaldırır */
    public static function handle_sync($request) {
        $body         = $request->get_json_params();
        $products     = isset($body['products']) && is_array($body['products']) ? $body['products'] : [];
        $deletes      = isset($body['deletes']) && is_array($body['deletes']) ? $body['deletes'] : [];
        $stockUpdates = isset($body['stockUpdates']) && is_array($body['stockUpdates']) ? $body['stockUpdates'] : [];
        $results      = [];

        // Gönderim bölgeleri (shipping zones) — WooCommerce'de oluştur/güncelle
        if (isset($body['shippingZones']) && is_array($body['shippingZones'])) {
            try {
                WPD_Shipping::apply($body['shippingZones']);
                $results[] = ['type' => 'shippingZones', 'status' => 'applied', 'count' => count($body['shippingZones'])];
            } catch (Exception $e) {
                $results[] = ['type' => 'shippingZones', 'status' => 'error', 'error' => $e->getMessage()];
            }
        }

        // Sadece stok güncellemesi (çift yönlü stok yayılımı) — ürünleri yeniden oluşturmaz
        foreach ($stockUpdates as $su) {
            $sku = isset($su['sku']) ? $su['sku'] : '';
            try {
                WPD_Product_Sync::apply_stock($su);
                $results[] = ['sku' => $sku, 'status' => 'stock-updated'];
            } catch (Exception $e) {
                $results[] = ['sku' => $sku, 'status' => 'error', 'error' => $e->getMessage()];
            }
        }

        foreach ($products as $item) {
            $pid = isset($item['id']) ? intval($item['id']) : 0;
            try {
                WPD_Product_Sync::upsert($item);
                $results[] = ['productId' => $pid, 'status' => 'success'];
            } catch (Exception $e) {
                $results[] = ['productId' => $pid, 'status' => 'error', 'error' => $e->getMessage()];
            }
        }

        // Merkezden silinen ürünleri bu siteden de kaldır
        foreach ($deletes as $delId) {
            $pid = intval($delId);
            try {
                WPD_Product_Sync::delete_by_central_id($pid);
                $results[] = ['productId' => $pid, 'status' => 'deleted'];
            } catch (Exception $e) {
                $results[] = ['productId' => $pid, 'status' => 'error', 'error' => $e->getMessage()];
            }
        }

        return rest_ensure_response(['results' => $results]);
    }
}
