<?php
/*
 * IP Address Detective & System Status
 * Reskinned for Wartelpas (Dark Professional)
 */

// ==========================================
// 1. LOGIC & FUNCTIONS (TIDAK DIUBAH)
// ==========================================

function esc($value)
{
	return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function is_valid_ip($ip)
{
	return filter_var($ip, FILTER_VALIDATE_IP) !== false;
}

function is_public_ip($ip)
{
	return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

function split_forwarded_for($value)
{
	$items = array_filter(array_map('trim', explode(',', (string)$value)));
	$valid = [];
	foreach ($items as $ip) {
		if (is_valid_ip($ip)) {
			$valid[] = $ip;
		}
	}
	return $valid;
}

function get_client_ip_detail()
{
	$server = $_SERVER;
	$candidates = [];

	// Urutan prioritas header (Cloudflare/Proxy/Direct)
	$headers = [
		'HTTP_CF_CONNECTING_IP',
		'HTTP_TRUE_CLIENT_IP',
		'HTTP_X_REAL_IP',
		'HTTP_CLIENT_IP',
		'HTTP_X_FORWARDED_FOR',
		'REMOTE_ADDR'
	];

	foreach ($headers as $header) {
		if (!empty($server[$header])) {
			// Handle multiple IPs di X-Forwarded-For
			if ($header === 'HTTP_X_FORWARDED_FOR') {
				$ips = split_forwarded_for($server[$header]);
				foreach ($ips as $ip) {
					$candidates[] = $ip;
				}
			} else {
				if (is_valid_ip($server[$header])) {
					$candidates[] = $server[$header];
				}
			}
		}
	}

	// Filter duplikat
	$candidates = array_unique($candidates);
	
	// Logika Pemilihan IP Utama (Prioritaskan Public IP pertama yang ditemukan)
	$selected_ip = '';
	foreach ($candidates as $ip) {
		if (is_public_ip($ip)) {
			$selected_ip = $ip;
			break;
		}
	}
	// Jika tidak ada public IP, ambil yang valid pertama (bisa jadi local/private)
	if (empty($selected_ip) && !empty($candidates)) {
		$selected_ip = reset($candidates);
	}

	// Tentukan Label Public/Private
	$is_public = is_public_ip($selected_ip);

	return [
		'selected'   => $selected_ip,
		'is_public'  => $is_public,
		'candidates' => $candidates,
		'details'    => [
			'REMOTE_ADDR'          => $server['REMOTE_ADDR'] ?? '-',
			'HTTP_X_FORWARDED_FOR' => $server['HTTP_X_FORWARDED_FOR'] ?? '-',
			'HTTP_X_REAL_IP'       => $server['HTTP_X_REAL_IP'] ?? '-',
		]
	];
}

// Get OPcache Status
$opcache_enabled = function_exists('opcache_get_status');
$opcache_status = null;
if ($opcache_enabled) {
	$opcache_status = @opcache_get_status(false);
}

// Execute Logic
$ip = get_client_ip_detail();
$host = $_SERVER['HTTP_HOST'] ?? '-';
$proto = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443) ? 'https' : 'http';
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '-';
$server_time = date('Y-m-d H:i:s');
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IP Address Detective - Wartelpas</title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
    <style>
        /* Custom Dark Theme "Profesional Clean" */
        body {
            background-color: #1a1c23; /* Dark Background */
            color: #a0a6b6; /* Soft Text */
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding-top: 40px;
            padding-bottom: 40px;
        }
        .card-detective {
            background-color: #24262d;
            border: 1px solid #2f323e;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
            overflow: hidden;
            max-width: 700px;
            margin: 0 auto;
        }
        .card-header {
            background-color: #2f323e;
            padding: 15px 20px;
            border-bottom: 1px solid #3a3f4b;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .card-header h3 {
            margin: 0;
            color: #fff;
            font-size: 18px;
            font-weight: 600;
        }
        .card-body {
            padding: 25px;
        }
        /* Hero Section for IP */
        .ip-hero {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #2f323e;
        }
        .ip-display {
            font-size: 36px;
            font-weight: 700;
            color: #fff;
            letter-spacing: 1px;
            margin-bottom: 5px;
        }
        .badge-custom {
            padding: 5px 12px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .badge-public { background-color: #2e7d32; color: #fff; } /* Green */
        .badge-private { background-color: #c62828; color: #fff; } /* Red */
        
        /* Table Styling */
        .table-custom {
            width: 100%;
            margin-bottom: 0;
        }
        .table-custom th {
            color: #fff;
            font-weight: 500;
            border-top: none !important;
            border-bottom: 1px solid #2f323e !important;
            width: 35%;
        }
        .table-custom td {
            color: #a0a6b6;
            border-top: none !important;
            border-bottom: 1px solid #2f323e !important;
            word-break: break-all;
        }
        .list-unstyled li {
            padding: 2px 0;
            font-family: monospace;
            color: #e0e0e0;
        }
        .section-title {
            font-size: 14px;
            color: #5c6270;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-top: 20px;
            margin-bottom: 10px;
            font-weight: 700;
        }
        
        /* OPcache Bar */
        .progress-xs {
            height: 8px;
            background-color: #2f323e;
            border-radius: 4px;
            margin-bottom: 5px;
        }
        .progress-bar-success { background-color: #00e676; }
        .text-stats { font-size: 11px; color: #5c6270; }
        
        /* Footer */
        .card-footer {
            background-color: #1f2128;
            padding: 12px 20px;
            text-align: right;
            font-size: 12px;
            color: #5c6270;
            border-top: 1px solid #2f323e;
        }
        .label-primary{
            padding: 7px;
        }
        .text-highlight { color: #29b6f6; } /* Light Blue */
    </style>
</head>
<body>

<div class="container">
    <div class="card-detective">
        <div class="card-header">
            <h3><i class="fa fa-user-secret text-highlight"></i> IP Address Detective</h3>
            <span class="label label-primary"><i class="fa fa-server"></i> <?= esc($host); ?></span>
        </div>
        
        <div class="card-body">
            <div class="ip-hero">
                <div class="text-stats" style="margin-bottom:5px;">IP ANDA TERDETEKSI SEBAGAI</div>
                <div class="ip-display"><?= esc($ip['selected']); ?></div>
                <?php if ($ip['is_public']): ?>
                    <span class="badge-custom badge-public"><i class="fa fa-globe"></i> Publik (Internet)</span>
                <?php else: ?>
                    <span class="badge-custom badge-private"><i class="fa fa-lock"></i> Non-Publik (Lokal/VPN)</span>
                <?php endif; ?>
            </div>

            <div class="row">
                <div class="col-md-12">
                    <div class="section-title"><i class="fa fa-search"></i> Analisa Header</div>
                    <table class="table table-custom">
                        <tr>
                            <th>REMOTE_ADDR</th>
                            <td><code><?= esc($ip['details']['REMOTE_ADDR']); ?></code></td>
                        </tr>
                        <tr>
                            <th>X-Forwarded-For</th>
                            <td>
                                <?php if ($ip['details']['HTTP_X_FORWARDED_FOR'] !== '-'): ?>
                                    <code><?= esc($ip['details']['HTTP_X_FORWARDED_FOR']); ?></code>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Kandidat IP Lain</th>
                            <td>
                                <?php if (!empty($ip['candidates'])): ?>
                                    <ul class="list-unstyled" style="margin-bottom:0;">
                                        <?php foreach ($ip['candidates'] as $candidate): ?>
                                            <?php if ($candidate !== $ip['selected']): ?>
                                                <li><i class="fa fa-angle-right"></i> <?= esc($candidate); ?></li>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </ul>
                                    <?php if (count($ip['candidates']) <= 1): ?>
                                        <span class="text-muted">Tidak ada kandidat lain</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <div class="row" style="margin-top:20px;">
                <div class="col-md-12">
                    <div class="section-title"><i class="fa fa-cogs"></i> Info Sistem & Kinerja</div>
                    <table class="table table-custom">
                        <tr>
                            <th>Protokol</th>
                            <td>
                                <?php if ($proto === 'https'): ?>
                                    <span class="text-success"><i class="fa fa-lock"></i> HTTPS (Aman)</span>
                                <?php else: ?>
                                    <span class="text-warning"><i class="fa fa-unlock"></i> HTTP (Tidak Terenkripsi)</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>User Agent</th>
                            <td style="font-size:12px;"><?= esc($user_agent); ?></td>
                        </tr>
                        <tr>
                            <th>OPcache Status</th>
                            <td>
                                <?php
                                if (!$opcache_enabled) {
                                    echo '<span class="text-danger"><i class="fa fa-times-circle"></i> Ekstensi Tidak Terinstall</span>';
                                } elseif (empty($opcache_status) || empty($opcache_status['opcache_enabled'])) {
                                    echo '<span class="text-warning"><i class="fa fa-pause-circle"></i> Tidak Aktif</span>';
                                } else {
                                    $stats = $opcache_status['opcache_statistics'];
                                    $hitRate = $stats['opcache_hit_rate'] ?? 0;
                                    $hits = $stats['hits'] ?? 0;
                                    $misses = $stats['misses'] ?? 0;
                                    $cached = $opcache_status['memory_usage']['used_memory'] ?? 0;
                                    
                                    // Format memory
                                    $cached_mb = round($cached / 1024 / 1024, 2);
                                    
                                    echo '<div style="display:flex; justify-content:space-between; align-items:center;">';
                                    echo '<span class="text-success" style="font-weight:bold;"><i class="fa fa-check-circle"></i> Aktif</span>';
                                    echo '<span class="text-stats">Hit Rate: '.number_format($hitRate, 2).'%</span>';
                                    echo '</div>';
                                    
                                    echo '<div class="progress progress-xs">';
                                    echo '<div class="progress-bar progress-bar-success" role="progressbar" aria-valuenow="'.$hitRate.'" aria-valuemin="0" aria-valuemax="100" style="width: '.$hitRate.'%"></div>';
                                    echo '</div>';
                                    
                                    echo '<div class="text-stats">';
                                    echo 'Memori: <b>' . $cached_mb . ' MB</b> | Hits: <b>' . $hits . '</b> | Miss: <b>' . $misses . '</b>';
                                    echo '</div>';
                                }
                                ?>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <div class="card-footer">
            <i class="fa fa-clock-o"></i> Waktu Server: <?= $server_time; ?>
        </div>
    </div>
</div>

</body>
</html>