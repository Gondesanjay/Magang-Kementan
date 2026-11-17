<?php

namespace Database\Seeders;

use App\Models\ApprovalLog;
use App\Models\CutiDitangguhkan;
use App\Models\Notifikasi;
use App\Models\Pegawai;
use App\Models\PengajuanCuti;
use App\Models\SaldoCuti;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Menghapus semua data dummy dari PerformanceTestSeeder.
 *
 *   php artisan db:seed --class=PerformanceTestCleanup
 *
 * Yang dihapus: pegawai ber-nama "UJI-%" beserta pengajuan, saldo, catatan
 * penangguhan, log approval, dan notifikasinya. Data asli tidak disentuh.
 */
class PerformanceTestCleanup extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->error('Tidak dijalankan di production.');

            return;
        }

        DB::disableQueryLog();

        $idPegawai = Pegawai::where('nama', 'like', 'UJI-%')->pluck('id')->all();

        if (empty($idPegawai)) {
            $this->command->info('Tidak ada data dummy.');

            return;
        }

        foreach (array_chunk($idPegawai, 500) as $kelompok) {
            $idPengajuan = PengajuanCuti::whereIn('pegawai_id', $kelompok)->pluck('id')->all();

            foreach (array_chunk($idPengajuan, 1000) as $paket) {
                ApprovalLog::whereIn('pengajuan_id', $paket)->delete();
                CutiDitangguhkan::whereIn('pengajuan_asal_id', $paket)->delete();
                PengajuanCuti::whereIn('id', $paket)->delete();
            }

            CutiDitangguhkan::whereIn('pegawai_id', $kelompok)->delete();
            SaldoCuti::whereIn('pegawai_id', $kelompok)->delete();
            Notifikasi::whereIn('pegawai_id', $kelompok)->delete();
            Pegawai::whereIn('id', $kelompok)->delete();
        }

        $this->command->info('Data dummy sudah dihapus (' . count($idPegawai) . ' pegawai).');
    }
}
