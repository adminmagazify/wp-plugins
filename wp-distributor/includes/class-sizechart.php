<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Beden tablosu PULL — sitedeki Loobek 'ts_size_chart' tablolarını merkeze aktarır.
 * Admin panelden "Beden tablolarını merkeze çek" butonuyla tetiklenir.
 */
class WPD_SizeChartSync {

    public static function pull_to_central() {
        if (!post_type_exists('ts_size_chart')) {
            return ['success' => false, 'message' => 'Bu temada beden tablosu sistemi (ts_size_chart) yok.'];
        }

        $posts = get_posts([
            'post_type'   => 'ts_size_chart',
            'numberposts' => -1,
            'post_status' => 'publish',
        ]);

        $charts = [];
        foreach ($posts as $p) {
            $raw = get_post_meta($p->ID, 'ts_chart_table', true);
            $table = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
            if (!is_array($table) || empty($table)) {
                continue;
            }
            $charts[] = ['name' => $p->post_title, 'table' => $table];
        }

        if (empty($charts)) {
            return ['success' => false, 'message' => 'Aktarılacak tablo bulunamadı.'];
        }

        $key = WPD_Api_Client::get_api_key();
        if (!$key) {
            return ['success' => false, 'message' => 'Önce merkeze kaydolun (API key yok).'];
        }

        $res = wp_remote_post(WPD_Api_Client::central_url() . '/api/public/size-charts/import', [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-API-Key'    => $key,
            ],
            'body' => wp_json_encode(['charts' => $charts]),
        ]);

        if (is_wp_error($res)) {
            return ['success' => false, 'message' => $res->get_error_message()];
        }
        $code = wp_remote_retrieve_response_code($res);
        $data = json_decode(wp_remote_retrieve_body($res), true);
        if ($code < 200 || $code >= 300) {
            return ['success' => false, 'message' => 'Merkez hatası (HTTP ' . $code . ')'];
        }
        return ['success' => true, 'imported' => isset($data['imported']) ? (int) $data['imported'] : 0];
    }
}
