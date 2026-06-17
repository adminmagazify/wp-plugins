<?php
if (!defined('ABSPATH')) {
    exit;
}
/** @var bool  $registered */
/** @var array $categories */
/** @var array $selected */
?>
<div class="wrap">
    <h1>Ürün Dağıtım Paneli</h1>
    <?php settings_errors('wpd'); ?>

    <?php if (!class_exists('WooCommerce')): ?>
        <div class="notice notice-error">
            <p><strong>WooCommerce aktif değil.</strong> Ürünlerin aktarılabilmesi için önce WooCommerce'i kurup etkinleştirin.</p>
        </div>
    <?php endif; ?>

    <h2>Bağlantı Durumu</h2>

    <form method="post">
        <?php wp_nonce_field('wpd_url'); ?>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="wpd_central_url">Merkez API Adresi</label></th>
                <td>
                    <input type="url" id="wpd_central_url" name="central_url" class="regular-text"
                        style="width:420px;"
                        value="<?php echo esc_attr(WPD_Api_Client::central_url()); ?>"
                        placeholder="https://merkez-adresiniz.com" />
                    <button type="submit" class="button" name="wpd_save_url" value="1">Adresi Kaydet</button>
                    <p class="description">Merkez panel adresiniz. Tünel adresi değişirse buraya yenisini yapıştırıp kaydedin.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Kayıt Durumu</th>
                <td>
                    <?php if ($registered): ?>
                        <span style="color:#1a7f37;font-weight:600;">✓ Kayıtlı</span>
                    <?php else: ?>
                        <span style="color:#b32d2e;font-weight:600;">Kayıtlı değil</span>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
    </form>

    <form method="post">
        <?php wp_nonce_field('wpd_register'); ?>
        <button type="submit" class="button button-primary" name="wpd_register" value="1">
            <?php echo $registered ? 'Yeniden Kaydol' : 'Merkeze Kaydol'; ?>
        </button>
    </form>

    <?php if ($registered): ?>
        <hr style="margin:30px 0;">
        <h2>Satmak İstediğiniz Kategoriler</h2>
        <p class="description">İşaretlediğiniz kategorilerdeki ürünler sitenize otomatik gönderilir.</p>

        <form method="post">
            <?php wp_nonce_field('wpd_categories'); ?>
            <?php if (empty($categories)): ?>
                <p>Kategori bulunamadı. (Merkezde henüz kategori yok ya da bağlantı kurulamadı.)</p>
            <?php else: ?>
                <ul style="margin:16px 0;">
                    <?php foreach ($categories as $cat): ?>
                        <li style="margin-bottom:8px;">
                            <label>
                                <input type="checkbox" name="categories[]"
                                    value="<?php echo intval($cat['id']); ?>"
                                    <?php checked(in_array((int) $cat['id'], array_map('intval', $selected), true)); ?> />
                                <?php echo esc_html($cat['name']); ?>
                            </label>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <button type="submit" class="button button-primary" name="wpd_save_categories" value="1">
                    Seçimi Kaydet
                </button>
            <?php endif; ?>
        </form>
    <?php endif; ?>

    <?php if ($registered && post_type_exists('ts_size_chart')): ?>
        <hr style="margin:30px 0;">
        <h2>Beden Tabloları</h2>
        <p class="description">
            Bu sitedeki mevcut beden tablolarını (Size Charts) merkez panele aktarır.
            Merkeze çektikten sonra panelden yönetip tüm sitelere dağıtabilirsiniz.
        </p>
        <form method="post" style="margin-top:10px;">
            <?php wp_nonce_field('wpd_pull_charts'); ?>
            <button type="submit" class="button button-secondary" name="wpd_pull_charts" value="1">
                Beden Tablolarını Merkeze Çek
            </button>
        </form>
    <?php endif; ?>
</div>
