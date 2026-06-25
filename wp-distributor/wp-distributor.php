<?php
/**
 * Plugin Name: WP Distributor
 * Description: Merkez panelden ürünleri otomatik alır ve WooCommerce'e aktarır. Site sahibi hangi kategorilerde ürün satacağını seçer.
 * Version: 1.2.6
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

define('WPD_VERSION', '1.2.6');
define('WPD_PATH', plugin_dir_path(__FILE__));

require_once WPD_PATH . 'includes/class-api-client.php';
require_once WPD_PATH . 'includes/class-product-sync.php';
require_once WPD_PATH . 'includes/class-rest-endpoint.php';
require_once WPD_PATH . 'includes/class-admin.php';
require_once WPD_PATH . 'includes/class-updater.php';
require_once WPD_PATH . 'includes/class-stock.php';
require_once WPD_PATH . 'includes/class-shipping.php';
require_once WPD_PATH . 'includes/class-sizechart.php';
require_once WPD_PATH . 'includes/class-orders.php';

// GitHub release tabanlı otomatik güncelleme
WPD_Updater::init(__FILE__);

// Çift yönlü stok: sitede satış/iade olunca merkeze bildir (tek ortak havuz)
add_action('woocommerce_reduce_order_stock', ['WPD_Stock', 'on_reduce'], 20, 1);
add_action('woocommerce_restore_order_stock', ['WPD_Stock', 'on_restore'], 20, 1);

// Satış paneli: sipariş ödenince/tamamlanınca merkeze raporla
add_action('woocommerce_order_status_changed', ['WPD_Orders', 'on_status'], 20, 4);

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

// WooCommerce ürün sekmesine "Beden Tablosu" ekle — yalnızca "Giyim" kategorisindeki ürünlerde
add_filter('woocommerce_product_tabs', function ($tabs) {
    global $product;
    if (!$product) return $tabs;

    // Beden tablosu sadece giyim kategorisinde (alt kategoriler dahil: "Erkek Giyim" vb.) gösterilir
    $is_clothing = false;
    $terms = get_the_terms($product->get_id(), 'product_cat');
    if ($terms && !is_wp_error($terms)) {
        foreach ($terms as $t) {
            if (stripos($t->slug, 'giyim') !== false || stripos($t->name, 'giyim') !== false) {
                $is_clothing = true;
                break;
            }
        }
    }
    if (!$is_clothing) return $tabs;

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
