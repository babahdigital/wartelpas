<?php
/*
 * WARTELPAS SECURITY & ERROR PAGE SYSTEM
 * Optimized for Mikhmon v3 Dark Theme
 * -------------------------------------------------------
 * Menangani tampilan Error (400, 403, 404, 500) dengan
 * antarmuka profesional, aman, dan informatif.
 */

// Matikan pelaporan error default PHP agar tidak bocor ke user
error_reporting(0);

// Ambil Status Code dari Server / Query
$status = isset($_GET['code']) ? (int)$_GET['code'] : (isset($_SERVER['REDIRECT_STATUS']) ? (int)$_SERVER['REDIRECT_STATUS'] : 404);

// Pastikan kode status dikirim ke header browser
http_response_code($status);

// Konfigurasi Pesan & Ikon Error
$codes = array(
    403 => array(
        'title'   => '403 Forbidden',
        'header'  => 'Akses Ditolak!',
        'message' => 'Sistem keamanan Wartel memblokir permintaan Anda.<br>Anda tidak memiliki izin untuk mengakses sumber daya ini.',
        'icon'    => 'fa-ban'
    ),
    404 => array(
        'title'   => '404 Not Found',
        'header'  => 'Halaman Tidak Ditemukan',
        'message' => 'Halaman atau file yang Anda cari tidak tersedia.<br>Mungkin sudah dihapus atau URL yang Anda masukkan salah.',
        'icon'    => 'fa-search-minus'
    ),
    500 => array(
        'title'   => '500 Server Error',
        'header'  => 'Terjadi Kesalahan Server',
        'message' => 'Terjadi gangguan pada server internal.<br>Silakan hubungi administrator jaringan untuk perbaikan.',
        'icon'    => 'fa-cogs'
    ),
    400 => array(
        'title'   => '400 Bad Request',
        'header'  => 'Permintaan Tidak Valid',
        'message' => 'Browser mengirimkan data yang tidak dapat diproses oleh server.',
        'icon'    => 'fa-exclamation-circle'
    )
);

// Fallback jika error tidak dikenal
if (array_key_exists($status, $codes)) {
    $err = $codes[$status];
} else {
    $err = array(
        'title'   => 'Unknown Error',
        'header'  => 'Kesalahan Tidak Diketahui',
        'message' => 'Terjadi kesalahan sistem yang tidak teridentifikasi.',
        'icon'    => 'fa-bug'
    );
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title><?= $err['title'] ?> - Wartelpas Security</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <link rel="stylesheet" href="./css/font-awesome/css/font-awesome.min.css">
    <style>
        /* RESET & BASE STYLES */
        * { box-sizing: border-box; outline: none; }
        body {
            font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background-color: #1b1d29; /* Mikhmon v3 Dark BG */
            color: #b9bbbe;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }

        /* CONTAINER CARD */
        .error-container {
            background: #282b3c; /* Mikhmon v3 Card BG */
            width: 100%;
            max-width: 480px;
            padding: 40px 30px;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
            border-top: 4px solid #f64e60; /* Merah Tegas untuk Error */
            margin: 20px;
            position: relative;
            overflow: hidden;
        }

        /* ICON STYLING */
        .icon-wrapper {
            margin-bottom: 25px;
        }
        .icon-wrapper i {
            font-size: 80px;
            color: #f64e60; /* Merah Error */
            text-shadow: 0 5px 10px rgba(246, 78, 96, 0.2);
        }

        /* TYPOGRAPHY */
        h1 {
            color: #ffffff;
            font-size: 24px;
            font-weight: 600;
            margin: 0 0 15px 0;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }
        p {
            font-size: 15px;
            line-height: 1.6;
            color: #9899ac;
            margin-bottom: 30px;
        }

        /* ACTION BUTTON */
        .btn {
            display: inline-block;
            background-color: #3699ff; /* Mikhmon Blue */
            color: #ffffff;
            text-decoration: none;
            padding: 12px 30px;
            border-radius: 6px;
            font-weight: 500;
            font-size: 14px;
            transition: all 0.3s ease;
            border: 1px solid transparent;
            box-shadow: 0 2px 5px rgba(54, 153, 255, 0.3);
        }
        .btn:hover {
            background-color: #187de4;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(54, 153, 255, 0.4);
        }
        .btn i { margin-right: 8px; }

        /* FOOTER INFO */
        .footer-info {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #32354a;
            font-size: 12px;
            color: #5e6278;
        }
        .error-code-badge {
            background: #3a2434;
            color: #f64e60;
            padding: 4px 10px;
            border-radius: 4px;
            font-weight: bold;
            font-family: monospace;
            font-size: 13px;
            margin-top: 5px;
            display: inline-block;
        }

        /* ANIMATION */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .error-container {
            animation: fadeIn 0.5s ease-out;
        }
    </style>
</head>
<body>

    <div class="error-container">
        <div class="icon-wrapper">
            <i class="fa <?= $err['icon'] ?>"></i>
        </div>
        
        <h1><?= $err['header'] ?></h1>
        <p><?= $err['message'] ?></p>

        <a href="./" class="btn">
            <i class="fa fa-arrow-left"></i> Kembali ke Dashboard
        </a>

        <div class="footer-info">
            &copy; 2026 Wartelpas Security System<br>
            <span class="error-code-badge">CODE: <?= $status ?></span>
        </div>
    </div>

</body>
</html>