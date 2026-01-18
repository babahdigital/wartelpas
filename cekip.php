<?php
echo "<h1>IP Address Detektif</h1>";
echo "<b>IP Asli Anda (Remote Addr):</b> " . $_SERVER['REMOTE_ADDR'] . "<br>";
echo "<b>IP Forwarded (Jika via Proxy):</b> " . (isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : '-');
?>