<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * GitHub release tabanlı otomatik güncelleyici (dış bağımlılık yok).
 *
 * Merkezdeki "wp-plugins" repo'sunda bu plugin için yayınlanan en yeni release'i
 * kontrol eder ve WordPress'in standart güncelleme sistemine bildirir. Böylece
 * tüm sitelerde Eklentiler sayfasında "güncelleme var" görünür (veya otomatik
 * güncelleme açıksa kendiliğinden güncellenir).
 *
 * Release konvansiyonu (monorepo'da birden çok plugin'i ayırmak için):
 *   - Tag:   wp-distributor-1.1.3   (TAG_PREFIX + sürüm)
 *   - Asset: wp-distributor.zip     (release'e eklenen kurulabilir zip)
 */
class WPD_Updater {

    const GITHUB_REPO = 'adminmagazify/wp-plugins';
    const TAG_PREFIX  = 'wp-distributor-';
    const ASSET_NAME  = 'wp-distributor.zip';
    const CACHE_KEY   = 'wpd_update_check';
    const CACHE_TTL   = 21600; // 6 saat (GitHub API'yi yormamak için)

    protected static $plugin_file; // wp-distributor/wp-distributor.php
    protected static $plugin_slug; // wp-distributor

    public static function init($plugin_file) {
        self::$plugin_file = plugin_basename($plugin_file);
        self::$plugin_slug = dirname(self::$plugin_file);

        add_filter('pre_set_site_transient_update_plugins', [__CLASS__, 'check_for_update']);
        add_filter('plugins_api', [__CLASS__, 'plugin_info'], 20, 3);
        add_filter('upgrader_source_selection', [__CLASS__, 'fix_source_dir'], 10, 4);
        add_action('upgrader_process_complete', [__CLASS__, 'clear_cache'], 10, 0);
    }

    /**
     * En yeni sürümü MERKEZ API'den (Railway) sorar — doğrudan GitHub'a değil.
     * Merkez, GitHub'ı sunucu tarafında tek noktadan sorup cache'ler; böylece siteler
     * GitHub'ın IP başına 60 istek/saat limitine (403) takılmaz. Zip yine GitHub'dan iner.
     */
    protected static function get_latest_release() {
        // "Yeniden kontrol et" (force-check) yapıldığında cache'i atla.
        $force = !empty($_GET['force-check']);

        if (!$force) {
            $cached = get_transient(self::CACHE_KEY);
            if ($cached !== false) {
                return $cached ?: null;
            }
        }

        $url = WPD_Api_Client::central_url() . '/api/public/plugin-update';
        $res = wp_remote_get($url, [
            'timeout' => 15,
            'headers' => [
                'Accept'     => 'application/json',
                'User-Agent' => 'wp-distributor-updater',
            ],
        ]);

        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
            set_transient(self::CACHE_KEY, '', 1800); // hata: 30 dk kısa cache
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($res), true);
        if (!is_array($data) || empty($data['version']) || empty($data['download_url'])) {
            set_transient(self::CACHE_KEY, '', 900); // sürüm yok/eksik: 15 dk
            return null;
        }

        $best = [
            'version'   => (string) $data['version'],
            'download'  => (string) $data['download_url'],
            'changelog' => isset($data['changelog']) ? $data['changelog'] : '',
            'name'      => isset($data['name']) ? $data['name'] : 'WP Distributor',
        ];

        set_transient(self::CACHE_KEY, $best, self::CACHE_TTL);
        return $best;
    }

    /** WordPress güncelleme kontrolüne yeni sürümü ekler. */
    public static function check_for_update($transient) {
        if (empty($transient) || empty($transient->checked)) {
            return $transient;
        }

        $current = isset($transient->checked[self::$plugin_file])
            ? $transient->checked[self::$plugin_file]
            : (defined('WPD_VERSION') ? WPD_VERSION : '0');

        $latest = self::get_latest_release();

        if ($latest && version_compare($latest['version'], $current, '>')) {
            $transient->response[self::$plugin_file] = (object) [
                'slug'        => self::$plugin_slug,
                'plugin'      => self::$plugin_file,
                'new_version' => $latest['version'],
                'package'     => $latest['download'],
                'url'         => 'https://github.com/' . self::GITHUB_REPO,
            ];
        } else {
            // Güncel: no_update listesine ekle ki Eklentiler sayfası düzgün davransın
            unset($transient->response[self::$plugin_file]);
            $transient->no_update[self::$plugin_file] = (object) [
                'slug'        => self::$plugin_slug,
                'plugin'      => self::$plugin_file,
                'new_version' => $current,
                'package'     => '',
                'url'         => 'https://github.com/' . self::GITHUB_REPO,
            ];
        }

        return $transient;
    }

    /** "Ayrıntıları gör" popup'ı için bilgi sağlar. */
    public static function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== self::$plugin_slug) {
            return $result;
        }
        $latest = self::get_latest_release();
        if (!$latest) {
            return $result;
        }
        return (object) [
            'name'          => 'WP Distributor',
            'slug'          => self::$plugin_slug,
            'version'       => $latest['version'],
            'author'        => 'WP Central',
            'homepage'      => 'https://github.com/' . self::GITHUB_REPO,
            'download_link' => $latest['download'],
            'sections'      => [
                'changelog' => $latest['changelog'] !== '' ? nl2br(esc_html($latest['changelog'])) : 'Değişiklik notu yok.',
            ],
        ];
    }

    /**
     * İndirilen zip'in kök klasörünün plugin slug'ıyla (wp-distributor) eşleşmesini sağlar.
     * Asset zaten wp-distributor/ ile paketlendiği için normalde sorun çıkmaz; bu bir güvencedir.
     */
    public static function fix_source_dir($source, $remote_source, $upgrader, $hook_extra = null) {
        if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== self::$plugin_file) {
            return $source;
        }
        global $wp_filesystem;
        if (!$wp_filesystem) {
            return $source;
        }
        $desired = trailingslashit($remote_source) . self::$plugin_slug . '/';
        if (untrailingslashit($source) === untrailingslashit($desired)) {
            return $source;
        }
        // Hedef zaten varsa önce sil
        if ($wp_filesystem->is_dir(untrailingslashit($desired))) {
            $wp_filesystem->delete(untrailingslashit($desired), true);
        }
        if ($wp_filesystem->move(untrailingslashit($source), untrailingslashit($desired))) {
            return $desired;
        }
        return $source;
    }

    public static function clear_cache() {
        delete_transient(self::CACHE_KEY);
    }
}
