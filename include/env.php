<?php
// PROTEKSI FILE ENV
if (substr($_SERVER["REQUEST_URI"], -7) == "env.php") { header("Location:./"); exit(); };

// KONFIGURASI TERPUSAT BACKUP/RESTORE + RCLONE
$env = [
    'backup' => [
        'secret' => 'WartelpasSecureKey',
        'allowed_ips' => ['127.0.0.1', '::1', '10.10.83.1', '172.19.0.1'],
        'rate_window' => 300,
        'rate_limit' => 1,
        'keep_days' => 14,
        'keep_count' => 30,
        'min_db_size' => 65536
    ],
    'rclone' => [
        'bin' => '/usr/bin/rclone',
        'remote' => 'Wartelpas:backup_wartelpas',
        'enable' => true,
        'upload' => true,
        'download' => true
    ]
];
