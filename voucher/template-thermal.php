<style>
	.qrcode{ height:100px; width:100px; }
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

$timelimit = str_replace("m", "mnt", $timelimit);
$timelimit = str_replace("h", "jam", $timelimit);
?>

<table class="voucher" data-username="<?= htmlspecialchars($user) ?>" style="width: 180px;">
  <tbody>
    <tr>
      <td style="text-align: center; font-size: 14px; font-weight:bold; border-bottom: 1px black solid;">
          <img src="<?= $logo; ?>" alt="logo" style="height:30px;border:0;">
          <br>
          <span style="font-size:10px;"><?= date("d/m/y H:i") ?></span>
      </td>
    </tr>
    <tr>
      <td>
        <table style="text-align: center; width: 170px; font-size: 12px;">
          <tbody>
            <?php if ($usermode == "vc") { ?>
            <tr>
              <td>Kode Voucher</td>
            </tr>
            <tr>
              <td style="width:100%; border: 1px solid black; font-weight:bold; font-size:16px;"><?= $username; ?></td>
            </tr>
            <?php } elseif ($usermode == "up") { ?>
            <tr>
              <td style="width: 50%">User</td>
              <td>Pass</td>
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
    
    <?php if ($qr == "yes") { ?>
    <tr>
      <td style="text-align:center;">
        <?= $qrcode ?>
      </td>
    </tr>
    <?php } ?>

    <tr>
      <td style="text-align:center; font-weight:bold; font-size:14px; padding-top:5px;">
        <?= $timelimit; ?> / <?= $price; ?>
        
        <?php if($lokasi_blok != ""): ?>
            <div style="font-size:18px; margin-top:2px; border-top:1px dashed #000; padding-top:2px; text-align: right;">
                <?= $lokasi_blok; ?>
            </div>
        <?php endif; ?>
      </td>
    </tr>
    
    <tr>
      <td style="text-align: center; font-size: 10px; border-top: 1px solid black; margin-top:5px;">
        Login: <?= $dnsname; ?>
      </td>
    </tr>
  </tbody>
</table>