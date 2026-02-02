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

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

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
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
                        
                        <!-- Username Input -->
                        <div class="form-group" id="group-username">
                            <label class="sr-only" for="_username">Username</label>
                            <div class="input-group">
                                <span class="input-group-addon"><i class="fa fa-user"></i></span>
                                <input type="text" class="form-control" id="_username" name="user" placeholder="Username" required autofocus autocomplete="username">
                            </div>
                            <span class="help-block" id="error-username">Username wajib diisi.</span>
                        </div>

                        <!-- Password Input -->
                        <div class="form-group" id="group-password">
                            <label class="sr-only" for="password">Password</label>
                            <div class="input-group">
                                <span class="input-group-addon"><i class="fa fa-lock"></i></span>
                                <input type="password" class="form-control" id="password" name="pass" placeholder="Password" required autocomplete="current-password">
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
                &copy; <?= date('Y') ?> Security By Babah Digital. All rights reserved.
            </div>
        </div>
    </div>

    <div id="sidenav" style="display:none"></div>
    <div id="main" style="display:none"></div>
    <a id="openNav" href="javascript:void(0)" style="display:none"></a>
    <a id="closeNav" href="javascript:void(0)" style="display:none"></a>
    <div id="overL" style="display:none"></div>
    <span id="idto" style="display:none">disable</span>
    <span id="timer" style="display:none"></span>
    <a id="logout" href="javascript:void(0)" style="display:none"></a>

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
</body>
</html>