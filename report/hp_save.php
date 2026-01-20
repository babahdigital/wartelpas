<?php
// FILE: report/hp_save.php
// Simpan data handphone per blok (AJAX)

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION["mikhmon"])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Session tidak valid.']);
    exit;
}

$root_dir = dirname(__DIR__);
$dbFile = $root_dir . '/db_data/mikhmon_stats.db';
if (!is_dir($root_dir . '/db_data')) {
    mkdir($root_dir . '/db_data', 0777, true);
}

$session_id = $_POST['session'] ?? '';
$session_qs = $session_id !== '' ? '&session=' . urlencode($session_id) : '';
$show = $_POST['show'] ?? 'harian';
$date = $_POST['date'] ?? '';
if ($date === '') {
    $date = $show === 'bulanan' ? date('Y-m') : ($show === 'tahunan' ? date('Y') : date('Y-m-d'));
}

$blok_name = trim($_POST['blok_name'] ?? '');
$report_date = trim($_POST['report_date'] ?? '');
$total_units = (int)($_POST['total_units'] ?? 0);
$rusak_units = (int)($_POST['rusak_units'] ?? 0);
$spam_units = (int)($_POST['spam_units'] ?? 0);
$active_units = max(0, $total_units - $rusak_units - $spam_units);
$notes = trim($_POST['notes'] ?? '');

$use_wartel = isset($_POST['unit_wartel']) ? 1 : 0;
$use_kamtib = isset($_POST['unit_kamtib']) ? 1 : 0;
$wartel_units = (int)($_POST['wartel_units'] ?? 0);
$kamtib_units = (int)($_POST['kamtib_units'] ?? 0);
$sum_units = ($use_wartel ? $wartel_units : 0) + ($use_kamtib ? $kamtib_units : 0);

if ($blok_name === '' || $report_date === '') {
    echo json_encode(['ok' => false, 'message' => 'Blok dan tanggal wajib diisi.']);
    exit;
}

if (!$use_wartel && !$use_kamtib) {
    echo json_encode(['ok' => false, 'message' => 'Pilih minimal salah satu unit (WARTEL/KAMTIB).']);
    exit;
}

if ($use_wartel && !$use_kamtib && $total_units !== $wartel_units) {
    echo json_encode(['ok' => false, 'message' => 'Jika hanya WARTEL dipilih, jumlahnya harus sama dengan total.']);
    exit;
}

if (!$use_wartel && $use_kamtib && $total_units !== $kamtib_units) {
    echo json_encode(['ok' => false, 'message' => 'Jika hanya KAMTIB dipilih, jumlahnya harus sama dengan total.']);
    exit;
}

if ($use_wartel && $use_kamtib && $total_units !== $sum_units) {
    echo json_encode(['ok' => false, 'message' => 'Total unit harus sama dengan jumlah WARTEL + KAMTIB.']);
    exit;
}

if ($total_units < ($rusak_units + $spam_units)) {
    echo json_encode(['ok' => false, 'message' => 'Total unit tidak boleh kurang dari Rusak + Spam.']);
    exit;
}

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE TABLE IF NOT EXISTS phone_block_daily (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        report_date TEXT,
        blok_name TEXT,
        unit_type TEXT,
        total_units INTEGER,
        active_units INTEGER,
        rusak_units INTEGER,
        spam_units INTEGER,
        notes TEXT,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(report_date, blok_name, unit_type)
    )");
    try { $db->exec("ALTER TABLE phone_block_daily ADD COLUMN unit_type TEXT"); } catch (Exception $e) {}

    $stmt = $db->prepare("INSERT INTO phone_block_daily
        (report_date, blok_name, unit_type, total_units, active_units, rusak_units, spam_units, notes, updated_at)
        VALUES (:d, :b, :ut, :t, :a, :r, :s, :n, CURRENT_TIMESTAMP)
        ON CONFLICT(report_date, blok_name, unit_type) DO UPDATE SET
          unit_type=excluded.unit_type,
          total_units=excluded.total_units,
          active_units=excluded.active_units,
          rusak_units=excluded.rusak_units,
          spam_units=excluded.spam_units,
          notes=excluded.notes,
          updated_at=CURRENT_TIMESTAMP
    ");

    $stmt->execute([
        ':d' => $report_date,
        ':b' => $blok_name,
        ':ut' => 'TOTAL',
        ':t' => $total_units,
        ':a' => $active_units,
        ':r' => $rusak_units,
        ':s' => $spam_units,
        ':n' => $notes
    ]);

    if ($use_wartel) {
        $stmt->execute([
            ':d' => $report_date,
            ':b' => $blok_name,
            ':ut' => 'WARTEL',
            ':t' => $wartel_units,
            ':a' => 0,
            ':r' => 0,
            ':s' => 0,
            ':n' => ''
        ]);
    }
    if ($use_kamtib) {
        $stmt->execute([
            ':d' => $report_date,
            ':b' => $blok_name,
            ':ut' => 'KAMTIB',
            ':t' => $kamtib_units,
            ':a' => 0,
            ':r' => 0,
            ':s' => 0,
            ':n' => ''
        ]);
    }

    $redirect = './?report=selling' . $session_qs . '&show=' . urlencode($show) . '&date=' . urlencode($date);
    echo json_encode(['ok' => true, 'redirect' => $redirect]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Gagal menyimpan data.']);
}
