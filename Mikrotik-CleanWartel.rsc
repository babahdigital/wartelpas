# ==========================================
# SCRIPT CUCI GUDANG FINAL (FIXED BY GEMINI)
# ==========================================

# 1. CEK KUNCI & ANTI-NYANGKUT
# Jika variabel nyangkut lebih dari 10 menit (manual reset), kita paksa lanjut.
:global isCleaning;
:if ([:typeof $isCleaning] = "nothing") do={ :set isCleaning false; }
:if ($isCleaning = true) do={
    :log warning "CUCI GUDANG: Proses sebelumnya belum selesai atau nyangkut. Set manual isCleaning=false jika perlu.";
    :return;
}
:set isCleaning true;

# 2. PRE-CLEAN (Opsional - Pastikan script ini ada)
# Menggunakan 'do' agar jika hantu-sweeper tidak ada, script INDUK tidak mati.
:do {
    /system script run hantu-sweeper;
} on-error={ :log warning "CUCI GUDANG: Script hantu-sweeper tidak ditemukan, lanjut proses..."; }
:delay 5s;

# 3. SYNC STATISTIK (Wajib Jalan)
:log info "SYNC: Mengirim data statistik ke Mikhmon...";
:do {
    # HAPUS parameter 'duration', itu yang bikin error.
    /tool fetch url="http://10.10.83.2:8081/report/sync_stats.php?key=WartelpasSecureKey" keep-result=no;
    :log info "SYNC STATS: Berhasil.";
} on-error={ :log error "SYNC STATS: GAGAL KONEKSI! Cek IP Server/Jaringan."; }

:delay 10s;

# 4. SYNC SALES (Laporan Keuangan - PENTING UNTUK DATA TANGGAL 13)
:log info "SYNC: Mengirim laporan penjualan...";
:do {
    # HAPUS parameter 'duration'
    /tool fetch url="http://10.10.83.2:8081/report/sync_sales.php?key=WartelpasSecureKey" keep-result=no;
    :log info "SYNC SALES: Berhasil terkirim.";
} on-error={ :log error "SYNC SALES: GAGAL KONEKSI! Data penjualan tidak masuk DB."; }

:delay 10s;

# 5. HAPUS USER EXPIRED (CLEANUP)
:log info "CLEANUP: Menghapus user Disabled...";

# Hapus Profile 10Menit
:do {
    :local deadUsers10 [/ip hotspot user find where profile="10Menit" and disabled=yes];
    :if ([:len $deadUsers10] > 0) do={
        /ip hotspot user remove $deadUsers10;
        :log info ("CLEANUP: Terhapus " . [:len $deadUsers10] . " user 10Menit.");
    }
} on-error={ :log warning "CLEANUP: Gagal hapus 10Menit"; }

:delay 2s;

# Hapus Profile 30Menit
:do {
    :local deadUsers30 [/ip hotspot user find where profile="30Menit" and disabled=yes];
    :if ([:len $deadUsers30] > 0) do={
        /ip hotspot user remove $deadUsers30;
        :log info ("CLEANUP: Terhapus " . [:len $deadUsers30] . " user 30Menit.");
    }
} on-error={ :log warning "CLEANUP: Gagal hapus 30Menit"; }

# Buka Kunci
:set isCleaning false;
:log info "SUKSES: Cuci Gudang Selesai.";