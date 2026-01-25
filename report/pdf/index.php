<?php
session_start();
error_reporting(0);

if (!isset($_SESSION["mikhmon"])) {
    header("Location:../../admin.php?id=login");
    exit;
}

http_response_code(403);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Akses Dibatasi</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f7f7f7; margin: 0; }
        .wrap { max-width: 520px; margin: 60px auto; background: #fff; padding: 24px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); }
        h1 { font-size: 18px; margin: 0 0 10px; }
        p { margin: 0; color: #555; }
    </style>
</head>
<body>
    <div class="wrap">
        <h1>Akses Dibatasi</h1>
        <p>Folder ini digunakan untuk menyimpan file PDF laporan. Akses langsung tidak diizinkan.</p>
    </div>
</body>
</html>
