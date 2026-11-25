<?php

namespace App\Services;

use App\Models\SaldoCuti;

/**
 * Satu-satunya tempat logika carry-over dari sisa saldo biasa.
 * Dipakai oleh Command GenerateSaldoCutiTahunBaru, AdminController (preview),
 * dan SaldoCuti::sinkronkanBawaan().
 *
 * carryDariSaldoBiasa() mengembalikan sisa mentah tahun lalu (Hak - Terpakai),
 * tanpa bawaan dan tanpa dipotong 6.
 *
 * Cuti ditangguhkan (Tgh) TIDAK dikurangkan di sini karena harinya kembali ke
 * saldo tahun itu. Tgh dibawa terpisah ke tahun berikutnya dan menambah Total
 * Tersedia (lihat RekapKuotaDetailController::hitungKuota, TGH_MASUK_TOTAL).
 * Contoh: Hak 12, terpakai 4, Tgh 2 -> sisa 8; bawaan = min(6, 8) = 6; Tgh 2 dibawa terpisah.
 *
 * Aturan bawaan (maks. 6 hari, atau 12 jika sisa N-2 dan N-1 sama-sama 12)
 * diterapkan sekali oleh SaldoCuti::hitungBawaan() saat ditampilkan.
 */
class CarryOverSaldoService
{
    public function ringkasanTahun(int $pegawaiId, int $tahun): ?array
    {
        $saldo = SaldoCuti::where('pegawai_id', $pegawaiId)->where('tahun', $tahun)->first();

        if (!$saldo) {
            return null;
        }

        $saldoSebelumnya = SaldoCuti::where('pegawai_id', $pegawaiId)->where('tahun', $tahun - 1)->first();

        $hak = (int) $saldo->kuota_tahunan;
        $carry = (int) ($saldo->carry_forward_normal ?? 0);
        $duaTahunLalu = (int) ($saldoSebelumnya->carry_forward_normal ?? 0);
        $eligible = SaldoCuti::hitungBawaan($duaTahunLalu, $carry, $hak);
        $terpakai = SaldoCuti::terpakaiEfektif($pegawaiId, $tahun, $saldo);

        return [
            'terpakai' => $terpakai,
            // Tgh tahun ini tidak mengurangi sisa (TGH_TAHUN_INI_MENGURANGI_SISA = false).
            'sisa_hak' => max($hak - $terpakai, 0),
            // Belum memuat Tgh tahun lalu; bukan angka di Rekap Kuota Detail, jangan dipakai untuk tampilan.
            'saldo_akhir' => max($hak + $eligible - $terpakai, 0),
        ];
    }

    /**
     * Sisa mentah tahun ($tahunBaru - 1) = Hak - Terpakai (tanpa bawaan, tanpa
     * dipotong 6, tanpa Tgh). Mengembalikan 0 jika dompet tahun lalu belum ada.
     */
    public function carryDariSaldoBiasa(int $pegawaiId, int $tahunBaru): int
    {
        $saldoTahunLalu = $this->ringkasanTahun($pegawaiId, $tahunBaru - 1);

        return $saldoTahunLalu ? (int) $saldoTahunLalu['sisa_hak'] : 0;
    }
}
