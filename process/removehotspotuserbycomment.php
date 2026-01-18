<?php
/*
 * Copyright (C) 2018 Laksamadi Guko.
 * FIXED by Gemini AI for Pak Dul (Wartel Edition)
 * DATE: 2026-01-12
 * REPAIR LOG: 
 * 1. Fix GET parameter name mismatch (remove-hotspot-user-by-comment).
 * 2. Add Server Filter (wartel) to match user.php scope.
 */
session_start();
// Sembunyikan error PHP agar tidak merusak response AJAX
error_reporting(0);
ini_set('max_execution_time', 300);

if (!isset($_SESSION["mikhmon"])) {
    header("Location:../admin.php?id=login");
    exit();
}

include_once('../lib/routeros_api.class.php');
include_once('../include/config.php');

$API = new RouterosAPI();
$API->debug = false;

// =======================================================================
// 1. TANGKAP PARAMETER (PERBAIKAN VARIABEL)
// =======================================================================
// Sesuai dengan user.php yang mengirim: remove-hotspot-user-by-comment (pakai strip)
$target_comment = isset($_GET['remove-hotspot-user-by-comment']) ? $_GET['remove-hotspot-user-by-comment'] : "";

// Fallback: Jika ternyata dikirim tanpa strip (untuk kompatibilitas)
if ($target_comment == "") {
    $target_comment = isset($_GET['removehotspotuserbycomment']) ? $_GET['removehotspotuserbycomment'] : "";
}

if ($API->connect($iphost, $userhost, decrypt($passwdhost))) {

    // =======================================================================
    // 2. AMBIL DATA USER (PERBAIKAN FILTER SERVER)
    // =======================================================================
    // Tambahkan filter server "wartel" agar sama persis dengan tampilan di user.php
    $all_users = $API->comm("/ip/hotspot/user/print", array(
        "?server" => "wartel"
    ));
    
    $TotalReg = count($all_users);
    $deleted_count = 0;

    // Simpan profil untuk redirect balik
    $redirect_profile = isset($_SESSION['ubp']) ? $_SESSION['ubp'] : "all";
    if($redirect_profile == "") $redirect_profile = "all";

    // =======================================================================
    // 3. LOOP DAN HAPUS (LOGIKA PARTIAL MATCH)
    // =======================================================================
    for ($i = 0; $i < $TotalReg; $i++) {
        $userdetails = $all_users[$i];
        $uid = $userdetails['.id'];
        $ucomment = isset($userdetails['comment']) ? $userdetails['comment'] : "";
        
        // Cek apakah komentar user mengandung kata target (Case Insensitive)
        // Logika ini SUDAH BENAR: 'vc-123-Blok-A' akan terhapus jika target='Blok-A'
        if ($target_comment != "" && stripos($ucomment, $target_comment) !== false) {
            
            $API->comm("/ip/hotspot/user/remove", array(
                ".id" => "$uid",
            ));
            
            $deleted_count++;
        }
    }
    
    $API->disconnect();
    
    // Pesan Sukses
    $msg = "PROSES SELESAI!\n\n" . 
           "Target Grup: " . $target_comment . "\n" . 
           "Server: Wartel\n" .
           "Jumlah Dihapus: " . $deleted_count . " user.";
           
} else {
    $msg = "GAGAL KONEKSI KE MIKROTIK!";
    $redirect_profile = "all";
}

// =======================================================================
// 4. OUTPUT JAVASCRIPT
// =======================================================================
?>
<script>
    alert(<?= json_encode($msg); ?>);
    window.location.href = './?hotspot=users&profile=<?= $redirect_profile; ?>&session=<?= $session; ?>';
</script>