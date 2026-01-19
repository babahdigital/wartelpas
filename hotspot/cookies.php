<?php
/*
 * Copyright (C) 2018 Laksamadi Guko.
 * Modified by Pak Dul & Gemini AI (2026) - Wartel Edition
 * UPDATE: Fix Alignment (Flexbox Manual) & Original Background
 */
session_start();
error_reporting(0);
if (!isset($_SESSION["mikhmon"])) {
    header("Location:../admin.php?id=login");
} else {
    $getcookies = $API->comm("/ip/hotspot/cookie/print");
    $TotalReg = count($getcookies);
    $countcookies = $API->comm("/ip/hotspot/cookie/print", array(
        "count-only" => "",
    ));
}
?>

<style>
    /* Force Layout dengan CSS Murni (Bypass Bootstrap Version) */
    .flex-header {
        display: flex !important;
        justify-content: space-between !important;
        align-items: center !important;
        flex-wrap: nowrap !important;
        width: 100%;
    }
    
    .flex-left, .flex-right {
        display: flex !important;
        align-items: center !important;
		margin-left: 20px;
    }
    
    /* Background Asli (Warna Sebelumnya) */
    .card-modern {
        background: rgba(0, 0, 0, 0.2); /* Kembali ke warna asli */
        border: 1px solid rgba(255, 255, 255, 0.1);
        box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.37);
        backdrop-filter: blur(4px);
        border-radius: 8px;
        height: calc(100vh - 120px);
        display: flex;
        flex-direction: column;
    }

    .card-header-modern {
        background: rgba(0, 0, 0, 0.1);
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        padding: 15px;
        height: 70px; /* Tinggi fix untuk presisi */
    }

    /* Input Search yang Presisi */
    .search-box {
        position: relative;
        margin-right: 10px;
    }
    .input-modern {
        background: rgba(0, 0, 0, 0.3);
        border: 1px solid rgba(255, 255, 255, 0.2);
        color: #fff;
        border-radius: 4px;
        padding: 6px 10px 6px 30px; /* Padding kiri untuk icon */
        font-size: 13px;
        height: 34px; /* Samakan tinggi dengan tombol */
        width: 200px;
        transition: all 0.2s;
    }
    .input-modern:focus {
        background: rgba(0, 0, 0, 0.5);
        border-color: #3498db;
        outline: none;
    }
    .search-icon {
        position: absolute;
        left: 10px;
        top: 50%;
        transform: translateY(-50%);
        color: #ccc;
        font-size: 12px;
    }

    /* Tombol Refresh */
    .btn-refresh {
        background: rgba(255, 255, 255, 0.1);
        border: 1px solid rgba(255, 255, 255, 0.2);
        color: #fff;
        height: 34px;
        width: 34px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 4px;
        cursor: pointer;
        transition: 0.2s;
    }
    .btn-refresh:hover {
        background: #3498db;
        border-color: #3498db;
    }

    /* Tabel & Scroll */
    .table-container {
        flex: 1;
        overflow: auto;
    }
    .table-modern {
        width: 100%;
        color: #e0e0e0;
    }
    .table-modern thead th {
        background: rgba(0, 0, 0, 0.4); /* Header tabel gelap */
        position: sticky;
        top: 0;
        z-index: 5;
        border-bottom: 2px solid rgba(255, 255, 255, 0.1);
        padding: 10px;
        text-transform: uppercase;
        font-size: 0.8rem;
    }
    .table-modern td {
        padding: 8px 10px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        vertical-align: middle;
    }
    
    /* Font styles */
    .text-mono { font-family: monospace; letter-spacing: 0.5px; }
    .badge-count {
        background: #3498db;
        color: white;
        padding: 2px 8px;
        border-radius: 10px;
        font-size: 11px;
        margin-left: 10px;
        vertical-align: middle;
    }
</style>

<div class="row">
    <div class="col-12">
        <div class="card-modern">
            <div class="card-header-modern flex-header">
                <div class="flex-left">
                    <h3 style="margin: 0; font-size: 16px; color: #fff; font-weight: bold;">
                        <i class="fa fa-sitemap" style="margin-right: 8px;"></i> Hotspot Cookies
                    </h3>
                    <span class="badge-count">
                        <?php echo $countcookies; ?> Active
                    </span>
                </div>
                
                <div class="flex-right">
                    <div class="search-box">
                        <i class="fa fa-search search-icon"></i>
                        <input id="filterTable" type="text" class="input-modern" placeholder="Cari User / MAC...">
                    </div>
                    <div class="btn-refresh" onclick="location.reload();" title="Refresh Data">
                        <i class="fa fa-refresh"></i>
                    </div>
                </div>
            </div>

            <div class="table-container">
                <table id="dataTable" class="table-modern">
                    <thead>
                        <tr>
                            <th width="5%" style="text-align: center;">#</th>
                            <th>User</th>
                            <th>MAC Address</th>
                            <th>Domain</th>
                            <th>Expires</th>
                            <th width="5%" style="text-align: center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        for ($i = 0; $i < $TotalReg; $i++) {
                            $cookies = $getcookies[$i];
                            $id = $cookies['.id'];
                            $user = $cookies['user'];
                            $maca = $cookies['mac-address'];
                            $domain = isset($cookies['domain']) ? $cookies['domain'] : '-';
                            $exp = formatDTM($cookies['expires-in']);
                            
                            $uriprocess = "'./?remove-cookie=" . $id . "&session=" . $session . "'";
                            
                            echo "<tr>";
                            echo "<td style='text-align:center; opacity: 0.7;'>" . ($i + 1) . "</td>";
                            echo "<td style='font-weight:bold; color:#81d4fa;'>" . $user . "</td>";
                            echo "<td class='text-mono' style='color:#a5d6a7;'>" . $maca . "</td>";
                            echo "<td style='opacity: 0.8;'>" . $domain . "</td>";
                            echo "<td><span style='background:rgba(255,255,255,0.1); padding:2px 6px; border-radius:3px; font-size:11px;'>" . $exp . "</span></td>";
                            echo "<td style='text-align:center;'>
                                    <span style='cursor:pointer;' title='Hapus " . $user . "' onclick=loadpage(".$uriprocess.")>
                                        <i class='fa fa-trash text-danger'></i>
                                    </span>
                                  </td>";
                            echo "</tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
    // Script pencarian realtime sederhana
    document.getElementById('filterTable').addEventListener('keyup', function() {
        var value = this.value.toLowerCase();
        var rows = document.querySelectorAll('#dataTable tbody tr');
        rows.forEach(function(row) {
            row.style.display = row.innerText.toLowerCase().indexOf(value) > -1 ? '' : 'none';
        });
    });
</script>