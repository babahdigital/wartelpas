<?php
// Maintenance page (PHP version)
error_reporting(0);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/include/acl.php';

$enabled = isMaintenanceEnabled();

if (isset($_GET['check'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode([
        'maintenance' => (bool)$enabled,
        'timestamp' => date('c'),
    ]);
    exit;
}

if (!$enabled) {
    header('Location: ./');
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <link rel="icon" href="./img/favicon.png" />
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Maintenance</title>
    <style>
        body {
            background-color: #0d1117;
            color: #c9d1d9;
            font-family: 'Courier New', Courier, monospace;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
            padding: 20px;
        }
        .terminal-window {
            width: 100%;
            max-width: 640px;
        }
        .command-line {
            display: flex;
            margin-bottom: 10px;
        }
        .prompt { color: #58a6ff; margin-right: 10px; }
        .cmd { color: #fff; }
        .output {
            color: #8b949e;
            margin-bottom: 18px;
            line-height: 1.5;
            border-left: 2px solid #30363d;
            padding-left: 15px;
        }
        .highlight { color: #f0883e; }
        .cursor {
            display: inline-block;
            width: 8px;
            height: 15px;
            background: #c9d1d9;
            animation: blink 1s step-end infinite;
            vertical-align: middle;
        }
        @keyframes blink { 50% { opacity: 0; } }
        .btn-retry {
            margin-top: 24px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #58a6ff;
            text-decoration: none;
            border: 1px solid #30363d;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 12px;
            transition: all 0.2s;
        }
        .btn-retry:hover { border-color: #58a6ff; background: rgba(88, 166, 255, 0.1); }
        .btn-retry.disabled {
            opacity: 0.5;
            pointer-events: none;
        }
        .status-badge {
            display: inline-block;
            margin-left: 6px;
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 999px;
            background: rgba(240, 136, 62, 0.2);
            color: #f0883e;
            border: 1px solid rgba(240, 136, 62, 0.4);
        }
        .footer-note {
            font-size: 11px;
            color: #6b7280;
            margin-top: 14px;
        }
    </style>
</head>
<body>
    <div class="terminal-window">
        <div class="command-line">
            <span class="prompt">root@wartelpas:~#</span>
            <span class="cmd">system_status --check</span>
        </div>
        <div class="output" id="maintenance-output">
            > Initializing maintenance protocol...<br>
            > <span class="highlight">Status: MAINTENANCE_MODE_ACTIVE</span>
            <span class="status-badge" id="maintenance-badge">aktif</span><br>
            > Estimasi selesai: Segera.<br>
            > Pesan: Sistem sedang dalam pembaruan keamanan dan performa.
        </div>

        <div class="command-line">
            <span class="prompt">root@wartelpas:~#</span>
            <span class="cmd">_ <span class="cursor"></span></span>
        </div>

        <a href="javascript:void(0)" class="btn-retry disabled" id="back-btn">[ KEMBALI KE SISTEM ]</a>
        <div class="footer-note" id="footer-note">Status dicek otomatis setiap 10 detik.</div>
    </div>

    <script>
        const backBtn = document.getElementById('back-btn');
        const badge = document.getElementById('maintenance-badge');
        const footerNote = document.getElementById('footer-note');

        function setReady(ready) {
            if (ready) {
                backBtn.classList.remove('disabled');
                backBtn.textContent = '[ KEMBALI KE SISTEM ]';
                badge.textContent = 'selesai';
                badge.style.background = 'rgba(46, 204, 113, 0.2)';
                badge.style.color = '#2ecc71';
                badge.style.borderColor = 'rgba(46, 204, 113, 0.4)';
                footerNote.textContent = 'Maintenance selesai. Klik tombol untuk kembali.';
            } else {
                backBtn.classList.add('disabled');
                backBtn.textContent = '[ MENUNGGU SISTEM ]';
                badge.textContent = 'aktif';
            }
        }

        async function checkMaintenance() {
            try {
                const res = await fetch('./maintenance.php?check=1', { cache: 'no-store' });
                const data = await res.json();
                if (data && data.maintenance === false) {
                    setReady(true);
                } else {
                    setReady(false);
                }
            } catch (e) {
                setReady(false);
            }
        }

        backBtn.addEventListener('click', () => {
            if (backBtn.classList.contains('disabled')) return;
            window.location.replace('./');
        });

        checkMaintenance();
        setInterval(checkMaintenance, 10000);
    </script>
</body>
</html>
