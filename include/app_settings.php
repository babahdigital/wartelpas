<?php
require_once __DIR__ . '/db_helpers.php';

if (!function_exists('app_settings_db')) {
    function app_settings_db() {
        $dbFile = get_stats_db_path();
        $db = new PDO('sqlite:' . $dbFile);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE IF NOT EXISTS app_settings (key TEXT PRIMARY KEY, value TEXT, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        return $db;
    }
}

if (!function_exists('app_setting_get')) {
    function app_setting_get($key, $default = null) {
        try {
            $db = app_settings_db();
            $stmt = $db->prepare("SELECT value FROM app_settings WHERE key = :k LIMIT 1");
            $stmt->execute([':k' => $key]);
            $val = $stmt->fetchColumn();
            return $val !== false && $val !== null ? $val : $default;
        } catch (Exception $e) {
            return $default;
        }
    }
}

if (!function_exists('app_setting_set')) {
    function app_setting_set($key, $value) {
        $db = app_settings_db();
        $stmt = $db->prepare("INSERT OR REPLACE INTO app_settings (key, value, updated_at) VALUES (:k, :v, CURRENT_TIMESTAMP)");
        $stmt->execute([':k' => $key, ':v' => (string)$value]);
        return true;
    }
}
