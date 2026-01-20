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

:global syncStatsOk;
:global syncSalesOk;
:set syncStatsOk true;
:set syncSalesOk true;

# 2. SYNC STATISTIK (Wajib Jalan)
:log info "SYNC: Mengirim data statistik ke Mikhmon...";
:do {
    # HAPUS parameter 'duration', itu yang bikin error.
    /tool fetch url="http://wartelpas.sobigidul.net/report/sync_stats.php?key=WartelpasSecureKey&session=S3c7x9_LB" keep-result=no;
    :log info "SYNC STATS: Berhasil.";
} on-error={ :log error "SYNC STATS: GAGAL KONEKSI! Cek IP Server/Jaringan."; :set syncStatsOk false; }

:delay 10s;

# 3. SYNC SALES (Laporan Keuangan - PENTING UNTUK DATA TANGGAL 13)
:log info "SYNC: Mengirim laporan penjualan...";
:do {
    # HAPUS parameter 'duration'
    /tool fetch url="http://wartelpas.sobigidul.net/report/sync_sales.php?key=WartelpasSecureKey&session=S3c7x9_LB" keep-result=no;
    :log info "SYNC SALES: Berhasil terkirim.";
} on-error={ :log error "SYNC SALES: GAGAL KONEKSI! Data penjualan tidak masuk DB."; :set syncSalesOk false; }

:delay 10s;

# Jika sync gagal, batal cleanup agar data MikroTik tidak hilang
:if (($syncStatsOk = false) || ($syncSalesOk = false)) do={
    :log warning "CLEANUP: Dibatalkan karena sync gagal. Data MikroTik dipertahankan.";
    :set isCleaning false;
    :return;
}

# 4. SYNC USAGE (Catat login/logout/uptime)
:log info "SYNC: Mengirim data pemakaian (usage) ...";
:do {
    /tool fetch url="http://wartelpas.sobigidul.net/process/sync_usage.php?session=S3c7x9_LB" keep-result=no;
    :log info "SYNC USAGE: Berhasil.";
} on-error={ :log warning "SYNC USAGE: Gagal koneksi."; }

:delay 5s;

# 4b. CLEAR SERVER LOGS (optional)
:log info "MAINT: Clear server logs...";
:do {
    /tool fetch url="http://wartelpas.sobigidul.net/report/clear_logs.php?key=WartelpasSecureKey&session=S3c7x9_LB" keep-result=no;
    :log info "MAINT: Clear logs OK.";
} on-error={ :log warning "MAINT: Clear logs gagal."; }

:delay 2s;

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