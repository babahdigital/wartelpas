<?php
session_start();
// =======================================================================
// SECURITY BLOCK - WARTEL PAS (ANTI-BRUTEFORCE & BOT)
// Date Analysis: 2026-01-12
// Author: Pak Dul & Gemini AI
// =======================================================================

// 1. Cek Login (Wajib)
if (!isset($_SESSION["mikhmon"])) {
    header("Location:../admin.php?id=login");
    exit();
}

// 2. STOP EKSEKUSI TOTAL
// Mematikan mesin pemroses agar BOT tidak bisa menembak API/Script ini.
// File ini (adduser.php & quickuser.php) adalah target utama serangan bot
// yang menghasilkan komentar tanggal (date).

die("<div style='text-align:center; padding:50px; color:red; font-family:sans-serif;'>
        <h1><i class='fa fa-ban'></i> AKSES DITUTUP (SECURITY)</h1>
        <p>Sistem mendeteksi potensi serangan bot melalui jalur ini.</p>
        <button onclick='window.history.back()' style='padding:10px 20px; cursor:pointer;'>Kembali</button>
     </div>");
?>