<?php


namespace App\Console\Commands;


use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;


/**
 * Memindahkan pengajuan cuti lama (status final, tanggal_selesai lebih dari N tahun)
 * beserta approval_logs, bukti_pengajuans, dan surat_persetujuans ke tabel *_arsip.
 *
 * Default = DRY-RUN (hanya melapor). Pindah sungguhan: --jalankan
 *
 * Aturan keamanan:
 * - Hanya status final yang boleh diarsip.
 * - Pengajuan yang punya baris di cuti_ditangguhkans TIDAK diarsip
 *   (relasinya CASCADE, kalau dihapus data penangguhannya ikut hilang).
 * - saldo_cuti dan log_perubahan_saldos tidak disentuh.
 * - Per batch dalam satu transaksi: salin -> bandingkan jumlah -> hapus.
 *   Jumlah tidak cocok = rollback seluruh batch dan berhenti.
 */
class ArsipkanCuti extends Command
{
    protected $signature = 'cuti:arsipkan
                            {--jalankan : Pindahkan data sungguhan (tanpa ini hanya dry-run)}
                            {--tahun= : Batas umur data dalam tahun (bawaan: config leave.arsip_tahun, atau 5; minimal 5)}
                            {--sampai= : [UJI COBA, hanya APP_ENV=local] Batas tanggal manual YYYY-MM-DD, mengabaikan --tahun}
                            {--tanpa-konfirmasi : Lewati pertanyaan yes/no (untuk jadwal otomatis, hanya bersama --jalankan)}
                            {--batch=100 : Jumlah pengajuan per transaksi}';


    protected $description = 'Arsipkan pengajuan cuti final yang selesai lebih dari N tahun lalu';


    // GEMBOK: umur minimum data yang boleh diarsip (tahun). Opsi --tahun hanya
    // boleh MEMPERBESAR batas ini, tidak boleh menurunkannya.
    private const MIN_TAHUN_ARSIP = 5;


    private const STATUS_FINAL = [
        'disetujui',
        'ditolak',
        'dibatalkan_reguler',
        'dibatalkan_ditangguhkan',
        'ditangguhkan',
        'kedaluwarsa', // data lama; fitur kedaluwarsa sudah dihapus
    ];


    // [tabel anak, kolom yang menunjuk ke pengajuan_cutis]
    private const ANAK = [
        ['approval_logs', 'pengajuan_id'],
        ['bukti_pengajuans', 'pengajuan_id'],
        ['surat_persetujuans', 'pengajuan_id'],
    ];


    public function handle(): int
    {
        $tahun = (int) ($this->option('tahun') ?: config('leave.arsip_tahun', 5));
        $batch = max(1, (int) $this->option('batch'));
        $jalankan = (bool) $this->option('jalankan');


        if ($tahun < self::MIN_TAHUN_ARSIP) {
            $this->error('Ditolak: data baru boleh diarsip jika umurnya minimal ' . self::MIN_TAHUN_ARSIP . ' tahun.');
            return self::FAILURE;
        }


        foreach (['pengajuan_cutis', 'approval_logs', 'bukti_pengajuans', 'surat_persetujuans'] as $t) {
            if (! Schema::hasTable($t . '_arsip')) {
                $this->error("Tabel {$t}_arsip belum ada. Jalankan: php artisan migrate");
                return self::FAILURE;
            }
        }


        $sampai = $this->option('sampai');
        if ($sampai && $this->option('tanpa-konfirmasi')) {
            $this->error('Ditolak: --tanpa-konfirmasi tidak boleh digabung dengan --sampai.');
            return self::FAILURE;
        }
        if ($sampai) {
            if (! app()->environment('local')) {
                $this->error('Ditolak: --sampai hanya boleh dipakai untuk uji coba di lingkungan local (APP_ENV=local).');
                return self::FAILURE;
            }
            if (! \Carbon\Carbon::hasFormat($sampai, 'Y-m-d')) {
                $this->error('Format --sampai harus YYYY-MM-DD, contoh 2027-01-01.');
                return self::FAILURE;
            }
            $batas = $sampai;
            $this->warn("UJI COBA: batas manual, tanggal_selesai sebelum {$batas}.");
        } else {
            $batas = now()->subYears($tahun)->toDateString();
            $this->info("Batas: tanggal_selesai sebelum {$batas} ({$tahun} tahun lalu).");
        }


        $total = $this->kandidat($batas)->count();


        if (! $jalankan) {
            $this->laporDryRun($batas, $total);
            return self::SUCCESS;
        }


        if ($total === 0) {
            $this->info('Tidak ada data yang perlu diarsipkan.');
            return self::SUCCESS;
        }


        if (
            ! $this->option('tanpa-konfirmasi')
            && ! $this->confirm("Pindahkan {$total} pengajuan ke arsip? Pastikan database sudah di-backup.")
        ) {
            $this->warn('Dibatalkan.');
            return self::SUCCESS;
        }


        $dipindah = 0;


        while (true) {
            $ids = $this->kandidat($batas)->orderBy('p.id')->limit($batch)->pluck('p.id')->all();


            if (empty($ids)) {
                break;
            }


            try {
                $this->pindahkanBatch($ids);
            } catch (\Throwable $e) {
                $this->error('GAGAL, batch ini dibatalkan (rollback): ' . $e->getMessage());
                $this->line("Sudah berhasil dipindah sebelum gagal: {$dipindah}");
                return self::FAILURE;
            }


            $dipindah += count($ids);
            $this->line("Dipindah: {$dipindah} / {$total}");
        }


        $this->info("Selesai. {$dipindah} pengajuan dipindah ke arsip.");
        return self::SUCCESS;
    }


    private function kandidat(string $batas)
    {
        return DB::table('pengajuan_cutis as p')
            ->whereIn('p.status', self::STATUS_FINAL)
            ->where('p.tanggal_selesai', '<', $batas)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('cuti_ditangguhkans as c')
                    ->whereColumn('c.pengajuan_asal_id', 'p.id');
            });
    }


    private function laporDryRun(string $batas, int $total): void
    {
        $this->warn('MODE DRY-RUN: tidak ada data yang diubah.');
        $this->line("Pengajuan yang akan dipindah: {$total}");


        if ($total === 0) {
            return;
        }


        $perStatus = $this->kandidat($batas)
            ->select('p.status', DB::raw('COUNT(*) as jumlah'))
            ->groupBy('p.status')->get();
        $this->table(['Status', 'Jumlah'], $perStatus->map(fn($r) => [$r->status, $r->jumlah])->all());


        $perTahun = $this->kandidat($batas)
            ->select(DB::raw('YEAR(p.tanggal_selesai) as tahun'), DB::raw('COUNT(*) as jumlah'))
            ->groupBy(DB::raw('YEAR(p.tanggal_selesai)'))->orderBy('tahun')->get();
        $this->table(['Tahun selesai', 'Jumlah'], $perTahun->map(fn($r) => [$r->tahun, $r->jumlah])->all());


        $ids = $this->kandidat($batas)->pluck('p.id')->all();
        $anak = [];
        foreach (self::ANAK as [$tabel, $kolom]) {
            $anak[] = [$tabel, DB::table($tabel)->whereIn($kolom, $ids)->count()];
        }
        $this->table(['Tabel anak (ikut dipindah)', 'Jumlah baris'], $anak);


        $this->line('Untuk memindahkan sungguhan: php artisan cuti:arsipkan --jalankan');
    }


    private function pindahkanBatch(array $ids): void
    {
        DB::transaction(function () use ($ids) {
            // 1) Salin pengajuan, lalu bandingkan jumlahnya.
            $masuk = $this->salin('pengajuan_cutis', 'pengajuan_cutis_arsip', 'id', $ids);
            if ($masuk !== count($ids)) {
                throw new RuntimeException("Pengajuan tersalin {$masuk}, seharusnya " . count($ids));
            }


            // 2) Salin anak-anaknya, bandingkan jumlah sumber vs hasil salinan.
            foreach (self::ANAK as [$tabel, $kolom]) {
                $diharapkan = DB::table($tabel)->whereIn($kolom, $ids)->count();
                $tersalin = $this->salin($tabel, $tabel . '_arsip', $kolom, $ids);
                if ($tersalin !== $diharapkan) {
                    throw new RuntimeException("{$tabel} tersalin {$tersalin}, seharusnya {$diharapkan}");
                }
            }


            // 2b) Notifikasi tanpa foreign key: dibersihkan manual agar tidak
            // menunjuk ke pengajuan yang sudah pindah ke arsip.
            DB::table('notifikasis')->whereIn('pengajuan_id', $ids)->delete();

            // 3) Baru hapus dari tabel utama (anak ikut terhapus lewat CASCADE).
            $terhapus = DB::table('pengajuan_cutis')->whereIn('id', $ids)->delete();
            if ($terhapus !== count($ids)) {
                throw new RuntimeException("Terhapus {$terhapus}, seharusnya " . count($ids));
            }


            foreach (self::ANAK as [$tabel, $kolom]) {
                if (DB::table($tabel)->whereIn($kolom, $ids)->exists()) {
                    throw new RuntimeException("{$tabel} masih menyisakan baris setelah penghapusan");
                }
            }
        });
    }


    private function salin(string $sumber, string $tujuan, string $kolomKunci, array $ids): int
    {
        $kolom = collect(Schema::getColumnListing($sumber))
            ->map(fn($k) => "`{$k}`")
            ->implode(', ');
        $placeholder = implode(',', array_fill(0, count($ids), '?'));


        return DB::affectingStatement(
            "INSERT INTO `{$tujuan}` ({$kolom}, `diarsipkan_at`)
             SELECT {$kolom}, NOW() FROM `{$sumber}` WHERE `{$kolomKunci}` IN ({$placeholder})",
            $ids
        );
    }
}
