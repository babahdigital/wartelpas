<style>
    .qrcode{
        height:80px;
        width:80px;
    }
	#num {
		margin-top: 9px;
	}
</style>

<?php
// 1. LOGIKA BERSIHKAN KOMENTAR (Ambil Blok Saja)
$data_blok = explode("-", $comment, 4);
$lokasi_blok = (isset($data_blok[3])) ? $data_blok[3] : $comment; 

// 2. LOGIKA PAKET & WAKTU
// Kita "Matikan" validity agar tidak muncul di manapun (termasuk di tampilan Small)
$validity = ""; 

// Kita Ubah Timelimit (misal "2m") menjadi "Paket 2 Menit"
// Ini akan menimpa tampilan lama secara otomatis
$timelimit = str_replace("m", " Menit", $timelimit);
$timelimit = str_replace("h", " Jam", $timelimit);
$timelimit = "Paket " . $timelimit;
?>

<table class="voucher" style=" width: 220px;">
  <tbody>
<tr>
      <td style="text-align: left; font-size: 14px; font-weight:bold; border-bottom: 1px black solid;"><img src="<?= $logo; ?>" alt="logo" style="height:35px;border:0;"> <span id="num"><?= " [$num]"; ?></span></td>
    </tr>
<tr>
      <td>
    <table style=" text-align: center; width: 210px; font-size: 12px;">
  <tbody>
<tr>
      <td>
        <table style="width:100%;">
<?php if ($usermode == "vc") { ?>
        <tr>
          <td font-size: 12px;>Kode Voucher</td>
        </tr>
        <tr>
          <td style="width:100%; border: 1px solid black; font-weight:bold; font-size:16px;"><?= $username; ?></td>
        </tr>
<?php 
} elseif ($usermode == "up") { ?>
<?php if ($qr == "yes") { ?>
        <tr>
          <td>Username</td>
        </tr>
        <tr>
          <td style="border: 1px solid black; font-weight:bold;"><?= $username; ?></td>
        </tr>
        <tr>
          <td>Password</td>
        </tr>
        <tr>
          <td style="border: 1px solid black; font-weight:bold;"><?= $password; ?></td>
        </tr>
<?php 
} else { ?>
        <tr>
          <td style="width: 50%">Username</td>
          <td >Password</td>
        </tr>
        <tr style="font-size: 14px;">
          <td style="border: 1px solid black; font-weight:bold;"><?= $username; ?></td>
          <td style="border: 1px solid black; font-weight:bold;"><?= $password; ?></td>
        </tr>
<?php 
}
} ?>
</table>
      </td>
<?php if ($qr == "yes") { ?>
      <td>
    <?= $qrcode ?>
      </td>
<?php 
} ?>
<tr>
      <td colspan="2" style="border-top: 1px solid black;font-weight:bold; font-size:16px">
        <?= $timelimit; ?> <?= $price; ?>
      </td>
</tr>
    <tr>
      <td colspan="2" style="font-weight:bold; font-size:12px">Login: http://<?= $dnsname; ?></td>
</tr>
    
    <tr>
      <td colspan="2" style="font-weight:bold; font-size:14px; border-top: 1px dashed black; padding-top: 2px;">
        <?= $lokasi_blok; ?>
      </td>
    </tr>
    </tbody>
    </table>
      </td>
    </tr>
  </tbody>
</table>	        	        	        	        