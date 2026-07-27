<?php
/**
 * Plugin Name: WP Distributor
 * Description: Merkez panelden ürünleri otomatik alır ve WooCommerce'e aktarır. Site sahibi hangi kategorilerde ürün satacağını seçer.
 * Version: 1.5.1
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

define('WPD_VERSION', '1.5.1');
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
require_once WPD_PATH . 'includes/class-campaigns.php';
require_once WPD_PATH . 'includes/class-content.php';
require_once WPD_PATH . 'includes/class-setup.php';

// Merkezden gelen içerik (duyuru/banner) gösterimi
WPD_Content::init();

// Klon-kurulum: [wpd_field] shortcode'u (yasal sayfalar/footer için)
WPD_Setup::init();

// Klon hijyeni: domain master'dan farklıysa (klon) kendi domainiyle yeniden kaydol
add_action('init', ['WPD_Api_Client', 'maybe_reregister_after_clone'], 5);

// GitHub release tabanlı otomatik güncelleme
WPD_Updater::init(__FILE__);

// Dış URL'li görseller: attachment'lar dosya indirmeden dış URL'e (feed görseli) işaret eder.
// Bu filtreler WooCommerce/tema görsel URL'i isterken saklı dış URL'i döndürür → indirme/depolama yok.
add_filter('wp_get_attachment_url', function ($url, $post_id) {
    $ext = get_post_meta($post_id, '_wpd_ext_url', true);
    return $ext ? $ext : $url;
}, 10, 2);

add_filter('wp_get_attachment_image_src', function ($image, $attachment_id, $size) {
    $ext = get_post_meta($attachment_id, '_wpd_ext_url', true);
    if ($ext) {
        return [$ext, 0, 0, false];
    }
    return $image;
}, 10, 3);

// Dış görsellerde yerel boyut yok → srcset üretme (kırık srcset olmasın)
add_filter('wp_calculate_image_srcset', function ($sources, $size_array, $image_src, $image_meta, $attachment_id) {
    return get_post_meta($attachment_id, '_wpd_ext_url', true) ? [] : $sources;
}, 10, 5);

/* =========================================================================
   ATTACHMENT'SIZ DIŞ GÖRSEL (v1.3.0)
   Ürün görselleri artık media kütüphanesine kayıt açmadan, doğrudan ürün
   meta'sındaki dış URL'den (_wpd_main_image_url / _wpd_gallery_urls) çizilir.
   ========================================================================= */

// Ürünün ana dış görsel URL'i (yoksa boş)
function wpd_main_image_url($product_id) {
    return get_post_meta($product_id, '_wpd_main_image_url', true);
}

// Mağaza listesi, sepet, widget, ilgili ürünler → WC_Product::get_image()
add_filter('woocommerce_product_get_image', function ($html, $product) {
    $url = wpd_main_image_url($product->get_id());
    if (!$url) return $html;
    return '<img src="' . esc_url($url) . '" alt="' . esc_attr($product->get_name())
        . '" class="wpd-ext-img" loading="lazy" decoding="async" />';
}, 10, 2);

// Temaların öne çıkan görsel (get_the_post_thumbnail) kullanımı
add_filter('post_thumbnail_html', function ($html, $post_id) {
    if (!empty($html)) return $html;
    $url = get_post_meta($post_id, '_wpd_main_image_url', true);
    if (!$url) return $html;
    return '<img src="' . esc_url($url) . '" class="wp-post-image wpd-ext-img" loading="lazy" decoding="async" />';
}, 10, 2);

// has_post_thumbnail true olsun (yoksa tema placeholder gösterir)
add_filter('has_post_thumbnail', function ($has, $post) {
    if ($has) return $has;
    $pid = is_object($post) ? $post->ID : (int) $post;
    if ($pid && get_post_meta($pid, '_wpd_main_image_url', true)) return true;
    return $has;
}, 10, 2);

/* ---- Attachment'sız dış görseli "GERÇEK öne çıkan görsel" gibi sun (v1.3.1) ----
   loobek gibi temalar liste/arşiv thumbnail'ını WordPress öne çıkan görsel sistemiyle
   çizer: get_the_post_thumbnail(_url), CSS background veya admin-ajax. Bunlar attachment/
   thumbnail_id ister; attachment'sız yapıda bu yoktu → boş görsel → spinner'da takılma.
   Çözüm: dış görselli üründe _thumbnail_id olarak ürünün KENDİ id'sini "sentinel" ver ve
   o sahte attachment'ın URL/src çözümünü dış URL'e yönlendir. Böylece tema hangi standart
   yolu kullanırsa kullansın (img, url, background, ajax) liste görselleri de gelir. */
add_filter('get_post_metadata', function ($value, $object_id, $meta_key, $single) {
    if ($meta_key !== '_thumbnail_id') return $value;
    if (is_admin() && !wp_doing_ajax()) return $value;   // gerçek wp-admin ekranını bozma
    if ($value !== null) return $value;                  // gerçek thumbnail zaten çözülmüş
    if (get_post_meta($object_id, '_wpd_main_image_url', true)) {
        return $single ? (string) $object_id : [(string) $object_id];
    }
    return $value;
}, 10, 4);

// Sentinel "attachment" (aslında ürün) için görsel kaynağı → dış URL (800x800 varsay).
add_filter('wp_get_attachment_image_src', function ($image, $attachment_id, $size, $icon) {
    $u = get_post_meta($attachment_id, '_wpd_main_image_url', true);
    if ($u) return [$u, 800, 800, false];
    return $image;
}, 10, 4);

add_filter('wp_get_attachment_url', function ($url, $attachment_id) {
    $u = get_post_meta($attachment_id, '_wpd_main_image_url', true);
    return $u ?: $url;
}, 9, 2);

// Tekil ürün sayfası galerisi: WooCommerce'in attachment tabanlı galerisini,
// dış URL'lerden çizen kendi galerimizle değiştir (dış görselli ürünlerde).
add_action('init', function () {
    remove_action('woocommerce_before_single_product_summary', 'woocommerce_show_product_images', 20);
    add_action('woocommerce_before_single_product_summary', 'wpd_external_product_gallery', 20);
});

function wpd_external_product_gallery() {
    global $product;
    if (!$product) return;
    $main = wpd_main_image_url($product->get_id());
    if (!$main) {
        // Bu ürün dış görselli değil → WooCommerce'in normal galerisini göster
        if (function_exists('woocommerce_show_product_images')) {
            woocommerce_show_product_images();
        }
        return;
    }
    $gallery = get_post_meta($product->get_id(), '_wpd_gallery_urls', true);
    $gallery = is_array($gallery) ? $gallery : [];
    $all = array_merge([$main], $gallery);
    ?>
    <div class="wpd-gallery">
        <div class="wpd-gallery-main">
            <img id="wpd-gallery-main-img" src="<?php echo esc_url($main); ?>"
                 alt="<?php echo esc_attr($product->get_name()); ?>" />
        </div>
        <?php if (count($all) > 1) : ?>
            <div class="wpd-gallery-thumbs">
                <?php foreach ($all as $i => $u) : ?>
                    <img src="<?php echo esc_url($u); ?>"
                         class="wpd-gallery-thumb<?php echo $i === 0 ? ' active' : ''; ?>"
                         onclick="var m=document.getElementById('wpd-gallery-main-img'); if(m){m.src=this.src;} var ts=this.parentNode.querySelectorAll('.wpd-gallery-thumb'); for(var k=0;k<ts.length;k++){ts[k].classList.remove('active');} this.classList.add('active');" />
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <style>
        .wpd-gallery{width:100%}
        .wpd-gallery-main img{width:100%;height:auto;border:1px solid #eee;border-radius:8px;object-fit:contain;background:#fff}
        .wpd-gallery-thumbs{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}
        .wpd-gallery-thumb{width:64px;height:64px;object-fit:cover;border:2px solid #eee;border-radius:6px;cursor:pointer;background:#fff}
        .wpd-gallery-thumb.active{border-color:#2a7ae4}
    </style>
    <?php
}

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
