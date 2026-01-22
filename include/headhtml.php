<?php
/*
 *		Copyright (C) 2018 Laksamadi Guko.
 *
 *		This program is free software; you can redistribute it and/or modify
 *		it under the terms of the GNU General Public License as published by
 *		the Free Software Foundation; either version 2 of the License, or
 *		(at your option) any later version.
 *
 *		This program is distributed in the hope that it will be useful,
 *		but WITHOUT ANY WARRANTY; without even the implied warranty of
 *		MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.		See the
 *		GNU General Public License for more details.
 *
 *		You should have received a copy of the GNU General Public License
 *		along with this program.		If not, see <http://www.gnu.org/licenses/>.
 */
session_start();
 // hide all error
error_reporting(0);
?>
<!DOCTYPE html>
<html>
	<head>
		<title>Wartelpas </title>
		<meta charset="utf-8">
		<meta http-equiv="cache-control" content="private" />
		<meta http-equiv="X-UA-Compatible" content="IE=edge">
		<!-- Tell the browser to be responsive to screen width -->
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<!-- Theme color -->
		<meta name="theme-color" content="<?= $themecolor ?>" />
		<!-- Font Awesome -->
		<link rel="stylesheet" type="text/css" href="css/font-awesome/css/font-awesome.min.css" />
		<!-- Mikhmon UI -->
		<link rel="stylesheet" href="css/mikhmon-ui.<?= $theme; ?>.min.css">
		<!-- favicon -->
		<link rel="icon" href="./img/favicon.png" />
		<!-- jQuery -->
		<script src="js/jquery.min.js"></script>
		<!-- pace -->
		<link href="css/pace.<?= $theme; ?>.css" rel="stylesheet" />
		<script src="js/pace.min.js"></script>
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
	</head>
	<body>
		<div class="wrapper">

			
