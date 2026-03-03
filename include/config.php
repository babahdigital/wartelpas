<?php
// PROTEKSI FILE CONFIG
if (substr($_SERVER["REQUEST_URI"], -10) == "config.php") {
    header("Location:./");
    exit();
};

require_once __DIR__ . '/db.php';
$data = app_db_load_config();