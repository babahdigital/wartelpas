<?php
/*
 * Copyright (C) 2018 Laksamadi Guko.
 * Modified by Pak Dul & Gemini AI (2026) - Wartel Edition
 * UPDATE: Modern Dark UI & Responsive Fix
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
    .card-modern {
        background: rgba(0, 0, 0, 0.2);
        border: 1px solid rgba(255, 255, 255, 0.1);
        box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.37);
        backdrop-filter: blur(4px);
        border-radius: 12px;
    }
    .card-header-modern {
        background: transparent;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        padding: 1.5rem;
    }
    .table-modern {
        width: 100%;
        margin-bottom: 0;
        color: #e0e0e0;
    }
    .table-modern thead th {
        border-top: none;
        border-bottom: 2px solid rgba(255, 255, 255, 0.1);
        font-weight: 600;
        text-transform: uppercase;
        font-size: 0.85rem;
        letter-spacing: 0.5px;
        background-color: rgba(255, 255, 255, 0.05);
    }
    .table-modern tbody tr {
        transition: background-color 0.2s ease;
    }
    .table-modern tbody tr:hover {
        background-color: rgba(255, 255, 255, 0.1);
    }
    .table-modern td {
        border-top: 1px solid rgba(255, 255, 255, 0.05);
        vertical-align: middle;
        padding: 12px 15px;
    }
    .input-modern {
        background: rgba(255, 255, 255, 0.1);
        border: 1px solid rgba(255, 255, 255, 0.2);
        color: #fff;
        border-radius: 20px;
        padding-left: 15px;
    }
    .input-modern:focus {
        background: rgba(255, 255, 255, 0.15);
        color: #fff;
        border-color: #3498db;
    }
    .btn-icon-hover:hover {
        transform: scale(1.2);
        transition: transform 0.2s;
    }
</style>

<div class="row">
    <div class="col-12">
        <div class="card card-modern">
            <div class="card-header card-header-modern d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0">
                    <i class="fa fa-sitemap mr-2"></i> Hotspot Cookies 
                    <span class="badge badge-pill badge-primary ml-2" style="font-size: 0.9rem;">
                        <?php echo $countcookies; ?> Item<?php echo ($countcookies > 1) ? 's' : ''; ?>
                    </span>
                </h3>
                <div class="card-tools">
                    <i onclick="location.reload();" class="fa fa-refresh pointer btn-icon-hover text-success" style="font-size: 1.2rem;" title="Reload data"></i>
                </div>
            </div>
            
            <div class="card-body p-0">
                <div class="p-3">
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text bg-transparent border-0 text-white"><i class="fa fa-search"></i></span>
                        </div>
                        <input id="filterTable" type="text" class="form-control input-modern" placeholder="Search Cookies...">
                    </div>
                </div>

                <div class="table-responsive" style="max-height: 75vh;">
                    <table id="dataTable" class="table table-modern table-hover text-nowrap">
                        <thead>
                            <tr>
                                <th style="width: 50px; text-align: center;">Action</th>
                                <th class="pointer" title="Click to sort"><i class="fa fa-sort"></i> User</th>
                                <th class="pointer" title="Click to sort"><i class="fa fa-sort"></i> MAC Address</th>
                                <th class="pointer" title="Click to sort"><i class="fa fa-sort"></i> Domain</th>
                                <th class="pointer" title="Click to sort"><i class="fa fa-sort"></i> Expires In</th>
                            </tr>
                        </thead>
                        <tbody> 
                        <?php
                        for ($i = 0; $i < $TotalReg; $i++) {
                            $cookies = $getcookies[$i];
                            $id = $cookies['.id'];
                            $user = $cookies['user'];
                            $maca = $cookies['mac-address'];
                            $domain = $cookies['domain'];
                            $exp = formatDTM($cookies['expires-in']);

                            $uriprocess = "'./?remove-cookie=" . $id . "&session=" . $session . "'";

                            echo "<tr>";
                            echo "<td style='text-align:center;'>
                                    <span class='pointer btn-icon-hover' title='Remove " . $user . "' onclick=loadpage(".$uriprocess.")>
                                        <i class='fa fa-trash text-danger' style='font-size: 1.1rem;'></i>
                                    </span>
                                  </td>";
                            echo "<td style='font-weight: 500; color: #64b5f6;'>" . $user . "</td>";
                            echo "<td style='font-family: monospace;'>" . $maca . "</td>";
                            echo "<td>" . $domain . "</td>";
                            echo "<td><span class='badge badge-secondary'>" . $exp . "</span></td>";
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