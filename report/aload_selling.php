<?php
session_start();
error_reporting(0);

if (!isset($_SESSION["mikhmon"])) {
    header('Content-Type: text/html; charset=UTF-8');
    echo '<div style="padding:20px; background:#1f2327; color:#fff; border:1px solid #3a4046; border-radius:8px;">'
        . '<div style="font-weight:700; margin-bottom:6px;">Sesi berakhir</div>'
        . '<div style="font-size:12px; color:#cbd5e1;">Silakan login ulang untuk membuka dashboard penjualan.</div>'
        . '<div style="margin-top:10px;"><a href="./admin.php?id=login" style="color:#93c5fd; text-decoration:underline;">Login</a></div>'
        . '</div>';
    exit;
}

$session = $_GET['session'] ?? '';
if ($session !== '') {
    include('../include/config.php');
    include('../include/readcfg.php');
}

$_GET['ajax'] = '1';
require_once __DIR__ . '/laporan/data.php';
require_once __DIR__ . '/laporan/render.php';
