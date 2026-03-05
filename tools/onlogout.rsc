# SCRIPT MIKROTIK ON-LOGOUT
# Versi kompatibel API: tanpa karakter sama-dengan pada source script

:local baseUrl "{{BASE_URL}}";
:local localBaseUrl "{{LOCAL_BASE_URL}}";
:local usageKey "{{USAGE_KEY}}";
:local session "{{SESSION}}";

	:local username $user;
	:local userip $address;
	:local usermac "-";
	:local date [/system clock get date];
	:local time [/system clock get time];
	:local uptime "0s";

	:if ([:len $username] > 0) do {
		:do { /system script run Wartelpas-SyncUsage; } on-error {};
		:local usageMarker "/report/laporan/services/usage_ingest.php";
	};
