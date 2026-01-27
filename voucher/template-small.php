<style>
	#num { margin-top: 7px; }
</style>

<?php
$root_dir = dirname(__DIR__);
$env = [];
$envFile = $root_dir . '/include/env.php';
if (file_exists($envFile)) {
  require $envFile;
}
$pricing = $env['pricing'] ?? [];
$profiles_cfg = $env['profiles'] ?? [];
$price10 = (int)($pricing['price_10'] ?? 0);
$price30 = (int)($pricing['price_30'] ?? 0);
$label10 = $profiles_cfg['label_10'] ?? '10 Menit';
$label30 = $profiles_cfg['label_30'] ?? '30 Menit';
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
$waktu_custom = str_replace("m", " Menit", $timelimit);
$waktu_custom = str_replace("h", " Jam", $waktu_custom);
$validity_display = $validity;
$validity_display = str_replace("m", " Menit", $validity_display);
$validity_display = str_replace("h", " Jam", $validity_display);
$profile_display = isset($profile_name) ? preg_replace('/(\d+)([A-Za-z]+)/', '$1 $2', $profile_name) : '';
$display_paket = trim($waktu_custom) !== "" ? ($waktu_custom . " / ") : (trim($profile_display) !== "" ? ($profile_display . " / ") : (trim($validity_display) !== "" ? ($validity_display . " / ") : ""));
$display_paket = preg_replace('/\b(\d+)\s*Menit\b/i', '$1 Menit', $display_paket);

// Fallback harga jika tidak terbaca dari profile on-login
$price_display = $price ?? '';
$price_value = 0;
$profile_hint = strtolower(trim((string)$profile_name . ' ' . (string)$timelimit . ' ' . (string)$validity . ' ' . (string)$comment));
if (trim($price_display) === '') {
  if (preg_match('/\b30\s*(menit|m)\b|30menit|\b30m\b/i', $profile_hint)) {
    $price_value = $price30;
  } elseif (preg_match('/\b10\s*(menit|m)\b|10menit|\b10m\b/i', $profile_hint)) {
    $price_value = $price10;
  }
  if ($price_value > 0) {
    if (isset($currency) && isset($cekindo) && in_array($currency, $cekindo['indo'], true)) {
      $price_display = $currency . " " . number_format((float)$price_value, 0, ",", ".");
    } else {
      $price_display = isset($currency) && $currency !== ''
        ? ($currency . " " . number_format((float)$price_value, 2))
        : number_format((float)$price_value, 0, ",", ".");
    }
  }
}

if (trim($display_paket) === '') {
  if ($price_value === $price30) $display_paket = $label30 . ' / ';
  if ($price_value === $price10) $display_paket = $label10 . ' / ';
}
?>

<table class="voucher" data-username="<?= htmlspecialchars($username) ?>" style="width: 160px;">
  <tbody>
    <tr>
      <td style="text-align: left; font-size: 12px; font-weight:bold; border-bottom: 1px black solid;">
        <img src="<?php echo $logo;?>" alt="logo" style="height:30px; border:0; vertical-align:middle; margin-right:2px;">
        <span id="num" style="float:right;"><?php echo "No. $num";?></span>
      </td>
    </tr>
    <tr>
      <td>
        <table style="text-align: center; width: 150px;">
          <tbody>
            <tr style="color: black; font-size: 11px;">
              <td>
                <table style="width:100%;">
                  <?php if($usermode == "vc"){?>
                  <tr>
                    <td>Kode Voucher</td>
                  </tr>
                  <tr style="color: black; font-size: 16px;">
                    <td style="width:100%; border: 1px solid black; font-weight:bold;"><?php echo $username;?></td>
                  </tr>
                  <tr>
                    <td colspan="2" style="font-weight:bold; padding-top:3px;">
                      <?php echo $display_paket;?> <?php echo $price_display;?>
                        
                      <?php if($lokasi_blok != ""): ?>
                        <div style="text-align: right; margin-top: 12px;">
                          <span style="font-size:14px; background:#eee; padding:0 5px; border-radius:3px;"><?php echo $lokasi_blok;?></span>
                        </div>
                      <?php endif; ?>
                      <?php if (stripos($comment, '(Retur)') !== false): ?>
                        <div style="text-align:left; margin-top:4px; font-size:11px; font-weight:bold;">
                          Retur
                        </div>
                      <?php endif; ?>
                    </td>
                  </tr>
                  <?php } elseif($usermode == "up"){?>
                  <tr>
                    <td style="width: 50%">User</td>
                    <td>Pass</td>
                  </tr>
                  <tr style="color: black; font-size: 14px;">
                    <td style="border: 1px solid black; font-weight:bold;"><?php echo $username;?></td>
                    <td style="border: 1px solid black; font-weight:bold;"><?php echo $password;?></td>
                  </tr>
                  <tr>
                    <td colspan="2" style="font-weight:bold; padding-top:2px;">
                        <?php echo $display_paket;?> <?php echo $price_display;?>
                        
                        <?php if($lokasi_blok != ""): ?>
                            <div style="text-align: right; margin-top: 2px;">
                                <span style="font-size:14px; background:#eee; padding:0 5px; border-radius:3px;"><?php echo $lokasi_blok;?></span>
                            </div>
                        <?php endif; ?>
                    </td>
                  </tr>
                  <?php }?>
                </table>
              </td>
            </tr>
          </tbody>
        </table>
      </td>
    </tr>
  </tbody>
</table>