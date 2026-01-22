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
$error = isset($error) ? $error : '';
// $_please_login tidak lagi ditampilkan sesuai permintaan, tapi variabel dibiarkan untuk kompatibilitas backend
$_please_login = isset($_please_login) ? $_please_login : 'Silakan Login';

?>
    <div class="container">
        <div class="login-container">
            <div class="panel panel-default panel-login">
                <div class="panel-heading">
                    <!-- Logo Utama -->
                    <img src="img/logo.png" alt="Logo" class="logo-img" onerror="this.onerror=null;this.src='logo.png';">
                </div>
                <div class="panel-body">
                    <form id="loginForm" action="" method="post" autocomplete="off">
                        
                        <!-- Username Input -->
                        <div class="form-group" id="group-username">
                            <label class="sr-only" for="_username">Username</label>
                            <div class="input-group">
                                <span class="input-group-addon"><i class="fa fa-user"></i></span>
                                <input type="text" class="form-control" id="_username" name="user" placeholder="Username" required autofocus>
                            </div>
                            <span class="help-block" id="error-username">Username wajib diisi.</span>
                        </div>

                        <!-- Password Input -->
                        <div class="form-group" id="group-password">
                            <label class="sr-only" for="password">Password</label>
                            <div class="input-group">
                                <span class="input-group-addon"><i class="fa fa-lock"></i></span>
                                <input type="password" class="form-control" id="password" name="pass" placeholder="Password" required>
                            </div>
                            <span class="help-block" id="error-password">Password wajib diisi.</span>
                        </div>

                        <!-- Tombol Login -->
                        <button type="submit" name="login" value="Login" class="btn btn-login btn-block">
                            <i class="fa fa-sign-in"></i> Masuk Aplikasi
                        </button>

                        <!-- Error PHP -->
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
                    // Jika valid, submit normal ke PHP
                    var $btn = $('button[type="submit"]');
                    $btn.html('<i class="fa fa-spinner fa-spin"></i> Memproses...');
                }
            });

            // Hilangkan error saat mengetik
            $('#_username, #password').on('input', function() {
                $(this).closest('.form-group').removeClass('has-error');
                $(this).closest('.form-group').find('.help-block').hide();
            });
        });
    </script>
    <style>
        /* --- DARK MODE REFINED STYLES --- */
        body {
            background-color: #252525; /* Abu-abu gelap lembut */
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
            max-width: 450px; /* Lebar card proporsional */
            padding: 15px;
            margin: auto;
        }

        /* Panel Login Styling */
        .panel-login {
            border: none;
            border-radius: 10px;
            background: #333333; 
            box-shadow: 0 10px 30px rgba(0,0,0,0.4); 
        }

        .panel-login .panel-heading {
            background-color: #333333;
            border-bottom: 1px solid #444;
            border-radius: 10px 10px 0 0;
            padding: 30px 15px 10px 15px;
            text-align: center;
        }

        .logo-img {
            max-height: 90px;
            width: auto;
            margin-bottom: 5px;
            display: block;
            margin-left: auto;
            margin-right: auto;
        }

        .panel-login .panel-body {
            padding: 40px;
        }

        .form-group {
            margin-bottom: 25px;
        }

        /* --- SEMPURNAKAN FLEXBOX INPUT GROUP --- */
        
        .input-group {
            /* 1. Aktifkan Flex pada Container Utama */
            display: flex; 
            width: 100%;
            /* 'stretch' memastikan tinggi Addon dan Input otomatis sama */
            align-items: stretch; 
        }
        
        .input-group-addon {
            /* 2. Aktifkan Flex pada Kotak Ikon (Addon) */
            display: flex;
            align-items: center;     /* Tengahkan Vertikal secara otomatis */
            justify-content: center; /* Tengahkan Horizontal secara otomatis */
            
            width: 50px;      /* Lebar fix */
            min-width: 50px;  /* Pastikan tidak mengecil */
            padding: 0;       /* Reset padding bawaan bootstrap */
            
            background-color: #444;
            border: 1px solid #555;
            border-right: 0;  /* Hilangkan border kanan agar menyatu dengan input */
            color: #ccc;
            font-size: 18px;
            border-radius: 4px 0 0 4px;
        }

        .input-group .form-control {
            /* 3. Input mengisi sisa ruang yang tersedia */
            flex: 1; 
            width: auto !important; /* Override width 100% bawaan */
            float: none; /* Matikan float bawaan */
            
            height: 50px;
            background-color: #444;
            border: 1px solid #555;
            color: #fff;
            font-size: 16px;
            padding-left: 15px; /* Spasi teks di dalam input */
            
            border-radius: 0 4px 4px 0;
            box-shadow: none;
            z-index: 1; /* Manajemen tumpukan layer */
        }

        /* Hapus margin/padding manual pada icon karena sudah dicenter oleh Flexbox */
        .input-group-addon i {
            margin: 0; 
        }

        /* Efek Focus */
        .form-control:focus {
            background-color: #505050;
            border-color: #337ab7;
            /* Tambahkan z-index agar border focus muncul di atas addon */
            z-index: 2; 
            position: relative;
            color: #fff;
        }
        
        /* Saat input focus, border addon juga ikut berubah warna agar terlihat menyatu */
        .form-control:focus + .input-group-addon, /* Selector jaga-jaga */
        .input-group:focus-within .input-group-addon {
            border-color: #337ab7;
        }

        /* Placeholder color */
        .form-control::-webkit-input-placeholder { color: #888; }
        .form-control:-moz-placeholder { color: #888; }
        .form-control::-moz-placeholder { color: #888; }
        .form-control:-ms-input-placeholder { color: #888; }

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
            color: #ff6b6b;
        }

        .has-error .help-block {
            display: block;
        }

        /* Tombol Login */
        .btn-login {
            background-color: #337ab7;
            border-color: #2e6da4;
            color: #fff;
            padding: 0; /* Reset padding karena menggunakan flex center/height */
            display: flex;
            align-items: center;
            justify-content: center;
            
            font-size: 18px;
            font-weight: 600;
            border-radius: 4px;
            transition: all 0.3s;
            margin-top: 15px;
            height: 50px;
            width: 100%;
        }

        .btn-login i {
            margin-right: 8px;
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
</body>
</html>