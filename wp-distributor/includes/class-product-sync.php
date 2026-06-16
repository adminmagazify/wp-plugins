<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Merkezden gelen ürün verisini WooCommerce'e yazar.
 * Beden varyasyonu varsa "variable product", yoksa "simple product" oluşturur.
 * SKU "central-{id}" ile tekrar gönderimde günceller, çoğaltmaz.
 */
class WPD_Product_Sync {

    public static function upsert($item) {
        if (!class_exists('WC_Product_Simple')) {
            throw new Exception('WooCommerce aktif değil');
        }
        if (empty($item['id']) || empty($item['name'])) {
            throw new Exception('Eksik ürün verisi');
        }

        $sku = 'central-' . intval($item['id']);
        $existing_id = wc_get_product_id_by_sku($sku);

        $variations = (isset($item['variations']) && is_array($item['variations'])) ? $item['variations'] : [];
        $has_variations = count($variations) > 0;

        // Ürün tipi değiştiyse (simple <-> variable) eskisini sil, temiz başla
        if ($existing_id) {
            $existing = wc_get_product($existing_id);
            $was_variable = $existing && $existing->is_type('variable');
            if ($was_variable !== $has_variations) {
                wp_delete_post($existing_id, true);
                $existing_id = 0;
            }
        }

        // Sıfırdan oluşturulacaksa bu SKU'yu tutan öksüz/çöp/bayat kayıtları temizle
        // (wc_get_product_id_by_sku'nun bulamadığı artıkların duplicate-SKU hatasını önler)
        if (!$existing_id) {
            self::purge_sku_family($sku);
        }

        if ($has_variations) {
            $product_id = self::upsert_variable($existing_id, $sku, $item, $variations);
        } else {
            $product_id = self::upsert_simple($existing_id, $sku, $item);
        }

        self::sync_images($product_id, $item);

        // Marka — WooCommerce'in resmi "Markalar" taksonomisine (product_brand) ata
        if (isset($item['brand'])) {
            self::assign_brand_term($product_id, $item['brand']);
        }

        // Beden tablosu
        if (isset($item['sizeChartHtml'])) {
            update_post_meta($product_id, '_wpd_size_chart', wp_kses_post($item['sizeChartHtml']));
        }

        return $product_id;
    }

    /**
     * Markayı WooCommerce'in "Markalar" taksonomisine yazar (kategori gibi).
     * Taksonomi WC sürümüne göre 'product_brand' (resmi) veya eski eklenti slug'ı olabilir.
     * Marka boşsa ürünün mevcut markalarını temizler.
     */
    protected static function assign_brand_term($product_id, $brand) {
        $taxonomy = self::detect_brand_taxonomy();
        if (!$taxonomy) {
            return; // Sitede marka taksonomisi yoksa sessizce geç (attribute zaten yazıldı)
        }

        $brand = sanitize_text_field($brand);
        if ($brand === '') {
            wp_set_object_terms($product_id, [], $taxonomy);
            return;
        }

        $term = get_term_by('name', $brand, $taxonomy);
        if (!$term) {
            $created = wp_insert_term($brand, $taxonomy);
            if (is_wp_error($created)) {
                return;
            }
            $term_id = $created['term_id'];
        } else {
            $term_id = $term->term_id;
        }
        wp_set_object_terms($product_id, [(int) $term_id], $taxonomy, false);
    }

    /** Sitede aktif marka taksonomisinin adını döndürür (yoksa boş). */
    protected static function detect_brand_taxonomy() {
        foreach (['product_brand', 'pwb-brand', 'yith_product_brand'] as $tax) {
            if (taxonomy_exists($tax)) {
                return $tax;
            }
        }
        return '';
    }

    /** Merkezdeki ID'ye karşılık gelen ürünü (varsa) WooCommerce'den kalıcı siler */
    public static function delete_by_central_id($id) {
        self::purge_sku_family('central-' . intval($id));
    }

    /**
     * Bu SKU'yu (central-{id}) ve varyasyon SKU'larını (central-{id}-*) tutan TÜM post'ları
     * — çöptekiler dahil — ve arama tablosu (wc_product_meta_lookup) artıklarını kalıcı temizler.
     * wc_get_product_id_by_sku'nun yakalayamadığı öksüz kayıtların yol açtığı
     * "duplicate SKU" çakışmalarını çözer.
     */
    public static function purge_sku_family($sku) {
        global $wpdb;
        $like = $wpdb->esc_like($sku . '-') . '%';
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND (meta_value = %s OR meta_value LIKE %s)",
            $sku,
            $like
        ));
        foreach ($ids as $pid) {
            wp_delete_post((int) $pid, true);
        }
        $table = $wpdb->prefix . 'wc_product_meta_lookup';
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE sku = %s OR sku LIKE %s", $sku, $like));
    }

    /** Ad, açıklama, durum, kategori, marka, kargo sınıfı — her iki ürün tipinde ortak */
    protected static function apply_base($product, $item) {
        $product->set_name(sanitize_text_field($item['name']));

        if (isset($item['description'])) {
            $product->set_description(wp_kses_post(nl2br($item['description'])));
        }

        if (isset($item['shortDescription'])) {
            $product->set_short_description(wp_kses_post(nl2br($item['shortDescription'])));
        }

        $product->set_status(!empty($item['active']) ? 'publish' : 'draft');

        if (!empty($item['categoryName'])) {
            $term_id = self::ensure_category($item['categoryName'], $item['categorySlug'] ?? '');
            if ($term_id) {
                $product->set_category_ids([$term_id]);
            }
        }

        // Kargo sınıfı — sitede yoksa WooCommerce'de oluştur, sonra ata
        if (!empty($item['shippingClass'])) {
            $term_id = self::ensure_shipping_class($item['shippingClass'], isset($item['shippingClassName']) ? $item['shippingClassName'] : '');
            if ($term_id) {
                $product->set_shipping_class_id($term_id);
            }
        }
    }

    /**
     * Gönderim sınıfı term'ini (product_shipping_class) slug'a göre bulur; yoksa oluşturur.
     * Ad merkezden gelirse onu kullanır (örn. "Ücretsiz Gönderim"), yoksa slug'ı ad yapar.
     */
    protected static function ensure_shipping_class($slug, $name) {
        $slug = sanitize_title($slug);
        if ($slug === '') {
            return 0;
        }
        $term = get_term_by('slug', $slug, 'product_shipping_class');
        if ($term) {
            return $term->term_id;
        }
        $label = trim((string) $name) !== '' ? sanitize_text_field($name) : $slug;
        $created = wp_insert_term($label, 'product_shipping_class', ['slug' => $slug]);
        if (is_wp_error($created)) {
            // Yarış durumu: araya başka istek girip oluşturmuş olabilir
            $term = get_term_by('slug', $slug, 'product_shipping_class');
            return $term ? $term->term_id : 0;
        }
        return $created['term_id'];
    }

    /** Basit ürün (bedensiz) */
    protected static function upsert_simple($existing_id, $sku, $item) {
        $product = $existing_id ? wc_get_product($existing_id) : new WC_Product_Simple();
        $product->set_sku($sku);
        self::apply_base($product, $item);
        $product->set_regular_price((string) $item['price']);
        $product->set_manage_stock(true);
        $product->set_stock_quantity(intval($item['stock']));

        // Marka (custom attribute)
        if (!empty($item['brand'])) {
            self::set_brand_attribute($product, $item['brand']);
        }

        $product_id = $product->save();
        if (!$product_id) {
            throw new Exception('Ürün kaydedilemedi');
        }
        return $product_id;
    }

    /** Varyasyonlu ürün (bedenli) */
    protected static function upsert_variable($existing_id, $sku, $item, $variations) {
        $product = $existing_id ? wc_get_product($existing_id) : new WC_Product_Variable();
        if (!$product || !$product->is_type('variable')) {
            $product = new WC_Product_Variable();
        }
        $product->set_sku($sku);
        self::apply_base($product, $item);

        // Beden listesi (benzersiz, sıralı)
        $sizes = [];
        foreach ($variations as $v) {
            $s = sanitize_text_field($v['size']);
            if ($s !== '' && !in_array($s, $sizes, true)) {
                $sizes[] = $s;
            }
        }

        // "Beden" attribute'unu kur (Marka gibi diğer attribute'ları koru)
        $beden_attr = new WC_Product_Attribute();
        $beden_attr->set_name('Beden');
        $beden_attr->set_options($sizes);
        $beden_attr->set_position(0);
        $beden_attr->set_visible(true);
        $beden_attr->set_variation(true);

        $existing_attrs = array_filter($product->get_attributes(), function ($a) {
            return strtolower($a->get_name()) !== 'beden';
        });
        $all_attrs = array_values(array_merge(array_values($existing_attrs), [$beden_attr]));

        // Marka attribute'u ekle
        if (!empty($item['brand'])) {
            $brand_attr = new WC_Product_Attribute();
            $brand_attr->set_name('Marka');
            $brand_attr->set_options([sanitize_text_field($item['brand'])]);
            $brand_attr->set_position(1);
            $brand_attr->set_visible(true);
            $brand_attr->set_variation(false);
            $all_attrs = array_filter($all_attrs, function ($a) {
                return strtolower($a->get_name()) !== 'marka';
            });
            $all_attrs[] = $brand_attr;
        }

        $product->set_attributes(array_values($all_attrs));

        $product_id = $product->save();
        if (!$product_id) {
            throw new Exception('Ürün kaydedilemedi');
        }

        self::sync_variations($product_id, $sku, $item, $variations);

        // Parent'ı varyasyonlara göre senkronize et (stok durumu, fiyat aralığı)
        WC_Product_Variable::sync($product_id);

        return $product_id;
    }

    /** Beden varyasyonlarını oluştur/güncelle, kaldırılanları sil */
    protected static function sync_variations($product_id, $sku, $item, $variations) {
        $parent = wc_get_product($product_id);
        $existing_by_key = [];
        foreach ($parent->get_children() as $cid) {
            $cv = wc_get_product($cid);
            if (!$cv) {
                continue;
            }
            $attrs = $cv->get_attributes();
            $val = isset($attrs['beden']) ? $attrs['beden'] : '';
            $key = sanitize_title($val);
            if ($key !== '') {
                $existing_by_key[$key] = $cid;
            } else {
                wp_delete_post($cid, true);
            }
        }

        foreach ($variations as $v) {
            $size = sanitize_text_field($v['size']);
            if ($size === '') {
                continue;
            }
            $key = sanitize_title($size);

            $variation = isset($existing_by_key[$key])
                ? wc_get_product($existing_by_key[$key])
                : new WC_Product_Variation();

            $variation->set_parent_id($product_id);
            $variation->set_attributes(['beden' => $size]);
            $variation->set_sku($sku . '-' . $key);
            // Beden fiyatı varsa onu kullan, yoksa ürün fiyatı
            $var_price = (!empty($v['price']) && $v['price'] !== null) ? (string) $v['price'] : (string) $item['price'];
            $variation->set_regular_price($var_price);
            $variation->set_manage_stock(true);
            $variation->set_stock_quantity(intval($v['stock']));
            $variation->set_status('publish');
            $variation->save();

            unset($existing_by_key[$key]);
        }

        // Artık gelmeyen bedenleri sil
        foreach ($existing_by_key as $cid) {
            wp_delete_post($cid, true);
        }
    }

    protected static function set_brand_attribute($product, $brand) {
        $existing = $product->get_attributes();
        $brand_attr = new WC_Product_Attribute();
        $brand_attr->set_name('Marka');
        $brand_attr->set_options([sanitize_text_field($brand)]);
        $brand_attr->set_position(1);
        $brand_attr->set_visible(true);
        $brand_attr->set_variation(false);
        // Marka dışındaki attribute'ları koru (örn. Beden)
        $others = array_filter($existing, function ($a) {
            return strtolower($a->get_name()) !== 'marka';
        });
        $product->set_attributes(array_values(array_merge(array_values($others), [$brand_attr])));
    }

    protected static function ensure_category($name, $slug) {
        $slug = sanitize_title($slug ? $slug : $name);
        $term = get_term_by('slug', $slug, 'product_cat');
        if ($term) {
            return $term->term_id;
        }
        $created = wp_insert_term(sanitize_text_field($name), 'product_cat', ['slug' => $slug]);
        if (is_wp_error($created)) {
            return 0;
        }
        return $created['term_id'];
    }

    protected static function sync_images($product_id, $item) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $main_src = isset($item['mainImage']) ? $item['mainImage'] : '';
        if ($main_src && get_post_meta($product_id, '_wpd_main_src', true) !== $main_src) {
            $att_id = self::sideload($main_src, $product_id);
            if ($att_id) {
                set_post_thumbnail($product_id, $att_id);
                update_post_meta($product_id, '_wpd_main_src', $main_src);
            }
        }

        $gallery = (isset($item['gallery']) && is_array($item['gallery'])) ? $item['gallery'] : [];
        $gallery_key = md5(implode('|', $gallery));
        if (!empty($gallery) && get_post_meta($product_id, '_wpd_gallery_key', true) !== $gallery_key) {
            $att_ids = [];
            foreach ($gallery as $src) {
                $id = self::sideload($src, $product_id);
                if ($id) {
                    $att_ids[] = $id;
                }
            }
            if ($att_ids) {
                update_post_meta($product_id, '_product_image_gallery', implode(',', $att_ids));
                update_post_meta($product_id, '_wpd_gallery_key', $gallery_key);
            }
        }
    }

    protected static function sideload($url, $product_id) {
        $tmp = download_url($url, 20);
        if (is_wp_error($tmp)) {
            return 0;
        }
        $name = basename(wp_parse_url($url, PHP_URL_PATH));
        if (!$name) {
            $name = 'image-' . time() . '.jpg';
        }
        $file = ['name' => $name, 'tmp_name' => $tmp];
        $att_id = media_handle_sideload($file, $product_id);
        if (is_wp_error($att_id)) {
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
            return 0;
        }
        return $att_id;
    }
}
