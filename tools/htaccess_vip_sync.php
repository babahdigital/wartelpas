<?php
// Sync VIP whitelist to .htaccess (CLI or token-protected HTTP)
error_reporting(0);

$rootDir = dirname(__DIR__);
$htaccessPath = $rootDir . '/.htaccess';

// Load env
$env = [];
$envFile = $rootDir . '/include/env.php';
if (is_file($envFile)) {
    require $envFile;
}

function normalize_ip_list($raw) {
    $raw = str_replace(["\r", "\n"], ' ', (string)$raw);
    $parts = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
    $out = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') continue;
        $out[] = $p;
    }
    return $out;
}

function is_valid_ip($ip) {
    $ip = trim((string)$ip);
    if ($ip === '') return false;
    return filter_var($ip, FILTER_VALIDATE_IP) !== false;
}

function extract_vip_ips($content) {
    $ips = [];
    if (preg_match_all('/SetEnvIf\s+\S+\s+"\\^([0-9\\.]+)(?:\(\$\|,\))?"\s+TAMU_VIP/i', $content, $m)) {
        foreach ($m[1] as $raw) {
            $ip = str_replace('\\.', '.', $raw);
            if ($ip !== '') $ips[$ip] = true;
        }
    }
    return array_keys($ips);
}

function build_setenv_lines($ips, $allowAll = false) {
    $lines = [];
    if ($allowAll && empty($ips)) {
        $lines[] = 'SetEnvIf Remote_Addr ".*" TAMU_VIP';
        return $lines;
    }
    foreach ($ips as $ip) {
        $safe = str_replace('.', '\\.', $ip);
        $lines[] = "SetEnvIf X-Forwarded-For \"^{$safe}($|,)\" TAMU_VIP";
    }
    foreach ($ips as $ip) {
        $safe = str_replace('.', '\\.', $ip);
        $lines[] = "SetEnvIf X-Real-IP \"^{$safe}$\" TAMU_VIP";
    }
    foreach ($ips as $ip) {
        $safe = str_replace('.', '\\.', $ip);
        $lines[] = "SetEnvIf Remote_Addr \"^{$safe}$\" TAMU_VIP";
    }
    return $lines;
}

function replace_vip_block($content, $setenvLines) {
    $lines = preg_split('/\r?\n/', $content);
    $out = [];
    $inVipSection = false;
    foreach ($lines as $line) {
        if (preg_match('/^#\s*4\.\s*LOGIKA DETEKSI IP/i', $line)) {
            $inVipSection = true;
            $out[] = $line;
            continue;
        }
        if ($inVipSection && preg_match('/^#\s*5\./i', $line)) {
            foreach ($setenvLines as $l) {
                $out[] = $l;
            }
            $inVipSection = false;
            $out[] = $line;
            continue;
        }
        if ($inVipSection) {
            if (preg_match('/^SetEnvIf\s+\S+\s+"\\^/i', $line)) {
                continue;
            }
            if (preg_match('/^#\s*=+\s*$/', $line)) {
                continue;
            }
        }
        $out[] = $line;
    }
    if ($inVipSection) {
        foreach ($setenvLines as $l) {
            $out[] = $l;
        }
    }
    return implode("\n", $out);
}

function replace_requireany_blocks($content, $ips) {
    $lines = preg_split('/\r?\n/', $content);
    $out = [];
    $inRequireAny = false;
    $buffer = [];
    $hasVip = false;

    $buildBlock = function() use ($ips) {
        $block = [];
        $block[] = "    Require env TAMU_VIP";
        foreach ($ips as $ip) {
            $block[] = "    Require ip {$ip}";
        }
        $block[] = "    Require ip 127.0.0.1";
        $block[] = "    Require ip ::1";
        return $block;
    };

    foreach ($lines as $line) {
        if (preg_match('/^\s*<RequireAny>\s*$/i', $line)) {
            $inRequireAny = true;
            $buffer = [$line];
            $hasVip = false;
            continue;
        }
        if ($inRequireAny) {
            if (preg_match('/Require\s+env\s+TAMU_VIP/i', $line)) {
                $hasVip = true;
            }
            if (preg_match('/^\s*<\/RequireAny>\s*$/i', $line)) {
                if ($hasVip) {
                    $out = array_merge($out, $buffer);
                    $out = array_merge($out, $buildBlock());
                    $out[] = $line;
                } else {
                    $out = array_merge($out, $buffer);
                    $out[] = $line;
                }
                $inRequireAny = false;
                $buffer = [];
                $hasVip = false;
                continue;
            }
            if ($hasVip && preg_match('/^\s*Require\s+(env|ip)\s+/i', $line)) {
                continue;
            }
            $buffer[] = $line;
            continue;
        }
        $out[] = $line;
    }
    if ($inRequireAny) {
        if ($hasVip) {
            $out = array_merge($out, $buffer);
            $out = array_merge($out, $buildBlock());
        } else {
            $out = array_merge($out, $buffer);
        }
    }
    return implode("\n", $out);
}

function sync_vip_htaccess($env, $htaccessPath) {
    $env_vip_ips = [];
    $allow_all_if_empty = true;
    if (isset($env) && is_array($env)) {
        $env_whitelist = $env['security']['vip_whitelist'] ?? ($env['vip_whitelist'] ?? []);
        $env_allow_all = $env['security']['vip_allow_all_if_empty'] ?? ($env['vip_allow_all_if_empty'] ?? null);
        if ($env_allow_all !== null) {
            $allow_all_if_empty = (bool)$env_allow_all;
        }
        if (is_string($env_whitelist)) {
            $env_vip_ips = normalize_ip_list($env_whitelist);
        } elseif (is_array($env_whitelist)) {
            foreach ($env_whitelist as $v) {
                $v = trim((string)$v);
                if ($v !== '') $env_vip_ips[] = $v;
            }
        }
        $env_vip_ips = array_values(array_unique(array_filter($env_vip_ips, 'is_valid_ip')));
    }

    $db_ips = [];
    try {
        require_once __DIR__ . '/../include/db.php';
        $pdo = app_db();
        $pdo->exec("CREATE TABLE IF NOT EXISTS vip_whitelist (ip TEXT PRIMARY KEY, name TEXT, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
        $stmt = $pdo->query("SELECT ip FROM vip_whitelist");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ip = trim((string)($row['ip'] ?? ''));
            if ($ip !== '' && is_valid_ip($ip)) $db_ips[] = $ip;
        }
    } catch (Exception $e) {
        $db_ips = [];
    }

    $ips = array_values(array_unique(array_merge($db_ips, $env_vip_ips)));
    $allow_all_active = $allow_all_if_empty && empty($ips);

    if (!is_file($htaccessPath) || !is_writable($htaccessPath)) {
        return ['ok' => false, 'message' => 'File .htaccess tidak dapat ditulis.'];
    }

    $content = file_get_contents($htaccessPath);
    if ($content === false) {
        return ['ok' => false, 'message' => 'Gagal membaca .htaccess.'];
    }

    $setenvLines = build_setenv_lines($ips, $allow_all_active);
    $updated = replace_vip_block($content, $setenvLines);
    $updated = replace_requireany_blocks($updated, $ips);
    $writeOk = @file_put_contents($htaccessPath, $updated, LOCK_EX);
    if ($writeOk === false) {
        return ['ok' => false, 'message' => 'Gagal menyimpan .htaccess.'];
    }

    return ['ok' => true, 'message' => 'Whitelist VIP disinkronkan.', 'count' => count($ips)];
}

$is_cli = (PHP_SAPI === 'cli');
$key = '';
if (!$is_cli) {
    $key = trim((string)($_GET['key'] ?? ''));
    if ($key === '' && isset($_SERVER['HTTP_X_WARTELPAS_KEY'])) {
        $key = trim((string)$_SERVER['HTTP_X_WARTELPAS_KEY']);
    }
    $toolsToken = $env['security']['tools']['token'] ?? '';
    if ($toolsToken === '' || $key === '' || !hash_equals((string)$toolsToken, (string)$key)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => 'Invalid token.']);
        exit;
    }
}

$result = sync_vip_htaccess($env, $htaccessPath);

if (!$is_cli) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result);
} else {
    echo ($result['ok'] ? 'OK' : 'ERROR') . ': ' . ($result['message'] ?? '') . PHP_EOL;
}
