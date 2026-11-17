<?php

namespace App\Support;

use Carbon\Carbon;

// Satu-satunya sumber aturan "tahun mana yang boleh dilihat" di halaman riwayat.
// Dipakai bersama oleh Riwayat Pengajuan dan (nanti) Monitoring / Rekap Kuota,
// supaya aturannya tidak berbeda antar halaman.
class LeaveYearWindow
{
    public static function min(): int
    {
        return now()->year - (int) config('leave.history_years_back', 2);
    }


    public static function max(): int
    {
        return now()->year + (int) config('leave.history_years_forward', 1);
    }

    // Urutan menurun, mis. di 2026: [2027, 2026, 2025, 2024].
    public static function years(): array
    {
        return range(self::max(), self::min());
    }

    // Tahun kosong / tidak valid / di luar jendela -> kembali ke tahun berjalan.
    // Ini yang mencegah jendela ditembus lewat URL (?tahun=2019).
    public static function resolve($year): int
    {
        $year = (int) $year;


        return in_array($year, self::years(), true) ? $year : (int) now()->year;
    }

    // Awal dan akhir tahun, dipakai untuk filter overlap.
    public static function range(int $year): array
    {
        return [
            Carbon::create($year, 1, 1)->startOfDay(),
            Carbon::create($year, 12, 31)->endOfDay(),
        ];
    }
}
