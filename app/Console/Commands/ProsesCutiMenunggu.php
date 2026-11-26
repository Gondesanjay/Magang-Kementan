<?php

namespace App\Console\Commands;

use App\Models\HariLibur;
use App\Models\Notifikasi;
use App\Models\Pegawai;
use App\Models\PengajuanCuti;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// Command harian: pengingat bertahap untuk pengajuan yang menunggu.
//   Atasan   : hari kerja ke-2 (susulan: ke-1) dan ke-4
//   Admin HR : bersama pengingat kedua, lalu diulang tiap 5 hari kerja
// Tidak ada kedaluwarsa otomatis. Pengajuan hanya berakhir lewat keputusan atasan,
// pembatalan pemohon, atau penangguhan Kepala Biro.
// Hitungan dimulai dari updated_at (diajukan / terakhir naik level), jadi dihitung
// ulang di tiap level.
//
// php artisan cuti:proses-menunggu [--dry-run]
class ProsesCutiMenunggu extends Command
{
    protected $signature = 'cuti:proses-menunggu
        {--dry-run : Hanya tampilkan yang akan dilakukan, tanpa mengubah data}';

    protected $description = 'Kirim pengingat bertahap ke atasan dan Admin HR untuk pengajuan cuti yang menunggu';

    private const HARI_PENGINGAT_1 = 2;
    private const HARI_PENGINGAT_SUSULAN = 1;
    private const HARI_PENGINGAT_2 = 4;
    private const HARI_ULANG_MENGGANTUNG = 5;

    private const ROLE_PER_STATUS = [
        'menunggu_l1' => 2,
        'menunggu_l2' => 3,
        'menunggu_l3' => 4,
        'menunggu_l4' => 6,
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $today = Carbon::today();
        $hariLibur = HariLibur::pluck('tanggal')
            ->map(fn($t) => Carbon::parse($t)->toDateString())
            ->all();

        $jumlah = 0;
        $daftar = PengajuanCuti::with('pegawai')
            ->whereIn('status', array_keys(self::ROLE_PER_STATUS))
            ->get();

        foreach ($daftar as $p) {
            if ($p->pegawai && $this->kirimPengingat($p, $today, $hariLibur, $dryRun)) {
                $jumlah++;
            }
        }

        $awalan = $dryRun ? '[DRY-RUN] ' : '';
        $this->info("{$awalan}Selesai. Pengingat terkirim: {$jumlah}.");

        return self::SUCCESS;
    }

    private function kirimPengingat(PengajuanCuti $p, Carbon $today, array $hariLibur, bool $dryRun): bool
    {
        $basis = Carbon::parse($p->updated_at ?? $p->created_at);
        $hari = $this->hariKerjaTerlewat($basis, $today, $hariLibur);

        $sudah = (int) $p->jumlah_pengingat;
        $terakhir = $p->pengingat_terakhir_at ? Carbon::parse($p->pengingat_terakhir_at) : null;

        // Pengajuan sudah bergerak sejak pengingat terakhir: hitung dari awal
        if (!$terakhir || $terakhir->lt($basis)) {
            $sudah = 0;
            $terakhir = null;
        }

        $batasPertama = $p->isSusulan() ? self::HARI_PENGINGAT_SUSULAN : self::HARI_PENGINGAT_1;

        // Tahap 1 dan 2 = pengingat bertahap, 3 = ulangan "Menggantung" ke Admin HR
        $tahap = null;
        if ($hari >= self::HARI_PENGINGAT_2 && $sudah < 2) {
            $tahap = 2;
        } elseif ($hari >= $batasPertama && $sudah < 1) {
            $tahap = 1;
        } elseif (
            $sudah >= 2 && $terakhir
            && $this->hariKerjaTerlewat($terakhir, $today, $hariLibur) >= self::HARI_ULANG_MENGGANTUNG
        ) {
            $tahap = 3;
        }

        if ($tahap === null) {
            return false;
        }

        $nama = $p->pegawai->nama ?? 'Pegawai';
        $this->line("• #{$p->id} {$nama} ({$p->status}) {$hari} hari kerja tanpa respon -> tahap {$tahap}");

        if ($dryRun) {
            return true;
        }

        // Klaim dulu, baru kirim. Gagal klaim = pengajuan sudah bergerak / diproses lain.
        // Query builder dipakai agar updated_at tidak ikut berubah.
        $klaim = DB::table($p->getTable())
            ->where('id', $p->id)
            ->where('status', $p->status)
            ->where('updated_at', $p->updated_at)
            ->update([
                'jumlah_pengingat' => min($tahap, 2),
                'pengingat_terakhir_at' => now(),
            ]);

        if (!$klaim) {
            return false;
        }

        // Awalan pesan sengaja dipertahankan; penanda susulan ditaruh di akhir.
        $ket = $p->isSusulan() ? ' Ini pengajuan susulan, cuti sudah dijalani.' : '';
        $rincian = 'Pengajuan cuti ' . $nama . ' (' . $this->tgl($p->tanggal_mulai) . ') sudah '
            . $hari . ' hari kerja ';

        if ($tahap <= 2) {
            foreach ($this->penerimaAtasan($p) as $atasan) {
                Notifikasi::create([
                    'pegawai_id'   => $atasan->id,
                    'pengajuan_id' => $p->id,
                    'judul'        => $tahap === 2 ? 'Pengingat Kedua: Pengajuan Cuti' : 'Pengingat: Pengajuan Cuti',
                    'pesan'        => $rincian . 'menunggu persetujuan Anda.' . $ket,
                    'tautan'       => route('atasan.approval', [], false),
                    'is_read'      => false,
                ]);
            }
        }

        if ($tahap >= 2) {
            foreach (Pegawai::penerimaApproval(5) as $hr) {
                Notifikasi::create([
                    'pegawai_id'   => $hr->id,
                    'pengajuan_id' => $p->id,
                    'judul'        => 'Pengajuan Cuti Menggantung',
                    'pesan'        => $rincian . 'belum diproses (' . $p->status . ').' . $ket,
                    'tautan'       => route('atasan.approval', [], false),
                    'is_read'      => false,
                ]);
            }
        }

        return true;
    }

    // Atasan yang sedang ditunggu: semua pejabat aktif pada level itu (kecuali pemohon)
    private function penerimaAtasan(PengajuanCuti $p): Collection
    {
        $pg = $p->pegawai;
        $role = self::ROLE_PER_STATUS[$p->status] ?? null;

        if (!$pg || $role === null) {
            return collect();
        }

        return Pegawai::penerimaApproval($role, $pg->tim_kerja, $pg->kelompok_substansi)
            ->reject(fn($a) => (int) $a->id === (int) $pg->id)
            ->values();
    }

    private function hariKerjaTerlewat(Carbon $dari, Carbon $sampai, array $hariLibur): int
    {
        $n = 0;
        $c = $dari->copy()->startOfDay()->addDay();
        $end = $sampai->copy()->startOfDay();

        while ($c->lte($end)) {
            if (!$c->isWeekend() && !in_array($c->toDateString(), $hariLibur, true)) {
                $n++;
            }
            $c->addDay();
        }

        return $n;
    }

    private function tgl(\DateTimeInterface|string $tanggal): string
    {
        return Carbon::parse($tanggal)->format('d/m/Y');
    }
}
