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
    $env = getEnvConfig();
    return !empty($env['maintenance']['enabled']);
}

function getMaintenanceUrl()
{
    return './maintenance.html';
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

function update_operator_password_hash($newHash)
{
    if (!is_string($newHash) || $newHash === '') return false;
    require_once __DIR__ . '/db.php';
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
