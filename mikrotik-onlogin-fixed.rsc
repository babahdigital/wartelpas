# SCRIPT MIKROTIK ON-LOGIN (FIXED VERSION)
# File: mikrotik-onlogin-fixed.rsc
# Update: 2026-01-19 - PRESERVE BLOK INFO

# Realtime report endpoint (sesuaikan)
:local baseUrl "http://wartelpas.sobigidul.net/report/live_ingest.php";
:local key "WartelpasSecureKey";
:local session "S3c7x9_LB";

:put (",remc,5000,1d,5000,,Enable,");

{
    :local comment [/ip hotspot user get [/ip hotspot user find where name="$user"] comment];
    :local ucode [:pick $comment 0 2];
    
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
    
    :if ($ucode = "vc" or $ucode = "up" or $comment = "") do={
        :local date [/system clock get date];
        :local year [:pick $date 7 11];
        :local month [:pick $date 0 3];
        
        /sys sch add name="$user" disable=no start-date=$date interval="1d";
        :delay 5s;
        
        :local exp [/sys sch get [/sys sch find where name="$user"] next-run];
        :local getxp [:len $exp];
        :local newComment "";
        
        # BUAT COMMENT BARU DENGAN FORMAT: EXPIRED | BLOK | DATA LAIN
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
        
        # PRESERVE BLOK INFO - TAMBAHKAN KE COMMENT BARU
        :if ([:len $blokInfo] > 0) do={
            :set newComment ("$newComment | $blokInfo");
        }
        
        :delay 5s;
        /sys sch remove [find where name="$user"];
        
        :local mac $"mac-address";
        :local time [/system clock get time];
        
        # SAVE KE DATABASE LOG (Format untuk PHP parsing)
        :local logComment "$date-|-$time-|-$user-|-5000-|-$address-|-$mac-|-1d-|-10Menit-|-$blokInfo";
        /system script add name=$logComment owner="$month$year" source="$date" comment="mikhmon";

        # REALTIME REPORT (URL-ENCODED)
        :local payload $logComment;
        :set payload [:replace $payload " " "%20"];
        :set payload [:replace $payload "|" "%7C"];
        :set payload [:replace $payload "/" "%2F"];
        :set payload [:replace $payload ":" "%3A"];
        :local url ($baseUrl . "?key=" . $key . "&session=" . $session . "&data=" . $payload);
        /tool fetch url=$url mode=http keep-result=no;
        
        # SET COMMENT BARU (DENGAN BLOK)
        /ip hotspot user set comment=$newComment [find where name=$user];
        
        # SET MAC ADDRESS
        /ip hotspot user set mac-address=$mac [find where name=$user];
    }
}
