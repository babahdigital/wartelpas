<?php
session_start();
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: text/plain');

$secret_token = 'WartelpasSecureKey';
if (!isset($_GET['key']) || $_GET['key'] !== $secret_token) {
    die("Error: Token Salah.\n");
}

$target_date = isset($_GET['date']) && $_GET['date'] !== '' ? $_GET['date'] : date('Y-m-d');

$root_dir = dirname(__DIR__);
$dbFile = $root_dir . '/db_data/mikhmon_stats.db';
if (!file_exists($dbFile)) {
    die("Error: Database tidak ditemukan.\n");
}

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Hapus login_events berdasarkan date_key
    $delEvents = $db->prepare("DELETE FROM login_events WHERE date_key = :d");
    $delEvents->execute([':d' => $target_date]);
    $deleted_events = $delEvents->rowCount();

    // Hapus login_history berdasarkan tanggal (fallback beberapa kolom)
    $delHist = $db->prepare("DELETE FROM login_history WHERE
        login_date = :d
        OR substr(first_login_real,1,10) = :d
        OR substr(last_login_real,1,10) = :d
        OR substr(login_time_real,1,10) = :d
        OR substr(logout_time_real,1,10) = :d
        OR substr(updated_at,1,10) = :d
    ");
    $delHist->execute([':d' => $target_date]);
    $deleted_history = $delHist->rowCount();

    echo "OK: date={$target_date}, deleted_events={$deleted_events}, deleted_history={$deleted_history}\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
