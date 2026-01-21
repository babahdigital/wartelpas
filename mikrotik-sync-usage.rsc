# ==========================================
# MIKROTIK SYNC USAGE - 2 MENIT SEKALI
# File: mikrotik-sync-usage.rsc
# Tujuan: menjaga data usage (login/logout/uptime/bytes) tetap update
# ==========================================

# Script utama (buat sekali)
/system script
add name=Wartel-SyncUsage policy=read,write,test source=
{
	:global syncUsageLock;
	:if ([:typeof $syncUsageLock] = "nothing") do={ :set syncUsageLock false; }
	:if ($syncUsageLock = true) do={ :log warning "SYNC USAGE: masih berjalan, skip"; :return; }
	:set syncUsageLock true;

	:local url "http://wartelpas.sobigidul.net:8081/process/sync_usage.php?session=S3c7x9_LB";
	:do {
		/tool fetch url=$url keep-result=no;
		:log info "SYNC USAGE: OK (2m)";
	} on-error={
		:log warning "SYNC USAGE: Gagal koneksi";
	}

	:set syncUsageLock false;
}

# Scheduler 2 menit sekali (buat sekali)
/system scheduler
add name=Wartel-SyncUsage interval=2m on-event=Wartel-SyncUsage start-time=startup
