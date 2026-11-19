<?php

namespace App\Console\Commands;

use App\Models\Pegawai;
use App\Models\SaldoCuti;
use App\Services\CarryOverSaldoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// ================= COMMAND: GENERATE SALDO CUTI TAHUN BARU =================
// Satu-satunya tempat logika carry-over dijalankan. TIDAK dijadwalkan
// otomatis: hanya lewat tombol manual Admin HR
// (AdminController::generateSaldoManual()) atau dari terminal.
//
// carry_forward_normal diisi SISA MENTAH tahun lalu
// (Hak - Terpakai - Tgh tahun itu, lihat CarryOverSaldoService).
// Bawaan eligible dihitung SaldoCuti::hitungBawaan(): min(6, sisa tahun lalu),
// atau 12 jika sisa N-2 dan N-1 masing-masing 12. Batas hak + bawaan 24 hanya
// berlaku bila SaldoCuti::TERAPKAN_BATAS_ATAS_TOTAL_HAK = true (saat ini false).
//
// PERILAKU:
//   1. Bawaan: hanya MEMBUAT dompet yang belum ada untuk pegawai yang masih
//      aktif. Dompet yang sudah ada TIDAK disentuh karena dijaga auto-sync
//      (SaldoCuti::sinkronkanBawaan). Aman dijalankan berulang, mis. untuk
//      pegawai yang masuk setelah Generate.
//   2. --hitung-ulang: carry-over & sisa dompet yang SUDAH ada dihitung ulang
//      dari saldo tahun sebelumnya. Untuk perbaikan data; tombol UI tidak
//      memakainya.
//   3. Hak (kuota_tahunan) yang sudah ada dipertahankan, tidak dipaksa 12.
//   4. Seluruh proses dalam satu transaksi.
//   5. Tahun dibatasi tahun berjalan / tahun depan. --force untuk testing.
class GenerateSaldoCutiTahunBaru extends Command
{
    protected $signature = 'saldo-cuti:generate-tahun-baru {--tahun=} {--force} {--hitung-ulang}';

    protected $description = 'Buat saldo cuti tahunan yang belum ada untuk pegawai aktif (carry = Hak - Terpakai - Tgh tahun lalu; bawaan maks. 6/12 hari). Dompet yang sudah ada dilewati kecuali --hitung-ulang.';

    private const ROLE_ID_ADMIN = 5;
    private const KUOTA_TAHUNAN_DEFAULT = 12;

    public function handle(): int
    {
        $tahunBaru = (int) ($this->option('tahun') ?? date('Y'));
        $sekarang = (int) date('Y');
        $hitungUlang = (bool) $this->option('hitung-ulang');

        if (!$this->option('force') && !in_array($tahunBaru, [$sekarang, $sekarang + 1], true)) {
            $this->error("Tahun {$tahunBaru} ditolak. Hanya {$sekarang} atau " . ($sekarang + 1) . " yang diizinkan (gunakan --force untuk testing).");

            return self::FAILURE;
        }

        $pegawaiSemua = Pegawai::where('role_id', '!=', self::ROLE_ID_ADMIN)->get();
        $carryOver = app(CarryOverSaldoService::class);

        $dibuat = 0;
        $diperbarui = 0;
        $dilewati = 0;

        DB::transaction(function () use ($pegawaiSemua, $tahunBaru, $carryOver, $hitungUlang, &$dibuat, &$diperbarui, &$dilewati) {
            foreach ($pegawaiSemua as $pegawai) {
                $saldoAda = SaldoCuti::where('pegawai_id', $pegawai->id)
                    ->where('tahun', $tahunBaru)
                    ->first();

                // Dompet sudah ada: biarkan (dijaga auto-sync), kecuali --hitung-ulang.
                if ($saldoAda && !$hitungUlang) {
                    $dilewati++;
                    continue;
                }

                // Dompet baru hanya untuk pegawai yang masih aktif, supaya
                // pegawai pensiun/keluar tidak mendapat saldo "siluman".
                if (!$saldoAda && !$this->masihAktif($pegawai)) {
                    $dilewati++;
                    continue;
                }

                // Sisa mentah tahun lalu (Hak - Terpakai - Tgh), disimpan apa adanya.
                $carryDariSaldoBiasa = $carryOver->carryDariSaldoBiasa($pegawai->id, $tahunBaru);

                // Pertahankan hak yang sudah ada (impor / edit manual)
                $kuotaTahunan = (int) ($saldoAda->kuota_tahunan ?? self::KUOTA_TAHUNAN_DEFAULT);

                // Sisa N-2 untuk aturan akumulasi 12 + 12.
                $duaTahunLalu = (int) (SaldoCuti::where('pegawai_id', $pegawai->id)
                    ->where('tahun', $tahunBaru - 1)
                    ->value('carry_forward_normal') ?? 0);

                // hitungBawaan() menangani aturan 6/12 dan batas 24 (jika aktif).
                $totalHakTersedia = $kuotaTahunan
                    + SaldoCuti::hitungBawaan($duaTahunLalu, $carryDariSaldoBiasa, $kuotaTahunan);

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

                $saldoAda ? $diperbarui++ : $dibuat++;
            }
        });

        $pesan = "Saldo cuti tahun {$tahunBaru}: {$dibuat} dompet baru dibuat";
        if ($hitungUlang) {
            $pesan .= ", {$diperbarui} dompet dihitung ulang";
        }
        $pesan .= ", {$dilewati} dilewati (sudah ada atau pegawai tidak aktif).";

        $this->info($pesan);

        return self::SUCCESS;
    }

    // status_aktif NULL dianggap 'Aktif' (sama dengan KelolaPegawai.vue).
    private function masihAktif(Pegawai $pegawai): bool
    {
        return $pegawai->status_aktif === null || $pegawai->status_aktif === 'Aktif';
    }
}
// ================= END COMMAND =================