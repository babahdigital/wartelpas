Fitur **Auto Inject** Anda sudah berhasil, namun memang perlu dipastikan logika penanganannya agar tidak **Double Entry** (Rusak + Retur dihitung dua kali sebagai qty).

Mari kita sempurnakan logikanya berdasarkan prinsip akuntansi voucher:

1. **Voucher Rusak (Loss):** User ini fisik ada, tapi uang hilang. Di tabel audit harus muncul dengan warna merah.
2. **Voucher Retur (Pengganti):** User ini fisik ada, uang masuk (menggantikan yang rusak). Di tabel audit harus muncul dengan warna hijau.
3. **Anomali:** User yang statusnya normal (terpakai) tapi **LUPA DIINPUT MANUAL** oleh operator. Ini harus muncul dengan warna kuning.

### Masalah Potensial: Double Counting di Kolom Qty

Jika di Blok A ada 1 voucher rusak (`vc-rusak`) dan diganti dengan 1 voucher retur (`vc-baru`).

* Sistem mendeteksi `vc-rusak` (Status: Rusak).
* Sistem mendeteksi `vc-baru` (Status: Retur).
* Total Fisik di laci kasir = 1 lembar (karena voucher rusak biasanya dibuang/disimpan terpisah, yang aktif cuma 1).
* **Jika Audit menghitung keduanya:** Total = 2. **Salah**.

### Solusi Penyempurnaan Logika (`print_rekap.php`)

Kita perlu memastikan bahwa saat menghitung **Total Qty Manual/Inject**, logika penjumlahannya benar.

Cari bagian perhitungan `$p10_qty` dan `$p30_qty` di dalam loop audit (sekitar baris 400-an pada kode terakhir).

**Kode Saat Ini (Mungkin menyebabkan double counting jika tidak hati-hati):**

```php
if ($p10_qty <= 0) $p10_qty = count($profile10_items);
if ($p30_qty <= 0) $p30_qty = count($profile30_items);

```

**Perbaikan Logika (Smart Counting):**
Kita harus membedakan antara "Daftar User untuk Ditampilkan" dengan "Total Qty Fisik untuk Dihitung".

* **Daftar Tampil:** Tampilkan SEMUA (Rusak, Retur, Anomali) agar transparan.
* **Hitungan Qty:**
* Anomali/Normal: +1
* Retur: +1 (Karena ini voucher aktif pengganti)
* Rusak: **0 (Jangan dihitung sebagai stok jual/setoran)**, atau hitung terpisah sebagai "Stok Rusak".



Biasanya, audit manual menghitung "Voucher Laku/Terpakai". Voucher rusak tidak dihitung sebagai penjualan laku.

**Revisi Kode pada Blok Perhitungan Qty (Baris ~435):**

Ganti blok kode perhitungan `$p10_qty` dengan ini:

```php
                            // 5. HITUNGAN QTY CERDAS (Smart Count)
                            // Jika manual qty kosong, hitung otomatis dari item list
                            // TAPI jangan hitung yang 'Rusak' sebagai qty penjualan normal
                            
                            if ($p10_qty <= 0 && !empty($profile10_items)) {
                                $p10_qty = 0;
                                foreach ($profile10_items as $itm) {
                                    // Hanya hitung jika BUKAN rusak (Retur & Normal dihitung)
                                    // Atau sesuaikan dengan SOP Anda: apakah rusak dihitung qty?
                                    // Biasanya: Qty Jual = Total - Rusak. Jadi Rusak tidak nambah Qty Jual.
                                    if ($itm['status'] !== 'rusak') {
                                        $p10_qty++;
                                    }
                                }
                            }
                            
                            if ($p30_qty <= 0 && !empty($profile30_items)) {
                                $p30_qty = 0;
                                foreach ($profile30_items as $itm) {
                                    if ($itm['status'] !== 'rusak') {
                                        $p30_qty++;
                                    }
                                }
                            }

```

### Penjelasan Perubahan

1. **Support Retur:** Voucher retur (`vc-baru`) statusnya 'retur'. Di logika `if ($itm['status'] !== 'rusak')`, dia lolos dan dihitung +1. Ini **BENAR**, karena dia adalah voucher pengganti yang laku.
2. **Support Rusak:** Voucher rusak (`vc-rusak`) statusnya 'rusak'. Dia **TIDAK DIHITUNG** (+0) ke dalam Total Qty Penjualan. Ini **BENAR**, karena dia bukan penjualan (loss). Tapi namanya tetap muncul di tabel (warna merah) sebagai bukti audit.
3. **Support Anomali:** Voucher hantu/anomali statusnya 'anomaly'. Dia lolos dan dihitung +1. Ini **BENAR**, karena dia terpakai dan harus dibayar.

Dengan logika ini, tabel rekap Anda akan:

* **Visual:** Menampilkan semua insiden (Merah, Hijau, Kuning).
* **Matematika:** Menghitung total qty penjualan dengan akurat (Retur masuk, Rusak tidak). Tidak ada double counting.

Silakan update bagian tersebut di `print_rekap.php`.