Saya mengerti frustrasi Anda. Terima kasih atas feedback yang sangat detail dan screenshot-nya. Ini sangat membantu saya melihat celah logika yang tertinggal.

**Analisa Audit Holistik Masalah Anda:**

1. **Dropdown Blok Kosong saat Filter Ready:**
* **Penyebab:** Logika pembuatan list dropdown (`$list_blok`) diletakkan **di dalam** loop filter atau setelah filter diterapkan. Akibatnya, jika Anda memfilter "Status: Ready" dan tidak ada user Ready di Blok A, maka "Blok A" hilang dari dropdown.
* **Solusi:** Kita harus memisahkan proses. **Langkah 1:** Ambil semua data -> **Langkah 2:** Catat semua Blok yang ada (untuk dropdown) -> **Langkah 3:** Baru lakukan Filter (untuk tabel).


2. **Profile Kosong di Tabel:**
* **Penyebab:** Data dari history atau script `on-login` kadang tidak memiliki atribut `profile` yang standar.
* **Solusi:** Tambahkan logika *fallback* paksa. Jika kolom profile kosong, isi otomatis berdasarkan deteksi 10/30 dari nama blok atau komentar.


3. **Hapus Blok "Sangkut" & Script MikroTik:**
* **Penyebab:** Script `delete_block_full` sebelumnya mungkin terlalu spesifik mencari nama user. Script transaksi (`2026-01-28-|-...`) di MikroTik adalah *item terpisah* di menu `/system/script`, bukan di menu `/ip/hotspot/user`.
* **Solusi:** Tambahkan loop khusus yang menghapus `/system/script` yang namanya mengandung teks blok target.


4. **Hapus User Retur (Double Delete):**
* **Penyebab:** Fitur hapus user saat ini hanya menghapus target yang dipilih.
* **Solusi:** Fitur `delete_user_full` harus memiliki "kecerdasan" untuk membaca komentar. Jika ada tulisan `Retur Ref: u12345`, maka user `u12345` juga harus dicari dan dihapus.



---

Berikut adalah **PERBAIKAN FINAL** untuk file `data.php` dan `actions.php`.

### FILE 1: `hotspot/user/data.php`

*Fokus: Memperbaiki Dropdown agar tidak hilang, dan mengisi Profile yang kosong.*

```php
<?php
require_once __DIR__ . '/helpers.php';

// ... (Fungsi save_user_history, get_user_history biarkan tetap ada seperti sebelumnya) ...

if (isset($_GET['action']) || isset($_POST['action'])) {
  return;
}

// ==========================================
// 1. PENGAMBILAN DATA RAW (ROUTER + DB)
// ==========================================

// Ambil User dari Router
$all_users = $API->comm("/ip/hotspot/user/print", array(
    "?server" => $hotspot_server,
    ".proplist" => ".id,name,comment,profile,disabled,bytes-in,bytes-out,uptime"
));
$active = $API->comm("/ip/hotspot/active/print", array(
  "?server" => $hotspot_server,
  ".proplist" => "user,uptime,address,mac-address,bytes-in,bytes-out"
));

$activeMap = [];
foreach($active as $a) {
    if(isset($a['user'])) $activeMap[$a['user']] = $a;
}

// Ambil User dari Database (History yang tidak ada di router)
$router_names = [];
foreach($all_users as $u) { 
    if(!empty($u['name'])) $router_names[$u['name']] = true; 
}

if ($db) {
    try {
        $res = $db->query("SELECT username, raw_comment, blok_name, last_status, last_bytes, last_uptime, validity FROM login_history WHERE username IS NOT NULL AND username != ''");
        while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
            $uname = $row['username'];
            if (isset($router_names[$uname])) continue; // Skip jika sudah ada di router

            $all_users[] = [
                'name' => $uname,
                'comment' => $row['raw_comment'],
                'profile' => 'default', // Nanti kita perbaiki di bawah
                'disabled' => ($row['last_status'] == 'rusak') ? 'true' : 'false',
                'bytes-in' => $row['last_bytes'],
                'bytes-out' => 0,
                'uptime' => $row['last_uptime'],
                'from_db' => true,
                'db_validity' => $row['validity'],
                'db_blok' => $row['blok_name']
            ];
        }
    } catch(Exception $e) {}
}

// ==========================================
// 2. BANGUN DROPDOWN BLOK (SEBELUM FILTER!)
// ==========================================
// Ini solusi agar dropdown TIDAK KOSONG saat filter Ready dipilih
$list_blok = [];
if (!$is_ajax) {
    $temp_bloks = [];
    foreach ($all_users as $u) {
        $c = $u['comment'] ?? '';
        // Ekstrak blok (A10 -> BLOK-A)
        $bn = extract_blok_name($c); 
        
        // Fallback ke data DB jika router kosong
        if ($bn === '' && !empty($u['db_blok'])) {
            $bn = extract_blok_name($u['db_blok']);
        }
        
        // Ambil hurufnya saja agar A10 dan A30 masuk ke grup BLOK-A
        if ($bn !== '') {
            $clean_bn = preg_replace('/[^A-Z]/', '', str_replace('BLOK-', '', $bn));
            if($clean_bn !== '') {
                $temp_bloks['BLOK-' . $clean_bn] = true;
            } else {
                $temp_bloks[$bn] = true;
            }
        }
    }
    $list_blok = array_keys($temp_bloks);
    sort($list_blok, SORT_NATURAL | SORT_FLAG_CASE);
}

// ==========================================
// 3. FILTERING & DATA PROCESSING
// ==========================================
$display_data = [];
$req_prof_clean = trim((string)$req_prof); // 10 atau 30 atau all

foreach($all_users as $u) {
    $name = $u['name'] ?? '';
    $comment = $u['comment'] ?? '';
    
    // --- DETEKSI BLOK ITEM INI ---
    $f_blok = extract_blok_name($comment);
    if ($f_blok === '' && !empty($u['db_blok'])) {
        $f_blok = extract_blok_name($u['db_blok']);
    }
    // Normalisasi ke format BLOK-A (buang angka 10/30)
    if ($f_blok !== '') {
        $core_char = preg_replace('/[^A-Z]/', '', str_replace('BLOK-', '', $f_blok));
        if ($core_char !== '') {
            $f_blok = 'BLOK-' . $core_char;
        }
    }

    // --- DETEKSI PROFILE (FIX PROFILE KOSONG) ---
    $prof_source = $u['profile'] ?? '';
    $prof_db = $u['db_validity'] ?? '';
    $uptime_chk = $u['uptime'] ?? '';
    
    // Deteksi cerdas jenis profile (10 atau 30)
    $profile_kind = detect_profile_kind_unified($prof_source, $comment, $f_blok, $uptime_chk);
    
    // Tampilan Profile di Tabel
    $display_profile = $prof_source;
    if (empty($display_profile) || strtolower($display_profile) === 'default') {
        if (!empty($prof_db)) {
            $display_profile = $prof_db;
        } elseif ($profile_kind !== 'other') {
            $display_profile = $profile_kind . ' Menit'; // Paksa tampil 10 Menit / 30 Menit
        }
    }

    // --- LOGIKA FILTER ---
    
    // 1. Filter Blok
    if ($req_comm != '') {
        $req_comm_clean = str_replace(' ', '', strtoupper($req_comm)); // BLOKA
        $f_blok_clean = str_replace('-', '', strtoupper($f_blok));     // BLOKA
        if ($req_comm_clean !== $f_blok_clean) continue;
    }

    // 2. Filter Profile
    if ($req_prof_clean !== 'all') {
        if ($profile_kind != $req_prof_clean) continue;
    }

    // --- LOGIKA STATUS (Tetap sama seperti sebelumnya) ---
    $disabled = $u['disabled'] ?? 'false';
    $is_active = isset($activeMap[$name]);
    
    // Hitung Bytes & Uptime
    $bytes = (int)($u['bytes-in'] ?? 0) + (int)($u['bytes-out'] ?? 0);
    $uptime = $uptime_chk;
    
    // Logika Status Rusak/Retur
    $comment_rusak = preg_match('/\bAudit:\s*RUSAK\b/i', $comment) || preg_match('/^\s*RUSAK\b/i', $comment);
    $is_retur = stripos($comment, '(Retur)') !== false || stripos($comment, 'Retur Ref:') !== false;
    $is_rusak = $comment_rusak || ($disabled === 'true');
    
    if ($is_retur) $is_rusak = false; // Retur menang atas rusak

    $is_used = (!$is_retur && !$is_rusak && $disabled !== 'true') &&
      ($is_active || $bytes > 50 || ($uptime !== '' && $uptime !== '0s'));

    $status = 'READY';
    if ($is_active) $status = 'ONLINE';
    elseif ($is_retur) $status = 'RETUR';
    elseif ($is_rusak) $status = 'RUSAK';
    elseif ($is_used) $status = 'TERPAKAI';

    // Filter Status Khusus
    if ($req_status == 'ready' && $status !== 'READY') continue;
    if ($req_status == 'online' && $status !== 'ONLINE') continue;
    if ($req_status == 'used' && !in_array($status, ['TERPAKAI','RETUR','RUSAK'])) continue;
    if ($req_status == 'rusak' && $status !== 'RUSAK') continue;
    if ($req_status == 'retur' && $status !== 'RETUR') continue;

    // Filter Search
    if (!empty($req_search)) {
        if (stripos($name, $req_search) === false && stripos($comment, $req_search) === false) continue;
    }

    // ... (Lanjutkan perhitungan login/logout time, IP/MAC, relogin flag, dll) ...
    // ... (Logika save ke DB tetap jalan) ...

    // Masukkan ke array display
    $display_data[] = [
        'uid' => $u['.id'] ?? '',
        'name' => $name,
        'profile' => $display_profile, // Gunakan profile yg sudah diperbaiki
        'profile_kind' => $profile_kind,
        'blok' => $f_blok,
        'ip' => $f_ip ?? '-', // Pastikan variable ini terisi dari logika IP/MAC
        'mac' => $f_mac ?? '-',
        'comment' => $comment,
        'status' => $status,
        'bytes' => $bytes,
        'uptime' => $uptime,
        // ... (field lain sesuai struktur tabel) ...
    ];
}

// ... (Sisa file: Sorting, Pagination, JSON Output sama seperti sebelumnya) ...

```

---

### FILE 2: `hotspot/user/actions.php`

*Fokus: Hapus Blok Sangkut (Script & DB) & Hapus User Retur Sekaligus.*

```php
<?php
// ... (Bagian atas file actions.php) ...

    // --- [ACTION: HAPUS USER + PASANGAN RETUR (CHAIN DELETE)] ---
    if (!$action_blocked && $act == 'delete_user_full') {
      
      $targets = []; // List user yang akan dihapus
      $targets[strtolower($name)] = $name;

      // 1. Cari Parent (User Lama) jika user ini adalah hasil Retur
      // Cek di Router
      $uinfo = $API->comm('/ip/hotspot/user/print', ['?name' => $name, '.proplist' => 'comment']);
      $comm = $uinfo[0]['comment'] ?? '';
      
      // Jika kosong di router, cek di DB
      if ($comm === '' && $db) {
          $hist = get_user_history($name);
          $comm = $hist['raw_comment'] ?? '';
      }
      
      $parent = extract_retur_user_from_ref($comm); // Helper function
      if ($parent != '') {
          $targets[strtolower($parent)] = $parent;
      }

      // 2. Cari Children (User Baru) jika user ini pernah diretur
      // Cari comment yang berisi "Retur Ref:vc-NAMA" atau "Retur Ref:NAMA"
      $search_ref_1 = 'Retur Ref:vc-' . $name;
      $search_ref_2 = 'Retur Ref:' . $name;

      // Scan Router
      $all_users = $API->comm('/ip/hotspot/user/print', ['.proplist' => 'name,comment']);
      foreach ($all_users as $u) {
          $c = $u['comment'] ?? '';
          if (stripos($c, $search_ref_1) !== false || stripos($c, $search_ref_2) !== false) {
              $targets[strtolower($u['name'])] = $u['name'];
          }
      }

      // Scan DB (Untuk user yang sudah hilang di router)
      if ($db) {
          try {
              $stmt = $db->prepare("SELECT username FROM login_history WHERE raw_comment LIKE :r1 OR raw_comment LIKE :r2");
              $stmt->execute([':r1' => "%$search_ref_1%", ':r2' => "%$search_ref_2%"]);
              while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                  $targets[strtolower($r['username'])] = $r['username'];
              }
          } catch(Exception $e){}
      }

      // 3. EKSEKUSI HAPUS MASAL
      $deleted_names = [];
      foreach ($targets as $target_name) {
          if ($target_name == '') continue;

          // Hapus Active
          $actv = $API->comm('/ip/hotspot/active/print', ['?user' => $target_name, '.proplist' => '.id']);
          foreach ($actv as $a) {
              $API->write('/ip/hotspot/active/remove', false);
              $API->write('=.id=' . $a['.id']);
              $API->read();
          }

          // Hapus User Router
          $usr = $API->comm('/ip/hotspot/user/print', ['?name' => $target_name, '.proplist' => '.id']);
          if (isset($usr[0]['.id'])) {
              $API->write('/ip/hotspot/user/remove', false);
              $API->write('=.id=' . $usr[0]['.id']);
              $API->read();
          }

          // Hapus DB
          if ($db) {
              try {
                  $db->beginTransaction();
                  $tbls = ['login_history', 'login_events', 'sales_history', 'live_sales'];
                  foreach ($tbls as $t) {
                      $stmt = $db->prepare("DELETE FROM $t WHERE username = :u");
                      $stmt->execute([':u' => $target_name]);
                  }
                  $db->commit();
              } catch(Exception $e) { if($db->inTransaction()) $db->rollBack(); }
          }
          $deleted_names[] = $target_name;
      }

      $action_message = 'Berhasil hapus user & pasangan retur: ' . implode(', ', $deleted_names);


    // --- [ACTION: HAPUS BLOK SANGKUT (SCRIPT & DB)] ---
    } elseif ($act == 'delete_block_full') {
      
      // Ambil inti huruf blok (A10 -> A, Blok-A -> A)
      $blok_norm = extract_blok_name($blok);
      $core_char = preg_replace('/[^A-Z]/', '', str_replace('BLOK-', '', $blok_norm ?: $blok));
      // Fallback jika nama blok pakai angka
      if ($core_char == '') $core_char = preg_replace('/[^A-Z0-9]/', '', str_replace('BLOK-', '', $blok_norm ?: $blok));

      // Keyword Pencarian
      // Kita cari "Blok-A" (case insensitive). Ini akan mencakup:
      // - "Blok-A"
      // - "Blok-A10"
      // - "2026...-|-Blok-A10"
      $search_key = 'Blok-' . $core_char;
      $db_prefix = 'BLOK-' . $core_char;

      // 1. HAPUS SCRIPT MIKROTIK (Penting untuk membersihkan log transaksi)
      $script_deleted = 0;
      $scripts = $API->comm('/system/script/print', ['.proplist' => '.id,name']);
      foreach ($scripts as $scr) {
          $nm = $scr['name'] ?? '';
          // Cek apakah nama script mengandung "Blok-A"
          if ($nm != '' && stripos($nm, $search_key) !== false) {
              $API->write('/system/script/remove', false);
              $API->write('=.id=' . $scr['.id']);
              $API->read();
              $script_deleted++;
          }
      }

      // 2. HAPUS USER ROUTER & ACTIVE
      $user_deleted = 0;
      $list = $API->comm('/ip/hotspot/user/print', ['.proplist' => '.id,name,comment']);
      foreach ($list as $u) {
          $c = $u['comment'] ?? '';
          if (stripos($c, $search_key) !== false) {
              // Hapus Active
              $ac = $API->comm('/ip/hotspot/active/print', ['?user' => $u['name']]);
              foreach($ac as $a) {
                  $API->write('/ip/hotspot/active/remove', false);
                  $API->write('=.id=' . $a['.id']);
                  $API->read();
              }
              // Hapus User
              $API->write('/ip/hotspot/user/remove', false);
              $API->write('=.id=' . $u['.id']);
              $API->read();
              $user_deleted++;
          }
      }

      // 3. HAPUS DATABASE (Sapu Bersih)
      $db_deleted = 0;
      if ($db) {
          try {
              $db->beginTransaction();
              
              // Hapus jika blok_name = BLOK-A... ATAU comment mengandung Blok-A...
              $where = " (blok_name LIKE :bp OR raw_comment LIKE :cp) ";
              $p = [
                  ':bp' => $db_prefix . '%', 
                  ':cp' => '%' . $search_key . '%'
              ];

              $tables = ['login_history', 'sales_history', 'live_sales'];
              foreach ($tables as $t) {
                  $stmt = $db->prepare("DELETE FROM $t WHERE $where");
                  $stmt->execute($p);
                  if ($t == 'sales_history') $db_deleted += $stmt->rowCount();
              }

              // Hapus Audit Manual & HP
              $tbls_opt = ['audit_rekap_manual', 'phone_block_daily'];
              foreach ($tbls_opt as $to) {
                  try {
                      $stmt = $db->prepare("DELETE FROM $to WHERE blok_name LIKE :bp");
                      $stmt->execute([':bp' => $db_prefix . '%']);
                  } catch(Exception $e){}
              }

              $db->commit();
          } catch(Exception $e) { if($db->inTransaction()) $db->rollBack(); }
      }

      $action_message = "Berhasil Hapus Blok {$db_prefix}.\nRouter: {$user_deleted} user, {$script_deleted} script dihapus.\nDB: Data bersih.";
    }

// ... (Sisa file actions.php) ...

```

**Ringkasan Perbaikan:**

1. **Dropdown Anti-Hilang:** Dropdown kini dibuat di awal script `data.php` sebelum filter status diterapkan. Jadi walau user Ready tidak ada, Blok tetap muncul.
2. **Hapus Script:** Saya menambahkan loop `system/script` di `actions.php` yang mencari nama script mengandung `Blok-A`. Ini akan menghapus data "hantu" di MikroTik.
3. **Hapus User Retur Cerdas:** Cukup klik hapus pada user "Retur", script akan otomatis mencari dan menghapus user asalnya (dan sebaliknya).
4. **Profile Fix:** Jika profile kosong, script `data.php` akan mengisinya otomatis dengan "10 Menit" atau "30 Menit" berdasarkan deteksi cerdas.