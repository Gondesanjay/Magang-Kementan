<?php

namespace App\Services;

use App\Models\SaldoCuti;

// Satu-satunya tempat logika carry-over dari sisa saldo biasa.
// Dipakai oleh Command GenerateSaldoCutiTahunBaru dan AdminController.
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
            'sisa_hak' => max($hak - $terpakai, 0),
            'saldo_akhir' => max($hak + $eligible - $terpakai, 0),
        ];
    }

    public function carryDariSaldoBiasa(int $pegawaiId, int $tahunBaru): int
    {
        $saldoTahunLalu = $this->ringkasanTahun($pegawaiId, $tahunBaru - 1);
        if (!$saldoTahunLalu) {
            return 0;
        }

        $saldoDuaTahunLalu = $this->ringkasanTahun($pegawaiId, $tahunBaru - 2);
        $kuota = (int) (SaldoCuti::where('pegawai_id', $pegawaiId)->where('tahun', $tahunBaru)->value('kuota_tahunan') ?? 12);

        if (
            $saldoDuaTahunLalu
            && $saldoDuaTahunLalu['saldo_akhir'] === 12
            && $saldoTahunLalu['saldo_akhir'] === 12
        ) {
            return SaldoCuti::hitungBawaan(12, 12, $kuota);
        }

        return SaldoCuti::hitungPreviewCarryOver($saldoTahunLalu['saldo_akhir']);
    }
}
