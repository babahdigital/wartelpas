# SCRIPT MIKROTIK ON-LOGIN
# Versi kompatibel API: tanpa karakter sama-dengan pada source script

:local baseUrl "{{BASE_URL}}";
:local localBaseUrl "{{LOCAL_BASE_URL}}";
:local key "{{LIVE_KEY}}";
:local session "{{SESSION}}";
:local usageKey "{{USAGE_KEY}}";
:global wartelpasprice;
:global wartelpasplabel;

	:local username $user;
	:local userip $address;
	:local usermac "-";
	:local date [/system clock get date];
	:local time [/system clock get time];

	:if ([:len $username] > 0) do {
		:local price10 "{{PRICE_10}}";
		:local price30 "{{PRICE_30}}";
		:local profile10 "{{PROFILE_10}}";
		:local profile30 "{{PROFILE_30}}";
		:local price $price10;
		:local pLabel $profile10;
		:if ([:len "$wartelpasplabel"] > 0) do { :set pLabel "$wartelpasplabel"; };
		:if ([:len "$wartelpasprice"] > 0) do { :set price "$wartelpasprice"; } else {
			:if ($pLabel = $profile30) do { :set price $price30; } else { :set price $price10; };
		};
		:local blokInfo "-";
		:local logTxt ($date . "-|-" . $time . "-|-" . $username . "-|-" . $price . "-|-" . $userip . "-|-" . $usermac . "-|-1d-|-" . $pLabel . "-|-" . $blokInfo);

		:local userKey $username;
		:if ([:len $userKey] > 12) do { :set userKey [:pick $userKey 0 12]; };
		:local dateStamp "0000";
		:if ([:len $date] > 10) do { :set dateStamp ([:pick $date 4 6] . [:pick $date 7 11]); };
		:local timeStamp "000000";
		:if ([:len $time] > 7) do { :set timeStamp ([:pick $time 0 2] . [:pick $time 3 5] . [:pick $time 6 8]); };
		:local macTail "00";
		:if ([:len $usermac] > 1) do { :set macTail [:pick $usermac ([:len $usermac] - 2) [:len $usermac]]; };
		:local saleName ("mk-" . $dateStamp . "-" . $timeStamp . "-" . $userKey . "-" . $macTail);
		/system script add name $saleName source $logTxt comment "mikhmon";
		:do { /system script run Wartelpas-SyncUsage; } on-error {};
		:set wartelpasprice "";
		:set wartelpasplabel "";

		:local liveMarker "/report/laporan/services/live_ingest.php";
		:local usageMarker "/report/laporan/services/usage_ingest.php";
	};