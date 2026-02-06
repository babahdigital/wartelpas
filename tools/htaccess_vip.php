<?php
session_start();
error_reporting(0);

if (!isset($_SESSION["mikhmon"])) {
    header("Location:../admin.php?id=login");
    exit;
}

require_once __DIR__ . '/../include/acl.php';
require_once __DIR__ . '/../include/db.php';

if (!isSuperAdmin()) {
    echo "<div style='padding:14px;font-family:Arial;'>Akses ditolak.</div>";
    exit;
}

$htaccessPath = dirname(__DIR__) . '/.htaccess';
$is_embed = isset($_GET['embed']) && $_GET['embed'] === '1';
$vip_whitelist_no_render = isset($vip_whitelist_no_render) ? (bool)$vip_whitelist_no_render : false;
$vip_whitelist_action = isset($vip_whitelist_action) ? (string)$vip_whitelist_action : '';
$status = '';
$error = '';
$ips = [];
$ip_names = [];
$env_vip_ips = [];
$allow_all_if_empty = true;
$render_ips = [];

if (!empty($_SESSION['vip_whitelist_flash']) && is_array($_SESSION['vip_whitelist_flash'])) {
    $status = (string)($_SESSION['vip_whitelist_flash']['status'] ?? '');
    $error = (string)($_SESSION['vip_whitelist_flash']['error'] ?? '');
    unset($_SESSION['vip_whitelist_flash']);
}

// Helper functions
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
        // Match X-Forwarded-For: ^IP($|,)
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
        // Deteksi header section VIP
        if (preg_match('/^#\s*4\.\s*LOGIKA DETEKSI IP/i', $line)) {
            $inVipSection = true;
            $out[] = $line;
            continue;
        }
        // Deteksi header section berikutnya (mis: # 5. GERBANG...)
        if ($inVipSection && preg_match('/^#\s*5\./i', $line)) {
            foreach ($setenvLines as $l) {
                $out[] = $l;
            }
            $inVipSection = false;
            $out[] = $line;
            continue;
        }
        // Hapus baris VIP lama dalam section
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
    // Jika section adalah akhir file
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
    $hasRequireAny = false;

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
            $hasRequireAny = true;
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

            if ($hasVip) {
                if (preg_match('/^\s*Require\s+(env|ip)\s+/i', $line)) {
                    continue;
                }
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

// Load existing IPs
if (is_file($htaccessPath)) {
    $content = file_get_contents($htaccessPath);
    if ($content !== false) {
        $ips = extract_vip_ips($content);
    }
}

// Load env VIP whitelist (optional)
$env = [];
$envFile = __DIR__ . '/../include/env.php';
if (is_file($envFile)) {
    require $envFile;
}
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

// Database Init
try {
    $pdo = app_db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS vip_whitelist (
        ip TEXT PRIMARY KEY,
        name TEXT,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $stmt = $pdo->query("SELECT ip, name FROM vip_whitelist");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $ip = (string)($row['ip'] ?? '');
        if ($ip === '') continue;
        $ip_names[$ip] = (string)($row['name'] ?? '');
    }
} catch (Exception $e) {
    $ip_names = [];
}

if (empty($ips) && !empty($ip_names)) {
    $ips = array_keys($ip_names);
}
if (!empty($env_vip_ips)) {
    $ips = array_values(array_unique(array_merge($ips, $env_vip_ips)));
}
$render_ips = !empty($env_vip_ips) ? array_values(array_diff($ips, $env_vip_ips)) : $ips;

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vip_whitelist'])) {
    $add_ip = trim((string)($_POST['add_ip'] ?? ''));
    $add_name = trim((string)($_POST['add_name'] ?? ''));
    $edit_ip_old = trim((string)($_POST['edit_ip_old'] ?? ''));
    $keep_ips = $_POST['keep_ips'] ?? [];
    $keep_names = $_POST['keep_name'] ?? [];
    $remove_ips = $_POST['remove_ips'] ?? [];
    $remove_ip_single = trim((string)($_POST['remove_ip_single'] ?? ''));
    
    if (!is_array($keep_ips)) $keep_ips = [];
    if (!is_array($keep_names)) $keep_names = [];
    if (!is_array($remove_ips)) $remove_ips = [];
    if ($remove_ip_single !== '' && !in_array($remove_ip_single, $remove_ips, true)) {
        $remove_ips[] = $remove_ip_single;
    }
    $has_remove = !empty($remove_ips);
    if ($has_remove) {
        if ($add_name === '' && $add_ip !== '') {
            $add_ip = '';
        }
        if ($add_ip === '' && $add_name !== '') {
            $add_name = '';
        }
    }

    $final = [];
    foreach ($keep_ips as $ip) {
        $ip = trim((string)$ip);
        if ($ip === '' || in_array($ip, $remove_ips, true)) continue;
        if ($edit_ip_old !== '' && $ip === $edit_ip_old) continue;
        if (!is_valid_ip($ip)) continue;
        $name = trim((string)($keep_names[$ip] ?? ''));
        if ($name === '') {
            $error = 'Nama wajib diisi untuk semua IP yang aktif.';
            break;
        }
        $final[$ip] = $name;
    }

    if ($error === '' && $edit_ip_old !== '' && $add_ip === '') {
        $error = 'IP wajib diisi untuk edit.';
    }

    if ($add_ip !== '' || $add_name !== '') {
        if ($add_ip === '') {
            $error = 'IP wajib diisi.';
        } elseif ($add_name === '') {
            $error = 'Nama wajib diisi.';
        } elseif (!is_valid_ip($add_ip)) {
            $error = 'IP tidak valid.';
        } else {
            $final[$add_ip] = $add_name;
        }
    }
    $final_db = $final;
    if ($error === '' && !empty($env_vip_ips)) {
        foreach ($env_vip_ips as $env_ip) {
            if (!is_valid_ip($env_ip)) continue;
            if (!isset($final[$env_ip])) {
                $final[$env_ip] = $ip_names[$env_ip] ?? 'ENV';
            }
        }
    }

    $ips = array_keys($final);
    $allow_all_active = $allow_all_if_empty && empty($ips);

    if ($error === '') {
        // Coba perbaiki permission dari sisi PHP sebelum menulis
        if (is_file($htaccessPath)) {
            @chmod($htaccessPath, 0666);
        }

        if (!is_file($htaccessPath) || !is_writable($htaccessPath)) {
             // Upaya terakhir permission fix
            if (is_file($htaccessPath)) {
                @chmod($htaccessPath, 0666);
            }
            if (!is_writable($htaccessPath)) {
                $perm = substr(sprintf('%o', fileperms($htaccessPath)), -4);
                $owner = fileowner($htaccessPath);
                $error = "File .htaccess tidak dapat ditulis. (Perm: $perm, Owner: $owner). Pastikan Entrypoint Docker sudah melakukan 'chown www-data'.";
            }
        }

        if ($error === '') {
            $content = file_get_contents($htaccessPath);
            if ($content === false) {
                $error = 'Gagal membaca .htaccess.';
            } else {
                // Backup
                @file_put_contents($htaccessPath . '.bak', $content);
                
                // Process logic
                $setenvLines = $allow_all_active ? build_setenv_lines([], true) : build_setenv_lines($ips, false);
                $updated = replace_vip_block($content, $setenvLines);
                $updated = replace_requireany_blocks($updated, $ips);
                
                // Write with Lock
                $writeOk = @file_put_contents($htaccessPath, $updated, LOCK_EX);
                
                if ($writeOk !== false) {
                    $status = 'Whitelist VIP diperbarui.';
                    // Update DB
                    try {
                        $pdo->beginTransaction();
                        $stmtUp = $pdo->prepare("INSERT INTO vip_whitelist (ip, name, updated_at) VALUES (:ip, :name, CURRENT_TIMESTAMP)
                            ON CONFLICT(ip) DO UPDATE SET name=excluded.name, updated_at=CURRENT_TIMESTAMP");
                        foreach ($final_db as $ip => $name) {
                            $stmtUp->execute([':ip' => $ip, ':name' => $name]);
                        }
                        if (!empty($final_db)) {
                            $placeholders = implode(',', array_fill(0, count($final_db), '?'));
                            $stmtDel = $pdo->prepare("DELETE FROM vip_whitelist WHERE ip NOT IN ($placeholders)");
                            $stmtDel->execute(array_keys($final_db));
                        } else {
                            $pdo->exec("DELETE FROM vip_whitelist");
                        }
                        $pdo->commit();
                    } catch (Exception $e) {
                        // Silent db error
                    }
                } else {
                    $error = 'Gagal menyimpan .htaccess (Write Failed).';
                }
            }
        }
    }

    if ($vip_whitelist_action !== '') {
        $_SESSION['vip_whitelist_flash'] = ['status' => $status, 'error' => $error];
        $_SESSION['vip_whitelist_autoshow'] = 1;
        header('Location: ' . $vip_whitelist_action);
        exit;
    }
}

function vip_whitelist_render_form($status, $error, $ips, $ip_names, $htaccessPath, $action = '') {
        $action = (string)$action;
        $action_attr = $action !== '' ? (' action="' . htmlspecialchars($action) . '"') : '';
        ?>
        <div>
            <div class="m-status-text" style="text-align:center; padding-top:0; margin-bottom:12px;">
                VIP Whitelist<br>
            </div>
                <input type="hidden" name="edit_ip_old" id="vip-edit-old" value="">

            <?php if ($status): ?>
                <div class="m-alert m-alert-info" data-auto-close="1" style="margin-bottom:12px; position:relative;">
                    <i class="fa fa-check-circle" style="font-size: 16px !important;"></i>
                    <div><?= htmlspecialchars($status) ?></div>
                    <button type="button" aria-label="Close" onclick="this.parentElement.style.display='none';" style="position:absolute; right:10px; top:8px; background:transparent; border:none; color:inherit; font-size:16px; cursor:pointer;">×</button>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="m-alert m-alert-danger" data-auto-close="1" style="margin-bottom:12px; position:relative;">
                    <i class="fa fa-exclamation-triangle" style="font-size: 16px !important;"></i>
                    <div><?= htmlspecialchars($error) ?></div>
                    <button type="button" aria-label="Close" onclick="this.parentElement.style.display='none';" style="position:absolute; right:10px; top:8px; background:transparent; border:none; color:inherit; font-size:16px; cursor:pointer;">×</button>
                </div>
            <?php endif; ?>

            <form method="post"<?= $action_attr; ?> id="vip-whitelist-form">
                <input type="hidden" name="vip_whitelist" value="1">
                <input type="hidden" name="remove_ip_single" id="vip-remove-ip" value="">
                <div class="m-pass-form m-pass-grid">
                    <div class="m-pass-row">
                        <label class="m-pass-label">Nama</label>
                        <input type="text" name="add_name" id="vip-add-name" class="m-pass-input" placeholder="Nama pemilik" required>
                    </div>
                    <div class="m-pass-row">
                        <label class="m-pass-label">IP</label>
                        <input type="text" name="add_ip" id="vip-add-ip" class="m-pass-input" placeholder="Contoh: 10.10.0.6" required>
                    </div>
                </div>
                <div style="margin-top:12px; text-align: left; margin-bottom: 20px;">
                    <button type="submit" class="m-btn m-btn-success"><i class="fa fa-save" style="font-size: 14px !important;"></i> Simpan & Terapkan</button>
                </div>

                <div class="m-pass-divider" style="margin-top:14px;"></div>
                <div style="font-size:12px; color:#9ca3af; font-weight:600; margin-bottom:10px; margin-top:20px; text-align: left;">Daftar IP VIP (aktif)</div>

                <?php if (empty($ips)): ?>
                    <div class="admin-empty" style="padding:10px;">Belum ada IP VIP.</div>
                <?php else: ?>
                    <?php foreach ($ips as $ip): ?>
                        <?php $nameVal = (string)($ip_names[$ip] ?? ''); ?>
                        <div class="router-item" style="margin-bottom:8px; text-align: left;">
                            <div class="router-icon"><i class="fa fa-shield" style="font-size: 14px !important;"></i></div>
                            <div class="router-info">
                                <span class="router-name"><?= htmlspecialchars($nameVal !== '' ? $nameVal : $ip) ?></span>
                                <span class="router-session">IP: <?= htmlspecialchars($ip) ?></span>
                            </div>
                            <div class="router-actions">
                                <a href="javascript:void(0)" title="Edit" class="vip-edit" data-ip="<?= htmlspecialchars($ip) ?>" data-name="<?= htmlspecialchars($nameVal) ?>"><i class="fa fa-pencil" style="font-size: 14px !important;"></i></a>
                                <a href="javascript:void(0)" title="Hapus" class="vip-remove" data-ip="<?= htmlspecialchars($ip) ?>" style="margin-left:6px; color:#dc2626;"><i class="fa fa-trash" style="font-size: 14px !important;"></i></a>
                            </div>
                            <input type="hidden" name="keep_ips[]" value="<?= htmlspecialchars($ip) ?>">
                            <input type="hidden" name="keep_name[<?= htmlspecialchars($ip) ?>]" value="<?= htmlspecialchars($nameVal) ?>">
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </form>
            <script>
            (function(){
                var alerts = document.querySelectorAll('.m-popup-backdrop.show [data-auto-close="1"]');
                if (!alerts || !alerts.length) return;
                setTimeout(function(){
                    alerts.forEach(function(el){ if (el && el.style.display !== 'none') el.style.display = 'none'; });
                }, 3000);
            })();
            </script>
        </div>
        <?php
}
?>