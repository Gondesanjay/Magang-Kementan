<?php

namespace App\Console\Commands;

use App\Models\HariLibur;
use App\Models\Notifikasi;
use App\Models\Pegawai;
use App\Models\PengajuanCuti;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// Command harian:
// 1. Kedaluwarsa otomatis jika tanggal_mulai sudah lewat
// 2. Pengingat bertahap (2 & 4 hari kerja) ke atasan + Admin HR
// 3. Bersihkan notifikasi lama saat kedaluwarsa (aman: cek pengajuan lain dulu)
//
// php artisan cuti:proses-menunggu [--dry-run] [--bersihkan-lama]
class ProsesCutiMenunggu extends Command
{
    protected $signature = 'cuti:proses-menunggu
        {--dry-run : Hanya tampilkan yang akan dilakukan, tanpa mengubah data}
        {--bersihkan-lama : Bersihkan notifikasi lama untuk pengajuan yang sudah kedaluwarsa}';

    protected $description = 'Kirim pengingat ke atasan & kedaluwarsakan pengajuan cuti yang tidak diproses sampai tanggal mulai terlewati';

    private const HARI_PENGINGAT_1 = 2;
    private const HARI_PENGINGAT_2 = 4;

    private const STATUS_MENUNGGU = ['menunggu_l1', 'menunggu_l2', 'menunggu_l3', 'menunggu_l4'];

    private const JUDUL_PENGINGAT = ['Pengingat: Pengajuan Cuti', 'Pengingat Kedua: Pengajuan Cuti'];
    private const JUDUL_NOTIF_AWAL = ['Pengajuan Cuti Baru', 'Revisi Pengajuan Cuti'];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($this->option('bersihkan-lama')) {
            return $this->bersihkanLama($dryRun);
        }

        $today = Carbon::today();
        $hariLibur = HariLibur::pluck('tanggal')
            ->map(fn($t) => Carbon::parse($t)->toDateString())
            ->all();

        $daftar = PengajuanCuti::with('pegawai')
            ->whereIn('status', self::STATUS_MENUNGGU)
            ->get();

        $jumlahKedaluwarsa = 0;
        $jumlahPengingat = 0;

        foreach ($daftar as $p) {
            $mulai = Carbon::parse($p->tanggal_mulai)->startOfDay();

            if ($mulai->lt($today)) {
                if ($this->kedaluwarsakan($p, $dryRun)) {
                    $jumlahKedaluwarsa++;
                }
                continue;
            }

            if ($this->kirimPengingat($p, $today, $hariLibur, $dryRun)) {
                $jumlahPengingat++;
            }
        }

        $awalan = $dryRun ? '[DRY-RUN] ' : '';
        $this->info("{$awalan}Selesai. Kedaluwarsa: {$jumlahKedaluwarsa}, pengingat terkirim: {$jumlahPengingat}.");

        return self::SUCCESS;
    }

    private function kedaluwarsakan(PengajuanCuti $p, bool $dryRun): bool
    {
        $nama = $p->pegawai->nama ?? 'Pegawai';
        $this->line("• #{$p->id} {$nama} ({$p->status}) mulai {$p->tanggal_mulai} -> KEDALUWARSA");

        if ($dryRun) {
            return true;
        }

        $statusLama = $p->status;
        $penerima = $this->penerimaAtasan($p);

        $keterangan = trim((string) $p->keterangan);
        $keterangan = ($keterangan === '' ? '-' : $keterangan)
            . ' | [KEDALUWARSA: Tidak diproses atasan sampai tanggal mulai cuti terlewati]';

        $berubah = PengajuanCuti::where('id', $p->id)
            ->where('status', $statusLama)
            ->update([
                'status' => 'kedaluwarsa',
                'keterangan' => $keterangan,
            ]);

        if (!$berubah) {
            return false;
        }

        // Bersihkan notifikasi lama sebelum membuat notifikasi kedaluwarsa baru
        $this->bersihkanNotifikasiLama($p, $statusLama, $penerima, $dryRun);

        if ($p->pegawai) {
            Notifikasi::create([
                'pegawai_id' => $p->pegawai_id,
                'judul' => 'Pengajuan Cuti Kedaluwarsa',
                'pesan' => 'Pengajuan cuti Anda tanggal ' . $this->tgl($p->tanggal_mulai)
                    . ' tidak diproses atasan sampai tanggal mulai terlewati. Silakan ajukan ulang bila masih diperlukan.',
                'tautan' => route('karyawan.riwayat', [], false),
                'is_read' => false,
            ]);
        }

        foreach ($penerima as $atasan) {
            Notifikasi::create([
                'pegawai_id' => $atasan->id,
                'judul' => 'Pengajuan Cuti Kedaluwarsa',
                'pesan' => 'Pengajuan cuti ' . $nama . ' (' . $this->tgl($p->tanggal_mulai)
                    . ') kedaluwarsa karena tidak diproses sampai tanggal mulai terlewati.',
                'tautan' => route('atasan.approval', [], false),
                'is_read' => false,
            ]);
        }

        return true;
    }

    private function kirimPengingat(PengajuanCuti $p, Carbon $today, array $hariLibur, bool $dryRun): bool
    {
        $basis = Carbon::parse($p->updated_at ?? $p->created_at);
        $hari = $this->hariKerjaTerlewat($basis, $today, $hariLibur);

        $sudah = (int) $p->jumlah_pengingat;
        if (
            !$p->pengingat_terakhir_at
            || Carbon::parse($p->pengingat_terakhir_at)->lt($basis)
        ) {
            $sudah = 0;
        }

        $tahap = null;
        if ($hari >= self::HARI_PENGINGAT_2 && $sudah < 2) {
            $tahap = 2;
        } elseif ($hari >= self::HARI_PENGINGAT_1 && $sudah < 1) {
            $tahap = 1;
        }

        if ($tahap === null) {
            return false;
        }

        $nama = $p->pegawai->nama ?? 'Pegawai';
        $this->line("• #{$p->id} {$nama} ({$p->status}) {$hari} hari kerja tanpa respon -> pengingat {$tahap}");

        if ($dryRun) {
            return true;
        }

        $penerima = $this->penerimaAtasan($p);

        foreach ($penerima as $atasan) {
            Notifikasi::create([
                'pegawai_id' => $atasan->id,
                'judul' => $tahap === 2 ? 'Pengingat Kedua: Pengajuan Cuti' : 'Pengingat: Pengajuan Cuti',
                'pesan' => 'Pengajuan cuti ' . $nama . ' (' . $this->tgl($p->tanggal_mulai)
                    . ') sudah ' . $hari . ' hari kerja menunggu persetujuan Anda.',
                'tautan' => route('atasan.approval', [], false),
                'is_read' => false,
            ]);
        }

        if ($tahap === 2) {
            foreach (Pegawai::where('role_id', 5)->get() as $hr) {
                Notifikasi::create([
                    'pegawai_id' => $hr->id,
                    'judul' => 'Pengajuan Cuti Menggantung',
                    'pesan' => 'Pengajuan cuti ' . $nama . ' (' . $this->tgl($p->tanggal_mulai)
                        . ') sudah ' . $hari . ' hari kerja belum diproses (' . $p->status . ').',
                    // PERBAIKAN: sebelumnya admin.rekap, sekarang ke Monitoring/Approval
                    'tautan' => route('atasan.approval', [], false),
                    'is_read' => false,
                ]);
            }
        }

        // Query builder agar updated_at tidak ikut berubah
        DB::table($p->getTable())
            ->where('id', $p->id)
            ->where('status', $p->status)
            ->update([
                'jumlah_pengingat' => $tahap,
                'pengingat_terakhir_at' => now(),
            ]);

        return true;
    }

    // Hapus notifikasi lama yang sudah tidak berlaku.
    // Pengingat: cocokkan nama + tanggal. Notif awal: hanya hapus jika tidak ada pengajuan lain di level sama.
    private function bersihkanNotifikasiLama(PengajuanCuti $p, string $statusLama, Collection $penerima, bool $dryRun): int
    {
        $pg = $p->pegawai;
        if (!$pg) {
            return 0;
        }

        $nama = $this->escapeLike((string) $pg->nama);
        $tgl = $this->tgl($p->tanggal_mulai);
        $idAtasan = $penerima->pluck('id')->all();
        $idHr = Pegawai::where('role_id', 5)->pluck('id')->all();

        $adaLainDiLevelSama = PengajuanCuti::where('pegawai_id', $p->pegawai_id)
            ->where('id', '!=', $p->id)
            ->where('status', $statusLama)
            ->exists();

        $adaLainTanggalSama = PengajuanCuti::where('pegawai_id', $p->pegawai_id)
            ->where('id', '!=', $p->id)
            ->whereIn('status', self::STATUS_MENUNGGU)
            ->whereDate('tanggal_mulai', Carbon::parse($p->tanggal_mulai)->toDateString())
            ->exists();

        $jumlah = 0;

        // 1) Pengingat (nama + tanggal)
        if (!$adaLainTanggalSama) {
            $polaPengingat = "Pengajuan cuti {$nama} ({$tgl}) %";

            if (!empty($idAtasan)) {
                $jumlah += $this->hapus(
                    Notifikasi::whereIn('pegawai_id', $idAtasan)
                        ->whereIn('judul', self::JUDUL_PENGINGAT)
                        ->where('pesan', 'like', $polaPengingat),
                    $dryRun
                );
            }

            if (!empty($idHr)) {
                $jumlah += $this->hapus(
                    Notifikasi::whereIn('pegawai_id', $idHr)
                        ->where('judul', 'Pengajuan Cuti Menggantung')
                        ->where('pesan', 'like', $polaPengingat),
                    $dryRun
                );
            }
        }

        // 2) "Pengajuan Cuti Baru" / "Revisi" (hanya nama)
        if (!$adaLainDiLevelSama && !empty($idAtasan)) {
            $jumlah += $this->hapus(
                Notifikasi::whereIn('pegawai_id', $idAtasan)
                    ->whereIn('judul', self::JUDUL_NOTIF_AWAL)
                    ->where('pesan', 'like', "%dari {$nama} yang butuh persetujuan Anda.%"),
                $dryRun
            );
        }

        return $jumlah;
    }

    // Mode --bersihkan-lama: bersihkan notifikasi untuk pengajuan yang sudah kedaluwarsa sebelumnya
    private function bersihkanLama(bool $dryRun): int
    {
        $total = 0;
        $daftar = PengajuanCuti::with('pegawai')->where('status', 'kedaluwarsa')->get();

        foreach ($daftar as $p) {
            $statusLama = match ((int) $p->level_saat_ini) {
                1 => 'menunggu_l1',
                2 => 'menunggu_l2',
                3 => 'menunggu_l3',
                4 => 'menunggu_l4',
                default => null,
            };

            if ($statusLama === null) {
                $this->line("• #{$p->id} dilewati (level tidak dikenali)");
                continue;
            }

            $penerima = $this->penerimaAtasan($p, $statusLama);
            $jumlah = $this->bersihkanNotifikasiLama($p, $statusLama, $penerima, $dryRun);
            $nama = $p->pegawai->nama ?? 'Pegawai';
            $this->line("• #{$p->id} {$nama} ({$statusLama}) -> {$jumlah} notifikasi lama");
            $total += $jumlah;
        }

        $awalan = $dryRun ? '[DRY-RUN] ' : '';
        $this->info("{$awalan}Selesai. Notifikasi lama yang dibersihkan: {$total}.");

        return self::SUCCESS;
    }

    private function hapus(Builder $query, bool $dryRun): int
    {
        return $dryRun ? (int) $query->count() : (int) $query->delete();
    }

    private function escapeLike(string $teks): string
    {
        return addcslashes($teks, '%_\\');
    }

    // Atasan yang sedang ditunggu (L1=role2 tim sama, L2=role3 kelompok sama, L3=role4, L4=role6)
    private function penerimaAtasan(PengajuanCuti $p, ?string $status = null): Collection
    {
        $pg = $p->pegawai;
        if (!$pg) {
            return collect();
        }

        $status ??= $p->status;

        $hasil = match ($status) {
            'menunggu_l1' => (empty($pg->tim_kerja) || $pg->tim_kerja === '-')
                ? collect()
                : Pegawai::where('role_id', 2)->where('tim_kerja', $pg->tim_kerja)->get(),
            'menunggu_l2' => Pegawai::where('role_id', 3)
                ->where('kelompok_substansi', $pg->kelompok_substansi)->get(),
            'menunggu_l3' => Pegawai::where('role_id', 4)->get(),
            'menunggu_l4' => Pegawai::where('role_id', 6)->get(),
            default => collect(),
        };

        return $hasil->filter(fn($a) => $a->id !== $pg->id)->values();
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
