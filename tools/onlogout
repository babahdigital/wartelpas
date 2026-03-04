# SCRIPT MIKROTIK ON-LOGOUT
# HTTPS-safe: tidak memaksa mode=http agar tidak konflik dengan URL http

:local baseUrl "{{BASE_URL}}";
:local localBaseUrl "{{LOCAL_BASE_URL}}";
:local usageKey "{{USAGE_KEY}}";
:local session "{{SESSION}}";

{
	:local username "$user";
	:local userip "$address";
	:local usermac $"mac-address";
	:local date [/system clock get date];
	:local time [/system clock get time];
	:local uptime "0s";

	:if ([:len "$uptime"] > 0) do={ :set uptime "$uptime"; }

	:if ([:len $username] > 0) do={
		:local uID [/ip hotspot user find where name="$username"];
		:local comment "";
		:if ([:len $uID] > 0) do={
			:set uID ($uID->0);
			:do {
				:set comment [/ip hotspot user get $uID comment];
			} on-error={}
		} else={
			:set uID "";
		}

		:local cmtLower [:tolower $comment];
		:local isVip false;
		:if (([:find $cmtLower "vip"] != nil) || ([:find $cmtLower "pengelola"] != nil)) do={ :set isVip true; }

		:if ([:len $uID] > 0) do={
			:local newComment $comment;
			:if ([:len $newComment] = 0) do={ :set newComment ($date . " " . $time); }
			:if ([:find $newComment "| IP:"] = nil) do={
				:set newComment ($newComment . " | IP:" . $userip . " | MAC:" . $usermac);
			}
			:do { /ip hotspot user set numbers=$uID comment=$newComment mac-address=$usermac; } on-error={}
		}

		:if (!$isVip) do={
			:local useUrl ($localBaseUrl . "/report/laporan/services/usage_ingest.php?key=" . $usageKey . "&session=" . $session . "&event=logout&user=" . $username . "&date=" . $date . "&time=" . $time . "&ip=" . $userip . "&mac=" . $usermac . "&uptime=" . $uptime);
			:local useUrlFallback ($baseUrl . "/report/laporan/services/usage_ingest.php?key=" . $usageKey . "&session=" . $session . "&event=logout&user=" . $username . "&date=" . $date . "&time=" . $time . "&ip=" . $userip . "&mac=" . $usermac . "&uptime=" . $uptime);

			:do {
				/tool fetch url=$useUrl keep-result=no;
			} on-error={
				:do {
					/tool fetch url=$useUrlFallback keep-result=no check-certificate=no;
				} on-error={}
			}
		}
	}
}