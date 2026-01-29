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
    $env = getEnvConfig();
    if (!empty($env['maintenance']['redirect_url'])) {
        return (string)$env['maintenance']['redirect_url'];
    }
    return './dev/maintenance.html';
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
