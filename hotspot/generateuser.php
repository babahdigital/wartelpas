<?php
/*
 * Copyright (C) 2018 Laksamadi Guko.
 * SECURITY UPGRADE: Anti-CSRF, Anti-Bot, & Silent Defense
 * Code Owner: Pak Dul (WartelPas)
 * REBUILD STYLE: Midnight Modern UI (Fixed Layout)
 */
// Cek session start
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Sembunyikan error
error_reporting(0);
ini_set('max_execution_time', 300);

// --- 1. GENERATE CSRF TOKEN ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// --- HELPER FUNCTIONS ---
if (!function_exists('extract_blok_name')) {
    function extract_blok_name($comment) {
        if (empty($comment)) return '';
        if (preg_match('/\bblok\s*[-_]?\s*([A-Za-z0-9]+)/i', $comment, $m)) {
            return 'BLOK-' . strtoupper($m[1]);
        }
        return '';
    }
}

if (!function_exists('extract_ip_mac_from_comment')) {
    function extract_ip_mac_from_comment($comment) {
        $ip = ''; $mac = '';
        if (!empty($comment)) {
            if (preg_match('/\bIP\s*:\s*([^|\s]+)/i', $comment, $m)) $ip = trim($m[1]);
            if (preg_match('/\bMAC\s*:\s*([^|\s]+)/i', $comment, $m)) $mac = trim($m[1]);
        }
        return ['ip' => $ip, 'mac' => $mac];
    }
}

$session = isset($_GET['session']) ? $_GET['session'] : '';

if (!isset($_SESSION["mikhmon"])) {
    header("Location:../admin.php?id=login");
    exit();
} else {
    date_default_timezone_set($_SESSION['timezone']);
    $genprof = isset($_GET['genprof']) ? $_GET['genprof'] : "";

    // --- LOGIC DETAIL PROFIL ---
    if ($genprof != "") {
        $getprofile = $API->comm("/ip/hotspot/user/profile/print", array("?name" => "$genprof"));
        if (isset($getprofile[0])) {
            $ponlogin = $getprofile[0]['on-login'];
            $getprice = explode(",", $ponlogin)[2];
            $getprice = ($getprice == "0") ? "" : $getprice;
            $getvalid = explode(",", $ponlogin)[3];
            $getlocku = explode(",", $ponlogin)[6];
            $getlocku = ($getlocku == "") ? "Disable" : $getlocku;

            if ($currency == in_array($currency, $cekindo['indo'])) {
                $getprice = $currency . " " . number_format((float)$getprice, 0, ",", ".");
            } else {
                $getprice = $currency . " " . number_format((float)$getprice);
            }
            // Disimpan dalam variabel untuk ditampilkan di bawah
            $ValidPriceInfo = [
                'valid' => $getvalid,
                'price' => $getprice,
                'lock'  => $getlocku
            ];
        }
    }

    $getprofile_list = $API->comm("/ip/hotspot/user/profile/print");

    // --- PROSES GENERATE USER ---
    if (isset($_POST['qty'])) {
        // CSRF Check
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
             echo "<script>window.location.href='./error.php';</script>"; exit();
        }
        // Rate Limit
        if (isset($_SESSION['last_gen_time']) && (time() - $_SESSION['last_gen_time'] < 5)) {
             echo "<script>window.location='./?hotspot-user=generate&session=" . $session . "'</script>"; exit();
        }
        $_SESSION['last_gen_time'] = time();

        // Ambil Data
        $qty = (int)$_POST['qty']; 
        $adcomment = isset($_POST['adcomment']) ? trim($_POST['adcomment']) : "";
        $profile = ($_POST['profile']);
        $userl = ($_POST['userl']);
        $prefix = isset($_POST['prefix']) ? $_POST['prefix'] : ""; 

        // Security Checkpoint
        $violation = false;
        if ($qty < 50) { $violation = true; }
        if (substr($adcomment, 0, 5) !== 'Blok-') { $violation = true; }
        $allowed_profiles = ['10Menit', '30Menit'];
        if (!in_array($profile, $allowed_profiles)) { $violation = true; }
        if ($violation) { echo "<script>window.location.href='./error.php';</script>"; exit(); }

        $timelimit = ($profile == '10Menit') ? "10m" : (($profile == '30Menit') ? "30m" : "0");
        
        // Prepare Data
        $getprofile = $API->comm("/ip/hotspot/user/profile/print", array("?name" => "$profile"));
        $ponlogin = $getprofile[0]['on-login'];
        $getvalid = explode(",", $ponlogin)[3];
        $getprice = explode(",", $ponlogin)[2];
        $getsprice = explode(",", $ponlogin)[4];
        $getlock = explode(",", $ponlogin)[6];
        
        $_SESSION['ubp'] = $profile;
        $server = $hotspot_server ?? 'wartel';
        $user = "vc";
        $datalimit = 0;
        
        $commt = $user . "-" . rand(100, 999) . "-" . date("m.d.y") . "-" . $adcomment;
        $gentemp = $commt . "|~" . $profile . "~" . $getvalid . "~" . $getprice . "!".$getsprice."~" . $timelimit . "~" . $datalimit . "~" . $getlock;
        $gen = '<?php $genu="'.encrypt($gentemp).'";?>';
        
        $handle = fopen('./voucher/temp.php', 'w');
        fwrite($handle, $gen);
        fclose($handle);

        $u = array();
        for ($i = 1; $i <= $qty; $i++) {
            $p[$i] = randNLC($userl); 
            $u[$i] = "$prefix$p[$i]";
        }

        // Add to Router
        for ($i = 1; $i <= $qty; $i++) {
            $API->comm("/ip/hotspot/user/add", array(
                "server" => "$server",      
                "name" => "$u[$i]",
                "password" => "$u[$i]",     
                "profile" => "$profile",
                "limit-uptime" => "$timelimit",
                "limit-bytes-total" => "0", 
                "comment" => "$commt",
            ));
        }
        echo "<script>window.location='./?hotspot-user=generate&session=" . $session . "'</script>";
    }

    // --- LOGIC DATA RINGKASAN ---
    $blockSummary = [];
    $totalRusak = 0;
    $totalRetur = 0;

    $active_list = $API->comm('/ip/hotspot/active/print', ['?server' => ($hotspot_server ?? 'wartel'), '.proplist' => 'user']);
    $activeMap = [];
    foreach ($active_list as $a) { if (isset($a['user'])) $activeMap[$a['user']] = true; }

    $all_users = $API->comm('/ip/hotspot/user/print', ['?server' => ($hotspot_server ?? 'wartel'), '.proplist' => 'name,comment,disabled,bytes-in,bytes-out,uptime']);

    foreach ($all_users as $u) {
        $name = $u['name'] ?? '';
        $comment = $u['comment'] ?? '';
        $disabled = $u['disabled'] ?? 'false';
        $is_active = isset($activeMap[$name]);
        $bytes = (int)($u['bytes-in'] ?? 0) + (int)($u['bytes-out'] ?? 0);
        $uptime = $u['uptime'] ?? '';
        $cm = extract_ip_mac_from_comment($comment);

        $is_rusak = (stripos($comment, 'RUSAK') !== false) || ($disabled === 'true');
        $is_retur = (stripos($comment, '(Retur)') !== false);
        if ($is_rusak) $is_retur = false;

        $is_used = (!$is_retur && !$is_rusak && $disabled !== 'true') &&
            ($is_active || $bytes > 50 || ($uptime !== '' && $uptime !== '0s') || (($cm['ip'] ?? '') !== ''));

        $status = 'READY';
        if ($is_active) $status = 'ONLINE';
        elseif ($is_rusak) $status = 'RUSAK';
        elseif ($is_retur) $status = 'RETUR';
        elseif ($is_used) $status = 'TERPAKAI';

        if ($status === 'RUSAK') $totalRusak++;
        if ($status === 'RETUR') $totalRetur++;
        if ($status === 'READY') {
            $blok = extract_blok_name($comment);
            if ($blok !== '') {
                if (!isset($blockSummary[$blok])) $blockSummary[$blok] = 0;
                $blockSummary[$blok]++;
            }
        }
    }
    if (!empty($blockSummary)) ksort($blockSummary, SORT_NATURAL | SORT_FLAG_CASE);
}
?>

<style>
    :root {
        --bg-main: #1e2129;      /* Background Utama Gelap */
        --bg-card: #262935;      /* Background Card */
        --bg-input: #323542;     /* Input Field */
        --border-c: #3e4252;     /* Border Color */
        --text-pri: #e6e6e6;     /* Teks Utama Putih/Abu Terang */
        --text-sec: #9ca3af;     /* Teks Sekunder Abu */
        --accent: #3b82f6;       /* Biru Utama */
        --accent-hover: #2563eb; /* Biru Hover */
        --danger: #ef4444;       /* Merah */
        --warning: #f59e0b;      /* Kuning */
    }

    /* Layout Utilities */
    .row-eq-height {
        display: flex;
        flex-wrap: wrap;
    }

    .text-right {
        text-align: right !important;
    }
    
    .card-modern {
        background-color: var(--bg-card);
        color: var(--text-pri);
        border: 1px solid var(--border-c);
        border-radius: 8px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.2);
        display: flex;
        flex-direction: column;
        height: 100%; /* Agar tinggi card mengikuti kolom */
        position: relative;
        margin-left: 30px;
    }

    .card-header-mod {
        padding: 15px 20px;
        border-bottom: 1px solid var(--border-c);
        background: rgba(0,0,0,0.1);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .card-header-mod h3 {
        margin: 0; font-size: 1.1rem; font-weight: 600; color: var(--text-pri);
    }

    .card-body-mod {
        padding: 20px;
        flex-grow: 1; /* Isi card akan mengisi ruang kosong */
        display: flex;
        flex-direction: column;
    }

    .gen-wrapper { padding: 16px 18px; }
    @media (min-width: 992px) {
        .gen-wrapper { padding: 20px 26px; }
    }

    /* Form Styles */
    .form-group label {
        color: var(--text-sec);
        font-size: 0.85rem;
        margin-bottom: 5px;
        display: block;
    }

    .form-control-mod {
        width: 100%;
        background-color: var(--bg-input);
        border: 1px solid var(--border-c);
        color: var(--text-pri);
        padding: 10px 12px;
        border-radius: 6px;
        font-size: 0.95rem;
        transition: border 0.2s;
        margin-bottom: 5px;
        min-height: 42px;
    }

    .form-control-mod:focus {
        border-color: var(--accent);
        outline: none;
        box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2);
    }

    /* Lock / Readonly Field */
    .locked-input {
        background-color: #2a2d38 !important;
        border: 1px dashed #4b5563 !important;
        color: #9ca3af !important;
        cursor: not-allowed;
        font-family: monospace;
    }

    /* Generate Button */
    .btn-generate {
        background: linear-gradient(to right, var(--accent), var(--accent-hover));
        color: white;
        border: none;
        width: 100%;
        padding: 12px;
        border-radius: 6px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-top: auto; /* Dorong ke paling bawah */
        cursor: pointer;
        transition: transform 0.1s;
    }
    .btn-generate:hover { transform: translateY(-1px); box-shadow: 0 4px 10px rgba(59, 130, 246, 0.3); }

    /* Summary & Table */
    .table-dark-mod {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.9rem;
    }
    .table-dark-mod th {
        text-align: left;
        color: var(--text-sec);
        padding: 8px 0;
        border-bottom: 1px solid var(--border-c);
    }
    .table-dark-mod td {
        padding: 8px 0;
        border-bottom: 1px solid #323542;
        color: var(--text-pri);
    }
    
    /* Scroll area untuk list blok jika terlalu panjang */
    .summary-scroll {
        max-height: 380px; /* Sesuaikan agar tidak terlalu panjang */
        overflow-y: auto;
        padding-right: 5px;
        margin-bottom: 20px;
    }

    .info-server {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }
    .info-server .form-control-mod {
        flex: 1 1 220px;
    }

    /* FOOTER STATS (Rusak/Retur) */
    .footer-stats-container {
        margin-top: auto; /* Tempel di bawah card ringkasan */
        padding-top: 15px;
        border-top: 1px solid var(--border-c);
        display: flex;
        justify-content: center;
        gap: 40px;
    }
    
    .stat-item {
        text-align: center;
        padding: 0 10px;
    }
    
    .stat-val {
        font-size: 2rem;
        font-weight: 700;
        line-height: 1;
        display: block;
        margin-bottom: 5px;
    }
    
    .stat-label {
        font-size: 0.75rem;
        color: var(--text-sec);
        text-transform: uppercase;
        letter-spacing: 1px;
    }
    
    .text-red { color: var(--danger); }
    .text-yellow { color: var(--warning); }
    .text-info-xxs { font-size: 0.75rem; color: var(--text-sec); margin-top: 4px; }
    
    /* Custom Scrollbar */
    ::-webkit-scrollbar { width: 6px; }
    ::-webkit-scrollbar-track { background: var(--bg-main); }
    ::-webkit-scrollbar-thumb { background: #4b5563; border-radius: 3px; }
</style>

<div class="container-fluid gen-wrapper">
    <div class="row row-eq-height g-3">
        
        <div class="col-8" style="margin-left: -30px;">
            <div class="card-modern">
                <div class="card-header-mod">
                    <h3><i class="fa fa-cogs"></i> Konfigurasi Voucher</h3>
                    <small id="loader" style="display:none;" class="text-warning"><i class="fa fa-circle-o-notch fa-spin"></i> Proses...</small>
                </div>
                <div class="card-body-mod">
                    <form autocomplete="off" method="post" action="./?hotspot-user=generate&session=<?= $session; ?>" style="display: flex; flex-direction: column; height: 100%;">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="session" value="<?= $session; ?>">

                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <div class="form-group">
                                    <label>Jumlah (Pcs)</label>
                                    <input type="number" name="qty" id="qtyInput" class="form-control-mod" value="50" min="50" max="500" required>
                                    <div class="text-danger text-info-xxs">*Minimal 50 User</div>
                                </div>
                            </div>
                            <div class="col-12 col-md-6">
                                <div class="form-group">
                                    <label>Panjang Karakter</label>
                                    <select name="userl" class="form-control-mod">
                                        <option value="6">6 Digit</option>
                                        <option value="7">7 Digit</option>
                                        <option value="8">8 Digit</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <div class="form-group">
                                    <label>Profil Paket</label>
                                    <select name="profile" id="uprof" class="form-control-mod" onchange="GetVP(); updateTimeLimit();" required>
                                        <?php 
                                        $allowedProfiles = ['10Menit', '30Menit'];
                                        if ($genprof != "" && in_array($genprof, $allowedProfiles)) {
                                            echo "<option selected>" . $genprof . "</option>";
                                        }
                                        if (!empty($getprofile_list)) {
                                            foreach ($getprofile_list as $p) {
                                                if (in_array($p['name'], $allowedProfiles) && $p['name'] != $genprof) {
                                                    echo "<option>" . $p['name'] . "</option>";
                                                }
                                            }
                                        } else {
                                            echo "<option value=\"\" disabled>Tidak ada profil</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-12 col-md-6">
                                <div class="form-group">
                                    <label>Batas Waktu</label>
                                    <input type="text" id="timelimit" name="timelimit_display" class="form-control-mod locked-input" readonly value="-">
                                </div>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-12">
                                <div class="form-group">
                                    <label>Komentar (Kode Blok)</label>
                                    <select name="adcomment" class="form-control-mod" required>
                                        <?php
                                        foreach(range('A', 'F') as $blk) {
                                            foreach(['10', '30'] as $suf) echo "<option value='Blok-$blk$suf'>Blok-$blk$suf</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                            <div class="row g-3">
                                <div class="col-12">
                                <div class="form-group">
                                    <label>Info Server</label>
                                    <div class="info-server">
                                        <input type="text" class="form-control-mod locked-input" value="Server: <?= htmlspecialchars($hotspot_server ?? 'wartel') ?>" readonly>
                                        <input type="text" class="form-control-mod locked-input" value="Mode: User=Pass" readonly>
                                    </div>
                                    <input type="hidden" name="user" value="vc">
                                </div>
                            </div>
                        </div>

                        <div id="GetValidPrice" style="margin-bottom: 20px;">
                            <?php 
                            if ($genprof != "" && isset($ValidPriceInfo)) {
                                echo "<div style='background: rgba(59,130,246,0.1); padding:10px; border-radius:5px; border:1px solid rgba(59,130,246,0.2); font-size:0.85rem;'>";
                                echo "<i class='fa fa-info-circle text-primary'></i> <b>Info:</b> Validitas: {$ValidPriceInfo['valid']} | Harga: {$ValidPriceInfo['price']}";
                                echo "</div>";
                            }
                            ?>
                        </div>

                        <button type="submit" name="save" onclick="return validateForm()" class="btn-generate">
                            <i class="fa fa-bolt mr-2"></i> GENERATE VOUCHER
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-4">
            <div class="card-modern">
                <div class="card-header-mod">
                    <h3><i class="fa fa-list-alt"></i> Ringkasan (READY)</h3>
                </div>
                <div class="card-body-mod">
                    
                    <div class="summary-scroll">
                        <?php if (!empty($blockSummary)): ?>
                            <table class="table-dark-mod">
                                <thead>
                                    <tr>
                                        <th>Kode Blok</th>
                                        <th class="text-right">Jumlah</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($blockSummary as $blok => $count): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($blok) ?></td>
                                        <td class="text-right" style="font-weight:bold; color: #10b981;"><?= (int)$count ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="text-center" style="padding: 40px 0; color: var(--text-sec);">
                                <i class="fa fa-inbox fa-3x mb-3" style="opacity: 0.3"></i><br>
                                Belum ada stok Ready.
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="footer-stats-container">
                        <div class="stat-item">
                            <span class="stat-val text-red"><?= (int)$totalRusak ?></span>
                            <span class="stat-label">VOUCHER RUSAK</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-val text-yellow"><?= (int)$totalRetur ?></span>
                            <span class="stat-label">VOUCHER RETUR</span>
                        </div>
                    </div>

                </div>
            </div>
        </div>

    </div>
</div>

<script>
function GetVP(){
  var prof = document.getElementById('uprof').value;
  // Reload div via AJAX
  $("#GetValidPrice").load("./process/getvalidprice.php?name="+prof+"&session=<?= $session; ?> #getdata", function(response, status, xhr) {
      if (status == "error") {
          console.log("Error loading price info");
      }
  });
} 

function updateTimeLimit() {
    var prof = document.getElementById('uprof').value;
    var timeField = document.getElementById('timelimit');
    
    if (prof === '10Menit') {
        timeField.value = '10m';
    } else if (prof === '30Menit') {
        timeField.value = '30m';
    } else {
        timeField.value = '-';
    }
}

function validateForm() {
    var qty = document.getElementById('qtyInput').value;
    if (qty < 50) {
        alert("PERHATIAN: Minimal generate harus 50 user!");
        return false;
    }
    document.getElementById('loader').style.display = 'inline-block';
    return true;
}

$(document).ready(function() {
    updateTimeLimit();
    GetVP();
});
</script>