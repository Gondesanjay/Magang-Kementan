<?php

namespace Database\Seeders;

use App\Models\Pegawai;
use App\Models\PengajuanCuti;
use App\Models\SaldoCuti;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Seeder data dummy untuk UJI PERFORMA (jangan dijalankan di produksi).
 *
 * Menjalankan:
 *   php artisan db:seed --class=PerformanceTestSeeder
 *
 * Membersihkan lagi:
 *   php artisan db:seed --class=PerformanceTestCleanup
 *
 * Semua pegawai dummy diberi nama berawalan "UJI-" supaya mudah dikenali dan
 * dihapus. Pegawai dummy SEMUA role 1 (staf), jadi tidak mengubah rantai
 * approval maupun penerima notifikasi pada data asli.
 *
 * PENTING: sesuaikan nama kolom di bawah dengan tabel Anda bila ada yang
 * berbeda (terutama kolom wajib/NOT NULL yang tidak saya ketahui).
 */
class PerformanceTestSeeder extends Seeder
{
    // ====== ATUR JUMLAH DATA DI SINI ======
    private const JUMLAH_PEGAWAI = 2000;     // pegawai dummy
    private const JUMLAH_PENGAJUAN = 100000; // pengajuan cuti dummy
    private const UKURAN_CHUNK = 1000;       // baris per sekali insert
    private const PASSWORD_DUMMY = 'password123';

    private const JENIS_CUTI = [
        'Cuti Tahunan' => 80,
        'Cuti Besar' => 5,
        'Cuti Alasan Penting' => 10,
        'Cuti Melahirkan' => 5,
    ];

    private const STATUS = [
        'disetujui' => 55,
        'menunggu_l1' => 8,
        'menunggu_l2' => 7,
        'menunggu_l3' => 5,
        'menunggu_l4' => 5,
        'ditolak' => 10,
        'dibatalkan_reguler' => 6,
        'dibatalkan_ditangguhkan' => 4,
    ];

    private const NAMA_DEPAN = ['Budi', 'Siti', 'Agus', 'Dewi', 'Rizki', 'Ani', 'Joko', 'Rina', 'Hadi', 'Maya', 'Eko', 'Lestari', 'Fajar', 'Putri', 'Andi', 'Wulan'];
    private const NAMA_BELAKANG = ['Santoso', 'Wijaya', 'Pratama', 'Nugroho', 'Hidayat', 'Saputra', 'Lestari', 'Kusuma', 'Utami', 'Setiawan', 'Rahman', 'Permata'];
    private const JABATAN = ['Analis Kebijakan', 'Perencana Ahli Pertama', 'Pengelola Data', 'Penyusun Laporan', 'Pranata Komputer', 'Staf Administrasi'];

    /** @var array<string, array<string, int>> cache daftar kolom per tabel */
    private array $kolomTabel = [];

    /**
     * Buang kolom yang tidak ada di tabel (mis. jatah_cuti) supaya insert tidak error.
     * Kolom yang dibuang ditampilkan sekali sebagai peringatan.
     */
    private function saring(string $tabel, array $baris): array
    {
        if (! isset($this->kolomTabel[$tabel])) {
            $this->kolomTabel[$tabel] = array_flip(Schema::getColumnListing($tabel));

            $dibuang = array_diff(array_keys($baris[0] ?? []), array_keys($this->kolomTabel[$tabel]));
            if ($dibuang) {
                $this->command->warn("Tabel {$tabel}: kolom dilewati karena tidak ada: " . implode(', ', $dibuang));
            }
        }

        return array_map(fn ($b) => array_intersect_key($b, $this->kolomTabel[$tabel]), $baris);
    }

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->error('Seeder ini tidak boleh dijalankan di production.');

            return;
        }

        DB::disableQueryLog();
        mt_srand(2026); // hasil bisa diulang

        $this->command->info('1/3 Membuat pegawai dummy...');
        $this->buatPegawai();

        $idPegawai = Pegawai::where('nama', 'like', 'UJI-%')->pluck('id')->all();

        $this->command->info('2/3 Membuat saldo cuti...');
        $this->buatSaldo($idPegawai);

        $this->command->info('3/3 Membuat pengajuan cuti...');
        $this->buatPengajuan($idPegawai);

        $this->command->info('Selesai.');
    }

    private function buatPegawai(): void
    {
        $tabel = (new Pegawai)->getTable();
        $sudahAda = DB::table($tabel)->where('nama', 'like', 'UJI-%')->count();

        if ($sudahAda >= self::JUMLAH_PEGAWAI) {
            $this->command->warn("Sudah ada {$sudahAda} pegawai dummy, dilewati.");

            return;
        }

        // Pakai kelompok & tim kerja yang sudah ada supaya filter/halaman terasa nyata.
        $kelompok = DB::table($tabel)->whereNotNull('kelompok_substansi')
            ->where('kelompok_substansi', '!=', '')->where('kelompok_substansi', '!=', '-')
            ->distinct()->pluck('kelompok_substansi')->all() ?: ['Kelompok A', 'Kelompok B', 'Kelompok C'];

        $timKerja = DB::table($tabel)->whereNotNull('tim_kerja')
            ->where('tim_kerja', '!=', '')->where('tim_kerja', '!=', '-')
            ->distinct()->pluck('tim_kerja')->all() ?: ['Tim 1', 'Tim 2', 'Tim 3'];

        $passwordHash = Hash::make(self::PASSWORD_DUMMY); // sekali saja, hash itu lambat
        $sekarang = now();
        $baris = [];

        $bar = $this->command->getOutput()->createProgressBar(self::JUMLAH_PEGAWAI);

        for ($i = $sudahAda; $i < self::JUMLAH_PEGAWAI; $i++) {
            // NIP 18 digit: lahir(8) + TMT(6) + jenis kelamin(1) + urut(3).
            // Digit ke-15 = 1 laki-laki, 2 perempuan (dipakai aturan Cuti Melahirkan).
            $lahir = Carbon::create(1970, 1, 1)->addDays($i);
            $tmt = Carbon::create(mt_rand(2005, 2024), mt_rand(1, 12), 1);
            $perempuan = $i % 2 === 0;

            $nip = $lahir->format('Ymd')
                . $tmt->format('Ym')
                . ($perempuan ? '2' : '1')
                . str_pad((string) ($i % 1000), 3, '0', STR_PAD_LEFT);

            $nama = 'UJI-' . str_pad((string) ($i + 1), 5, '0', STR_PAD_LEFT) . ' '
                . self::NAMA_DEPAN[array_rand(self::NAMA_DEPAN)] . ' '
                . self::NAMA_BELAKANG[array_rand(self::NAMA_BELAKANG)];

            $baris[] = [
                'nama' => $nama,
                'nip' => $nip,
                'jabatan' => self::JABATAN[array_rand(self::JABATAN)],
                'kelompok_substansi' => $kelompok[array_rand($kelompok)],
                'tim_kerja' => $timKerja[array_rand($timKerja)],
                'role_id' => 1,
                'status_kepegawaian' => mt_rand(1, 10) <= 7 ? 'PNS' : 'PPPK',
                'status_aktif' => 'Aktif',
                'tanggal_masuk' => $tmt->toDateString(),
                'jatah_cuti' => 12,
                'alamat_domisili' => 'Jl. Uji Beban No. ' . ($i + 1) . ', Jakarta',
                'no_telepon' => '08' . mt_rand(1000000000, 9999999999),
                'password' => $passwordHash,
                'is_first_login' => false,
                'created_at' => $sekarang,
                'updated_at' => $sekarang,
            ];

            if (count($baris) >= 500) {
                DB::table($tabel)->insert($this->saring($tabel, $baris));
                $bar->advance(count($baris));
                $baris = [];
            }
        }

        if ($baris) {
            DB::table($tabel)->insert($this->saring($tabel, $baris));
            $bar->advance(count($baris));
        }

        $bar->finish();
        $this->command->newLine();
    }

    private function buatSaldo(array $idPegawai): void
    {
        $tabel = (new SaldoCuti)->getTable();
        $tahunIni = (int) date('Y');
        $sekarang = now();
        $baris = [];

        foreach ($idPegawai as $id) {
            foreach ([$tahunIni - 1, $tahunIni] as $tahun) {
                $ada = DB::table($tabel)->where('pegawai_id', $id)->where('tahun', $tahun)->exists();
                if ($ada) {
                    continue;
                }

                $baris[] = [
                    'pegawai_id' => $id,
                    'tahun' => $tahun,
                    'kuota_tahunan' => 12,
                    'carry_forward_normal' => mt_rand(0, 6),
                    'sisa' => 12,
                    'koreksi_saldo' => null,
                    'created_at' => $sekarang,
                    'updated_at' => $sekarang,
                ];
            }

            if (count($baris) >= self::UKURAN_CHUNK) {
                DB::table($tabel)->insert($this->saring($tabel, $baris));
                $baris = [];
            }
        }

        if ($baris) {
            DB::table($tabel)->insert($this->saring($tabel, $baris));
        }
    }

    private function buatPengajuan(array $idPegawai): void
    {
        if (empty($idPegawai)) {
            $this->command->error('Tidak ada pegawai dummy. Berhenti.');

            return;
        }

        $tabel = (new PengajuanCuti)->getTable();
        $tahunIni = (int) date('Y');
        $jenisPool = $this->buatPool(self::JENIS_CUTI);
        $statusPool = $this->buatPool(self::STATUS);
        $baris = [];

        $bar = $this->command->getOutput()->createProgressBar(self::JUMLAH_PENGAJUAN);

        for ($i = 0; $i < self::JUMLAH_PENGAJUAN; $i++) {
            $jenis = $jenisPool[array_rand($jenisPool)];
            $status = $statusPool[array_rand($statusPool)];

            [$mulai, $selesai, $hari] = $this->acakRentang($tahunIni - 1, $tahunIni);

            $level = preg_match('/^menunggu_l(\d)$/', $status, $m)
                ? (int) $m[1]
                : ($status === 'disetujui' ? 4 : mt_rand(1, 4));

            $dibuat = $mulai->copy()->subDays(mt_rand(1, 20));

            $baris[] = [
                'pegawai_id' => $idPegawai[array_rand($idPegawai)],
                'jenis_cuti' => $jenis,
                'anak_ke' => $jenis === 'Cuti Melahirkan' ? mt_rand(1, 3) : null,
                'tanggal_mulai' => $mulai->toDateString(),
                'tanggal_selesai' => $selesai->toDateString(),
                'jumlah_hari' => $hari,
                'keterangan' => 'Pengajuan uji beban #' . ($i + 1),
                'alamat_cuti' => 'Jl. Uji Beban No. ' . mt_rand(1, 500) . ', Jakarta',
                'no_telp' => '08' . mt_rand(1000000000, 9999999999),
                'lampiran' => null,
                'status' => $status,
                'level_saat_ini' => $level,
                'created_at' => $dibuat,
                'updated_at' => $dibuat,
            ];

            if (count($baris) >= self::UKURAN_CHUNK) {
                DB::table($tabel)->insert($this->saring($tabel, $baris));
                $bar->advance(count($baris));
                $baris = [];
            }
        }

        if ($baris) {
            DB::table($tabel)->insert($this->saring($tabel, $baris));
            $bar->advance(count($baris));
        }

        $bar->finish();
        $this->command->newLine();
    }

    /** Ubah peta [nilai => bobot] menjadi daftar berbobot untuk array_rand(). */
    private function buatPool(array $bobot): array
    {
        $pool = [];
        foreach ($bobot as $nilai => $berat) {
            for ($k = 0; $k < $berat; $k++) {
                $pool[] = $nilai;
            }
        }

        return $pool;
    }

    /**
     * Rentang cuti acak yang dimulai di hari kerja, lamanya 1-5 hari kerja.
     * Libur nasional tidak diperhitungkan (cukup untuk uji beban).
     *
     * @return array{0: Carbon, 1: Carbon, 2: int}
     */
    private function acakRentang(int $tahunAwal, int $tahunAkhir): array
    {
        $mulai = Carbon::create(mt_rand($tahunAwal, $tahunAkhir), mt_rand(1, 12), mt_rand(1, 28));

        while ($mulai->isWeekend()) {
            $mulai->addDay();
        }

        $hari = mt_rand(1, 5);
        $selesai = $mulai->copy();
        $sisa = $hari - 1;

        while ($sisa > 0) {
            $selesai->addDay();
            if (! $selesai->isWeekend()) {
                $sisa--;
            }
        }

        return [$mulai, $selesai, $hari];
    }
}
