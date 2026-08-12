<?php
/*

Plugin Name: Magazify Hesap Merkezi
Description: WooCommerce Hesabım menüsünün isimlerini ve sıralamasını Magazify için düzenler.
Version: 2.2.10
Author: Magazify

*/

if (!defined('ABSPATH')) {
    exit;
}

define('MHM_VERSION', '2.2.10');

// GitHub release tabanlı otomatik güncelleme
require_once __DIR__ . '/includes/class-updater.php';
MHM_Updater::init(__FILE__);

/*
|--------------------------------------------------------------------------
| WORDPRESS YÖNETİM ALANI KISITLAMALARI
|--------------------------------------------------------------------------
*/

function mhm_hide_admin_bar_for_store_users($show) {

    if (!is_user_logged_in()) {

        return $show;

    }

    $user = wp_get_current_user();

    if (in_array('administrator', (array) $user->roles, true)) {

        return true;

    }

    return false;

}

add_filter(

    'show_admin_bar',

    'mhm_hide_admin_bar_for_store_users',

    PHP_INT_MAX

);

/**

 * Administrator dışındaki kullanıcıların

 * wp-admin erişimini engeller.

 */

function mhm_block_wp_admin_for_store_users() {

    if (wp_doing_ajax()) {

        return;

    }

    $user = wp_get_current_user();

    if (in_array('administrator', (array) $user->roles, true)) {
        return;
    }
    if (function_exists('wc_get_page_permalink')) {
        $redirect_url = wc_get_page_permalink('myaccount');
    } else {
        $redirect_url = home_url('/');
    }
    wp_safe_redirect($redirect_url);
    exit;

}

add_action('admin_init', 'mhm_block_wp_admin_for_store_users');

/*

|--------------------------------------------------------------------------
| ŞİFRE EKRANI DOSYALARI
|--------------------------------------------------------------------------

*/

function mhm_enqueue_password_assets() {

    if (
        !function_exists('is_account_page') ||
        !is_account_page()
    ) {
        return;
    }

    $css_file = plugin_dir_path(__FILE__) . 'mhm.css';
    $js_file  = plugin_dir_path(__FILE__) . 'mhm.js';

    $css_version = file_exists($css_file)
        ? (string) filemtime($css_file)
        : '2.2.5';

    $js_version = file_exists($js_file)
        ? (string) filemtime($js_file)
        : '2.2.5';

    wp_enqueue_style(
        'mhm-style',
        plugin_dir_url(__FILE__) . 'mhm.css',
        [],
        $css_version
    );

    wp_enqueue_script(
        'mhm-script',
        plugin_dir_url(__FILE__) . 'mhm.js',
        [],
        $js_version,
        true
    );
}

add_action(
    'wp_enqueue_scripts',
    'mhm_enqueue_password_assets'
);


/*
|--------------------------------------------------------------------------
| METİN NORMALİZASYONU
|--------------------------------------------------------------------------
*/

function mhm_normalize_label($label) {

    $label = wp_strip_all_tags((string) $label);
    $label = remove_accents($label);
    $label = mb_strtolower($label, 'UTF-8');
    $label = preg_replace('/\s+/', ' ', $label);

    return trim($label);
}


/*
|--------------------------------------------------------------------------
| HESAP MERKEZİ — ESKİ HESAP SEKMELERİ ENTEGRASYONU
|--------------------------------------------------------------------------
|
| Eski "Magazac Hesap Sekmeleri" eklentisindeki üç özel endpoint artık
| doğrudan Hesap Merkezi tarafından yönetilir. Eski eklenti bu sürüm
| kullanılırken devre dışı bırakılmalıdır.
|
*/

function mhm_integrated_account_tabs() {

    return [
        'urun-gorsel' => [
            'label'     => 'Mağaza Koleksiyonu Ürünü Oluştur',
            'shortcode' => 'mockup_creator',
        ],
        'hesap-bilgileri' => [
            'label'     => 'Hesap Bilgileri',
            'shortcode' => '', // İçerik doğrudan Hesap Merkezi tarafından render edilir.
        ],
        'hesap-hareketleri' => [
            'label'     => 'Hesap Hareketleri',
            'shortcode' => 'hesap-hareketleri',
        ],
        'tasarim-yukle' => [
            'label'     => 'Tasarım Yükle',
            'shortcode' => '',
        ],
        'destek' => [
            'label'     => 'Teknik Destek',
            'shortcode' => '', // İçerik doğrudan Hesap Merkezi tarafından render edilir.
        ],
    ];
}


function mhm_register_integrated_account_endpoints() {

    foreach (mhm_integrated_account_tabs() as $endpoint => $data) {
        add_rewrite_endpoint(
            $endpoint,
            EP_ROOT | EP_PAGES
        );
    }
}

add_action(
    'init',
    'mhm_register_integrated_account_endpoints'
);


function mhm_add_integrated_account_menu_items($items) {

    if (
        !is_user_logged_in() ||
        !current_user_can('manage_woocommerce')
    ) {
        return $items;
    }

    foreach (mhm_integrated_account_tabs() as $endpoint => $data) {
        if (!isset($items[$endpoint])) {
            $items[$endpoint] = $data['label'];
        }
    }

    return $items;
}

add_filter(
    'woocommerce_account_menu_items',
    'mhm_add_integrated_account_menu_items',
    90
);


function mhm_render_integrated_account_endpoint($endpoint) {

    if (
        !is_user_logged_in() ||
        !current_user_can('manage_woocommerce')
    ) {
        echo '<p>Bu alanı görüntüleme yetkiniz bulunmuyor.</p>';
        return;
    }

    $tabs = mhm_integrated_account_tabs();

    if (empty($tabs[$endpoint]['shortcode'])) {
        return;
    }

    echo do_shortcode(
        '[' . $tabs[$endpoint]['shortcode'] . ']'
    );
}


function mhm_product_creator_endpoint_content() {
    mhm_render_integrated_account_endpoint('urun-gorsel');
}

function mhm_account_information_endpoint_content() {
    echo mhm_hb_render_account_information();
}

function mhm_account_movements_endpoint_content() {
    mhm_render_integrated_account_endpoint('hesap-hareketleri');
}

function mhm_design_upload_endpoint_content() {
    echo mhm_render_design_upload_page();
}

function mhm_support_endpoint_content() {
    echo mhm_render_support_page();
}

add_action(
    'woocommerce_account_urun-gorsel_endpoint',
    'mhm_product_creator_endpoint_content'
);

add_action(
    'woocommerce_account_hesap-bilgileri_endpoint',
    'mhm_account_information_endpoint_content'
);

add_action(
    'woocommerce_account_hesap-hareketleri_endpoint',
    'mhm_account_movements_endpoint_content'
);

add_action(
    'woocommerce_account_tasarim-yukle_endpoint',
    'mhm_design_upload_endpoint_content'
);

add_action(
    'woocommerce_account_destek_endpoint',
    'mhm_support_endpoint_content'
);


/*
|--------------------------------------------------------------------------
| HESABIM MENÜSÜ
|--------------------------------------------------------------------------
*/

function mhm_account_menu_items($items) {

    if (!is_user_logged_in()) {
        return $items;
    }

    $recognized = [];
    $remaining  = [];

    foreach ($items as $key => $label) {

        $normalized_label = mhm_normalize_label($label);

        /*
         * GİZLENECEK MENÜLER
         */
        if (
            $key === 'downloads' ||
            $key === 'indirimler' ||
            $key === 'indirim' ||
            $key === 'edit-address' ||
            $normalized_label === 'indirmeler' ||
            $normalized_label === 'indirimler' ||
            $normalized_label === 'adresler' ||
            $normalized_label === 'adres yonetimi'
        ) {
            continue;
        }

        /*
         * MAĞAZA YÖNETİMİ
         */
        if ($key === 'dashboard') {

            $recognized['dashboard'] = [
                'key'   => $key,
                'label' => 'Mağaza Yönetimi',
            ];

            continue;
        }

        /*
         * MAĞAZA KOLEKSİYONU ÜRÜNÜ OLUŞTUR
         */
        if (
            in_array(
                $key,
                [
                    'urun-gorsel-olustur',
                    'urun-gorsel-olusturma',
                    'urun-gorsel',
                    'urun-olustur',
                    'urun-olusturma',
                    'pod-urun-olustur',
                    'mockup-creator',
                ],
                true
            ) ||
            str_contains($normalized_label, 'urun gorsel olustur') ||
            str_contains($normalized_label, 'urun olustur')
        ) {

            $recognized['product_creator'] = [
                'key'   => $key,
                'label' => 'Mağaza Koleksiyonu Ürünü Oluştur',
            ];

            continue;
        }

        /*
         * MAĞAZA KOLEKSİYONU ÜRÜN YÖNETİMİ
         */
        if (
            in_array(
                $key,
                [
                    'urun-yonetimi',
                    'urun-yonetim',
                ],
                true
            ) ||
            str_contains($normalized_label, 'urun yonetimi')
        ) {

            $recognized['product_management'] = [
                'key'   => $key,
                'label' => 'Mağaza Koleksiyonu Ürün Yönetimi',
            ];

            continue;
        }

        /*
         * SİPARİŞLER
         */
        if ($key === 'orders') {

            $recognized['orders'] = [
                'key'   => $key,
                'label' => 'Siparişler',
            ];

            continue;
        }

        /*
         * HESAP HAREKETLERİ
         */
        if ($key === 'hesap-hareketleri') {

            $recognized['account_movements'] = [
                'key'   => $key,
                'label' => 'Hesap Hareketleri',
            ];

            continue;
        }

        /*
         * MAĞAZA GÜNCELLEME
         */
        if (
            in_array(
                $key,
                [
                    'magaza-guncelleme',
                    'magaza-guncelle',
                    'magaza-guncelleme-talebi',
                ],
                true
            ) ||
            str_contains($normalized_label, 'magaza guncelle')
        ) {

            $recognized['store_update'] = [
                'key'   => $key,
                'label' => 'Mağaza Güncelleme',
            ];

            continue;
        }

        /*
         * HESAP BİLGİLERİ
         */
        if ($key === 'hesap-bilgileri') {

            $recognized['account_information'] = [
                'key'   => $key,
                'label' => 'Hesap Bilgileri',
            ];

            continue;
        }

        /*
         * TASARIM YÜKLE
         */
        if ($key === 'tasarim-yukle') {

            $recognized['design_upload'] = [
                'key'   => $key,
                'label' => 'Tasarım Yükle',
            ];

            continue;
        }

        /*
         * TEKNİK DESTEK
         */
        if ($key === 'destek') {

            $recognized['support'] = [
                'key'   => $key,
                'label' => 'Teknik Destek',
            ];

            continue;
        }

        /*
         * HESAP DETAYLARI
         */
        if ($key === 'edit-account') {

            $recognized['account_details'] = [
                'key'   => $key,
                'label' => 'Şifre Değiştirme',
            ];

            continue;
        }

        /*
         * OTURUMU KAPAT
         */
        if ($key === 'customer-logout') {

            $recognized['logout'] = [
                'key'   => $key,
                'label' => 'Oturumu Kapat',
            ];

            continue;
        }

        /*
         * TANIMLANMAMIŞ MENÜLER
         */
        $remaining[$key] = $label;
    }

    /*
     * İSTENEN MENÜ SIRASI
     */
    $desired_order = [
        'dashboard',
        'product_creator',
        'product_management',
        'design_upload',
        'orders',
        'account_movements',
        'store_update',
        'account_information',
        'support',
        'account_details',
    ];

    $new_items = [];

    foreach ($desired_order as $group_key) {

        if (!isset($recognized[$group_key])) {
            continue;
        }

        $menu_item = $recognized[$group_key];

        $new_items[$menu_item['key']] = $menu_item['label'];
    }

    /*
     * Tanınmayan özel endpoint'leri Oturumu Kapat'tan önce ekler.
     */
    foreach ($remaining as $key => $label) {
        $new_items[$key] = $label;
    }

    /*
     * Oturumu Kapat her zaman en sonda.
     */
    if (isset($recognized['logout'])) {

        $logout = $recognized['logout'];

        $new_items[$logout['key']] = $logout['label'];
    }

    return $new_items;
}

add_filter(
    'woocommerce_account_menu_items',
    'mhm_account_menu_items',
    999
);


/*
|--------------------------------------------------------------------------
| MAĞAZA GÜNCELLEME — HESAP MERKEZİ ENTEGRASYONU
|--------------------------------------------------------------------------
|
| Bu bölümde gönderilen Domain, Hakkımızda ve Logo talepleri
| veritabanına veya kullanıcı meta alanlarına kaydedilmez.
| Form verileri yalnızca iletisim@magazify.com adresine e-posta
| olarak gönderilir.
|
*/

function mhm_store_update_can_manage() {

    return is_user_logged_in()
        && current_user_can('manage_woocommerce');
}


/*
|--------------------------------------------------------------------------
| MAĞAZA GÜNCELLEME ENDPOINT
|--------------------------------------------------------------------------
*/

function mhm_register_store_update_endpoint() {

    add_rewrite_endpoint(
        'magaza-guncelleme',
        EP_ROOT | EP_PAGES
    );
}

add_action(
    'init',
    'mhm_register_store_update_endpoint'
);


function mhm_activate_plugin() {

    mhm_register_integrated_account_endpoints();
    mhm_register_store_update_endpoint();
    flush_rewrite_rules();
}

register_activation_hook(
    __FILE__,
    'mhm_activate_plugin'
);


function mhm_deactivate_plugin() {

    flush_rewrite_rules();
}

register_deactivation_hook(
    __FILE__,
    'mhm_deactivate_plugin'
);


/*
|--------------------------------------------------------------------------
| MAĞAZA GÜNCELLEME MENÜ ÖĞESİ
|--------------------------------------------------------------------------
|
| Önce özel endpoint menüye eklenir.
| Daha sonra mevcut mhm_account_menu_items() filtresi bunu doğru
| sıraya yerleştirir ve adını "Mağaza Güncelleme" olarak korur.
|
*/

function mhm_add_store_update_menu_item($items) {

    if (!mhm_store_update_can_manage()) {
        return $items;
    }

    if (isset($items['magaza-guncelleme'])) {
        return $items;
    }

    $new_items = [];

    foreach ($items as $key => $label) {

        $new_items[$key] = $label;

        if ($key === 'hesap-hareketleri') {
            $new_items['magaza-guncelleme'] =
                'Mağaza Güncelleme';
        }
    }

    if (!isset($new_items['magaza-guncelleme'])) {
        $new_items['magaza-guncelleme'] =
            'Mağaza Güncelleme';
    }

    return $new_items;
}

add_filter(
    'woocommerce_account_menu_items',
    'mhm_add_store_update_menu_item',
    100
);


/*
|--------------------------------------------------------------------------
| MAĞAZA GÜNCELLEME ENDPOINT İÇERİĞİ
|--------------------------------------------------------------------------
*/

function mhm_store_update_endpoint_content() {

    if (!mhm_store_update_can_manage()) {

        echo '<p>Bu alanı görüntüleme yetkiniz bulunmuyor.</p>';
        return;
    }

    mhm_render_store_update_page();
}

add_action(
    'woocommerce_account_magaza-guncelleme_endpoint',
    'mhm_store_update_endpoint_content'
);


/*
|--------------------------------------------------------------------------
| MAĞAZA GÜNCELLEME DURUM MESAJI
|--------------------------------------------------------------------------
*/

function mhm_store_update_message() {

    $status = isset($_GET['mhm_store_update'])
        ? sanitize_key(
            wp_unslash(
                $_GET['mhm_store_update']
            )
        )
        : '';

    $messages = [
        'domain_success' => [
            'class'   => 'mhm-store-message-success',
            'message' => 'Domain değişimi talebiniz gönderildi.',
        ],
        'about_success' => [
            'class'   => 'mhm-store-message-success',
            'message' => 'Hakkımızda güncelleme talebiniz gönderildi.',
        ],
        'logo_success' => [
            'class'   => 'mhm-store-message-success',
            'message' => 'Logo değişimi talebiniz gönderildi.',
        ],
        'mail_error' => [
            'class'   => 'mhm-store-message-error',
            'message' => 'Talebiniz e-posta olarak gönderilemedi. Lütfen daha sonra tekrar deneyin.',
        ],
        'invalid_request' => [
            'class'   => 'mhm-store-message-error',
            'message' => 'İşlem doğrulanamadı. Lütfen tekrar deneyin.',
        ],
        'missing_domain' => [
            'class'   => 'mhm-store-message-error',
            'message' => 'Lütfen kullanmak istediğiniz domain adresini girin.',
        ],
        'missing_about' => [
            'class'   => 'mhm-store-message-error',
            'message' => 'Lütfen yeni Hakkımızda metnini girin.',
        ],
        'missing_logo' => [
            'class'   => 'mhm-store-message-error',
            'message' => 'Lütfen bir PNG logo dosyası seçin.',
        ],
        'invalid_logo' => [
            'class'   => 'mhm-store-message-error',
            'message' => 'Logo dosyası PNG formatında ve en fazla 5 MB olmalıdır.',
        ],
    ];

    if (
        $status === '' ||
        !isset($messages[$status])
    ) {
        return;
    }

    echo '<div class="mhm-store-message '
        . esc_attr($messages[$status]['class'])
        . '" role="status">';

    echo esc_html(
        $messages[$status]['message']
    );

    echo '</div>';
}


/*
|--------------------------------------------------------------------------
| MAĞAZA GÜNCELLEME FORM EKRANI
|--------------------------------------------------------------------------
*/

function mhm_render_store_update_page() {

    ?>

    <div class="mhm-store-update-page">

        <div class="mhm-store-update-header">

            <h2>Mağaza Güncelleme</h2>

            <div class="mhm-store-update-description">
                Mağazanızın domain adresi, Hakkımızda metni veya logosunda değişiklik yapmak için aşağıdaki alanları kullanabilirsiniz. Gönderdiğiniz talepler Magazify ekibine e-posta olarak iletilir ve incelendikten sonra uygulanır.
            </div>

        </div>

        <?php mhm_store_update_message(); ?>


        <!-- =====================================================
             DOMAIN DEĞİŞİMİ
             ===================================================== -->

        <section class="mhm-store-update-card">

            <div class="mhm-store-update-card-header">

                <h3>Domain Değişimi</h3>

                <div class="mhm-store-card-description">
                    Mağazanızda kullanmak istediğiniz domain adresini girin ve domain adresinizi Magazify altyapısına yönlendirin.
                    <a
                        href="https://magazify.com/domain/"
                        target="_blank"
                        rel="noopener noreferrer"
                    >Domain yönlendirme detaylarını görüntüleyin.</a>
                </div>

            </div>

            <form
                method="post"
                action="<?php
                echo esc_url(
                    admin_url('admin-post.php')
                );
                ?>"
                class="mhm-store-update-form"
            >

                <input
                    type="hidden"
                    name="action"
                    value="mhm_store_update_domain"
                >

                <?php
                wp_nonce_field(
                    'mhm_store_update_domain',
                    'mhm_store_update_nonce'
                );
                ?>

                <div class="mhm-store-field">

                    <label for="mhm_domain_address">
                        Yeni Domain Adresi
                    </label>

                    <input
                        id="mhm_domain_address"
                        type="text"
                        name="domain_address"
                        placeholder="ornek.com"
                        autocomplete="off"
                        required
                    >

                </div>

                <div class="mhm-store-field">

                    <label for="mhm_domain_description">
                        Açıklama
                    </label>

                    <textarea
                        id="mhm_domain_description"
                        name="description"
                        rows="5"
                        placeholder="Domain değişimiyle ilgili eklemek istediğiniz açıklamayı yazın..."
                    ></textarea>

                </div>

                <button
                    type="submit"
                    class="mhm-store-submit"
                >
                    Onayla ve Gönder
                </button>

            </form>

        </section>


        <!-- =====================================================
             HAKKIMIZDA GÜNCELLEME
             ===================================================== -->

        <section class="mhm-store-update-card">

            <div class="mhm-store-update-card-header">

                <h3>Hakkımızda Güncelleme</h3>

                <div class="mhm-store-card-description">
                    Mağazanızın Hakkımızda bölümünde yayınlanmasını istediğiniz yeni metni aşağıdaki alana eksiksiz olarak girin.
                </div>

            </div>

            <form
                method="post"
                action="<?php
                echo esc_url(
                    admin_url('admin-post.php')
                );
                ?>"
                class="mhm-store-update-form"
            >

                <input
                    type="hidden"
                    name="action"
                    value="mhm_store_update_about"
                >

                <?php
                wp_nonce_field(
                    'mhm_store_update_about',
                    'mhm_store_update_nonce'
                );
                ?>

                <div class="mhm-store-field">

                    <label for="mhm_about_text">
                        Yeni Hakkımızda Metni
                    </label>

                    <textarea
                        id="mhm_about_text"
                        name="about_text"
                        rows="8"
                        placeholder="Yeni Hakkımızda metnini yazın..."
                        required
                    ></textarea>

                </div>

                <button
                    type="submit"
                    class="mhm-store-submit"
                >
                    Onayla ve Gönder
                </button>

            </form>

        </section>


        <!-- =====================================================
             LOGO DEĞİŞİMİ
             ===================================================== -->

        <section class="mhm-store-update-card">

            <div class="mhm-store-update-card-header">

                <h3>Logo Değişimi</h3>

                <div class="mhm-store-card-description">
                    Logo dosyanızı 150 × 50 px ölçülerinde, PNG formatında ve tercihen 300 DPI çözünürlükte hazırlayın. Şeffaf arka plan kullanılması tavsiye edilir. Logo dosyasının en fazla 5 MB olması gerekir.
                </div>

            </div>

            <form
                method="post"
                action="<?php
                echo esc_url(
                    admin_url('admin-post.php')
                );
                ?>"
                enctype="multipart/form-data"
                class="mhm-store-update-form"
            >

                <input
                    type="hidden"
                    name="action"
                    value="mhm_store_update_logo"
                >

                <?php
                wp_nonce_field(
                    'mhm_store_update_logo',
                    'mhm_store_update_nonce'
                );
                ?>

                <div class="mhm-store-field">

                    <label for="mhm_logo_description">
                        Açıklama
                    </label>

                    <textarea
                        id="mhm_logo_description"
                        name="description"
                        rows="5"
                        placeholder="Logo değişimiyle ilgili açıklamanızı yazın..."
                    ></textarea>

                </div>

                <div class="mhm-store-field">

                    <label for="mhm_logo_file">
                        Logo Dosyası
                    </label>

                    <input
                        id="mhm_logo_file"
                        type="file"
                        name="logo_file"
                        accept="image/png,.png"
                        required
                    >

                    <span
                        class="mhm-logo-file-name"
                        data-empty-text="Henüz dosya seçilmedi."
                    >
                        Henüz dosya seçilmedi.
                    </span>

                </div>

                <button
                    type="submit"
                    class="mhm-store-submit"
                >
                    Onayla ve Gönder
                </button>

            </form>

        </section>

    </div>

    <?php
}


/*
|--------------------------------------------------------------------------
| E-POSTA ORTAK BİLGİLERİ
|--------------------------------------------------------------------------
*/

function mhm_store_update_email_context() {

    $user = wp_get_current_user();

    return [
        'user_id'      => (int) $user->ID,
        'display_name' => $user->display_name,
        'email'        => $user->user_email,
        'site_name'    => get_bloginfo('name'),
        'site_url'     => home_url('/'),
    ];
}


function mhm_store_update_context_text() {

    $context = mhm_store_update_email_context();

    $text  = "Mağaza / Site: "
        . $context['site_name']
        . "\n";

    $text .= "Site Adresi: "
        . $context['site_url']
        . "\n";

    $text .= "Kullanıcı: "
        . $context['display_name']
        . "\n";

    $text .= "Kullanıcı E-posta: "
        . $context['email']
        . "\n";

    $text .= "Kullanıcı ID: "
        . $context['user_id']
        . "\n";

    $text .= "Talep Tarihi: "
        . wp_date('d.m.Y H:i')
        . "\n";

    return $text;
}


/*
|--------------------------------------------------------------------------
| FORM DOĞRULAMA
|--------------------------------------------------------------------------
*/

function mhm_store_update_verify_request($action) {

    if (!mhm_store_update_can_manage()) {
        wp_die('Bu işlemi yapma yetkiniz bulunmuyor.');
    }

    if (
        !isset($_POST['mhm_store_update_nonce']) ||
        !wp_verify_nonce(
            sanitize_text_field(
                wp_unslash(
                    $_POST['mhm_store_update_nonce']
                )
            ),
            $action
        )
    ) {
        mhm_store_update_redirect(
            'invalid_request'
        );
    }
}


/*
|--------------------------------------------------------------------------
| DOMAIN DEĞİŞİM TALEBİ
|--------------------------------------------------------------------------
*/

function mhm_handle_store_update_domain() {

    mhm_store_update_verify_request(
        'mhm_store_update_domain'
    );

    $domain = isset($_POST['domain_address'])
        ? sanitize_text_field(
            wp_unslash(
                $_POST['domain_address']
            )
        )
        : '';

    $description = isset($_POST['description'])
        ? sanitize_textarea_field(
            wp_unslash(
                $_POST['description']
            )
        )
        : '';

    if ($domain === '') {
        mhm_store_update_redirect(
            'missing_domain'
        );
    }

    $body  = mhm_store_update_context_text();
    $body .= "\n";
    $body .= "Yeni Domain Adresi:\n";
    $body .= $domain . "\n\n";
    $body .= "Açıklama:\n";
    $body .= (
        $description !== ''
            ? $description
            : 'Açıklama girilmedi.'
    );

    $sent = wp_mail(
        'iletisim@magazify.com',
        'Magazify - Domain Değişimi Talebi',
        $body
    );

    mhm_store_update_redirect(
        $sent
            ? 'domain_success'
            : 'mail_error'
    );
}

add_action(
    'admin_post_mhm_store_update_domain',
    'mhm_handle_store_update_domain'
);


/*
|--------------------------------------------------------------------------
| HAKKIMIZDA GÜNCELLEME TALEBİ
|--------------------------------------------------------------------------
*/

function mhm_handle_store_update_about() {

    mhm_store_update_verify_request(
        'mhm_store_update_about'
    );

    $about_text = isset($_POST['about_text'])
        ? sanitize_textarea_field(
            wp_unslash(
                $_POST['about_text']
            )
        )
        : '';

    if ($about_text === '') {
        mhm_store_update_redirect(
            'missing_about'
        );
    }

    $body  = mhm_store_update_context_text();
    $body .= "\n";
    $body .= "Yeni Hakkımızda Metni:\n\n";
    $body .= $about_text;

    $sent = wp_mail(
        'iletisim@magazify.com',
        'Magazify - Hakkımızda Güncelleme Talebi',
        $body
    );

    mhm_store_update_redirect(
        $sent
            ? 'about_success'
            : 'mail_error'
    );
}

add_action(
    'admin_post_mhm_store_update_about',
    'mhm_handle_store_update_about'
);


/*
|--------------------------------------------------------------------------
| LOGO DEĞİŞİM TALEBİ
|--------------------------------------------------------------------------
*/

function mhm_handle_store_update_logo() {

    mhm_store_update_verify_request(
        'mhm_store_update_logo'
    );

    $description = isset($_POST['description'])
        ? sanitize_textarea_field(
            wp_unslash(
                $_POST['description']
            )
        )
        : '';

    if (
        !isset($_FILES['logo_file']) ||
        empty($_FILES['logo_file']['name']) ||
        (int) $_FILES['logo_file']['error'] !== UPLOAD_ERR_OK
    ) {
        mhm_store_update_redirect(
            'missing_logo'
        );
    }

    $file = $_FILES['logo_file'];

    if ((int) $file['size'] > 5 * 1024 * 1024) {
        mhm_store_update_redirect(
            'invalid_logo'
        );
    }

    $filetype = wp_check_filetype_and_ext(
        $file['tmp_name'],
        $file['name'],
        [
            'png' => 'image/png',
        ]
    );

    if (
        empty($filetype['ext']) ||
        $filetype['ext'] !== 'png' ||
        empty($filetype['type']) ||
        $filetype['type'] !== 'image/png'
    ) {
        mhm_store_update_redirect(
            'invalid_logo'
        );
    }

    /*
     * Dosya WordPress medya kütüphanesine veya upload klasörüne
     * kaydedilmez. PHP'nin yükleme sırasında oluşturduğu geçici
     * dosya wp_mail() çağrısında doğrudan ek olarak kullanılır.
     */
    $attachment_path = $file['tmp_name'];

    $body  = mhm_store_update_context_text();
    $body .= "\n";
    $body .= "Logo Değişimi Açıklaması:\n";
    $body .= (
        $description !== ''
            ? $description
            : 'Açıklama girilmedi.'
    );

    $sent = wp_mail(
        'iletisim@magazify.com',
        'Magazify - Logo Değişimi Talebi',
        $body,
        [],
        [
            $attachment_path,
        ]
    );

    mhm_store_update_redirect(
        $sent
            ? 'logo_success'
            : 'mail_error'
    );
}

add_action(
    'admin_post_mhm_store_update_logo',
    'mhm_handle_store_update_logo'
);


/*
|--------------------------------------------------------------------------
| FORM SONRASI YÖNLENDİRME
|--------------------------------------------------------------------------
*/

function mhm_store_update_redirect($status) {

    $url = function_exists(
        'wc_get_account_endpoint_url'
    )
        ? wc_get_account_endpoint_url(
            'magaza-guncelleme'
        )
        : home_url('/');

    $url = add_query_arg(
        'mhm_store_update',
        sanitize_key($status),
        $url
    );

    wp_safe_redirect($url);
    exit;
}





/*
|--------------------------------------------------------------------------
| MAĞAZA YÖNETİMİ SAYFASI
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| MAĞAZA YÖNETİMİ ANA SAYFASI
|--------------------------------------------------------------------------
|
| WooCommerce'in kendi Hesabım endpoint yönlendirmesine müdahale edilmez.
| Böylece Ürün Oluşturma, Ürün Yönetimi, Siparişler, Hesap Hareketleri,
| Mağaza Güncelleme ve Şifre Değiştirme gibi gerçek endpoint'ler normal
| şekilde çalışmaya devam eder.
|
| Yalnızca Hesabım ana sayfası (dashboard) açıldığında WooCommerce'in
| varsayılan dashboard çıktısı tamponlanır ve yerine Magazify
| Mağaza Yönetimi ekranı gösterilir.
|
*/

function mhm_is_dashboard_request() {

    if (
        !function_exists('is_account_page') ||
        !is_account_page()
    ) {
        return false;
    }

    global $wp;

    $endpoint_keys = [];

    if (function_exists('wc_get_account_menu_items')) {
        $endpoint_keys = array_keys(
            wc_get_account_menu_items()
        );
    }

    if (
        function_exists('WC') &&
        WC() &&
        isset(WC()->query) &&
        method_exists(
            WC()->query,
            'get_query_vars'
        )
    ) {
        $endpoint_keys = array_merge(
            $endpoint_keys,
            array_keys(
                WC()->query->get_query_vars()
            )
        );
    }

    $endpoint_keys = array_merge(
        $endpoint_keys,
        [
            'view-order',
            'order-pay',
            'order-received',
            'lost-password',
        ]
    );

    $endpoint_keys = array_unique(
        $endpoint_keys
    );

    foreach ($endpoint_keys as $endpoint) {

        if (
            $endpoint === 'dashboard' ||
            $endpoint === 'customer-logout'
        ) {
            continue;
        }

        if (
            isset($wp->query_vars) &&
            array_key_exists(
                $endpoint,
                $wp->query_vars
            )
        ) {
            return false;
        }
    }

    return true;
}


/*
|--------------------------------------------------------------------------
| DASHBOARD ÇIKTISINI TAMPONLA
|--------------------------------------------------------------------------
*/

function mhm_dashboard_buffer_start() {

    if (!mhm_is_dashboard_request()) {
        return;
    }

    $GLOBALS['mhm_dashboard_buffer_level'] =
        ob_get_level() + 1;

    ob_start();
}

add_action(
    'woocommerce_account_content',
    'mhm_dashboard_buffer_start',
    1
);


/*
|--------------------------------------------------------------------------
| VARSAYILAN DASHBOARD YERİNE MAĞAZA YÖNETİMİ
|--------------------------------------------------------------------------
*/

function mhm_dashboard_buffer_replace() {

    if (!mhm_is_dashboard_request()) {
        return;
    }

    $buffer_level = isset(
        $GLOBALS['mhm_dashboard_buffer_level']
    )
        ? (int) $GLOBALS['mhm_dashboard_buffer_level']
        : 0;

    if (
        $buffer_level > 0 &&
        ob_get_level() >= $buffer_level
    ) {
        ob_end_clean();
    }

    unset(
        $GLOBALS['mhm_dashboard_buffer_level']
    );

    mhm_render_store_management_dashboard();
}

add_action(
    'woocommerce_account_content',
    'mhm_dashboard_buffer_replace',
    9999
);


/*
|--------------------------------------------------------------------------
| HESABIM ENDPOINT BAĞLANTILARI
|--------------------------------------------------------------------------
*/

function mhm_account_endpoint_url(array $candidates) {

    if (!function_exists('wc_get_account_menu_items')) {
        return '';
    }

    $items = wc_get_account_menu_items();

    foreach ($candidates as $endpoint) {

        if (!isset($items[$endpoint])) {
            continue;
        }

        if ($endpoint === 'dashboard') {
            return wc_get_page_permalink('myaccount');
        }

        return wc_get_account_endpoint_url($endpoint);
    }

    return '';
}


/*
|--------------------------------------------------------------------------
| VARSAYILAN İÇERİKLER
|--------------------------------------------------------------------------
*/

function mhm_default_content_settings() {

    return [
        'partner_info_title' => 'Mağaza Ortağı Bilgilendirme',
        'partner_info_intro' => '',

        'page_title' => 'Mağaza Yönetimi',
        'page_intro' => 'Mağazanızla ilgili ürün oluşturma, ürün yönetimi, sipariş takibi, hesap hareketleri ve güncelleme işlemlerine bu sayfa üzerinden ulaşabilirsiniz. Aşağıdaki bölümler aynı zamanda Mağaza Yönetimi panelinin kullanım kılavuzu olarak hazırlanmıştır.',

        /*
         * Teknik Destek ayarları.
         * Kategoriler her satır ayrı kategori olacak şekilde saklanır.
         */
        'support_email' => sanitize_email(get_option('admin_email')),
        'support_categories' => "Genel Destek\nÜrün Oluşturma\nÜrün Yönetimi\nSiparişler\nHesap Hareketleri\nMağaza Güncelleme\nHesap Bilgileri\nTeknik Sorun\nDiğer",
        'support_intro' => 'Mağazanızın kullanımı, ürün oluşturma, siparişler, hesap hareketleri veya teknik bir sorunla ilgili destek talebinizi aşağıdaki form üzerinden iletebilirsiniz. Talebiniz Magazify Destek Ekibi’ne e-posta olarak gönderilecektir.',
        'support_file_help' => 'JPG, PNG, WEBP veya PDF. Maksimum dosya boyutu 5 MB.',
        'support_account_note' => 'Hesap adınız, kayıtlı e-posta adresiniz ve mağaza/site bilgisi talebe otomatik olarak eklenir.',

        'design_upload_title' => 'Tasarım Yükle',
        'design_upload_intro' => 'Baskıya uygun tasarımlarınızı bu bölümden yükleyebilirsiniz. Yüklenen tasarımlar hesabınıza bağlı olarak saklanır ve gerektiğinde silebilirsiniz.',
        'design_upload_rules' => '',
        'design_upload_button' => 'Tasarımı Yükle',
        'design_max_file_mb' => 15,
        'design_width' => 900,
        'design_height' => 1350,
        'design_dpi' => 300,

        'sections' => [
            'store' => [
                'title'          => 'Mağaza Nedir?',
                'description'    => '“Mağaza Adı”, sizin Mağaza Ortağı olduğunuz alışveriş sitesidir. Mağaza Ortağı olarak şirket kurmadan, stok tutmadan, ürün tedariği ve operasyon süreçleriyle uğraşmadan satış yapabilir ve hakediş kazanabilirsiniz. Aynı zamanda kendi mağazanızdan yapacağınız alışverişlerde en az hakediş tutarı kadar avantajdan ve dönemsel sürpriz ödüllerden yararlanabilirsiniz.',
                'video_1_url'    => '',
                'video_1_text'   => '',
                'video_2_url'    => '',
                'video_2_text'   => '',
                'video_3_url'    => '',
                'video_3_text'   => '',
                'button_label'   => '',
                'button_url'     => '',
                'endpoints'      => [],
            ],

            'collection' => [
                'title'          => 'Mağaza Koleksiyonu Nedir?',
                'description'    => 'Mağaza Koleksiyonu, mağazanızda satışa sunduğunuz ürünlerden oluşur. Koleksiyona eklenen ürünler mağazanızda satışa açılır ve satış gerçekleştikçe ilgili ürün için belirlenen hakediş tutarı hesaplanır.',
                'video_1_url'    => '',
                'video_1_text'   => '',
                'video_2_url'    => '',
                'video_2_text'   => '',
                'video_3_url'    => '',
                'video_3_text'   => '',
                'button_label'   => '',
                'button_url'     => '',
                'endpoints'      => [],
            ],

            'creator' => [
                'title'          => 'Mağaza Koleksiyonu Ürünü Oluşturma',
                'description'    => 'Bu bölümde tasarımlarınızı uygun ürünlere uygulayabilir, ürün görsellerini hazırlayabilir ve Mağaza Koleksiyonunuza eklemek üzere yeni ürünler oluşturabilirsiniz.',
                'video_1_url'    => '',
                'video_1_text'   => '',
                'video_2_url'    => '',
                'video_2_text'   => '',
                'video_3_url'    => '',
                'video_3_text'   => '',
                'button_label'   => 'Ürün Oluşturmaya Git',
                'button_url'     => '',
                'endpoints'      => [
                    'urun-gorsel-olustur',
                    'urun-gorsel-olusturma',
                    'urun-gorsel',
                    'urun-olustur',
                    'urun-olusturma',
                    'pod-urun-olustur',
                    'mockup-creator',
                ],
            ],

            'products' => [
                'title'          => 'Mağaza Koleksiyonu Yönetimi',
                'description'    => 'Mağazanızdaki ürünleri, satış fiyatlarını ve referans hakediş tutarlarını bu bölümden inceleyebilirsiniz. Satışta olmasını istemediğiniz ürünleri koleksiyonunuzdan kaldırabilirsiniz.',
                'video_1_url'    => '',
                'video_1_text'   => '',
                'video_2_url'    => '',
                'video_2_text'   => '',
                'video_3_url'    => '',
                'video_3_text'   => '',
                'button_label'   => 'Ürünleri Yönet',
                'button_url'     => '',
                'endpoints'      => [
                    'urun-yonetimi',
                    'urun-yonetim',
                ],
            ],

            'orders' => [
                'title'          => 'Sipariş Yönetimi',
                'description'    => 'Mağazanız üzerinden verilen siparişlerin tarihini, durumunu, müşteri ve ürün bilgilerini, KDV tutarlarını, gönderim değerlerini, referans hakedişi ve toplam satış tutarını bu bölümden takip edebilirsiniz.',
                'video_1_url'    => '',
                'video_1_text'   => '',
                'video_2_url'    => '',
                'video_2_text'   => '',
                'video_3_url'    => '',
                'video_3_text'   => '',
                'button_label'   => 'Siparişleri Görüntüle',
                'button_url'     => '',
                'endpoints'      => ['orders'],
            ],

            'movements' => [
                'title'          => 'Hesap Hareketleri',
                'description'    => 'Siparişlerden oluşan hakedişleri, gönderim maliyetlerini, yapılan ödemeleri ve kalan bakiyenizi bu bölümden takip edebilirsiniz. Finansal hareketler sipariş bazında ve şeffaf biçimde gösterilir.',
                'video_1_url'    => '',
                'video_1_text'   => '',
                'video_2_url'    => '',
                'video_2_text'   => '',
                'video_3_url'    => '',
                'video_3_text'   => '',
                'button_label'   => 'Hesap Hareketlerine Git',
                'button_url'     => '',
                'endpoints'      => ['hesap-hareketleri'],
            ],

            'store_update' => [
                'title'          => 'Mağaza Güncelleme',
                'description'    => 'Mağaza adı, alan adı, logo, renkler ve mağazanızın görünümüyle ilgili güncelleme taleplerinizi bu bölümden iletebilirsiniz.',
                'video_1_url'    => '',
                'video_1_text'   => '',
                'video_2_url'    => '',
                'video_2_text'   => '',
                'video_3_url'    => '',
                'video_3_text'   => '',
                'button_label'   => 'Mağaza Güncellemeye Git',
                'button_url'     => '',
                'endpoints'      => [
                    'magaza-guncelleme',
                    'magaza-guncelle',
                    'magaza-guncelleme-talebi',
                ],
            ],

            'account_info' => [
                'title'          => 'Hesap Bilgileri',
                'description'    => 'Hakediş ödemeleri için gerekli banka, iletişim ve kimlik bilgilerinizi bu bölümden kaydedebilir veya güncelleyebilirsiniz.',
                'video_1_url'    => '',
                'video_1_text'   => '',
                'video_2_url'    => '',
                'video_2_text'   => '',
                'video_3_url'    => '',
                'video_3_text'   => '',
                'button_label'   => 'Hesap Bilgilerine Git',
                'button_url'     => '',
                'endpoints'      => ['hesap-bilgileri'],
            ],

            'support' => [
                'title'          => 'Teknik Destek',
                'description'    => 'Teknik sorunlar, ürün oluşturma, siparişler, hesap hareketleri veya mağaza güncelleme süreçleriyle ilgili destek taleplerinizi bu bölüm üzerinden Magazify ekibine iletebilirsiniz.',
                'video_1_url'    => '',
                'video_1_text'   => '',
                'video_2_url'    => '',
                'video_2_text'   => '',
                'video_3_url'    => '',
                'video_3_text'   => '',
                'button_label'   => 'Teknik Desteğe Git',
                'button_url'     => '',
                'endpoints'      => ['destek'],
            ],
        ],
    ];
}


/*
|--------------------------------------------------------------------------
| KAYITLI İÇERİKLERİ GETİR
|--------------------------------------------------------------------------
*/

function mhm_content_settings() {

    $defaults = mhm_default_content_settings();
    $saved    = get_option('mhm_content_settings', []);

    if (!is_array($saved)) {
        return $defaults;
    }

    $settings = $defaults;

    if (isset($saved['partner_info_title'])) {
        $settings['partner_info_title'] = sanitize_text_field($saved['partner_info_title']);
    }
    if (isset($saved['partner_info_intro'])) {
        $settings['partner_info_intro'] = wp_kses_post($saved['partner_info_intro']);
    }

    if (isset($saved['page_title'])) {
        $settings['page_title'] = $saved['page_title'];
    }

    if (isset($saved['page_intro'])) {
        $settings['page_intro'] = $saved['page_intro'];
    }

    if (isset($saved['support_email'])) {
        $saved_email = sanitize_email($saved['support_email']);

        if ($saved_email !== '') {
            $settings['support_email'] = $saved_email;
        }
    }

    if (isset($saved['support_categories'])) {
        $settings['support_categories'] =
            sanitize_textarea_field($saved['support_categories']);
    }

    if (isset($saved['support_intro'])) {
        $settings['support_intro'] =
            sanitize_textarea_field($saved['support_intro']);
    }

    if (isset($saved['support_file_help'])) {
        $settings['support_file_help'] =
            sanitize_textarea_field($saved['support_file_help']);
    }

    if (isset($saved['support_account_note'])) {
        $settings['support_account_note'] =
            sanitize_textarea_field($saved['support_account_note']);
    }

    if (isset($saved['design_upload_title'])) {
        $settings['design_upload_title'] =
            sanitize_text_field($saved['design_upload_title']);
    }

    if (isset($saved['design_upload_intro'])) {
        $settings['design_upload_intro'] =
            sanitize_textarea_field($saved['design_upload_intro']);
    }

    if (isset($saved['design_upload_rules'])) {
        $settings['design_upload_rules'] =
            sanitize_textarea_field($saved['design_upload_rules']);
    }

    if (isset($saved['design_upload_button'])) {
        $settings['design_upload_button'] =
            sanitize_text_field($saved['design_upload_button']);
    }

    if (isset($saved['design_max_file_mb'])) {
        $settings['design_max_file_mb'] = max(
            1,
            absint($saved['design_max_file_mb'])
        );
    }

    if (isset($saved['design_width'])) {
        $settings['design_width'] = max(
            1,
            absint($saved['design_width'])
        );
    }

    if (isset($saved['design_height'])) {
        $settings['design_height'] = max(
            1,
            absint($saved['design_height'])
        );
    }

    if (isset($saved['design_dpi'])) {
        $settings['design_dpi'] = max(
            1,
            absint($saved['design_dpi'])
        );
    }

    if (!empty($saved['sections']) && is_array($saved['sections'])) {

        foreach ($settings['sections'] as $key => $section) {

            if (
                !isset($saved['sections'][$key]) ||
                !is_array($saved['sections'][$key])
            ) {
                continue;
            }

            foreach ($section as $field => $value) {

                if ($field === 'endpoints') {
                    continue;
                }

                if (array_key_exists($field, $saved['sections'][$key])) {
                    $settings['sections'][$key][$field] =
                        $saved['sections'][$key][$field];
                }
            }

            /*
             * 2.2.0 ve öncesindeki tek video alanını otomatik olarak
             * ilk video slotuna taşır. Eski kayıt silinmez.
             */
            if (
                empty($settings['sections'][$key]['video_1_url']) &&
                !empty($saved['sections'][$key]['video_url'])
            ) {
                $settings['sections'][$key]['video_1_url'] =
                    esc_url_raw($saved['sections'][$key]['video_url']);
            }
        }
    }

    return $settings;
}


/*
|--------------------------------------------------------------------------
| VİDEO ÇIKTISI
|--------------------------------------------------------------------------
*/

function mhm_render_video($video_url) {

    $video_url = trim((string) $video_url);

    if ($video_url === '') {

        return '';

    }

    $path = wp_parse_url(

        $video_url,

        PHP_URL_PATH

    );

    $extension = strtolower(

        pathinfo(

            (string) $path,

            PATHINFO_EXTENSION

        )

    );

    /*

     * Doğrudan yüklenen video dosyaları.

     */

    if (

        in_array(

            $extension,

            ['mp4', 'webm', 'ogg'],

            true

        )

    ) {

        $mime_types = [

            'mp4'  => 'video/mp4',

            'webm' => 'video/webm',

            'ogg'  => 'video/ogg',

        ];

        return sprintf(

            '<video

                class="mhm-local-video"

                controls

                preload="metadata"

                playsinline

            >

                <source

                    src="%1$s"

                    type="%2$s"

                >

                Tarayıcınız video oynatmayı desteklemiyor.

            </video>',

            esc_url($video_url),

            esc_attr($mime_types[$extension])

        );

    }

    /*

     * YouTube bağlantıları.

     */

    if (

        preg_match(

            '~(?:youtube\.com/watch\?v=|youtu\.be/)([a-zA-Z0-9_-]+)~',

            $video_url,

            $matches

        )

    ) {

        return sprintf(

            '<iframe

                src="https://www.youtube.com/embed/%1$s"

                width="560"
                height="315"

                title="Eğitim videosu"

                loading="lazy"

                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"

                allowfullscreen

            ></iframe>',

            esc_attr($matches[1])

        );

    }

    /*

     * Vimeo bağlantıları.

     */

    if (

        preg_match(

            '~vimeo\.com/([0-9]+)~',

            $video_url,

            $matches

        )

    ) {

        return sprintf(

            '<iframe

                src="https://player.vimeo.com/video/%1$s"

                width="560"
                height="315"

                title="Eğitim videosu"

                loading="lazy"

                allow="autoplay; fullscreen; picture-in-picture"

                allowfullscreen

            ></iframe>',

            esc_attr($matches[1])

        );

    }

    return sprintf(

        '<a

            class="mhm-video-link"

            href="%1$s"

            target="_blank"

            rel="noopener noreferrer"

        >

            Eğitim videosunu aç

        </a>',

        esc_url($video_url)

    );

}



/*
|--------------------------------------------------------------------------
| ZENGİN METİN ÇIKTISI
|--------------------------------------------------------------------------
*/

function mhm_render_rich_text($content) {

    $content = (string) $content;

    if ($content === '') {
        return '';
    }

    return wp_kses_post(
        wpautop(
            do_shortcode($content)
        )
    );
}


/*
|--------------------------------------------------------------------------
| MAĞAZA YÖNETİMİ SAYFASI ÇIKTISI
|--------------------------------------------------------------------------
*/

function mhm_render_store_management_dashboard() {

    $settings = mhm_content_settings();
    ?>

    <div class="mhm-management-page">

        <header class="mhm-management-header mhm-partner-info-header">
            <h2><?php echo esc_html($settings['partner_info_title']); ?></h2>
            <div class="mhm-rich-content mhm-management-intro">
                <?php echo mhm_render_rich_text($settings['partner_info_intro']); ?>
            </div>
        </header>

        <header class="mhm-management-header">

            <h2>
                <?php echo esc_html($settings['page_title']); ?>
            </h2>

            <div class="mhm-rich-content mhm-management-intro">
                <?php
                echo mhm_render_rich_text(
                    $settings['page_intro']
                );
                ?>
            </div>

        </header>

        <div class="mhm-management-sections">

            <?php foreach ($settings['sections'] as $section) :

                $endpoint_url = !empty($section['endpoints'])
                    ? mhm_account_endpoint_url($section['endpoints'])
                    : '';

                $button_url = trim((string) $section['button_url']);

                if ($button_url === '') {
                    $button_url = $endpoint_url;
                }

                $videos = [];

                for ($video_index = 1; $video_index <= 3; $video_index++) {

                    $url_key  = 'video_' . $video_index . '_url';
                    $text_key = 'video_' . $video_index . '_text';

                    $video_url  = isset($section[$url_key])
                        ? trim((string) $section[$url_key])
                        : '';

                    $video_text = isset($section[$text_key])
                        ? trim((string) $section[$text_key])
                        : '';

                    $videos[] = [
                        'html' => mhm_render_video($video_url),
                        'text' => $video_text,
                    ];
                }
                ?>

                <section class="mhm-management-section">

                    <div class="mhm-management-content">

                        <h3>
                            <?php echo esc_html($section['title']); ?>
                        </h3>

                        <div class="mhm-rich-content mhm-section-description">
                            <?php
                            echo mhm_render_rich_text(
                                $section['description']
                            );
                            ?>
                        </div>

                        <div class="mhm-video-grid">

                            <?php foreach ($videos as $video) : ?>

                                <div class="mhm-video-card<?php
                                    echo $video['html'] === ''
                                        ? ' is-empty'
                                        : '';
                                ?>">

                                    <?php if ($video['html'] !== '') : ?>

                                        <div class="mhm-video-box mhm-video-box-compact">
                                            <?php
                                            /*
                                             * HTML mhm_render_video() içinde yalnızca
                                             * esc_url / esc_attr ile güvenli olarak
                                             * oluşturulur. HTML5 video/source yapısını
                                             * bozmamak için doğrudan basılır.
                                             */
                                            echo $video['html'];
                                            ?>
                                        </div>

                                        <?php if ($video['text'] !== '') : ?>
                                            <p class="mhm-video-caption">
                                                <?php echo esc_html($video['text']); ?>
                                            </p>
                                        <?php endif; ?>

                                    <?php else : ?>

                                        <div
                                            class="mhm-video-placeholder"
                                            aria-hidden="true"
                                        ></div>

                                    <?php endif; ?>

                                </div>

                            <?php endforeach; ?>

                        </div>

                        <?php if (
                            $button_url !== '' &&
                            $section['button_label'] !== ''
                        ) : ?>

                            <div class="mhm-section-action">

                                <a
                                    class="mhm-management-button"
                                    href="<?php echo esc_url($button_url); ?>"
                                >
                                    <?php
                                    echo esc_html(
                                        $section['button_label']
                                    );
                                    ?>
                                </a>

                            </div>

                        <?php endif; ?>

                    </div>

                </section>

            <?php endforeach; ?>

        </div>
    </div>

    <?php
}


/*
|--------------------------------------------------------------------------
| YÖNETİM MENÜSÜ
|--------------------------------------------------------------------------
*/

function mhm_admin_menu() {

    add_menu_page(

        'Hesap Merkezi',

        'Hesap Merkezi',

        'manage_woocommerce',

        'mhm-content-management',

        'mhm_admin_page',

        'dashicons-admin-users',

        26

    );

}

add_action(
    'admin_menu',
    'mhm_admin_menu'
);


/*
|--------------------------------------------------------------------------
| YÖNETİM PANELİ KAYDI
|--------------------------------------------------------------------------
*/

function mhm_save_admin_content() {

    if (!current_user_can('manage_woocommerce')) {
        wp_die('Bu işlemi yapma yetkiniz bulunmuyor.');
    }

    check_admin_referer(
        'mhm_save_content',
        'mhm_content_nonce'
    );

    $defaults = mhm_default_content_settings();
    $current  = mhm_content_settings();

    $settings = [
        'partner_info_title' => isset($_POST['partner_info_title'])
            ? sanitize_text_field(wp_unslash($_POST['partner_info_title']))
            : $defaults['partner_info_title'],
        'partner_info_intro' => isset($_POST['partner_info_intro'])
            ? wp_kses_post(wp_unslash($_POST['partner_info_intro']))
            : $defaults['partner_info_intro'],

        'page_title' => isset($_POST['page_title'])
            ? sanitize_text_field(wp_unslash($_POST['page_title']))
            : $defaults['page_title'],

        'page_intro' => isset($_POST['page_intro'])
            ? wp_kses_post(wp_unslash($_POST['page_intro']))
            : $defaults['page_intro'],

        /*
         * Teknik Destek ayarları ayrı alt menüden yönetilir.
         * Ana Hesap Merkezi içerikleri kaydedildiğinde mevcut destek
         * ayarlarının üzerine yazılmaz.
         */
        'support_email' => isset($current['support_email'])
            ? sanitize_email($current['support_email'])
            : $defaults['support_email'],

        'support_categories' => isset($current['support_categories'])
            ? sanitize_textarea_field($current['support_categories'])
            : $defaults['support_categories'],

        'support_intro' => isset($current['support_intro'])
            ? sanitize_textarea_field($current['support_intro'])
            : $defaults['support_intro'],

        'support_file_help' => isset($current['support_file_help'])
            ? sanitize_textarea_field($current['support_file_help'])
            : $defaults['support_file_help'],

        'support_account_note' => isset($current['support_account_note'])
            ? sanitize_textarea_field($current['support_account_note'])
            : $defaults['support_account_note'],

        'design_upload_title' => isset($current['design_upload_title'])
            ? sanitize_text_field($current['design_upload_title'])
            : $defaults['design_upload_title'],

        'design_upload_intro' => isset($current['design_upload_intro'])
            ? sanitize_textarea_field($current['design_upload_intro'])
            : $defaults['design_upload_intro'],

        'design_upload_rules' => isset($current['design_upload_rules'])
            ? sanitize_textarea_field($current['design_upload_rules'])
            : $defaults['design_upload_rules'],

        'design_upload_button' => isset($current['design_upload_button'])
            ? sanitize_text_field($current['design_upload_button'])
            : $defaults['design_upload_button'],

        'design_max_file_mb' => isset($current['design_max_file_mb'])
            ? max(1, absint($current['design_max_file_mb']))
            : $defaults['design_max_file_mb'],

        'design_width' => isset($current['design_width'])
            ? max(1, absint($current['design_width']))
            : $defaults['design_width'],

        'design_height' => isset($current['design_height'])
            ? max(1, absint($current['design_height']))
            : $defaults['design_height'],

        'design_dpi' => isset($current['design_dpi'])
            ? max(1, absint($current['design_dpi']))
            : $defaults['design_dpi'],

        'sections' => [],
    ];

    foreach ($defaults['sections'] as $key => $section) {

        $posted = isset($_POST['sections'][$key])
            && is_array($_POST['sections'][$key])
            ? wp_unslash($_POST['sections'][$key])
            : [];

        $settings['sections'][$key] = [
            'title' => isset($posted['title'])
                ? sanitize_text_field($posted['title'])
                : $section['title'],

            'description' => isset($posted['description'])
                ? wp_kses_post($posted['description'])
                : $section['description'],

            'video_1_url' => isset($posted['video_1_url'])
                ? esc_url_raw($posted['video_1_url'])
                : '',

            'video_1_text' => isset($posted['video_1_text'])
                ? sanitize_text_field($posted['video_1_text'])
                : '',

            'video_2_url' => isset($posted['video_2_url'])
                ? esc_url_raw($posted['video_2_url'])
                : '',

            'video_2_text' => isset($posted['video_2_text'])
                ? sanitize_text_field($posted['video_2_text'])
                : '',

            'video_3_url' => isset($posted['video_3_url'])
                ? esc_url_raw($posted['video_3_url'])
                : '',

            'video_3_text' => isset($posted['video_3_text'])
                ? sanitize_text_field($posted['video_3_text'])
                : '',

            'button_label' => isset($posted['button_label'])
                ? sanitize_text_field($posted['button_label'])
                : $section['button_label'],

            'button_url' => isset($posted['button_url'])
                ? esc_url_raw($posted['button_url'])
                : '',
        ];
    }

    update_option(
        'mhm_content_settings',
        $settings,
        false
    );

    wp_safe_redirect(
        add_query_arg(
            [
                'page'        => 'mhm-content-management',
                'mhm_updated' => '1',
            ],
            admin_url('admin.php')
        )
    );

    exit;
}

add_action(
    'admin_post_mhm_save_content',
    'mhm_save_admin_content'
);


/*
|--------------------------------------------------------------------------
| YÖNETİM PANELİ
|--------------------------------------------------------------------------
*/

function mhm_admin_page() {

    if (!current_user_can('manage_woocommerce')) {
        wp_die('Bu alanı görüntüleme yetkiniz bulunmuyor.');
    }

    $settings = mhm_content_settings();
    ?>

    <div class="wrap mhm-admin-page mhm-content-admin-page">

        <div class="mhm-admin-hero">

            <div>

                <span class="mhm-admin-eyebrow">MAGAZIFY</span>

                <h1>Hesap Merkezi İçerik Yönetimi</h1>

                <p class="mhm-admin-description">
                    Mağaza Yönetimi ekranındaki içerikleri, eğitim
                    videolarını, kılavuz metinlerini ve yönlendirme
                    butonlarını buradan yönetebilirsiniz.
                </p>

            </div>

            <div class="mhm-admin-hero-badge">
                Zengin Metin Editörü
            </div>

        </div>

        <?php if (
            isset($_GET['mhm_updated']) &&
            sanitize_key(wp_unslash($_GET['mhm_updated'])) === '1'
        ) : ?>

            <div class="notice notice-success is-dismissible">
                <p>Mağaza Yönetimi içerikleri kaydedildi.</p>
            </div>

        <?php endif; ?>

        <form
            method="post"
            action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
            class="mhm-admin-form"
        >

            <input
                type="hidden"
                name="action"
                value="mhm_save_content"
            >

            <?php
            wp_nonce_field(
                'mhm_save_content',
                'mhm_content_nonce'
            );
            ?>

            <section class="mhm-admin-main-settings">

                <h2>Mağaza Ortağı Bilgilendirme</h2>

                <div class="mhm-admin-field">
                    <label>Sayfa Başlığı</label>
                    <input type="text" name="partner_info_title"
                        value="<?php echo esc_attr($settings['partner_info_title']); ?>">
                </div>

                <div class="mhm-admin-field mhm-admin-editor-field">
                    <label>Giriş Metni</label>
                    <p class="mhm-admin-field-help">
                        Başlık, kalın metin, liste ve bağlantı gibi biçimlendirmeleri editör araçlarından kullanabilirsiniz.
                    </p>
                    <?php
                    wp_editor(
                        $settings['partner_info_intro'],
                        'mhm_partner_info_intro_editor',
                        [
                            'textarea_name' => 'partner_info_intro',
                            'textarea_rows' => 16,
                            'media_buttons' => false,
                            'teeny' => false,
                            'quicktags' => true,
                            'tinymce' => [
                                'toolbar1' => 'formatselect,bold,italic,bullist,numlist,blockquote,alignleft,aligncenter,alignright,link,unlink,undo,redo',
                                'toolbar2' => '',
                            ],
                        ]
                    );
                    ?>
                </div>

            </section>

            <section class="mhm-admin-main-settings">

                <h2>Sayfa Başlığı ve Giriş Metni</h2>

                <div class="mhm-admin-field">
                    <label for="mhm_page_title">
                        Sayfa Başlığı
                    </label>

                    <input
                        id="mhm_page_title"
                        type="text"
                        name="page_title"
                        value="<?php
                        echo esc_attr($settings['page_title']);
                        ?>"
                    >
                </div>

                <div class="mhm-admin-field mhm-admin-editor-field">

                    <label>
                        Giriş Metni
                    </label>

                    <p class="mhm-admin-field-help">
                        Başlık, kalın metin, liste ve bağlantı gibi
                        biçimlendirmeleri editör araçlarından kullanabilirsiniz.
                    </p>

                    <?php
                    wp_editor(
                        $settings['page_intro'],
                        'mhm_page_intro_editor',
                        [
                            'textarea_name' => 'page_intro',
                            'textarea_rows' => 16,
                            'media_buttons' => false,
                            'teeny'         => false,
                            'quicktags'     => true,
                            'tinymce'       => [
                                'toolbar1' => 'formatselect,bold,italic,bullist,numlist,blockquote,alignleft,aligncenter,alignright,link,unlink,undo,redo',
                                'toolbar2' => '',
                            ],
                        ]
                    );
                    ?>

                </div>

            </section>

            <div class="mhm-admin-sections">

                <?php
                $number = 0;

                foreach ($settings['sections'] as $key => $section) :

                    $number++;
                    ?>

                    <section class="mhm-admin-section">

                        <button
                            type="button"
                            class="mhm-admin-section-toggle"
                            aria-expanded="true"
                        >
                            <span class="mhm-admin-section-title-wrap">

                                <span class="mhm-admin-section-number">
                                    <?php
                                    echo esc_html(
                                        str_pad(
                                            (string) $number,
                                            2,
                                            '0',
                                            STR_PAD_LEFT
                                        )
                                    );
                                    ?>
                                </span>

                                <span class="mhm-admin-section-title">
                                    <?php echo esc_html($section['title']); ?>
                                </span>

                            </span>

                            <span class="mhm-admin-toggle-symbol">−</span>
                        </button>

                        <div class="mhm-admin-section-content">

                            <div class="mhm-admin-field">
                                <label>
                                    Bölüm Başlığı
                                </label>

                                <input
                                    type="text"
                                    name="sections[<?php
                                    echo esc_attr($key);
                                    ?>][title]"
                                    value="<?php
                                    echo esc_attr($section['title']);
                                    ?>"
                                >
                            </div>

                            <div class="mhm-admin-field mhm-admin-editor-field">

                                <label>
                                    Bölüm Açıklaması
                                </label>

                                <?php
                                wp_editor(
                                    $section['description'],
                                    'mhm_section_description_' . sanitize_key($key),
                                    [
                                        'textarea_name' =>
                                            'sections[' . $key . '][description]',
                                        'textarea_rows' => 8,
                                        'media_buttons' => false,
                                        'teeny'         => false,
                                        'quicktags'     => true,
                                        'tinymce'       => [
                                            'toolbar1' => 'formatselect,bold,italic,bullist,numlist,blockquote,link,unlink,undo,redo',
                                            'toolbar2' => '',
                                        ],
                                    ]
                                );
                                ?>

                            </div>

                            <div class="mhm-admin-video-slots">

                                <?php for ($video_index = 1; $video_index <= 3; $video_index++) :

                                    $url_key  = 'video_' . $video_index . '_url';
                                    $text_key = 'video_' . $video_index . '_text';
                                    ?>

                                    <div class="mhm-admin-video-slot">

                                        <h4>
                                            Video <?php echo esc_html($video_index); ?>
                                        </h4>

                                        <div class="mhm-admin-field">
                                            <label>Video URL</label>

                                            <input
                                                type="url"
                                                name="sections[<?php
                                                echo esc_attr($key);
                                                ?>][<?php
                                                echo esc_attr($url_key);
                                                ?>]"
                                                value="<?php
                                                echo esc_attr(
                                                    isset($section[$url_key])
                                                        ? $section[$url_key]
                                                        : ''
                                                );
                                                ?>"
                                                placeholder="YouTube, Vimeo veya MP4 URL"
                                            >
                                        </div>

                                        <div class="mhm-admin-field">
                                            <label>Kısa Video Açıklaması</label>

                                            <input
                                                type="text"
                                                name="sections[<?php
                                                echo esc_attr($key);
                                                ?>][<?php
                                                echo esc_attr($text_key);
                                                ?>]"
                                                value="<?php
                                                echo esc_attr(
                                                    isset($section[$text_key])
                                                        ? $section[$text_key]
                                                        : ''
                                                );
                                                ?>"
                                                placeholder="Videonun altında gösterilecek kısa açıklama"
                                            >
                                        </div>

                                    </div>

                                <?php endfor; ?>

                            </div>

                            <div class="mhm-admin-grid">

                                <div class="mhm-admin-field">
                                    <label>
                                        Bölüm Butonu Metni
                                    </label>

                                    <input
                                        type="text"
                                        name="sections[<?php
                                        echo esc_attr($key);
                                        ?>][button_label]"
                                        value="<?php
                                        echo esc_attr(
                                            $section['button_label']
                                        );
                                        ?>"
                                    >
                                </div>

                                <div class="mhm-admin-field">
                                    <label>
                                        Bölüm Butonu Özel URL
                                    </label>

                                    <input
                                        type="url"
                                        name="sections[<?php
                                        echo esc_attr($key);
                                        ?>][button_url]"
                                        value="<?php
                                        echo esc_attr(
                                            $section['button_url']
                                        );
                                        ?>"
                                        placeholder="Boş bırakılırsa ilgili Hesabım bölümü kullanılır"
                                    >
                                </div>

                            </div>

                        </div>

                    </section>

                <?php endforeach; ?>

            </div>

            <div class="mhm-admin-savebar">

                <div class="mhm-admin-savebar-text">
                    Yaptığınız değişiklikleri yayınlamak için kaydedin.
                </div>

                <button
                    type="submit"
                    class="button button-primary button-large"
                >
                    İçerikleri Kaydet
                </button>

            </div>

        </form>

    </div>

    <?php
}


/*
|--------------------------------------------------------------------------
| YÖNETİM PANELİ CSS VE JS
|--------------------------------------------------------------------------
*/

function mhm_admin_assets($hook_suffix) {

    if (

        $hook_suffix !==

        'toplevel_page_mhm-content-management'

    ) {

        return;

    }

    $css_file = plugin_dir_path(__FILE__) . 'mhm.css';
    $js_file  = plugin_dir_path(__FILE__) . 'mhm.js';

    $css_version = file_exists($css_file)
        ? (string) filemtime($css_file)
        : '2.2.5';

    $js_version = file_exists($js_file)
        ? (string) filemtime($js_file)
        : '2.2.5';

    wp_enqueue_style(
        'mhm-admin-style',
        plugin_dir_url(__FILE__) . 'mhm.css',
        [],
        $css_version
    );

    wp_enqueue_script(
        'mhm-admin-script',
        plugin_dir_url(__FILE__) . 'mhm.js',
        [],
        $js_version,
        true
    );
}

add_action(
    'admin_enqueue_scripts',
    'mhm_admin_assets'
);




/*
|--------------------------------------------------------------------------
| HESAP DETAYLARI YERİNE YALNIZCA ŞİFRE DEĞİŞTİRME
|--------------------------------------------------------------------------
*/

function mhm_replace_edit_account_content() {

    if (!is_user_logged_in()) {
        return;
    }

    remove_action(
        'woocommerce_account_edit-account_endpoint',
        'woocommerce_account_edit_account',
        10
    );

    add_action(
        'woocommerce_account_edit-account_endpoint',
        'mhm_render_password_change_form',
        10
    );
}

add_action(
    'wp',
    'mhm_replace_edit_account_content',
    20
);


/*
|--------------------------------------------------------------------------
| ŞİFRE DEĞİŞTİRME FORMU
|--------------------------------------------------------------------------
*/

function mhm_render_password_change_form() {

    $status = isset($_GET['mhm_password_status'])
        ? sanitize_key(wp_unslash($_GET['mhm_password_status']))
        : '';

    $messages = [
        'success' => [
            'class'   => 'mhm-message-success',
            'message' => 'Şifreniz başarıyla değiştirildi.',
        ],
        'empty' => [
            'class'   => 'mhm-message-error',
            'message' => 'Lütfen tüm alanları doldurun.',
        ],
        'wrong_current' => [
            'class'   => 'mhm-message-error',
            'message' => 'Mevcut şifreniz doğru değil.',
        ],
        'mismatch' => [
            'class'   => 'mhm-message-error',
            'message' => 'Yeni şifre ve şifre onayı eşleşmiyor.',
        ],
        'short' => [
            'class'   => 'mhm-message-error',
            'message' => 'Yeni şifreniz en az 8 karakter olmalıdır.',
        ],
        'same' => [
            'class'   => 'mhm-message-error',
            'message' => 'Yeni şifreniz mevcut şifrenizle aynı olamaz.',
        ],
        'invalid' => [
            'class'   => 'mhm-message-error',
            'message' => 'İşlem doğrulanamadı. Lütfen tekrar deneyin.',
        ],
    ];
    ?>

    <div class="mhm-password-page">

        <div class="mhm-password-info">
            <h2>Şifre Değiştirme</h2>

<div class="mhm-password-description">
    Bu ekrandan yalnızca hesabınızın giriş şifresini değiştirebilirsiniz. Ad, soyad, görünen ad ve e-posta bilgileri Magazify yönetimi tarafından güncellenir.
</div>
        </div>

        <?php if ($status !== '' && isset($messages[$status])) : ?>
            <div
                class="mhm-message <?php echo esc_attr($messages[$status]['class']); ?>"
                role="status"
            >
                <?php echo esc_html($messages[$status]['message']); ?>
            </div>
        <?php endif; ?>

        <form
            method="post"
            action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
            class="mhm-password-form"
        >
            <input
                type="hidden"
                name="action"
                value="mhm_change_password"
            >

            <?php
            wp_nonce_field(
                'mhm_change_password',
                'mhm_password_nonce'
            );
            ?>

            <div class="mhm-password-field">
                <label for="mhm_current_password">
                    Mevcut şifre
                </label>

                <div class="mhm-password-control">
                    <input
                        id="mhm_current_password"
                        type="password"
                        name="current_password"
                        autocomplete="current-password"
                        required
                    >

                    <button
                        type="button"
                        class="mhm-toggle-password"
                        data-target="mhm_current_password"
                    >
                        Göster
                    </button>
                </div>
            </div>

            <div class="mhm-password-field">
                <label for="mhm_new_password">
                    Yeni şifre
                </label>

                <div class="mhm-password-control">
                    <input
                        id="mhm_new_password"
                        type="password"
                        name="new_password"
                        autocomplete="new-password"
                        minlength="8"
                        required
                    >

                    <button
                        type="button"
                        class="mhm-toggle-password"
                        data-target="mhm_new_password"
                    >
                        Göster
                    </button>
                </div>

                <small>En az 8 karakter kullanın.</small>
            </div>

            <div class="mhm-password-field">
                <label for="mhm_confirm_password">
                    Yeni şifreyi onayla
                </label>

                <div class="mhm-password-control">
                    <input
                        id="mhm_confirm_password"
                        type="password"
                        name="confirm_password"
                        autocomplete="new-password"
                        minlength="8"
                        required
                    >

                    <button
                        type="button"
                        class="mhm-toggle-password"
                        data-target="mhm_confirm_password"
                    >
                        Göster
                    </button>
                </div>
            </div>

            <button
                type="submit"
                class="mhm-password-submit"
            >
                Şifreyi Güncelle
            </button>
        </form>

    </div>

    <?php
}


/*
|--------------------------------------------------------------------------
| ŞİFRE DEĞİŞTİRME İŞLEMİ
|--------------------------------------------------------------------------
*/

function mhm_change_password() {

    if (!is_user_logged_in()) {
        wp_die('Bu işlemi yapabilmek için giriş yapmalısınız.');
    }

    if (
        !isset($_POST['mhm_password_nonce']) ||
        !wp_verify_nonce(
            sanitize_text_field(
                wp_unslash($_POST['mhm_password_nonce'])
            ),
            'mhm_change_password'
        )
    ) {
        mhm_password_redirect('invalid');
    }

    $current_password = isset($_POST['current_password'])
        ? (string) wp_unslash($_POST['current_password'])
        : '';

    $new_password = isset($_POST['new_password'])
        ? (string) wp_unslash($_POST['new_password'])
        : '';

    $confirm_password = isset($_POST['confirm_password'])
        ? (string) wp_unslash($_POST['confirm_password'])
        : '';

    if (
        $current_password === '' ||
        $new_password === '' ||
        $confirm_password === ''
    ) {
        mhm_password_redirect('empty');
    }

    $user = wp_get_current_user();

    if (
        !$user->exists() ||
        !wp_check_password(
            $current_password,
            $user->user_pass,
            $user->ID
        )
    ) {
        mhm_password_redirect('wrong_current');
    }

    if ($new_password !== $confirm_password) {
        mhm_password_redirect('mismatch');
    }

    if (strlen($new_password) < 8) {
        mhm_password_redirect('short');
    }

    if (
        wp_check_password(
            $new_password,
            $user->user_pass,
            $user->ID
        )
    ) {
        mhm_password_redirect('same');
    }

    wp_set_password(
        $new_password,
        $user->ID
    );

    /*
     * Parola değişiminden sonra kullanıcıyı oturumda tutar.
     */
    wp_set_current_user($user->ID);

    wp_set_auth_cookie(
        $user->ID,
        true,
        is_ssl()
    );

    clean_user_cache($user->ID);

    mhm_password_redirect('success');
}

add_action(
    'admin_post_mhm_change_password',
    'mhm_change_password'
);


/*
|--------------------------------------------------------------------------
| ŞİFRE EKRANINA YÖNLENDİRME
|--------------------------------------------------------------------------
*/

function mhm_password_redirect($status) {

    $url = function_exists('wc_get_account_endpoint_url')
        ? wc_get_account_endpoint_url('edit-account')
        : home_url('/');

    $url = add_query_arg(
        'mhm_password_status',
        sanitize_key($status),
        $url
    );

    wp_safe_redirect($url);
    exit;
}




/*
|--------------------------------------------------------------------------
| TASARIM YÜKLE — HESAP MERKEZİ ENTEGRASYONU
|--------------------------------------------------------------------------
*/

if (!defined('MHM_DESIGN_MAX_FILE_SIZE')) {
    define('MHM_DESIGN_MAX_FILE_SIZE', 15 * 1024 * 1024);
}

if (!defined('MHM_DESIGN_WIDTH')) {
    define('MHM_DESIGN_WIDTH', 900);
}

if (!defined('MHM_DESIGN_HEIGHT')) {
    define('MHM_DESIGN_HEIGHT', 1350);
}

if (!defined('MHM_DESIGN_DPI')) {
    define('MHM_DESIGN_DPI', 300);
}


/*
|--------------------------------------------------------------------------
| TASARIM KRİTERLERİ — DİNAMİK AYARLAR
|--------------------------------------------------------------------------
|
| Sabitler eski kurulumlarla geriye dönük uyumluluk için korunur.
| Gerçek yükleme doğrulaması admin panelinden yönetilen değerleri kullanır.
|
*/

function mhm_design_criteria() {

    $settings = mhm_content_settings();

    return [
        'max_file_mb' => max(
            1,
            absint(
                isset($settings['design_max_file_mb'])
                    ? $settings['design_max_file_mb']
                    : 15
            )
        ),
        'width' => max(
            1,
            absint(
                isset($settings['design_width'])
                    ? $settings['design_width']
                    : MHM_DESIGN_WIDTH
            )
        ),
        'height' => max(
            1,
            absint(
                isset($settings['design_height'])
                    ? $settings['design_height']
                    : MHM_DESIGN_HEIGHT
            )
        ),
        'dpi' => max(
            1,
            absint(
                isset($settings['design_dpi'])
                    ? $settings['design_dpi']
                    : MHM_DESIGN_DPI
            )
        ),
    ];
}


function mhm_design_rules_text() {

    $criteria = mhm_design_criteria();

    return sprintf(
        'PNG formatında, %1$d × %2$d px ölçülerinde, %3$d DPI ve maksimum %4$d MB boyutunda tasarımlar yükleyin.',
        $criteria['width'],
        $criteria['height'],
        $criteria['dpi'],
        $criteria['max_file_mb']
    );
}


function mhm_get_uploaded_designs($user_id = 0, $admin_all = false) {

    $meta_query = [
        [
            'key'     => '_mhm_design_upload',
            'value'   => '1',
            'compare' => '=',
        ],
    ];

    if (!$admin_all) {

        $user_id = $user_id
            ? absint($user_id)
            : get_current_user_id();

        $meta_query[] = [
            'key'     => '_mhm_design_owner',
            'value'   => $user_id,
            'compare' => '=',
            'type'    => 'NUMERIC',
        ];
    }

    return get_posts([
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'meta_query'     => $meta_query,
    ]);
}


function mhm_read_png_dpi($file_path) {

    if (!is_readable($file_path)) {
        return null;
    }

    $fp = fopen($file_path, 'rb');

    if (!$fp) {
        return null;
    }

    if (fread($fp, 8) !== "\x89PNG\r\n\x1a\n") {
        fclose($fp);
        return null;
    }

    $dpi = null;

    while (!feof($fp)) {

        $length_data = fread($fp, 4);

        if (strlen($length_data) !== 4) {
            break;
        }

        $length = unpack('N', $length_data)[1];
        $type   = fread($fp, 4);

        if (strlen($type) !== 4) {
            break;
        }

        $data = $length > 0
            ? fread($fp, $length)
            : '';

        fread($fp, 4); // CRC

        if ($type === 'pHYs' && strlen($data) >= 9) {

            $values = unpack(
                'Nx/Ny/Cunit',
                substr($data, 0, 9)
            );

            if (
                isset($values['unit']) &&
                (int) $values['unit'] === 1 &&
                !empty($values['x']) &&
                !empty($values['y'])
            ) {
                $dpi = [
                    'x' => round($values['x'] * 0.0254),
                    'y' => round($values['y'] * 0.0254),
                ];
            }

            break;
        }

        if ($type === 'IEND') {
            break;
        }
    }

    fclose($fp);

    return $dpi;
}


function mhm_render_design_upload_page() {

    if (
        !is_user_logged_in() ||
        !current_user_can('manage_woocommerce')
    ) {
        return '<p>Bu alanı görüntüleme yetkiniz bulunmuyor.</p>';
    }

    $settings = mhm_content_settings();
    $designs  = mhm_get_uploaded_designs(
        get_current_user_id(),
        false
    );

    $status = isset($_GET['mhm_design'])
        ? sanitize_key(wp_unslash($_GET['mhm_design']))
        : '';

    ob_start();
    ?>

    <div class="mhm-design-page">

        <div class="mhm-design-header">

            <h2><?php
                echo esc_html($settings['design_upload_title']);
            ?></h2>

            <div class="mhm-design-intro"><?php
                echo esc_html($settings['design_upload_intro']);
            ?></div>

        </div>

        <?php
        $criteria = mhm_design_criteria();

        $messages = [
            'success'        => ['success', 'Tasarımınız başarıyla yüklendi.'],
            'deleted'        => ['success', 'Tasarım silindi.'],
            'invalid_file'   => ['error', 'Lütfen geçerli bir PNG dosyası seçin.'],
            'file_too_large' => [
                'error',
                sprintf(
                    'Tasarım dosyası en fazla %d MB olabilir.',
                    $criteria['max_file_mb']
                ),
            ],
            'invalid_size'   => [
                'error',
                sprintf(
                    'Tasarım dosyası tam olarak %1$d × %2$d piksel olmalıdır.',
                    $criteria['width'],
                    $criteria['height']
                ),
            ],
            'invalid_dpi'    => [
                'error',
                sprintf(
                    'Tasarım dosyasının çözünürlüğü %d DPI olmalıdır.',
                    $criteria['dpi']
                ),
            ],
            'upload_error'   => ['error', 'Tasarım yüklenemedi. Lütfen tekrar deneyin.'],
            'delete_error'   => ['error', 'Tasarım silinemedi.'],
            'invalid_request'=> ['error', 'İşlem doğrulanamadı. Lütfen tekrar deneyin.'],
        ];

        if ($status !== '' && isset($messages[$status])) {

            $message_data = $messages[$status];

            echo '<div class="mhm-design-message mhm-design-message-'
                . esc_attr($message_data[0])
                . '">'
                . esc_html($message_data[1])
                . '</div>';
        }
        ?>

        <form
            method="post"
            action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
            enctype="multipart/form-data"
            class="mhm-design-upload-form"
        >

            <input
                type="hidden"
                name="action"
                value="mhm_upload_design"
            >

            <?php
            wp_nonce_field(
                'mhm_upload_design',
                'mhm_design_nonce'
            );
            ?>

            <div class="mhm-design-rules"><?php
                echo esc_html(mhm_design_rules_text());
            ?></div>

            <div class="mhm-design-upload-row">

                <div class="mhm-design-file-wrap">

                    <label for="mhm_design_file">
                        Tasarım Dosyası
                    </label>

                    <div class="mhm-design-file-control">
                        <input
                            id="mhm_design_file"
                            type="file"
                            name="design_file"
                            accept=".png,image/png"
                            required
                        >
                        <label class="mhm-design-file-button" for="mhm_design_file">Dosya Seç</label>
                        <span
                            class="mhm-design-file-text"
                            data-empty-text="Henüz dosya seçilmedi"
                        >Henüz dosya seçilmedi</span>
                    </div>

                </div>

                <button
                    type="submit"
                    class="mhm-design-upload-submit"
                >
                    <?php
                    echo esc_html(
                        $settings['design_upload_button']
                    );
                    ?>
                </button>

            </div>

        </form>

        <div class="mhm-design-gallery-section">

            <h3>Yüklediğiniz Tasarımlar</h3>

            <?php
            echo mhm_render_design_gallery(
                $designs,
                false
            );
            ?>

        </div>

    </div>

    <?php

    return ob_get_clean();
}


function mhm_render_design_gallery($designs, $is_admin = false) {

    if (empty($designs)) {
        return '<div class="mhm-design-empty">Henüz yüklenmiş tasarım bulunmuyor.</div>';
    }

    ob_start();
    ?>

    <div class="mhm-design-grid">

        <?php foreach ($designs as $design) : ?>

            <?php
            $attachment_id = (int) $design->ID;

            $owner_id = (int) get_post_meta(
                $attachment_id,
                '_mhm_design_owner',
                true
            );

            $owner = $owner_id
                ? get_userdata($owner_id)
                : false;

            $image_url = wp_get_attachment_image_url(
                $attachment_id,
                'medium'
            );

            $full_image_url = wp_get_attachment_url(
                $attachment_id
            );

            if (!$image_url) {
                $image_url = $full_image_url;
            }

            if (!$full_image_url) {
                $full_image_url = $image_url;
            }
            ?>

            <article class="mhm-design-card">

                <button
                    type="button"
                    class="mhm-design-preview mhm-design-lightbox-trigger"
                    data-full-image="<?php
                    echo esc_url($full_image_url);
                    ?>"
                    aria-label="<?php
                    echo esc_attr(
                        get_the_title($attachment_id)
                        . ' tasarımını büyüt'
                    );
                    ?>"
                >

                    <img
                        src="<?php echo esc_url($image_url); ?>"
                        alt="<?php
                        echo esc_attr(
                            get_the_title($attachment_id)
                        );
                        ?>"
                        loading="lazy"
                    >

                </button>

                <?php if ($is_admin) : ?>

                    <div class="mhm-design-owner">
                        <?php
                        echo esc_html(
                            $owner
                                ? $owner->display_name
                                : 'Bilinmeyen Kullanıcı'
                        );
                        ?>
                    </div>

                <?php endif; ?>

                <div class="mhm-design-card-actions">

                    <form
                        method="post"
                        action="<?php
                        echo esc_url(
                            admin_url('admin-post.php')
                        );
                        ?>"
                        onsubmit="return window.confirm('Bu tasarımı silmek istediğinize emin misiniz?');"
                    >

                        <input
                            type="hidden"
                            name="action"
                            value="mhm_delete_design"
                        >

                        <input
                            type="hidden"
                            name="attachment_id"
                            value="<?php
                            echo esc_attr($attachment_id);
                            ?>"
                        >

                        <input
                            type="hidden"
                            name="from_admin"
                            value="<?php
                            echo $is_admin ? '1' : '0';
                            ?>"
                        >

                        <?php
                        wp_nonce_field(
                            'mhm_delete_design_' . $attachment_id,
                            'mhm_design_delete_nonce'
                        );
                        ?>

                        <button
                            type="submit"
                            class="mhm-design-delete"
                        >
                            Sil
                        </button>

                    </form>

                </div>

            </article>

        <?php endforeach; ?>

    </div>

    <div
        class="mhm-design-lightbox"
        hidden
        aria-hidden="true"
    >

        <button
            type="button"
            class="mhm-design-lightbox-close"
            aria-label="Tasarımı kapat"
        >
            ×
        </button>

        <img
            class="mhm-design-lightbox-image"
            src=""
            alt=""
        >

    </div>

    <?php

    return ob_get_clean();
}


function mhm_handle_design_upload() {

    if (
        !is_user_logged_in() ||
        !current_user_can('manage_woocommerce')
    ) {
        wp_die('Bu işlemi yapma yetkiniz bulunmuyor.');
    }

    if (
        !isset($_POST['mhm_design_nonce']) ||
        !wp_verify_nonce(
            sanitize_text_field(
                wp_unslash($_POST['mhm_design_nonce'])
            ),
            'mhm_upload_design'
        )
    ) {
        mhm_design_redirect('invalid_request');
    }

    if (
        !isset($_FILES['design_file']) ||
        empty($_FILES['design_file']['name']) ||
        (int) $_FILES['design_file']['error'] !== UPLOAD_ERR_OK
    ) {
        mhm_design_redirect('invalid_file');
    }

    $file = $_FILES['design_file'];
    $criteria = mhm_design_criteria();

    $max_file_bytes =
        $criteria['max_file_mb'] * 1024 * 1024;

    if ((int) $file['size'] > $max_file_bytes) {
        mhm_design_redirect('file_too_large');
    }

    $file_check = wp_check_filetype_and_ext(
        $file['tmp_name'],
        $file['name'],
        [
            'png' => 'image/png',
        ]
    );

    if (
        empty($file_check['type']) ||
        $file_check['type'] !== 'image/png'
    ) {
        mhm_design_redirect('invalid_file');
    }

    $image_size = @getimagesize(
        $file['tmp_name']
    );

    if (
        !$image_size ||
        (int) $image_size[0] !== $criteria['width'] ||
        (int) $image_size[1] !== $criteria['height']
    ) {
        mhm_design_redirect('invalid_size');
    }

    /*
     * PNG pHYs metadata varsa DPI doğrulanır.
     * Metadata yoksa doğru PNG ve piksel ölçüsü kabul edilir.
     */
    $dpi = mhm_read_png_dpi(
        $file['tmp_name']
    );

    if (
        is_array($dpi) &&
        (
            abs((int) $dpi['x'] - $criteria['dpi']) > 2 ||
            abs((int) $dpi['y'] - $criteria['dpi']) > 2
        )
    ) {
        mhm_design_redirect('invalid_dpi');
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $attachment_id = media_handle_upload(
        'design_file',
        0,
        [],
        [
            'test_form' => false,
            'mimes'     => [
                'png' => 'image/png',
            ],
        ]
    );

    if (is_wp_error($attachment_id)) {
        mhm_design_redirect('upload_error');
    }

    update_post_meta(
        $attachment_id,
        '_mhm_design_upload',
        '1'
    );

    update_post_meta(
        $attachment_id,
        '_mhm_design_owner',
        get_current_user_id()
    );

    update_post_meta(
        $attachment_id,
        '_mhm_design_width',
        $criteria['width']
    );

    update_post_meta(
        $attachment_id,
        '_mhm_design_height',
        $criteria['height']
    );

    if (is_array($dpi)) {

        update_post_meta(
            $attachment_id,
            '_mhm_design_dpi_x',
            (int) $dpi['x']
        );

        update_post_meta(
            $attachment_id,
            '_mhm_design_dpi_y',
            (int) $dpi['y']
        );
    }

    mhm_design_redirect('success');
}

add_action(
    'admin_post_mhm_upload_design',
    'mhm_handle_design_upload'
);


function mhm_handle_design_delete() {

    if (!is_user_logged_in()) {
        wp_die('Bu işlemi yapma yetkiniz bulunmuyor.');
    }

    $attachment_id = isset($_POST['attachment_id'])
        ? absint(
            wp_unslash($_POST['attachment_id'])
        )
        : 0;

    if (!$attachment_id) {
        mhm_design_redirect('delete_error');
    }

    if (
        !isset($_POST['mhm_design_delete_nonce']) ||
        !wp_verify_nonce(
            sanitize_text_field(
                wp_unslash(
                    $_POST['mhm_design_delete_nonce']
                )
            ),
            'mhm_delete_design_' . $attachment_id
        )
    ) {
        mhm_design_redirect('invalid_request');
    }

    $is_design = get_post_meta(
        $attachment_id,
        '_mhm_design_upload',
        true
    );

    $owner_id = (int) get_post_meta(
        $attachment_id,
        '_mhm_design_owner',
        true
    );

    $from_admin =
        isset($_POST['from_admin']) &&
        sanitize_key(
            wp_unslash($_POST['from_admin'])
        ) === '1';

    $can_delete = false;

    if (
        $from_admin &&
        current_user_can('manage_woocommerce')
    ) {
        $can_delete = true;
    } elseif (
        current_user_can('manage_woocommerce') &&
        $owner_id === get_current_user_id()
    ) {
        $can_delete = true;
    }

    if (
        $is_design !== '1' ||
        !$can_delete
    ) {
        wp_die('Bu tasarımı silme yetkiniz bulunmuyor.');
    }

    $deleted = wp_delete_attachment(
        $attachment_id,
        true
    );

    if (!$deleted) {

        if ($from_admin) {

            wp_safe_redirect(
                add_query_arg(
                    [
                        'page'       => 'mhm-design-upload-settings',
                        'mhm_design' => 'delete_error',
                    ],
                    admin_url('admin.php')
                )
            );

            exit;
        }

        mhm_design_redirect('delete_error');
    }

    if ($from_admin) {

        wp_safe_redirect(
            add_query_arg(
                [
                    'page'        => 'mhm-design-upload-settings',
                    'mhm_updated' => '1',
                ],
                admin_url('admin.php')
            )
        );

        exit;
    }

    mhm_design_redirect('deleted');
}

add_action(
    'admin_post_mhm_delete_design',
    'mhm_handle_design_delete'
);


function mhm_design_redirect($status) {

    $url = function_exists(
        'wc_get_account_endpoint_url'
    )
        ? wc_get_account_endpoint_url('tasarim-yukle')
        : home_url('/');

    $url = add_query_arg(
        'mhm_design',
        sanitize_key($status),
        $url
    );

    wp_safe_redirect($url);
    exit;
}


/*
|--------------------------------------------------------------------------
| TEKNİK DESTEK — HESAP MERKEZİ ENTEGRASYONU
|--------------------------------------------------------------------------
|
| Destek talepleri WordPress veritabanında saklanmaz.
| Form verileri yalnızca Hesap Merkezi yönetim panelinde belirlenen
| destek e-posta adresine gönderilir.
|
*/

if (!defined('MHM_SUPPORT_MAX_FILE_SIZE')) {
    define('MHM_SUPPORT_MAX_FILE_SIZE', 5 * 1024 * 1024);
}


/*
|--------------------------------------------------------------------------
| DESTEK AYARLARI
|--------------------------------------------------------------------------
*/

function mhm_support_settings() {

    $settings = mhm_content_settings();

    return [
        'email' => isset($settings['support_email'])
            ? sanitize_email($settings['support_email'])
            : sanitize_email(get_option('admin_email')),

        'categories' => isset($settings['support_categories'])
            ? (string) $settings['support_categories']
            : '',

        'intro' => isset($settings['support_intro'])
            ? (string) $settings['support_intro']
            : '',

        'file_help' => isset($settings['support_file_help'])
            ? (string) $settings['support_file_help']
            : '',

        'account_note' => isset($settings['support_account_note'])
            ? (string) $settings['support_account_note']
            : '',
    ];
}


function mhm_support_categories() {

    $settings = mhm_support_settings();

    $lines = preg_split(
        '/\r\n|\r|\n/',
        (string) $settings['categories']
    );

    $categories = [];

    foreach ((array) $lines as $line) {

        $category = sanitize_text_field($line);

        if ($category === '') {
            continue;
        }

        if (!in_array($category, $categories, true)) {
            $categories[] = $category;
        }
    }

    if (empty($categories)) {
        $categories = [
            'Genel Destek',
            'Ürün Oluşturma',
            'Ürün Yönetimi',
            'Siparişler',
            'Hesap Hareketleri',
            'Mağaza Güncelleme',
            'Hesap Bilgileri',
            'Teknik Sorun',
            'Diğer',
        ];
    }

    return $categories;
}


/*
|--------------------------------------------------------------------------
| DESTEK SAYFASI
|--------------------------------------------------------------------------
*/

function mhm_render_support_page() {

    if (
        !is_user_logged_in() ||
        !current_user_can('manage_woocommerce')
    ) {
        return '<p>Bu alanı görüntüleme yetkiniz bulunmuyor.</p>';
    }

    $categories       = mhm_support_categories();
    $support_settings = mhm_support_settings();

    $status = isset($_GET['mhm_support'])
        ? sanitize_key(
            wp_unslash($_GET['mhm_support'])
        )
        : '';

    ob_start();
    ?>

    <div class="mhm-support-page">

        <div class="mhm-support-header">

            <h2>Teknik Destek</h2>

            <div class="mhm-support-intro-text"><?php
                echo esc_html(
                    $support_settings['intro']
                );
            ?></div>

        </div>

        <?php if ($status === 'success') : ?>

            <div
                class="mhm-support-message mhm-support-message-success"
                role="status"
            >
                Destek talebiniz başarıyla gönderildi.
            </div>

        <?php elseif ($status !== '') : ?>

            <?php
            $messages = [
                'invalid_request' => 'İşlem doğrulanamadı. Lütfen tekrar deneyin.',
                'invalid_category' => 'Lütfen geçerli bir destek kategorisi seçin.',
                'missing_subject' => 'Lütfen destek talebiniz için bir konu girin.',
                'missing_message' => 'Lütfen destek talebinizi açıklayın.',
                'invalid_file' => 'Ek dosya JPG, PNG, WEBP veya PDF formatında ve en fazla 5 MB olmalıdır.',
                'mail_error' => 'Destek talebiniz gönderilemedi. Lütfen daha sonra tekrar deneyin.',
            ];

            $message = isset($messages[$status])
                ? $messages[$status]
                : 'İşlem sırasında bir hata oluştu.';
            ?>

            <div
                class="mhm-support-message mhm-support-message-error"
                role="alert"
            >
                <?php echo esc_html($message); ?>
            </div>

        <?php endif; ?>

        <form
            method="post"
            action="<?php
            echo esc_url(
                admin_url('admin-post.php')
            );
            ?>"
            enctype="multipart/form-data"
            class="mhm-support-form"
        >

            <input
                type="hidden"
                name="action"
                value="mhm_submit_support_request"
            >

            <?php
            wp_nonce_field(
                'mhm_submit_support_request',
                'mhm_support_nonce'
            );
            ?>

            <div class="mhm-support-field">

                <label for="mhm_support_category">
                    Destek Konusu
                </label>

                <select
                    id="mhm_support_category"
                    name="support_category"
                    required
                >
                    <?php foreach ($categories as $category) : ?>

                        <option
                            value="<?php echo esc_attr($category); ?>"
                        >
                            <?php echo esc_html($category); ?>
                        </option>

                    <?php endforeach; ?>
                </select>

            </div>

            <div class="mhm-support-field">

                <label for="mhm_support_subject">
                    Konu
                </label>

                <input
                    id="mhm_support_subject"
                    type="text"
                    name="support_subject"
                    maxlength="180"
                    autocomplete="off"
                    required
                >

            </div>

            <div class="mhm-support-field">

                <label for="mhm_support_message">
                    Açıklama
                </label>

                <textarea
                    id="mhm_support_message"
                    name="support_message"
                    rows="8"
                    required
                ></textarea>

            </div>

            <div class="mhm-support-field">

                <label for="mhm_support_file">
                    Dosya Ekle
                    <span class="mhm-support-optional">
                        (Opsiyonel)
                    </span>
                </label>

                <input
                    id="mhm_support_file"
                    type="file"
                    name="support_file"
                    accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf"
                >

                <div
                    class="mhm-support-file-name"
                    data-empty-text="Henüz dosya seçilmedi."
                >
                    Henüz dosya seçilmedi.
                </div>

                <div class="mhm-support-file-help"><?php
                    echo esc_html(
                        $support_settings['file_help']
                    );
                ?></div>

            </div>

            <div class="mhm-support-form-note"><?php
                echo esc_html(
                    $support_settings['account_note']
                );
            ?></div>

            <button
                type="submit"
                class="mhm-support-submit"
            >
                Destek Talebi Gönder
            </button>

        </form>

    </div>

    <?php

    return ob_get_clean();
}


/*
|--------------------------------------------------------------------------
| DESTEK TALEBİ GÖNDER
|--------------------------------------------------------------------------
*/

function mhm_handle_support_request() {

    if (
        !is_user_logged_in() ||
        !current_user_can('manage_woocommerce')
    ) {
        wp_die('Bu işlemi yapma yetkiniz bulunmuyor.');
    }

    if (
        !isset($_POST['mhm_support_nonce']) ||
        !wp_verify_nonce(
            sanitize_text_field(
                wp_unslash(
                    $_POST['mhm_support_nonce']
                )
            ),
            'mhm_submit_support_request'
        )
    ) {
        mhm_support_redirect('invalid_request');
    }

    $category = isset($_POST['support_category'])
        ? sanitize_text_field(
            wp_unslash(
                $_POST['support_category']
            )
        )
        : '';

    $subject = isset($_POST['support_subject'])
        ? sanitize_text_field(
            wp_unslash(
                $_POST['support_subject']
            )
        )
        : '';

    $message = isset($_POST['support_message'])
        ? sanitize_textarea_field(
            wp_unslash(
                $_POST['support_message']
            )
        )
        : '';

    if (
        $category === '' ||
        !in_array(
            $category,
            mhm_support_categories(),
            true
        )
    ) {
        mhm_support_redirect('invalid_category');
    }

    if ($subject === '') {
        mhm_support_redirect('missing_subject');
    }

    if ($message === '') {
        mhm_support_redirect('missing_message');
    }

    $support_settings = mhm_support_settings();
    $support_email    = $support_settings['email'];

    if ($support_email === '') {
        $support_email = sanitize_email(
            get_option('admin_email')
        );
    }

    $user = wp_get_current_user();

    $mail_subject = sprintf(
        '[Magazify Destek] %s - %s',
        $category,
        $subject
    );

    $body  = "Yeni Magazify Destek Talebi\n\n";
    $body .= "Kategori: " . $category . "\n";
    $body .= "Konu: " . $subject . "\n\n";
    $body .= "Mağaza / Site: " . get_bloginfo('name') . "\n";
    $body .= "Site Adresi: " . home_url('/') . "\n";
    $body .= "Kullanıcı: " . $user->display_name . "\n";
    $body .= "E-posta: " . $user->user_email . "\n";
    $body .= "Kullanıcı ID: " . (int) $user->ID . "\n";
    $body .= "Tarih: " . wp_date('d.m.Y H:i') . "\n\n";
    $body .= "Açıklama:\n";
    $body .= $message . "\n";

    $headers = [];

    if (is_email($user->user_email)) {

        $reply_name = sanitize_text_field(
            $user->display_name
        );

        $headers[] = sprintf(
            'Reply-To: %s <%s>',
            $reply_name !== ''
                ? $reply_name
                : 'Magazify Mağaza Ortağı',
            sanitize_email($user->user_email)
        );
    }

    $attachments = [];

    if (
        isset($_FILES['support_file']) &&
        !empty($_FILES['support_file']['name'])
    ) {

        $file = $_FILES['support_file'];

        if (
            (int) $file['error'] !== UPLOAD_ERR_OK ||
            (int) $file['size'] > MHM_SUPPORT_MAX_FILE_SIZE
        ) {
            mhm_support_redirect('invalid_file');
        }

        $allowed_mimes = [
            'jpg|jpeg|jpe' => 'image/jpeg',
            'png'          => 'image/png',
            'webp'         => 'image/webp',
            'pdf'          => 'application/pdf',
        ];

        $file_check = wp_check_filetype_and_ext(
            $file['tmp_name'],
            $file['name'],
            $allowed_mimes
        );

        if (
            empty($file_check['type']) ||
            !in_array(
                $file_check['type'],
                array_values($allowed_mimes),
                true
            )
        ) {
            mhm_support_redirect('invalid_file');
        }

        /*
         * Dosya Medya Kütüphanesine veya uploads klasörüne yazılmaz.
         * PHP'nin geçici yükleme dosyası doğrudan wp_mail() eki olur.
         */
        $attachments[] = $file['tmp_name'];

        $body .= "\nEk Dosya: "
            . sanitize_file_name($file['name'])
            . "\n";
    }

    $sent = wp_mail(
        $support_email,
        $mail_subject,
        $body,
        $headers,
        $attachments
    );

    mhm_support_redirect(
        $sent
            ? 'success'
            : 'mail_error'
    );
}

add_action(
    'admin_post_mhm_submit_support_request',
    'mhm_handle_support_request'
);


/*
|--------------------------------------------------------------------------
| DESTEK FORMU SONRASI YÖNLENDİRME
|--------------------------------------------------------------------------
*/

function mhm_support_redirect($status) {

    $url = function_exists(
        'wc_get_account_endpoint_url'
    )
        ? wc_get_account_endpoint_url('destek')
        : home_url('/');

    $url = add_query_arg(
        'mhm_support',
        sanitize_key($status),
        $url
    );

    wp_safe_redirect($url);
    exit;
}


/*
|--------------------------------------------------------------------------
| HESAP BİLGİLERİ — HESAP MERKEZİ ENTEGRASYONU
|--------------------------------------------------------------------------
|
| Eski Magazify Hesap Bilgileri eklentisinin veri yapısı korunur.
| Mevcut user_meta anahtarları değiştirilmez; kayıtlı bilgiler aynen okunur.
|
*/

if (!defined('MHM_HB_MAX_FILE_SIZE')) {
    define('MHM_HB_MAX_FILE_SIZE', 5 * 1024 * 1024);
}


function mhm_hb_admin_menu() {
    add_submenu_page(
        'mhm-content-management',
        'Hesap Bilgileri',
        'Hesap Bilgileri',
        'manage_woocommerce',
        'hesap-bilgileri',
        'mhm_hb_admin_page'
    );
}
add_action('admin_menu', 'mhm_hb_admin_menu', 30);



/*
|--------------------------------------------------------------------------
| TASARIM YÜKLE AYARLARI — YÖNETİM ALT MENÜSÜ
|--------------------------------------------------------------------------
*/

function mhm_design_upload_admin_menu() {

    add_submenu_page(
        'mhm-content-management',
        'Tasarım Yükle Ayarları',
        'Tasarım Yükle Ayarları',
        'manage_woocommerce',
        'mhm-design-upload-settings',
        'mhm_design_upload_admin_page'
    );
}

add_action(
    'admin_menu',
    'mhm_design_upload_admin_menu',
    31
);


function mhm_save_design_upload_settings() {

    if (!current_user_can('manage_woocommerce')) {
        wp_die('Bu işlemi yapma yetkiniz bulunmuyor.');
    }

    check_admin_referer(
        'mhm_save_design_upload_settings',
        'mhm_design_upload_settings_nonce'
    );

    $settings = mhm_content_settings();

    $settings['design_upload_title'] = isset($_POST['design_upload_title'])
        ? sanitize_text_field(wp_unslash($_POST['design_upload_title']))
        : '';

    $settings['design_upload_intro'] = isset($_POST['design_upload_intro'])
        ? sanitize_textarea_field(wp_unslash($_POST['design_upload_intro']))
        : '';

    $settings['design_upload_rules'] = isset($_POST['design_upload_rules'])
        ? sanitize_textarea_field(wp_unslash($_POST['design_upload_rules']))
        : '';

    $settings['design_upload_button'] = isset($_POST['design_upload_button'])
        ? sanitize_text_field(wp_unslash($_POST['design_upload_button']))
        : '';

    $settings['design_max_file_mb'] = isset($_POST['design_max_file_mb'])
        ? max(1, absint(wp_unslash($_POST['design_max_file_mb'])))
        : 15;

    $settings['design_width'] = isset($_POST['design_width'])
        ? max(1, absint(wp_unslash($_POST['design_width'])))
        : 900;

    $settings['design_height'] = isset($_POST['design_height'])
        ? max(1, absint(wp_unslash($_POST['design_height'])))
        : 1350;

    $settings['design_dpi'] = isset($_POST['design_dpi'])
        ? max(1, absint(wp_unslash($_POST['design_dpi'])))
        : 300;

    /*
     * Dosya kuralları metni artık kriterlerden otomatik üretilir.
     * Eski serbest metin alanı veri kaybı olmaması için option içinde
     * korunabilir, fakat doğrulamada kullanılmaz.
     */
    $settings['design_upload_rules'] = '';

    update_option(
        'mhm_content_settings',
        $settings,
        false
    );

    wp_safe_redirect(
        add_query_arg(
            [
                'page'        => 'mhm-design-upload-settings',
                'mhm_updated' => '1',
            ],
            admin_url('admin.php')
        )
    );

    exit;
}

add_action(
    'admin_post_mhm_save_design_upload_settings',
    'mhm_save_design_upload_settings'
);


function mhm_design_upload_admin_page() {

    if (!current_user_can('manage_woocommerce')) {
        wp_die('Bu alanı görüntüleme yetkiniz bulunmuyor.');
    }

    $settings = mhm_content_settings();
    $designs  = mhm_get_uploaded_designs(0, true);
    ?>

    <div class="wrap mhm-admin-page mhm-design-admin-page">

        <h1>Tasarım Yükle Ayarları</h1>

        <p class="mhm-admin-description">
            Tasarım Yükle sayfasındaki başlık, açıklama, yükleme kriterleri
            ve yükleme butonu metnini buradan yönetebilirsiniz. Belirlediğiniz
            ölçü, DPI ve dosya boyutu değerleri gerçek yükleme kontrolünde
            kullanılır. Yüklenen tüm tasarımları da aşağıdaki galeriden
            silebilirsiniz.
        </p>

        <?php if (
            isset($_GET['mhm_updated']) &&
            sanitize_key(wp_unslash($_GET['mhm_updated'])) === '1'
        ) : ?>

            <div class="notice notice-success is-dismissible">
                <p>Tasarım Yükle ayarları kaydedildi.</p>
            </div>

        <?php endif; ?>

        <form
            method="post"
            action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
            class="mhm-admin-form"
        >

            <input
                type="hidden"
                name="action"
                value="mhm_save_design_upload_settings"
            >

            <?php
            wp_nonce_field(
                'mhm_save_design_upload_settings',
                'mhm_design_upload_settings_nonce'
            );
            ?>

            <section class="mhm-admin-main-settings">

                <h2>Sayfa Ayarları</h2>

                <div class="mhm-admin-field">

                    <label for="mhm_design_upload_title">
                        Sayfa Başlığı
                    </label>

                    <input
                        id="mhm_design_upload_title"
                        type="text"
                        name="design_upload_title"
                        value="<?php
                        echo esc_attr($settings['design_upload_title']);
                        ?>"
                    >

                </div>

                <div class="mhm-admin-field">

                    <label for="mhm_design_upload_intro">
                        Sayfa Açıklaması
                    </label>

                    <textarea
                        id="mhm_design_upload_intro"
                        name="design_upload_intro"
                        rows="5"
                    ><?php
                    echo esc_textarea($settings['design_upload_intro']);
                    ?></textarea>

                </div>

                <div class="mhm-admin-criteria-box">

                    <h3>Tasarım Dosyası Kriterleri</h3>

                    <p class="mhm-admin-help-text">
                        Buradaki değerler yalnızca açıklama metnini değil,
                        gerçek yükleme doğrulamasını da belirler.
                    </p>

                    <div class="mhm-admin-criteria-grid">

                        <div class="mhm-admin-field">
                            <label for="mhm_design_width">
                                Genişlik (px)
                            </label>

                            <input
                                id="mhm_design_width"
                                type="number"
                                min="1"
                                step="1"
                                name="design_width"
                                value="<?php
                                echo esc_attr($settings['design_width']);
                                ?>"
                            >
                        </div>

                        <div class="mhm-admin-field">
                            <label for="mhm_design_height">
                                Yükseklik (px)
                            </label>

                            <input
                                id="mhm_design_height"
                                type="number"
                                min="1"
                                step="1"
                                name="design_height"
                                value="<?php
                                echo esc_attr($settings['design_height']);
                                ?>"
                            >
                        </div>

                        <div class="mhm-admin-field">
                            <label for="mhm_design_dpi">
                                DPI
                            </label>

                            <input
                                id="mhm_design_dpi"
                                type="number"
                                min="1"
                                step="1"
                                name="design_dpi"
                                value="<?php
                                echo esc_attr($settings['design_dpi']);
                                ?>"
                            >
                        </div>

                        <div class="mhm-admin-field">
                            <label for="mhm_design_max_file_mb">
                                Maksimum Dosya Boyutu (MB)
                            </label>

                            <input
                                id="mhm_design_max_file_mb"
                                type="number"
                                min="1"
                                step="1"
                                name="design_max_file_mb"
                                value="<?php
                                echo esc_attr(
                                    $settings['design_max_file_mb']
                                );
                                ?>"
                            >
                        </div>

                    </div>

                    <div class="mhm-admin-generated-rule">
                        <strong>Ön yüzde gösterilecek kural:</strong>
                        <?php echo esc_html(mhm_design_rules_text()); ?>
                    </div>

                </div>

                <div class="mhm-admin-field">

                    <label for="mhm_design_upload_button">
                        Yükleme Butonu Metni
                    </label>

                    <input
                        id="mhm_design_upload_button"
                        type="text"
                        name="design_upload_button"
                        value="<?php
                        echo esc_attr($settings['design_upload_button']);
                        ?>"
                    >

                </div>

            </section>

            <?php submit_button('Ayarları Kaydet'); ?>

        </form>

        <section class="mhm-admin-main-settings mhm-design-admin-gallery-section">

            <h2>Yüklenen Tasarımlar</h2>

            <?php
            echo mhm_render_design_gallery(
                $designs,
                true
            );
            ?>

        </section>

    </div>

    <?php
}


/*
|--------------------------------------------------------------------------
| TEKNİK DESTEK AYARLARI — YÖNETİM ALT MENÜSÜ
|--------------------------------------------------------------------------
*/

function mhm_support_admin_menu() {

    add_submenu_page(
        'mhm-content-management',
        'Teknik Destek Ayarları',
        'Teknik Destek Ayarları',
        'manage_woocommerce',
        'mhm-support-settings',
        'mhm_support_admin_page'
    );
}

add_action(
    'admin_menu',
    'mhm_support_admin_menu',
    32
);


/*
|--------------------------------------------------------------------------
| TEKNİK DESTEK AYARLARINI KAYDET
|--------------------------------------------------------------------------
*/

function mhm_save_support_settings() {

    if (!current_user_can('manage_woocommerce')) {
        wp_die('Bu işlemi yapma yetkiniz bulunmuyor.');
    }

    check_admin_referer(
        'mhm_save_support_settings',
        'mhm_support_settings_nonce'
    );

    $settings = mhm_content_settings();

    $support_email = isset($_POST['support_email'])
        ? sanitize_email(
            wp_unslash($_POST['support_email'])
        )
        : '';

    if ($support_email === '') {
        $support_email = sanitize_email(
            get_option('admin_email')
        );
    }

    $settings['support_email'] = $support_email;

    $settings['support_categories'] =
        isset($_POST['support_categories'])
            ? sanitize_textarea_field(
                wp_unslash($_POST['support_categories'])
            )
            : '';

    $settings['support_intro'] =
        isset($_POST['support_intro'])
            ? sanitize_textarea_field(
                wp_unslash($_POST['support_intro'])
            )
            : '';

    $settings['support_file_help'] =
        isset($_POST['support_file_help'])
            ? sanitize_textarea_field(
                wp_unslash($_POST['support_file_help'])
            )
            : '';

    $settings['support_account_note'] =
        isset($_POST['support_account_note'])
            ? sanitize_textarea_field(
                wp_unslash($_POST['support_account_note'])
            )
            : '';

    update_option(
        'mhm_content_settings',
        $settings,
        false
    );

    wp_safe_redirect(
        add_query_arg(
            [
                'page'        => 'mhm-support-settings',
                'mhm_updated' => '1',
            ],
            admin_url('admin.php')
        )
    );

    exit;
}

add_action(
    'admin_post_mhm_save_support_settings',
    'mhm_save_support_settings'
);


/*
|--------------------------------------------------------------------------
| TEKNİK DESTEK AYARLARI SAYFASI
|--------------------------------------------------------------------------
*/

function mhm_support_admin_page() {

    if (!current_user_can('manage_woocommerce')) {
        wp_die('Bu alanı görüntüleme yetkiniz bulunmuyor.');
    }

    $settings = mhm_content_settings();
    ?>

    <div class="wrap mhm-admin-page mhm-support-admin-page">

        <h1>Teknik Destek Ayarları</h1>

        <p class="mhm-admin-description">
            Teknik Destek formunun e-posta adresini, kategori listesini
            ve kullanıcıya gösterilen bilgilendirme metinlerini bu
            sayfadan yönetebilirsiniz.
        </p>

        <?php if (
            isset($_GET['mhm_updated']) &&
            sanitize_key(
                wp_unslash($_GET['mhm_updated'])
            ) === '1'
        ) : ?>

            <div class="notice notice-success is-dismissible">
                <p>Teknik Destek ayarları kaydedildi.</p>
            </div>

        <?php endif; ?>

        <form
            method="post"
            action="<?php
            echo esc_url(
                admin_url('admin-post.php')
            );
            ?>"
            class="mhm-admin-form"
        >

            <input
                type="hidden"
                name="action"
                value="mhm_save_support_settings"
            >

            <?php
            wp_nonce_field(
                'mhm_save_support_settings',
                'mhm_support_settings_nonce'
            );
            ?>

            <section
                class="mhm-admin-main-settings mhm-admin-support-settings"
            >

                <h2>Teknik Destek Ayarları</h2>

                <p class="mhm-admin-help-text">
                    Teknik destek formundan gönderilen talepler aşağıdaki
                    e-posta adresine iletilir. İleride Zendesk veya benzeri
                    bir destek sisteminin gelen e-posta adresini buraya
                    yazarak aynı formu kullanmaya devam edebilirsiniz.
                </p>

                <div class="mhm-admin-field">

                    <label for="mhm_support_email">
                        Teknik Destek E-posta Adresi
                    </label>

                    <input
                        id="mhm_support_email"
                        type="email"
                        name="support_email"
                        value="<?php
                        echo esc_attr(
                            $settings['support_email']
                        );
                        ?>"
                        placeholder="destek@magazify.com"
                        required
                    >

                </div>

                <div class="mhm-admin-field">

                    <label for="mhm_support_categories">
                        Destek Kategorileri
                    </label>

                    <textarea
                        id="mhm_support_categories"
                        name="support_categories"
                        rows="10"
                    ><?php
                    echo esc_textarea(
                        $settings['support_categories']
                    );
                    ?></textarea>

                    <p class="description">
                        Her satıra bir kategori yazın. Ön yüzdeki seçim
                        listesi bu alan üzerinden otomatik oluşturulur.
                    </p>

                </div>

                <div class="mhm-admin-field">

                    <label for="mhm_support_intro">
                        Teknik Destek Giriş Metni
                    </label>

                    <textarea
                        id="mhm_support_intro"
                        name="support_intro"
                        rows="5"
                    ><?php
                    echo esc_textarea(
                        $settings['support_intro']
                    );
                    ?></textarea>

                    <p class="description">
                        Teknik Destek başlığının altında görünen açıklama.
                    </p>

                </div>

                <div class="mhm-admin-field">

                    <label for="mhm_support_file_help">
                        Dosya Yükleme Bilgilendirme Metni
                    </label>

                    <textarea
                        id="mhm_support_file_help"
                        name="support_file_help"
                        rows="3"
                    ><?php
                    echo esc_textarea(
                        $settings['support_file_help']
                    );
                    ?></textarea>

                    <p class="description">
                        Dosya seçme alanının altında gösterilir.
                    </p>

                </div>

                <div class="mhm-admin-field">

                    <label for="mhm_support_account_note">
                        Hesap Bilgisi Bilgilendirme Metni
                    </label>

                    <textarea
                        id="mhm_support_account_note"
                        name="support_account_note"
                        rows="4"
                    ><?php
                    echo esc_textarea(
                        $settings['support_account_note']
                    );
                    ?></textarea>

                    <p class="description">
                        Destek talebi gönderme butonunun hemen üstündeki
                        bilgilendirme kutusunda gösterilir.
                    </p>

                </div>

            </section>

            <?php submit_button('Ayarları Kaydet'); ?>

        </form>

    </div>

    <?php
}


/*
|--------------------------------------------------------------------------
| FORM ALANLARI
|--------------------------------------------------------------------------
*/

function mhm_hb_fields() {

    return [
        /*
         * Mağaza ortağı bilgileri
         */
        'ad'    => 'Ad',
        'soyad' => 'Soyad',

        /*
         * Firma bilgileri
         */
        'firma_adi'     => 'Firma Adı (1)',
        'vergi_dairesi' => 'Firma Vergi Dairesi',
        'vergi_no'      => 'Firma Vergi No',

        /*
         * Adres bilgileri
         */
        'ulke'       => 'Ülke',
        'sehir'      => 'Şehir',
        'ilce'       => 'İlçe',
        'mahalle'    => 'Mahalle',
        'adres'      => 'Sokak/Apartman/Daire',
        'posta_kodu' => 'Posta Kodu',

        /*
         * İletişim bilgileri
         */
        'telefon' => 'Telefon',
        'email'   => 'E-posta Adresi',

        /*
         * Banka bilgileri
         */
        'banka_hesap_sahibi' => 'Banka Hesap Sahibi Ad Soyad (1)',
        'banka'               => 'Banka',
        'iban'                => 'IBAN',

        /*
         * Mağaza ortağı kimlik belgeleri
         */
        'kimlik_on'   => 'Mağaza Ortağı Kimlik Ön Yüz',
        'kimlik_arka' => 'Mağaza Ortağı Kimlik Arka Yüz',
    ];
}


/*
|--------------------------------------------------------------------------
| DOSYA ALANLARI
|--------------------------------------------------------------------------
*/

function mhm_hb_file_fields() {

    return [
        'kimlik_on',
        'kimlik_arka',
    ];
}


/*
|--------------------------------------------------------------------------
| BİLGİLENDİRME METNİ
|--------------------------------------------------------------------------
*/

function mhm_hb_information_text() {

    return 'Mağaza Ortağının banka hesabında tanımlı tam Ad Soyad girilmelidir. Mağaza Ortağı ile Banka Hesap sahibi aynı olmalıdır. Firma Adı opsiyoneldir, şirket sahibi olunması şart değildir.';
}


/*
|--------------------------------------------------------------------------
| FORM DEĞERİNİ TEMİZLEME
|--------------------------------------------------------------------------
*/

function mhm_hb_sanitize_field_value($key, $value) {

    $value = wp_unslash($value);

    if ($key === 'email') {
        return sanitize_email($value);
    }

    if ($key === 'adres') {
        return sanitize_textarea_field($value);
    }

    if ($key === 'iban') {
        /*
         * IBAN içindeki boşlukları kaldırır ve büyük harfe çevirir.
         */
        $value = preg_replace('/\s+/', '', $value);

        return strtoupper(
            sanitize_text_field($value)
        );
    }

    return sanitize_text_field($value);
}


/*
|--------------------------------------------------------------------------
| AD SOYAD NORMALİZASYONU
|--------------------------------------------------------------------------
|
| Büyük-küçük harf, Türkçe karakter, noktalama işaretleri
| ve fazla boşluk farklılıklarını azaltarak karşılaştırma yapar.
|
*/

function mhm_hb_normalize_person_name($name) {

    $name = sanitize_text_field($name);
    $name = remove_accents($name);
    $name = strtolower($name);

    /*
     * Harf, sayı ve boşluk dışındaki karakterleri kaldırır.
     */
    $name = preg_replace('/[^a-z0-9\s]/', ' ', $name);

    /*
     * Birden fazla boşluğu tek boşluğa indirir.
     */
    $name = preg_replace('/\s+/', ' ', $name);

    return trim($name);
}


/*
|--------------------------------------------------------------------------
| MAĞAZA ORTAĞI VE BANKA HESAP SAHİBİ KONTROLÜ
|--------------------------------------------------------------------------
*/

function mhm_hb_validate_account_owner($data) {

    $ad = isset($data['ad'])
        ? mhm_hb_sanitize_field_value('ad', $data['ad'])
        : '';

    $soyad = isset($data['soyad'])
        ? mhm_hb_sanitize_field_value('soyad', $data['soyad'])
        : '';

    $banka_hesap_sahibi = isset($data['banka_hesap_sahibi'])
        ? mhm_hb_sanitize_field_value(
            'banka_hesap_sahibi',
            $data['banka_hesap_sahibi']
        )
        : '';

    $magaza_ortagi = trim($ad . ' ' . $soyad);

    /*
     * Alanlardan biri boşsa eşleşme kontrolünü burada yapmaz.
     * İstenirse daha sonra zorunlu alan kontrolü eklenebilir.
     */
    if ($magaza_ortagi === '' || $banka_hesap_sahibi === '') {
        return true;
    }

    return mhm_hb_normalize_person_name($magaza_ortagi)
        === mhm_hb_normalize_person_name($banka_hesap_sahibi);
}


/*
|--------------------------------------------------------------------------
| KİMLİK DOSYASI YÜKLEME
|--------------------------------------------------------------------------
*/

function mhm_hb_upload_identity_file($file, $user_id, $meta_key) {

    if (
        empty($file) ||
        empty($file['name']) ||
        empty($file['tmp_name'])
    ) {
        return true;
    }

    if (
        isset($file['error']) &&
        (int) $file['error'] !== UPLOAD_ERR_OK
    ) {
        return new WP_Error(
            'mhm_hb_upload_error',
            'Kimlik belgesi yüklenirken bir hata oluştu.'
        );
    }

    if (
        isset($file['size']) &&
        (int) $file['size'] > MHM_HB_MAX_FILE_SIZE
    ) {
        return new WP_Error(
            'mhm_hb_file_too_large',
            'Kimlik belgesinin dosya boyutu en fazla 5 MB olabilir.'
        );
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    $allowed_mimes = [
        'jpg|jpeg|jpe' => 'image/jpeg',
        'png'          => 'image/png',
        'webp'         => 'image/webp',
    ];

    /*
     * Dosyanın gerçek türünü kontrol eder.
     */
    $file_check = wp_check_filetype_and_ext(
        $file['tmp_name'],
        $file['name'],
        $allowed_mimes
    );

    if (
        empty($file_check['type']) ||
        !in_array(
            $file_check['type'],
            array_values($allowed_mimes),
            true
        )
    ) {
        return new WP_Error(
            'mhm_hb_invalid_file_type',
            'Yalnızca JPG, PNG veya WEBP formatında kimlik belgesi yüklenebilir.'
        );
    }

    $upload = wp_handle_upload(
        $file,
        [
            'test_form' => false,
            'mimes'     => $allowed_mimes,
        ]
    );

    if (
        !empty($upload['error']) ||
        empty($upload['file'])
    ) {
        return new WP_Error(
            'mhm_hb_upload_failed',
            !empty($upload['error'])
                ? sanitize_text_field($upload['error'])
                : 'Dosya yüklenemedi.'
        );
    }

    $attachment_id = wp_insert_attachment(
        [
            'post_mime_type' => $upload['type'],
            'post_title'     => sanitize_text_field(
                pathinfo($file['name'], PATHINFO_FILENAME)
            ),
            'post_content'   => '',
            'post_status'    => 'inherit',
            'post_author'    => $user_id,
        ],
        $upload['file']
    );

    if (is_wp_error($attachment_id)) {
        return $attachment_id;
    }

    $metadata = wp_generate_attachment_metadata(
        $attachment_id,
        $upload['file']
    );

    if (!empty($metadata)) {
        wp_update_attachment_metadata(
            $attachment_id,
            $metadata
        );
    }

    /*
     * Yeni dosya kaydedilmeden önce eski dosya kimliğini alır.
     */
    $old_attachment_id = (int) get_user_meta(
        $user_id,
        $meta_key,
        true
    );

    update_user_meta(
        $user_id,
        $meta_key,
        $attachment_id
    );

    /*
     * Eski dosya varsa ve yeni dosyadan farklıysa siler.
     */
    if (
        $old_attachment_id > 0 &&
        $old_attachment_id !== (int) $attachment_id
    ) {
        wp_delete_attachment(
            $old_attachment_id,
            true
        );
    }

    return $attachment_id;
}


/*
|--------------------------------------------------------------------------
| KULLANICI VERİLERİNİ KAYDETME
|--------------------------------------------------------------------------
*/

function mhm_hb_save_user_fields($user_id, $post_data, $files = []) {

    $fields      = mhm_hb_fields();
    $file_fields = mhm_hb_file_fields();

    if (!mhm_hb_validate_account_owner($post_data)) {
        return new WP_Error(
            'mhm_hb_account_owner_mismatch',
            'Mağaza Ortağı ile banka hesap sahibi aynı kişi olmalıdır. Ad, Soyad ve Banka Hesap Sahibi Ad Soyad alanlarını kontrol ediniz.'
        );
    }

    foreach ($fields as $key => $label) {

        if (in_array($key, $file_fields, true)) {

            if (
                isset($files[$key]) &&
                !empty($files[$key]['name'])
            ) {
                $upload_result = mhm_hb_upload_identity_file(
                    $files[$key],
                    $user_id,
                    $key
                );

                if (is_wp_error($upload_result)) {
                    return $upload_result;
                }
            }

            continue;
        }

        if (isset($post_data[$key])) {

            update_user_meta(
                $user_id,
                $key,
                mhm_hb_sanitize_field_value(
                    $key,
                    $post_data[$key]
                )
            );
        }
    }

    return true;
}


/*
|--------------------------------------------------------------------------
| SHORTCODE: [hesap-bilgileri]
|--------------------------------------------------------------------------
*/

function mhm_hb_render_account_information() {

    if (!is_user_logged_in()) {
        return '<p>Bu alanı görüntülemek için giriş yapmalısınız.</p>';
    }

    if (!current_user_can('manage_woocommerce')) {
        return '<p>Bu alanı görüntüleme yetkiniz yok.</p>';
    }

    $user_id = get_current_user_id();
    $fields  = mhm_hb_fields();

    ob_start();
    ?>

    <div class="hb-wrapper">

        <div class="hb-info-box">
            <div class="hb-info-line">
                <span class="hb-info-marker">(1)</span>
                <span class="hb-info-text"><?php
                    echo esc_html(mhm_hb_information_text());
                ?></span>
            </div>
        </div>

        <?php
        $updated = isset($_GET['mhm_hb_updated'])
            ? sanitize_text_field(wp_unslash($_GET['mhm_hb_updated']))
            : '';

        $error_message = isset($_GET['mhm_hb_error'])
            ? sanitize_text_field(wp_unslash($_GET['mhm_hb_error']))
            : '';
        ?>

        <?php if ($updated === '1') : ?>

            <div
                class="hb-message hb-message-success"
                role="status"
            >
                Bilgileriniz kaydedildi.
            </div>

        <?php endif; ?>

        <?php if ($error_message !== '') : ?>

            <div
                class="hb-message hb-message-error"
                role="alert"
            >
                <?php echo esc_html($error_message); ?>
            </div>

        <?php endif; ?>

        <form
            method="post"
            enctype="multipart/form-data"
            class="hb-form"
        >

            <?php
            wp_nonce_field(
                'mhm_hb_save_account',
                'mhm_hb_nonce'
            );
            ?>

            <?php foreach ($fields as $key => $label) : ?>

                <?php
                $value = get_user_meta(
                    $user_id,
                    $key,
                    true
                );
                ?>

                <?php if (in_array($key, mhm_hb_file_fields(), true)) : ?>

                    <?php
                    $attachment_url = $value
                        ? wp_get_attachment_url((int) $value)
                        : '';
                    ?>

                    <div class="hb-field hb-field-file">

                        <label for="<?php echo esc_attr($key); ?>">
                            <?php echo esc_html($label); ?>
                        </label>

                        <input
                            id="<?php echo esc_attr($key); ?>"
                            type="file"
                            name="<?php echo esc_attr($key); ?>"
                            accept="image/jpeg,image/png,image/webp"
                        >

                        <div class="hb-existing">

                            <?php if ($attachment_url) : ?>

                                <a
                                    href="<?php echo esc_url($attachment_url); ?>"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    Görüntüle
                                </a>

                            <?php else : ?>

                                Henüz yüklenmedi

                            <?php endif; ?>

                        </div>

                    </div>

                <?php else : ?>

                    <div class="hb-field">

                        <label for="<?php echo esc_attr($key); ?>">
                            <?php echo esc_html($label); ?>
                        </label>

                        <input
                            id="<?php echo esc_attr($key); ?>"
                            type="<?php echo $key === 'email' ? 'email' : 'text'; ?>"
                            name="<?php echo esc_attr($key); ?>"
                            value="<?php echo esc_attr($value); ?>"
                            autocomplete="off"
                        >

                    </div>

                <?php endif; ?>

            <?php endforeach; ?>

            <button
                type="submit"
                name="mhm_hb_save"
                value="1"
                class="hb-save"
            >
                Bilgileri Kaydet
            </button>

        </form>

    </div>

    <?php

    return ob_get_clean();
}


/*
|--------------------------------------------------------------------------
| ÖN YÜZ FORM KAYDI
|--------------------------------------------------------------------------
*/

add_action('init', function () {

    if (!isset($_POST['mhm_hb_save'])) {
        return;
    }

    if (
        !is_user_logged_in() ||
        !current_user_can('manage_woocommerce')
    ) {
        return;
    }

    if (
        !isset($_POST['mhm_hb_nonce']) ||
        !wp_verify_nonce(
            sanitize_text_field(
                wp_unslash($_POST['mhm_hb_nonce'])
            ),
            'mhm_hb_save_account'
        )
    ) {
        return;
    }

    $user_id = get_current_user_id();

    $result = mhm_hb_save_user_fields(
        $user_id,
        $_POST,
        $_FILES
    );

    /*
     * Kayıttan sonra her zaman Hesap Bilgileri sekmesinde kal.
     * Referer bazı tema / cache / güvenlik katmanlarında ana sayfaya
     * dönebildiği için endpoint URL doğrudan oluşturulur.
     */
    if (function_exists('wc_get_account_endpoint_url')) {
        $redirect_url = wc_get_account_endpoint_url(
            'hesap-bilgileri'
        );
    } else {
        $redirect_url = home_url('/hesabim/hesap-bilgileri/');
    }

    $redirect_url = remove_query_arg(
        [
            'mhm_hb_updated',
            'mhm_hb_error',
        ],
        $redirect_url
    );

    if (is_wp_error($result)) {

        $redirect_url = add_query_arg(
            'mhm_hb_error',
            $result->get_error_message(),
            $redirect_url
        );

    } else {

        $redirect_url = add_query_arg(
            'mhm_hb_updated',
            '1',
            $redirect_url
        );
    }

    wp_safe_redirect($redirect_url);
    exit;
});


/*
|--------------------------------------------------------------------------
| YÖNETİM PANELİ
|--------------------------------------------------------------------------
*/

function mhm_hb_admin_page() {

    if (!current_user_can('manage_woocommerce')) {
        wp_die(
            esc_html__('Bu alanı görüntüleme yetkiniz yok.', 'hesap-bilgileri')
        );
    }

    $selected_user = isset($_GET['user_id'])
        ? absint($_GET['user_id'])
        : 0;

    echo '<div class="wrap">';
    echo '<h1>Hesap Bilgileri</h1>';

    /*
     * Subscriber rolü dışındaki kullanıcıları getirir.
     */
    $users = get_users([
        'role__not_in' => ['subscriber'],
        'orderby'      => 'display_name',
        'order'        => 'ASC',
    ]);

    echo '<form method="get" class="hb-admin-user-select">';

    echo '<input
            type="hidden"
            name="page"
            value="hesap-bilgileri"
          >';

    echo '<select
            name="user_id"
            onchange="this.form.submit()"
          >';

    echo '<option value="">— Kullanıcı Seçin —</option>';

    foreach ($users as $user) {

        printf(
            '<option value="%1$d" %2$s>%3$s (%4$s)</option>',
            (int) $user->ID,
            selected(
                $selected_user,
                $user->ID,
                false
            ),
            esc_html($user->display_name),
            esc_html($user->user_email)
        );
    }

    echo '</select>';
    echo '</form>';

    if (!$selected_user) {

        echo '<p>Lütfen bir kullanıcı seçin.</p>';
        echo '</div>';

        return;
    }

    /*
     * Seçilen kullanıcının mevcut olduğunu kontrol eder.
     */
    $selected_user_object = get_userdata($selected_user);

    if (!$selected_user_object) {

        echo '<div class="notice notice-error"><p>Geçersiz kullanıcı seçildi.</p></div>';
        echo '</div>';

        return;
    }

    /*
     * YÖNETİM PANELİ FORM KAYDI
     */
    if (isset($_POST['mhm_hb_admin_save'])) {

        check_admin_referer(
            'mhm_hb_admin_save_account',
            'mhm_hb_admin_nonce'
        );

        $result = mhm_hb_save_user_fields(
            $selected_user,
            $_POST,
            $_FILES
        );

        if (is_wp_error($result)) {

            echo '<div class="notice notice-error is-dismissible"><p>';
            echo esc_html(
                $result->get_error_message()
            );
            echo '</p></div>';

        } else {

            echo '<div class="notice notice-success is-dismissible"><p>';
            echo 'Bilgiler güncellendi.';
            echo '</p></div>';
        }
    }

    $fields = mhm_hb_fields();

    echo '<div class="hb-admin-info">';
    echo '<strong>(1)</strong> ';
    echo esc_html(mhm_hb_information_text());
    echo '</div>';

    echo '<form method="post" enctype="multipart/form-data">';

    wp_nonce_field(
        'mhm_hb_admin_save_account',
        'mhm_hb_admin_nonce'
    );

    echo '<table class="form-table">';

    foreach ($fields as $key => $label) {

        $value = get_user_meta(
            $selected_user,
            $key,
            true
        );

        echo '<tr>';

        echo '<th>';
        echo '<label for="' . esc_attr($key) . '">';
        echo esc_html($label);
        echo '</label>';
        echo '</th>';

        echo '<td>';

        if (in_array($key, mhm_hb_file_fields(), true)) {

            $attachment_url = $value
                ? wp_get_attachment_url((int) $value)
                : '';

            echo '<input
                    id="' . esc_attr($key) . '"
                    type="file"
                    name="' . esc_attr($key) . '"
                    accept="image/jpeg,image/png,image/webp"
                  >';

            echo '<br>';

            if ($attachment_url) {

                echo '<small>';

                echo '<a
                        href="' . esc_url($attachment_url) . '"
                        target="_blank"
                        rel="noopener noreferrer"
                      >';

                echo 'Görüntüle';

                echo '</a>';
                echo '</small>';

            } else {

                echo '<small>Yüklenmemiş</small>';
            }

        } else {

            $input_type = $key === 'email'
                ? 'email'
                : 'text';

            echo '<input
                    id="' . esc_attr($key) . '"
                    type="' . esc_attr($input_type) . '"
                    name="' . esc_attr($key) . '"
                    value="' . esc_attr($value) . '"
                    class="regular-text"
                  >';
        }

        echo '</td>';
        echo '</tr>';
    }

    echo '</table>';

    echo '<p>';

    echo '<button
            type="submit"
            name="mhm_hb_admin_save"
            value="1"
            class="button button-primary"
          >';

    echo 'Kaydet';

    echo '</button>';

    echo '</p>';

    echo '</form>';
    echo '</div>';
}