<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * GitHub release tabanlı otomatik güncelleyici (dış bağımlılık yok).
 * wp-distributor ile aynı desen: merkez API'yi (Railway) sorar, o da GitHub'ı
 * sunucu tarafında tek noktadan sorup cache'ler — 1000 site GitHub'ın IP başına
 * 60 istek/saat limitine (403) takılmaz. Zip yine GitHub release'den iner.
 *
 * Release konvansiyonu (monorepo'da birden çok plugin'i ayırmak için):
 *   Tag:   siparis-hesap-hareketleri-1.1.2   (TAG_PREFIX + sürüm)
 *   Asset: siparis-hesap-hareketleri.zip
 */
class MSH_Updater {

    const CENTRAL_URL = 'https://api-production-76ce.up.railway.app';
    const PLUGIN_SLUG_ID = 'siparis-hesap-hareketleri'; // merkez API'de ?slug= parametresi
    const GITHUB_REPO = 'adminmagazify/wp-plugins';
    const CACHE_KEY   = 'msh_update_check';
    const CACHE_TTL   = 21600; // 6 saat

    protected static $plugin_file;
    protected static $plugin_slug;

    public static function init($plugin_file) {
        self::$plugin_file = plugin_basename($plugin_file);
        self::$plugin_slug = dirname(self::$plugin_file);

        add_filter('pre_set_site_transient_update_plugins', [__CLASS__, 'check_for_update']);
        add_filter('plugins_api', [__CLASS__, 'plugin_info'], 20, 3);
        add_filter('upgrader_source_selection', [__CLASS__, 'fix_source_dir'], 10, 4);
        add_action('upgrader_process_complete', [__CLASS__, 'clear_cache'], 10, 0);
    }

    protected static function get_latest_release() {
        $force = !empty($_GET['force-check']);

        if (!$force) {
            $cached = get_transient(self::CACHE_KEY);
            if ($cached !== false) {
                return $cached ?: null;
            }
        }

        $url = self::CENTRAL_URL . '/api/public/plugin-update?slug=' . self::PLUGIN_SLUG_ID;
        $res = wp_remote_get($url, [
            'timeout' => 15,
            'headers' => [
                'Accept'     => 'application/json',
                'User-Agent' => 'msh-updater',
            ],
        ]);

        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
            set_transient(self::CACHE_KEY, '', 1800);
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($res), true);
        if (!is_array($data) || empty($data['version']) || empty($data['download_url'])) {
            set_transient(self::CACHE_KEY, '', 900);
            return null;
        }

        $best = [
            'version'   => (string) $data['version'],
            'download'  => (string) $data['download_url'],
            'changelog' => isset($data['changelog']) ? $data['changelog'] : '',
            'name'      => isset($data['name']) ? $data['name'] : 'Magazify Sipariş ve Hesap Hareketleri',
        ];

        set_transient(self::CACHE_KEY, $best, self::CACHE_TTL);
        return $best;
    }

    public static function check_for_update($transient) {
        if (empty($transient) || empty($transient->checked)) {
            return $transient;
        }

        $current = isset($transient->checked[self::$plugin_file])
            ? $transient->checked[self::$plugin_file]
            : (defined('MSH_VERSION') ? MSH_VERSION : '0');

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

    public static function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== self::$plugin_slug) {
            return $result;
        }
        $header = ['Version' => 'Version', 'Name' => 'Plugin Name', 'Description' => 'Description', 'Author' => 'Author'];
        $data = @get_file_data(WP_PLUGIN_DIR . '/' . self::$plugin_file, $header);

        $latest    = self::get_latest_release();
        $version   = ($latest && !empty($latest['version'])) ? $latest['version'] : (!empty($data['Version']) ? $data['Version'] : '1.0.0');
        $download  = ($latest && !empty($latest['download'])) ? $latest['download'] : '';
        $changelog = ($latest && !empty($latest['changelog'])) ? nl2br(esc_html($latest['changelog'])) : 'Değişiklik notu bulunmuyor.';

        return (object) [
            'name'          => !empty($data['Name']) ? $data['Name'] : 'Magazify Sipariş ve Hesap Hareketleri',
            'slug'          => self::$plugin_slug,
            'version'       => $version,
            'author'        => !empty($data['Author']) ? $data['Author'] : 'Magazify',
            'homepage'      => 'https://github.com/' . self::GITHUB_REPO,
            'download_link' => $download,
            'sections'      => [
                'description' => !empty($data['Description']) ? $data['Description'] : 'Sipariş raporlama, hakediş, gönderim katkısı ve mağaza ortağı hesap hareketleri.',
                'changelog'   => $changelog,
            ],
        ];
    }

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
