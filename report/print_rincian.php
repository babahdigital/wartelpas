<?php
session_start();
error_reporting(0);

if (!isset($_SESSION["mikhmon"])) {
    header("Location:../admin.php?id=login");
    exit;
}

$session = $_GET['session'] ?? '';

include('../include/config.php');
include('../include/readcfg.php');
include_once('../lib/routeros_api.class.php');

$dbFile = dirname(__DIR__) . '/db_data/mikhmon_stats.db';
$cur = isset($currency) ? $currency : 'Rp';
$session_id = $session;

$mode = $_GET['mode'] ?? '';
$req_status = strtolower((string)($_GET['status'] ?? ''));
$is_usage = ($mode === 'usage' || in_array($req_status, ['used','online','rusak','all']));
$filter_user = trim((string)($_GET['user'] ?? ''));
$filter_blok = trim((string)($_GET['blok'] ?? ''));

$filter_date = $_GET['date'] ?? date('Y-m-d');

$usage_label = 'TERPAKAI';
if ($req_status === 'online') $usage_label = 'ONLINE';
elseif ($req_status === 'rusak') $usage_label = 'RUSAK';
elseif ($req_status === 'all') $usage_label = 'SEMUA';

function normalize_block_name_simple($blok_name) {
    $raw = strtoupper(trim((string)$blok_name));
    if ($raw === '') return '';
    $raw = preg_replace('/^BLOK[-_\s]*/', '', $raw);
    if (preg_match('/^([A-Z0-9]+)/', $raw, $m)) {
        $raw = $m[1];
    }
    return 'BLOK-' . $raw;
}

function extract_blok_name($comment) {
    if (empty($comment)) return '';
    if (preg_match('/\bblok\s*[-_]?\s*([A-Za-z0-9]+)/i', $comment, $m)) {
        return 'BLOK-' . strtoupper($m[1]);
    }
    return '';
}

function extract_ip_mac_from_comment($comment) {
    $ip = '';
    $mac = '';
    if (!empty($comment)) {
        if (preg_match('/\bIP\s*:\s*([^|\s]+)/i', $comment, $m)) {
            $ip = trim($m[1]);
        }
        if (preg_match('/\bMAC\s*:\s*([^|\s]+)/i', $comment, $m)) {
            $mac = trim($m[1]);
        }
    }
    return ['ip' => $ip, 'mac' => $mac];
}

function uptime_to_seconds($uptime) {
    if (empty($uptime) || $uptime === '0s') return 0;
    $total = 0;
    if (preg_match_all('/(\d+)(w|d|h|m|s)/i', $uptime, $m, PREG_SET_ORDER)) {
        foreach ($m as $part) {
            $val = (int)$part[1];
            switch (strtolower($part[2])) {
                case 'w': $total += $val * 7 * 24 * 3600; break;
                case 'd': $total += $val * 24 * 3600; break;
                case 'h': $total += $val * 3600; break;
                case 'm': $total += $val * 60; break;
                case 's': $total += $val; break;
            }
        }
    }
    return $total;
}

function extract_datetime_from_comment($comment) {
    if (empty($comment)) return '';
    $first = trim(explode('|', $comment)[0] ?? '');
    if ($first === '') return '';
    $ts = strtotime($first);
    if ($ts === false) return '';
    return date('Y-m-d H:i:s', $ts);
}

function format_date_indo($dateStr) {
    if (empty($dateStr) || $dateStr === '-') return '-';
    $ts = strtotime($dateStr);
    if ($ts === false) return $dateStr;
    return date('d-m-Y H:i:s', $ts);
}

function format_date_only_indo($dateStr) {
    if (empty($dateStr) || $dateStr === '-') return '-';
    $ts = strtotime($dateStr);
    if ($ts === false) return $dateStr;
    return date('d-m-Y', $ts);
}

function format_bytes_short($bytes) {
    $b = (float)$bytes;
    if ($b <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($b >= 1024 && $i < count($units) - 1) {
        $b /= 1024;
        $i++;
    }
    $dec = $i >= 2 ? 2 : 0;
    return number_format($b, $dec, ',', '.') . ' ' . $units[$i];
}

function norm_date_from_raw_report($raw_date) {
    $raw = trim((string)$raw_date);
    if ($raw === '') return '';
    if (preg_match('/^[a-zA-Z]{3}\/\d{2}\/\d{4}$/', $raw)) {
        $mon = strtolower(substr($raw, 0, 3));
        $map = [
            'jan' => '01', 'feb' => '02', 'mar' => '03', 'apr' => '04', 'may' => '05', 'jun' => '06',
            'jul' => '07', 'aug' => '08', 'sep' => '09', 'oct' => '10', 'nov' => '11', 'dec' => '12'
        ];
        $mm = $map[$mon] ?? '';
        if ($mm !== '') {
            $parts = explode('/', $raw);
            return $parts[2] . '-' . $mm . '-' . $parts[1];
        }
    }
    if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $raw)) {
        $parts = explode('/', $raw);
        return $parts[2] . '-' . $parts[0] . '-' . $parts[1];
    }
    return '';
}

$rows = [];
$list = [];
$usage_list = [];

try {
    if (file_exists($dbFile)) {
        $db = new PDO('sqlite:' . $dbFile);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $res = $db->query("SELECT 
                sh.raw_date, sh.raw_time, sh.sale_date, sh.sale_time, sh.sale_datetime,
                sh.username, sh.profile, sh.profile_snapshot,
                sh.price, sh.price_snapshot, sh.sprice_snapshot, sh.validity,
                sh.comment, sh.blok_name, sh.status, sh.is_rusak, sh.is_retur, sh.is_invalid, sh.qty,
                sh.full_raw_data, lh.last_status
            FROM sales_history sh
            LEFT JOIN login_history lh ON lh.username = sh.username
            UNION ALL
            SELECT 
                ls.raw_date, ls.raw_time, ls.sale_date, ls.sale_time, ls.sale_datetime,
                ls.username, ls.profile, ls.profile_snapshot,
                ls.price, ls.price_snapshot, ls.sprice_snapshot, ls.validity,
                ls.comment, ls.blok_name, ls.status, ls.is_rusak, ls.is_retur, ls.is_invalid, ls.qty,
                ls.full_raw_data, lh2.last_status
            FROM live_sales ls
            LEFT JOIN login_history lh2 ON lh2.username = ls.username
            WHERE ls.sync_status = 'pending'
            ORDER BY sale_datetime DESC, raw_date DESC");
        if ($res) $rows = $res->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $rows = [];
}

if ($is_usage && file_exists($dbFile)) {
    $histMap = [];
    try {
        $db = $db ?? new PDO('sqlite:' . $dbFile);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $db->query("SELECT username, blok_name, ip_address, mac_address, last_uptime, last_bytes, login_time_real, logout_time_real, raw_comment, last_status, login_count, first_login_real, last_login_real FROM login_history");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $uname = $row['username'] ?? '';
            if ($uname !== '') $histMap[$uname] = $row;
        }
    } catch (Exception $e) {
        $histMap = [];
    }

    $API = new RouterosAPI();
    $API->debug = false;
    $API->timeout = 5;
    $API->attempts = 1;
    $hotspot_server = $hotspot_server ?? 'wartel';
    $connected = $API->connect($iphost, $userhost, decrypt($passwdhost));
    if ($connected) {
        $all_users = $API->comm("/ip/hotspot/user/print", array(
            "?server" => $hotspot_server,
            ".proplist" => ".id,name,comment,profile,disabled,bytes-in,bytes-out,uptime"
        ));
        $active = $API->comm("/ip/hotspot/active/print", array(
            "?server" => $hotspot_server,
            ".proplist" => "user,uptime,address,mac-address,bytes-in,bytes-out"
        ));
        $API->disconnect();

        $activeMap = [];
        foreach ($active as $a) {
            if (isset($a['user'])) $activeMap[$a['user']] = $a;
        }

        $seen_users = [];
        foreach ($all_users as $u) {
            $name = $u['name'] ?? '';
            if ($name === '') continue;
            if ($filter_user !== '' && $name !== $filter_user) continue;

            $comment = (string)($u['comment'] ?? '');
            $disabled = $u['disabled'] ?? 'false';
            $is_active = isset($activeMap[$name]);

            $f_blok = extract_blok_name($comment);
            $hist = $histMap[$name] ?? null;
            if ($f_blok === '' && $hist && !empty($hist['blok_name'])) {
                $f_blok = normalize_block_name_simple($hist['blok_name']);
            }
            if ($filter_blok !== '') {
                $target_blok = normalize_block_name_simple($filter_blok);
                if ($f_blok === '' || strcasecmp($f_blok, $target_blok) !== 0) continue;
            }

            $f_ip = $is_active ? ($activeMap[$name]['address'] ?? '-') : '-';
            $f_mac = $is_active ? ($activeMap[$name]['mac-address'] ?? '-') : '-';
            if ($f_ip == '-' || $f_mac == '-') {
                $cm = extract_ip_mac_from_comment($comment);
                if ($f_ip == '-' && !empty($cm['ip'])) $f_ip = $cm['ip'];
                if ($f_mac == '-' && !empty($cm['mac'])) $f_mac = $cm['mac'];
            }
            if (!$is_active && $hist) {
                if ($f_ip == '-' && !empty($hist['ip_address'])) $f_ip = $hist['ip_address'];
                if ($f_mac == '-' && !empty($hist['mac_address'])) $f_mac = $hist['mac_address'];
            }

            $bytes_total = ($u['bytes-in'] ?? 0) + ($u['bytes-out'] ?? 0);
            $bytes_active = 0;
            if ($is_active) {
                $bytes_active = ($activeMap[$name]['bytes-in'] ?? 0) + ($activeMap[$name]['bytes-out'] ?? 0);
            }
            $bytes_hist = (int)($hist['last_bytes'] ?? 0);
            $bytes = max((int)$bytes_total, (int)$bytes_active, $bytes_hist);

            $uptime_user = $u['uptime'] ?? '';
            $uptime_hist = $hist['last_uptime'] ?? '';
            $uptime_active = $is_active ? ($activeMap[$name]['uptime'] ?? '') : '';
            $uptime = $uptime_active != '' ? $uptime_active : ($uptime_user != '' ? $uptime_user : ($uptime_hist != '' ? $uptime_hist : '0s'));

            $is_rusak = stripos($comment, 'RUSAK') !== false;
            $is_retur = stripos($comment, '(Retur)') !== false || stripos($comment, 'Retur Ref:') !== false;
            $hist_status = strtolower((string)($hist['last_status'] ?? ''));
            if ($hist_status === 'rusak') $is_rusak = true;
            if ($hist_status === 'retur') $is_retur = true;
            if ($disabled === 'true') $is_rusak = true;
            if ($is_retur && $hist_status !== 'rusak' && $disabled !== 'true') $is_rusak = false;
            if ($is_rusak || $hist_status === 'rusak') $is_retur = false;

            $hist_used = $hist && (
                in_array($hist_status, ['online','terpakai','rusak','retur']) ||
                !empty($hist['login_time_real']) ||
                !empty($hist['logout_time_real']) ||
                (!empty($hist['last_uptime']) && $hist['last_uptime'] != '0s') ||
                (int)($hist['last_bytes'] ?? 0) > 0
            );
            $is_used = (!$is_retur && !$is_rusak && $disabled !== 'true') &&
                ($is_active || $bytes > 50 || $uptime != '0s' || $hist_used);

            $status = 'READY';
            if ($is_active) $status = 'ONLINE';
            elseif ($is_rusak) $status = 'RUSAK';
            elseif ($disabled === 'true') $status = 'RUSAK';
            elseif ($is_retur) $status = 'RETUR';
            elseif ($is_used) $status = 'TERPAKAI';
            if ($status === 'READY') continue;

            $status_match = true;
            if ($req_status === 'online') $status_match = ($status === 'ONLINE');
            elseif ($req_status === 'rusak') $status_match = ($status === 'RUSAK');
            elseif ($req_status === 'used' || $req_status === 'terpakai') $status_match = ($status === 'TERPAKAI');
            elseif ($req_status === 'all') $status_match = in_array($status, ['RUSAK','TERPAKAI']);
            else $status_match = ($status === 'TERPAKAI');

            if (!$status_match) continue;
            if ($status === 'READY') continue;

            $seen_users[$name] = true;

            $login_time = $hist['login_time_real'] ?? '';
            $logout_time = $hist['logout_time_real'] ?? '';
            if ($logout_time === '') {
                $logout_time = extract_datetime_from_comment($comment);
            }
            if ($status === 'ONLINE') {
                $logout_time = '-';
                // gunakan waktu login dari DB agar presisi sama dengan users.php
                if ($login_time === '') {
                    $u_sec = uptime_to_seconds($uptime);
                    if ($u_sec > 0) {
                        $login_time = date('Y-m-d H:i:s', time() - $u_sec);
                    }
                }
            }
            $has_usage = ($bytes > 0) || ($uptime != '' && $uptime != '0s') || (!empty($hist['login_time_real']) || !empty($hist['logout_time_real']));
            if ($status === 'TERPAKAI' && !$has_usage) {
                continue;
            }
            if ($login_time === '' && $logout_time !== '' && $logout_time !== '-') {
                $u_sec = uptime_to_seconds($uptime);
                if ($u_sec > 0) {
                    $login_time = date('Y-m-d H:i:s', strtotime($logout_time) - $u_sec);
                } else {
                    $login_time = $logout_time;
                }
            }
            if ($login_time === '') $login_time = '-';
            if ($logout_time === '') $logout_time = '-';

            $relogin = ((int)($hist['login_count'] ?? 0) > 1) || (!empty($hist['first_login_real']) && !empty($hist['last_login_real']) && $hist['first_login_real'] !== $hist['last_login_real']);
            $usage_list[] = [
                'login' => $login_time,
                'logout' => $logout_time,
                'username' => $name,
                'blok' => $f_blok ?: '-',
                'ip' => $f_ip,
                'mac' => $f_mac,
                'uptime' => $uptime,
                'bytes' => $bytes,
                'status' => strtolower($status),
                'comment' => $comment,
                'relogin' => $relogin
            ];
        }

        // Tambahkan data history-only jika user sudah hilang dari Mikrotik
        foreach ($histMap as $uname => $row) {
            if (isset($seen_users[$uname])) continue;
            if ($filter_user !== '' && $uname !== $filter_user) continue;
            $hist_status = strtolower((string)($row['last_status'] ?? ''));
            if (!in_array($hist_status, ['rusak','retur','terpakai','online'])) continue;
            $status = ($hist_status === 'rusak') ? 'RUSAK' : ($hist_status === 'retur' ? 'RETUR' : 'TERPAKAI');
            if ($req_status === 'online') continue;
            if ($req_status === 'rusak' && $status !== 'RUSAK') continue;
            if ($req_status === 'used' && $status !== 'TERPAKAI') continue;
            if ($req_status === 'all' && !in_array($status, ['RUSAK','TERPAKAI'])) continue;

            $comment = (string)($row['raw_comment'] ?? '');
            $f_blok = normalize_block_name_simple($row['blok_name'] ?? '') ?: extract_blok_name($comment);
            if ($filter_blok !== '') {
                $target_blok = normalize_block_name_simple($filter_blok);
                if ($f_blok === '' || strcasecmp($f_blok, $target_blok) !== 0) continue;
            }

            $login_time = $row['login_time_real'] ?? '-';
            $logout_time = $row['logout_time_real'] ?? '-';
            $has_usage = ((int)($row['last_bytes'] ?? 0) > 0) || (!empty($row['last_uptime']) && $row['last_uptime'] != '0s') || (!empty($row['login_time_real']) || !empty($row['logout_time_real']));
            if ($status === 'TERPAKAI' && !$has_usage) continue;
            $relogin = ((int)($row['login_count'] ?? 0) > 1) || (!empty($row['first_login_real']) && !empty($row['last_login_real']) && $row['first_login_real'] !== $row['last_login_real']);
            $usage_list[] = [
                'login' => $login_time,
                'logout' => $logout_time,
                'username' => $uname,
                'blok' => $f_blok ?: '-',
                'ip' => $row['ip_address'] ?? '-',
                'mac' => $row['mac_address'] ?? '-',
                'uptime' => $row['last_uptime'] ?? '-',
                'bytes' => (int)($row['last_bytes'] ?? 0),
                'status' => strtolower($status),
                'comment' => $comment,
                'relogin' => $relogin
            ];
        }
    }
}

foreach ($rows as $r) {
    $sale_date = $r['sale_date'] ?: norm_date_from_raw_report($r['raw_date'] ?? '');
    if ($sale_date !== $filter_date) continue;

    $price = (int)($r['price_snapshot'] ?? $r['price'] ?? 0);
    $comment = (string)($r['comment'] ?? '');
    $status = strtolower((string)($r['status'] ?? ''));
    $lh_status = strtolower((string)($r['last_status'] ?? ''));
    $profile = $r['profile_snapshot'] ?? ($r['profile'] ?? '-');
    $cmt_low = strtolower($comment);

    if ($status === '' || $status === 'normal') {
        if (strpos($cmt_low, 'invalid') !== false) $status = 'invalid';
        elseif (strpos($cmt_low, 'rusak') !== false || $lh_status === 'rusak') $status = 'rusak';
        elseif (strpos($cmt_low, 'retur') !== false || $lh_status === 'retur') $status = 'retur';
        else $status = 'normal';
    }

    $gross_add = ($status === 'retur' || $status === 'invalid') ? 0 : $price;
    $loss_rusak = ($status === 'rusak') ? $price : 0;
    $loss_invalid = ($status === 'invalid') ? $price : 0;
    $net_add = $gross_add - $loss_rusak - $loss_invalid;

    $list[] = [
        'time' => $r['sale_time'] ?: ($r['raw_time'] ?? ''),
        'username' => $r['username'] ?? '-',
        'profile' => $profile,
        'comment' => $comment,
        'status' => $status,
        'price' => $price,
        'gross' => $gross_add,
        'net' => $net_add
    ];
}

function esc($s){ return htmlspecialchars((string)$s); }
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title><?= $is_usage ? 'Bukti Pemakaian Voucher' : 'Print Rincian Harian' ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: Arial, sans-serif; color:#111; margin:20px; }
        h2 { margin:0 0 6px 0; }
        .meta { font-size:12px; color:#555; margin-bottom:12px; }
        .toolbar { margin-bottom:14px; display:flex; gap:8px; flex-wrap:wrap; }
        .btn { padding:6px 10px; border:1px solid #999; background:#f2f2f2; cursor:pointer; border-radius:4px; font-size:12px; }
        table { width:100%; border-collapse:collapse; font-size:12px; }
        th, td { border:1px solid #ddd; padding:6px; text-align:left; vertical-align:top; }
        th { background:#f5f5f5; }
        .status-normal { color:#0a7f2e; font-weight:700; }
        .status-rusak { color:#d35400; font-weight:700; }
        .status-retur { color:#7f8c8d; font-weight:700; }
        .status-invalid { color:#c0392b; font-weight:700; }
        .status-online { color:#1976d2; font-weight:700; }
        .status-terpakai { color:#0a7f2e; font-weight:700; }
        @media print { .toolbar { display:none; } }
    </style>
</head>
<body>
    <div class="toolbar">
        <button class="btn" onclick="window.print()">Print / Download PDF</button>
        <button class="btn" onclick="shareReport()">Share</button>
    </div>

    <?php if ($is_usage): ?>
            <h2>Bukti Pemakaian Voucher (<?= esc($usage_label) ?>)</h2>
            <div class="meta">
        <?php if ($filter_user !== ''): ?>User: <?= esc($filter_user) ?> | <?php endif; ?>
        <?php if ($filter_blok !== ''): ?>Blok: <?= esc($filter_blok) ?> | <?php endif; ?>
                Status: <?= esc($usage_label) ?> | 
                Tanggal Cetak: <?= esc(format_date_indo(date('Y-m-d H:i:s'))) ?>
      </div>

      <table>
          <thead>
              <tr>
                  <th>Login</th>
                  <th>Logout</th>
                  <th>Username</th>
                  <th>Blok</th>
                  <th>IP</th>
                  <th>MAC</th>
                  <th>Uptime</th>
                  <th>Bytes</th>
                  <th>Status</th>
              </tr>
          </thead>
          <tbody>
              <?php if (empty($usage_list)): ?>
                  <tr><td colspan="9" style="text-align:center;">Tidak ada data</td></tr>
              <?php else: ?>
                  <?php foreach ($usage_list as $it): ?>
                  <tr>
                      <td><?= esc(format_date_indo($it['login'])) ?></td>
                      <td><?= esc(format_date_indo($it['logout'])) ?></td>
                      <td><?= esc($it['username']) ?></td>
                      <td><?= esc($it['blok']) ?></td>
                      <td><?= esc($it['ip']) ?></td>
                      <td><?= esc($it['mac']) ?></td>
                      <td><?= esc($it['uptime']) ?></td>
                      <td><?= esc(format_bytes_short($it['bytes'])) ?></td>
                                            <?php
                                                $st = strtoupper((string)($it['status'] ?? ''));
                                                $st_class = 'status-normal';
                                                if ($st === 'ONLINE') $st_class = 'status-online';
                                                elseif ($st === 'RUSAK') $st_class = 'status-rusak';
                                                elseif ($st === 'TERPAKAI') $st_class = 'status-terpakai';
                                            ?>
                                            <?php if (!empty($it['relogin'])): $st = $st . ' / RELOGIN'; endif; ?>
                                            <td class="<?= esc($st_class) ?>"><?= esc($st) ?></td>
                  </tr>
                  <?php endforeach; ?>
              <?php endif; ?>
          </tbody>
      </table>
    <?php else: ?>
    <h2>Rincian Transaksi Harian</h2>
    <div class="meta">Tanggal: <?= esc(format_date_only_indo($filter_date)) ?></div>

      <table>
          <thead>
              <tr>
                  <th>Jam</th>
                  <th>Username</th>
                  <th>Profile</th>
                  <th>Catatan</th>
                  <th>Status</th>
                  <th>Harga</th>
                  <th>Bruto</th>
                  <th>Netto</th>
              </tr>
          </thead>
          <tbody>
              <?php if (empty($list)): ?>
                  <tr><td colspan="8" style="text-align:center;">Tidak ada data</td></tr>
              <?php else: ?>
                  <?php foreach ($list as $it): ?>
                  <tr>
                      <td><?= esc($it['time']) ?></td>
                      <td><?= esc($it['username']) ?></td>
                      <td><?= esc($it['profile']) ?></td>
                      <td><?= esc($it['comment']) ?></td>
                      <td class="status-<?= esc($it['status']) ?>"><?= strtoupper(esc($it['status'])) ?></td>
                      <td><?= $cur ?> <?= number_format((int)$it['price'],0,',','.') ?></td>
                      <td><?= $cur ?> <?= number_format((int)$it['gross'],0,',','.') ?></td>
                      <td><?= $cur ?> <?= number_format((int)$it['net'],0,',','.') ?></td>
                  </tr>
                  <?php endforeach; ?>
              <?php endif; ?>
          </tbody>
      </table>
    <?php endif; ?>

<script>
function shareReport(){
    const title = <?= $is_usage ? "'Bukti Pemakaian Voucher'" : "'Rincian Transaksi Harian'" ?>;
    if (navigator.share) {
        navigator.share({
            title: title,
            url: window.location.href
        });
    } else {
        window.prompt('Salin link laporan:', window.location.href);
    }
}
</script>
</body>
</html>
