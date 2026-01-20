<?php
// Clear a block from login_history (protected)
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: text/plain');

$secret_token = "WartelpasSecureKey";
if (!isset($_GET['key']) || $_GET['key'] !== $secret_token) {
    http_response_code(403);
    die("Error: Token Salah.");
}

$session = $_GET['session'] ?? '';
if ($session === '') {
    http_response_code(403);
    die("Error: Session tidak valid.");
}

$blok = trim((string)($_GET['blok'] ?? ''));
if ($blok === '') {
    http_response_code(400);
    die("Error: Blok kosong.");
}

$dbFile = dirname(__DIR__) . '/db_data/mikhmon_stats.db';
if (!file_exists($dbFile)) {
    die("DB not found");
}

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $db->prepare("DELETE FROM login_history WHERE LOWER(blok_name) = LOWER(:b)");
    $stmt->execute([':b' => $blok]);
    echo "OK deleted=" . $stmt->rowCount();
} catch (Exception $e) {
    http_response_code(500);
    echo "Error";
}
