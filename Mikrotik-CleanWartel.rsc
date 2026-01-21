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
    /tool fetch url="http://wartelpas.sobigidul.net:8081/report/sync_stats.php?key=WartelpasSecureKey&session=S3c7x9_LB" keep-result=no;
    :log info "SYNC STATS: Berhasil.";
} on-error={ :log error "SYNC STATS: GAGAL KONEKSI! Cek IP Server/Jaringan."; :set syncStatsOk false; }

:delay 10s;

# 3. SYNC SALES (Laporan Keuangan - PENTING UNTUK DATA TANGGAL 13)
:log info "SYNC: Mengirim laporan penjualan...";
:do {
    # HAPUS parameter 'duration'
    /tool fetch url="http://wartelpas.sobigidul.net:8081/report/sync_sales.php?key=WartelpasSecureKey&session=S3c7x9_LB" keep-result=no;
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
    /tool fetch url="http://wartelpas.sobigidul.net:8081/process/sync_usage.php?session=S3c7x9_LB" keep-result=no;
    :log info "SYNC USAGE: Berhasil.";
} on-error={ :log warning "SYNC USAGE: Gagal koneksi."; }

:delay 5s;

# 4b. CLEAR SCRIPT LOG (opsional) - HAPUS SCRIPT MIKHMON TERBENTUK SAAT LOGIN
:log info "MAINT: Hapus script mikhmon...";
:do {
    :local scr [/system script find where comment="mikhmon"];
    :if ([:len $scr] > 0) do={
        /system script remove $scr;
        :log info ("MAINT: Script mikhmon terhapus " . [:len $scr] . ".");
    } else={
        :log info "MAINT: Tidak ada script mikhmon.";
    }
} on-error={ :log warning "MAINT: Gagal hapus script mikhmon."; }

:delay 2s;

# 5. HAPUS USER NON-READY (SUDAH PERNAH TERPAKAI)
:log info "CLEANUP: Hapus user terpakai (bytes/uptime/disabled)...";

# Hapus Profile 10Menit & 30Menit (hanya yang sudah terpakai)
:do {
    :local removed 0;
    :foreach u in=[/ip hotspot user find where (profile="10Menit" or profile="30Menit")] do={
        :local bi [/ip hotspot user get $u bytes-in];
        :local bo [/ip hotspot user get $u bytes-out];
        :local up [/ip hotspot user get $u uptime];
        :local dis [/ip hotspot user get $u disabled];
        :local total ($bi + $bo);
        :if (($dis = true) || ($total > 0) || ($up != "0s" && $up != "")) do={
            /ip hotspot user remove $u;
            :set removed ($removed + 1);
        }
    }
    :log info ("CLEANUP: Terhapus " . $removed . " user (10/30Menit) terpakai.");
} on-error={ :log warning "CLEANUP: Gagal hapus user terpakai."; }

# Buka Kunci
:set isCleaning false;
:log info "SUKSES: Cuci Gudang Selesai.";