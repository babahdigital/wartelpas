<?php
error_reporting(0);
require_once __DIR__ . '/../../include/popup_announcement.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $data = popup_announcement_get_active_public();
    echo json_encode(['ok' => true, 'data' => $data]);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'message' => 'Gagal memuat informasi.']);
}
