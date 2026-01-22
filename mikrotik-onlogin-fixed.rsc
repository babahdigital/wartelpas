# SCRIPT MIKROTIK ON-LOGIN (FIXED VERSION)
# File: mikrotik-onlogin-fixed.rsc
# Update: 2026-01-19 - PRESERVE BLOK INFO

# Realtime report endpoint (sesuaikan)
:local baseUrl "http://wartelpas.sobigidul.net/report/live_ingest.php";
:local key "WartelpasSecureKey";
:local session "S3c7x9_LB";

{
    :local userId [/ip hotspot user find where name="$user"];
    :local comment "";
    :if ([:len $userId] > 0) do={
        :set comment [/ip hotspot user get $userId comment];
    }
    :local ucode [:pick $comment 0 2];
    :local date [/system clock get date];
    :local time [/system clock get time];
    :local mac $"mac-address";
    :local profile "";
    :if ([:len $userId] > 0) do={
        :set profile [/ip hotspot user get $userId profile];
    }
    
    # EKSTRAK BLOK DARI COMMENT LAMA (SEBELUM DIGANTI)
    :local blokInfo "";
    :local commentLen [:len $comment];
    
    # Cari "Blok-" dalam comment
    :for i from=0 to=($commentLen - 5) do={
        :local substr [:pick $comment $i ($i + 5)];
        :if ($substr = "Blok-" or $substr = "blok-") do={
            # Extract sampai spasi atau akhir string
            :local endPos $i;
            :for j from=$i to=$commentLen do={
                :local char [:pick $comment $j ($j + 1)];
                :if ($char = " " or $char = ")" or $char = "|" or $j = $commentLen) do={
                    :set endPos $j;
                    :set j $commentLen;
                }
            }
            :set blokInfo [:pick $comment $i $endPos];
            :set i $commentLen;
        }
    }
    
    :local schExist [/sys sch find where name="$user"];
    :local hasLog [];
    :if ([:len $hasLog] = 0) do={
        :local date [/system clock get date];
        :local year [:pick $date 7 11];
        :local month [:pick $date 0 3];
        
        /sys sch add name="$user" disable=no start-date=$date interval="1d";
        :delay 5s;
        :local schId [/sys sch find where name="$user"];
        :local exp "";
        :if ([:len $schId] > 0) do={
            :set exp [/sys sch get $schId next-run];
        } else={
            :log warning "SYNC WARN: scheduler not found for $user";
        }
        :local getxp [:len $exp];
        :local newComment "";
        :local existingDate "";
        :if ([:len $comment] > 0) do={
            :local pipePos [:find $comment "|"];
            :if ([:typeof $pipePos] != "nil") do={
                :set existingDate [:pick $comment 0 $pipePos];
            } else={
                :set existingDate $comment;
            }
        }
        
        # BUAT COMMENT BARU DENGAN FORMAT: EXPIRED | BLOK | DATA LAIN
        :if ([:len $existingDate] > 0) do={
            :set newComment $existingDate;
        } else={
            :if ($getxp = 15) do={
                :local d [:pick $exp 0 6];
                :local t [:pick $exp 7 16];
                :local s "/";
                :set newComment ("$d$s$year $t");
            }
            :if ($getxp = 8) do={
                :set newComment ("$date $exp");
            }
            :if ($getxp > 15) do={
                :set newComment $exp;
            }
        }
        
        # PRESERVE BLOK INFO - TAMBAHKAN KE COMMENT BARU
        :if ([:len $blokInfo] > 0) do={
            :set newComment ("$newComment | $blokInfo");
        }
        
        :delay 5s;
        :if ([:len $schId] > 0) do={
            /sys sch remove $schId;
        }
        
        # SAVE KE DATABASE LOG (Format untuk PHP parsing)
        :local price "5000";
        :local validity "1d";
        :local profileLabel "10Menit";
        :if ($profile = "30Menit") do={
            :set price "20000";
            :set profileLabel "30Menit";
        }
        :local logComment ($date . "-|-")
        :set logComment ($logComment . $time . "-|-")
        :set logComment ($logComment . $user . "-|-")
        :set logComment ($logComment . $price . "-|-")
        :set logComment ($logComment . $address . "-|-")
        :set logComment ($logComment . $mac . "-|-")
        :set logComment ($logComment . $validity . "-|-")
        :set logComment ($logComment . $profileLabel . "-|-")
        :set logComment ($logComment . $blokInfo)

        :local exactLog [/system script find where comment="mikhmon" and name="$logComment"];
        :if ([:len $exactLog] = 0) do={
            /system script add name=$logComment owner="$month$year" source="$date" comment="mikhmon";

            # REALTIME REPORT (POST, tanpa url-encode)
            :do {
                /tool fetch url=$baseUrl http-method=post http-data=("data=" . $logComment . "&key=" . $key . "&session=" . $session) keep-result=no;
            } on-error={ :log warning "LIVE_INGEST fetch gagal"; }
        } else={
            :log info ("SKIP_SALE exact duplicate for user=" . $user);
        }

        # SET COMMENT BARU (DENGAN BLOK) - TANPA MENUMPUK
        :if ([:len $userId] > 0) do={
            /ip hotspot user set comment=$newComment $userId;
            # SET MAC ADDRESS
            /ip hotspot user set mac-address=$mac $userId;
        } else={
            :log warning "SYNC WARN: user not found for $user (comment/mac not set)";
        }
    }

    # REALTIME USAGE (LOGIN) - kirim untuk semua login
    :local usageUrl ("http://wartelpas.sobigidul.net/process/usage_ingest.php?key=" . $key . "&session=" . $session . "&event=login" . "&user=" . $user . "&date=" . $date . "&time=" . $time . "&ip=" . $address . "&mac=" . $mac . "&uptime=" . $uptime);
    /tool fetch url=$usageUrl keep-result=no;
}
