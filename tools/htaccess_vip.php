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

function build_setenv_lines($ips) {
    $lines = [];
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
        // Deteksi separator section berikutnya (===)
        if ($inVipSection && preg_match('/^#\s*=+/', $line)) {
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
                continue;
            }
            $buffer[] = $line;
            continue;
        }
        $out[] = $line;
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

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vip_whitelist'])) {
    $add_ip = trim((string)($_POST['add_ip'] ?? ''));
    $add_name = trim((string)($_POST['add_name'] ?? ''));
    $keep_ips = $_POST['keep_ips'] ?? [];
    $keep_names = $_POST['keep_name'] ?? [];
    $remove_ips = $_POST['remove_ips'] ?? [];
    
    if (!is_array($keep_ips)) $keep_ips = [];
    if (!is_array($keep_names)) $keep_names = [];
    if (!is_array($remove_ips)) $remove_ips = [];

    $final = [];
    foreach ($keep_ips as $ip) {
        $ip = trim((string)$ip);
        if ($ip === '' || in_array($ip, $remove_ips, true)) continue;
        if (!is_valid_ip($ip)) continue;
        $name = trim((string)($keep_names[$ip] ?? ''));
        if ($name === '') {
            $error = 'Nama wajib diisi untuk semua IP yang aktif.';
            break;
        }
        $final[$ip] = $name;
    }

    if ($add_ip !== '') {
        if ($add_name === '') {
            $error = 'Nama wajib diisi.';
        } elseif (!is_valid_ip($add_ip)) {
            $error = 'IP tidak valid.';
        } else {
            $final[$add_ip] = $add_name;
        }
    }
    $ips = array_keys($final);

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
                $setenvLines = build_setenv_lines($ips);
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
                        foreach ($final as $ip => $name) {
                            $stmtUp->execute([':ip' => $ip, ':name' => $name]);
                        }
                        if (!empty($final)) {
                            $placeholders = implode(',', array_fill(0, count($final), '?'));
                            $stmtDel = $pdo->prepare("DELETE FROM vip_whitelist WHERE ip NOT IN ($placeholders)");
                            $stmtDel->execute(array_keys($final));
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
}

function vip_whitelist_render_form($status, $error, $ips, $ip_names, $htaccessPath, $action = '') {
        $action = (string)$action;
        $action_attr = $action !== '' ? (' action="' . htmlspecialchars($action) . '"') : '';
        ?>
        <div class="vip-shell">
            <div class="card-modern" style="margin-bottom:16px;">
                <div class="card-header-modern" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
                    <div>
                        <div class="vip-title"><i class="fa fa-shield"></i> VIP Whitelist Generator (.htaccess)</div>
                        <div class="vip-meta">File: <?= htmlspecialchars($htaccessPath) ?></div>
                    </div>
                </div>
                <div class="card-body-modern">
                    <?php if ($status): ?>
                        <div class="alert alert-success" style="margin-bottom:12px;"><?= htmlspecialchars($status) ?></div>
                    <?php endif; ?>
                    <?php if ($error): ?>
                        <div class="alert alert-danger" style="margin-bottom:12px;"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <form method="post"<?= $action_attr; ?>>
                        <input type="hidden" name="vip_whitelist" value="1">
                        <div class="vip-grid" style="margin-bottom:12px;">
                            <div class="form-group-modern">
                                <label class="form-label">Nama</label>
                                <input type="text" name="add_name" class="m-pass-input" placeholder="Nama pemilik" required>
                            </div>
                            <div class="form-group-modern">
                                <label class="form-label">IP</label>
                                <input type="text" name="add_ip" class="m-pass-input" placeholder="Contoh: 10.10.0.6" required>
                            </div>
                        </div>
                        <div style="margin-bottom:16px;">
                            <button type="submit" class="btn-action"><i class="fa fa-save"></i> Simpan & Terapkan</button>
                        </div>

                        <div class="form-group-modern">
                            <label class="form-label">Daftar IP VIP (aktif)</label>
                            <?php if (empty($ips)): ?>
                                <div class="admin-empty" style="padding:10px;">Belum ada IP VIP.</div>
                            <?php else: ?>
                                <div style="overflow:auto;">
                                    <table class="table table-sm vip-table">
                                        <thead>
                                            <tr>
                                                <th>IP</th>
                                                <th>Nama</th>
                                                <th style="width:80px;">Hapus</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($ips as $ip): ?>
                                                <tr>
                                                    <td class="ip-cell">
                                                        <?= htmlspecialchars($ip) ?>
                                                        <input type="hidden" name="keep_ips[]" value="<?= htmlspecialchars($ip) ?>">
                                                    </td>
                                                    <td>
                                                        <input type="text" name="keep_name[<?= htmlspecialchars($ip) ?>]" class="m-pass-input" value="<?= htmlspecialchars($ip_names[$ip] ?? '') ?>" required>
                                                    </td>
                                                    <td style="text-align:center;">
                                                        <input type="checkbox" name="remove_ips[]" value="<?= htmlspecialchars($ip) ?>">
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                            <div class="vip-note" style="margin-top:8px;">Centang kolom hapus untuk mengeluarkan IP. Backup otomatis tersimpan di .htaccess.bak</div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>VIP Whitelist Generator</title>
    <link rel="stylesheet" href="../admin_assets/admin.css">
    <link rel="stylesheet" href="../css/admin.css">
    <link rel="stylesheet" href="../css/popup.css">
    <style>
                body { background:<?= $is_embed ? 'transparent' : '#0f172a' ?>; padding:<?= $is_embed ? '0' : '16px' ?>; }
                .vip-shell { max-width: <?= $is_embed ? '100%' : '980px' ?>; margin: 0 auto; }
                .vip-title { display:flex; align-items:center; gap:8px; font-weight:700; }
                .vip-meta { font-size:12px; color:var(--text-secondary); margin-top:4px; }
                .vip-grid { display:grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap:16px; }
                .vip-table td input.m-pass-input { height: 34px; }
                .vip-note { font-size:12px; color:var(--text-secondary); }
                .ip-cell { font-family: monospace; }
                @media (max-width: 720px) { .vip-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <?php if (!$vip_whitelist_no_render): ?>
        <?php vip_whitelist_render_form($status, $error, $ips, $ip_names, $htaccessPath, $vip_whitelist_action); ?>
    <?php endif; ?>
</body>
</html>