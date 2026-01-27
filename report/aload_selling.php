<?php
session_start();
error_reporting(0);

if (!isset($_SESSION["mikhmon"])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
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
