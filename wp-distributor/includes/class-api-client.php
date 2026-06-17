<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Merkez API ile haberleşen istemci.
 * Plugin → Merkez yönündeki tüm istekler buradan geçer (X-API-Key header'ı ile).
 */
class WPD_Api_Client {

    /** Sitenin domain'i (merkezdeki kayıt anahtarı) */
    public static function get_domain() {
        return wp_parse_url(home_url(), PHP_URL_HOST);
    }

    public static function get_api_key() {
        return get_option('wpd_api_key', '');
    }

    public static function get_api_secret() {
        return get_option('wpd_api_secret', '');
    }

    /** Merkez API adresi: admin panelden ayarlandıysa onu, yoksa sabiti kullanır */
    public static function central_url() {
        $opt = trim(get_option('wpd_central_url', ''));
        return rtrim($opt !== '' ? $opt : WPD_CENTRAL_URL, '/');
    }

    /**
     * Siteyi merkeze kaydeder; dönen apiKey/apiSecret'i saklar.
     * Aktivasyonda ve admin panelden "Yeniden Kaydol" ile çağrılır.
     */
    public static function register_site() {
        $res = wp_remote_post(self::central_url() . '/api/public/register', [
            'timeout' => 20,
            'headers' => ['Content-Type' => 'application/json', 'X-Plugin-Version' => WPD_VERSION],
            'body'    => wp_json_encode([
                'domain'     => self::get_domain(),
                'ownerName'  => get_bloginfo('name'),
                'ownerEmail' => get_bloginfo('admin_email'),
            ]),
        ]);

        if (is_wp_error($res)) {
            return ['success' => false, 'message' => $res->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($res);
        $data = json_decode(wp_remote_retrieve_body($res), true);

        if ($code >= 200 && $code < 300 && !empty($data['apiKey'])) {
            update_option('wpd_api_key', sanitize_text_field($data['apiKey']));
            update_option('wpd_api_secret', sanitize_text_field($data['apiSecret']));
            update_option('wpd_registered', 1);
            update_option('wpd_registered_domain', self::get_domain());
            return ['success' => true];
        }

        return [
            'success' => false,
            'message' => isset($data['error']) ? $data['error'] : 'Kayıt başarısız (HTTP ' . $code . ')',
        ];
    }

    /** Merkezdeki tüm kategorileri getirir */
    public static function fetch_categories() {
        $res = wp_remote_get(self::central_url() . '/api/public/categories', [
            'timeout' => 15,
            'headers' => ['X-API-Key' => self::get_api_key(), 'X-Plugin-Version' => WPD_VERSION],
        ]);
        if (is_wp_error($res)) {
            return [];
        }
        $data = json_decode(wp_remote_retrieve_body($res), true);
        return is_array($data) ? $data : [];
    }

    /** Bu sitenin merkezde seçili olan kategori ID'lerini getirir */
    public static function fetch_selected_category_ids() {
        $res = wp_remote_get(self::central_url() . '/api/public/me', [
            'timeout' => 15,
            'headers' => ['X-API-Key' => self::get_api_key(), 'X-Plugin-Version' => WPD_VERSION],
        ]);
        if (is_wp_error($res)) {
            return [];
        }
        $data = json_decode(wp_remote_retrieve_body($res), true);
        return isset($data['categoryIds']) && is_array($data['categoryIds']) ? $data['categoryIds'] : [];
    }

    /** Site sahibinin seçtiği kategorileri merkeze kaydeder */
    public static function save_categories($category_ids) {
        $res = wp_remote_request(self::central_url() . '/api/public/my-categories', [
            'method'  => 'PUT',
            'timeout' => 15,
            'headers' => [
                'Content-Type'     => 'application/json',
                'X-API-Key'        => self::get_api_key(),
                'X-Plugin-Version' => WPD_VERSION,
            ],
            'body' => wp_json_encode(['categoryIds' => array_map('intval', (array) $category_ids)]),
        ]);

        if (is_wp_error($res)) {
            return ['success' => false, 'message' => $res->get_error_message()];
        }
        $code = wp_remote_retrieve_response_code($res);
        return ['success' => $code >= 200 && $code < 300];
    }
}
