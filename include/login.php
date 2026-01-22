<?php
/*
 * Copyright (C) 2018 Laksamadi Guko.
 * Modified & Reskinned 2024 for Wartelpas (Dark Mode Enhanced).
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
// $_please_login tidak lagi ditampilkan sesuai permintaan, tapi variabel dibiarkan untuk kompatibilitas backend
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
        /* --- DARK MODE REFINED STYLES --- */
        body {
            /* Mengubah background body dari hitam pekat ke abu-abu gelap lembut */
            background-color: #252525; 
            color: #eeeeee;
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
        }

        .login-container {
            width: 100%;
            /* MELEBARKAN CARD: Dinaikkan dari 400px ke 450px agar lebih proporsional */
            max-width: 450px; 
            padding: 15px;
            margin: auto;
        }

        /* Panel Login Styling */
        .panel-login {
            border: none; /* Menghilangkan border default */
            border-radius: 10px; /* Radius lebih halus */
            /* Menggunakan warna panel yang sedikit lebih terang dari background body */
            background: #333333; 
            /* Shadow yang lebih lembut tapi tetap terlihat dimensi */
            box-shadow: 0 10px 30px rgba(0,0,0,0.4); 
        }

        .panel-login .panel-heading {
            background-color: #333333; /* Warna sama dengan body panel */
            border-bottom: 1px solid #444; /* Garis pemisah halus */
            border-radius: 10px 10px 0 0;
            padding: 30px 15px 10px 15px; /* Padding atas lebih besar */
            text-align: center;
        }

        .logo-img {
            max-height: 90px; /* Sedikit diperbesar */
            width: auto;
            margin-bottom: 5px;
            display: block;
            margin-left: auto;
            margin-right: auto;
        }

        .panel-login .panel-body {
            padding: 40px; /* Padding isi lebih luas agar tidak sempit */
        }

        .form-group {
            margin-bottom: 25px; /* Jarak antar input diperlebar sedikit */
        }

        /* FIX: Memastikan Input Group (Icon + Input) Berdampingan dengan sempurna */
        .input-group {
            width: 100%;
            border-collapse: separate;
            display: table;
        }
        
        /* Styling Input Group Addon (Icon Box) */
        .input-group-addon {
            background-color: #444;
            border-color: #555;
            color: #ccc;
            min-width: 50px; /* Lebar icon fix */
            border-radius: 4px 0 0 4px;
            /* Memastikan border menyatu dengan input */
            border-right: 0; 
        }

        /* Input Field Styling */
        .form-control {
            background-color: #444;
            border-color: #555;
            color: #fff;
            height: 50px; /* Tinggi input diperbesar agar lebih gagah */
            font-size: 16px;
            border-radius: 0 4px 4px 0; /* Radius hanya di kanan */
            box-shadow: none;
            /* Pastikan input mengisi sisa ruang */
            width: 100% !important; 
        }

        .form-control:focus {
            background-color: #505050;
            border-color: #337ab7;
            box-shadow: none; /* Hapus shadow default bootstrap */
            color: #fff;
        }

        /* Placeholder color */
        .form-control::-webkit-input-placeholder { color: #888; }
        .form-control:-moz-placeholder { color: #888; }
        .form-control::-moz-placeholder { color: #888; }
        .form-control:-ms-input-placeholder { color: #888; }

        /* Icon Styling di dalam addon */
        .input-group-addon i {
            font-size: 18px; /* Icon diperbesar sedikit */
        }

        /* Error States Styling */
        .has-error .form-control {
            border-color: #e74c3c;
        }
        
        .has-error .input-group-addon {
            background-color: #3e2b2b;
            border-color: #e74c3c;
            color: #e74c3c;
        }

        .help-block {
            display: none;
            font-size: 13px;
            margin-top: 5px;
            margin-bottom: 0;
            text-align: left;
            color: #ff6b6b; /* Warna error merah soft */
        }

        .has-error .help-block {
            display: block;
        }

        /* Tombol Login */
        .btn-login {
            background-color: #337ab7;
            border-color: #2e6da4;
            color: #fff;
            padding: 12px;
            font-size: 18px; /* Font tombol lebih besar */
            font-weight: 600;
            border-radius: 4px;
            transition: all 0.3s;
            margin-top: 15px;
            height: 50px; /* Tinggi disamakan dengan input */
        }

        .btn-login:hover, .btn-login:focus {
            background-color: #286090;
            color: #fff;
            border-color: #204d74;
            box-shadow: 0 4px 10px rgba(0,0,0,0.3);
        }

        /* Alert Styling for PHP Errors */
        .alert-custom {
            background-color: #3e2b2b;
            color: #ff6b6b;
            border: 1px solid #e74c3c;
            border-radius: 4px;
            padding: 12px;
            margin-top: 25px;
            font-size: 14px;
            text-align: center;
        }

        .login-footer {
            margin-top: 25px;
            text-align: center;
            font-size: 13px;
            color: #888;
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
                    <!-- Logo Utama -->
                    <!-- Menggunakan fallback logo: Coba load img/logo.png, kalau gagal coba logo.png -->
                    <img src="img/logo.png" alt="Logo" class="logo-img" onerror="this.onerror=null;this.src='logo.png';">
                    <!-- Tulisan 'Silakan Login' Dihapus sesuai permintaan -->
                </div>
                <div class="panel-body">
                    <form id="loginForm" action="" method="post" autocomplete="off">
                        
                        <!-- Username Input -->
                        <div class="form-group" id="group-username">
                            <label class="sr-only" for="_username">Username</label>
                            <div class="input-group">
                                <span class="input-group-addon"><i class="fa fa-user"></i></span>
                                <!-- Name 'user' dipertahankan untuk backend -->
                                <input type="text" class="form-control" id="_username" name="user" placeholder="Username" required autofocus>
                            </div>
                            <span class="help-block" id="error-username">Username wajib diisi.</span>
                        </div>

                        <!-- Password Input -->
                        <div class="form-group" id="group-password">
                            <label class="sr-only" for="password">Password</label>
                            <div class="input-group">
                                <span class="input-group-addon"><i class="fa fa-lock"></i></span>
                                <!-- Name 'pass' dipertahankan untuk backend -->
                                <input type="password" class="form-control" id="password" name="pass" placeholder="Password" required>
                            </div>
                            <span class="help-block" id="error-password">Password wajib diisi.</span>
                        </div>

                        <!-- Tombol Login -->
                        <!-- Name 'login' dipertahankan untuk backend -->
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
                    // Jika valid, form akan submit secara normal ke PHP
                    var $btn = $('button[type="submit"]');
                    $btn.html('<i class="fa fa-spinner fa-spin"></i> Memproses...');
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