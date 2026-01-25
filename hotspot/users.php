<?php
$debug_mode = isset($_GET['debug']) && $_GET['debug'] == '1';
if ($debug_mode) {
	ini_set('display_errors', '1');
	ini_set('display_startup_errors', '1');
	error_reporting(E_ALL);
}

$base_dir = __DIR__ . '/user/';
$required_files = ['bootstrap.php', 'helpers.php', 'data.php', 'actions.php', 'render.php'];
foreach ($required_files as $file) {
	if (!file_exists($base_dir . $file)) {
		die("<div style='padding:10px;color:#fff;background:#b91c1c;'>File tidak ditemukan: {$base_dir}{$file}</div>");
	}
}

require $base_dir . 'bootstrap.php';
require $base_dir . 'helpers.php';
require $base_dir . 'data.php';
require $base_dir . 'actions.php';
require $base_dir . 'render.php';
