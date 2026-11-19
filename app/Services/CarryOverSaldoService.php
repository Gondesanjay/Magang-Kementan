<?php

namespace App\Services;

use App\Models\CutiDitangguhkan;
use App\Models\SaldoCuti;

// Satu-satunya tempat logika carry-over dari sisa saldo biasa.
// Dipakai oleh Command GenerateSaldoCutiTahunBaru, AdminController (preview),
// dan SaldoCuti::sinkronkanBawaan().
//
// carryDariSaldoBiasa() mengembalikan SISA MENTAH tahun lalu:
//   Hak - Terpakai - Tgh tahun itu
// (tanpa bawaan, tanpa dipotong 6). Tgh tahun itu WAJIB dikurangkan di sini
// karena ia masuk ke Total Tersedia tahun berikutnya lewat "Tgh tahun lalu"
// (lihat RekapKuotaDetailController::hitungKuota). Tanpa pengurangan, Tgh
// terhitung dua kali: sekali di carry, sekali lagi sebagai Tgh tahun lalu.
//
// Aturan bawaan (maks. 6 hari, atau 12 jika sisa N-2 dan N-1 sama-sama 12)
// diterapkan SATU KALI oleh SaldoCuti::hitungBawaan() saat ditampilkan.
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

        // Tgh tahun ini memotong sisa hak (sama dengan TGH_TAHUN_INI_MENGURANGI_SISA
        // di RekapKuotaDetailController).
        $tghTahunIni = (int) CutiDitangguhkan::where('pegawai_id', $pegawaiId)
            ->where('tahun_asal', $tahun)
            ->sum('jumlah_hari');

        return [
            'terpakai' => $terpakai,
            'sisa_hak' => max($hak - $terpakai - $tghTahunIni, 0),
            // CATATAN: belum memuat Tgh (tahun lalu / tahun ini). Bukan angka
            // yang tampil di Rekap Kuota Detail; jangan dipakai untuk tampilan.
            'saldo_akhir' => max($hak + $eligible - $terpakai, 0),
        ];
    }

    /**
     * Sisa mentah tahun ($tahunBaru - 1) = Hak - Terpakai - Tgh tahun itu
     * (tanpa bawaan, tanpa dipotong 6). Mengembalikan 0 jika dompet tahun
     * lalu belum ada.
     */
    public function carryDariSaldoBiasa(int $pegawaiId, int $tahunBaru): int
    {
        $saldoTahunLalu = $this->ringkasanTahun($pegawaiId, $tahunBaru - 1);

        return $saldoTahunLalu ? (int) $saldoTahunLalu['sisa_hak'] : 0;
    }
}
