<style>
	#num {
		margin-top: 7px;
	}
</style>

<?php
// 1. Ambil Blok Saja
$data_blok = explode("-", $comment, 4);
$lokasi_blok = (isset($data_blok[3])) ? $data_blok[3] : $comment; 

// 2. Format Waktu Custom (Paket ... Menit)
$waktu_custom = str_replace("m", " Menit", $timelimit);
$waktu_custom = str_replace("h", " Jam", $waktu_custom);
$display_paket = "Paket " . $waktu_custom;
?>

<table class="voucher" style=" width: 160px;">
  <tbody>
    <tr>
      <td style="text-align: left; font-size: 14px; font-weight:bold; border-bottom: 1px black solid;">
        <img src="<?php echo $logo;?>" alt="logo" style="height:30px; border:0; vertical-align:middle; margin-right:2px;">
        <span id="num"><?php echo " [$num]";?></span>
      </td>
    </tr>
    <tr>
      <td>
    <table style=" text-align: center; width: 150px;">
  <tbody>
    <tr style="color: black; font-size: 11px;">
      <td>
        <table style="width:100%;">
<?php if($usermode == "vc"){?>
        <tr>
          <td >Kode Voucher</td>
        </tr>
        <tr style="color: black; font-size: 14px;">
          <td style="width:100%; border: 1px solid black; font-weight:bold;"><?php echo $username;?></td>
        </tr>
        <tr>
          <td colspan="2" style="border: 1px solid black; font-weight:bold;"><?php echo $display_paket;?> <?php echo $price;?></td>
        </tr>
<?php }elseif($usermode == "up"){?>
          <tr>
          <td style="width: 50%">Username</td>
          <td>Password</td>
        </tr>
        <tr style="color: black; font-size: 14px;">
          <td style="border: 1px solid black; font-weight:bold;"><?php echo $username;?></td>
          <td style="border: 1px solid black; font-weight:bold;"><?php echo $password;?></td>
        </tr>
        <tr>
          <td colspan="2" style="border: 1px solid black; font-weight:bold;"><?php echo $display_paket;?> <?php echo $price;?></td>
        </tr>
<?php }?>
</table>
      </td>
    </tr>
    
    <tr>
        <td style="font-weight:bold; font-size:12px; border-top: 1px dashed black;">
            <?php echo $lokasi_blok; ?>
        </td>
    </tr>
    
  </tbody>
    </table>
      </td>
    </tr>
  </tbody>
</table>	        	        