<?php

namespace App\Console\Commands;

use App\Models\CutiDitangguhkan;
use App\Models\Pegawai;
use App\Models\PengajuanCuti;
use App\Models\SaldoCuti;
use Illuminate\Console\Command;

// ================= [DIUBAH] COMMAND: GENERATE SALDO CUTI TAHUN BARU =================
// Command ini adalah SATU-SATUNYA tempat logika perhitungan carry-over
// saldo cuti tahunan dijalankan — baik dipanggil OTOMATIS oleh Laravel
// Scheduler setiap 1 Januari (lihat routes/console.php), MAUPUN
// dipanggil MANUAL oleh Admin lewat tombol di halaman Kelola Saldo Cuti
// (lihat AdminController::generateSaldoManual()). Disentralisasi di sini
// supaya logikanya tidak pernah tertulis dua kali di tempat berbeda.
//
// ================= REVISI PENTING (2 SUMBER CARRY-OVER) =================
// Sebelumnya carry-over hanya menghitung dari kolom `sisa` di SaldoCuti
// dengan flag boolean `ditangguhkan_resmi` sederhana. REVISI INI mengganti
// pendekatan itu total, karena project sudah punya tabel khusus
// `cuti_ditangguhkans` (dibuat via PembatalanController@process setiap
// L4 menangguhkan sebuah pengajuan) yang jauh lebih presisi — mencatat
// PER PENGAJUAN, bukan cuma flag ya/tidak per tahun.
//
// Sekarang ada DUA sumber hari yang bisa dibawa ke tahun baru, dan
// KEDUANYA DIGABUNG:
//
//   SUMBER 1 — Sisa saldo biasa (tabel `saldo_cutis`, kolom `sisa`):
//     JALUR NORMAL : dibawa MAKSIMAL 6 hari.
//     JALUR KHUSUS : dibawa PENUH, HANYA jika pegawai tidak memakai cuti
//                     sama sekali selama 2 tahun berturut-turut (sisa =
//                     kuota penuh di kedua tahun tersebut).
//
//   SUMBER 2 — Hari dari tabel `cuti_ditangguhkans` dengan
//     status_pakai = 'belum_dipakai' di tahun asal tersebut:
//     SELALU dibawa PENUH, TANPA potongan apa pun — karena ini bukan
//     "pegawai tidak sempat pakai", melainkan "pegawai resmi dilarang
//     pakai oleh institusi" (sudah melalui alur approval L4). Kalau ada
//     beberapa pengajuan yang ditangguhkan di tahun yang sama, SEMUA
//     dijumlahkan tanpa batas tambahan (selain cap total 24 hari di
//     akhir, yang berlaku gabungan Sumber 1 + Sumber 2 + kuota baru).
//
//   BATAS ATAS: Total hak cuti tahun baru (Sumber 1 + Sumber 2 + kuota
//                baru) dibatasi MAKSIMAL 24 hari.
//
// Setelah hari dari Sumber 2 dibawa ke tahun baru, baris
// `cuti_ditangguhkans` yang bersangkutan ditandai `status_pakai =
// 'sudah_dipakai'` dan `tahun_penggunaan` diisi, supaya TIDAK dihitung
// dobel lagi di tahun-tahun berikutnya.
class GenerateSaldoCutiTahunBaru extends Command
{
    protected $signature = 'saldo-cuti:generate-tahun-baru {--tahun=}';

    protected $description = 'Generate saldo cuti tahunan untuk semua pegawai di tahun baru, menggabungkan carry-over dari sisa saldo biasa (Jalur Normal maks 6 hari / Jalur Khusus 2-tahun tidak pakai) dan hari dari cuti_ditangguhkans (selalu penuh), dengan batas atas total 24 hari.';

    private const ROLE_ID_ADMIN = 5;
    private const KUOTA_TAHUNAN_DEFAULT = 12;
    private const BATAS_CARRY_NORMAL = 6;
    private const BATAS_ATAS_TOTAL_HAK = 24;

    public function handle(): int
    {
        // Kalau dipanggil tanpa --tahun (dari scheduler tiap 1 Januari),
        // tahun baru = tahun berjalan saat command ini jalan.
        // Kalau dipanggil manual dengan --tahun=2027, bisa dipakai untuk
        // testing/koreksi tahun tertentu tanpa harus menunggu tanggal asli.
        $tahunBaru = (int) ($this->option('tahun') ?? date('Y'));
        $tahunLalu = $tahunBaru - 1;

        $pegawaiSemua = Pegawai::where('role_id', '!=', self::ROLE_ID_ADMIN)->get();

        $jumlahDiproses = 0;

        foreach ($pegawaiSemua as $pegawai) {
            // SUMBER 1: sisa saldo biasa, Jalur Normal vs Jalur Khusus
            // (2 tahun berturut-turut tidak pakai cuti).
            $carryDariSaldoBiasa = $this->hitungCarryDariSaldoBiasa($pegawai->id, $tahunBaru);

            // SUMBER 2: hari dari cuti_ditangguhkans yang belum dipakai
            // di tahun lalu, SELALU dibawa penuh.
            $baris_ditangguhkan = CutiDitangguhkan::belumDipakai($pegawai->id, $tahunLalu)->get();
            $carryDariPenangguhanResmi = $baris_ditangguhkan->sum('jumlah_hari');

            $totalCarryForward = $carryDariSaldoBiasa + $carryDariPenangguhanResmi;

            $kuotaTahunan = self::KUOTA_TAHUNAN_DEFAULT;
            $totalHakTersedia = min($totalCarryForward + $kuotaTahunan, self::BATAS_ATAS_TOTAL_HAK);

            // Cuti yang sudah terpakai di tahun baru ini (biasanya 0 kalau
            // command dijalankan pas awal tahun, tapi tetap dihitung ulang
            // supaya aman kalau command di-generate ulang di tengah tahun
            // setelah ada koreksi data).
            $cutiTerpakaiTahunIni = PengajuanCuti::where('pegawai_id', $pegawai->id)
                ->where('jenis_cuti', 'Cuti Tahunan')
                ->where('status', 'disetujui')
                ->whereYear('tanggal_mulai', $tahunBaru)
                ->sum('jumlah_hari');

            $sisaAkhir = max(0, $totalHakTersedia - $cutiTerpakaiTahunIni);

            SaldoCuti::updateOrCreate(
                ['pegawai_id' => $pegawai->id, 'tahun' => $tahunBaru],
                [
                    'kuota_tahunan' => $kuotaTahunan,
                    'carry_forward_normal' => $totalCarryForward,
                    'sisa' => $sisaAkhir,
                    'terpakai' => $cutiTerpakaiTahunIni,
                ]
            );

            // Tandai baris cuti_ditangguhkans yang barusan dibawa penuh
            // ini sebagai SUDAH DIPAKAI di tahun baru, supaya tidak
            // dihitung dobel lagi kalau command ini dijalankan ulang
            // untuk tahun setelahnya.
            foreach ($baris_ditangguhkan as $baris) {
                $baris->update([
                    'status_pakai' => 'sudah_dipakai',
                    'tahun_penggunaan' => $tahunBaru,
                ]);
            }

            $jumlahDiproses++;
        }

        $this->info("Saldo cuti tahun {$tahunBaru} berhasil digenerate untuk {$jumlahDiproses} pegawai.");

        return self::SUCCESS;
    }

    // SUMBER 1: menentukan carry-over dari sisa saldo BIASA (bukan dari
    // penangguhan resmi, yang dihitung terpisah lewat CutiDitangguhkan).
    // Jalur Khusus di sini HANYA berlaku untuk kondisi "2 tahun
    // berturut-turut tidak pakai cuti sama sekali" — kondisi
    // "ditangguhkan resmi" sudah ditangani oleh SUMBER 2 (tabel
    // cuti_ditangguhkans), bukan di sini lagi.
    private function hitungCarryDariSaldoBiasa(int $pegawaiId, int $tahunBaru): int
    {
        $saldoTahunLalu = SaldoCuti::where('pegawai_id', $pegawaiId)
            ->where('tahun', $tahunBaru - 1)
            ->first();

        $saldoDuaTahunLalu = SaldoCuti::where('pegawai_id', $pegawaiId)
            ->where('tahun', $tahunBaru - 2)
            ->first();

        // Kalau tidak ada data tahun lalu sama sekali (pegawai baru
        // masuk, atau memang belum pernah digenerate), tidak ada apa pun
        // yang bisa dibawa dari sumber ini.
        if (!$saldoTahunLalu) {
            return 0;
        }

        $sisaTahunLalu = (int) $saldoTahunLalu->sisa;

        // "Tidak dipakai sama sekali" = sisa akhir tahun tersebut masih
        // sama dengan (atau lebih besar dari, untuk jaga-jaga) kuota awal
        // tahun itu.
        $tidakDipakaiTahunLalu = $sisaTahunLalu >= $saldoTahunLalu->kuota_tahunan;
        $tidakDipakaiDuaTahunLalu = $saldoDuaTahunLalu
            && (int) $saldoDuaTahunLalu->sisa >= $saldoDuaTahunLalu->kuota_tahunan;

        if ($tidakDipakaiTahunLalu && $tidakDipakaiDuaTahunLalu) {
            // Jalur Khusus (2 tahun tidak pakai): dibawa penuh.
            return $sisaTahunLalu;
        }

        // Jalur Normal: dipotong maksimal 6 hari.
        return min($sisaTahunLalu, self::BATAS_CARRY_NORMAL);
    }
} 
// ================= [END DIUBAH] =================