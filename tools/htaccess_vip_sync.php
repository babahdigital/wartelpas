<?php
error_reporting(0);

$rootDir = dirname(__DIR__);
$htaccessPath = $rootDir . '/.htaccess';
$htaccessTemplatePath = $rootDir . '/htaccess-templated';

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
        $lines[] = "SetEnvIf CF-Connecting-IP \"^{$safe}$\" TAMU_VIP";
    }
    foreach ($ips as $ip) {
        $safe = str_replace('.', '\\.', $ip);
        $lines[] = "SetEnvIf True-Client-IP \"^{$safe}$\" TAMU_VIP";
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

function replace_global_gate($content, $publicAccess = false) {
    $target = $publicAccess ? 'Require all granted' : 'Require all denied';
    $pattern = '/(#\s*A\.\s*DEFAULT:\s*TOLAK\s*SEMUA\s*\R)\s*Require\s+all\s+(?:denied|granted)/i';
    $updated = preg_replace($pattern, '$1' . $target, $content, 1);
    return is_string($updated) ? $updated : $content;
}

function replace_requireany_blocks($content, $ips, $publicAccess = false) {
    $lines = preg_split('/\r?\n/', $content);
    $out = [];
    $inRequireAny = false;
    $buffer = [];
    $hasVip = false;

    $buildBlock = function() use ($ips, $publicAccess) {
        $block = [];
        if ($publicAccess) {
            $block[] = "    Require all granted";
            return $block;
        }
        $block[] = "    Require env TAMU_VIP";
        $block[] = "    Require env TAMU_VIP_ACCESS";
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

function get_htaccess_base_content($primaryPath, $templatePath) {
    $primary = is_file($primaryPath) ? @file_get_contents($primaryPath) : false;
    if ($primary !== false && trim((string)$primary) !== '') {
        return $primary;
    }
    $template = is_file($templatePath) ? @file_get_contents($templatePath) : false;
    if ($template !== false && trim((string)$template) !== '') {
        return $template;
    }
    return false;
}

function write_htaccess_targets($updatedContent, $primaryPath, $templatePath) {
    $targets = [$primaryPath, $templatePath];
    foreach ($targets as $target) {
        $dir = dirname($target);
        if (!is_dir($dir) || !is_writable($dir)) {
            return ['ok' => false, 'message' => 'Direktori target tidak dapat ditulis: ' . $dir];
        }
        if (is_file($target)) {
            @chmod($target, 0666);
            if (!is_writable($target)) {
                return ['ok' => false, 'message' => 'File tidak dapat ditulis: ' . $target];
            }
            @file_put_contents($target . '.bak', (string)@file_get_contents($target));
        }
    }

    foreach ($targets as $target) {
        $ok = @file_put_contents($target, $updatedContent, LOCK_EX);
        if ($ok === false) {
            return ['ok' => false, 'message' => 'Gagal menyimpan file: ' . $target];
        }
    }

    return ['ok' => true, 'message' => 'OK'];
}

function sync_vip_htaccess($env, $htaccessPath, $htaccessTemplatePath, $dryRun = false) {
    $env_vip_ips = [];
    $allow_all_if_empty = true;
    $public_access = false;
    if (isset($env) && is_array($env)) {
        $env_whitelist = $env['security']['vip_whitelist'] ?? ($env['vip_whitelist'] ?? []);
        $env_allow_all = $env['security']['vip_allow_all_if_empty'] ?? ($env['vip_allow_all_if_empty'] ?? null);
        $public_access = (bool)($env['security']['public_access'] ?? ($env['public_access'] ?? false));
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

    $content = get_htaccess_base_content($htaccessPath, $htaccessTemplatePath);
    if ($content === false) {
        return ['ok' => false, 'message' => 'Gagal membaca sumber konfigurasi .htaccess/.htaccess-templated.'];
    }

    if (empty($ips)) {
        $existing_ips = extract_vip_ips($content);
        if (!empty($existing_ips)) {
            $ips = array_values(array_unique(array_filter($existing_ips, 'is_valid_ip')));
            $allow_all_active = $allow_all_if_empty && empty($ips);
        }
    }

    $setenvLines = build_setenv_lines($ips, $allow_all_active);
    $updated = replace_vip_block($content, $setenvLines);
    $updated = replace_global_gate($updated, $public_access);
    $updated = replace_requireany_blocks($updated, $ips, $public_access);
    if ($dryRun) {
        $currentPrimary = is_file($htaccessPath) ? (string)@file_get_contents($htaccessPath) : '';
        $currentTemplate = is_file($htaccessTemplatePath) ? (string)@file_get_contents($htaccessTemplatePath) : '';
        return [
            'ok' => true,
            'dry_run' => true,
            'message' => 'Dry-run: tidak ada file yang ditulis.',
            'count' => count($ips),
            'changes' => [
                '.htaccess' => ($currentPrimary !== $updated),
                'htaccess-templated' => ($currentTemplate !== $updated)
            ]
        ];
    } else {
        $writeResult = write_htaccess_targets($updated, $htaccessPath, $htaccessTemplatePath);
        if (!$writeResult['ok']) {
            return $writeResult;
        }
    }

    return ['ok' => true, 'message' => 'Whitelist VIP disinkronkan ke .htaccess dan htaccess-templated.', 'count' => count($ips)];
}

$is_cli = (PHP_SAPI === 'cli');
$dryRun = false;
$cliArgs = [];
if ($is_cli && isset($_SERVER['argv']) && is_array($_SERVER['argv'])) {
    $cliArgs = $_SERVER['argv'];
    $dryRun = in_array('--dry-run', $cliArgs, true);
}
$key = '';
if (!$is_cli) {
    $dryRun = isset($_GET['dry_run']) && (string)$_GET['dry_run'] === '1';
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

$result = sync_vip_htaccess($env, $htaccessPath, $htaccessTemplatePath, $dryRun);

if (!$is_cli) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result);
} else {
    if (!empty($result['dry_run'])) {
        echo 'DRY-RUN: ' . ($result['message'] ?? '') . PHP_EOL;
        if (isset($result['changes']) && is_array($result['changes'])) {
            foreach ($result['changes'] as $target => $changed) {
                echo '- ' . $target . ': ' . ($changed ? 'CHANGED' : 'UNCHANGED') . PHP_EOL;
            }
        }
    } else {
        echo ($result['ok'] ? 'OK' : 'ERROR') . ': ' . ($result['message'] ?? '') . PHP_EOL;
    }
}
