<?php
/*
 * Copyright (C) 2018 Laksamadi Guko.
 * SECURITY UPGRADE: Anti-CSRF, Anti-Bot, & Silent Defense
 * Code Owner: Pak Dul (WartelPas)
 */
// Cek session start, jika belum aktif (misal akses langsung), start session.
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Sembunyikan error
error_reporting(0);
ini_set('max_execution_time', 300);

// --- 1. GENERATE CSRF TOKEN (PENGAMAN FORMULIR) ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

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
}

$session = isset($_GET['session']) ? $_GET['session'] : '';

if (!isset($_SESSION["mikhmon"])) {
    header("Location:../admin.php?id=login");
    exit();
} else {
    // Set Timezone
    date_default_timezone_set($_SESSION['timezone']);
    $genprof = isset($_GET['genprof']) ? $_GET['genprof'] : "";

    // --- LOGIC DETAIL PROFIL (Visual Only) ---
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
            $ValidPrice = "<b>Validity : " . $getvalid . " | Price : " . $getprice . " | Lock User : " . $getlocku . "</b>";
        }
    }

    // --- PROSES GENERATE USER (DENGAN SECURITY LAYER) ---
    if (isset($_POST['qty'])) {
        
        // --- SECURITY CHECK 1: CSRF TOKEN VALIDATION ---
        // Jika token dari form tidak sama dengan session, ini serangan!
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
             echo "<script>window.location.href='./error.php';</script>";
             exit();
        }

        // --- SECURITY CHECK 2: RATE LIMITING (ANTI-BOT) ---
        // Mencegah tombol dipencet berulang kali dalam waktu singkat (5 detik)
        if (isset($_SESSION['last_gen_time']) && (time() - $_SESSION['last_gen_time'] < 5)) {
             // Diam-diam tolak, jangan kasih notif
             echo "<script>window.location='./?hotspot-user=generate&session=" . $session . "'</script>";
             exit();
        }
        $_SESSION['last_gen_time'] = time(); // Catat waktu eksekusi

        // AMBIL VARIABEL
        $qty = (int)$_POST['qty']; 
        $adcomment = isset($_POST['adcomment']) ? trim($_POST['adcomment']) : "";
        $profile = ($_POST['profile']);
        $userl = ($_POST['userl']);
        $prefix = isset($_POST['prefix']) ? $_POST['prefix'] : ""; 

        // =======================================================================
        // SECURITY CHECKPOINT (SILENT MODE)
        // =======================================================================
        $server = "wartel";       
        $user   = "vc";          
        $char   = "mix";          
        $mbgb   = 1048576;        
        $datalimit = 0;           

        $violation = false;
        if ($qty < 50) { $violation = true; }
        if (substr($adcomment, 0, 5) !== 'Blok-') { $violation = true; }
        $allowed_profiles = ['10Menit', '30Menit'];
        if (!in_array($profile, $allowed_profiles)) { $violation = true; }

        if ($violation) {
             echo "<script>window.location.href='./error.php';</script>";
             exit();
        }

        if ($profile == '10Menit') {
            $timelimit = "10m";
        } elseif ($profile == '30Menit') {
            $timelimit = "30m";
        } else {
            $timelimit = "0"; 
        }

        // --- END SECURITY CHECKPOINT ---
        
        $getprofile = $API->comm("/ip/hotspot/user/profile/print", array("?name" => "$profile"));
        $ponlogin = $getprofile[0]['on-login'];
        $getvalid = explode(",", $ponlogin)[3];
        $getprice = explode(",", $ponlogin)[2];
        $getsprice = explode(",", $ponlogin)[4];
        $getlock = explode(",", $ponlogin)[6];
        
        $_SESSION['ubp'] = $profile;
        
        $commt = $user . "-" . rand(100, 999) . "-" . date("m.d.y") . "-" . $adcomment;
        
        $gentemp = $commt . "|~" . $profile . "~" . $getvalid . "~" . $getprice . "!".$getsprice."~" . $timelimit . "~" . $datalimit . "~" . $getlock;
        $gen = '<?php $genu="'.encrypt($gentemp).'";?>';
        $temp = './voucher/temp.php';
        $handle = fopen($temp, 'w') or die('Cannot open file:  ' . $temp);
        fwrite($handle, $gen);
        fclose($handle);

        $u = array();
        for ($i = 1; $i <= $qty; $i++) {
            $p[$i] = randNLC($userl); 
            $u[$i] = "$prefix$p[$i]";
        }

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

    // --- VISUALISASI HASIL ---
    $getprofile = $API->comm("/ip/hotspot/user/profile/print");
    
    if(file_exists('./voucher/temp.php')){
        include_once('./voucher/temp.php');
        if(isset($genu)){
            $decrypted_genu = decrypt($genu);
            $genuser = explode("-", $decrypted_genu);
            $genuser1 = explode("~", $decrypted_genu);
            
            if(count($genuser) > 1 && count($genuser1) > 1){
                $ucode = $genuser[1];
                $udate = $genuser[2];
                $uprofile = $genuser1[1];
                $uvalid = $genuser1[2];
                $uprice = explode("!",$genuser1[3])[0];
                $suprice = isset(explode("!",$genuser1[3])[1]) ? explode("!",$genuser1[3])[1] : "-";
                $utlimit = $genuser1[4];
                $udlimit = $genuser1[5];
                $ulock = isset($genuser1[6]) ? $genuser1[6] : "-";
                
                $urlprint = explode("|", $decrypted_genu)[0];

                if ($currency == in_array($currency, $cekindo['indo'])) {
                    $uprice = (is_numeric($uprice)) ? $currency . " " . number_format((float)$uprice, 0, ",", ".") : $uprice;
                    $suprice = (is_numeric($suprice)) ? $currency . " " . number_format((float)$suprice, 0, ",", ".") : $suprice;
                } else {
                    $uprice = (is_numeric($uprice)) ? $currency . " " . number_format((float)$uprice) : $uprice;
                    $suprice = (is_numeric($suprice)) ? $currency . " " . number_format((float)$suprice) : $suprice;
                }
            }
        }
    }

    // --- RINGKASAN SISA VOUCHER PER BLOK + TOTAL RETUR/RUSAK ---
    $blockSummary = [];
    $totalRusak = 0;
    $totalRetur = 0;
    $totalReady = 0;

    $active_list = $API->comm('/ip/hotspot/active/print', [
        '?server' => 'wartel',
        '.proplist' => 'user,uptime,bytes-in,bytes-out,address,mac-address'
    ]);
    $activeMap = [];
    foreach ($active_list as $a) {
        if (isset($a['user'])) $activeMap[$a['user']] = $a;
    }

    $all_users = $API->comm('/ip/hotspot/user/print', [
        '?server' => 'wartel',
        '.proplist' => '.id,name,comment,disabled,bytes-in,bytes-out,uptime'
    ]);

    foreach ($all_users as $u) {
        $name = $u['name'] ?? '';
        $comment = $u['comment'] ?? '';
        $disabled = $u['disabled'] ?? 'false';
        $is_active = $name !== '' && isset($activeMap[$name]);

        $bytes_total = (int)($u['bytes-in'] ?? 0) + (int)($u['bytes-out'] ?? 0);
        $bytes_active = 0;
        if ($is_active) {
            $bytes_active = (int)($activeMap[$name]['bytes-in'] ?? 0) + (int)($activeMap[$name]['bytes-out'] ?? 0);
        }
        $bytes = max($bytes_total, $bytes_active);

        $uptime_user = $u['uptime'] ?? '';
        $uptime_active = $is_active ? ($activeMap[$name]['uptime'] ?? '') : '';
        $uptime = $uptime_user !== '' ? $uptime_user : $uptime_active;

        $cm = extract_ip_mac_from_comment($comment);
        $is_rusak = (stripos($comment, 'RUSAK') !== false) || ($disabled === 'true');
        $is_retur = (stripos($comment, '(Retur)') !== false) || (stripos($comment, 'Retur Ref:') !== false);
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
                $totalReady++;
            }
        }
    }

    if (!empty($blockSummary)) {
        ksort($blockSummary, SORT_NATURAL | SORT_FLAG_CASE);
    }
}
?>

<div class="row">
<div class="col-8">
<div class="card box-bordered">
    <div class="card-header">
    <h3><i class="fa fa-user-plus"></i> <?= $_generate_user ?> <small id="loader" style="display: none;" ><i><i class='fa fa-circle-o-notch fa-spin'></i> <?= $_processing ?> </i></small></h3> 
    </div>
    <div class="card-body">
<form autocomplete="off" method="post" action="./?hotspot-user=generate&session=<?= $session; ?>">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
    <input type="hidden" name="session" value="<?= $session; ?>">
    
    <div>
        <?php if (isset($_SESSION['ubp']) && $_SESSION['ubp'] != "") {
            echo "<a class='btn bg-warning' href='./?hotspot=users&profile=" . $_SESSION['ubp'] . "&session=" . $session . "'> <i class='fa fa-close'></i> ".$_close."</a>";
        } else {
            echo "<a class='btn bg-warning' href='./?hotspot=users&profile=all&session=" . $session . "'> <i class='fa fa-close'></i> ".$_close."</a>";
        }
        ?>
        <a class="btn bg-pink" title="Lihat User per Profile" href="./?hotspot=users&profile=<?php echo isset($uprofile) ? $uprofile : "all"; ?>&session=<?= $session; ?>"> <i class="fa fa-users"></i> <?= $_user_list ?></a>
        
        <button type="submit" name="save" onclick="return validateForm()" class="btn bg-primary" title="Generate User"> <i class="fa fa-save"></i> <?= $_generate ?></button>
        
    </div>

<style>
    .locked-field {
        background-color: transparent !important; 
        border: 1px dashed rgba(128, 128, 128, 0.5) !important; 
        color: inherit !important; 
        cursor: not-allowed; 
        font-weight: bold;
        opacity: 0.8;
    }
</style>

<table class="table">
  <tr>
    <td class="align-middle"><?= $_qty ?></td>
    <td>
        <input class="form-control" type="number" id="qtyInput" name="qty" min="50" max="500" value="50" required="1" title="Minimal 50 User">
        <small class="text-danger">*Minimal 50 User</small>
    </td>
  </tr>
  
  <tr>
    <td class="align-middle">Server</td>
    <td>
        <input type="text" class="form-control locked-field" value="wartel" readonly>
    </td>
  </tr>

  <tr>
    <td class="align-middle"><?= $_user_mode ?></td>
    <td>
        <input type="text" class="form-control locked-field" value="Username = Password" readonly>
        <input type="hidden" name="user" value="vc">
    </td>
  </tr>

  <tr>
    <td class="align-middle"><?= $_user_length ?></td>
    <td>
      <select class="form-control" id="userl" name="userl" required="1">
            <option value="6">6 Digit</option>
            <option value="7">7 Digit</option>
            <option value="8">8 Digit</option>
      </select>
    </td>
  </tr>
  
  <tr>
    <td class="align-middle"><?= $_profile ?></td>
    <td>
        <select class="form-control" onchange="GetVP(); updateTimeLimit();" id="uprof" name="profile" required="1">
            <?php 
            $allowedProfiles = ['10Menit', '30Menit'];
            if ($genprof != "" && in_array($genprof, $allowedProfiles)) {
                echo "<option>" . $genprof . "</option>";
            }
            $TotalReg = count($getprofile);
            for ($i = 0; $i < $TotalReg; $i++) {
                $pName = $getprofile[$i]['name'];
                if(in_array($pName, $allowedProfiles) && $pName != $genprof){
                    echo "<option>" . $pName . "</option>";
                }
            }
            ?>
        </select>
    </td>
  </tr>

  <tr>
    <td class="align-middle"><?= $_time_limit ?></td>
    <td>
        <input class="form-control locked-field" type="text" size="4" autocomplete="off" name="timelimit_display" id="timelimit" value="" readonly>
    </td>
  </tr>

  <tr>
    <td class="align-middle"><?= $_comment ?></td>
    <td>
        <select class="form-control" name="adcomment" id="comment" required="1">
            <?php
            $blocks = range('A', 'F');
            $suffixes = ['10', '30'];
            foreach($blocks as $blk) {
                foreach($suffixes as $suf) {
                    echo "<option value='Blok-$blk$suf'>Blok-$blk$suf</option>";
                }
            }
            ?>
        </select>
    </td>
  </tr>

   <tr >
    <td colspan="4" class="align-middle w-12" id="GetValidPrice">
        <?php if ($genprof != "") { echo isset($ValidPrice) ? $ValidPrice : ""; } ?>
    </td>
  </tr>
</table>
</form>
</div>
</div>
</div>

<div class="col-4">
    <div class="card">
        <div class="card-header">
                        <h3><i class="fa fa-ticket"></i> Ringkasan Voucher</h3>
        </div>
        <div class="card-body">
<?php if (!empty($blockSummary)): ?>
        <div class="mb-2" style="font-weight:600;">Sisa Voucher per Blok (READY)</div>
        <table class="table table-bordered">
            <thead>
                <tr>
                    <th>Blok</th>
                    <th>Jumlah</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($blockSummary as $blok => $count): ?>
                    <tr>
                        <td><?= htmlspecialchars($blok) ?></td>
                        <td><?= (int)$count ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
<?php else: ?>
        <div class="text-center p-3 text-muted">Tidak ada voucher READY untuk ditampilkan.</div>
<?php endif; ?>

        <div class="mt-3">
            <div><b>Total Rusak:</b> <?= (int)$totalRusak ?></div>
            <div><b>Total Retur:</b> <?= (int)$totalRetur ?></div>
        </div>
</div>
</div>
</div>

<script>
function GetVP(){
  var prof = document.getElementById('uprof').value;
  $("#GetValidPrice").load("./process/getvalidprice.php?name="+prof+"&session=<?= $session; ?> #getdata");
} 

function updateTimeLimit() {
    var prof = document.getElementById('uprof').value;
    var timeField = document.getElementById('timelimit');
    
    if (prof === '10Menit') {
        timeField.value = '10m';
    } else if (prof === '30Menit') {
        timeField.value = '30m';
    } else {
        timeField.value = ''; 
    }
}

function validateForm() {
    loader(); 
    var qty = document.getElementById('qtyInput').value;
    if (qty < 50) {
        alert("Minimal generate harus 50 user!");
        document.getElementById('loader').style.display = 'none';
        return false;
    }
    return true;
}

$(document).ready(function() {
    updateTimeLimit();
    GetVP();
});
</script>