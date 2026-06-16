<?php
/**
 * Plugin Name: WP Distributor
 * Description: Merkez panelden ürünleri otomatik alır ve WooCommerce'e aktarır. Site sahibi hangi kategorilerde ürün satacağını seçer.
 * Version: 1.1.7
 * Author: WP Central
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Merkez API adresi.
 * Plugin'i sitelere dağıtmadan önce burayı kendi sunucu adresinizle değiştirin.
 * Örnek: https://merkez.siteniz.com  (sonunda / olmadan)
 */
if (!defined('WPD_CENTRAL_URL')) {
    define('WPD_CENTRAL_URL', 'https://api-production-76ce.up.railway.app');
}

define('WPD_VERSION', '1.1.7');
define('WPD_PATH', plugin_dir_path(__FILE__));

require_once WPD_PATH . 'includes/class-api-client.php';
require_once WPD_PATH . 'includes/class-product-sync.php';
require_once WPD_PATH . 'includes/class-rest-endpoint.php';
require_once WPD_PATH . 'includes/class-admin.php';
require_once WPD_PATH . 'includes/class-updater.php';

// GitHub release tabanlı otomatik güncelleme
WPD_Updater::init(__FILE__);

// Aktivasyonda merkeze otomatik kaydol + güncelleme önbelleğini temizle
register_activation_hook(__FILE__, ['WPD_Api_Client', 'register_site']);
register_activation_hook(__FILE__, ['WPD_Updater', 'clear_cache']);

// Merkezin push yapacağı REST endpoint'i kaydet
add_action('rest_api_init', ['WPD_Rest_Endpoint', 'register_routes']);

// Admin menüsü ve form işlemleri
if (is_admin()) {
    add_action('admin_menu', ['WPD_Admin', 'add_menu']);
    add_action('admin_init', ['WPD_Admin', 'handle_actions']);
}

// WooCommerce ürün sekmesine "Beden Tablosu" ekle
add_filter('woocommerce_product_tabs', function ($tabs) {
    global $product;
    if (!$product) return $tabs;
    $chart_html = get_post_meta($product->get_id(), '_wpd_size_chart', true);
    if (!$chart_html) return $tabs;
    $tabs['wpd_size_chart'] = [
        'title'    => 'Beden Tablosu',
        'priority' => 25,
        'callback' => function () use ($chart_html) {
            echo '<div class="wpd-size-chart-wrap">';
            echo '<style>
                .wpd-size-chart-wrap table.wpd-size-chart{width:100%;border-collapse:collapse;margin-top:10px}
                .wpd-size-chart-wrap table.wpd-size-chart th,.wpd-size-chart-wrap table.wpd-size-chart td{border:1px solid #ddd;padding:8px 12px;text-align:center}
                .wpd-size-chart-wrap table.wpd-size-chart thead{background:#f5f5f5;font-weight:bold}
            </style>';
            echo wp_kses_post($chart_html);
            echo '</div>';
        },
    ];
    return $tabs;
});
