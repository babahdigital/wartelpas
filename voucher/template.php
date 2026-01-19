<style>
    .qrcode{ height:80px; width:80px; }
    #num { margin-top: 9px; }
</style>

<?php
// --- LOGIKA AMBIL KODE BLOK ---
$lokasi_blok = ""; 
if (stripos($comment, "blok-") !== false) {
  $parts = preg_split('/blok-/i', $comment);
  if (isset($parts[1])) $lokasi_blok = trim(explode("|", $parts[1])[0]); 
} elseif (stripos($comment, "kamar-") !== false) {
  $parts = preg_split('/kamar-/i', $comment);
  if (isset($parts[1])) $lokasi_blok = "KMR " . trim(explode("|", $parts[1])[0]);
}
if ($lokasi_blok != "") {
  $lokasi_blok = preg_split('/[\s\(\|]/', $lokasi_blok)[0];
}

// --- LOGIKA WAKTU ---
$timelimit = str_replace("m", " Menit", $timelimit);
$timelimit = str_replace("h", " Jam", $timelimit);
if (stripos($timelimit, "Paket") === false) {
    $timelimit = "Paket " . $timelimit;
}
?>

<table class="voucher" data-username="<?= htmlspecialchars($user) ?>" style="width: 220px;">
  <tbody>
    <tr>
      <td colspan="2" style="text-align: left; font-size: 14px; font-weight:bold; border-bottom: 1px black solid;">
        <img src="<?= $logo; ?>" alt="logo" style="height:35px;border:0; vertical-align:middle;"> 
        <span id="num" style="float:right;"><?= "No. $num"; ?></span>
      </td>
    </tr>
    <tr>
      <td colspan="2">
        <table style="text-align: center; width: 210px;">
          <tbody>
            <?php if ($usermode == "vc") { ?>
            <tr>
              <td>Kode Voucher</td>
            </tr>
            <tr style="font-size: 20px;">
              <td style="border: 2px solid black; font-weight:bold;"><?= $username; ?></td>
            </tr>
            <?php } elseif ($usermode == "up") { ?>
            <tr>
              <td style="width: 50%">Username</td>
              <td>Password</td>
            </tr>
            <tr style="font-size: 14px;">
              <td style="border: 1px solid black; font-weight:bold;"><?= $username; ?></td>
              <td style="border: 1px solid black; font-weight:bold;"><?= $password; ?></td>
            </tr>
            <?php } ?>
          </tbody>
        </table>
      </td>
    </tr>
    <tr>
      <td>
        <?= $qrcode ?>
      </td>
      <td style="vertical-align: middle;">
         <div style="font-weight:bold; font-size:16px; text-align:center;">
            <?= $timelimit; ?><br>
            <?= $price; ?>
            
            <?php if($lokasi_blok != ""): ?>
                <div style="margin-top:8px; font-size:18px; color:black; border-top:1px dashed #000; text-align: right; padding-right: 2px;">
                    <?= $lokasi_blok; ?>
                </div>
            <?php endif; ?>
         </div>
      </td>
    </tr>
    <tr>
      <td colspan="2" style="border-top: 1px solid black; font-size: 10px; text-align:center;">
        Login: <strong>http://<?= $dnsname; ?></strong>
      </td>
    </tr>
  </tbody>
</table>