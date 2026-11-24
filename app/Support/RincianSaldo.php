<?php

namespace App\Support;

use App\Models\CutiDitangguhkan;
use App\Models\SaldoCuti;
use Carbon\Carbon;

/**
 * SATU-SATUNYA tempat rumus "Rincian Sisa Cuti" untuk modal Detail Cuti
 * DAN untuk cek saldo saat Kepala Biro menyetujui (MonitoringCutiController::process).
 * Rumus sama dengan DashboardController::hitungSaldoPegawai() dan
 * RekapKuotaDetailController::hitungKuota():
 *   terpakai total = SaldoCuti::terpakaiEfektif() + Tgh tahun itu
 *   sisa hak       = kuota - terpakai total
 *   total tersedia = kuota + bawaan eligible + dikembalikan - terpakai total
 *   dikembalikan   = Tgh tahun asal (tahun - 1)
 *
 * Rumus aritmetika di atas kini ditulis SEKALI di hitungTotal(). CutiController,
 * DashboardController, dan hitung() di bawah memanggil method itu.
 *
 * Tahun yang dipakai untuk tiap pengajuan:
 *   - tahun filter halaman, bila cuti beririsan dengan tahun itu;
 *   - selain itu, tahun tanggal_mulai.
 *
 * [N+1] lengkapi() kini memuat SEMUA bahan hitungan dalam 4 query tetap
 * (saldo, Tgh, koreksi bulanan, cuti disetujui), berapa pun jumlah pegawainya.
 * Terpakai efektif dihitung di memori lewat SaldoCuti::terpakaiEfektifDariBahan(),
 * dengan aturan yang SAMA dengan SaldoCuti::terpakaiEfektif().
 */
class RincianSaldo
{
    public static function tahunUntuk($pengajuan, ?int $tahunFilter = null): int
    {
        $mulai   = Carbon::parse($pengajuan->tanggal_mulai);
        $selesai = Carbon::parse($pengajuan->tanggal_selesai ?? $pengajuan->tanggal_mulai);


        if ($tahunFilter !== null && $mulai->year <= $tahunFilter && $selesai->year >= $tahunFilter) {
            return $tahunFilter;
        }


        return $mulai->year;
    }


    /**
     * Rincian saldo SATU pegawai pada SATU tahun (3 query kecil).
     * Dipakai untuk cek saldo saat persetujuan, supaya angkanya sama persis
     * dengan yang tampil di modal Detail (lihat lengkapi()).
     *
     * Dipanggil sebelum pengajuan berstatus 'disetujui', jadi pengajuan yang
     * sedang dicek belum ikut terhitung sebagai terpakai.
     */
    public static function untukPegawaiTahun(int $pegawaiId, int $tahun): array
    {
        $tahunQuery = [$tahun, $tahun - 1];


        $saldo = SaldoCuti::where('pegawai_id', $pegawaiId)
            ->whereIn('tahun', $tahunQuery)
            ->get()
            ->keyBy(fn($s) => $s->pegawai_id . '-' . $s->tahun);


        $tgh = CutiDitangguhkan::where('pegawai_id', $pegawaiId)
            ->whereIn('tahun_asal', $tahunQuery)
            ->get(['pegawai_id', 'tahun_asal', 'jumlah_hari'])
            ->groupBy(fn($r) => $r->pegawai_id . '-' . $r->tahun_asal)
            ->map(fn($baris) => (int) $baris->sum('jumlah_hari'));


        // Satu pegawai: jalur lama (terpakaiEfektif per pegawai) sudah cukup.
        return self::hitung($pegawaiId, $tahun, $saldo, $tgh, null);
    }


    /**
     * Menempelkan rincian saldo ke $item->pegawai (dan $item->rincian_saldo).
     * Query ter-batch: 4 query untuk seluruh daftar.
     *
     * @param iterable $daftar koleksi PengajuanCuti (relasi 'pegawai' sebaiknya
     *                         sudah di-eager load)
     */
    public static function lengkapi(iterable $daftar, ?int $tahunFilter = null): void
    {
        $daftar = collect($daftar)->filter(fn($item) => $item->pegawai);


        if ($daftar->isEmpty()) {
            return;
        }


        $pasangan = $daftar->map(fn($item) => [
            (int) $item->pegawai_id,
            self::tahunUntuk($item, $tahunFilter),
        ]);


        $pegawaiIds  = $pasangan->pluck(0)->unique()->values()->all();
        $tahunInti   = $pasangan->pluck(1)->unique()->values()->all();
        $tahunQuery  = collect($tahunInti)
            ->flatMap(fn($t) => [$t, $t - 1])
            ->unique()->values()->all();


        $saldo = SaldoCuti::whereIn('pegawai_id', $pegawaiIds)
            ->whereIn('tahun', $tahunQuery)
            ->get()
            ->keyBy(fn($s) => $s->pegawai_id . '-' . $s->tahun);


        $tgh = CutiDitangguhkan::whereIn('pegawai_id', $pegawaiIds)
            ->whereIn('tahun_asal', $tahunQuery)
            ->get(['pegawai_id', 'tahun_asal', 'jumlah_hari'])
            ->groupBy(fn($r) => $r->pegawai_id . '-' . $r->tahun_asal)
            ->map(fn($baris) => (int) $baris->sum('jumlah_hari'));


        // [N+1] Bahan hitung Terpakai untuk SEMUA pegawai & tahun (2 query).
        $bahan = SaldoCuti::muatBahanTerpakai($pegawaiIds, $tahunInti);


        $cache = [];


        foreach ($daftar as $item) {
            $pid   = (int) $item->pegawai_id;
            $tahun = self::tahunUntuk($item, $tahunFilter);
            $kunci = $pid . '-' . $tahun;


            $cache[$kunci] ??= self::hitung($pid, $tahun, $saldo, $tgh, $bahan);
            $info = $cache[$kunci];


            // Model Pegawai bisa dipakai bersama oleh beberapa pengajuan (eager
            // load). Di-clone supaya dua pengajuan beda tahun tidak saling timpa.
            $pegawai = clone $item->pegawai;


            $pegawai->sisa_cuti_tersedia      = $info['total_cuti_tersedia'];
            $pegawai->total_cuti_tersedia     = $info['total_cuti_tersedia'];
            $pegawai->kuota_tahunan           = $info['kuota_tahunan'];
            $pegawai->sisa_tahun_lalu         = $info['sisa_tahun_lalu'];
            $pegawai->sisa_dua_tahun_lalu     = $info['sisa_dua_tahun_lalu'];
            $pegawai->sisa_hak_tahun_berjalan = $info['sisa_hak_tahun_berjalan'];
            $pegawai->saldo_bawaan_eligible   = $info['saldo_bawaan_eligible'];
            $pegawai->cuti_terpakai           = $info['cuti_terpakai'];
            $pegawai->cuti_terpakai_total     = $info['cuti_terpakai_total'];
            $pegawai->cuti_ditangguhkan       = $info['cuti_ditangguhkan'];
            $pegawai->cuti_dikembalikan       = $info['cuti_dikembalikan'];
            $pegawai->tahun_saldo             = $tahun;


            $item->setRelation('pegawai', $pegawai);
            $item->rincian_saldo = $info;
        }
    }


    /**
     * SATU-SATUNYA rumus saldo murni (tanpa query ke database).
     *   terpakai total = terpakai efektif + Tgh tahun ini
     *   sisa hak       = kuota - terpakai total
     *   total tersedia = kuota + bawaan + dikembalikan (Tgh tahun lalu) - terpakai total
     * Hasil tidak pernah negatif.
     */
    public static function hitungTotal(
        int $kuota,
        int $bawaan,
        int $dikembalikan,
        int $terpakai,
        int $tangguh
    ): array {
        $terpakaiTotal = $terpakai + $tangguh;

        return [
            'cuti_terpakai_total'     => $terpakaiTotal,
            'sisa_hak_tahun_berjalan' => max($kuota - $terpakaiTotal, 0),
            'total_cuti_tersedia'     => max(($kuota + $bawaan + $dikembalikan) - $terpakaiTotal, 0),
        ];
    }


    /**
     * @param array|null $bahan hasil SaldoCuti::muatBahanTerpakai(); null =
     *                          pakai SaldoCuti::terpakaiEfektif() (query per pegawai).
     */
    private static function hitung(int $pid, int $tahun, $saldo, $tgh, ?array $bahan = null): array
    {
        $s     = $saldo->get($pid . '-' . $tahun);
        $lalu  = $saldo->get($pid . '-' . ($tahun - 1));


        $kuota            = (int) ($s->kuota_tahunan ?? 12);
        $sisaTahunLalu    = (int) ($s->carry_forward_normal ?? 0);
        $sisaDuaTahunLalu = (int) ($lalu->carry_forward_normal ?? 0);


        $terpakai = $bahan !== null
            ? SaldoCuti::terpakaiEfektifDariBahan($pid, $tahun, $s, $bahan)
            : (int) SaldoCuti::terpakaiEfektif($pid, $tahun, $s);
        $tangguh      = (int) $tgh->get($pid . '-' . $tahun, 0);
        $dikembalikan = (int) $tgh->get($pid . '-' . ($tahun - 1), 0);


        $bawaan = SaldoCuti::hitungBawaan($sisaDuaTahunLalu, $sisaTahunLalu, $kuota);
        $total  = self::hitungTotal($kuota, $bawaan, $dikembalikan, (int) $terpakai, $tangguh);


        return [
            'tahun'                   => $tahun,
            'kuota_tahunan'           => $kuota,
            'sisa_tahun_lalu'         => $sisaTahunLalu,
            'sisa_dua_tahun_lalu'     => $sisaDuaTahunLalu,
            'saldo_bawaan_eligible'   => $bawaan,
            'cuti_terpakai'           => $terpakai,
            'cuti_terpakai_total'     => $total['cuti_terpakai_total'],
            'cuti_ditangguhkan'       => $tangguh,
            'cuti_dikembalikan'       => $dikembalikan,
            'sisa_hak_tahun_berjalan' => $total['sisa_hak_tahun_berjalan'],
            'total_cuti_tersedia'     => $total['total_cuti_tersedia'],
        ];
    }
}