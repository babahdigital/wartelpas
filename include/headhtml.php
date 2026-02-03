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
		<link href="css/login.css" rel="stylesheet" />
		<link rel="stylesheet" href="css/popup.css">
		<link rel="stylesheet" href="css/tooltips.css">
		<?php if (!empty($is_admin_layout)) : ?>
		<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
		<?php $adminCssVer = @filemtime(__DIR__ . '/../admin_assets/admin.css') ?: time(); ?>
		<?php $adminJsVer = @filemtime(__DIR__ . '/../admin_assets/admin.js') ?: time(); ?>
		<link rel="stylesheet" href="admin_assets/admin.css?v=<?= $adminCssVer; ?>">
		<script src="admin_assets/admin.js?v=<?= $adminJsVer; ?>" defer></script>
		<?php endif; ?>
		<script src="js/pace.min.js"></script>
		<script src="js/popup.js?v=<?= @filemtime(__DIR__ . '/../js/popup.js') ?: time(); ?>" defer></script>
		<script src="js/tooltips.js" defer></script>

	</head>
	<body>
		<div class="wrapper">

			
