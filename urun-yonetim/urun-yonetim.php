<?php
/*
Plugin Name: Magazify Ürün Yönetim
Description: Ürün Yönetim Eklentisi
Version: 1.5
Author: Magazify
*/

if (!defined('ABSPATH')) {
    exit;
}

define('UY_VERSION', '1.5');

// GitHub release tabanlı otomatik güncelleme
require_once __DIR__ . '/includes/class-updater.php';
UY_Updater::init(__FILE__);

/*
|--------------------------------------------------------------------------
| YETKİ KONTROLÜ
|--------------------------------------------------------------------------
|
| manage_woocommerce yetkisi varsayılan olarak:
| - Yönetici
| - Mağaza yöneticisi
|
| rollerinde bulunur.
|
*/

function uy_kullanici_urun_yonetebilir() {
    return is_user_logged_in() && current_user_can('manage_woocommerce');
}


/*
|--------------------------------------------------------------------------
| WOOCOMMERCE HESABIM ENDPOINT
|--------------------------------------------------------------------------
*/

function uy_urun_yonetimi_endpoint_ekle() {
    add_rewrite_endpoint(
        'urun-yonetimi',
        EP_ROOT | EP_PAGES
    );
}

add_action(
    'init',
    'uy_urun_yonetimi_endpoint_ekle'
);


/*
|--------------------------------------------------------------------------
| EKLENTİ ETKİNLEŞTİRME / DEVRE DIŞI BIRAKMA
|--------------------------------------------------------------------------
*/

function uy_eklenti_aktiflestirildi() {
    uy_urun_yonetimi_endpoint_ekle();
    flush_rewrite_rules();
}

register_activation_hook(
    __FILE__,
    'uy_eklenti_aktiflestirildi'
);

function uy_eklenti_devre_disi_birakildi() {
    flush_rewrite_rules();
}

register_deactivation_hook(
    __FILE__,
    'uy_eklenti_devre_disi_birakildi'
);


/*
|--------------------------------------------------------------------------
| HESABIM MENÜSÜNE "ÜRÜN YÖNETİMİ" EKLE
|--------------------------------------------------------------------------
*/

function uy_hesabim_menu_ogesi_ekle($items) {

    if (!uy_kullanici_urun_yonetebilir()) {
        return $items;
    }

    $yeni_items = [];

    foreach ($items as $key => $label) {

        $yeni_items[$key] = $label;

        if ($key === 'dashboard') {
            $yeni_items['urun-yonetimi'] = 'Ürün Yönetimi';
        }
    }

    if (!isset($yeni_items['urun-yonetimi'])) {
        $yeni_items['urun-yonetimi'] = 'Ürün Yönetimi';
    }

    return $yeni_items;
}

add_filter(
    'woocommerce_account_menu_items',
    'uy_hesabim_menu_ogesi_ekle',
    40
);


/*
|--------------------------------------------------------------------------
| ÜRÜN YÖNETİMİ ENDPOINT İÇERİĞİ
|--------------------------------------------------------------------------
*/

function uy_urun_yonetimi_endpoint_icerigi() {

    if (!uy_kullanici_urun_yonetebilir()) {
        echo '<p>Bu bölümü görüntüleme yetkiniz bulunmuyor.</p>';
        return;
    }

    echo do_shortcode('[urun-yonetim]');
}

add_action(
    'woocommerce_account_urun-yonetimi_endpoint',
    'uy_urun_yonetimi_endpoint_icerigi'
);


/*
|--------------------------------------------------------------------------
| SHORTCODE: [urun-yonetim]
|--------------------------------------------------------------------------
*/

add_shortcode(
    'urun-yonetim',
    'uy_urun_yonetim_shortcode'
);

function uy_urun_yonetim_shortcode() {

    if (!class_exists('WooCommerce')) {
        return '<p>WooCommerce yüklü değil.</p>';
    }

    if (!uy_kullanici_urun_yonetebilir()) {
        return '<p>Bu bölümü görüntüleme yetkiniz bulunmuyor.</p>';
    }

    ob_start();

    /*
     * SAYFALAMA
     */
    $paged = isset($_GET['uy_page'])
        ? max(1, absint(wp_unslash($_GET['uy_page'])))
        : 1;

    /*
     * Yalnızca "Mağaza Koleksiyonu" kategorisindeki
     * yayınlanmış ürünleri getirir.
     */
    $args = [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => 10,
        'paged'          => $paged,
        'tax_query'      => [
            [
                'taxonomy' => 'product_cat',
                'field'    => 'slug',
                'terms'    => 'magaza-koleksiyonu',
            ],
        ],
    ];

    $loop = new WP_Query($args);

    echo '<div class="uy-urun-listesi">';

    /*
     * BİLGİ KUTUSU
     */
    echo '
        <div class="uy-info-box">

            <p>
                <strong>
                    (1) Net hakediş, Mağaza Koleksiyonu ürünlerinde KDV hariç satış fiyatı üzerinden %20 ile hesaplanır. Magazify bu oranı değiştirme hakkını saklı tutar.
                </strong>
            </p>

            <p>
                <strong>
                    (2) Net hakediş miktarı referans değerdir; vergi oranları, ek lojistik masrafları, indirim kampanyaları, indirimli satışlar ve kuponlu satışlar sebebiyle değişiklik gösterebilir.
                </strong>
            </p>

            <p>
                <strong>
                    (3) Magazify kendisini devamlı geliştirmektedir. Bu ekranı belli aralıklarla incelemenizi tavsiye ederiz.
                </strong>
            </p>

        </div>
    ';

    /*
     * TABLO BAŞLIĞI
     */
    echo '
        <div class="uy-header">

            <div class="uy-header-thumb">
                Görsel
            </div>

            <div class="uy-header-title">
                Ürün İsmi
            </div>

            <div class="uy-header-net">
                Net Hakediş (1)
            </div>

            <div class="uy-header-price">
                Satış Fiyatı
            </div>

            <div class="uy-header-action">
                İşlem
            </div>

        </div>
    ';

    /*
     * ÜRÜNLER
     */
    if ($loop->have_posts()) {

        while ($loop->have_posts()) {
            $loop->the_post();

            $product_id = get_the_ID();
            $product    = wc_get_product($product_id);

            if (!$product) {
                continue;
            }

            /*
             * AKTİF SATIŞ FİYATI
             *
             * İndirimli fiyat varsa indirimli fiyatı,
             * yoksa normal fiyatı kullanır.
             */
            $price = (float) $product->get_price();

            if ($price <= 0) {
                continue;
            }

            /*
             * KDV HARİÇ SATIŞ FİYATI
             *
             * KDV oranı sabit olarak %10 kabul edilir.
             *
             * Örnek:
             * 110 / 1.10 = 100 TL
             */
            $kdv_haric_fiyat = $price / 1.10;

            /*
             * NET HAKEDİŞ
             *
             * Mağaza Koleksiyonu ürünlerinde
             * KDV hariç satış fiyatının %20'sidir.
             *
             * Örnek:
             * 100 × 0.20 = 20 TL
             */
            $net_hakedis_raw = $kdv_haric_fiyat * 0.20;

            echo '<div class="uy-urun-item">';

            /*
             * ÜRÜN GÖRSELİ
             */
            echo '
                <a
                    href="' . esc_url(get_permalink($product_id)) . '"
                    target="_blank"
                    rel="noopener noreferrer"
                >
            ';

            echo wp_kses_post(
                $product->get_image('thumbnail')
            );

            echo '</a>';

            /*
             * ÜRÜN İSMİ
             */
            echo '<div class="uy-urun-title">';

            echo esc_html(
                get_the_title($product_id)
            );

            echo '</div>';

            /*
             * NET HAKEDİŞ
             */
            echo '<div class="uy-urun-net">';

            echo wp_kses_post(
                wc_price($net_hakedis_raw)
            );

            echo '</div>';

            /*
             * SATIŞ FİYATI
             */
            echo '<div class="uy-urun-price">';

            echo wp_kses_post(
                wc_price($price)
            );

            echo '</div>';

            /*
             * SİLME BUTONU
             */
            echo '
                <button
                    type="button"
                    class="uy-delete-product"
                    data-id="' . esc_attr($product_id) . '"
                >
                    Sil
                </button>
            ';

            echo '</div>';
        }

    } else {

        echo '
            <div class="uy-empty-state">
                Mağaza koleksiyonunda henüz ürün bulunmuyor.
            </div>
        ';
    }

    /*
     * SAYFALAMA
     */
    $total_pages = (int) $loop->max_num_pages;

    if ($total_pages > 1) {

        echo '
            <nav
                class="uy-pagination"
                aria-label="Ürün sayfaları"
            >
        ';

        /*
         * ÖNCEKİ SAYFA
         */
        if ($paged > 1) {

            $previous_url = add_query_arg(
                'uy_page',
                $paged - 1
            );

            echo '
                <a
                    class="uy-page-link uy-page-prev"
                    href="' . esc_url($previous_url) . '"
                    aria-label="Önceki sayfa"
                >
                    ‹
                </a>
            ';
        }

        /*
         * SAYFA NUMARALARI
         */
        for ($i = 1; $i <= $total_pages; $i++) {

            $page_url = add_query_arg(
                'uy_page',
                $i
            );

            if ($i === $paged) {

                echo '
                    <span
                        class="uy-page-link uy-page-current"
                        aria-current="page"
                    >
                        ' . esc_html($i) . '
                    </span>
                ';

            } else {

                echo '
                    <a
                        class="uy-page-link"
                        href="' . esc_url($page_url) . '"
                    >
                        ' . esc_html($i) . '
                    </a>
                ';
            }
        }

        /*
         * SONRAKİ SAYFA
         */
        if ($paged < $total_pages) {

            $next_url = add_query_arg(
                'uy_page',
                $paged + 1
            );

            echo '
                <a
                    class="uy-page-link uy-page-next"
                    href="' . esc_url($next_url) . '"
                    aria-label="Sonraki sayfa"
                >
                    ›
                </a>
            ';
        }

        echo '</nav>';
    }

    /*
     * ÜRÜN LİSTESİ KAPANIŞI
     */
    echo '</div>';

    wp_reset_postdata();

    return ob_get_clean();
}


/*
|--------------------------------------------------------------------------
| AJAX ÜRÜN SİLME
|--------------------------------------------------------------------------
*/

add_action(
    'wp_ajax_uy_delete_product',
    'uy_delete_product'
);

function uy_delete_product() {

    check_ajax_referer(
        'uy_delete_product_nonce',
        'nonce'
    );

    if (!uy_kullanici_urun_yonetebilir()) {
        wp_send_json_error('Yetkiniz yok.');
    }

    $product_id = isset($_POST['product_id'])
        ? absint(wp_unslash($_POST['product_id']))
        : 0;

    if (!$product_id) {
        wp_send_json_error('Geçersiz ürün.');
    }

    if (get_post_type($product_id) !== 'product') {
        wp_send_json_error('Geçersiz ürün kaydı.');
    }

    if (!has_term(
        'magaza-koleksiyonu',
        'product_cat',
        $product_id
    )) {
        wp_send_json_error(
            'Bu ürün Mağaza Koleksiyonu kategorisinde değil.'
        );
    }

    if (!current_user_can('delete_post', $product_id)) {
        wp_send_json_error(
            'Bu ürünü silme yetkiniz yok.'
        );
    }

    if (wp_delete_post($product_id, true)) {
        wp_send_json_success('Ürün silindi.');
    }

    wp_send_json_error('Silme işlemi başarısız.');
}


/*
|--------------------------------------------------------------------------
| JAVASCRIPT VE CSS
|--------------------------------------------------------------------------
*/

add_action('wp_enqueue_scripts', function () {

    if (!is_account_page()) {
        return;
    }

    wp_enqueue_script(
        'uy-js',
        plugin_dir_url(__FILE__) . 'uy.js',
        ['jquery'],
        '1.5',
        true
    );

    wp_localize_script(
        'uy-js',
        'uy_ajax',
        [
            'url'   => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(
                'uy_delete_product_nonce'
            ),
        ]
    );

    wp_enqueue_style(
        'uy-style',
        plugin_dir_url(__FILE__) . 'uy-style.css',
        [],
        '1.5'
    );
});