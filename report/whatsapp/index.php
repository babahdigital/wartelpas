<?php
session_start();
error_reporting(0);

if (!isset($_SESSION["mikhmon"])) {
    header("Location:../admin.php?id=login");
    exit;
}

$session_id = $_GET['session'] ?? '';
?>

<div class="row">
    <div class="col-12">
        <div class="box box-solid">
            <div class="box-header with-border">
                <h3 class="box-title"><i class="fa fa-whatsapp"></i> WhatsApp Laporan</h3>
            </div>
            <div class="box-body">
                <p>Modul ini disiapkan untuk pengiriman laporan harian via WhatsApp (Fonnte).</p>
                <div style="margin-top:10px;">
                    <strong>Catatan Pengembangan:</strong>
                    <ul style="margin-top:6px;">
                        <li>Halaman ini akan menjadi pusat konfigurasi pengiriman laporan.</li>
                        <li>File PDF laporan akan disimpan di folder <code>report/pdf</code>.</li>
                        <li>Endpoint kirim dan log pengiriman akan ditambahkan pada tahap berikutnya.</li>
                    </ul>
                </div>
                <div style="margin-top:10px; color:#777; font-size:12px;">
                    Session aktif: <?= htmlspecialchars($session_id); ?>
                </div>
            </div>
        </div>
    </div>
</div>
