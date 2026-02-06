<?php
session_start();
error_reporting(0);

if (!isset($_SESSION["mikhmon"])) {
    header("Location:../admin.php?id=login");
    exit;
}

require_once __DIR__ . '/../include/acl.php';
if (!isSuperAdmin()) {
    echo "<div style='padding:14px;font-family:Arial;'>Akses ditolak.</div>";
    exit;
}

$htaccessPath = dirname(__DIR__) . '/.htaccess';
$status = '';
$error = '';
$ips = [];

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $add = normalize_ip_list($_POST['add_ips'] ?? '');
    $keep = normalize_ip_list($_POST['keep_ips'] ?? '');
    $final = [];
    foreach (array_merge($keep, $add) as $ip) {
        if ($ip === '') continue;
        $final[$ip] = true;
    }
    $ips = array_keys($final);

    if (!is_file($htaccessPath) || !is_readable($htaccessPath) || !is_writable($htaccessPath)) {
        $error = 'File .htaccess tidak dapat dibaca/ditulis.';
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
    body { font-family: Arial, Helvetica, sans-serif; background:#0f172a; color:#e5e7eb; padding:20px; }
    .card { background:#111827; border:1px solid #1f2937; border-radius:10px; padding:16px; max-width:900px; margin:0 auto; }
    .title { font-size:18px; font-weight:700; margin-bottom:10px; }
    .meta { font-size:12px; color:#9ca3af; margin-bottom:12px; }
    .ok { background:#14532d; color:#bbf7d0; padding:8px 10px; border-radius:6px; margin-bottom:10px; }
    .err { background:#7f1d1d; color:#fecaca; padding:8px 10px; border-radius:6px; margin-bottom:10px; }
    .row { display:flex; gap:12px; flex-wrap:wrap; }
    .col { flex:1 1 260px; }
    textarea, input[type=text] { width:100%; background:#0b1220; color:#e5e7eb; border:1px solid #1f2937; border-radius:8px; padding:10px; }
    .btn { padding:10px 14px; border:0; border-radius:6px; background:#22c55e; color:#fff; cursor:pointer; font-weight:600; }
    .btn:disabled { opacity:0.6; cursor:not-allowed; }
    .list { margin:0; padding-left:18px; font-size:13px; }
    .note { font-size:12px; color:#cbd5e1; margin-top:8px; }
    label { font-size:12px; color:#cbd5db; display:block; margin-bottom:6px; }
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
      <div class="row">
        <div class="col">
          <label>Daftar IP VIP (aktif)</label>
          <textarea name="keep_ips" rows="8" placeholder="Satu IP per baris"><?php echo htmlspecialchars(implode("\n", $ips)); ?></textarea>
          <div class="note">IP di sini akan dipertahankan.</div>
        </div>
        <div class="col">
          <label>Tambah IP baru</label>
          <textarea name="add_ips" rows="8" placeholder="Pisahkan dengan koma/spasi/baris baru"></textarea>
          <div class="note">Contoh: 10.10.0.6, 172.16.8.21</div>
        </div>
      </div>
      <div style="margin-top:12px;">
        <button type="submit" class="btn">Simpan & Terapkan</button>
      </div>
    </form>

    <div class="note">Catatan: backup otomatis tersimpan di .htaccess.bak</div>
  </div>
</body>
</html>
