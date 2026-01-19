<?php
/*
 * Copyright (C) 2018 Laksamadi Guko.
 * Modified by Pak Dul & Gemini AI (2026) - Wartel Edition
 * UPDATE: Precision Layout, Sticky Header, Responsive Fix
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
    /* Container & Card Styling */
    .card-modern {
        background: #1f2937; /* Warna solid gelap modern untuk kontras lebih baik */
        border: 1px solid rgba(255, 255, 255, 0.08);
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.3);
        border-radius: 8px;
        display: flex;
        flex-direction: column;
        height: calc(100vh - 120px); /* Menyesuaikan tinggi layar laptop dikurangi navbar */
        overflow: hidden;
    }

    /* Header Styling */
    .card-header-modern {
        background: rgba(31, 41, 55, 0.95);
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        padding: 1rem 1.5rem;
        flex-shrink: 0; /* Header tidak boleh mengecil */
    }

    /* Search Input Styling */
    .search-wrapper {
        position: relative;
        width: 250px;
    }
    .input-modern {
        background: rgba(0, 0, 0, 0.2);
        border: 1px solid rgba(255, 255, 255, 0.1);
        color: #e5e7eb;
        border-radius: 6px;
        padding: 6px 10px 6px 35px;
        font-size: 0.9rem;
        transition: all 0.2s;
        width: 100%;
    }
    .input-modern:focus {
        background: rgba(0, 0, 0, 0.4);
        border-color: #3b82f6;
        outline: none;
    }
    .search-icon {
        position: absolute;
        left: 10px;
        top: 50%;
        transform: translateY(-50%);
        color: #9ca3af;
        font-size: 0.8rem;
    }

    /* Table Container Styling */
    .table-container {
        flex-grow: 1;
        overflow: auto; /* Scroll hanya di area tabel */
        position: relative;
    }

    /* Table Styling */
    .table-modern {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        color: #d1d5db;
        font-size: 0.9rem;
    }
    
    /* Sticky Header Logic */
    .table-modern thead th {
        position: sticky;
        top: 0;
        background: #1f2937; /* Wajib solid color agar tidak transparan saat discroll */
        z-index: 10;
        padding: 12px 15px;
        text-transform: uppercase;
        font-size: 0.75rem;
        font-weight: 600;
        letter-spacing: 0.05em;
        color: #9ca3af;
        border-bottom: 2px solid rgba(255, 255, 255, 0.1);
        box-shadow: 0 2px 4px rgba(0,0,0,0.1); /* Bayangan halus di bawah header */
    }

    .table-modern tbody tr {
        border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        transition: background-color 0.15s;
    }
    .table-modern tbody tr:hover {
        background-color: rgba(59, 130, 246, 0.1); /* Highlight biru tipis saat hover */
    }
    .table-modern td {
        padding: 10px 15px;
        vertical-align: middle;
        border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    }
    
    /* Data Styling */
    .font-mono {
        font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
        font-size: 0.85rem;
    }
    .text-user { color: #60a5fa; font-weight: 500; }
    .text-mac { color: #a78bfa; }
    .text-domain { color: #34d399; }
    
    /* Scrollbar Styling (Webkit) */
    .table-container::-webkit-scrollbar {
        width: 8px;
        height: 8px;
    }
    .table-container::-webkit-scrollbar-track {
        background: rgba(0,0,0,0.1);
    }
    .table-container::-webkit-scrollbar-thumb {
        background: rgba(255,255,255,0.1);
        border-radius: 4px;
    }
    .table-container::-webkit-scrollbar-thumb:hover {
        background: rgba(255,255,255,0.2);
    }
</style>

<div class="row">
    <div class="col-12">
        <div class="card-modern">
            <div class="card-header-modern d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center">
                    <h3 class="card-title mb-0 text-white" style="font-size: 1.1rem; font-weight: 600;">
                        <i class="fa fa-sitemap mr-2 text-primary"></i> Hotspot Cookies
                    </h3>
                    <span class="badge badge-pill badge-primary ml-3 px-3 py-1" style="font-size: 0.8rem; background: rgba(59, 130, 246, 0.2); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.3);">
                        <?php echo $countcookies; ?> Active
                    </span>
                </div>
                
                <div class="d-flex align-items-center">
                    <div class="search-wrapper mr-3 d-none d-sm-block">
                        <i class="fa fa-search search-icon"></i>
                        <input id="filterTable" type="text" class="input-modern" placeholder="Search User, MAC...">
                    </div>
                    <button onclick="location.reload();" class="btn btn-sm btn-outline-secondary text-light" style="border-color: rgba(255,255,255,0.2);" title="Refresh Data">
                        <i class="fa fa-refresh"></i>
                    </button>
                </div>
            </div>

            <div class="table-container">
                <table id="dataTable" class="table-modern">
                    <thead>
                        <tr>
                            <th style="width: 60px; text-align: center;">#</th>
                            <th>User</th>
                            <th>MAC Address</th>
                            <th>Domain</th>
                            <th>Expires</th>
                            <th style="width: 60px; text-align: center;">Opt</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Gunakan buffer output jika data sangat banyak untuk performa
                        for ($i = 0; $i < $TotalReg; $i++) {
                            $cookies = $getcookies[$i];
                            $id = $cookies['.id'];
                            $user = $cookies['user'];
                            $maca = $cookies['mac-address'];
                            $domain = isset($cookies['domain']) ? $cookies['domain'] : '-';
                            $exp = formatDTM($cookies['expires-in']);
                            
                            $uriprocess = "'./?remove-cookie=" . $id . "&session=" . $session . "'";
                            
                            echo "<tr>";
                            // Index Number
                            echo "<td style='text-align:center; color: #6b7280;'>" . ($i + 1) . "</td>";
                            
                            // User
                            echo "<td class='text-user'>" . $user . "</td>";
                            
                            // MAC (Monospace font for precision)
                            echo "<td class='font-mono text-mac'>" . $maca . "</td>";
                            
                            // Domain
                            echo "<td class='text-domain'>" . $domain . "</td>";
                            
                            // Expires
                            echo "<td><span class='badge' style='background: rgba(255,255,255,0.1); color: #d1d5db; font-weight: normal;'>" . $exp . "</span></td>";
                            
                            // Action Button
                            echo "<td style='text-align:center;'>
                                    <span class='pointer' title='Remove " . $user . "' onclick=loadpage(".$uriprocess.")>
                                        <i class='fa fa-trash text-danger' style='opacity: 0.8; transition: opacity 0.2s;' onmouseover='this.style.opacity=1' onmouseout='this.style.opacity=0.8'></i>
                                    </span>
                                  </td>";
                            echo "</tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
            <?php if ($TotalReg == 0): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fa fa-inbox fa-3x mb-3" style="opacity: 0.3;"></i>
                    <p>No active cookies found.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    document.getElementById('filterTable').addEventListener('keyup', function() {
        var value = this.value.toLowerCase();
        var rows = document.querySelectorAll('#dataTable tbody tr');
        
        rows.forEach(function(row) {
            var text = row.textContent.toLowerCase();
            row.style.display = text.indexOf(value) > -1 ? '' : 'none';
        });
    });
</script>