<?php 
// PROTEKSI FILE CONFIG
if(substr($_SERVER["REQUEST_URI"], -10) == "config.php"){header("Location:./"); exit();}; 

$data['mikhmon'] = array ('1'=>'mikhmon<|<kamtib','mikhmon>|>o5KfrJqUaWNl');

/* * SECURITY UPDATE: Session ID Obfuscation
 * "LapasBatulicin" diubah menjadi Hash Unik "S3c7x9_LB"
 * URL Akses menjadi: /?session=S3c7x9_LB
 */
$data['S3c7x9_LB'] = array (
    '1'=>'S3c7x9_LB!10.10.83.1', // ID Internal juga disesuaikan dengan Hash
    'S3c7x9_LB@|@abdullah',
    'S3c7x9_LB#|#mZ2amZOlsZo=',
    'S3c7x9_LB%Lapas Batulicin',
    'S3c7x9_LB^wartelpas.net',
    'S3c7x9_LB&Rp',
    'S3c7x9_LB*10',
    'S3c7x9_LB(1',
    'S3c7x9_LB)',
    'S3c7x9_LB=10',
    'S3c7x9_LB@!@disable',
    'S3c7x9_LB~wartel' // Hotspot server khusus wartelpas
);