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

:log info "SETTLE: CLEANUP: Mulai proses cuci gudang.";

:global syncStatsOk;
:global syncSalesOk;
:set syncStatsOk true;
:set syncSalesOk true;

# 2. SYNC STATISTIK (Wajib Jalan)
:log info "SETTLE: SYNC: Mengirim data statistik ke Mikhmon...";
:do {
    # HAPUS parameter 'duration', itu yang bikin error.
    /tool fetch url="http://wartelpas.sobigidul.net:8081/report/sync_stats.php?key=WartelpasSecureKey&session=S3c7x9_LB" keep-result=no;
    :log info "SETTLE: SYNC STATS: Berhasil.";
} on-error={ :log error "SETTLE: SYNC STATS: GAGAL KONEKSI! Cek IP Server/Jaringan."; :set syncStatsOk false; }

:delay 3s;


# 3. SYNC SALES (Laporan Keuangan - PENTING UNTUK DATA TANGGAL 13)
:log info "SETTLE: SYNC: Mengirim laporan penjualan...";
:do {
    # HAPUS parameter 'duration'
    /tool fetch url="http://wartelpas.sobigidul.net:8081/report/sync_sales.php?key=WartelpasSecureKey&session=S3c7x9_LB" keep-result=no;
    :log info "SETTLE: SYNC SALES: Berhasil terkirim.";
} on-error={ :log error "SETTLE: SYNC SALES: GAGAL KONEKSI! Data penjualan tidak masuk DB."; :set syncSalesOk false; }

:delay 3s;


# Jika sync gagal, batal cleanup agar data MikroTik tidak hilang
:if (($syncStatsOk = false) || ($syncSalesOk = false)) do={
    :log warning "SETTLE: CLEANUP: Dibatalkan karena sync gagal. Data MikroTik dipertahankan.";
    :set isCleaning false;
    :return;
}

# 4. SYNC USAGE (Catat login/logout/uptime)
:log info "SETTLE: SYNC: Mengirim data pemakaian (usage) ...";
:do {
    /tool fetch url="http://wartelpas.sobigidul.net:8081/process/sync_usage.php?session=S3c7x9_LB" keep-result=no;
    :log info "SETTLE: SYNC USAGE: Berhasil.";
} on-error={ :log warning "SETTLE: SYNC USAGE: Gagal koneksi."; }

:delay 3s;


# 4b. CLEAR SCRIPT LOG (opsional) - HAPUS SCRIPT MIKHMON TERBENTUK SAAT LOGIN
:log info "SETTLE: MAINT: Hapus script mikhmon...";
:do {
    :local scr [/system script find where comment="mikhmon"];
    :if ([:len $scr] > 0) do={
        /system script remove $scr;
        :log info ("SETTLE: MAINT: Script mikhmon terhapus " . [:len $scr] . ".");
    } else={
        :log info "SETTLE: MAINT: Tidak ada script mikhmon.";
    }
} on-error={ :log warning "SETTLE: MAINT: Gagal hapus script mikhmon."; }

:delay 2s;


# 5. HAPUS USER NON-READY (SUDAH PERNAH TERPAKAI)
:log info "SETTLE: CLEANUP: Hapus user terpakai (bytes/uptime/disabled)...";

# Hapus Profile 10Menit & 30Menit (hanya yang sudah terpakai)
:do {
    :local removed 0;
    :foreach u in=[/ip hotspot user find where (profile="10Menit" or profile="30Menit")] do={
        :local name [/ip hotspot user get $u name];
        :local bi [/ip hotspot user get $u bytes-in];
        :local bo [/ip hotspot user get $u bytes-out];
        :local up [/ip hotspot user get $u uptime];
        :local dis [/ip hotspot user get $u disabled];
        :local comm [/ip hotspot user get $u comment];
        :local biVal [:tonum $bi];
        :local boVal [:tonum $bo];
        :local total ($biVal + $boVal);
        :local isActive [/ip hotspot active find where user=$name];
        :local isDisabled (( $dis = true ) || ( $dis = "true" ));
        :local hasUsage (( $total > 0 ) || ( $up != "0s" && $up != "" ));
        :local isReady (( $isDisabled = false ) && ( $total = 0 ) && ( ($up = "0s") || ($up = "") ));
        :local commLower [:tolower $comm];
        :local isVcPrefix ([:find $commLower "vc-"] = 0);
        :local isVcReady ($isVcPrefix && ( $isDisabled = false ) && ( $total = 0 ) && ( ($up = "0s") || ($up = "") ));
        :if ([:len $isActive] > 0) do={
            :log info ("SETTLE: CLEANUP: Skip online user " . $name . ".");
        } else={
            :if ($isReady || $isVcReady) do={
                :log info ("SETTLE: CLEANUP: Skip READY user " . $name . ".");
            } else={
                :if ($isDisabled || $hasUsage) do={
                    /ip hotspot user remove $u;
                    :set removed ($removed + 1);
                }
            }
        }
    }
    :log info ("SETTLE: CLEANUP: Terhapus " . $removed . " user (10/30Menit) terpakai.");
} on-error={ :log warning "SETTLE: CLEANUP: Gagal hapus user terpakai."; }

:delay 2s;

# Buka Kunci
:set isCleaning false;
:log info "SETTLE: SUKSES: Cuci Gudang Selesai.";