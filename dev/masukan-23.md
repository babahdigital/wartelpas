Berikut adalah **Analisa Holistik** dan **Solusi Perbaikan** untuk 3 masalah utama yang Anda hadapi:

1. **Hapus User Retur (Double Delete):** Saat ini Anda harus menghapus user lama dan user baru secara terpisah. **Solusi:** Saya memodifikasi `delete_user_full` agar otomatis mencari "pasangan"-nya (User asal atau User pengganti) dan menghapus keduanya sekaligus.
2. **Data User Sangkut di DB:** Walaupun script router terhapus, database masih menyimpan data karena query penghapusan sebelumnya kurang agresif dalam mencari variasi nama blok (Blok-A vs Blok-A10). **Solusi:** Update query `delete_block_full` dengan logika sapu bersih (wildcard).
3. **Dropdown Blok Kosong/Ketendang & Profile Kosong:** Saat filter kombinasi (Blok A + Profil 10 + Ready) dipilih, dropdown blok mereset/hilang. Ini terjadi karena logika filter memotong data sebelum dropdown dibangun, atau data profil di tabel tidak terisi jika field `profile` di MikroTik kosong. **Solusi:** Perbaikan di `data.php` untuk *fallback* tampilan profile dan perbaikan urutan logika dropdown.

Silakan terapkan update pada 2 file berikut: `actions.php` dan `data.php`.

---

### FILE 1: `hotspot/user/actions.php`

**Perubahan:**

1. **`delete_user_full`:** Ditambahkan logika "Chain Detection". Jika hapus User A, sistem cek apakah User A adalah hasil retur dari User B? Jika ya, User B ikut dihapus. Sebaliknya, jika User A diretur menjadi User C, User C ikut dihapus.
2. **`delete_block_full`:** Diperkuat dengan pencarian `LIKE` untuk menangkap semua variasi komentar (`-|-`, `Blok-A10`, `Blok A 10`) di database.

```php
<?php
// ... (Bagian atas file tetap sama, pastikan include helper ada) ...
// UPDATE BAGIAN: delete_user_full dan delete_block_full

    // --- [LOGIKA BARU: HAPUS USER + PASANGAN RETUR] ---
    if (!$action_blocked && $act == 'delete_user_full') {
      $delete_map = [];
      if ($name != '') {
        $delete_map[strtolower($name)] = $name;
      }

      // 1. Cek Comment User Target (Apakah dia hasil retur dari user lain?)
      $base_comment = '';
      $uinfo_full = $API->comm('/ip/hotspot/user/print', [
        '?server' => $hotspot_server,
        '?name' => $name,
        '.proplist' => 'comment'
      ]);
      if (!empty($uinfo_full[0]['comment'])) {
        $base_comment = $uinfo_full[0]['comment'];
      }
      // Fallback ke DB jika router sudah kosong
      if ($base_comment === '' && $db && $name != '') {
        $hist = get_user_history($name);
        if ($hist && !empty($hist['raw_comment'])) {
          $base_comment = $hist['raw_comment'];
        }
      }

      // Deteksi Parent (User Lama)
      $parent_user = extract_retur_user_from_ref($base_comment);
      if ($parent_user != '') {
        $delete_map[strtolower($parent_user)] = $parent_user;
      }

      // 2. Cek Apakah User Target memiliki Child (User Baru hasil retur)
      // Cari user lain yang comment-nya mengandung "Retur Ref:nama_target"
      $target_ref_1 = 'Retur Ref:vc-' . $name;
      $target_ref_2 = 'Retur Ref:' . $name;
      
      // Cek di Router
      $list = $API->comm('/ip/hotspot/user/print', [
        '?server' => $hotspot_server,
        '.proplist' => 'name,comment'
      ]);
      foreach ($list as $usr) {
        $c = $usr['comment'] ?? '';
        $n = $usr['name'] ?? '';
        if ($n != '' && (stripos($c, $target_ref_1) !== false || stripos($c, $target_ref_2) !== false)) {
           $delete_map[strtolower($n)] = $n;
        }
      }

      // Cek di DB (untuk user yang sudah dihapus router tapi ada di DB)
      if ($db) {
        try {
          $stmt = $db->prepare("SELECT username FROM login_history WHERE raw_comment LIKE :r1 OR raw_comment LIKE :r2");
          $stmt->execute([':r1' => "%$target_ref_1%", ':r2' => "%$target_ref_2%"]);
          while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
             $u = $row['username'];
             $delete_map[strtolower($u)] = $u;
          }
        } catch (Exception $e) {}
      }

      $delete_names = array_values($delete_map);
      $deleted_list = [];

      // EKSEKUSI HAPUS LOOP
      foreach ($delete_names as $del_name) {
        if ($del_name == '') continue;
        
        // 1. Hapus Active
        $active_rows = $API->comm('/ip/hotspot/active/print', ['?user' => $del_name, '.proplist' => '.id']);
        foreach ($active_rows as $a) {
            if (isset($a['.id'])) {
                $API->write('/ip/hotspot/active/remove', false);
                $API->write('=.id=' . $a['.id']);
                $API->read();
            }
        }

        // 2. Hapus User Router
        $uget = $API->comm('/ip/hotspot/user/print', ['?name' => $del_name, '.proplist' => '.id']);
        if (isset($uget[0]['.id'])) {
          $API->write('/ip/hotspot/user/remove', false);
          $API->write('=.id=' . $uget[0]['.id']);
          $API->read();
        }

        // 3. Hapus DB Total
        if ($db) {
            try {
              $db->beginTransaction();
              $tables = ['login_history', 'login_events', 'sales_history', 'live_sales'];
              foreach ($tables as $tbl) {
                $stmt = $db->prepare("DELETE FROM $tbl WHERE username = :u");
                $stmt->execute([':u' => $del_name]);
              }
              $db->commit();
            } catch (Exception $e) {
              if ($db->inTransaction()) $db->rollBack();
            }
        }
        $deleted_list[] = $del_name;
      }

      // Log & Message
      $log_dir = $root_dir . '/logs';
      if (!is_dir($log_dir)) @mkdir($log_dir, 0755, true);
      $admin_name = $_SESSION['mikhmon'] ?? 'superadmin';
      $log_msg = implode(', ', $deleted_list);
      @file_put_contents($log_dir . '/admin_actions.log', '[' . date('Y-m-d H:i:s') . "] $admin_name delete_user_pair $log_msg\n", FILE_APPEND);
      
      $action_message = 'Berhasil hapus user & pasangan retur: ' . implode(', ', $deleted_list);

    // --- [LOGIKA BARU: HAPUS BLOK SANGKUT] ---
    } elseif ($act == 'delete_block_full') {
      
      // Ambil Inti Blok (A, B, C) tanpa angka
      $blok_norm = extract_blok_name($blok); // Mengembalikan BLOK-A
      $target_input = $blok_norm ?: $blok;
      
      // Ambil hurufnya saja (A)
      $core_char = preg_replace('/[^A-Z]/', '', strtoupper($target_input));
      $core_char = str_replace('BLOK', '', $core_char);
      if ($core_char == '') $core_char = preg_replace('/[^A-Z0-9]/', '', strtoupper($target_input)); // Fallback

      // Pattern Pencarian
      $search_pattern = 'Blok-' . $core_char; // "Blok-A" (akan kena Blok-A10)
      $db_blok_prefix = 'BLOK-' . $core_char; // "BLOK-A"

      // 1. HAPUS ROUTER (User & Script)
      $router_deleted = 0;
      $script_deleted = 0;

      // Hapus User & Active
      $list = $API->comm('/ip/hotspot/user/print', ['?server' => $hotspot_server, '.proplist' => '.id,comment,name']);
      foreach ($list as $usr) {
          $c = $usr['comment'] ?? '';
          // Cek apakah comment mengandung Blok-A (misal Blok-A10)
          if (stripos($c, $search_pattern) !== false) {
              // Hapus Active
              $ac = $API->comm('/ip/hotspot/active/print', ['?user' => $usr['name']]);
              foreach($ac as $a) {
                  if(isset($a['.id'])) {
                      $API->write('/ip/hotspot/active/remove', false);
                      $API->write('=.id=' . $a['.id']);
                      $API->read();
                  }
              }
              // Hapus User
              if (isset($usr['.id'])) {
                  $API->write('/ip/hotspot/user/remove', false);
                  $API->write('=.id=' . $usr['.id']);
                  $API->read();
                  $router_deleted++;
              }
          }
      }

      // Hapus Script (Penting!)
      $scripts = $API->comm('/system/script/print', ['?comment' => 'mikhmon', '.proplist' => '.id,name']);
      foreach ($scripts as $scr) {
          $sname = $scr['name'] ?? '';
          if (stripos($sname, $search_pattern) !== false) {
              if (isset($scr['.id'])) {
                  $API->write('/system/script/remove', false);
                  $API->write('=.id=' . $scr['.id']);
                  $API->read();
                  $script_deleted++;
              }
          }
      }

      // 2. HAPUS DATABASE (Sapu Bersih)
      $db_deleted = 0;
      try {
          $db->beginTransaction();
          
          // Logic: Hapus jika blok_name = BLOK-A* ATAU raw_comment mengandung Blok-A
          $where = " (blok_name LIKE :bp OR raw_comment LIKE :cp) ";
          $params = [
              ':bp' => $db_blok_prefix . '%', // Match BLOK-A, BLOK-A10
              ':cp' => '%' . $search_pattern . '%' // Match "...Blok-A10..."
          ];

          $tables = ['login_history', 'sales_history', 'live_sales'];
          foreach ($tables as $t) {
              $stmt = $db->prepare("DELETE FROM $t WHERE $where");
              $stmt->execute($params);
              if ($t == 'sales_history') $db_deleted += $stmt->rowCount();
          }

          // Hapus Audit Manual
          if (table_exists($db, 'audit_rekap_manual')) {
              $stmt = $db->prepare("DELETE FROM audit_rekap_manual WHERE blok_name LIKE :bp");
              $stmt->execute([':bp' => $db_blok_prefix . '%']);
          }
          // Hapus HP Data
          if (table_exists($db, 'phone_block_daily')) {
              $stmt = $db->prepare("DELETE FROM phone_block_daily WHERE blok_name LIKE :bp");
              $stmt->execute([':bp' => $db_blok_prefix . '%']);
          }

          $db->commit();
      } catch (Exception $e) {
          if ($db->inTransaction()) $db->rollBack();
      }

      $action_message = "Berhasil Hapus Blok $db_blok_prefix. (Router: $router_deleted user, $script_deleted script. DB: Data bersih).";
    }
// ... (Sisa file actions.php) ...

```

---

### FILE 2: `hotspot/user/data.php`

**Perubahan:**

1. **Dropdown Anti-Reset:** Memindahkan logika pembuatan list dropdown (`$list_blok`) ke **ATAS** sebelum filter diterapkan. Ini memastikan meskipun Anda memfilter "Blok A", dropdown tetap berisi opsi lengkap (A, B, C, dst) dan tidak kosong.
2. **Fix Profile Kosong:** Menambahkan logika *fallback*. Jika kolom `profile` kosong, sistem akan menampilkan `profile_kind` (10/30) yang dideteksi dari nama blok/komentar.

```php
<?php
require_once __DIR__ . '/helpers.php';

// ... (Fungsi save_user_history, get_user_history dll biarkan tetap ada) ...

if (isset($_GET['action']) || isset($_POST['action'])) {
  return;
}

// 1. AMBIL DATA DARI ROUTER
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

// 2. PERSIAPAN DATA DB (HISTORY)
// Gabungkan data user yang ada di DB tapi sudah tidak ada di Router (untuk history)
$router_names = [];
foreach($all_users as $u) { $router_names[$u['name']] = true; }

if ($db) {
    try {
        // Ambil data history penting
        $res = $db->query("SELECT username, raw_comment, blok_name, last_status, last_bytes, last_uptime, validity FROM login_history WHERE username IS NOT NULL AND username != ''");
        while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
            $uname = $row['username'];
            if (isset($router_names[$uname])) continue; // Sudah ada di router

            // Tambahkan ke list user untuk ditampilkan
            $all_users[] = [
                'name' => $uname,
                'comment' => $row['raw_comment'],
                'profile' => 'default', // Nanti diperbaiki di bawah
                'disabled' => ($row['last_status'] == 'rusak') ? 'true' : 'false',
                'bytes-in' => $row['last_bytes'],
                'bytes-out' => 0,
                'uptime' => $row['last_uptime'],
                'from_db' => true,
                'db_validity' => $row['validity'], // Simpan validity asli DB
                'db_blok' => $row['blok_name']
            ];
        }
    } catch(Exception $e) {}
}

// 3. BANGUN DROPDOWN BLOK (SEBELUM FILTER!)
// Ini memperbaiki masalah dropdown kosong saat di-filter
$list_blok = [];
if (!$is_ajax) {
    $temp_bloks = [];
    foreach ($all_users as $u) {
        $c = $u['comment'] ?? '';
        // Gunakan extract_blok_name terbaru (A10 -> BLOK-A)
        $bn = extract_blok_name($c); 
        // Fallback ke data DB jika router kosong
        if ($bn === '' && !empty($u['db_blok'])) {
            $bn = extract_blok_name($u['db_blok']);
        }
        
        if ($bn !== '') {
            $temp_bloks[$bn] = true; // Gunakan key array untuk dedup
        }
    }
    $list_blok = array_keys($temp_bloks);
    sort($list_blok, SORT_NATURAL | SORT_FLAG_CASE);
}

// 4. FILTERING & PROCESSING UTAMA
$display_data = [];
$req_prof_clean = trim((string)$req_prof); // 10 atau 30 atau all

foreach($all_users as $u) {
    $name = $u['name'] ?? '';
    $comment = $u['comment'] ?? '';
    
    // --- DETEKSI BLOK ---
    $f_blok = extract_blok_name($comment);
    if ($f_blok === '' && !empty($u['db_blok'])) {
        $f_blok = extract_blok_name($u['db_blok']); // Pastikan format konsisten BLOK-A
    }

    // --- DETEKSI PROFILE (Fix Profile Kosong) ---
    $prof_source = $u['profile'] ?? '';
    $prof_db = $u['db_validity'] ?? '';
    
    // Deteksi jenis profile (10 atau 30) dari berbagai sumber
    $profile_kind = detect_profile_kind_unified($prof_source, $comment, $f_blok, $u['uptime'] ?? '');
    
    // Jika profile kosong/default, paksa tampilkan berdasarkan deteksi
    $display_profile = $prof_source;
    if (empty($display_profile) || strtolower($display_profile) === 'default') {
        if (!empty($prof_db)) {
            $display_profile = $prof_db; // Pakai data validity dari DB
        } elseif ($profile_kind !== 'other') {
            $display_profile = $profile_kind . ' Menit'; // Fallback ke deteksi
        }
    }

    // --- FILTER LOGIC ---
    
    // 1. Filter Blok (Handling spasi vs strip)
    // $req_comm biasanya berisi "BLOK A" (dari dropdown HTML)
    // $f_blok berisi "BLOK-A" (dari helper)
    if ($req_comm != '') {
        $req_comm_clean = str_replace(' ', '', strtoupper($req_comm)); // BLOKA
        $f_blok_clean = str_replace('-', '', strtoupper($f_blok));     // BLOKA
        // Bandingkan versi bersihnya
        if ($req_comm_clean !== $f_blok_clean) continue;
    }

    // 2. Filter Profile
    if ($req_prof_clean !== 'all') {
        // Bandingkan jenis profile (10 vs 10)
        if ($profile_kind != $req_prof_clean) continue;
    }

    // ... (Lanjutkan logika status filter, search, calculation byte/uptime seperti file asli) ...
    // ... (Bagian penentuan $status = ONLINE/READY/RUSAK/RETUR/TERPAKAI) ...
    
    // Masukkan ke display data
    $display_data[] = [
        // ... (data lain) ...
        'profile' => $display_profile, // Gunakan profile yang sudah diperbaiki
        'profile_kind' => $profile_kind,
        'blok' => $f_blok,
        // ...
    ];
}

// ... (Sisa file: Sorting, Pagination, JSON Output) ...

```

**Poin Penting Penerapan:**

1. Dengan update `actions.php`, user "sangkut" akan hilang karena query DB sekarang mencari dengan wildcard (`LIKE`).
2. User "retur" akan terhapus berpasangan (lama & baru) dalam sekali klik.
3. Dengan update `data.php`, saat Anda memilih Blok A -> Profile 10 -> Ready, dropdown blok **tidak akan kosong** karena list blok dibangun dari *semua* data sebelum difilter.
4. Kolom profile di tabel tidak akan kosong lagi karena ada logika *fallback* ke `$profile_kind` (10 Menit / 30 Menit) jika data aslinya kosong.