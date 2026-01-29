<?php
session_start();
error_reporting(0);

if (!isset($_SESSION["mikhmon"])) {
    header("Location:../admin.php?id=login");
    exit;
}

require_once(__DIR__ . '/../include/acl.php');
if (!isSuperAdmin()) {
    http_response_code(403);
    echo "Akses ditolak.";
    exit;
}

$root_dir = dirname(__DIR__);
$env = [];
$envFile = $root_dir . '/include/env.php';
if (file_exists($envFile)) {
    require $envFile;
}

$system_cfg = $env['system'] ?? [];
$db_rel = $system_cfg['db_file'] ?? 'db_data/mikhmon_stats.db';
if (preg_match('/^[A-Za-z]:\\|^\//', $db_rel)) {
    $dbFile = $db_rel;
} else {
    $dbFile = $root_dir . '/' . ltrim($db_rel, '/');
}

$user = isset($_GET['user']) ? trim((string)$_GET['user']) : '';
$date = isset($_GET['date']) ? trim((string)$_GET['date']) : '';
$all = isset($_GET['all']) && $_GET['all'] == '1';

if ($date === '') {
    $date = date('Y-m-d');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo "Format tanggal tidak valid (YYYY-MM-DD).";
    exit;
}
if ($user !== '' && !preg_match('/^[A-Za-z0-9._-]{2,32}$/', $user)) {
    echo "Username tidak valid.";
    exit;
}

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $exists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='vip_actions'");
    if (!$exists || !$exists->fetchColumn()) {
        echo "Tabel vip_actions belum ada.";
        exit;
    }

    if ($all) {
        $stmt = $db->prepare("DELETE FROM vip_actions WHERE date_key = :d");
        $stmt->execute([':d' => $date]);
        $deleted = $stmt->rowCount();
        echo "OK. Hapus semua VIP tanggal {$date}: {$deleted} baris.";
        exit;
    }

    if ($user !== '') {
        $stmt = $db->prepare("DELETE FROM vip_actions WHERE username = :u AND date_key = :d");
        $stmt->execute([':u' => $user, ':d' => $date]);
        $deleted = $stmt->rowCount();
        echo "OK. Hapus VIP user {$user} tanggal {$date}: {$deleted} baris.";
        exit;
    }

    $stmt = $db->prepare("SELECT COUNT(DISTINCT username) FROM vip_actions WHERE date_key = :d");
    $stmt->execute([':d' => $date]);
    $count = (int)$stmt->fetchColumn();
    echo "Info. Total VIP tanggal {$date}: {$count} user.\n";
    echo "Gunakan parameter: ?user=USERNAME&date=YYYY-MM-DD atau ?all=1&date=YYYY-MM-DD";
} catch (Exception $e) {
    echo "Gagal akses DB.";
}
