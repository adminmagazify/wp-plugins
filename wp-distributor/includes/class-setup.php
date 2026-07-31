<?php
/**
 * WPD_Setup — Klon-kurulum: merkezden gelen "site kimliği"ni WordPress/WooCommerce ayarlarına uygular.
 *
 * Merkez panelde her bayi sitesi için kimlik (isim, e-posta, SMTP, logo, favicon, kullanıcı) tanımlanır;
 * tek tıkla push edildiğinde bu sınıf klonlanan sitede tüm programatik ayarları otomatik yazar.
 * Böylece klon sonrası ~50 manuel WP-admin adımının büyük kısmı ortadan kalkar.
 *
 * Ayrıca yasal sayfaları klonda hiç düzenlememek için [wpd_field key="..."] shortcode'unu kaydeder.
 */
if (!defined('ABSPATH')) {
    exit;
}

class WPD_Setup {

    const IDENTITY_OPTION = 'wpd_site_identity';   // kimlik ham verisi (shortcode/footer için)
    const SIDELOAD_MAP    = 'wpd_sideload_map';     // URL → attachment id (idempotent görsel indirme)

    private static $sideload_err = '';             // son sideload hatası (skipped raporu için)

    public static function init() {
        add_shortcode('wpd_field', [__CLASS__, 'field_shortcode']);
    }

    /**
     * Merkezden gelen kimlik dizisini WP/WooCommerce ayarlarına uygular.
     * $id: { siteName, tagline, contactEmail, websiteUrl, logoUrl, faviconUrl, copyrightText,
     *        social, legalCompany, orderEmailGlobal, stockEmailGlobal,
     *        smtp:{host,port,security,user,pass}, manager:{username,...,password}, resetOrders }
     * Dönüş: uygulanan/atlanan adımların özeti (REST results için).
     */
    public static function apply($id) {
        // Görsel indirme + attachment üretimi zaman alabilir → süre sınırını yükselt (best-effort;
        // paylaşımlı hostta yok sayılabilir ama çoğu yerde çalışır). timeout fatal'ı try/catch ile yakalanmaz.
        if (function_exists('set_time_limit')) { @set_time_limit(120); }
        $done = [];
        $skipped = [];

        // Kimliği sakla (shortcode/footer/yasal buradan okur). GÜVENLİK: şifreleri ÇIKAR
        // (düz metin parola DB'de kalmasın) + autoload='no' (her sayfada belleğe yüklenmesin).
        $store = $id;
        if (isset($store['smtp']['pass'])) unset($store['smtp']['pass']);
        if (isset($store['manager']['password'])) unset($store['manager']['password']);
        update_option(self::IDENTITY_OPTION, $store, 'no');

        // 1) Site başlığı / slogan
        if (!empty($id['siteName']))  { update_option('blogname', sanitize_text_field($id['siteName']));        $done[] = 'blogname'; }
        if (isset($id['tagline']))    { update_option('blogdescription', sanitize_text_field($id['tagline']));  $done[] = 'blogdescription'; }

        // 2) Yönetim e-postası (doğrudan — onay maili tetiklemez)
        if (!empty($id['contactEmail']) && is_email($id['contactEmail'])) {
            update_option('admin_email', sanitize_email($id['contactEmail']));
            $done[] = 'admin_email';
        }

        // 3) Favicon (site_icon) — gerçek attachment gerekir
        if (!empty($id['faviconUrl'])) {
            $fid = self::sideload($id['faviconUrl']);
            if ($fid) { update_option('site_icon', $fid); $done[] = 'site_icon'; }
            else { $skipped[] = 'site_icon(' . self::$sideload_err . ')'; }
        }

        // 4) Logo — hem WP standart custom_logo hem TEMA'nın kendi logo alanları (loobek gibi
        // temalar custom_logo yerine kendi option'unda URL tutar → görünen logo orası).
        if (!empty($id['logoUrl'])) {
            $lid = self::sideload($id['logoUrl']);
            if ($lid) {
                set_theme_mod('custom_logo', $lid);
                $done[] = 'custom_logo';
                $local = wp_get_attachment_url($lid);           // sitenin kendi uploads URL'i
                $tl = self::apply_theme_logo($local ? $local : $id['logoUrl']);
                if (!empty($tl)) { $done[] = 'theme_logo(' . implode(',', $tl) . ')'; }
                else { $skipped[] = 'theme_logo(tema logo alanı bulunamadı)'; }
            } else { $skipped[] = 'custom_logo(' . self::$sideload_err . ')'; }
        }

        // 4.5) Sosyal medya (Instagram vb.) — temanın kendi sosyal alanlarına yaz (logo mantığı)
        if (!empty($id['social']) && is_array($id['social'])) {
            $ts = self::apply_theme_social($id['social']);
            if (!empty($ts)) { $done[] = 'theme_social(' . implode(',', $ts) . ')'; }
            else { $skipped[] = 'theme_social(tema sosyal alanı bulunamadı)'; }
        }

        // 5) WooCommerce e-posta ayarları (yalnızca WooCommerce aktifse)
        if (class_exists('WooCommerce')) {
            $siteEmail  = !empty($id['contactEmail']) ? sanitize_email($id['contactEmail']) : '';
            $orderEmail = !empty($id['orderEmailGlobal']) ? sanitize_email($id['orderEmailGlobal']) : '';
            // Alıcı = global sipariş e-postası + site e-postası (ilki her sitede aynı, ikincisi kendi)
            $recipients = trim(implode(', ', array_filter([$orderEmail, $siteEmail])), ', ');
            if ($recipients !== '') {
                foreach (['new_order', 'cancelled_order', 'failed_order'] as $et) {
                    $okey = 'woocommerce_' . $et . '_settings';
                    $opt = get_option($okey, []);
                    if (!is_array($opt)) $opt = [];
                    $opt['recipient'] = $recipients;
                    update_option($okey, $opt);
                }
                $done[] = 'woocommerce_admin_recipients';
            }
            if ($siteEmail !== '')          { update_option('woocommerce_email_from_address', $siteEmail); $done[] = 'wc_from_address'; }
            if (!empty($id['siteName']))    { update_option('woocommerce_email_from_name', sanitize_text_field($id['siteName'])); $done[] = 'wc_from_name'; }
            if (!empty($id['stockEmailGlobal']) && is_email($id['stockEmailGlobal'])) {
                update_option('woocommerce_stock_email_recipient', sanitize_email($id['stockEmailGlobal']));
                $done[] = 'wc_stock_recipient';
            }
            if (!empty($id['logoUrl'])) { update_option('woocommerce_email_header_image', esc_url_raw($id['logoUrl'])); $done[] = 'wc_email_logo'; }
        } else {
            $skipped[] = 'woocommerce(pasif)';
        }

        // 6) WP Mail SMTP (option tabanlı yapılandırma)
        if (!empty($id['smtp']) && is_array($id['smtp']) && !empty($id['smtp']['host'])) {
            self::apply_smtp($id['smtp'], $id);
            $done[] = 'wp_mail_smtp';
        }

        // 7) Mağaza yöneticisi kullanıcı
        if (!empty($id['manager']) && is_array($id['manager']) && !empty($id['manager']['username'])) {
            $ures = self::ensure_manager($id['manager']);
            $done[] = 'manager_user(' . $ures . ')';
        }

        // 8) (opt-in) Eski WooCommerce siparişlerini temizle — klon temiz başlasın
        if (!empty($id['resetOrders']) && function_exists('wc_get_orders')) {
            $deleted = self::reset_orders();
            $done[] = 'reset_orders(' . $deleted . ')';
        }

        return ['done' => $done, 'skipped' => $skipped];
    }

    /** WP Mail SMTP option'ını günceller (mevcut yapıyı korur, sadece ilgili alanları yazar). */
    private static function apply_smtp($smtp, $id) {
        $opt = get_option('wp_mail_smtp', []);
        if (!is_array($opt)) $opt = [];
        $mail = isset($opt['mail']) && is_array($opt['mail']) ? $opt['mail'] : [];
        $mail = array_merge($mail, [
            'mailer'         => 'smtp',
            'from_email'     => !empty($id['contactEmail']) ? sanitize_email($id['contactEmail']) : ($mail['from_email'] ?? ''),
            'from_name'      => !empty($id['siteName']) ? sanitize_text_field($id['siteName']) : ($mail['from_name'] ?? ''),
            'from_email_force' => true,
            'from_name_force'  => true,
        ]);
        $sec = isset($smtp['security']) ? strtolower($smtp['security']) : 'ssl';
        if (!in_array($sec, ['ssl', 'tls', 'none'], true)) $sec = 'ssl';
        $s = isset($opt['smtp']) && is_array($opt['smtp']) ? $opt['smtp'] : [];
        $s = array_merge($s, [
            'host'       => sanitize_text_field($smtp['host']),
            'port'       => isset($smtp['port']) ? intval($smtp['port']) : 465,
            'encryption' => $sec,
            'autotls'    => true,
            'auth'       => true,
            'user'       => isset($smtp['user']) ? sanitize_text_field($smtp['user']) : '',
            'pass'       => isset($smtp['pass']) ? (string) $smtp['pass'] : ($s['pass'] ?? ''),
        ]);
        $opt['mail'] = $mail;
        $opt['smtp'] = $s;
        update_option('wp_mail_smtp', $opt);
    }

    /** Mağaza yöneticisi kullanıcısını oluşturur/günceller. Dönüş: created|updated|error. */
    private static function ensure_manager($m) {
        $login = sanitize_user($m['username'], true);
        if ($login === '') return 'error';
        $email = !empty($m['email']) && is_email($m['email']) ? sanitize_email($m['email']) : '';
        $existing = get_user_by('login', $login);
        if ($existing) {
            // GÜVENLİK: mevcut kullanıcı administrator/süper admin ise DOKUNMA — rolünü düşürmek
            // veya şifresini ezmek siteyi yönetimsel olarak kilitler. (Kullanıcı adı çakışması.)
            if (in_array('administrator', (array) $existing->roles, true) || is_super_admin($existing->ID)) {
                return 'skipped-admin';
            }
            // Rolü DEĞİŞTİRME (set_role tüm rolleri siler) → shop_manager'ı EKLE, mevcutları koru
            if (!in_array('shop_manager', (array) $existing->roles, true)) {
                $existing->add_role('shop_manager');
            }
            $upd = ['ID' => $existing->ID]; // 'role' anahtarı YOK → roller korunur
            if ($email) $upd['user_email'] = $email;
            if (!empty($m['firstName'])) $upd['first_name'] = sanitize_text_field($m['firstName']);
            if (!empty($m['lastName']))  $upd['last_name']  = sanitize_text_field($m['lastName']);
            if (!empty($m['website']))   $upd['user_url']   = esc_url_raw($m['website']);
            wp_update_user($upd);
            if (!empty($m['password'])) wp_set_password($m['password'], $existing->ID);
            return 'updated';
        }
        // Aynı e-posta başka kullanıcıdaysa e-postasız oluştur (çakışmayı önle)
        if ($email && email_exists($email)) $email = '';
        $uid = wp_insert_user([
            'user_login'   => $login,
            'user_pass'    => !empty($m['password']) ? $m['password'] : wp_generate_password(16),
            'user_email'   => $email,
            'first_name'   => isset($m['firstName']) ? sanitize_text_field($m['firstName']) : '',
            'last_name'    => isset($m['lastName']) ? sanitize_text_field($m['lastName']) : '',
            'user_url'     => isset($m['website']) ? esc_url_raw($m['website']) : '',
            'role'         => 'shop_manager',
        ]);
        return is_wp_error($uid) ? 'error' : 'created';
    }

    /**
     * Klon temiz başlasın: WooCommerce siparişlerini kalıcı siler (HPOS + legacy uyumlu).
     * PARTİ PARTİ (50'şer) + üst sınır (2000/push): limit=-1 ile tümünü tek seferde silmek
     * bellek/timeout fatal'ı (500) yapardı. Daha fazlası varsa tekrar push ile devam edilir.
     */
    private static function reset_orders() {
        $n = 0;
        for ($i = 0; $i < 40; $i++) { // 40 × 50 = en fazla 2000 sipariş / push
            $ids = wc_get_orders(['limit' => 50, 'return' => 'ids', 'status' => 'any']);
            if (empty($ids)) break;
            foreach ($ids as $oid) {
                $o = wc_get_order($oid);
                if ($o) { $o->delete(true); $n++; }
            }
        }
        return $n;
    }

    /**
     * URL'den görseli media kütüphanesine indirir → attachment id. Idempotent (URL eşlemesi saklanır).
     * media_handle_sideload REST bağlamında (login'siz) takıldığı için sağlam yöntem:
     * download_url → wp_upload_bits → wp_insert_attachment → metadata. Başarısızsa 0 + self::$sideload_err.
     */
    private static function sideload($url) {
        self::$sideload_err = '';
        $url = esc_url_raw($url);
        if ($url === '') { self::$sideload_err = 'url boş'; return 0; }
        $map = get_option(self::SIDELOAD_MAP, []);
        if (!is_array($map)) $map = [];
        if (isset($map[$url]) && get_post($map[$url])) return (int) $map[$url];

        require_once ABSPATH . 'wp-admin/includes/file.php';   // download_url
        require_once ABSPATH . 'wp-admin/includes/image.php';  // wp_generate_attachment_metadata

        $tmp = download_url($url, 20); // 20 sn timeout — hanging indirme max_execution_time'ı yemesin
        if (is_wp_error($tmp)) { self::$sideload_err = 'indirme: ' . $tmp->get_error_message(); return 0; }

        $bytes = @file_get_contents($tmp);
        @unlink($tmp);
        if ($bytes === false || $bytes === '') { self::$sideload_err = 'boş dosya'; return 0; }

        $name = basename(parse_url($url, PHP_URL_PATH));
        if ($name === '' || strpos($name, '.') === false) $name = 'brand-' . time() . '.png';
        $name = sanitize_file_name($name);

        // wp_upload_bits: dosyayı uploads klasörüne doğrudan yazar (wp_handle_sideload'un form/type
        // kontrollerini atlar → login'siz REST'te güvenilir)
        $up = wp_upload_bits($name, null, $bytes);
        if (!empty($up['error'])) { self::$sideload_err = 'upload: ' . $up['error']; return 0; }

        $ftype = wp_check_filetype($up['file']);
        $aid = wp_insert_attachment([
            'post_mime_type' => $ftype['type'] ? $ftype['type'] : 'image/png',
            'post_title'     => $name,
            'post_status'    => 'inherit',
        ], $up['file']);
        if (is_wp_error($aid) || !$aid) { self::$sideload_err = 'attachment oluşmadı'; return 0; }

        $meta = wp_generate_attachment_metadata($aid, $up['file']);
        if (is_array($meta)) wp_update_attachment_metadata($aid, $meta);

        $map[$url] = (int) $aid;
        update_option(self::SIDELOAD_MAP, $map);
        return (int) $aid;
    }

    /**
     * TEMA LOGOSU: loobek gibi temalar logoyu custom_logo yerine KENDİ option'unda URL olarak tutar.
     * GÜVENLİ otomatik tespit: yalnızca tema slug'lı option'lara (Redux vb.) + theme_mods_{slug}
     * dokunur; içinde SADECE adı 'logo' geçen + değeri uploads URL'i olan alanları yeni logoyla
     * değiştirir (text_logo gibi metin alanlarına dokunmaz). Ne değiştirdiğini döndürür.
     */
    private static function apply_theme_logo($logo_url) {
        global $wpdb;
        if (!self::is_upload_url($logo_url) && !preg_match('#^https?://#', (string) $logo_url)) return [];
        $slug = get_stylesheet();
        $changed = [];
        $names = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name = %s LIMIT 30",
            '%' . $wpdb->esc_like($slug) . '%', 'theme_mods_' . $slug
        ));
        foreach ((array) $names as $name) {
            $val = get_option($name);
            if (!is_array($val)) continue;
            if (self::replace_logo_urls($val, $logo_url, $name, $changed)) {
                update_option($name, $val);
            }
        }
        return $changed;
    }

    /** Diziyi gezip 'logo' anahtarlı + uploads URL'li alanları $logo_url ile değiştirir (recursive). */
    private static function replace_logo_urls(&$arr, $logo_url, $path, &$changed) {
        $did = false;
        foreach ($arr as $k => &$v) {
            $kp = $path . '.' . $k;
            $key_is_logo = (is_string($k) && preg_match('/logo/i', $k));
            if (is_array($v)) {
                // Redux media alanı: logo anahtarı altında ['url'=>..., 'thumbnail'=>..., 'id'=>...]
                // TÜM uploads-URL alt alanlarını değiştir (url VE thumbnail — footer thumbnail'i okuyor).
                if ($key_is_logo) {
                    $any = false;
                    foreach ($v as $sk => &$sv) {
                        if (is_string($sv) && self::is_upload_url($sv)) { $sv = $logo_url; $changed[] = $kp . '.' . $sk; $did = true; $any = true; }
                    }
                    unset($sv);
                    if ($any) { if (isset($v['id'])) unset($v['id']); }
                    else if (self::replace_logo_urls($v, $logo_url, $kp, $changed)) { $did = true; }
                } elseif (self::replace_logo_urls($v, $logo_url, $kp, $changed)) {
                    $did = true;
                }
            } elseif ($key_is_logo && is_string($v) && self::is_upload_url($v)) {
                $v = $logo_url; $changed[] = $kp; $did = true;
            }
        }
        return $did;
    }

    private static function is_upload_url($s) {
        return is_string($s) && preg_match('#^https?://#', $s) && strpos($s, '/wp-content/uploads/') !== false;
    }

    /**
     * Sosyal medya: temanın kendi sosyal link alanlarına (loobek Redux vb.) klonun linklerini yazar.
     * GÜVENLİ: yalnızca tema slug'lı option'lar; adı platformu (instagram/facebook…) içeren + değeri
     * URL/boş olan alanları o platformun linkiyle değiştirir. $social = { instagram: url, ... }.
     */
    private static function apply_theme_social($social) {
        global $wpdb;
        $social = array_filter((array) $social); // boşları at
        if (empty($social)) return [];
        $slug = get_stylesheet();
        $changed = [];
        $names = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name = %s LIMIT 30",
            '%' . $wpdb->esc_like($slug) . '%', 'theme_mods_' . $slug
        ));
        foreach ((array) $names as $name) {
            $val = get_option($name);
            if (!is_array($val)) continue;
            if (self::replace_social_keys($val, $social, $name, $changed)) update_option($name, $val);
        }
        return $changed;
    }

    private static function replace_social_keys(&$arr, $social, $path, &$changed) {
        $did = false;
        foreach ($arr as $k => &$v) {
            $kp = $path . '.' . $k;
            if (is_array($v)) {
                if (self::replace_social_keys($v, $social, $kp, $changed)) $did = true;
            } elseif (is_string($k) && is_string($v)) {
                foreach ($social as $plat => $url) {
                    // anahtar platform adını içeriyor + değer URL ya da boş → o platformun linkini yaz
                    if (stripos($k, $plat) !== false && ($v === '' || preg_match('#^https?://#', $v))) {
                        if ($v !== $url) { $v = $url; $changed[] = $kp; $did = true; }
                        break;
                    }
                }
            }
        }
        return $did;
    }

    /** TEŞHİS: bir değer ağacında $needle'ın hangi key yolunda geçtiğini bulur (scanOption için). */
    public static function find_paths($val, $needle, $path, &$out) {
        if (is_string($val)) {
            if (strpos($val, $needle) !== false) {
                $out[] = ($path === '' ? '(kök)' : $path) . ' = ' . (strlen($val) > 140 ? substr($val, 0, 140) . '…' : $val);
            }
            return;
        }
        if (is_array($val)) {
            foreach ($val as $k => $v) self::find_paths($v, $needle, $path . '[' . $k . ']', $out);
        } elseif (is_object($val)) {
            foreach (get_object_vars($val) as $k => $v) self::find_paths($v, $needle, $path . '->' . $k, $out);
        }
    }

    /**
     * [wpd_field key="site_title|tagline|contact_email|company_name|website|copyright|instagram"]
     * Yasal sayfalar ve footer bu shortcode'u kullanır → klonda hiç düzenlenmez, değer otomatik gelir.
     * instagram: e-posta gibi metin döner; link istenirse [wpd_field key="instagram"] bir href içine konur.
     */
    public static function field_shortcode($atts) {
        $a = shortcode_atts(['key' => ''], $atts, 'wpd_field');
        $id = get_option(self::IDENTITY_OPTION, []);
        if (!is_array($id)) $id = [];
        $social = isset($id['social']) && is_array($id['social']) ? $id['social'] : [];
        switch ($a['key']) {
            case 'site_title':    return esc_html(get_bloginfo('name'));
            case 'tagline':       return esc_html(get_bloginfo('description'));
            case 'contact_email': return esc_html(!empty($id['contactEmail']) ? $id['contactEmail'] : get_bloginfo('admin_email'));
            case 'company_name':  return esc_html(!empty($id['legalCompany']) ? $id['legalCompany'] : get_bloginfo('name'));
            case 'website':       return esc_html(!empty($id['websiteUrl']) ? $id['websiteUrl'] : home_url());
            case 'copyright':     return esc_html(!empty($id['copyrightText']) ? $id['copyrightText'] : ('© ' . date('Y') . ' ' . get_bloginfo('name')));
            case 'instagram':     return !empty($social['instagram']) ? esc_url($social['instagram']) : '';
            case 'facebook':      return !empty($social['facebook']) ? esc_url($social['facebook']) : '';
            default:              return '';
        }
    }
}
