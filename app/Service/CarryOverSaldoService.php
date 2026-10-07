<?php

namespace App\Services;

use App\Models\SaldoCuti;

// ================= SERVICE: CARRY-OVER SALDO BIASA (SUMBER 1) =================
// SATU-SATUNYA tempat logika "carry-over dari sisa saldo biasa" ditulis.
// Dipakai oleh:
//   - Command GenerateSaldoCutiTahunBaru (menulis ke database)
//   - AdminController::hitungPreviewCarryOver() (hanya pratinjau)
// supaya keduanya tidak pernah berbeda hasil.
//
// Aturan:
//   Jalur Normal : min(6, saldo akhir tahun lalu)
//   Jalur Khusus : sisa hak tahun lalu dibawa penuh, HANYA jika tidak ada
//                  cuti terpakai sama sekali di 2 tahun berturut-turut.
class CarryOverSaldoService
{
    // Ringkasan satu tahun, dengan rumus yang sama seperti tampilan layar
    // (RekapKuotaDetailController::hitungKuota()). null jika barisnya belum ada.
    public function ringkasanTahun(int $pegawaiId, int $tahun): ?array
    {
        $saldo = SaldoCuti::where('pegawai_id', $pegawaiId)->where('tahun', $tahun)->first();

        if (!$saldo) {
            return null;
        }

        $saldoSebelumnya = SaldoCuti::where('pegawai_id', $pegawaiId)->where('tahun', $tahun - 1)->first();

        $hak = (int) $saldo->kuota_tahunan;
        $carry = (int) $saldo->carry_forward_normal;
        $duaTahunLalu = (int) ($saldoSebelumnya->carry_forward_normal ?? 0);

        $eligible = ($duaTahunLalu === 12 && $carry === 12) ? 12 : min(6, $carry);
        $terpakai = SaldoCuti::terpakaiEfektif($pegawaiId, $tahun, $saldo);

        return [
            'terpakai' => $terpakai,
            'sisa_hak' => max($hak - $terpakai, 0),
            'saldo_akhir' => max($hak + $eligible - $terpakai, 0),
        ];
    }

    // SUMBER 1: carry-over dari sisa saldo biasa.
    public function carryDariSaldoBiasa(int $pegawaiId, int $tahunBaru): int
    {
        $lalu = $this->ringkasanTahun($pegawaiId, $tahunBaru - 1);

        if (!$lalu) {
            return 0;
        }

        $duaLalu = $this->ringkasanTahun($pegawaiId, $tahunBaru - 2);

        // Jalur Khusus: tidak ada cuti terpakai sama sekali di 2 tahun berturut-turut.
        if ($duaLalu && $lalu['terpakai'] === 0 && $duaLalu['terpakai'] === 0) {
            return $lalu['sisa_hak'];
        }

        // Jalur Normal: maksimal 6 hari, sama dengan pratinjau di layar.
        return SaldoCuti::hitungPreviewCarryOver($lalu['saldo_akhir']);
    }
}
// ================= END SERVICE =================