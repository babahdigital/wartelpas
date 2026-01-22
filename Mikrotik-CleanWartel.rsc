# ==========================================
# SCRIPT CUCI GUDANG FINAL (FIXED BY GEMINI)
# ==========================================

# 1. CEK KUNCI & ANTI-NYANGKUT
# Jika variabel nyangkut lebih dari 10 menit (manual reset), kita paksa lanjut.
:global isCleaning;
:global cleaningSince;
:if ([:typeof $isCleaning] = "nothing") do={ :set isCleaning false; }
:local nowTime [/system clock get time];
:local nowSec [:totime $nowTime];
:if ([:typeof $cleaningSince] = "nothing") do={ :set cleaningSince $nowSec; }
:if ($isCleaning = true) do={
    :local diff ($nowSec - $cleaningSince);
    :if (($diff < 0) || ($diff > 600)) do={
        :log warning "CUCI GUDANG: Kunci auto-reset (timeout).";
        :set isCleaning false;
    } else={
        :log warning "CUCI GUDANG: Proses sebelumnya belum selesai atau nyangkut. Set manual isCleaning=false jika perlu.";
        :return;
    }
}
:set isCleaning true;
:set cleaningSince $nowSec;

# Helper kirim log ke server realtime
:global urlEncode;
:set urlEncode do={
    :local s $1;
    :if ([:typeof $s] = "nothing") do={ :set s ""; }
    :local out "";
    :local i 0;
    :local len [:len $s];
    :if ($len > 0) do={
        :for i from=0 to=($len - 1) do={
        :local ch [:pick $s $i ($i + 1)];
        :if ($ch = "%") do={ :set out ($out . "%25") } else={
        :if ($ch = " ") do={ :set out ($out . "%20") } else={
        :if ($ch = ":") do={ :set out ($out . "%3A") } else={
        :if ($ch = "/") do={ :set out ($out . "%2F") } else={
        :if ($ch = "&") do={ :set out ($out . "%26") } else={
        :if ($ch = "|") do={ :set out ($out . "%7C") } else={
        :if ($ch = "[") do={ :set out ($out . "%5B") } else={
        :if ($ch = "]") do={ :set out ($out . "%5D") } else={
            :set out ($out . $ch)
        }}}}}}};
        }
    }
    :return $out;
};
:global sendSettleLog;
:set sendSettleLog do={
    :do {
        :local msg $1;
        :local lvl $2;
        :if ([:typeof $msg] = "nothing") do={ :set msg ""; }
        :if ([:typeof $lvl] = "nothing") do={ :set lvl "info"; }
        :if ($msg = "") do={ :return; }
        :local tp ("script," . $lvl);
        :local dt [/system clock get date];
        :local tm [/system clock get time];
        :local esc [$urlEncode $msg];
        :local denc [$urlEncode $dt];
        :local tenc [$urlEncode $tm];
        :local penc [$urlEncode $tp];
        /tool fetch url=("http://wartelpas.sobigidul.net:8081/tools/settlement_log_ingest.php?key=WartelpasSecureKey&session=S3c7x9_LB&date=" . $denc . "&time=" . $tenc . "&topic=" . $penc . "&msg=" . $esc) keep-result=no;
    } on-error={
        :log warning "SETTLE: LOG: Gagal kirim log ke server.";
    }
};
:global logSettle;
:set logSettle do={
    :local lvl $1;
    :local msg $2;
    :if ($lvl = "info") do={ :log info $msg; } else={
        :if ($lvl = "warning") do={ :log warning $msg; } else={ :log error $msg; }
    }
    $sendSettleLog $msg $lvl;
};

:do {
$logSettle "info" "SETTLE: CLEANUP: Mulai proses cuci gudang.";

:global syncStatsOk;
:global syncSalesOk;
:set syncStatsOk true;
:set syncSalesOk true;

# 2. SYNC STATISTIK (Wajib Jalan)
:log info "SETTLE: SYNC: Mengirim data statistik ke Mikhmon...";
$sendSettleLog "SETTLE: SYNC: Mengirim data statistik ke Mikhmon..." "info";
:do {
    # HAPUS parameter 'duration', itu yang bikin error.
    /tool fetch url="http://wartelpas.sobigidul.net:8081/report/sync_stats.php?key=WartelpasSecureKey&session=S3c7x9_LB" keep-result=no;
    $logSettle "info" "SETTLE: SYNC STATS: Berhasil.";
} on-error={ $logSettle "error" "SETTLE: SYNC STATS: GAGAL KONEKSI! Cek IP Server/Jaringan."; :set syncStatsOk false; }

:delay 2s;


# 3. SYNC SALES (Laporan Keuangan - PENTING UNTUK DATA TANGGAL 13)
:log info "SETTLE: SYNC: Mengirim laporan penjualan...";
$sendSettleLog "SETTLE: SYNC: Mengirim laporan penjualan..." "info";
:do {
    # HAPUS parameter 'duration'
    /tool fetch url="http://wartelpas.sobigidul.net:8081/report/sync_sales.php?key=WartelpasSecureKey&session=S3c7x9_LB" keep-result=no;
    $logSettle "info" "SETTLE: SYNC SALES: Berhasil terkirim.";
} on-error={ $logSettle "error" "SETTLE: SYNC SALES: GAGAL KONEKSI! Data penjualan tidak masuk DB."; :set syncSalesOk false; }

:delay 2s;


# Jika sync gagal, batal cleanup agar data MikroTik tidak hilang
:if (($syncStatsOk = false) || ($syncSalesOk = false)) do={
    $logSettle "warning" "SETTLE: CLEANUP: Dibatalkan karena sync gagal. Data MikroTik dipertahankan.";
    :set isCleaning false;
    :return;
}

# 4. SYNC USAGE (Catat login/logout/uptime)
:log info "SETTLE: SYNC: Mengirim data pemakaian (usage) ...";
$sendSettleLog "SETTLE: SYNC: Mengirim data pemakaian (usage) ..." "info";
:do {
    /tool fetch url="http://wartelpas.sobigidul.net:8081/process/sync_usage.php?session=S3c7x9_LB" keep-result=no;
    $logSettle "info" "SETTLE: SYNC USAGE: Berhasil.";
} on-error={ $logSettle "warning" "SETTLE: SYNC USAGE: Gagal koneksi."; }

:delay 2s;


# 4c. CLEAR SERVER LOG (opsional) - Hapus log ingest di server
:log info "SETTLE: MAINT: Clear log ingest server...";
$sendSettleLog "SETTLE: MAINT: Clear log ingest server..." "info";
:do {
    /tool fetch url="http://wartelpas.sobigidul.net:8081/tools/clear_logs.php?key=WartelpasSecureKey&session=S3c7x9_LB" keep-result=no;
    $logSettle "info" "SETTLE: MAINT: Clear log ingest berhasil.";
} on-error={ $logSettle "warning" "SETTLE: MAINT: Gagal clear log ingest."; }

:delay 1s;


# 4b. CLEAR SCRIPT LOG (opsional) - HAPUS SCRIPT MIKHMON TERBENTUK SAAT LOGIN
:log info "SETTLE: MAINT: Hapus script mikhmon...";
$sendSettleLog "SETTLE: MAINT: Hapus script mikhmon..." "info";
:do {
    :local scr [/system script find where comment="mikhmon"];
    :if ([:len $scr] > 0) do={
        /system script remove $scr;
        $logSettle "info" ("SETTLE: MAINT: Script mikhmon terhapus " . [:len $scr] . ".");
    } else={
        $logSettle "info" "SETTLE: MAINT: Tidak ada script mikhmon.";
    }
} on-error={ $logSettle "warning" "SETTLE: MAINT: Gagal hapus script mikhmon."; }

:delay 1s;


# 5. HAPUS USER NON-READY (SUDAH PERNAH TERPAKAI)
$logSettle "info" "SETTLE: CLEANUP: Hapus user terpakai (bytes/uptime/disabled)...";

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
        :if ([:typeof $comm] = "nothing") do={ :set comm ""; }
        :local biVal [:tonum $bi];
        :local boVal [:tonum $bo];
        :if ([:typeof $biVal] = "nil") do={ :set biVal 0; }
        :if ([:typeof $boVal] = "nil") do={ :set boVal 0; }
        :local total ($biVal + $boVal);
        :local isActive [/ip hotspot active find where user=$name];
        :local isDisabled (( $dis = true ) || ( $dis = "true" ) || ( $dis = "yes" ) || ( $dis = "1" ));
        :local upVal $up;
        :local uptimeZero true;
        :if ([:typeof $upVal] = "time") do={
            :if ($upVal > 0s) do={ :set uptimeZero false; }
        } else={
            :if (($upVal != "") && ($upVal != "0s")) do={ :set uptimeZero false; }
        }
        :local hasUsage false;
        :if ($total > 0) do={ :set hasUsage true; }
        :if ($uptimeZero = false) do={ :set hasUsage true; }
        :local isRusakComment (([:find $comm "RUSAK"] >= 0) || ([:find $comm "Rusak"] >= 0) || ([:find $comm "rusak"] >= 0));
        :local isReturComment (([:find $comm "RETUR"] >= 0) || ([:find $comm "Retur"] >= 0) || ([:find $comm "retur"] >= 0));
        :local isMarkedBad ($isDisabled || $isRusakComment || $isReturComment);
        :local isReady (( $isDisabled = false ) && ( $total = 0 ) && $uptimeZero );
        :local commPrefix [:pick $comm 0 3];
        :if ([:len $isActive] > 0) do={
        } else={
            :if ($isMarkedBad) do={
                /ip hotspot user remove $u;
                :set removed ($removed + 1);
            } else={
                :if ($isReady) do={
                } else={
                    :if ($hasUsage) do={
                        /ip hotspot user remove $u;
                        :set removed ($removed + 1);
                    }
                }
            }
        }
    }
    $logSettle "info" ("SETTLE: CLEANUP: Terhapus " . $removed . " user (10/30Menit) terpakai.");
} on-error={ $logSettle "warning" "SETTLE: CLEANUP: Gagal hapus user terpakai."; }

:delay 1s;

# Buka Kunci
:set isCleaning false;
:set cleaningSince 0;
$logSettle "info" "SETTLE: SUKSES: Cuci Gudang Selesai.";
} on-error={
    :log error ("SETTLE: ERROR: " . $message);
    :set isCleaning false;
    :set cleaningSince 0;
}