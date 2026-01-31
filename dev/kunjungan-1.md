Tentu, Pak Abdul. Ini adalah langkah penting untuk memvisualisasikan bagaimana sistem ini akan bekerja sebelum masuk ke koding PHP backend.

Untuk menjawab pertanyaan Anda: **Apakah halaman admin diperlukan? SANGAT PERLU.**

**Alasannya:**
Karena konsep kita ada fitur "Persetujuan (Approval)" sebelum mengirim pesan WhatsApp ke pengunjung, maka harus ada halaman bagi petugas/admin untuk memverifikasi data yang masuk. Tanpa halaman admin, siapa yang akan mengubah status dari "PENDING" menjadi "DISETUJUI" atau "DITOLAK"?

Berikut adalah rincian apa saja yang ditampilkan pada masing-masing halaman dan simulasi HTML-nya menggunakan **Bootstrap 5** (agar tampilan langsung rapi dan responsif/mobile-friendly).

---

### Rincian Tampilan Data

#### 1. Halaman Users (Pengunjung / Publik)

Halaman ini fokus pada input data yang cepat dan mudah, terutama di HP.

**Yang Ditampilkan:**

* **Header:** Logo dan Nama Instansi yang jelas.
* **Formulir Registrasi:** Field input yang wajib diisi (NIK, Nama, WA, Tujuan Kunjungan, Tanggal).
* **Validasi Sederhana:** Informasi jika kuota hari tersebut penuh (nanti ditangani PHP).
* **Tombol Cek Status:** Bagi pengunjung yang sudah mendaftar dan ingin melihat apakah statusnya sudah disetujui tanpa menunggu WA.

#### 2. Halaman Admin (Dashboard & Manajemen)

Halaman ini padat informasi untuk pengambilan keputusan cepat oleh petugas.

**Yang Ditampilkan di Dashboard Utama:**

* **Widget Statistik Ringkas:**
* Jumlah booking yang statusnya **"PENDING/Menunggu Verifikasi"** (Ini paling penting agar segera diproses).
* Jumlah pengunjung yang dijadwalkan datang **"HARI INI"**.
* Total pengunjung yang sudah **"SELESAI"** (Check-in) hari ini.



**Yang Ditampilkan di Tabel Data Kunjungan (Halaman Operasional):**

* **Kode Booking & Tanggal:** Identitas utama booking.
* **Data Pengunjung:** Nama lengkap dan Nomor WA (digabung dalam satu kolom agar hemat tempat).
* **Tujuan Kunjungan:** Nama warga yang akan dikunjungi dan lokasinya (Blok/Kamar).
* **Status:** Badge warna-warni (Kuning=Pending, Hijau=Disetujui, Merah=Ditolak, Biru=Selesai).
* **Tombol Aksi (Action):** Tombol "Terima (✓)" dan "Tolak (✗)" untuk status Pending. Tombol "Check-in" untuk status Disetujui di hari H.

---

### SIMULASI HTML

Silakan simpan kode di bawah ini menjadi file `.html` dan buka di browser (Chrome/Firefox) untuk melihat hasilnya. Saya menggunakan CDN Bootstrap agar Anda tidak perlu mendownload file CSS/JS nya.

#### A. Simulasi Halaman Depan Pengunjung (`index.html`)

Ini tampilan yang dilihat masyarakat saat mendaftar lewat HP.

```html
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SI-KUNJUNG - Registrasi Online</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f6f9; }
        .card-registrasi { 
            border: none; 
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            border-top: 4px solid #0d6efd; /* Warna biru sebagai aksen */
        }
        .form-label { font-weight: 600; }
    </style>
</head>
<body>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="text-center mb-4">
                <img src="https://via.placeholder.com/80x80?text=LOGO" alt="Logo Instansi" class="mb-3 rounded-circle">
                <h4 class="fw-bold">SI-KUNJUNG APP</h4>
                <p class="text-muted">Formulir Pendaftaran Kunjungan Online</p>
            </div>

            <div class="card card-registrasi">
                <div class="card-body p-4">
                    <form action="#" method="POST"> <div class="mb-3">
                            <label class="form-label">NIK Pengunjung</label>
                            <input type="number" class="form-control form-control-lg" placeholder="Contoh: 637201xxxxxx" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Nama Lengkap (Sesuai KTP)</label>
                            <input type="text" class="form-control form-control-lg" placeholder="Nama Anda" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-primary fw-bold">Nomor WhatsApp Aktif</label>
                            <small class="d-block text-muted mb-1">Penting! Notifikasi kode booking dikirim ke sini.</small>
                            <input type="tel" class="form-control form-control-lg border-primary" placeholder="Contoh: 0812xxxxxxx" required>
                        </div>

                        <hr class="my-4">
                        <h6 class="fw-bold mb-3">Detail Kunjungan</h6>

                        <div class="mb-3">
                            <label class="form-label">Siapa yang dikunjungi?</label>
                            <select class="form-select form-select-lg" required>
                                <option value="" selected disabled>-- Pilih Nama Warga/Pegawai --</option>
                                <option value="1">Budi Santoso (Blok A - Kamar 12)</option>
                                <option value="2">Ahmad Junaedi (Blok B - Kamar 05)</option>
                                <option value="3">Siti Aminah (Staff Tata Usaha)</option>
                            </select>
                        </div>

                        <div class="row">
                            <div class="col-md-7 mb-3">
                                <label class="form-label">Tanggal Kunjungan</label>
                                <input type="date" class="form-control" required>
                            </div>
                            <div class="col-md-5 mb-3">
                                <label class="form-label">Jml Pengikut</label>
                                <input type="number" class="form-control" min="0" value="0">
                            </div>
                        </div>
                        
                        <div class="d-grid gap-2 mt-4">
                            <button type="button" class="btn btn-primary btn-lg fw-bold">KIRIM PENDAFTARAN</button>
                        </div>
                    </form>
                </div>
            </div>
            
            <div class="text-center mt-4">
                <a href="#" class="text-decoration-none">Sudah mendaftar? Cek Status Booking di sini.</a>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

```

#### B. Simulasi Halaman Admin Dashboard (`admin.html`)

Ini tampilan yang dilihat oleh petugas di PC/Laptop untuk memproses data.

```html
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - SI-KUNJUNG</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f0f2f5; }
        .navbar-brand { font-weight: bold; letter-spacing: 1px;}
        .card-stat { border: none; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .icon-stat { font-size: 2.5rem; opacity: 0.3; }
        .table-hover tbody tr:hover { background-color: #f8f9fa; }
        .btn-action { margin-right: 5px; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark py-3">
  <div class="container-fluid px-4">
    <a class="navbar-brand" href="#"><i class="fas fa-building-shield me-2"></i> ADMIN SI-KUNJUNG</a>
    <div class="d-flex">
        <span class="text-white me-3">Halo, Petugas Jaga</span>
        <a href="#" class="btn btn-outline-light btn-sm">Logout</a>
    </div>
  </div>
</nav>

<div class="container-fluid px-4 mt-4">
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card card-stat bg-warning text-dark h-100">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="card-title mb-1">Menunggu Verifikasi</h6>
                        <h2 class="fw-bold mb-0">3 Data</h2>
                        <small>Perlu tindakan segera!</small>
                    </div>
                    <i class="fas fa-clock icon-stat"></i>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-stat bg-primary text-white h-100">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="card-title mb-1">Jadwal Hari Ini</h6>
                        <h2 class="fw-bold mb-0">15 Orang</h2>
                        <small>Total yang akan datang</small>
                    </div>
                     <i class="fas fa-calendar-day icon-stat"></i>
                </div>
            </div>
        </div>
         <div class="col-md-4">
            <div class="card card-stat bg-success text-white h-100">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="card-title mb-1">Sudah Check-in/Selesai</h6>
                        <h2 class="fw-bold mb-0">8 Orang</h2>
                        <small>Hari ini</small>
                    </div>
                    <i class="fas fa-user-check icon-stat"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="card card-stat shadow-sm mb-5">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold"><i class="fas fa-list me-2"></i> Data Kunjungan Terbaru</h5>
            <div>
                <input type="text" class="form-control form-control-sm" placeholder="Cari Nama/Kode Booking...">
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Tanggal & Kode</th>
                            <th>Data Pengunjung (WA)</th>
                            <th>Tujuan Kunjungan</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Aksi Petugas</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="table-warning-subtle">
                            <td>
                                <div class="fw-bold">28 Okt 2023</div>
                                <small class="text-muted">- (Belum ada kode)</small>
                            </td>
                            <td>
                                <div class="fw-bold">Rina Kurniawati</div>
                                <small class="text-primary"><i class="fab fa-whatsapp"></i> 085212345678</small>
                            </td>
                            <td>
                                <div>Ahmad Junaedi</div>
                                <small class="text-muted">Blok B - Kamar 05</small>
                            </td>
                            <td class="text-center"><span class="badge bg-warning text-dark">PENDING</span></td>
                            <td class="text-center">
                                <button class="btn btn-success btn-sm btn-action" title="Terima & Kirim WA"><i class="fas fa-check"></i> Terima</button>
                                <button class="btn btn-danger btn-sm btn-action" title="Tolak"><i class="fas fa-times"></i> Tolak</button>
                            </td>
                        </tr>

                        <tr>
                            <td>
                                <div class="fw-bold">27 Okt 2023</div>
                                <span class="badge bg-secondary">KJ-A105</span>
                            </td>
                            <td>
                                <div class="fw-bold">Bambang Pamungkas</div>
                                <small class="text-primary"><i class="fab fa-whatsapp"></i> 081298765432</small>
                            </td>
                            <td>
                                <div>Budi Santoso</div>
                                <small class="text-muted">Blok A - Kamar 12</small>
                            </td>
                            <td class="text-center"><span class="badge bg-info text-dark">DISETUJUI</span></td>
                            <td class="text-center">
                                <button class="btn btn-primary btn-sm btn-action w-100"><i class="fas fa-qrcode me-1"></i> Proses Check-in</button>
                            </td>
                        </tr>

                        <tr>
                            <td>
                                <div class="fw-bold">27 Okt 2023</div>
                                <span class="badge bg-secondary">KJ-A101</span>
                            </td>
                            <td>
                                <div class="fw-bold">Dewi Sartika</div>
                                <small class="text-primary"><i class="fab fa-whatsapp"></i> 081912341234</small>
                            </td>
                            <td>
                                <div>Siti Aminah</div>
                                <small class="text-muted">Staff TU</small>
                            </td>
                            <td class="text-center"><span class="badge bg-success">SELESAI</span></td>
                            <td class="text-center">
                                <button class="btn btn-outline-secondary btn-sm btn-action" title="Lihat Detail"><i class="fas fa-eye"></i> Detail</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

```

### Langkah Selanjutnya untuk Anda

Simulasi HTML di atas sudah sangat siap untuk "dihidupkan" dengan PHP dan SQLite.

1. Di Halaman Depan (`index.html`), tag `<form>` nanti akan kita beri `method="POST"` dan action ke file PHP yang menyimpan data ke SQLite.
2. Di Halaman Admin (`admin.html`), bagian `<tbody>...</tbody>` (isi tabel) akan kita ganti dengan perulangan PHP (`while($row = $result->fetchArray())`) yang mengambil data real-time dari file `database.sqlite` Anda.
3. Tombol "Terima" di admin nanti akan menjadi link seperti `proses_terima.php?id=1`, yang fungsinya update status DB dan memanggil API WhatsApp.

Semoga visualisasi ini membantu memperjelas konsepnya, Pak Abdul!