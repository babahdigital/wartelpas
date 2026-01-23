# SCRIPT MIKROTIK ON-LOGOUT (CAPTURE IP & MAC)
# File: mikrotik-onlogout.rsc
# Update: 2026-01-19 - Save IP & MAC to comment on logout
# 
# INSTALASI:
# 1. MikroTik ? IP ? Hotspot ? Server Profiles
# 2. Pilih profile Anda ? Tab "Login"
# 3. Paste script ini di bagian "On Logout"
# 4. Apply & OK

{
    :local username "$user";
    :local userip "$address";
    :local usermac $"mac-address";
    :local logoutTime [/system clock get time];
    :local logoutDate [/system clock get date];
    :local userId [/ip hotspot user find where name="$username"];
    :local userUptime "";
    :local currentComment "";
    :if ($username = "") do={
        :log info "LOGOUT: skip empty user";
        :return;
    }
    :if ([:len $userId] = 0) do={
        :local actIds [/ip hotspot active find where user="$username"];
        :local ckIds [/ip hotspot cookie find where user="$username"];
        :if ([:len $actIds] = 0 && [:len $ckIds] = 0) do={
            :log info "LOGOUT: skip user not found";
            :return;
        }
    }
    :if ([:len $userId] > 0) do={
        :set userUptime [/ip hotspot user get $userId uptime];
        :set currentComment [/ip hotspot user get $userId comment];
    }
    # Bersihkan IP/MAC lama jika ada
    :local cleanComment $currentComment;
    :local ipPos [:find $cleanComment "| IP:"];
    :if ([:typeof $ipPos] != "nil") do={
        :set cleanComment [:pick $cleanComment 0 $ipPos];
    }
    
    # Extract Blok info dari comment (preserve)
    :local blokInfo "";
    :local commentLen [:len $cleanComment];
    
    # Cari "Blok-" atau "| Blok-" dalam comment
    :if ($commentLen > 4) do={
        :for i from=0 to=($commentLen - 5) do={
            :local substr [:pick $cleanComment $i ($i + 5)];
            :if ($substr = "Blok-" or $substr = "blok-") do={
                # Extract sampai spasi atau karakter khusus
                :local endPos $i;
                :for j from=$i to=$commentLen do={
                    :local char [:pick $cleanComment $j ($j + 1)];
                    :if ($char = " " or $char = ")" or $char = "-" or $char = "|" or $j = $commentLen) do={
                        :local nextOk false;
                        :if ($char = "-") do={
                            :if ([:len $currentComment] > ($j + 1)) do={
                                :if ([:pick $currentComment ($j + 1) ($j + 2)] != " ") do={
                                    :set nextOk true;
                                }
                            }
                        }
                        :if ($nextOk = true) do={
                            # Ini masih bagian dari Blok-A10, lanjut
                        } else={
                            :set endPos $j;
                            :set j $commentLen;
                        }
                    }
                }
                :set blokInfo [:pick $cleanComment $i $endPos];
                :set i $commentLen;
            }
        }
    }
    
    # Jika Blok tidak ditemukan, coba cari format "| Blok-"
    :if ([:len $blokInfo] = 0) do={
        :local pipePos [:find $cleanComment "| Blok-"];
        :if ([:typeof $pipePos] != "nil") do={
            :local startPos ($pipePos + 2);
            :local endPos [:find $cleanComment " " $startPos];
            :if ([:typeof $endPos] = "nil") do={
                :set endPos $commentLen;
            }
            :set blokInfo [:pick $cleanComment $startPos $endPos];
        }
    }
    
    # Build new comment tanpa ubah tanggal (ambil tanggal lama jika ada)
    :local baseDate "";
    :local pipePos2 [:find $cleanComment "|"];
    :if ([:typeof $pipePos2] != "nil") do={
        :set baseDate [:pick $cleanComment 0 $pipePos2];
    } else={
        :set baseDate $cleanComment;
    }
    :if ([:len $baseDate] = 0) do={
        :set baseDate ("$logoutDate $logoutTime");
    }
    
    # Format: "jan/21/2026 00:25:32 | Blok-A10 | IP:192.168.1.100 | MAC:AA:BB:CC:DD:EE:FF"
    :local newComment "$baseDate";
    
    :if ([:len $blokInfo] > 0) do={
        :set newComment ("$newComment | $blokInfo");
    }

    # Hapus IP/MAC lama jika sudah ada
    :set newComment ("$newComment | IP:$userip | MAC:$usermac");
    
    # Update comment dengan data logout (tanpa menumpuk)
    :if ([:len $userId] > 0) do={
        /ip hotspot user set comment=$newComment $userId;
    } else={
        :log warning "SYNC WARN: user not found for $username (comment not set)";
    }

    # REALTIME USAGE (LOGOUT)
    :local usageUrl ("http://wartelpas.sobigidul.net/process/usage_ingest.php?key=WartelpasSecureKey&session=S3c7x9_LB&event=logout&user=" . $username . "&date=" . $logoutDate . "&time=" . $logoutTime . "&ip=" . $userip . "&mac=" . $usermac . "&uptime=" . $userUptime);
    /tool fetch url=$usageUrl keep-result=no;

    # Hapus cookie + putus koneksi untuk user wartel saja (hindari user non-wartel)
    :local isWartel false;
    :local blokPos [:find $newComment "Blok-"];
    :if ([:typeof $blokPos] != "nil") do={ :set isWartel true; }
    :if ($isWartel = true) do={
        :local cookieIds [/ip hotspot cookie find where user="$username"];
        :if ([:len $cookieIds] > 0) do={
            :foreach cId in=$cookieIds do={
                :do { /ip hotspot cookie remove $cId; } on-error={}
            }
        }
        # Kick active hanya untuk user yang logout
        :local activeIds [/ip hotspot active find where user="$username"];
        :if ([:len $activeIds] > 0) do={
            :foreach aId in=$activeIds do={
                :do { /ip hotspot active remove $aId; } on-error={}
            }
        }
        # Putus koneksi hanya untuk user yang logout (berdasarkan IP)
        :if ([:len $userip] > 0) do={
            :local connSrc [/ip firewall connection find src-address=$userip];
            :if ([:len $connSrc] > 0) do={
                :foreach cSrc in=$connSrc do={ :do { /ip firewall connection remove $cSrc; } on-error={} }
            }
            :local connDst [/ip firewall connection find dst-address=$userip];
            :if ([:len $connDst] > 0) do={
                :foreach cDst in=$connDst do={ :do { /ip firewall connection remove $cDst; } on-error={} }
            }
        }
    }
    
    # Log ke system log (optional, untuk debugging)
    :log info "LOGOUT: $username | $userip | $usermac | $blokInfo";
}
