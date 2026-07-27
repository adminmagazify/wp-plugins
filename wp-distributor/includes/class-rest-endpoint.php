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
        $drafts       = isset($body['drafts']) && is_array($body['drafts']) ? $body['drafts'] : [];       // pasifleştir → taslağa çek
        $undrafts     = isset($body['undrafts']) && is_array($body['undrafts']) ? $body['undrafts'] : []; // geri aktif → yeniden yayınla
        $stockUpdates = isset($body['stockUpdates']) && is_array($body['stockUpdates']) ? $body['stockUpdates'] : [];
        $results      = [];

        // Media temizliği: eski dış-URL pointer attachment'larını (1.2.9) batch batch sil.
        // 119 bin kayıt tek istekte silinemez → merkez, remaining>0 olduğu sürece tekrar çağırır.
        if (!empty($body['purgeMedia'])) {
            $limit = isset($body['purgeMediaLimit']) ? max(1, intval($body['purgeMediaLimit'])) : 400;
            $q = new WP_Query([
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => $limit,
                'fields'         => 'ids',
                'meta_key'       => '_wpd_ext_url',
            ]);
            $deleted = 0;
            foreach ($q->posts as $aid) {
                wp_delete_post($aid, true);
                $deleted++;
            }
            $remaining = max(0, (int) $q->found_posts - $deleted);
            return rest_ensure_response(['results' => [[
                'type' => 'purgeMedia', 'status' => 'done', 'deleted' => $deleted, 'remaining' => $remaining,
            ]]]);
        }

        // Uzaktan CACHE TEMİZLEME (merkez panel → "Cache Temizle").
        // Site tarafındaki tüm katmanları boşaltır + eklenti sürüm önbelleğini tazeler
        // (sürüm/güncelleme "takılı kaldı" sorununu da çözer).
        if (!empty($body['purgeCache'])) {
            $cleared = [];
            if (function_exists('wc_delete_product_transients')) { wc_delete_product_transients(); $cleared[] = 'wc-transients'; }
            if (function_exists('wp_cache_flush')) { wp_cache_flush(); $cleared[] = 'object-cache'; }
            do_action('litespeed_purge_all'); $cleared[] = 'litespeed';
            if (function_exists('rocket_clean_domain')) { rocket_clean_domain(); $cleared[] = 'wp-rocket'; }
            if (function_exists('w3tc_flush_all')) { w3tc_flush_all(); $cleared[] = 'w3tc'; }
            // Eklenti sürüm/güncelleme önbelleği
            if (function_exists('wp_clean_plugins_cache')) { wp_clean_plugins_cache(true); $cleared[] = 'plugin-cache'; }
            delete_site_transient('update_plugins'); $cleared[] = 'update-transient';
            if (class_exists('WPD_Updater')) { WPD_Updater::clear_cache(); $cleared[] = 'wpd-update-cache'; }
            return rest_ensure_response(['results' => [[
                'type' => 'purgeCache', 'status' => 'done', 'cleared' => $cleared,
            ]]]);
        }

        // UZAKTAN EKLENTİ GÜNCELLEME (merkez panel → "Eklentiyi Güncelle").
        // 1000 sitede tek tek wp-admin'e girmeden güncelleme yapmak için.
        if (!empty($body['updatePlugin'])) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/misc.php';
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

            // Hedef eklenti: varsayılan kendisi (wp-distributor)
            $target = isset($body['plugin']) ? ltrim((string) $body['plugin'], '/') : plugin_basename(WPD_PATH . 'wp-distributor.php');
            $before = function_exists('get_file_data')
                ? (string) get_file_data(WP_PLUGIN_DIR . '/' . $target, ['Version' => 'Version'])['Version']
                : WPD_VERSION;

            // Güncelleme bilgisini tazele (cache yüzünden "güncelleme yok" demesin)
            if (class_exists('WPD_Updater')) { WPD_Updater::clear_cache(); }
            wp_clean_plugins_cache(true);
            delete_site_transient('update_plugins');
            wp_update_plugins();

            $skin     = new Automatic_Upgrader_Skin();
            $upgrader = new Plugin_Upgrader($skin);
            $ok       = $upgrader->upgrade($target);

            clearstatcache();
            $after = (string) get_file_data(WP_PLUGIN_DIR . '/' . $target, ['Version' => 'Version'])['Version'];

            // Güncelleme sonrası eklenti pasife düşmüşse tekrar etkinleştir
            if (!is_plugin_active($target)) { activate_plugin($target); }

            $status = is_wp_error($ok) ? 'error' : (($after !== $before) ? 'updated' : 'nochange');
            return rest_ensure_response(['results' => [[
                'type'     => 'updatePlugin',
                'status'   => $status,
                'plugin'   => $target,
                'before'   => $before,
                'after'    => $after,
                'error'    => is_wp_error($ok) ? $ok->get_error_message() : null,
                'messages' => array_slice((array) $skin->get_upgrade_messages(), -6),
            ]]]);
        }

        // Markalar (ürüne bağlamadan) — WooCommerce product_brand term'leri olarak oluştur
        if (isset($body['brands']) && is_array($body['brands'])) {
            $applied = 0;
            foreach ($body['brands'] as $b) {
                $name = isset($b['name']) ? $b['name'] : '';
                $slug = isset($b['slug']) ? $b['slug'] : '';
                if ($name && WPD_Product_Sync::ensure_brand_term($name, $slug)) {
                    $applied++;
                }
            }
            $results[] = ['type' => 'brands', 'status' => 'applied', 'count' => $applied];
        }

        // Kategoriler (ürüne bağlamadan) — WooCommerce product_cat term'leri olarak oluştur
        if (isset($body['categories']) && is_array($body['categories'])) {
            $applied = 0;
            foreach ($body['categories'] as $cat) {
                $name  = isset($cat['name']) ? $cat['name'] : '';
                $slug  = isset($cat['slug']) ? $cat['slug'] : '';
                $image = isset($cat['image']) ? $cat['image'] : '';
                if ($name && WPD_Product_Sync::ensure_category($name, $slug, $image)) {
                    $applied++;
                }
            }
            $results[] = ['type' => 'categories', 'status' => 'applied', 'count' => $applied];
        }

        // Beden tabloları (ürüne bağlamadan) — Loobek ts_size_chart olarak oluştur/güncelle
        if (isset($body['sizeCharts']) && is_array($body['sizeCharts'])) {
            $applied = 0;
            $sentIds = [];
            foreach ($body['sizeCharts'] as $chart) {
                if (isset($chart['id'])) {
                    $sentIds[] = intval($chart['id']);
                }
                if (WPD_Product_Sync::upsert_size_chart_post($chart)) {
                    $applied++;
                }
            }
            // Tam senkron: merkezde artık olmayan (silinen) beden tablolarını sitede de sil
            $deleted = WPD_Product_Sync::prune_size_charts($sentIds);
            $results[] = ['type' => 'sizeCharts', 'status' => 'applied', 'count' => $applied, 'deleted' => $deleted];
        }

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

        // Merkezde pasifleştirilen ürünleri SİLMEDEN taslağa çek (vitrinden kalkar, veri kalır)
        foreach ($drafts as $draftId) {
            $pid = intval($draftId);
            try {
                $st = WPD_Product_Sync::set_status_by_central_id($pid, 'draft');
                $results[] = ['productId' => $pid, 'status' => $st];
            } catch (Exception $e) {
                $results[] = ['productId' => $pid, 'status' => 'error', 'error' => $e->getMessage()];
            }
        }

        // Merkezde geri aktifleştirilen ürünleri yeniden yayınla
        foreach ($undrafts as $undraftId) {
            $pid = intval($undraftId);
            try {
                $st = WPD_Product_Sync::set_status_by_central_id($pid, 'publish');
                $results[] = ['productId' => $pid, 'status' => $st];
            } catch (Exception $e) {
                $results[] = ['productId' => $pid, 'status' => 'error', 'error' => $e->getMessage()];
            }
        }

        // Kampanyalar (indirim) — eşleşen ürünlere sale_price + tarih uygula; REPLACE (öncekini temizle)
        if (isset($body['campaigns']) && is_array($body['campaigns'])) {
            $cr = WPD_Campaign::apply($body['campaigns']);
            $results[] = ['type' => 'campaigns', 'status' => 'applied', 'count' => count($body['campaigns']), 'affected' => $cr['affected'], 'cleared' => $cr['cleared']];
        }

        // Site içeriği (duyuru/banner) — option olarak sakla (tema/shortcode gösterir)
        if (isset($body['contents']) && is_array($body['contents'])) {
            update_option('wpd_site_contents', array_values($body['contents']));
            $results[] = ['type' => 'contents', 'status' => 'applied', 'count' => count($body['contents'])];
        }

        // Klon-kurulum: site kimliği — WP/WooCommerce/SMTP/logo/kullanıcı ayarlarını uygula.
        // try/catch (\Throwable): apply() içinde bir fatal olsa bile REST isteği 500 vermesin;
        // hata mesajı+konumu yanıtta döner → merkez panelde görünür, site çökmez.
        if (isset($body['siteIdentity']) && is_array($body['siteIdentity']) && class_exists('WPD_Setup')) {
            try {
                $r = WPD_Setup::apply($body['siteIdentity']);
                $results[] = ['type' => 'siteIdentity', 'status' => 'applied', 'done' => $r['done'], 'skipped' => $r['skipped']];
            } catch (\Throwable $e) {
                $results[] = ['type' => 'siteIdentity', 'status' => 'error', 'error' => $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()];
            }
        }

        // LiteSpeed Cache: ürün/fiyat/kategori değişti → mağaza & arşiv sayfalarını tazele
        // (push toplu çalışır; tek seferde tüm cache'i geçersiz kılmak en güvenlisi)
        if (!empty($results)) {
            do_action('litespeed_purge_all');
        }

        return rest_ensure_response(['results' => $results]);
    }
}
