<?php
/*
Plugin Name: Magazify Sipariş ve Hesap Hareketleri
Description: Magazify sipariş raporlama, hakediş, gönderim katkısı ve mağaza ortağı hesap hareketleri yönetimi.
Version: 1.1.2
Author: Magazify
*/

if (!defined('ABSPATH')) {
    exit;
}

/*
|--------------------------------------------------------------------------
| SABİTLER
|--------------------------------------------------------------------------
*/

define('MSH_VERSION', '1.1.2');

// GitHub release tabanlı otomatik güncelleme
require_once __DIR__ . '/includes/class-updater.php';
MSH_Updater::init(__FILE__);

/*
 * Eski eklentilerle aynı tablo ve hesaplama davranışı korunur.
 */
define('HH_TABLE_VERSION', '1.0');
define('HH_FREE_SHIPPING_THRESHOLD', 1500.00);
define('HH_REFERENCE_SHIPPING_COST', 99.00);
define('HH_PARTNER_SHIPPING_SHARE_RATE', 0.50);

define('SY_HAKEDIS_ORANI', 0.20);


/*
|--------------------------------------------------------------------------
| SİPARİŞ VE HESAP AYARLARI
|--------------------------------------------------------------------------
|
| Sabitler varsayılan değer olarak korunur. Gerçek çalışma değerleri
| WordPress option üzerinden yönetilir.
|
*/

function msh_default_settings() {

    return [
        'entitlement_rate'            => (float) SY_HAKEDIS_ORANI,
        'free_shipping_threshold'     => (float) HH_FREE_SHIPPING_THRESHOLD,
        'reference_shipping_cost'     => (float) HH_REFERENCE_SHIPPING_COST,
        'partner_shipping_share_rate' => (float) HH_PARTNER_SHIPPING_SHARE_RATE,
    ];
}


function msh_get_settings() {

    $defaults = msh_default_settings();
    $saved    = get_option('msh_finance_settings', []);

    if (!is_array($saved)) {
        $saved = [];
    }

    $settings = wp_parse_args(
        $saved,
        $defaults
    );

    $settings['entitlement_rate'] =
        max(0, min(1, (float) $settings['entitlement_rate']));

    $settings['free_shipping_threshold'] =
        max(0, (float) $settings['free_shipping_threshold']);

    $settings['reference_shipping_cost'] =
        max(0, (float) $settings['reference_shipping_cost']);

    $settings['partner_shipping_share_rate'] =
        max(0, min(1, (float) $settings['partner_shipping_share_rate']));

    return $settings;
}


function msh_entitlement_rate() {
    $settings = msh_get_settings();
    return (float) $settings['entitlement_rate'];
}


function msh_free_shipping_threshold() {
    $settings = msh_get_settings();
    return (float) $settings['free_shipping_threshold'];
}


function msh_reference_shipping_cost() {
    $settings = msh_get_settings();
    return (float) $settings['reference_shipping_cost'];
}


function msh_partner_shipping_share_rate() {
    $settings = msh_get_settings();
    return (float) $settings['partner_shipping_share_rate'];
}


function msh_percent_label($decimal_rate) {

    return rtrim(
        rtrim(
            number_format(
                ((float) $decimal_rate) * 100,
                2,
                ',',
                '.'
            ),
            '0'
        ),
        ','
    );
}


/*
|--------------------------------------------------------------------------
| HESAP HAREKETLERİ TABLOSU
|--------------------------------------------------------------------------
*/

function hh_table_name() {
    global $wpdb;

    return $wpdb->prefix . 'magazify_account_movements';
}


function msh_create_or_upgrade_movements_table() {

    global $wpdb;

    $table_name      = hh_table_name();
    $charset_collate = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE {$table_name} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id BIGINT UNSIGNED NULL,
        movement_type VARCHAR(40) NOT NULL,
        amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
        description TEXT NULL,
        movement_date DATETIME NOT NULL,
        created_by BIGINT UNSIGNED NULL,
        is_manual TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY order_movement (order_id, movement_type),
        KEY movement_type (movement_type),
        KEY movement_date (movement_date)
    ) {$charset_collate};";

    dbDelta($sql);

    update_option(
        'hh_table_version',
        HH_TABLE_VERSION
    );
}


function msh_activate_plugin() {

    msh_create_or_upgrade_movements_table();
    flush_rewrite_rules();
}

register_activation_hook(
    __FILE__,
    'msh_activate_plugin'
);


function msh_maybe_upgrade_table() {

    if (
        get_option('hh_table_version')
        !== HH_TABLE_VERSION
    ) {
        msh_create_or_upgrade_movements_table();
    }
}

add_action(
    'plugins_loaded',
    'msh_maybe_upgrade_table'
);


/*
|--------------------------------------------------------------------------
| DOSYALAR
|--------------------------------------------------------------------------
*/

function msh_register_front_assets() {

    wp_register_style(
        'msh-style',
        plugin_dir_url(__FILE__) . 'msh.css',
        [],
        MSH_VERSION
    );

    wp_register_script(
        'msh-script',
        plugin_dir_url(__FILE__) . 'msh.js',
        [],
        MSH_VERSION,
        true
    );
}

add_action(
    'wp_enqueue_scripts',
    'msh_register_front_assets'
);


function msh_admin_assets($hook_suffix) {

    $allowed_hooks = [
        'hesap-merkezi_page_uy-siparis-yonetim',
        'hesap-merkezi_page_hesap-hareketleri',
        'hesap-merkezi_page_msh-finance-settings',
    ];

    $current_page = isset($_GET['page'])
        ? sanitize_key(wp_unslash($_GET['page']))
        : '';

    $allowed_pages = [
        'uy-siparis-yonetim',
        'hesap-hareketleri',
        'msh-finance-settings',
    ];

    if (
        !in_array($hook_suffix, $allowed_hooks, true) &&
        !in_array($current_page, $allowed_pages, true)
    ) {
        return;
    }

    wp_enqueue_style(
        'msh-style-admin',
        plugin_dir_url(__FILE__) . 'msh.css',
        [],
        MSH_VERSION
    );

    wp_enqueue_script(
        'msh-script-admin',
        plugin_dir_url(__FILE__) . 'msh.js',
        [],
        MSH_VERSION,
        true
    );
}

add_action(
    'admin_enqueue_scripts',
    'msh_admin_assets'
);


/*
|--------------------------------------------------------------------------
| YETKİLER
|--------------------------------------------------------------------------
*/

function hh_can_view_movements() {

    return is_user_logged_in()
        && (
            current_user_can('manage_woocommerce')
            || current_user_can('manage_store_panel')
        );
}


/*
|--------------------------------------------------------------------------
| YÖNETİM PANELİ MENÜLERİ
|--------------------------------------------------------------------------
|
| Bu eklenti teknik olarak bağımsız kalır; yalnızca yönetim ekranları
| Magazify Hesap Merkezi altında gösterilir.
|
*/

function msh_admin_menu() {

    $parent_slug = 'mhm-content-management';

    add_submenu_page(
        $parent_slug,
        'Sipariş Raporlama',
        'Sipariş Raporlama',
        'manage_woocommerce',
        'uy-siparis-yonetim',
        'uy_siparis_sayfasi'
    );

    add_submenu_page(
        $parent_slug,
        'Hesap Hareketleri',
        'Hesap Hareketleri',
        'manage_woocommerce',
        'hesap-hareketleri',
        'hh_admin_page'
    );

    add_submenu_page(
        $parent_slug,
        'Sipariş ve Hesap Ayarları',
        'Sipariş ve Hesap Ayarları',
        'manage_woocommerce',
        'msh-finance-settings',
        'msh_finance_settings_page'
    );
}

add_action(
    'admin_menu',
    'msh_admin_menu',
    40
);


/*
|--------------------------------------------------------------------------
| SİPARİŞ VE HESAP AYARLARINI KAYDET
|--------------------------------------------------------------------------
*/

function msh_save_finance_settings() {

    if (!current_user_can('manage_woocommerce')) {
        wp_die('Bu işlemi yapma yetkiniz bulunmuyor.');
    }

    check_admin_referer(
        'msh_save_finance_settings',
        'msh_finance_settings_nonce'
    );

    $entitlement_percent = isset($_POST['entitlement_percent'])
        ? (float) str_replace(
            ',',
            '.',
            wp_unslash($_POST['entitlement_percent'])
        )
        : 20;

    $threshold = isset($_POST['free_shipping_threshold'])
        ? (float) str_replace(
            ',',
            '.',
            wp_unslash($_POST['free_shipping_threshold'])
        )
        : HH_FREE_SHIPPING_THRESHOLD;

    $reference_cost = isset($_POST['reference_shipping_cost'])
        ? (float) str_replace(
            ',',
            '.',
            wp_unslash($_POST['reference_shipping_cost'])
        )
        : HH_REFERENCE_SHIPPING_COST;

    $partner_share_percent = isset($_POST['partner_shipping_share_percent'])
        ? (float) str_replace(
            ',',
            '.',
            wp_unslash($_POST['partner_shipping_share_percent'])
        )
        : 50;

    $settings = [
        'entitlement_rate' =>
            max(0, min(100, $entitlement_percent)) / 100,

        'free_shipping_threshold' =>
            max(0, $threshold),

        'reference_shipping_cost' =>
            max(0, $reference_cost),

        'partner_shipping_share_rate' =>
            max(0, min(100, $partner_share_percent)) / 100,
    ];

    update_option(
        'msh_finance_settings',
        $settings,
        false
    );

    /*
     * Ayarlar değiştiğinde mevcut processing/completed siparişlerin
     * otomatik hareketleri yeni değerlere göre yeniden senkronize edilir.
     * Manuel ödeme kayıtlarına dokunulmaz.
     */
    $order_ids = wc_get_orders([
        'status' => [
            'processing',
            'completed',
        ],
        'limit'  => -1,
        'return' => 'ids',
    ]);

    foreach ($order_ids as $order_id) {
        hh_sync_order_movements((int) $order_id);
    }

    wp_safe_redirect(
        add_query_arg(
            [
                'page'        => 'msh-finance-settings',
                'msh_updated' => '1',
            ],
            admin_url('admin.php')
        )
    );

    exit;
}

add_action(
    'admin_post_msh_save_finance_settings',
    'msh_save_finance_settings'
);


/*
|--------------------------------------------------------------------------
| SİPARİŞ VE HESAP AYARLARI SAYFASI
|--------------------------------------------------------------------------
*/

function msh_finance_settings_page() {

    if (!current_user_can('manage_woocommerce')) {
        wp_die('Bu alanı görüntüleme yetkiniz bulunmuyor.');
    }

    $settings = msh_get_settings();

    $shipping_partner_amount =
        $settings['reference_shipping_cost']
        * $settings['partner_shipping_share_rate'];
    ?>

    <div class="wrap msh-settings-page">

        <h1>Sipariş ve Hesap Ayarları</h1>

        <p class="msh-settings-intro">
            Sipariş Raporlama ve Hesap Hareketleri ekranlarında kullanılan
            finansal değişkenleri buradan yönetebilirsiniz. Değerleri
            değiştirdiğinizde mevcut işleniyor/tamamlandı durumundaki
            siparişlerin otomatik hakediş ve gönderim hareketleri yeni
            değerlere göre yeniden hesaplanır.
        </p>

        <?php if (
            isset($_GET['msh_updated']) &&
            sanitize_key(
                wp_unslash($_GET['msh_updated'])
            ) === '1'
        ) : ?>

            <div class="notice notice-success is-dismissible">
                <p>Sipariş ve Hesap Ayarları kaydedildi.</p>
            </div>

        <?php endif; ?>

        <form
            method="post"
            action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
            class="msh-settings-form"
        >

            <input
                type="hidden"
                name="action"
                value="msh_save_finance_settings"
            >

            <?php
            wp_nonce_field(
                'msh_save_finance_settings',
                'msh_finance_settings_nonce'
            );
            ?>

            <section class="msh-settings-card">

                <h2>Hakediş Ayarları</h2>

                <div class="msh-settings-field">

                    <label for="msh_entitlement_percent">
                        Hakediş Oranı (%)
                    </label>

                    <input
                        id="msh_entitlement_percent"
                        type="number"
                        name="entitlement_percent"
                        min="0"
                        max="100"
                        step="0.01"
                        value="<?php
                        echo esc_attr(
                            $settings['entitlement_rate'] * 100
                        );
                        ?>"
                    >

                    <p>
                        KDV hariç ürün satış toplamının Mağaza Ortağı
                        hakedişi olarak hesaplanacak yüzdesi.
                    </p>

                </div>

            </section>

            <section class="msh-settings-card">

                <h2>Gönderim Ayarları</h2>

                <div class="msh-settings-grid">

                    <div class="msh-settings-field">

                        <label for="msh_shipping_threshold">
                            Ücretsiz Gönderim Eşiği (TL)
                        </label>

                        <input
                            id="msh_shipping_threshold"
                            type="number"
                            name="free_shipping_threshold"
                            min="0"
                            step="0.01"
                            value="<?php
                            echo esc_attr(
                                $settings['free_shipping_threshold']
                            );
                            ?>"
                        >

                        <p>
                            Bu tutarın üzerindeki siparişlerde Mağaza
                            Ortağı gönderim katkısı hesabı uygulanır.
                        </p>

                    </div>

                    <div class="msh-settings-field">

                        <label for="msh_reference_shipping">
                            Referans Gönderim Maliyeti (TL)
                        </label>

                        <input
                            id="msh_reference_shipping"
                            type="number"
                            name="reference_shipping_cost"
                            min="0"
                            step="0.01"
                            value="<?php
                            echo esc_attr(
                                $settings['reference_shipping_cost']
                            );
                            ?>"
                        >

                    </div>

                    <div class="msh-settings-field">

                        <label for="msh_partner_share">
                            Mağaza Ortağı Gönderim Payı (%)
                        </label>

                        <input
                            id="msh_partner_share"
                            type="number"
                            name="partner_shipping_share_percent"
                            min="0"
                            max="100"
                            step="0.01"
                            value="<?php
                            echo esc_attr(
                                $settings['partner_shipping_share_rate'] * 100
                            );
                            ?>"
                        >

                    </div>

                    <div class="msh-settings-result">

                        <strong>
                            Mevcut Mağaza Ortağı Gönderim Maliyeti
                        </strong>

                        <span>
                            <?php
                            echo wp_kses_post(
                                wc_price(
                                    $shipping_partner_amount
                                )
                            );
                            ?>
                        </span>

                    </div>

                </div>

            </section>

            <?php submit_button('Ayarları Kaydet'); ?>

        </form>

    </div>

    <?php
}



/*
|--------------------------------------------------------------------------
| ORTAK FİNANS MOTORU
|--------------------------------------------------------------------------
|
| Sipariş Raporlama ve Hesap Hareketleri artık aynı hesaplama
| fonksiyonunu kullanır. Böylece KDV hariç, KDV ve hakediş
| sonuçları iki farklı yerde yeniden hesaplanmaz.
|
*/

function msh_decimal($value) {

    if (is_string($value)) {
        $value = str_replace(',', '.', $value);
    }

    return round(
        (float) $value,
        wc_get_price_decimals()
    );
}


function msh_order_financial_totals($order) {

    $totals = [
        'tax_exclusive' => 0.0,
        'tax'           => 0.0,
        'gross_products'=> 0.0,
        'entitlement'   => 0.0,
    ];

    if (!$order instanceof WC_Order) {
        return $totals;
    }

    foreach ($order->get_items('line_item') as $item) {

        if (!$item instanceof WC_Order_Item_Product) {
            continue;
        }

        $totals['tax_exclusive'] +=
            (float) $item->get_total();

        $totals['tax'] +=
            (float) $item->get_total_tax();
    }

    $totals['tax_exclusive'] =
        msh_decimal($totals['tax_exclusive']);

    $totals['tax'] =
        msh_decimal($totals['tax']);

    $totals['gross_products'] =
        msh_decimal(
            $totals['tax_exclusive']
            + $totals['tax']
        );

    $totals['entitlement'] =
        msh_decimal(
            $totals['tax_exclusive']
            * msh_entitlement_rate()
        );

    return $totals;
}


/*
 * Eski fonksiyon adlarını koruyoruz.
 */
function hh_decimal($value) {
    return msh_decimal($value);
}


function hh_calculate_order_finance(WC_Order $order) {

    $totals = msh_order_financial_totals($order);

    return [
        'tax_excluded_total' => $totals['tax_exclusive'],
        'tax_total'          => $totals['tax'],
        'entitlement'        => $totals['entitlement'],
    ];
}


function sy_order_financial_totals($order) {
    return msh_order_financial_totals($order);
}


/*
|--------------------------------------------------------------------------
| İPTAL / İADE / BAŞARISIZ SİPARİŞ TEMİZLİĞİ
|--------------------------------------------------------------------------
|
| Eski Hesap Hareketleri eklentisinde processing/completed dışına çıkan
| siparişlerin mevcut sistem hareketleri kalabiliyordu. Bu sürümde
| sipariş uygun durumdan çıktığında otomatik hakediş ve gönderim
| hareketleri temizlenir. Manuel ödeme kayıtlarına dokunulmaz.
|
*/

function msh_delete_automatic_order_movements($order_id) {

    global $wpdb;

    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM " . hh_table_name() . "
             WHERE order_id = %d
             AND is_manual = 0
             AND movement_type IN ('order_entitlement', 'shipping_share')",
            $order_id
        )
    );
}


function msh_handle_order_status_change(
    $order_id,
    $old_status,
    $new_status,
    $order
) {

    $allowed_statuses = [
        'processing',
        'completed',
    ];

    if (in_array($new_status, $allowed_statuses, true)) {

        hh_sync_order_movements($order_id);
        return;
    }

    msh_delete_automatic_order_movements(
        $order_id
    );
}

add_action(
    'woocommerce_order_status_changed',
    'msh_handle_order_status_change',
    20,
    4
);



/*
|--------------------------------------------------------------------------
| SİPARİŞ SİLME / ÇÖPE TAŞIMA TEMİZLİĞİ
|--------------------------------------------------------------------------
|
| Sipariş WooCommerce'ten silindiğinde veya çöpe taşındığında yalnızca
| o siparişe bağlı otomatik hakediş ve gönderim maliyeti hareketleri
| temizlenir. Manuel ödeme kayıtlarına dokunulmaz.
|
*/

function msh_handle_deleted_order($order_id) {

    $order_id = absint($order_id);

    if (!$order_id) {
        return;
    }

    msh_delete_automatic_order_movements(
        $order_id
    );
}


/*
 * WooCommerce CRUD / HPOS uyumlu sipariş silme ve çöp hook'ları.
 */
add_action(
    'woocommerce_before_delete_order',
    'msh_handle_deleted_order',
    20,
    1
);

add_action(
    'woocommerce_delete_order',
    'msh_handle_deleted_order',
    20,
    1
);

add_action(
    'woocommerce_before_trash_order',
    'msh_handle_deleted_order',
    20,
    1
);

add_action(
    'woocommerce_trash_order',
    'msh_handle_deleted_order',
    20,
    1
);


/*
 * Klasik shop_order post type kullanılan kurulumlar için fallback.
 */
function msh_handle_legacy_order_delete($post_id) {

    $post_id = absint($post_id);

    if (!$post_id) {
        return;
    }

    if (get_post_type($post_id) !== 'shop_order') {
        return;
    }

    msh_handle_deleted_order(
        $post_id
    );
}

add_action(
    'wp_trash_post',
    'msh_handle_legacy_order_delete',
    20,
    1
);

add_action(
    'before_delete_post',
    'msh_handle_legacy_order_delete',
    20,
    1
);


/*
|--------------------------------------------------------------------------
| YETİM HESAP HAREKETLERİ TEMİZLİĞİ
|--------------------------------------------------------------------------
|
| Geçmişte WooCommerce'ten silinmiş fakat Hesap Hareketleri tablosunda
| kalmış otomatik hareketleri temizler. Manuel hareketler korunur.
|
*/

function msh_cleanup_orphaned_order_movements($limit = 250) {

    global $wpdb;

    if (!function_exists('wc_get_order')) {
        return 0;
    }

    $limit = max(
        1,
        min(
            1000,
            absint($limit)
        )
    );

    $table_name = hh_table_name();

    $order_ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT DISTINCT order_id
             FROM {$table_name}
             WHERE order_id IS NOT NULL
             AND order_id > 0
             AND is_manual = 0
             AND movement_type IN ('order_entitlement', 'shipping_share')
             ORDER BY order_id DESC
             LIMIT %d",
            $limit
        )
    );

    if (empty($order_ids)) {
        return 0;
    }

    $cleaned = 0;

    foreach ($order_ids as $order_id) {

        $order_id = absint($order_id);

        if (!$order_id) {
            continue;
        }

        $order = wc_get_order(
            $order_id
        );

        if ($order instanceof WC_Order) {
            continue;
        }

        msh_delete_automatic_order_movements(
            $order_id
        );

        $cleaned++;
    }

    return $cleaned;
}


/*
|--------------------------------------------------------------------------
| ESKİ KAYITLAR İÇİN KONTROLLÜ BACKFILL
|--------------------------------------------------------------------------
|
| Artık her Hesap Hareketleri ekranı açılışında bütün siparişleri
| limit=-1 ile taramayız. Backfill yalnızca bir kez yapılır.
|
*/

function msh_maybe_backfill_movements() {

    if (
        get_option('msh_movements_backfill_v1')
        === 'yes'
    ) {
        return;
    }

    if (!class_exists('WooCommerce')) {
        return;
    }

    $order_ids = wc_get_orders([
        'status'  => [
            'processing',
            'completed',
        ],
        'limit'   => -1,
        'orderby' => 'date',
        'order'   => 'ASC',
        'return'  => 'ids',
    ]);

    foreach ($order_ids as $order_id) {

        hh_sync_order_movements(
            (int) $order_id
        );
    }

    update_option(
        'msh_movements_backfill_v1',
        'yes'
    );
}

add_action(
    'admin_init',
    'msh_maybe_backfill_movements'
);




/* =========================================================
   SİPARİŞ RAPORLAMA MODÜLÜ
   ========================================================= */

/*
|--------------------------------------------------------------------------
| YETKİ KONTROLÜ
|--------------------------------------------------------------------------
*/

function sy_kullanici_siparisleri_gorebilir() {

    return is_user_logged_in()
        && current_user_can('manage_woocommerce');
}


/*
|--------------------------------------------------------------------------
| WOOCOMMERCE SİPARİŞLER ENDPOINT'İNİ DEĞİŞTİR
|--------------------------------------------------------------------------
*/

function sy_siparisler_endpoint_degistir() {

    remove_action(
        'woocommerce_account_orders_endpoint',
        'woocommerce_account_orders',
        10
    );

    add_action(
        'woocommerce_account_orders_endpoint',
        'sy_hesabim_siparisler_icerigi',
        10
    );
}

add_action(
    'wp_loaded',
    'sy_siparisler_endpoint_degistir',
    20
);


/*
|--------------------------------------------------------------------------
| HESABIM > SİPARİŞLER İÇERİĞİ
|--------------------------------------------------------------------------
*/

function sy_hesabim_siparisler_icerigi() {

    if (!sy_kullanici_siparisleri_gorebilir()) {
        echo '<p>Bu alanı görüntüleme yetkiniz bulunmuyor.</p>';
        return;
    }

    echo uy_siparis_liste_html();
}


/*
|--------------------------------------------------------------------------
| SİPARİŞ DURUMU CSS SINIFI
|--------------------------------------------------------------------------
*/

function sy_status_class($status) {

    $status = sanitize_html_class($status);

    return $status !== ''
        ? ' sy-status-' . $status
        : '';
}


/*
|--------------------------------------------------------------------------
| SİPARİŞ ÜRÜNLERİ
|--------------------------------------------------------------------------
*/

function sy_order_product_names($order) {

    if (!$order instanceof WC_Order) {
        return '-';
    }

    $items = $order->get_items('line_item');

    if (empty($items)) {
        return '-';
    }

    $product_names = [];

    foreach ($items as $item) {

        if (!$item instanceof WC_Order_Item_Product) {
            continue;
        }

        $name     = $item->get_name();
        $quantity = (int) $item->get_quantity();

        if ($quantity > 1) {
            $name .= ' × ' . $quantity;
        }

        $product_names[] = $name;
    }

    if (empty($product_names)) {
        return '-';
    }

    return implode(', ', $product_names);
}




/*
|--------------------------------------------------------------------------
| SİPARİŞ LİSTESİ
|--------------------------------------------------------------------------
*/

function uy_siparis_liste_html() {

    if (!class_exists('WooCommerce')) {
        return '<p>WooCommerce yüklü değil.</p>';
    }

    if (!sy_kullanici_siparisleri_gorebilir()) {
        return '<p>Bu alanı görüntüleme yetkiniz bulunmuyor.</p>';
    }

    wp_enqueue_style('msh-style');
    wp_enqueue_script('msh-script');

    /*
     * Mağazadaki son 50 siparişi getirir.
     * WooCommerce HPOS ile uyumludur.
     */
    $orders = wc_get_orders([
        'limit'   => 50,
        'orderby' => 'date',
        'order'   => 'DESC',
        'return'  => 'objects',
    ]);

    ob_start();

    echo '<div class="sy-order-report">';

    /*
     * BİLGİLENDİRME KUTUSU
     */
    echo '
        <div class="sy-info-box">

            <p>
                <strong>(1) Hakediş:</strong>
                Sipariş içerisindeki ürünlerin WooCommerce tarafından hesaplanan KDV hariç satış tutarları üzerinden <?php echo esc_html(msh_percent_label(msh_entitlement_rate())); ?>% hakediş oranı ile hesaplanır. Ürünlerin KDV oranları vergi sınıflarına göre değişiklik gösterebilir. Kesin hakediş tutarı Hesap Hareketleri sayfasında gösterilecektir. Magazify hakediş oranlarını değiştirme hakkını saklı tutar.
            </p>

            <p>
                <strong>(2) Gönderim:</strong>
                Gönderim maliyeti minimum sipariş tutarına ve farklı tedarikçilerden gönderilecek ürünler sebebiyle oluşacak olan maliyete göre değişkenlik göstermektedir. Minimum sepet tutarı üzerinde müşterilerden kargo masrafı alınmamaktadır. Toplam gönderim maliyeti Hesap Hareketleri sayfasında detaylıca gösterilecek ve toplam maliyete yansıtılacaktır.
            </p>

        </div>
    ';

    /*
     * BOŞ SİPARİŞ DURUMU
     */
    if (empty($orders)) {

        echo '
            <div class="sy-empty-state">
                Henüz görüntülenecek bir sipariş bulunmuyor.
            </div>
        ';

        echo '</div>';

        return ob_get_clean();
    }

    /*
     * TABLO KAPSAYICISI
     */
    echo '<div class="sy-table-scroll">';

    /*
     * TABLO BAŞLIĞI
     *
     * Sıralama:
     * Sipariş Tarihi
     * Sipariş No
     * Durum
     * Müşteri Adı
     * Ürünler
     * Gönderim (2)
     * Hakediş (1)
     * KDV Hariç
     * KDV
     * Toplam Satış
     */
    echo '
        <div class="sy-header">

            <div class="sy-hd-date">Sipariş Tarihi</div>
            <div class="sy-hd-id">Sipariş No</div>
            <div class="sy-hd-status">Durum</div>
            <div class="sy-hd-customer">Müşteri Adı</div>
            <div class="sy-hd-product">Ürünler</div>
            <div class="sy-hd-ship">Gönderim (2)</div>
            <div class="sy-hd-hakedis">Hakediş (1)</div>
            <div class="sy-hd-tax-exclusive">KDV Hariç</div>
            <div class="sy-hd-tax">KDV</div>
            <div class="sy-hd-total">Toplam Satış</div>

        </div>
    ';

    /*
     * SİPARİŞLER
     */
    foreach ($orders as $order) {

        if (!$order instanceof WC_Order) {
            continue;
        }

        $order_id = $order->get_id();

        /* Sipariş tarihi */
        $date_created = $order->get_date_created();

        $date = $date_created
            ? $date_created->date_i18n('d.m.Y')
            : '-';

        /* Sipariş durumu */
        $status_slug = $order->get_status();
        $status      = wc_get_order_status_name($status_slug);
        $status_class = sy_status_class($status_slug);

        /* Müşteri adı */
        $customer = trim(
            $order->get_billing_first_name()
            . ' '
            . $order->get_billing_last_name()
        );

        if ($customer === '') {
            $customer = 'Misafir müşteri';
        }

        /* Ürün adları */
        $product_names = sy_order_product_names($order);

        /* Ürünlerin gerçek KDV, KDV hariç ve hakediş toplamları */
        $financials = sy_order_financial_totals($order);

        /*
         * Gönderim sütununda müşteriden tahsil edilen KDV hariç
         * gönderim bedeli gösterilir. Gönderim vergisi, ürün KDV'sine
         * karıştırılmaz.
         */
        $shipping = (float) $order->get_shipping_total();

        /*
         * Müşterinin ödediği sipariş genel toplamıdır.
         * Ürünler, ürün KDV'leri, gönderim ve varsa gönderim vergisi,
         * ücretler ve indirimlerin siparişteki nihai sonucunu gösterir.
         */
        $total = (float) $order->get_total();

        echo '<div class="sy-order-item">';

        echo '
            <div class="sy-col-date" data-label="Sipariş Tarihi">
                ' . esc_html($date) . '
            </div>
        ';

        echo '
            <div class="sy-col-id" data-label="Sipariş No">
                #' . esc_html($order_id) . '
            </div>
        ';

        echo '
            <div
                class="sy-col-status' . esc_attr($status_class) . '"
                data-label="Durum"
            >
                ' . esc_html($status) . '
            </div>
        ';

        echo '
            <div class="sy-col-customer" data-label="Müşteri Adı">
                ' . esc_html($customer) . '
            </div>
        ';

        echo '
            <div class="sy-col-product" data-label="Ürünler">
                ' . esc_html($product_names) . '
            </div>
        ';

        echo '
            <div class="sy-col-ship" data-label="Gönderim (2)">
                ' . wp_kses_post(wc_price($shipping)) . '
            </div>
        ';

        echo '
            <div class="sy-col-hakedis" data-label="Hakediş (1)">
                ' . wp_kses_post(wc_price($financials['entitlement'])) . '
            </div>
        ';

        echo '
            <div class="sy-col-tax-exclusive" data-label="KDV Hariç">
                ' . wp_kses_post(wc_price($financials['tax_exclusive'])) . '
            </div>
        ';

        echo '
            <div class="sy-col-tax" data-label="KDV">
                ' . wp_kses_post(wc_price($financials['tax'])) . '
            </div>
        ';

        echo '
            <div class="sy-col-total" data-label="Toplam Satış">
                ' . wp_kses_post(wc_price($total)) . '
            </div>
        ';

        echo '</div>';
    }

    echo '</div>';
    echo '</div>';

    return ob_get_clean();
}


/*
|--------------------------------------------------------------------------
| YÖNETİM PANELİ SAYFASI
|--------------------------------------------------------------------------
*/

function uy_siparis_sayfasi() {

    if (!current_user_can('manage_woocommerce')) {
        wp_die('Bu alanı görüntüleme yetkiniz bulunmuyor.');
    }

    echo '<div class="wrap sy-admin-wrap">';
    echo '<h1>Sipariş Yönetimi</h1>';
    echo uy_siparis_liste_html();
    echo '</div>';
}


/*
|--------------------------------------------------------------------------
| SHORTCODE: [siparis-yonetim]
|--------------------------------------------------------------------------
*/

add_shortcode(
    'siparis-yonetim',
    function () {

        if (!is_user_logged_in()) {
            return '<p>Bu alanı görüntülemek için giriş yapmanız gerekiyor.</p>';
        }

        if (!current_user_can('manage_woocommerce')) {
            return '<p>Bu alanı görüntüleme yetkiniz yok.</p>';
        }

        return uy_siparis_liste_html();
    }
);


/* =========================================================
   HESAP HAREKETLERİ MODÜLÜ
   ========================================================= */

/* ---------------------------------------------------------
   PARA VE TARİH YARDIMCILARI
--------------------------------------------------------- */

function hh_now_mysql() {
    return current_time('mysql');
}

function hh_order_date_mysql(WC_Order $order) {
    $date = $order->get_date_created();
    return $date ? $date->date('Y-m-d H:i:s') : hh_now_mysql();
}


/* ---------------------------------------------------------
   OTOMATİK GÖNDERİM KATKISI
--------------------------------------------------------- */
function hh_calculate_shipping_share(WC_Order $order) {
    $order_total = hh_decimal($order->get_total());

    /*
     * 1.500 TL ve altındaki siparişlerde Mağaza Ortağına
     * gönderim maliyeti yansıtılmaz.
     */
    if ($order_total <= msh_free_shipping_threshold()) {
        return 0.0;
    }

    /*
     * 1.500 TL üzerindeki siparişlerde 99 TL referans
     * gönderim maliyetinin %50'si yansıtılır.
     */
    return hh_decimal(
        msh_reference_shipping_cost()
        * msh_partner_shipping_share_rate()
    );
}


/* ---------------------------------------------------------
   HAREKET EKLE/GÜNCELLE
--------------------------------------------------------- */
function hh_upsert_order_movement($order_id, $movement_type, $amount, $description, $movement_date) {
    global $wpdb;

    $table_name = hh_table_name();
    $existing_id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM {$table_name} WHERE order_id = %d AND movement_type = %s LIMIT 1",
            $order_id,
            $movement_type
        )
    );

    $data = [
        'order_id'      => $order_id,
        'movement_type' => $movement_type,
        'amount'        => hh_decimal($amount),
        'description'   => sanitize_text_field($description),
        'movement_date' => $movement_date,
        'is_manual'     => 0,
        'updated_at'    => hh_now_mysql(),
    ];

    if ($existing_id) {
        $wpdb->update(
            $table_name,
            $data,
            ['id' => (int) $existing_id],
            ['%d', '%s', '%f', '%s', '%s', '%d', '%s'],
            ['%d']
        );
        return (int) $existing_id;
    }

    $data['created_at'] = hh_now_mysql();
    $wpdb->insert(
        $table_name,
        $data,
        ['%d', '%s', '%f', '%s', '%s', '%d', '%s', '%s']
    );

    return (int) $wpdb->insert_id;
}

function hh_sync_order_movements($order_id) {
    $order = wc_get_order($order_id);

    if (!$order instanceof WC_Order) {
        return;
    }

    $allowed_statuses = ['processing', 'completed'];
    if (!in_array($order->get_status(), $allowed_statuses, true)) {
        return;
    }

    $finance = hh_calculate_order_finance($order);
    $date    = hh_order_date_mysql($order);

    hh_upsert_order_movement(
        $order->get_id(),
        'order_entitlement',
        $finance['entitlement'],
        'Sipariş #' . $order->get_id() . ' hakedişi',
        $date
    );

    /*
     * Gönderim katkısı otomatik hesaplanır.
     */
    $shipping_share = hh_calculate_shipping_share($order);

    $shipping_description = $shipping_share > 0
        ? 'Sipariş #' . $order->get_id() . ' gönderim maliyeti payı'
        : 'Sipariş #' . $order->get_id() . ' gönderim maliyeti Magazify tarafından karşılandı';

    hh_upsert_order_movement(
        $order->get_id(),
        'shipping_share',
        -abs($shipping_share),
        $shipping_description,
        $date
    );
}


/* ---------------------------------------------------------
   HAREKETLERİ GETİR
--------------------------------------------------------- */
function hh_get_movements() {
    global $wpdb;

    $table_name = hh_table_name();

    return $wpdb->get_results(
        "SELECT * FROM {$table_name} ORDER BY movement_date DESC, id DESC",
        ARRAY_A
    );
}

function hh_get_summary() {
    global $wpdb;

    $table_name = hh_table_name();

    $entitlement = (float) $wpdb->get_var(
        "SELECT COALESCE(SUM(amount), 0) FROM {$table_name} WHERE movement_type = 'order_entitlement'"
    );

    $shipping = (float) $wpdb->get_var(
        "SELECT COALESCE(SUM(amount), 0) FROM {$table_name} WHERE movement_type = 'shipping_share'"
    );

    $payments = (float) $wpdb->get_var(
        "SELECT COALESCE(SUM(amount), 0) FROM {$table_name} WHERE movement_type = 'manual_payment'"
    );

    return [
        'entitlement' => hh_decimal($entitlement),
        'shipping'    => hh_decimal(abs($shipping)),
        'payments'    => hh_decimal(abs($payments)),
        'balance'     => hh_decimal($entitlement + $shipping + $payments),
    ];
}

/* ---------------------------------------------------------
   MANUEL MAĞAZA ORTAĞI ÖDEME EKLE
--------------------------------------------------------- */
function hh_add_manual_payment() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die('Bu işlemi yapma yetkiniz yok.');
    }

    check_admin_referer('hh_add_payment', 'hh_nonce');

    $amount = isset($_POST['amount'])
        ? hh_decimal(wp_unslash($_POST['amount']))
        : 0;

    $description = isset($_POST['description'])
        ? sanitize_text_field(wp_unslash($_POST['description']))
        : 'Mağaza ortağı ödemesi';

    $payment_date = isset($_POST['payment_date'])
        ? sanitize_text_field(wp_unslash($_POST['payment_date']))
        : '';

    if ($amount <= 0) {
        wp_safe_redirect(add_query_arg('hh_error', 'invalid_amount', wp_get_referer()));
        exit;
    }

    $date = $payment_date !== ''
        ? $payment_date . ' ' . current_time('H:i:s')
        : hh_now_mysql();

    global $wpdb;
    $wpdb->insert(
        hh_table_name(),
        [
            'order_id'      => null,
            'movement_type' => 'manual_payment',
            'amount'        => -abs($amount),
            'description'   => $description !== '' ? $description : 'Mağaza ortağı ödemesi',
            'movement_date' => $date,
            'created_by'    => get_current_user_id(),
            'is_manual'     => 1,
            'created_at'    => hh_now_mysql(),
            'updated_at'    => hh_now_mysql(),
        ],
        ['%d', '%s', '%f', '%s', '%s', '%d', '%d', '%s', '%s']
    );

    wp_safe_redirect(add_query_arg('hh_updated', 'payment_added', wp_get_referer()));
    exit;
}
add_action('admin_post_hh_add_manual_payment', 'hh_add_manual_payment');

/* ---------------------------------------------------------
   MANUEL ÖDEME SİL
---------------------------------------------------------
   JavaScript/AJAX bağımlılığı olmadan normal ve güvenli
   WordPress POST işlemiyle çalışır.
--------------------------------------------------------- */
function hh_delete_manual_payment() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die('Bu işlemi yapma yetkiniz yok.');
    }

    check_admin_referer(
        'hh_delete_manual_payment',
        'hh_delete_nonce'
    );

    $movement_id = isset($_POST['movement_id'])
        ? absint(wp_unslash($_POST['movement_id']))
        : 0;

    $redirect_url = admin_url('admin.php?page=hesap-hareketleri');

    if (!$movement_id) {
        wp_safe_redirect(
            add_query_arg('hh_error', 'invalid_movement', $redirect_url)
        );
        exit;
    }

    global $wpdb;
    $table_name = hh_table_name();

    $movement = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id, movement_type, is_manual
             FROM {$table_name}
             WHERE id = %d
             LIMIT 1",
            $movement_id
        ),
        ARRAY_A
    );

    if (
        !$movement ||
        $movement['movement_type'] !== 'manual_payment' ||
        (int) $movement['is_manual'] !== 1
    ) {
        wp_safe_redirect(
            add_query_arg(
                'hh_error',
                'movement_not_deletable',
                $redirect_url
            )
        );
        exit;
    }

    $deleted = $wpdb->delete(
        $table_name,
        ['id' => $movement_id],
        ['%d']
    );

    if ($deleted === false || $deleted === 0) {
        wp_safe_redirect(
            add_query_arg('hh_error', 'delete_failed', $redirect_url)
        );
        exit;
    }

    wp_safe_redirect(
        add_query_arg('hh_updated', 'payment_deleted', $redirect_url)
    );
    exit;
}
add_action(
    'admin_post_hh_delete_manual_payment',
    'hh_delete_manual_payment'
);

/* ---------------------------------------------------------
   HAREKET ETİKETLERİ
--------------------------------------------------------- */
function hh_movement_label($type) {
    $labels = [
        'order_entitlement' => 'Sipariş Hakedişi',
        'shipping_share'    => 'Gönderim',
        'manual_payment'    => 'Ödeme',
    ];

    return $labels[$type] ?? 'Hareket';
}

function hh_movement_class($type) {
    $classes = [
        'order_entitlement' => 'hh-type-entitlement',
        'shipping_share'    => 'hh-type-shipping',
        'manual_payment'    => 'hh-type-payment',
    ];

    return $classes[$type] ?? '';
}

/* ---------------------------------------------------------
   ÖZET TUTAR GÖSTERİMİ
--------------------------------------------------------- */
function hh_summary_amount_html($amount, $force_negative = false) {

    $amount = (float) $amount;

    $is_negative = $force_negative || $amount < 0;

    $formatted = number_format(
        abs($amount),
        2,
        ',',
        '.'
    );

    $class = 'hh-summary-amount';

    if ($is_negative) {
        $class .= ' is-negative';
    }

    return sprintf(
        '<span class="%1$s">%2$s TL</span>',
        esc_attr($class),
        esc_html($formatted)
    );
}


/* ---------------------------------------------------------
   ORTAK EKRAN ÇIKTISI
--------------------------------------------------------- */
function hh_render_screen($is_admin = false) {
    if (!class_exists('WooCommerce')) {
        return '<p>WooCommerce yüklü değil.</p>';
    }

    /*
     * Eski sürümlerde kalmış, artık WooCommerce'te karşılığı olmayan
     * otomatik sipariş hareketlerini temizle.
     */
    msh_cleanup_orphaned_order_movements();

    if (!hh_can_view_movements()) {
        return '<p>Bu alanı görüntüleme yetkiniz yok.</p>';
    }

    if (!$is_admin) {
        wp_enqueue_style('msh-style');
        wp_enqueue_script('msh-script');
    }

    $movements = hh_get_movements();
    $summary   = hh_get_summary();

    ob_start();
    ?>

    <div class="hh-wrapper<?php echo $is_admin ? ' hh-admin-view' : ''; ?>">

        <div class="hh-summary">
            <div class="hh-summary-card">
                <span class="hh-summary-title">Mağaza Ortağı Toplam Hakedişi</span>
                <strong><?php
                    echo wp_kses_post(
                        hh_summary_amount_html(
                            $summary['entitlement']
                        )
                    );
                ?></strong>
            </div>

            <div class="hh-summary-card">
                <span class="hh-summary-title">Mağaza Ortağı Toplam Gönderim Maliyeti</span>
                <strong><?php
                    echo wp_kses_post(
                        hh_summary_amount_html(
                            $summary['shipping'],
                            true
                        )
                    );
                ?></strong>
            </div>

            <div class="hh-summary-card">
                <span class="hh-summary-title">Mağaza Ortağı Toplam Ödemesi</span>
                <strong><?php
                    echo wp_kses_post(
                        hh_summary_amount_html(
                            $summary['payments'],
                            true
                        )
                    );
                ?></strong>
            </div>

            <div class="hh-summary-card hh-summary-balance">
                <span class="hh-summary-title">Mağaza Ortağı Kalan Bakiye</span>
                <strong><?php
                    echo wp_kses_post(
                        hh_summary_amount_html(
                            $summary['balance']
                        )
                    );
                ?></strong>
            </div>
        </div>

        <div class="hh-info-box">
            <div class="hh-info-line">
                <strong>(1)</strong>
                <span><?php echo wp_kses_post(wc_price(msh_free_shipping_threshold())); ?> ve altındaki siparişlerde müşteriden alınan gönderim bedeli <?php echo wp_kses_post(wc_price(msh_reference_shipping_cost())); ?> olarak kabul edilir. Gönderim masrafı referans gönderim maliyetinin üzerinde gerçekleşse de üzerindeki tutar Magazify tarafından karşılanır ve Mağaza Ortağına bir maliyet yansıtılmaz.</span>
            </div>

            <div class="hh-info-line">
                <strong>(2)</strong>
                <span><?php echo wp_kses_post(wc_price(msh_free_shipping_threshold())); ?> üzerindeki siparişlerde müşteriden gönderim bedeli alınmaz. Gönderim masrafı <?php echo wp_kses_post(wc_price(msh_reference_shipping_cost())); ?> olarak kabul edilerek Magazify ile Mağaza Ortağı arasında paylaşılır ve bu maliyetin %<?php echo esc_html(msh_percent_label(msh_partner_shipping_share_rate())); ?> oranındaki <?php echo wp_kses_post(wc_price(msh_reference_shipping_cost() * msh_partner_shipping_share_rate())); ?> kısmı Mağaza Ortağına gönderim maliyeti olarak yansıtılır. Gerçek gönderim masrafının referans gönderim maliyetinin üzerindeki kısmı Magazify tarafından karşılanır.</span>
            </div>

            <div class="hh-info-line">
                <strong>(3)</strong>
                <span>Sipariş tutarından bağımsız olarak, birden çok tedarikçiden yapılacak gönderimler sebebiyle oluşan ilave gönderim maliyetleri Magazify tarafından karşılanır. Magazify gönderim politikasını şeffaf ve anlaşılabilir biçimde değiştirme hakkını saklı tutar.</span>
            </div>

            <div class="hh-info-line">
                <strong>(4)</strong>
                <span>Ürünlerin iade edilmesi veya tekrar gönderilmesi durumunda oluşacak gönderim maliyetleri Magazify tarafından karşılanır. Magazify gönderim politikasını şeffaf ve anlaşılabilir biçimde değiştirme hakkını saklı tutar.</span>
            </div>
        </div>

        <?php if ($is_admin) : ?>

            <div class="hh-admin-form-box">
                <h2>Mağaza Ortağı Ödeme Ekle</h2>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="hh-payment-form">
                    <input type="hidden" name="action" value="hh_add_manual_payment">
                    <?php wp_nonce_field('hh_add_payment', 'hh_nonce'); ?>

                    <div class="hh-form-field">
                        <label for="hh-payment-amount">Ödeme Tutarı</label>
                        <input id="hh-payment-amount" type="number" name="amount" min="0.01" step="0.01" required>
                    </div>

                    <div class="hh-form-field">
                        <label for="hh-payment-date">Ödeme Tarihi</label>
                        <input id="hh-payment-date" type="date" name="payment_date" value="<?php echo esc_attr(current_time('Y-m-d')); ?>" required>
                    </div>

                    <div class="hh-form-field hh-form-field-wide">
                        <label for="hh-payment-description">Açıklama</label>
                        <input id="hh-payment-description" type="text" name="description" value="Mağaza ortağı ödemesi" maxlength="250">
                    </div>

                    <button type="submit" class="button button-primary hh-add-payment">Mağaza Ortağı Ödeme Ekle</button>
                </form>
            </div>

        <?php endif; ?>

        <div class="hh-table-scroll">
            <div class="hh-header">
                <div>Tarih</div>
                <div>Hareket</div>
                <div>Sipariş</div>
                <div>Açıklama</div>
                <div>Tutar</div>
                <?php if ($is_admin) : ?>
                    <div>İşlem</div>
                <?php endif; ?>
            </div>

            <?php if (empty($movements)) : ?>
                <div class="hh-empty-state">Henüz hesap hareketi bulunmuyor.</div>
            <?php else : ?>
                <?php foreach ($movements as $movement) : ?>
                    <?php
                    $type_class = hh_movement_class($movement['movement_type']);
                    $amount     = (float) $movement['amount'];
                    $is_credit  = $amount >= 0;
                    ?>

                    <div class="hh-row <?php echo esc_attr($type_class); ?>" data-movement-id="<?php echo esc_attr($movement['id']); ?>">
                        <div data-label="Tarih">
                            <?php echo esc_html(wp_date('d.m.Y', strtotime($movement['movement_date']))); ?>
                        </div>

                        <div data-label="Hareket">
                            <strong><?php echo esc_html(hh_movement_label($movement['movement_type'])); ?></strong>
                        </div>

                        <div data-label="Sipariş">
                            <?php echo $movement['order_id'] ? '#' . esc_html($movement['order_id']) : '—'; ?>
                        </div>

                        <div data-label="Açıklama">
                            <?php echo esc_html($movement['description']); ?>
                        </div>

                        <div data-label="Tutar" class="hh-amount <?php echo $is_credit ? 'hh-credit' : 'hh-debit'; ?>">
                            <?php echo $is_credit ? '+' : '-'; ?><?php echo wp_kses_post(wc_price(abs($amount))); ?>
                        </div>

                        <?php if ($is_admin) : ?>
                            <div data-label="İşlem" class="hh-actions">

                                <?php if ($movement['movement_type'] === 'manual_payment') : ?>
                                    <form
                                        method="post"
                                        action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                        class="hh-delete-payment-form"
                                        onsubmit="return window.confirm('Bu ödeme hareketini silmek istediğinize emin misiniz?');"
                                    >
                                        <input
                                            type="hidden"
                                            name="action"
                                            value="hh_delete_manual_payment"
                                        >

                                        <input
                                            type="hidden"
                                            name="movement_id"
                                            value="<?php echo esc_attr($movement['id']); ?>"
                                        >

                                        <?php
                                        wp_nonce_field(
                                            'hh_delete_manual_payment',
                                            'hh_delete_nonce'
                                        );
                                        ?>

                                        <button
                                            type="submit"
                                            class="button button-secondary hh-delete-payment-submit"
                                        >
                                            Sil
                                        </button>
                                    </form>

                                <?php else : ?>
                                    <span class="hh-locked">Sistem kaydı</span>
                                <?php endif; ?>

                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </div>

    <?php
    return ob_get_clean();
}

/* ---------------------------------------------------------
   ADMIN SAYFASI
--------------------------------------------------------- */
function hh_admin_page() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die('Bu alanı görüntüleme yetkiniz yok.');
    }

    $updated = isset($_GET['hh_updated'])
        ? sanitize_text_field(wp_unslash($_GET['hh_updated']))
        : '';

    $error = isset($_GET['hh_error'])
        ? sanitize_text_field(wp_unslash($_GET['hh_error']))
        : '';

    echo '<div class="wrap">';
    echo '<h1>Hesap Hareketleri</h1>';

    if ($updated === 'payment_added') {
        echo '<div class="notice notice-success is-dismissible"><p>Ödeme hareketi eklendi.</p></div>';
    } elseif ($updated === 'payment_deleted') {
        echo '<div class="notice notice-success is-dismissible"><p>Ödeme hareketi silindi.</p></div>';
    }

    if ($error !== '') {
        $error_messages = [
            'invalid_amount'         => 'Geçerli bir ödeme tutarı giriniz.',
            'invalid_movement'       => 'Geçersiz hareket kaydı.',
            'movement_not_deletable' => 'Yalnızca manuel ödeme hareketleri silinebilir.',
            'delete_failed'          => 'Ödeme hareketi silinemedi.',
        ];

        $message = isset($error_messages[$error])
            ? $error_messages[$error]
            : 'İşlem sırasında bir hata oluştu.';

        echo '<div class="notice notice-error is-dismissible"><p>'
            . esc_html($message)
            . '</p></div>';
    }

    echo hh_render_screen(true);
    echo '</div>';
}

/* ---------------------------------------------------------
   SHORTCODE: [hesap-hareketleri]
--------------------------------------------------------- */
function hh_shortcode() {
    return hh_render_screen(false);
}
add_shortcode('hesap-hareketleri', 'hh_shortcode');
