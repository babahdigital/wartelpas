<?php
/*
 * Copyright (C) 2018 Laksamadi Guko.
 * Modified by Pak Dul & Gemini AI (2026) - Wartel Edition
 * UPDATE: Ultra Modern Dark UI for Cookies
 */
session_start();
// hide all error
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
    /* Base Glassmorphism Card */
    .card-modern {
        background: linear-gradient(145deg, rgba(255,255,255,0.05) 0%, rgba(0,0,0,0.2) 100%);
        border: 1px solid rgba(255, 255, 255, 0.08);
        box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.4);
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        border-radius: 15px;
        overflow: hidden; /* Round corners for children */
    }
    
    /* Header Styling */
    .card-header-modern {
        background: rgba(0, 0, 0, 0.2);
        border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        padding: 20px;
    }
    .text-title-modern {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        font-weight: 600;
        color: #ecf0f1;
        letter-spacing: 0.5px;
    }
    
    /* Input Search Modern */
    .input-glass {
        background: rgba(0, 0, 0, 0.3);
        border: 1px solid rgba(255, 255, 255, 0.1);
        color: #fff;
        border-radius: 30px;
        padding: 10px 20px;
        transition: all 0.3s ease;
    }
    .input-glass:focus {
        background: rgba(0, 0, 0, 0.5);
        border-color: #0abde3;
        box-shadow: 0 0 10px rgba(10, 189, 227, 0.3);
        color: #fff;
    }
    
    /* Table Modern */
    .table-modern {
        width: 100%;
        margin-bottom: 0;
        color: #bdc3c7;
        border-collapse: separate;
        border-spacing: 0;
    }
    .table-modern thead th {
        background-color: rgba(0, 0, 0, 0.4);
        border-bottom: 2px solid rgba(255, 255, 255, 0.05);
        color: #95a5a6;
        font-weight: 700;
        text-transform: uppercase;
        font-size: 0.75rem;
        letter-spacing: 1px;
        padding: 15px;
        border-top: none;
    }
    .table-modern tbody tr {
        transition: all 0.2s ease;
        background: transparent;
    }
    .table-modern tbody tr:hover {
        background-color: rgba(255, 255, 255, 0.05);
        transform: scale(1.002);
    }
    .table-modern td {
        border-top: 1px solid rgba(255, 255, 255, 0.02);
        padding: 12px 15px;
        vertical-align: middle;
    }
    
    /* Special Text Colors */
    .text-user-neon {
        color: #00d2d3; /* Cyan Neon */
        font-weight: 600;
        text-shadow: 0 0 5px rgba(0, 210, 211, 0.2);
    }
    .text-mac-tech {
        font-family: 'Consolas', 'Monaco', monospace;
        color: #ff9f43; /* Pastel Orange */
        letter-spacing: 0.5px;
    }
    .text-domain {
        color: #a29bfe; /* Soft Purple */
        font-style: italic;
    }
    
    /* Badges */
    .badge-modern-gradient {
        background: linear-gradient(45deg, #5f27cd, #341f97);
        color: white;
        padding: 6px 12px;
        border-radius: 20px;
        font-weight: 500;
        font-size: 0.75rem;
        box-shadow: 0 2px 5px rgba(0,0,0,0.2);
    }
    .badge-count {
        background: #ff6b6b;
        color: white;
        font-size: 0.8rem;
        padding: 3px 8px;
        border-radius: 6px;
        margin-left: 10px;
        vertical-align: middle;
    }

    /* Buttons */
    .btn-delete-modern {
        background: rgba(255, 107, 107, 0.1);
        border-radius: 50%;
        width: 35px;
        height: 35px;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.3s;
        cursor: pointer;
    }
    .btn-delete-modern:hover {
        background: rgba(255, 107, 107, 0.8);
        box-shadow: 0 0 10px rgba(255, 107, 107, 0.5);
    }
    .btn-delete-modern i {
        color: #ff6b6b;
        transition: color 0.3s;
    }
    .btn-delete-modern:hover i {
        color: white;
    }
    
    .btn-refresh-modern {
        color: #2ed573;
        font-size: 1.2rem;
        cursor: pointer;
        transition: transform 0.4s ease;
    }
    .btn-refresh-modern:hover {
        transform: rotate(180deg);
        text-shadow: 0 0 8px rgba(46, 213, 115, 0.6);
    }
</style>

<div class="row">
    <div class="col-12">
        <div class="card card-modern">
            <div class="card-header card-header-modern d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center">
                    <i class="fa fa-sitemap mr-2" style="font-size: 1.5rem; color: #54a0ff;"></i>
                    <h3 class="text-title-modern mb-0">Hotspot Cookies</h3>
                    <span class="badge badge-count shadow-sm">
                        <?php echo $countcookies; ?>
                    </span>
                </div>
                <div>
                    <i onclick="location.reload();" class="fa fa-refresh btn-refresh-modern" title="Reload Data"></i>
                </div>
            </div>
            
            <div class="card-body p-0">
                <div class="p-3" style="background: rgba(0,0,0,0.1);">
                    <div class="input-group">
                         <div class="input-group-prepend">
                            <span class="input-group-text bg-transparent border-0" style="color: #ccc;"><i class="fa fa-search"></i></span>
                        </div>
                        <input id="filterTable" type="text" class="form-control input-glass" placeholder="Search cookies (User, Mac, Domain)...">
                    </div>
                </div>

                <div class="table-responsive" style="max-height: 70vh;">   	   
                    <table id="dataTable" class="table table-modern table-hover text-nowrap">
                      <thead>
                      <tr>
                        <th style="width: 50px; text-align:center;">Action</th>
                        <th class="pointer" title="Sort by User"><i class="fa fa-sort"></i> User</th>
                        <th class="pointer" title="Sort by MAC"><i class="fa fa-sort"></i> MAC Address</th>
                        <th class="pointer" title="Sort by Domain"><i class="fa fa-sort"></i> Domain</th>
                        <th class="pointer" title="Sort by Expire"><i class="fa fa-sort"></i> Expires In</th>
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
                        // Modern Action Button
                        echo "<td style='text-align:center;'>
                                <div class='d-flex justify-content-center'>
                                    <span class='btn-delete-modern' title='Remove " . $user . "' onclick=loadpage(".$uriprocess.")>
                                        <i class='fa fa-trash'></i>
                                    </span>
                                </div>
                              </td>";
                        
                        // Styled Columns
                        echo "<td class='text-user-neon'>" . $user . "</td>";
                        echo "<td class='text-mac-tech'>" . $maca . "</td>";
                        echo "<td class='text-domain'>" . $domain . "</td>";
                        echo "<td><span class='badge badge-modern-gradient'><i class='fa fa-clock-o mr-1'></i>" . $exp . "</span></td>";
                        echo "</tr>";
                    }
                    ?>
                      </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>