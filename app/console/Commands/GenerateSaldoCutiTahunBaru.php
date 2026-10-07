<?php

namespace App\Console\Commands;

use App\Models\CutiDitangguhkan;
use App\Models\Pegawai;
use App\Models\PengajuanCuti;
use App\Models\SaldoCuti;
use App\Services\CarryOverSaldoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// ================= COMMAND: GENERATE SALDO CUTI TAHUN BARU =================
// Satu-satunya tempat logika carry-over dijalankan (scheduler 1 Januari
// dan tombol manual Admin HR lewat AdminController::generateSaldoManual()).
//
// Dua sumber carry-over, digabung:
//   SUMBER 1 - sisa saldo biasa:
//     Jalur Normal : min(6, saldo akhir tahun lalu)
//     Jalur Khusus : sisa hak tahun lalu dibawa penuh, HANYA jika tidak ada
//                    cuti terpakai sama sekali di 2 tahun berturut-turut.
//     (logikanya ada di App\Services\CarryOverSaldoService, dipakai juga
//      oleh pratinjau di AdminController supaya hasilnya selalu sama)
//   SUMBER 2 - cuti_ditangguhkans: selalu dibawa penuh.
//   BATAS ATAS : hak + carry maksimal 24 hari.
//
// REVISI:
//   1. Aman diulang: baris penangguhan yang sudah ditandai untuk tahun baru
//      ini ikut dihitung lagi, jadi run kedua menghasilkan angka yang sama.
//   2. Hak (kuota_tahunan) yang sudah ada di database dipertahankan, tidak
//      dipaksa kembali ke 12 (melindungi hasil impor / edit manual).
//   3. Carry-over TIDAK lagi membaca kolom `sisa` (maknanya berbeda antara
//      command dan updateSaldo/impor). Dihitung dari hak, carry, dan terpakai
//      efektif (termasuk koreksi_saldo), sama dengan tampilan layar.
//   4. Seluruh proses dalam satu transaksi.
//   5. Tahun dibatasi tahun berjalan / tahun depan. Gunakan --force untuk
//      testing di luar batas itu.
//   6. Logika carry-over saldo biasa dipindah ke CarryOverSaldoService
//      (perilaku tidak berubah).
class GenerateSaldoCutiTahunBaru extends Command
{
    protected $signature = 'saldo-cuti:generate-tahun-baru {--tahun=} {--force}';

    protected $description = 'Generate saldo cuti tahunan untuk semua pegawai di tahun baru (carry-over saldo biasa + penangguhan resmi, batas total 24 hari). Aman diulang.';

    private const ROLE_ID_ADMIN = 5;
    private const KUOTA_TAHUNAN_DEFAULT = 12;
    private const BATAS_CARRY_NORMAL = 6;
    private const BATAS_ATAS_TOTAL_HAK = 24;

    public function handle(): int
    {
        $tahunBaru = (int) ($this->option('tahun') ?? date('Y'));
        $tahunLalu = $tahunBaru - 1;
        $sekarang = (int) date('Y');

        if (!$this->option('force') && !in_array($tahunBaru, [$sekarang, $sekarang + 1], true)) {
            $this->error("Tahun {$tahunBaru} ditolak. Hanya {$sekarang} atau " . ($sekarang + 1) . " yang diizinkan (gunakan --force untuk testing).");

            return self::FAILURE;
        }

        $pegawaiSemua = Pegawai::where('role_id', '!=', self::ROLE_ID_ADMIN)->get();
        $carryOver = app(CarryOverSaldoService::class);

        $jumlahDiproses = 0;

        DB::transaction(function () use ($pegawaiSemua, $tahunBaru, $tahunLalu, $carryOver, &$jumlahDiproses) {
            foreach ($pegawaiSemua as $pegawai) {
                $saldoAda = SaldoCuti::where('pegawai_id', $pegawai->id)
                    ->where('tahun', $tahunBaru)
                    ->first();

                // SUMBER 1
                $carryDariSaldoBiasa = $carryOver->carryDariSaldoBiasa($pegawai->id, $tahunBaru);

                // SUMBER 2 (termasuk baris yang sudah ditandai untuk tahun
                // baru ini, supaya run ulang tidak kehilangan hari tersebut)
                $barisDitangguhkan = CutiDitangguhkan::where('pegawai_id', $pegawai->id)
                    ->where('tahun_asal', $tahunLalu)
                    ->where(function ($q) use ($tahunBaru) {
                        $q->where('status_pakai', 'belum_dipakai')
                            ->orWhere('tahun_penggunaan', $tahunBaru);
                    })
                    ->get();
                $carryDariPenangguhanResmi = (int) $barisDitangguhkan->sum('jumlah_hari');

                $totalCarryForward = $carryDariSaldoBiasa + $carryDariPenangguhanResmi;

                // Pertahankan hak yang sudah ada (impor / edit manual)
                $kuotaTahunan = (int) ($saldoAda->kuota_tahunan ?? self::KUOTA_TAHUNAN_DEFAULT);
                $totalHakTersedia = min($totalCarryForward + $kuotaTahunan, self::BATAS_ATAS_TOTAL_HAK);

                $terpakaiMurni = (int) PengajuanCuti::where('pegawai_id', $pegawai->id)
                    ->where('jenis_cuti', 'Cuti Tahunan')
                    ->where('status', 'disetujui')
                    ->whereYear('tanggal_mulai', $tahunBaru)
                    ->sum('jumlah_hari');

                $terpakaiEfektif = SaldoCuti::terpakaiEfektif($pegawai->id, $tahunBaru, $saldoAda);

                // koreksi_saldo sengaja tidak ditulis: tetap seperti adanya.
                SaldoCuti::updateOrCreate(
                    ['pegawai_id' => $pegawai->id, 'tahun' => $tahunBaru],
                    [
                        'kuota_tahunan' => $kuotaTahunan,
                        'carry_forward_normal' => $totalCarryForward,
                        'sisa' => max(0, $totalHakTersedia - $terpakaiEfektif),
                        'terpakai' => $terpakaiMurni,
                    ]
                );

                foreach ($barisDitangguhkan as $baris) {
                    if ($baris->status_pakai !== 'sudah_dipakai' || (int) $baris->tahun_penggunaan !== $tahunBaru) {
                        $baris->update([
                            'status_pakai' => 'sudah_dipakai',
                            'tahun_penggunaan' => $tahunBaru,
                        ]);
                    }
                }

                $jumlahDiproses++;
            }
        });

        $this->info("Saldo cuti tahun {$tahunBaru} berhasil digenerate untuk {$jumlahDiproses} pegawai.");

        return self::SUCCESS;
    }
}
// ================= END COMMAND =================