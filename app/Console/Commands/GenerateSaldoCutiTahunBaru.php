<?php




namespace App\Console\Commands;




use App\Models\Pegawai;
use App\Models\SaldoCuti;
use App\Services\CarryOverSaldoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;




// ================= COMMAND: GENERATE SALDO CUTI TAHUN BARU =================
// Satu-satunya tempat logika carry-over dijalankan. TIDAK dijadwalkan
// otomatis: hanya berjalan lewat tombol manual Admin HR
// (AdminController::generateSaldoManual()) atau dipanggil dari terminal.
//
// carry_forward_normal diisi SISA MENTAH tahun lalu (Hak - Terpakai).
// Bawaan eligible dihitung oleh SaldoCuti::hitungBawaan():
//   min(6, sisa tahun lalu), atau 12 jika sisa N-2 dan N-1 masing-masing 12.
// Batas hak + bawaan 24 hari hanya berlaku bila
// SaldoCuti::TERAPKAN_BATAS_ATAS_TOTAL_HAK = true (saat ini false).
//
// PERILAKU (revisi auto-sync):
//   1. Secara BAWAAN command ini hanya MEMBUAT dompet yang belum ada, untuk
//      pegawai yang masih aktif. Dompet yang sudah ada TIDAK disentuh,
//      karena angkanya dijaga oleh auto-sync (SaldoCuti::sinkronkanBawaan)
//      setiap kali saldo tahun sebelumnya berubah. Karena itu aman dijalankan
//      berulang, dan pegawai yang baru masuk setelah Generate bisa
//      dibuatkan dompet dengan menjalankannya lagi.
//   2. Opsi --hitung-ulang: carry-over & sisa dompet yang SUDAH ada dihitung
//      ulang dari saldo tahun sebelumnya (perilaku lama). Hanya untuk
//      perbaikan data; tombol di UI tidak memakainya.
//   3. Hak (kuota_tahunan) yang sudah ada dipertahankan, tidak dipaksa 12.
//   4. Catatan penangguhan hanya untuk pelaporan Tgh, bukan carry-over.
//   5. Seluruh proses dalam satu transaksi.
//   6. Tahun dibatasi tahun berjalan / tahun depan. Gunakan --force untuk
//      testing di luar batas itu.
class GenerateSaldoCutiTahunBaru extends Command
{
    protected $signature = 'saldo-cuti:generate-tahun-baru {--tahun=} {--force} {--hitung-ulang}';




    protected $description = 'Buat saldo cuti tahunan yang belum ada untuk pegawai aktif (carry = sisa mentah tahun lalu; bawaan maks. 6/12 hari). Dompet yang sudah ada dilewati kecuali --hitung-ulang.';




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




                // Sisa mentah tahun lalu (Hak - Terpakai), disimpan apa adanya.
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
