<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WordPress admin menüsü ve ayar sayfası.
 * Site sahibi buradan kayıt durumunu görür ve kategori seçer.
 */
class WPD_Admin {

    public static function add_menu() {
        add_menu_page(
            'Ürün Dağıtım',
            'Ürün Dağıtım',
            'manage_options',
            'wp-distributor',
            [__CLASS__, 'render_page'],
            'dashicons-products',
            56
        );
    }

    /** Form gönderimlerini işler (admin_init üzerinde, sayfa render'ından önce) */
    public static function handle_actions() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Merkez API adresini kaydet
        if (isset($_POST['wpd_save_url']) && check_admin_referer('wpd_url')) {
            $url = isset($_POST['central_url']) ? esc_url_raw(trim(wp_unslash($_POST['central_url']))) : '';
            update_option('wpd_central_url', $url);
            add_settings_error('wpd', 'url', 'Merkez API adresi kaydedildi.', 'success');
        }

        // Merkeze (yeniden) kaydol
        if (isset($_POST['wpd_register']) && check_admin_referer('wpd_register')) {
            $r = WPD_Api_Client::register_site();
            add_settings_error(
                'wpd',
                'reg',
                $r['success'] ? 'Merkeze başarıyla kaydedildi.' : ('Kayıt hatası: ' . $r['message']),
                $r['success'] ? 'success' : 'error'
            );
        }

        // Beden tablolarını merkeze çek (pull)
        if (isset($_POST['wpd_pull_charts']) && check_admin_referer('wpd_pull_charts')) {
            $r = WPD_SizeChartSync::pull_to_central();
            add_settings_error(
                'wpd',
                'charts',
                $r['success'] ? ('Beden tabloları merkeze çekildi: ' . $r['imported'] . ' tablo.') : ('Hata: ' . $r['message']),
                $r['success'] ? 'success' : 'error'
            );
        }

        // Kategori seçimini kaydet
        if (isset($_POST['wpd_save_categories']) && check_admin_referer('wpd_categories')) {
            $cats = isset($_POST['categories']) ? array_map('intval', (array) $_POST['categories']) : [];
            $r = WPD_Api_Client::save_categories($cats);
            add_settings_error(
                'wpd',
                'cats',
                $r['success'] ? 'Kategori seçimi kaydedildi.' : 'Kategoriler kaydedilemedi.',
                $r['success'] ? 'success' : 'error'
            );
        }
    }

    public static function render_page() {
        $registered = get_option('wpd_registered');
        $categories = $registered ? WPD_Api_Client::fetch_categories() : [];
        $selected   = $registered ? WPD_Api_Client::fetch_selected_category_ids() : [];
        include WPD_PATH . 'admin/views/settings.php';
    }
}
