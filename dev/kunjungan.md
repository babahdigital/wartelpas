Tentu, Abdul. Berdasarkan preferensi Anda untuk sistem yang efisien, siap pakai, dan mengingat latar belakang Anda dalam pengelolaan jaringan serta aplikasi web (seperti Wartelpas), berikut adalah **Analisa Detail dan Konsep Sistem Informasi Kunjungan (SI-KUNJUNG)** berbasis SQLite dengan integrasi WhatsApp.

Konsep ini dirancang agar ringan (portable), tidak membebani server (karena menggunakan SQLite), namun tetap terlihat profesional dan fungsional.

### 1. Nama Sistem & Filosofi Desain

**Nama:** SI-KUNJUNG (Sistem Informasi Kunjungan Terintegrasi)
**Filosofi:** *Paperless, Real-time, & Self-Service.*
**Target:** Mengurangi antrian fisik, menghilangkan pendataan manual di buku tamu, dan memberikan kepastian jadwal kepada pengunjung melalui notifikasi WhatsApp otomatis.

---

### 2. Arsitektur Sistem (Technical Stack)

Sistem ini dirancang untuk berjalan di lingkungan server lokal (seperti XAMPP/Laragon) atau hosting ringan.

* **Bahasa Pemrograman:** PHP (Native/Vanilla) versi 7.4 atau 8.x. Alasan: Kompatibilitas tinggi, eksekusi cepat, dan mudah diintegrasikan dengan skrip yang sudah Anda miliki sebelumnya.
* **Database:** SQLite 3. Alasan: Single-file database (`.db`), mudah dibackup (tinggal copy file), performa sangat cepat untuk skala ribuan transaksi per bulan, dan tidak memerlukan konfigurasi server MySQL/MariaDB yang berat.
* **Frontend:** Bootstrap 5 (Support Dark Mode sesuai selera Anda) + jQuery (untuk AJAX request agar halaman tidak perlu reload saat input data).
* **Integrasi WhatsApp:** Menggunakan API Gateway (bisa menggunakan layanan pihak ketiga seperti Fonnte, Wablas, atau skrip WA Gateway mandiri yang dijalankan di server lokal via Node.js).
* **Server:** Apache atau Nginx.

---

### 3. Struktur Database SQLite (Skema Detail)

Database akan terdiri dari 3 tabel utama yang saling berelasi. Anda tidak perlu membuat tabel yang tidak digunakan.

#### A. Tabel `pengaturan` (Menyimpan config WA & Sesi)

Tabel ini menyimpan token WhatsApp dan pengaturan jam kunjungan agar bisa diubah tanpa menyentuh kodingan.

```sql
CREATE TABLE pengaturan (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nama_instansi TEXT,
    api_url_wa TEXT,
    api_key_wa TEXT,
    jam_buka TEXT, -- Format: 08:00
    jam_tutup TEXT, -- Format: 15:00
    kuota_harian INTEGER
);

```

#### B. Tabel `data_warga` (Target Kunjungan)

Berisi data orang yang akan dikunjungi (misal: Warga Binaan Pemasyarakatan atau Pegawai).

```sql
CREATE TABLE data_warga (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nama_lengkap TEXT,
    nomor_induk TEXT UNIQUE, -- NIP atau No Registrasi
    lokasi_blok TEXT -- Misal: Blok A, Kamar 12
);

```

#### C. Tabel `kunjungan` (Log Transaksi Utama)

Ini adalah tabel inti yang mencatat reservasi.

```sql
CREATE TABLE kunjungan (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    kode_booking TEXT UNIQUE, -- Format: KUNJ-20231027-001
    nik_pengunjung TEXT,
    nama_pengunjung TEXT,
    no_wa_pengunjung TEXT, -- Format: 08xxx (Auto convert ke 628xxx)
    id_warga_tujuan INTEGER, -- Relasi ke data_warga
    hubungan TEXT, -- Istri, Anak, Saudara, Kuasa Hukum
    tanggal_kunjungan DATE,
    jam_kunjungan TEXT,
    jumlah_pengikut INTEGER, -- Jumlah orang yang ikut
    status TEXT DEFAULT 'PENDING', -- PENDING, DISETUJUI, SELESAI, DITOLAK
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

```

---

### 4. Alur Logika & Integrasi WhatsApp (Flow System)

Berikut adalah detail logika bagaimana data diproses dari input hingga notifikasi WA terkirim.

#### Tahap 1: Registrasi Pengunjung (Front-end User)

1. Pengunjung mengakses web (bisa via scan QR di lokasi atau link publik).
2. Formulir meminta: NIK, Nama, No WA, Pilih Warga yang dikunjungi (Dropdown Search), Tanggal, dan Jumlah Pengikut.
3. **Logika Validasi:**
* Cek apakah `tanggal_kunjungan` masih memiliki kuota (Hitung baris di tabel `kunjungan` where `tanggal` = input).
* Cek apakah Warga yang dituju *boleh* dikunjungi hari itu.


4. **Action:** Simpan ke database dengan status `PENDING`.
5. **Trigger WA 1:** Kirim pesan ke Admin -> *"Ada pendaftaran kunjungan baru dari [Nama Pengunjung]. Mohon verifikasi."*

#### Tahap 2: Verifikasi Admin (Back-end Dashboard)

1. Admin melihat daftar status `PENDING`.
2. Admin menekan tombol **TERIMA** atau **TOLAK**.
3. **Jika DITERIMA:**
* Update status database menjadi `DISETUJUI`.
* Generate `kode_booking` (Contoh: **KJ-A10**).
* **Trigger WA 2 (Ke Pengunjung):**
> "Halo [Nama], Kunjungan Anda disetujui.
> Kode Booking: **KJ-A10**
> Tanggal: [Tanggal]
> Tunjukkan pesan ini kepada petugas saat kedatangan."




4. **Jika DITOLAK:**
* Input alasan penolakan.
* Update status database menjadi `DITOLAK`.
* **Trigger WA 3 (Ke Pengunjung):**
> "Maaf, Kunjungan Anda ditolak.
> Alasan: [Alasan Penolakan]. Silakan daftar untuk tanggal lain."





#### Tahap 3: Check-in (Hari H)

1. Pengunjung datang menunjukkan chat WhatsApp.
2. Petugas input `kode_booking` atau scan QR Code (jika fitur QR diaktifkan).
3. Sistem menampilkan detail pengunjung + foto Warga yang dikunjungi (untuk validasi keamanan).
4. Petugas klik **CHECK-IN / SELESAI**.
5. Status database berubah menjadi `SELESAI`.

---

### 5. Detail Fitur Teknis & Keamanan

Untuk memastikan aplikasi ini optimal dan minim error seperti preferensi Anda:

1. **Proteksi Input (Sanitization):**
Karena menggunakan SQLite, proteksi terhadap *SQL Injection* sangat krusial. Gunakan `SQLite3::prepare` statement untuk setiap input. Jangan gunakan query langsung.
* *Salah:* `query("INSERT INTO... values ('$nama')")`
* *Benar:* `prepare("INSERT INTO... values (:nama)")` lalu bind parameter.


2. **Format Nomor WhatsApp:**
Sistem harus memiliki fungsi *helper* untuk mengubah format nomor HP lokal.
* Input user: `08123456789`
* Output sistem: `628123456789` (Agar API WhatsApp bisa membaca).


3. **Index Database:**
Pada SQLite, tambahkan INDEX pada kolom `tanggal_kunjungan` dan `status`. Ini akan mempercepat proses pencarian data saat Admin memfilter daftar kunjungan, terutama jika data sudah mencapai ribuan baris.
4. **Auto Backup:**
Buat skrip PHP sederhana yang berjalan via Cron Job atau tombol manual di dashboard admin untuk menyalin file `database.sqlite` ke folder `/backup` dengan nama `database_tgl_jam.sqlite`. Ini adalah keunggulan utama SQLite (kemudahan backup).

---

### 6. Mockup Interface Sederhana (Konsep Visual)

* **Halaman Depan (Pengunjung):**
* Logo Instansi (Tengah).
* Card Besar: "Formulir Pendaftaran Kunjungan".
* Input field yang besar dan jelas (ramah layar sentuh HP).
* Tombol "Cek Status Booking" (Bagi yang sudah daftar tapi lupa jadwal).


* **Dashboard Admin:**
* *Sidebar:* Dashboard, Data Warga, Data Kunjungan, Laporan, Setting.
* *Dashboard Utama:* 3 Kotak Info (Total Kunjungan Hari Ini, Menunggu Persetujuan, Total Bulan Ini).
* *Tabel Kunjungan:* Dilengkapi fitur pencarian *live* (tanpa reload page) berdasarkan Nama atau Kode Booking.



### Rekomendasi Langkah Selanjutnya

Apakah Anda ingin saya buatkan **Struktur Folder dan File Database (schema.sql)** beserta **File Koneksi PHP**-nya agar Anda bisa langsung memulai coding dasarnya?