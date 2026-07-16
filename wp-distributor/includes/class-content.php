<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Merkezden gelen içerikleri gösterir. Veri wpd_site_contents option'ında.
 * - announcement (position=top): site başına duyuru çubuğu (otomatik)
 * - announcement/banner (home-hero, home-strip): [wpd_banner position="..."] shortcode'u ile
 * - hero: [wpd_hero position="..."] shortcode'u ile (arka plan + başlık + buton kapak)
 */
class WPD_Content {

    public static function init() {
        add_action('wp_body_open', [__CLASS__, 'render_announcements']);
        add_action('wp_head', [__CLASS__, 'styles']);
        add_shortcode('wpd_banner', [__CLASS__, 'banner_shortcode']);
        add_shortcode('wpd_hero', [__CLASS__, 'hero_shortcode']);
    }

    /** Aktif + tarih aralığındaki içerikler (opsiyonel türe göre). */
    private static function active_items($type = null) {
        $items = get_option('wpd_site_contents', []);
        if (!is_array($items)) {
            return [];
        }
        $now = current_time('timestamp');
        $out = [];
        foreach ($items as $it) {
            if ($type && (!isset($it['type']) || $it['type'] !== $type)) continue;
            if (isset($it['active']) && !$it['active']) continue;
            if (!empty($it['startAt']) && strtotime($it['startAt']) > $now) continue;
            if (!empty($it['endAt']) && strtotime($it['endAt']) < $now) continue;
            $out[] = $it;
        }
        return $out;
    }

    /** Site başına duyuru çubuğu (announcement, position=top). */
    public static function render_announcements() {
        foreach (self::active_items('announcement') as $it) {
            if ((isset($it['position']) ? $it['position'] : 'top') !== 'top') continue;
            $bg   = !empty($it['bgColor']) ? esc_attr($it['bgColor']) : '#111827';
            $text = esc_html(isset($it['text']) ? $it['text'] : '');
            if ($text === '') continue;
            $link = !empty($it['linkUrl']) ? esc_url($it['linkUrl']) : '';
            $inner = $link ? '<a href="' . $link . '" style="color:inherit;text-decoration:underline">' . $text . '</a>' : $text;
            echo '<div class="wpd-announce-bar" style="background:' . $bg . '">' . $inner . '</div>';
        }
    }

    /**
     * [wpd_banner position="..."] — o konumdaki içerikler: görsel banner VE metin duyurusu.
     * Konum verilirse yalnız o konumdakiler; verilmezse "top" (otomatik üst çubuk) hariç hepsi.
     */
    public static function banner_shortcode($atts) {
        $pos  = isset($atts['position']) ? $atts['position'] : '';
        $html = '';
        foreach (self::active_items() as $it) {
            $ipos = isset($it['position']) ? $it['position'] : 'top';
            if ($pos !== '') {
                if ($ipos !== $pos) continue;
            } elseif ($ipos === 'top') {
                continue; // konum belirtilmedi → üst çubuk zaten otomatik geliyor, çift basma
            }

            $type = isset($it['type']) ? $it['type'] : 'announcement';
            if ($type === 'hero') continue; // hero yalnız [wpd_hero] ile render edilir
            $link = !empty($it['linkUrl']) ? esc_url($it['linkUrl']) : '';

            if ($type === 'banner') {
                $img = !empty($it['imageUrl']) ? esc_url($it['imageUrl']) : '';
                if (!$img) continue;
                $alt = esc_attr(isset($it['title']) ? $it['title'] : '');
                $img_tag = '<img src="' . $img . '" alt="' . $alt . '" class="wpd-banner-img" style="max-width:100%;height:auto;display:block" />';
                $html .= '<div class="wpd-banner">' . ($link ? '<a href="' . $link . '">' . $img_tag . '</a>' : $img_tag) . '</div>';
            } else {
                $text = esc_html(isset($it['text']) ? $it['text'] : '');
                if ($text === '') continue;
                $bg    = !empty($it['bgColor']) ? esc_attr($it['bgColor']) : '#111827';
                $title = !empty($it['title']) ? '<strong class="wpd-announce-title">' . esc_html($it['title']) . '</strong>' : '';
                $body  = $link ? '<a href="' . $link . '" style="color:inherit;text-decoration:underline">' . $text . '</a>' : $text;
                $html .= '<div class="wpd-announce-block" style="background:' . $bg . '">' . $title . $body . '</div>';
            }
        }
        return $html;
    }

    /**
     * [wpd_hero position="home-hero"] — merkezden yönetilen tam kapak (hero):
     * arka plan görseli + kaplama + başlık + alt yazı + buton.
     */
    public static function hero_shortcode($atts) {
        $pos  = isset($atts['position']) ? $atts['position'] : '';
        $html = '';
        foreach (self::active_items('hero') as $it) {
            $ipos = isset($it['position']) ? $it['position'] : 'home-hero';
            if ($pos !== '' && $ipos !== $pos) continue;

            $img     = !empty($it['imageUrl']) ? esc_url($it['imageUrl']) : '';
            $heading = esc_html(isset($it['title']) ? $it['title'] : '');
            $sub     = esc_html(isset($it['text']) ? $it['text'] : '');
            $btnText = isset($it['buttonText']) ? trim($it['buttonText']) : '';
            $btnLink = !empty($it['linkUrl']) ? esc_url($it['linkUrl']) : '';
            $overlay = !empty($it['bgColor']) ? esc_attr($it['bgColor']) : '#0a3d8f';

            $sec_style = $img ? "background-image:url('" . $img . "');" : 'background:' . $overlay . ';';
            $btn = ($btnText !== '' && $btnLink)
                ? '<a class="wpd-hero-btn" href="' . $btnLink . '">' . esc_html($btnText) . '</a>'
                : '';

            $html .= '<section class="wpd-hero" style="' . $sec_style . '">'
                . ($img ? '<span class="wpd-hero-overlay" style="background:' . $overlay . '"></span>' : '')
                . '<div class="wpd-hero-inner">'
                . ($heading !== '' ? '<h2 class="wpd-hero-title">' . $heading . '</h2>' : '')
                . ($sub !== '' ? '<p class="wpd-hero-sub">' . $sub . '</p>' : '')
                . $btn
                . '</div>'
                . '</section>';
        }
        return $html;
    }

    public static function styles() {
        echo '<style>'
            . '.wpd-announce-bar{color:#fff;text-align:center;padding:8px 16px;font-size:14px;font-weight:500;line-height:1.4}'
            . '.wpd-banner{margin:12px 0}'
            . '.wpd-announce-block{color:#fff;text-align:center;padding:16px 20px;border-radius:10px;font-size:16px;font-weight:600;line-height:1.5;margin:12px 0}'
            . '.wpd-announce-title{display:block;font-size:1.15em;margin-bottom:4px}'
            . '.wpd-hero{position:relative;background-size:cover;background-position:center;min-height:420px;display:flex;align-items:center;border-radius:12px;overflow:hidden;margin:16px 0}'
            . '.wpd-hero-overlay{position:absolute;inset:0;opacity:.55;pointer-events:none}'
            . '.wpd-hero-inner{position:relative;z-index:1;padding:48px 56px;max-width:640px;color:#fff}'
            . '.wpd-hero-title{font-size:clamp(30px,5vw,60px);line-height:1.06;font-weight:800;margin:0 0 16px;color:#fff}'
            . '.wpd-hero-sub{font-size:18px;margin:0 0 24px;color:#fff;opacity:.95}'
            . '.wpd-hero-btn{display:inline-block;background:#fff;color:#111;padding:14px 30px;border-radius:8px;font-weight:600;text-decoration:none}'
            . '.wpd-hero-btn:hover{opacity:.9}'
            . '@media(max-width:600px){.wpd-hero-inner{padding:32px 24px}.wpd-hero{min-height:320px}}'
            . '</style>';
    }
}
