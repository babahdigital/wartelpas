# SCRIPT MIKROTIK ON-LOGIN (AUDITED & FIXED)
# File: mikrotik-onlogin-fixed.rsc
# Logic: 1 User = 1 Transaction. Backup First, then Fetch.

# KONFIGURASI
:local baseUrl "http://wartelpas.sobigidul.net/report/live_ingest.php";
:local key "WartelpasSecureKey";
:local session "S3c7x9_LB";

:put (",remc,20000,1d,5000,,Enable,");

{
    :local username "$user";
    :local address "$address";
    :local mac $"mac-address";
    :local date [/system clock get date];
    :local time [/system clock get time];
    :local month [:pick $date 0 3];
    :local year [:pick $date 7 11];

    :local uId [/ip hotspot user find where name="$username"];
    :local comment "";
    :if ([:len $uId] > 0) do={ :set comment [/ip hotspot user get $uId comment]; }

    :local ucode [:pick $comment 0 2];

    :local schId [/system scheduler find where name="$username"];
    :local isNewLogin false;
    :if (($ucode = "vc" or $ucode = "up" or $comment = "") and ([:len $schId] = 0)) do={
        :set isNewLogin true;
    }

    :if ($isNewLogin = true) do={
        :local blokInfo "";
        :local posBlok [:find $comment "Blok-"];
        :if ([:typeof $posBlok] = "nil") do={ :set posBlok [:find $comment "blok-"]; }
        :if ([:typeof $posBlok] != "nil") do={
            :set blokInfo [:pick $comment $posBlok ($posBlok + 8)];
            :local posSpasi [:find $blokInfo " "];
            :if ([:typeof $posSpasi] != "nil") do={ :set blokInfo [:pick $blokInfo 0 $posSpasi]; }
        }

        /system scheduler add name="$username" disable=no start-date=$date interval="1d";

        :delay 2s;
        :local schInfo [/system scheduler find where name="$username"];
        :local expTime "";
        :if ([:len $schInfo] > 0) do={ :set expTime [/system scheduler get $schInfo next-run]; }

        :local newComment "$date $time";
        :if ([:len $expTime] > 0) do={ :set newComment "$date $expTime"; }
        :if ([:len $blokInfo] > 0) do={ :set newComment ("$newComment | $blokInfo"); }

        :if ([:len $uId] > 0) do={
            /ip hotspot user set comment=$newComment $uId;
            /ip hotspot user set mac-address=$mac $uId;
        }

        :local price "5000";
        :local rawData "$date-|-$time-|-$username-|-$price-|-$address-|-$mac-|-1d-|-10Menit-|-$newComment";
        /system script add name=$rawData owner="$month$year" source="$date" comment="mikhmon";

        :local payload ("data=" . [:url-encode $rawData] . "&key=" . $key . "&session=" . $session);
        :do {
            /tool fetch url=$baseUrl http-method=post http-data=$payload keep-result=no;
        } on-error={
            :log warning "Ingest Live Gagal (Server Busy). Data aman di Script Backup.";
        }
    }

    :local usageUrl ("http://wartelpas.sobigidul.net/process/usage_ingest.php?key=" . $key . "&session=" . $session . "&event=login" . "&user=" . $username . "&date=" . $date . "&time=" . $time . "&ip=" . $address . "&mac=" . $mac);
    :do { /tool fetch url=$usageUrl keep-result=no; } on-error={ :log debug "Usage log failed"; }
}
