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

function get_env_superadmins()
{
    $env = getEnvConfig();
    $auth = $env['auth'] ?? [];

    $entries = [];
    $singleUser = $auth['superadmin_user'] ?? '';
    $singlePass = $auth['superadmin_pass'] ?? '';
    if ($singleUser !== '' || $singlePass !== '') {
        $entries[] = ['user' => (string)$singleUser, 'pass' => (string)$singlePass];
    }

    $list = $auth['superadmins'] ?? [];
    if (is_array($list)) {
        foreach ($list as $key => $value) {
            if (is_array($value)) {
                $u = $value['user'] ?? $value['username'] ?? '';
                $p = $value['pass'] ?? $value['password'] ?? '';
                if ($u !== '' || $p !== '') {
                    $entries[] = ['user' => (string)$u, 'pass' => (string)$p];
                }
                continue;
            }
            if (is_string($key) && $key !== '') {
                $entries[] = ['user' => (string)$key, 'pass' => (string)$value];
            }
        }
    }

    return $entries;
}

function find_env_superadmin($username)
{
    $username = (string)$username;
    if ($username === '') return [];
    foreach (get_env_superadmins() as $entry) {
        if (!empty($entry['user']) && $entry['user'] === $username) {
            return $entry;
        }
    }
    return [];
}

function verify_env_superadmin($username, $password)
{
    $entry = find_env_superadmin($username);
    if (empty($entry)) return false;
    return verify_password_compat((string)$password, (string)($entry['pass'] ?? ''));
}

function normalize_phone_to_62($phone, $countryCode = '62')
{
    $phone = trim((string)$phone);
    if ($phone === '') return '';
    $digits = preg_replace('/\D+/', '', $phone);
    if ($digits === '') return '';
    if ($digits[0] === '0') {
        $digits = $countryCode . substr($digits, 1);
    }
    if ($countryCode !== '' && strpos($digits, $countryCode) !== 0) {
        $digits = $countryCode . $digits;
    }
    return $digits;
}

function format_phone_display($phone, $countryCode = '62')
{
    $phone = trim((string)$phone);
    if ($phone === '') return '';
    $digits = preg_replace('/\D+/', '', $phone);
    if ($digits === '') return '';
    if ($countryCode !== '' && strpos($digits, $countryCode) === 0) {
        return '0' . substr($digits, strlen($countryCode));
    }
    if ($digits[0] !== '0') {
        return '0' . $digits;
    }
    return $digits;
}

function format_full_name_title($name)
{
    $name = trim((string)$name);
    if ($name === '') return '';
    if (function_exists('mb_convert_case')) {
        return mb_convert_case(mb_strtolower($name, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    }
    return ucwords(strtolower($name));
}

function is_valid_simple_username($value)
{
    $value = (string)$value;
    if ($value === '') return false;
    return preg_match('/^[a-z0-9]+$/', $value) === 1;
}

function is_valid_phone_length($phone, $min = 10, $max = 13)
{
    $digits = preg_replace('/\D+/', '', (string)$phone);
    $len = strlen($digits);
    return $len >= (int)$min && $len <= (int)$max;
}

function is_valid_phone_08($phone, $min = 10, $max = 13)
{
    $digits = preg_replace('/\D+/', '', (string)$phone);
    if ($digits === '') return false;
    if (!is_valid_phone_length($digits, $min, $max)) return false;
    return strpos($digits, '08') === 0;
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
    $source = $_SESSION['mikhmon_admin_source'] ?? '';
    if ($source === 'env') {
        return false;
    }
    $adminId = (int)($_SESSION['mikhmon_admin_id'] ?? 0);
    if ($adminId > 0 && function_exists('app_db_update_admin')) {
        $admin = app_db_get_admin_by_id($adminId);
        $username = $admin['username'] ?? '';
        if ($username === '') return false;
        app_db_update_admin($adminId, $username, $newHash, !empty($admin['is_active']));
        return true;
    }
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
