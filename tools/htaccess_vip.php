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
$status = '';
$error = '';
$ips = [];
$ip_names = [];

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
        if ($inVipSection && preg_match('/^#\s*=+/', $line)) {
            // end section before next separator
            foreach ($setenvLines as $l) {
                $out[] = $l;
            }
            $inVipSection = false;
            $out[] = $line;
            continue;
        }
        if ($inVipSection) {
            if (preg_match('/^SetEnvIf\s+\S+\s+"\\^/i', $line)) {
                continue; // skip old SetEnvIf VIP lines
            }
        }
        $out[] = $line;
    }
    // If section never closed, append at end
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

if (is_file($htaccessPath)) {
    $content = file_get_contents($htaccessPath);
    if ($content !== false) {
        $ips = extract_vip_ips($content);
    }
}

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
            $error = 'IP tidak valid. Gunakan format IPv4/IPv6 yang benar.';
        } else {
            $final[$add_ip] = $add_name;
        }
    }
    $ips = array_keys($final);

    if ($error !== '') {
        // skip update
    } elseif (!is_file($htaccessPath) || !is_readable($htaccessPath) || !is_writable($htaccessPath)) {
        if (is_file($htaccessPath) && is_readable($htaccessPath)) {
            @chmod($htaccessPath, 0666);
        }
        if (!is_file($htaccessPath) || !is_readable($htaccessPath) || !is_writable($htaccessPath)) {
            $error = 'File .htaccess tidak dapat dibaca/ditulis. Pastikan file sudah di-mount ke container dan permission write untuk www-data.';
        }
    } else {
        $content = file_get_contents($htaccessPath);
        if ($content === false) {
            $error = 'Gagal membaca .htaccess.';
        } else {
            $backup = $htaccessPath . '.bak';
            @file_put_contents($backup, $content);
            $setenvLines = build_setenv_lines($ips);
            $updated = replace_vip_block($content, $setenvLines);
            $updated = replace_requireany_blocks($updated, $ips);
            if (file_put_contents($htaccessPath, $updated) !== false) {
                $status = 'Whitelist VIP diperbarui.';
                try {
                    $pdo = app_db();
                    $pdo->exec("CREATE TABLE IF NOT EXISTS vip_whitelist (
                        ip TEXT PRIMARY KEY,
                        name TEXT,
                        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
                    )");
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
                }
            } else {
                $error = 'Gagal menyimpan .htaccess.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>VIP Whitelist Generator</title>
  <style>
        :root {
            --popup-text: #e5e7eb;
            --popup-muted: #94a3b8;
            --popup-border: #1f2937;
            --popup-primary: #3b82f6;
            --popup-success: #22c55e;
        }
        body { font-family: Arial, Helvetica, sans-serif; background:<?= $is_embed ? 'transparent' : '#0f172a' ?>; color:var(--popup-text); padding:<?= $is_embed ? '0' : '20px' ?>; }
        .card { background:#111827; border:1px solid #1f2937; border-radius:10px; padding:16px; max-width:<?= $is_embed ? '100%' : '900px' ?>; margin:<?= $is_embed ? '0' : '0 auto' ?>; }
    .title { font-size:18px; font-weight:700; margin-bottom:10px; }
    .meta { font-size:12px; color:#9ca3af; margin-bottom:12px; }
    .ok { background:#14532d; color:#bbf7d0; padding:8px 10px; border-radius:6px; margin-bottom:10px; }
    .err { background:#7f1d1d; color:#fecaca; padding:8px 10px; border-radius:6px; margin-bottom:10px; }
    .row { display:flex; gap:12px; flex-wrap:wrap; }
        .col { flex:1 1 260px; }
        .m-pass-form { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px 16px; }
        .m-pass-row { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
        .m-pass-row.m-span-2 { grid-column: 1 / -1; }
        .m-pass-label { font-size: 12px; color: var(--popup-muted); font-weight: 600; }
        .m-pass-input {
            height: 38px;
            border-radius: 8px;
            border: 1px solid var(--popup-border);
            background: #1b1f24;
            color: var(--popup-text);
            padding: 0 12px;
            font-size: 13px;
            outline: none;
            width: 100%;
        }
        .m-pass-input:focus { border-color: var(--popup-primary); box-shadow: 0 0 0 2px rgba(47, 129, 247, 0.15); }
        .m-btn { padding:10px 14px; border:0; border-radius:8px; background:var(--popup-success); color:#0f172a; cursor:pointer; font-weight:700; font-size:13px; }
        .m-btn:disabled { opacity:0.6; cursor:not-allowed; }
    .list { margin:0; padding-left:18px; font-size:13px; }
    .note { font-size:12px; color:#cbd5e1; margin-top:8px; }
        label { font-size:12px; color:#cbd5db; display:block; margin-bottom:6px; }
        table { width:100%; border-collapse: collapse; font-size:13px; }
        th, td { border-bottom:1px solid #1f2937; padding:8px 6px; text-align:left; }
        th { color:#9ca3af; font-size:11px; text-transform:uppercase; letter-spacing:.5px; }
        .ip-cell { font-family: monospace; }
        @media (max-width: 720px) {
            .m-pass-form { grid-template-columns: 1fr; }
        }
  </style>
</head>
<body>
  <div class="card">
    <div class="title">VIP Whitelist Generator (.htaccess)</div>
    <div class="meta">File: <?= htmlspecialchars($htaccessPath) ?></div>

    <?php if ($status): ?>
      <div class="ok"><?= htmlspecialchars($status) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="err"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post">
            <div class="m-pass-form">
                <div class="m-pass-row">
                    <label class="m-pass-label">Nama</label>
                    <input type="text" name="add_name" class="m-pass-input" placeholder="Nama pemilik" required>
                </div>
                <div class="m-pass-row">
                    <label class="m-pass-label">IP</label>
                    <input type="text" name="add_ip" class="m-pass-input" placeholder="Contoh: 10.10.0.6" required>
                </div>
            </div>
            <div style="margin-top:12px;">
                <button type="submit" class="m-btn">Simpan & Terapkan</button>
            </div>

            <div style="margin-top:16px;">
                <label>Daftar IP VIP (aktif)</label>
                <?php if (empty($ips)): ?>
                    <div class="note">Belum ada IP VIP.</div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>IP</th>
                                <th>Nama</th>
                                <th>Hapus</th>
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
                                        <input type="text" name="keep_name[<?= htmlspecialchars($ip) ?>]" class="m-pass-input" value="<?= htmlspecialchars($ip_names[$ip] ?? '') ?>" placeholder="Nama pemilik" required>
                                    </td>
                                    <td style="text-align:center;">
                                        <input type="checkbox" name="remove_ips[]" value="<?= htmlspecialchars($ip) ?>">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                <div class="note">Centang kolom hapus untuk mengeluarkan IP.</div>
            </div>
        </form>

    <div class="note">Catatan: backup otomatis tersimpan di .htaccess.bak</div>
  </div>
</body>
</html>
