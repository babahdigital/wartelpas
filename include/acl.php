<?php
if (function_exists('aclRedirect')) {
    return;
}

function aclRedirect($url)
{
    if (!headers_sent()) {
        header("Location: $url");
    } else {
        $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        echo "<script>window.location='{$safeUrl}'</script>";
    }
    exit;
}

function ensureRole()
{
    if (isset($_SESSION['mikhmon']) && empty($_SESSION['mikhmon_level'])) {
        $_SESSION['mikhmon_level'] = 'superadmin';
    }
}

function getEnvConfig()
{
    if (isset($GLOBALS['env_config']) && is_array($GLOBALS['env_config'])) {
        return $GLOBALS['env_config'];
    }

    $env = [];
    $envFile = __DIR__ . '/env.php';
    if (file_exists($envFile)) {
        require $envFile;
    }
    if (isset($env) && is_array($env)) {
        $GLOBALS['env_config'] = $env;
    } else {
        $GLOBALS['env_config'] = [];
    }

    return $GLOBALS['env_config'];
}

function isMaintenanceEnabled()
{
    return maintenance_db_get_enabled();
}

function maintenance_db_get_enabled()
{
    if (!function_exists('app_db')) {
        require_once __DIR__ . '/db.php';
    }
    try {
        $db = app_db();
        $db->exec("CREATE TABLE IF NOT EXISTS system_settings (key TEXT PRIMARY KEY, value TEXT, updated_at TEXT)");
        $stmt = $db->prepare("SELECT value FROM system_settings WHERE key = :k");
        $stmt->execute([':k' => 'maintenance_enabled']);
        $val = (string)($stmt->fetchColumn() ?? '');
        if ($val === '') return false;
        return in_array(strtolower($val), ['1', 'true', 'yes', 'on'], true);
    } catch (Exception $e) {
        return false;
    }
}

function maintenance_db_set_enabled($enabled)
{
    if (!function_exists('app_db')) {
        require_once __DIR__ . '/db.php';
    }
    try {
        $db = app_db();
        $db->exec("CREATE TABLE IF NOT EXISTS system_settings (key TEXT PRIMARY KEY, value TEXT, updated_at TEXT)");
        $stmt = $db->prepare("INSERT OR REPLACE INTO system_settings (key, value, updated_at) VALUES (:k, :v, :t)");
        $stmt->execute([
            ':k' => 'maintenance_enabled',
            ':v' => $enabled ? '1' : '0',
            ':t' => date('Y-m-d H:i:s'),
        ]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function getMaintenanceUrl()
{
    return './maintenance.php';
}

function isSuperAdmin()
{
    ensureRole();
    return isset($_SESSION['mikhmon_level']) && $_SESSION['mikhmon_level'] === 'superadmin';
}

function isOperator()
{
    ensureRole();
    return isset($_SESSION['mikhmon_level']) && $_SESSION['mikhmon_level'] === 'operator';
}

function operator_can($permission)
{
    if (isSuperAdmin()) return true;
    if (!isOperator()) return false;
    require_once __DIR__ . '/db.php';
    $opId = isset($_SESSION['mikhmon_operator_id']) ? (int)$_SESSION['mikhmon_operator_id'] : 0;
    if ($opId > 0 && function_exists('app_db_get_operator_permissions_for')) {
        $perms = app_db_get_operator_permissions_for($opId);
    } else {
        $perms = app_db_get_operator_permissions();
    }
    return !empty($perms[$permission]);
}

function requireLogin($redirectUrl = './admin.php?id=login')
{
    if (!isset($_SESSION['mikhmon'])) {
        aclRedirect($redirectUrl);
    }
}

function requireSuperAdmin($redirectUrl = './admin.php?id=sessions', $message = 'Akses ditolak. Hubungi Superadmin.')
{
    if (!isSuperAdmin()) {
        if (!headers_sent()) {
            header("Location: $redirectUrl");
        } else {
            $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
            $safeUrl = htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8');
            echo "<script>alert('{$safeMessage}'); window.location='{$safeUrl}';</script>";
        }
        exit;
    }
}

function is_password_hash($value)
{
    if (!is_string($value) || $value === '') return false;
    if (!function_exists('password_get_info')) return false;
    $info = password_get_info($value);
    return isset($info['algo']) && $info['algo'] !== 0;
}

function hash_password_value($plain)
{
    return password_hash((string)$plain, PASSWORD_DEFAULT);
}

function verify_password_compat($plain, $stored)
{
    if ($stored === '') return false;
    if (is_password_hash($stored)) {
        return password_verify((string)$plain, (string)$stored);
    }
    if (hash_equals((string)$stored, (string)$plain)) {
        return true;
    }
    if (function_exists('decrypt')) {
        return hash_equals((string)decrypt((string)$stored), (string)$plain);
    }
    return false;
}

function update_admin_password_hash($oldStored, $newHash)
{
    if (!is_string($newHash) || $newHash === '') return false;
    require_once __DIR__ . '/db.php';
    $admin = app_db_get_admin();
    $username = $admin['username'] ?? '';
    if ($username === '') return false;
    app_db_set_admin($username, $newHash);
    return true;
}

function update_operator_password_hash($newHash, $operatorId = null)
{
    if (!is_string($newHash) || $newHash === '') return false;
    require_once __DIR__ . '/db.php';
    $opId = $operatorId !== null ? (int)$operatorId : (int)($_SESSION['mikhmon_operator_id'] ?? 0);
    if ($opId > 0 && function_exists('app_db_update_operator')) {
        $opRow = app_db_get_operator_by_id($opId);
        $username = $opRow['username'] ?? '';
        if ($username === '') return false;
        app_db_update_operator($opId, $username, $newHash, !empty($opRow['is_active']));
        return true;
    }

    $op = app_db_get_operator();
    $username = $op['username'] ?? '';
    if ($username === '') {
        $env = getEnvConfig();
        $username = $env['auth']['operator_user'] ?? '';
    }
    if ($username === '') return false;
    app_db_set_operator($username, $newHash);
    return true;
}

function get_operator_password_override()
{
    $overrideFile = __DIR__ . '/operator_pass.php';
    if (!is_file($overrideFile)) return '';
    $operator_pass_override = '';
    include $overrideFile;
    return is_string($operator_pass_override) ? $operator_pass_override : '';
}
