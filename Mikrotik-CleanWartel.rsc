# ==========================================
# SCRIPT CUCI GUDANG (FIXED BY ABDULLAH)
# ==========================================

# 1. CEK KUNCI & ANTI-NYANGKUT
:global isCleaning;
:global cleaningSince;

# Inisialisasi variabel jika belum ada
:if ([:typeof $isCleaning] = "nothing") do={ :set isCleaning false; }
:local nowTime [/system clock get time];
:local nowSec [:totime $nowTime];
:if ([:typeof $cleaningSince] = "nothing") do={ :set cleaningSince $nowSec; }

# Cek status cleaning sebelumnya
:if ($isCleaning = true) do={
    :local diff ($nowSec - $cleaningSince);
    # Jika nyangkut lebih dari 10 menit (600 detik), reset otomatis
    :if (($diff < 0) || ($diff > 600)) do={
        :log warning "CUCI GUDANG: Kunci auto-reset (timeout).";
        :set isCleaning false;
    } else={
        :log warning "CUCI GUDANG: Proses sebelumnya belum selesai. Set manual isCleaning=false jika perlu.";
        :return;
    }
}

# Kunci proses
:set isCleaning true;
:set cleaningSince $nowSec;

# ==========================================
# FUNGSI ENCODE & LOGGING (OPTIMAL)
# ==========================================
:global urlEncode;
:set urlEncode do={
    # FORCE TO STRING: Kunci perbaikan ada di sini
    :local input [:tostr $1];
    :local out "";
    :local len [:len $input];
    
    :if ($len = 0) do={ :return ""; }
    
    :for i from=0 to=($len - 1) do={
        :local ch [:pick $input $i];
        :local replaced false;
        
        # Encoding karakter spesial URL
        :if ($ch = " ") do={ :set out ($out . "%20"); :set replaced true; }
        :if ($replaced = false) do={ 
            :if ($ch = "%") do={ :set out ($out . "%25"); :set replaced true; }
        }
        :if ($replaced = false) do={ 
            :if ($ch = ":") do={ :set out ($out . "%3A"); :set replaced true; }
        }
        :if ($replaced = false) do={ 
            :if ($ch = "/") do={ :set out ($out . "%2F"); :set replaced true; }
        }
        :if ($replaced = false) do={ 
            :if ($ch = "&") do={ :set out ($out . "%26"); :set replaced true; }
        }
        :if ($replaced = false) do={ 
            :if ($ch = "|") do={ :set out ($out . "%7C"); :set replaced true; }
        }
        :if ($replaced = false) do={ 
            :if ($ch = "[") do={ :set out ($out . "%5B"); :set replaced true; }
        }
        :if ($replaced = false) do={ 
            :if ($ch = "]") do={ :set out ($out . "%5D"); :set replaced true; }
        }
        
        # Karakter biasa, langsung gabung
        :if ($replaced = false) do={ :set out ($out . $ch); }
    }
    :return $out;
};

:global sendSettleLog;
:set sendSettleLog do={
    :global urlEncode;
    :do {
        :local rawMsg [:tostr $1];
        :local rawLvl [:tostr $2];
        
        :if ([:len $rawMsg] = 0) do={ :return; }
        :if ([:len $rawLvl] = 0) do={ :set rawLvl "info"; }

        # Ambil Waktu & Tanggal (Wajib cast tostr)
        :local rawDt [:tostr [/system clock get date]];
        :local rawTm [:tostr [/system clock get time]];
        :local rawTopic ("script," . $rawLvl);

        # Lakukan Encoding
        :local encMsg [$urlEncode $rawMsg];
        :local encDt  [$urlEncode $rawDt];
        :local encTm  [$urlEncode $rawTm];
        :local encTp  [$urlEncode $rawTopic];

        # Fallback safety (jika encoding return kosong, pakai raw walau beresiko)
        :if ([:len $encMsg] = 0) do={ :set encMsg $rawMsg; }
        :if ([:len $encDt] = 0)  do={ :set encDt $rawDt; }
        :if ([:len $encTm] = 0)  do={ :set encTm $rawTm; }
        :if ([:len $encTp] = 0)  do={ :set encTp $rawTopic; }

        # Kirim ke Server
        /tool fetch url=("http://wartelpas.sobigidul.net:8081/tools/settlement_log_ingest.php?key=WartelpasSecureKey&session=S3c7x9_LB&date=" . $encDt . "&time=" . $encTm . "&topic=" . $encTp . "&msg=" . $encMsg) keep-result=no;
        
    } on-error={
        :log warning "SETTLE: LOG: Gagal kirim log ke server (koneksi/url error).";
    }
};

:global logSettle;
:set logSettle do={
    :global sendSettleLog;
    :local lvl [:tostr $1];
    :local msg [:tostr $2];
    
    :if ($lvl = "info") do={ :log info $msg; } else={
        :if ($lvl = "warning") do={ :log warning $msg; } else={ :log error $msg; }
    }
    $sendSettleLog $msg $lvl;
};

# ==========================================
# LOGIKA UTAMA CLEANUP
# ==========================================
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
        /tool fetch url="http://wartelpas.sobigidul.net:8081/report/sync_stats.php?key=WartelpasSecureKey&session=S3c7x9_LB" keep-result=no;
        $logSettle "info" "SETTLE: SYNC STATS: Berhasil.";
    } on-error={ 
        $logSettle "error" "SETTLE: SYNC STATS: GAGAL KONEKSI! Cek IP Server/Jaringan."; 
        :set syncStatsOk false; 
    }

    :delay 2s;

    # 3. SYNC SALES (Laporan Keuangan)
    :log info "SETTLE: SYNC: Mengirim laporan penjualan...";
    $sendSettleLog "SETTLE: SYNC: Mengirim laporan penjualan..." "info";
    :do {
        /tool fetch url="http://wartelpas.sobigidul.net:8081/report/sync_sales.php?key=WartelpasSecureKey&session=S3c7x9_LB" keep-result=no;
        $logSettle "info" "SETTLE: SYNC SALES: Berhasil terkirim.";
    } on-error={ 
        $logSettle "error" "SETTLE: SYNC SALES: GAGAL KONEKSI! Data penjualan tidak masuk DB.";
        :set syncSalesOk false; 
    }

    :delay 2s;

    # Jika sync gagal, batal cleanup agar data tidak hilang
    :if (($syncStatsOk = false) || ($syncSalesOk = false)) do={
        $logSettle "warning" "SETTLE: CLEANUP: Dibatalkan karena sync gagal. Data MikroTik dipertahankan.";
        :set isCleaning false;
        :return;
    }

    # 4. SYNC USAGE
    :log info "SETTLE: SYNC: Mengirim data pemakaian (usage) ...";
    $sendSettleLog "SETTLE: SYNC: Mengirim data pemakaian (usage) ..." "info";
    :do {
        /tool fetch url="http://wartelpas.sobigidul.net:8081/process/sync_usage.php?session=S3c7x9_LB" keep-result=no;
        $logSettle "info" "SETTLE: SYNC USAGE: Berhasil.";
    } on-error={ $logSettle "warning" "SETTLE: SYNC USAGE: Gagal koneksi."; }

    :delay 2s;

    # 4c. CLEAR SERVER LOG (DINONAKTIFKAN - PINDAH SCHEDULER TERPISAH)
    # :log info "SETTLE: MAINT: Clear log ingest server...";
    # :do {
    #    /tool fetch url="http://wartelpas.sobigidul.net:8081/tools/clear_logs.php?key=WartelpasSecureKey&session=S3c7x9_LB&scope=all&purge=1" keep-result=no;
    #    $logSettle "info" "SETTLE: MAINT: Clear log ingest berhasil.";
    # } on-error={ $logSettle "warning" "SETTLE: MAINT: Gagal clear log ingest."; }

    # :delay 1s;

    # 4b. CLEAR SCRIPT LOG (Mikhmon Script)
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

    # 5. HAPUS USER NON-READY
    $logSettle "info" "SETTLE: CLEANUP: Hapus user terpakai (bytes/uptime/disabled)...";
    :local removed 0;
    :local skippedReady 0;

    :do {
        :foreach u in=[/ip hotspot user find where (profile="10Menit" or profile="30Menit")] do={
            :local name [/ip hotspot user get $u name];
            :local bi [:tonum [/ip hotspot user get $u bytes-in]];
            :local bo [:tonum [/ip hotspot user get $u bytes-out]];
            :local up [/ip hotspot user get $u uptime];
            :local dis [/ip hotspot user get $u disabled];
            :local comm [:tostr [/ip hotspot user get $u comment]];

            :if ([:typeof $bi] = "nil") do={ :set bi 0; }
            :if ([:typeof $bo] = "nil") do={ :set bo 0; }
            :local total ($bi + $bo);

            # Cek status Active
            :local isActive [/ip hotspot active find where user=$name];
            :local isDisabled (( $dis = true ) || ( $dis = "true" ) || ( $dis = "yes" ) || ( $dis = "1" ));

            # Cek Uptime
            :local uptimeZero true;
            :if ([:typeof $up] = "time") do={
                :if ($up > 0s) do={ :set uptimeZero false; }
            } else={
                # Handle jika uptime format string
                :if (([:tostr $up] != "") && ([:tostr $up] != "0s")) do={ :set uptimeZero false; }
            }

            :local hasUsage false;
            :if ($total > 0) do={ :set hasUsage true; }
            :if ($uptimeZero = false) do={ :set hasUsage true; }

            # Cek Komentar Rusak/Retur
            :local isRusakComment (([:find $comm "RUSAK"] >= 0) || ([:find $comm "Rusak"] >= 0) || ([:find $comm "rusak"] >= 0));
            :local isReturComment (([:find $comm "RETUR"] >= 0) || ([:find $comm "Retur"] >= 0) || ([:find $comm "retur"] >= 0));
            :local isMarkedBad ($isDisabled || $isRusakComment || $isReturComment);
            
            # Definisi READY: Tidak disable, Tidak ada usage, Uptime 0
            :local isReady (( $isDisabled = false ) && ( $total = 0 ) && $uptimeZero );

            :if ([:len $isActive] > 0) do={
                # Skip active users
            } else={
                :if ($isMarkedBad) do={
                    /ip hotspot user remove $u;
                    :set removed ($removed + 1);
                } else={
                    :if ($isReady) do={
                        :set skippedReady ($skippedReady + 1);
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
        $logSettle "info" ("SETTLE: CLEANUP: Skip READY " . $skippedReady . " user.");
    } on-error={ $logSettle "warning" "SETTLE: CLEANUP: Gagal hapus user terpakai."; }

    :delay 1s;

    # Buka Kunci
    :set isCleaning false;
    :set cleaningSince 0;
    $logSettle "info" "SETTLE: SUKSES: Cuci Gudang Selesai.";

} on-error={
    :global isCleaning;
    :global cleaningSince;
    :log error "SETTLE: ERROR: Terjadi kesalahan fatal pada script utama.";
    :set isCleaning false;
    :set cleaningSince 0;
}