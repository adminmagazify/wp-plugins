<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Merkezden gelen içerikleri gösterir. Veri wpd_site_contents option'ında.
 * - announcement (position=top): site başına duyuru çubuğu
 * - banner: [wpd_banner position="home-hero"] shortcode'u ile
 */
class WPD_Content {

    public static function init() {
        add_action('wp_body_open', [__CLASS__, 'render_announcements']);
        add_action('wp_head', [__CLASS__, 'styles']);
        add_shortcode('wpd_banner', [__CLASS__, 'banner_shortcode']);
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

    public static function styles() {
        echo '<style>'
            . '.wpd-announce-bar{color:#fff;text-align:center;padding:8px 16px;font-size:14px;font-weight:500;line-height:1.4}'
            . '.wpd-banner{margin:12px 0}'
            . '.wpd-announce-block{color:#fff;text-align:center;padding:16px 20px;border-radius:10px;font-size:16px;font-weight:600;line-height:1.5;margin:12px 0}'
            . '.wpd-announce-title{display:block;font-size:1.15em;margin-bottom:4px}'
            . '</style>';
    }
}
