<?php
// Plugin silinince ayarları temizle
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('wpd_api_key');
delete_option('wpd_api_secret');
delete_option('wpd_registered');
