<?php
/*
 * Copyright (C) 2018 Laksamadi Guko.
 * Modified & Reskinned 2024 for Wartelpas (Dark Mode).
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 */
session_start();

// Mencegah error "Undefined Variable" jika script ini dijalankan terpisah tanpa include parent
// Pastikan variabel ini di-set oleh sistem Anda, jika tidak, gunakan default kosong/standar
$error = isset($error) ? $error : '';
$_please_login = isset($_please_login) ? $_please_login : 'Silakan Login';

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - Wartelpas System</title>

    <!-- Bootstrap 3.3.7 CSS -->
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
    <!-- Font Awesome 4.7.0 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">

    <style>
        /* --- DARK MODE STYLES --- */
        body {
            background-color: #121212; /* Latar belakang sangat gelap */
            color: #e0e0e0; /* Teks terang */
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
        }

        .login-container {
            width: 100%;
            max-width: 400px;
            padding: 15px;
            margin: auto;
        }

        /* Panel Login Dark Mode */
        .panel-login {
            border: 1px solid #333;
            border-radius: 8px;
            box-shadow: 0 5px 25px rgba(0,0,0,0.5); /* Shadow lebih pekat */
            background: #1e1e1e; /* Panel abu-abu gelap */
        }

        .panel-login .panel-heading {
            background-color: #1e1e1e;
            border-bottom: 1px solid #333;
            border-radius: 8px 8px 0 0;
            padding: 25px 15px;
            text-align: center;
        }

        .logo-img {
            max-height: 80px; 
            width: auto;
            margin-bottom: 15px;
            display: block;
            margin-left: auto;
            margin-right: auto;
        }

        .app-title {
            color: #fff;
            font-weight: 700;
            font-size: 20px;
            margin-top: 5px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .panel-login .panel-body {
            padding: 30px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        /* Styling Input Group Bootstrap 3 Overrides for Dark Mode */
        .input-group-addon {
            background-color: #2c2c2c;
            border-color: #444;
            color: #aaa;
            min-width: 45px; /* Lebar konsisten untuk ikon */
        }

        .form-control {
            background-color: #2c2c2c; /* Input gelap */
            border-color: #444;
            color: #fff; /* Teks input putih */
            height: 45px;
            box-shadow: none;
            font-size: 15px;
        }

        .form-control:focus {
            background-color: #333;
            border-color: #337ab7;
            box-shadow: 0 0 5px rgba(51, 122, 183, 0.5);
            color: #fff;
        }

        /* Placeholder color styling */
        .form-control::-webkit-input-placeholder { color: #777; }
        .form-control:-moz-placeholder { color: #777; }
        .form-control::-moz-placeholder { color: #777; }
        .form-control:-ms-input-placeholder { color: #777; }

        /* Error States Styling */
        .has-error .form-control {
            border-color: #e74c3c;
            box-shadow: none;
        }
        
        .has-error .input-group-addon {
            background-color: #3e2b2b; /* Merah gelap transparan */
            border-color: #e74c3c;
            color: #e74c3c;
        }

        .help-block {
            display: none;
            font-size: 12px;
            margin-bottom: 0;
            text-align: left;
            color: #e74c3c; /* Warna error merah terang */
        }

        .has-error .help-block {
            display: block;
        }

        .btn-login {
            background-color: #337ab7;
            border-color: #2e6da4;
            color: #fff;
            padding: 12px;
            font-size: 16px;
            font-weight: bold;
            border-radius: 4px;
            transition: all 0.3s;
            margin-top: 10px;
        }

        .btn-login:hover, .btn-login:focus {
            background-color: #286090;
            color: #fff;
            border-color: #204d74;
        }

        /* Alert Styling for PHP Errors */
        .alert-custom {
            background-color: #3e2b2b;
            color: #e74c3c;
            border: 1px solid #e74c3c;
            border-radius: 4px;
            padding: 10px;
            margin-top: 20px;
            font-size: 14px;
            text-align: center;
        }

        .login-footer {
            margin-top: 20px;
            text-align: center;
            font-size: 13px;
            color: #777;
        }

        /* Animation for shake effect on error */
        @keyframes shake {
            0% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            50% { transform: translateX(5px); }
            75% { transform: translateX(-5px); }
            100% { transform: translateX(0); }
        }

        .shake-element {
            animation: shake 0.3s ease-in-out;
        }
    </style>
</head>
<body>

    <div class="container">
        <div class="login-container">
            <div class="panel panel-default panel-login">
                <div class="panel-heading">
                    <!-- Menggunakan fallback logo: Coba load img/logo.png, kalau gagal coba logo.png -->
                    <img src="img/logo.png" alt="Logo" class="logo-img" onerror="this.onerror=null;this.src='logo.png';">
                    <div class="app-title"><?= htmlspecialchars($_please_login) ?></div>
                </div>
                <div class="panel-body">
                    <form id="loginForm" action="" method="post" autocomplete="off">
                        
                        <!-- Username Input -->
                        <div class="form-group" id="group-username">
                            <label class="sr-only" for="_username">Username</label>
                            <div class="input-group">
                                <span class="input-group-addon"><i class="fa fa-user"></i></span>
                                <!-- Name harus 'user' sesuai script PHP asli -->
                                <input type="text" class="form-control" id="_username" name="user" placeholder="Username" required autofocus>
                            </div>
                            <span class="help-block" id="error-username">Username tidak boleh kosong.</span>
                        </div>

                        <!-- Password Input -->
                        <div class="form-group" id="group-password">
                            <label class="sr-only" for="password">Password</label>
                            <div class="input-group">
                                <span class="input-group-addon"><i class="fa fa-lock"></i></span>
                                <!-- Name harus 'pass' sesuai script PHP asli -->
                                <input type="password" class="form-control" id="password" name="pass" placeholder="Password" required>
                            </div>
                            <span class="help-block" id="error-password">Password wajib diisi.</span>
                        </div>

                        <!-- Tombol Login -->
                        <!-- Name harus 'login' sesuai script PHP asli -->
                        <button type="submit" name="login" value="Login" class="btn btn-login btn-block">
                            <i class="fa fa-sign-in"></i> Masuk Aplikasi
                        </button>

                        <!-- Menampilkan Error dari PHP jika ada -->
                        <?php if (!empty($error)): ?>
                        <div class="alert-custom shake-element">
                            <i class="fa fa-exclamation-circle"></i> <?= $error; ?>
                        </div>
                        <?php endif; ?>

                    </form>
                </div>
            </div>
            
            <div class="login-footer">
                &copy; <?= date('Y') ?> Wartelpas System. All rights reserved.
            </div>
        </div>
    </div>

    <!-- jQuery & Bootstrap 3 JS -->
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>

    <script>
        $(document).ready(function() {
            // Handler saat form disubmit (Validasi Sisi Klien)
            $('#loginForm').on('submit', function(e) {
                // Hapus validasi HTML5 bawaan browser agar style kita yang muncul
                // Jika ingin full validasi JS, uncomment baris bawah:
                // e.preventDefault(); 

                // Reset state error sebelumnya
                $('.form-group').removeClass('has-error');
                $('.help-block').hide();
                $('.panel-login').removeClass('shake-element');

                var username = $('#_username').val().trim();
                var password = $('#password').val().trim();
                var hasError = false;

                // Validasi Username
                if (username === '') {
                    $('#group-username').addClass('has-error');
                    $('#error-username').text('Username wajib diisi.').show();
                    hasError = true;
                }

                // Validasi Password
                if (password === '') {
                    $('#group-password').addClass('has-error');
                    $('#error-password').text('Password tidak boleh kosong.').show();
                    hasError = true;
                }

                // Jika ada error, batalkan submit dan getarkan panel
                if (hasError) {
                    e.preventDefault(); // Mencegah submit ke PHP
                    $('.panel-login').addClass('shake-element');
                    
                    setTimeout(function() {
                        $('.panel-login').removeClass('shake-element');
                    }, 300);
                } else {
                    // Jika valid, biarkan form submit ke PHP (action="")
                    // Tambahkan efek loading pada tombol
                    var $btn = $('button[type="submit"]');
                    $btn.html('<i class="fa fa-spinner fa-spin"></i> Memproses...');
                    // Form akan reload halaman setelah ini
                }
            });

            // Hilangkan error real-time saat user mengetik
            $('#_username, #password').on('input', function() {
                $(this).closest('.form-group').removeClass('has-error');
                $(this).closest('.form-group').find('.help-block').hide();
            });
        });
    </script>
</body>
</html>