<?php

// Jendela tahun untuk halaman riwayat cuti.
// Contoh di tahun 2026 (back = 2, forward = 1): tampil 2024, 2025, 2026, 2027.
// Ubah angka di sini kalau jendela ingin lebih longgar/ketat, tanpa ubah logika.
return [
    'history_years_back'    => 2,
    'history_years_forward' => 1,
    // Pengajuan final yang selesai lebih dari N tahun lalu diarsipkan oleh cuti:arsipkan.
    'arsip_tahun' => 5,
];
