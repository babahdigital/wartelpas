<?php
session_start();
error_reporting(0);

require_once __DIR__ . '/acl.php';
requireLogin('../admin.php?id=login');
ensureRole();
if (isOperator() && !operator_can('ann_manage')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Akses ditolak. Izin popup diperlukan.']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'message' => 'Metode tidak diizinkan.']);
    exit;
}

$csrf = $_SESSION['csrf_token'] ?? '';
$token = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if ($csrf === '' || $token === '' || !hash_equals($csrf, $token)) {
    echo json_encode(['ok' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}

if (empty($_FILES['image']) || !isset($_FILES['image']['tmp_name'])) {
    echo json_encode(['ok' => false, 'message' => 'File tidak ditemukan.']);
    exit;
}

$file = $_FILES['image'];
if (!empty($file['error'])) {
    echo json_encode(['ok' => false, 'message' => 'Upload gagal.']);
    exit;
}

$maxSize = 2 * 1024 * 1024; // 2MB
if (!empty($file['size']) && $file['size'] > $maxSize) {
    echo json_encode(['ok' => false, 'message' => 'Ukuran file terlalu besar. Maks 2MB.']);
    exit;
}

$allowed = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp'
];

$mime = '';
if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo) {
        $mime = finfo_file($finfo, $file['tmp_name']) ?: '';
        finfo_close($finfo);
    }
}
if ($mime === '' && function_exists('mime_content_type')) {
    $mime = mime_content_type($file['tmp_name']) ?: '';
}

if ($mime === '' || !isset($allowed[$mime])) {
    echo json_encode(['ok' => false, 'message' => 'Format gambar tidak didukung (jpg/png/webp).']);
    exit;
}

$ext = $allowed[$mime];
$name = 'popup_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$targetDir = dirname(__DIR__) . '/img/popup';
if (!is_dir($targetDir)) {
    @mkdir($targetDir, 0755, true);
}
$targetPath = $targetDir . '/' . $name;

if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    echo json_encode(['ok' => false, 'message' => 'Gagal menyimpan file.']);
    exit;
}

$url = '/img/popup/' . $name;
echo json_encode(['ok' => true, 'url' => $url]);
