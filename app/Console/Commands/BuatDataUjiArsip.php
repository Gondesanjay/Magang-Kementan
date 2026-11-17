<?php


namespace App\Console\Commands;


use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;


/**
 * KHUSUS LOKAL. Membuat pengajuan palsu bertanggal 2019 untuk menguji cuti:arsipkan.
 * Semua data uji ditandai keterangan = 'DATA UJI ARSIP' supaya mudah dibersihkan.
 *
 *   php artisan cuti:data-uji-arsip          -> buat data uji
 *   php artisan cuti:data-uji-arsip --hapus  -> hapus semua data uji (tabel utama + arsip)
 */
class BuatDataUjiArsip extends Command
{
    protected $signature = 'cuti:data-uji-arsip {--hapus : Hapus data uji}';


    protected $description = 'Buat atau hapus data uji untuk fitur arsip (hanya lingkungan lokal)';


    private const TANDA = 'DATA UJI ARSIP';


    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Ditolak: perintah ini tidak boleh dijalankan di production.');
            return self::FAILURE;
        }


        return $this->option('hapus') ? $this->hapus() : $this->buat();
    }


    private function buat(): int
    {
        $contoh = DB::table('pengajuan_cutis')->orderBy('id')->first();
        if (! $contoh) {
            $this->error('Butuh minimal 1 pengajuan di tabel pengajuan_cutis sebagai contoh struktur.');
            return self::FAILURE;
        }


        $pegawaiId = $contoh->pegawai_id;


        // [status, tanggal, punya_penangguhan, keterangan_skenario]
        $skenario = [
            ['disetujui',               '2019-03-10', false, 'harus pindah'],
            ['disetujui',               '2019-07-01', false, 'harus pindah'],
            ['ditolak',                 '2019-08-15', false, 'harus pindah'],
            ['dibatalkan_reguler',      '2020-01-05', false, 'harus pindah'],
            ['dibatalkan_ditangguhkan', '2020-02-10', false, 'harus pindah'],
            ['menunggu_l1',             '2019-05-05', false, 'TIDAK pindah (belum final)'],
            ['disetujui',               '2019-11-20', true,  'TIDAK pindah (punya penangguhan)'],
            ['disetujui',               now()->subYear()->toDateString(), false, 'TIDAK pindah (terlalu baru)'],
        ];


        DB::transaction(function () use ($contoh, $pegawaiId, $skenario) {
            foreach ($skenario as [$status, $tanggal, $tangguh, $catatan]) {
                $baris = (array) $contoh;
                unset($baris['id']);


                $baris['status'] = $status;
                $baris['tanggal_mulai'] = $tanggal;
                $baris['tanggal_selesai'] = $tanggal;
                $baris['jumlah_hari'] = 1;
                $baris['keterangan'] = self::TANDA;
                $baris['created_at'] = $tanggal . ' 08:00:00';
                $baris['updated_at'] = $tanggal . ' 08:00:00';


                $id = DB::table('pengajuan_cutis')->insertGetId($baris);


                DB::table('approval_logs')->insert([
                    'pengajuan_id' => $id,
                    'approver_id' => $pegawaiId,
                    'level_approval' => 1,
                    'keputusan' => 'setuju',
                    'catatan' => self::TANDA,
                    'tanggal_keputusan' => $tanggal . ' 09:00:00',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);


                if ($status === 'disetujui') {
                    DB::table('surat_persetujuans')->insert([
                        'pengajuan_id' => $id,
                        'nomor_surat' => 'UJI/' . $id,
                        'path_file_pdf' => 'uji/surat-' . $id . '.pdf',
                        'tanggal_terbit' => $tanggal . ' 10:00:00',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    DB::table('bukti_pengajuans')->insert([
                        'pengajuan_id' => $id,
                        'path_file_pdf' => 'uji/bukti-' . $id . '.pdf',
                        'tanggal_terbit' => $tanggal . ' 10:00:00',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }


                if ($tangguh) {
                    DB::table('cuti_ditangguhkans')->insert([
                        'pegawai_id' => $pegawaiId,
                        'pengajuan_asal_id' => $id,
                        'jumlah_hari' => 1,
                        'tahun_asal' => (int) substr($tanggal, 0, 4),
                        'status_pakai' => 'belum_dipakai',
                        'alasan_penangguhan' => self::TANDA,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }


                $this->line("#{$id} {$status} {$tanggal}  -> {$catatan}");
            }
        });


        $this->info('Data uji dibuat. Jalankan: php artisan cuti:arsipkan');
        return self::SUCCESS;
    }


    private function hapus(): int
    {
        $ids = DB::table('pengajuan_cutis')->where('keterangan', self::TANDA)->pluck('id')->all();
        $hapusUtama = DB::table('pengajuan_cutis')->where('keterangan', self::TANDA)->delete();


        $hapusArsip = 0;
        if (Schema::hasTable('pengajuan_cutis_arsip')) {
            $idArsip = DB::table('pengajuan_cutis_arsip')->where('keterangan', self::TANDA)->pluck('id')->all();
            foreach (['approval_logs_arsip', 'bukti_pengajuans_arsip', 'surat_persetujuans_arsip'] as $t) {
                if (Schema::hasTable($t) && $idArsip) {
                    DB::table($t)->whereIn('pengajuan_id', $idArsip)->delete();
                }
            }
            $hapusArsip = DB::table('pengajuan_cutis_arsip')->where('keterangan', self::TANDA)->delete();
        }


        $this->info("Dihapus: {$hapusUtama} dari tabel utama, {$hapusArsip} dari arsip.");
        return self::SUCCESS;
    }
}

