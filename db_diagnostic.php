<?php
/*
 * DATABASE DIAGNOSTIC TOOL
 * File: db_diagnostic.php
 * Gunakan untuk cek apakah database dan data tersimpan dengan benar
 */

session_start();
if (!isset($_SESSION["mikhmon"])) {
    die("ERROR: Session tidak aktif. Login dulu!");
}

echo "<html><head>";
echo "<style>
    body { font-family: 'Courier New', monospace; background: #1e2226; color: #ecf0f1; padding: 20px; }
    h2 { color: #3498db; border-bottom: 2px solid #3498db; padding-bottom: 10px; }
    .success { color: #2ecc71; font-weight: bold; }
    .error { color: #e74c3c; font-weight: bold; }
    .info { color: #f39c12; }
    table { width: 100%; border-collapse: collapse; margin: 20px 0; background: #2a3036; }
    th { background: #3498db; color: white; padding: 10px; text-align: left; }
    td { padding: 8px; border-bottom: 1px solid #495057; }
    tr:hover { background: #343a40; }
    .box { background: #2a3036; padding: 15px; margin: 15px 0; border-left: 4px solid #3498db; border-radius: 4px; }
    .code { background: #1b1e21; padding: 10px; border-radius: 4px; overflow-x: auto; margin: 10px 0; }
    .btn { display: inline-block; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 6px; margin: 10px 5px 10px 0; }
    .btn:hover { background: #2980b9; }
</style>";
echo "</head><body>";

echo "<h1>🔍 Database Diagnostic Tool - Wartelpas</h1>";
echo "<p><a href='?action=refresh' class='btn'>🔄 Refresh Data</a> <a href='./?hotspot=users&session=" . ($_GET['session'] ?? 'default') . "' class='btn' style='background:#2ecc71'>← Kembali ke Users</a></p>";

// === 1. CEK DATABASE FILE ===
echo "<h2>1. Database File Check</h2>";
$dbDir = __DIR__ . '/db_data';
$dbFile = $dbDir . '/mikhmon_stats.db';

echo "<div class='box'>";
echo "<strong>DB Directory:</strong> " . $dbDir . "<br>";
echo "<strong>DB File:</strong> " . $dbFile . "<br>";

if (file_exists($dbFile)) {
    $size = filesize($dbFile);
    $perms = substr(sprintf('%o', fileperms($dbFile)), -4);
    echo "<strong>Status:</strong> <span class='success'>✅ File ada</span><br>";
    echo "<strong>Size:</strong> " . number_format($size) . " bytes (" . round($size/1024, 2) . " KB)<br>";
    echo "<strong>Permissions:</strong> " . $perms . "<br>";
    echo "<strong>Writable:</strong> " . (is_writable($dbFile) ? "<span class='success'>✅ Yes</span>" : "<span class='error'>❌ No - PERMISSION ERROR!</span>") . "<br>";
} else {
    echo "<strong>Status:</strong> <span class='error'>❌ File tidak ada! Database belum dibuat.</span><br>";
}
echo "</div>";

// === 2. KONEKSI DATABASE ===
echo "<h2>2. Database Connection</h2>";
$db = null;
try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "<div class='box'><span class='success'>✅ Koneksi database BERHASIL</span></div>";
} catch(Exception $e) {
    echo "<div class='box'><span class='error'>❌ Koneksi database GAGAL: " . $e->getMessage() . "</span></div>";
    die();
}

// === 3. CEK TABEL ===
echo "<h2>3. Table Structure</h2>";
try {
    $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    echo "<div class='box'>";
    echo "<strong>Tables ditemukan:</strong> " . count($tables) . "<br>";
    foreach($tables as $t) {
        echo "- $t<br>";
    }
    echo "</div>";
    
    if (in_array('login_history', $tables)) {
        echo "<div class='box'><span class='success'>✅ Table 'login_history' ada</span><br>";
        
        // Get columns
        $cols = $db->query("PRAGMA table_info(login_history)")->fetchAll(PDO::FETCH_ASSOC);
        echo "<strong>Kolom-kolom:</strong><br>";
        echo "<table><tr><th>Name</th><th>Type</th><th>Not Null</th><th>Default</th></tr>";
        foreach($cols as $col) {
            echo "<tr><td>{$col['name']}</td><td>{$col['type']}</td><td>" . ($col['notnull'] ? 'Yes' : 'No') . "</td><td>{$col['dflt_value']}</td></tr>";
        }
        echo "</table>";
        echo "</div>";
    } else {
        echo "<div class='box'><span class='error'>❌ Table 'login_history' TIDAK ADA!</span></div>";
    }
} catch(Exception $e) {
    echo "<div class='box'><span class='error'>❌ Error: " . $e->getMessage() . "</span></div>";
}

// === 4. CEK DATA ===
echo "<h2>4. Data dalam Database</h2>";
try {
    $stmt = $db->query("SELECT COUNT(*) FROM login_history");
    $total = $stmt->fetchColumn();
    
    echo "<div class='box'>";
    echo "<strong>Total Records:</strong> <span class='info'>$total</span> user tersimpan<br>";
    
    if ($total > 0) {
        // Count by blok
        $stmt_blok = $db->query("SELECT blok_name, COUNT(*) as cnt FROM login_history WHERE blok_name != '' GROUP BY blok_name ORDER BY blok_name");
        $bloks = $stmt_blok->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<strong>Data per Blok:</strong><br>";
        echo "<table><tr><th>Blok</th><th>Jumlah User</th></tr>";
        foreach($bloks as $b) {
            echo "<tr><td>{$b['blok_name']}</td><td>{$b['cnt']}</td></tr>";
        }
        echo "</table>";
        
        // Latest 10 records
        echo "<strong>10 Data Terbaru:</strong><br>";
        $stmt_latest = $db->query("SELECT * FROM login_history ORDER BY updated_at DESC LIMIT 10");
        $latest = $stmt_latest->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<table>";
        echo "<tr><th>Username</th><th>Blok</th><th>IP</th><th>MAC</th><th>Updated</th></tr>";
        foreach($latest as $row) {
            echo "<tr>";
            echo "<td>{$row['username']}</td>";
            echo "<td>" . ($row['blok_name'] ?: '-') . "</td>";
            echo "<td>" . ($row['ip_address'] ?: '-') . "</td>";
            echo "<td>" . ($row['mac_address'] ?: '-') . "</td>";
            echo "<td>{$row['updated_at']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<br><span class='error'>⚠️ TIDAK ADA DATA!</span><br><br>";
        echo "<strong>Kemungkinan Penyebab:</strong><br>";
        echo "1. Script users.php belum pernah dijalankan<br>";
        echo "2. Tidak ada user dengan format comment 'Blok-X'<br>";
        echo "3. Fungsi save_user_history() tidak berjalan<br>";
        echo "4. Permission database write error<br>";
    }
    echo "</div>";
    
} catch(Exception $e) {
    echo "<div class='box'><span class='error'>❌ Error query: " . $e->getMessage() . "</span></div>";
}

// === 5. TEST WRITE ===
echo "<h2>5. Test Write ke Database</h2>";
if (isset($_GET['action']) && $_GET['action'] == 'test_write') {
    try {
        $test_user = "TEST_" . time();
        $stmt = $db->prepare("INSERT INTO login_history (username, blok_name, ip_address, mac_address, updated_at) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$test_user, 'BLOK-TEST', '192.168.1.100', 'AA:BB:CC:DD:EE:FF', date('Y-m-d H:i:s')]);
        echo "<div class='box'><span class='success'>✅ Test write BERHASIL! User $test_user tersimpan.</span></div>";
    } catch(Exception $e) {
        echo "<div class='box'><span class='error'>❌ Test write GAGAL: " . $e->getMessage() . "</span></div>";
    }
} else {
    echo "<div class='box'>";
    echo "<a href='?action=test_write&session=" . ($_GET['session'] ?? '') . "' class='btn' style='background:#f39c12'>🧪 Test Write Data</a>";
    echo "<p style='margin-top:10px; color:#adb5bd'>Klik untuk test apakah database bisa di-write. Akan membuat 1 dummy record.</p>";
    echo "</div>";
}

// === 6. QUERY MANUAL ===
echo "<h2>6. Manual Query</h2>";
if (isset($_POST['query'])) {
    $query = trim($_POST['query']);
    echo "<div class='box'>";
    echo "<strong>Query:</strong> <div class='code'>$query</div>";
    try {
        if (stripos($query, 'SELECT') === 0) {
            $result = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);
            if ($result) {
                echo "<strong>Result:</strong> " . count($result) . " rows<br>";
                echo "<table>";
                // Header
                echo "<tr>";
                foreach(array_keys($result[0]) as $col) {
                    echo "<th>$col</th>";
                }
                echo "</tr>";
                // Data
                foreach($result as $row) {
                    echo "<tr>";
                    foreach($row as $val) {
                        echo "<td>" . htmlspecialchars($val) . "</td>";
                    }
                    echo "</tr>";
                }
                echo "</table>";
            } else {
                echo "<span class='info'>No results</span>";
            }
        } else {
            $db->exec($query);
            echo "<span class='success'>✅ Query executed successfully</span>";
        }
    } catch(Exception $e) {
        echo "<span class='error'>❌ Error: " . $e->getMessage() . "</span>";
    }
    echo "</div>";
}

echo "<div class='box'>";
echo "<form method='POST'>";
echo "<textarea name='query' rows='4' style='width:100%; background:#343a40; color:white; border:1px solid #495057; padding:10px; font-family:monospace;' placeholder='SELECT * FROM login_history WHERE blok_name = \"BLOK-A10\"'>SELECT * FROM login_history ORDER BY updated_at DESC LIMIT 20</textarea><br>";
echo "<button type='submit' class='btn' style='background:#2ecc71'>▶ Execute Query</button>";
echo "</form>";
echo "<p style='color:#adb5bd; font-size:0.9rem'>Examples:<br>
- SELECT * FROM login_history WHERE blok_name = 'BLOK-A10'<br>
- SELECT blok_name, COUNT(*) as total FROM login_history GROUP BY blok_name<br>
- DELETE FROM login_history WHERE username LIKE 'TEST_%'</p>";
echo "</div>";

// === 7. RECOMMENDATIONS ===
echo "<h2>7. Recommendations</h2>";
echo "<div class='box'>";

if ($total == 0) {
    echo "<h3 style='color:#e74c3c'>⚠️ DATABASE KOSONG - Action Required:</h3>";
    echo "<ol style='line-height:2'>";
    echo "<li>Buka halaman <a href='./?hotspot=users&session=" . ($_GET['session'] ?? '') . "' style='color:#3498db'>Users Management</a></li>";
    echo "<li>Tunggu hingga halaman selesai loading (dropdown blok harus muncul)</li>";
    echo "<li>Refresh halaman ini untuk cek apakah data tersimpan</li>";
    echo "<li>Jika masih kosong, cek permission folder <code style='background:#1b1e21;padding:2px 5px'>db_data/</code></li>";
    echo "</ol>";
} else {
    echo "<h3 style='color:#2ecc71'>✅ Database OK</h3>";
    echo "<p>Database sudah berisi $total records. Filter dropdown blok seharusnya sudah berfungsi.</p>";
    
    if ($total < 10) {
        echo "<p class='info'>⚠️ Data masih sedikit ($total records). Pastikan semua user dengan Blok-X sudah ter-load minimal 1x.</p>";
    }
}

echo "</div>";

echo "<hr style='border:1px solid #495057; margin:30px 0'>";
echo "<p style='text-align:center; color:#adb5bd'>Database Diagnostic Tool v1.0 | <a href='./' style='color:#3498db'>Dashboard</a></p>";
echo "</body></html>";
?>
