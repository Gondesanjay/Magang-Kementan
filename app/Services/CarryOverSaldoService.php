<?php

namespace App\Services;

use App\Models\SaldoCuti;

// Satu-satunya tempat logika carry-over dari sisa saldo biasa.
// Dipakai oleh Command GenerateSaldoCutiTahunBaru, AdminController,
// dan SaldoCuti::sinkronkanBawaan().
//
// [DIUBAH] carryDariSaldoBiasa() sekarang mengembalikan SISA MENTAH tahun
// lalu (Hak - Terpakai), BUKAN saldo akhir yang sudah dipotong 6.
// Kolom saldo_cutis.carry_forward_normal harus berisi sisa mentah (sama
// seperti yang diketik Admin di edit inline atau diimpor dari Excel).
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


        return [
            'terpakai' => $terpakai,
            'sisa_hak' => max($hak - $terpakai, 0),
            'saldo_akhir' => max($hak + $eligible - $terpakai, 0),
        ];
    }


    /**
     * Sisa mentah tahun ($tahunBaru - 1) = Hak - Terpakai (tanpa bawaan,
     * tanpa dipotong 6). Mengembalikan 0 jika dompet tahun lalu belum ada.
     */
    public function carryDariSaldoBiasa(int $pegawaiId, int $tahunBaru): int
    {
        $saldoTahunLalu = $this->ringkasanTahun($pegawaiId, $tahunBaru - 1);


        return $saldoTahunLalu ? (int) $saldoTahunLalu['sisa_hak'] : 0;
    }
}
