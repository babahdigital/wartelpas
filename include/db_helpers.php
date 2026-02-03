<?php
if (function_exists('get_stats_db_path')) {
    return;
}

function get_stats_db_path()
{
    $root_dir = dirname(__DIR__);
    $env = [];
    $envFile = __DIR__ . '/env.php';
    if (file_exists($envFile)) {
        require $envFile;
    }
    $db_rel = $env['system']['db_file'] ?? 'db_data/mikhmon_stats.db';
    if (preg_match('/^[A-Za-z]:\\\\|^\//', $db_rel)) {
        return $db_rel;
    }
    return $root_dir . '/' . ltrim($db_rel, '/');
}
