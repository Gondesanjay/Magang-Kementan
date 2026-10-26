<?php

namespace App\Console\Commands;

use App\Models\Pegawai;
use App\Models\SaldoCuti;
use App\Services\CarryOverSaldoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// ================= COMMAND: GENERATE SALDO CUTI TAHUN BARU =================
// Satu-satunya tempat logika carry-over dijalankan (scheduler 1 Januari
// dan tombol manual Admin HR lewat AdminController::generateSaldoManual()).
//
// Satu-satunya sumber carry-over adalah sisa saldo biasa:
//   Bawaan eligible = min(6, sisa tahun lalu), atau 12 jika sisa N-2
//   dan N-1 masing-masing 12. Hak + bawaan dibatasi maksimal 24 hari.
//   BATAS ATAS : hak + carry maksimal 24 hari.
//
// REVISI:
//   1. Aman diulang: run kedua menghasilkan angka yang sama.
//   2. Hak (kuota_tahunan) yang sudah ada di database dipertahankan, tidak
//      dipaksa kembali ke 12 (melindungi hasil impor / edit manual).
//   3. Catatan penangguhan hanya untuk pelaporan Tgh, bukan carry-over.
//      Saldo yang dikembalikan saat penangguhan tidak mengubah tabel catatan.
//   4. Seluruh proses dalam satu transaksi.
//   5. Tahun dibatasi tahun berjalan / tahun depan. Gunakan --force untuk
//      testing di luar batas itu.
class GenerateSaldoCutiTahunBaru extends Command
{
    protected $signature = 'saldo-cuti:generate-tahun-baru {--tahun=} {--force}';

    protected $description = 'Generate saldo cuti tahunan untuk semua pegawai (bawaan sisa biasa maks. 6/12 hari, total maksimal 24). Aman diulang.';

    private const ROLE_ID_ADMIN = 5;
    private const KUOTA_TAHUNAN_DEFAULT = 12;
    private const BATAS_CARRY_NORMAL = 6;
    private const BATAS_ATAS_TOTAL_HAK = 24;

    public function handle(): int
    {
        $tahunBaru = (int) ($this->option('tahun') ?? date('Y'));
        $sekarang = (int) date('Y');

        if (!$this->option('force') && !in_array($tahunBaru, [$sekarang, $sekarang + 1], true)) {
            $this->error("Tahun {$tahunBaru} ditolak. Hanya {$sekarang} atau " . ($sekarang + 1) . " yang diizinkan (gunakan --force untuk testing).");

            return self::FAILURE;
        }

        $pegawaiSemua = Pegawai::where('role_id', '!=', self::ROLE_ID_ADMIN)->get();
        $carryOver = app(CarryOverSaldoService::class);

        $jumlahDiproses = 0;

        DB::transaction(function () use ($pegawaiSemua, $tahunBaru, $carryOver, &$jumlahDiproses) {
            foreach ($pegawaiSemua as $pegawai) {
                $saldoAda = SaldoCuti::where('pegawai_id', $pegawai->id)
                    ->where('tahun', $tahunBaru)
                    ->first();

                // SUMBER 1
                $carryDariSaldoBiasa = $carryOver->carryDariSaldoBiasa($pegawai->id, $tahunBaru);

                $totalCarryForward = $carryDariSaldoBiasa;

                // Pertahankan hak yang sudah ada (impor / edit manual)
                $kuotaTahunan = (int) ($saldoAda->kuota_tahunan ?? self::KUOTA_TAHUNAN_DEFAULT);
                $totalHakTersedia = min($totalCarryForward + $kuotaTahunan, self::BATAS_ATAS_TOTAL_HAK);

                $terpakaiMurni = SaldoCuti::terpakaiMurni($pegawai->id, $tahunBaru);

                $terpakaiEfektif = SaldoCuti::terpakaiEfektif($pegawai->id, $tahunBaru, $saldoAda);

                // koreksi_saldo sengaja tidak ditulis: tetap seperti adanya.
                SaldoCuti::updateOrCreate(
                    ['pegawai_id' => $pegawai->id, 'tahun' => $tahunBaru],
                    [
                        'kuota_tahunan' => $kuotaTahunan,
                        'carry_forward_normal' => $carryDariSaldoBiasa,
                        'sisa' => max(0, $totalHakTersedia - $terpakaiEfektif),
                        'terpakai' => $terpakaiMurni,
                    ]
                );

                $jumlahDiproses++;
            }
        });

        $this->info("Saldo cuti tahun {$tahunBaru} berhasil digenerate untuk {$jumlahDiproses} pegawai.");

        return self::SUCCESS;
    }
}
// ================= END COMMAND =================