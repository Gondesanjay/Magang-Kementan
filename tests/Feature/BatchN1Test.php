<?php

namespace Tests\Feature;

use App\Http\Controllers\CutiController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MonitoringCutiController;
use App\Http\Controllers\RekapKuotaDetailController;
use App\Models\CutiDitangguhkan;
use App\Models\KoreksiCutiBulanan;
use App\Models\Pegawai;
use App\Models\PengajuanCuti;
use App\Models\SaldoCuti;
use App\Support\RincianSaldo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * Bukti poin 4 (N+1) dan poin 2 (rumus saldo satu sumber).
 *  A. Jalur batch menghasilkan angka SAMA dengan jalur lama.
 *  B. Jumlah query batch TIDAK naik saat jumlah pegawai naik.
 *  C. Dashboard (opsional, perlu ROLE_* diisi).
 *  E. Rumus Total Tersedia satu sumber (RincianSaldo::hitungTotal).
 *
 * Simpan sebagai tests/Feature/BatchN1Test.php
 * Jalankan:  php artisan test --filter=BatchN1Test
 *
 * PENGAMAN: test berhenti jika nama database tidak berakhiran "test",
 * supaya data aplikasi tidak terhapus oleh RefreshDatabase.
 */
class BatchN1Test extends TestCase
{
    use RefreshDatabase;

    private const TAHUN = 2026;

    // ====== SESUAIKAN BILA PERLU ======
    private const ROLE_PEGAWAI  = 1;     // role_id untuk pegawai biasa (harus ada di tabel roles bila ada FK)
    private const ROLE_ADMIN_HR = 5;     // role_id Admin HR (tabel roles: 1 Karyawan ... 5 Admin HR, 6 Kepala Biro)
    private const URL_DASHBOARD = '/dashboard';
    private const STATUS_PAKAI_TGH = 'belum_dipakai'; // nilai status_pakai yang valid di aplikasimu
    // ==================================

    private int $urut = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // Database test kosong: isi tabel roles agar pegawais.role_id valid.
        $roles = [1 => 'Karyawan', 2 => 'Atasan L1', 3 => 'Atasan L2', 4 => 'Atasan L3', 5 => 'Admin HR', 6 => 'Kepala Biro'];

        foreach ($roles as $id => $nama) {
            DB::table('roles')->insertOrIgnore([
                'id'         => $id,
                'nama_role'  => $nama,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    protected function beforeRefreshingDatabase()
    {
        $koneksi = config('database.default');
        $nama    = (string) config("database.connections.{$koneksi}.database");

        if (! str_ends_with(strtolower($nama), 'test') && $nama !== ':memory:') {
            throw new RuntimeException(
                "BERHENTI: database test '{$nama}' tidak berakhiran 'test'. " .
                    'Atur DB_DATABASE di phpunit.xml ke database terpisah agar data aplikasi tidak terhapus.'
            );
        }
    }

    /**
     * Dijalankan sekali setelah migrate:fresh, SEBELUM transaksi test dimulai
     * (DDL di dalam transaksi MySQL akan memaksa commit dan merusak isolasi data).
     * Tabel notifikasis dibaca HandleInertiaRequests di setiap halaman, tetapi
     * belum dibuat oleh migration manapun. Hapus blok ini setelah migration-nya ada.
     * (Aman dibiarkan: Schema::hasTable mencegah error "table already exists".)
     */
    protected function afterRefreshingDatabase()
    {
        if (! Schema::hasTable('notifikasis')) {
            Schema::create('notifikasis', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('pegawai_id');
                $t->string('judul')->nullable();
                $t->text('pesan')->nullable();
                $t->string('tautan')->nullable();
                $t->boolean('is_read')->default(false);
                $t->timestamps();
            });
        }
    }

    // ------------------------------------------------------------------
    // FUNGSI BANTU
    // ------------------------------------------------------------------

    private function buatPegawai(array $attr = []): Pegawai
    {
        $this->urut++;

        return Pegawai::unguarded(fn() => Pegawai::create(array_merge([
            'nip'            => 'T' . str_pad((string) $this->urut, 8, '0', STR_PAD_LEFT),
            'nama'           => 'Pegawai Uji ' . $this->urut,
            'password'       => bcrypt('password'),
            'role_id'        => self::ROLE_PEGAWAI,
            'jabatan'        => 'STAF',
            'status_aktif'   => 'Aktif',
            'tanggal_masuk'  => '2020-01-01',
            'is_first_login' => 0,
        ], $attr)));
    }

    private function buatCuti(int $pegawaiId, string $mulai, string $selesai, int $hari, string $status = 'disetujui', string $jenis = 'Cuti Tahunan'): PengajuanCuti
    {
        return PengajuanCuti::unguarded(fn() => PengajuanCuti::create([
            'pegawai_id'       => $pegawaiId,
            'jenis_cuti'       => $jenis,
            'tanggal_mulai'    => $mulai,
            'tanggal_selesai'  => $selesai,
            'jumlah_hari'      => $hari,
            'keterangan'       => 'uji',
            'status'           => $status,
            'level_saat_ini'   => 1,
            'jumlah_pengingat' => 0,
        ]));
    }

    private function buatKoreksiBulanan(int $pegawaiId, int $tahun, int $bulan, int $nilai, int $nilaiSistem): void
    {
        KoreksiCutiBulanan::unguarded(fn() => KoreksiCutiBulanan::create([
            'pegawai_id'   => $pegawaiId,
            'tahun'        => $tahun,
            'bulan'        => $bulan,
            'nilai'        => $nilai,
            'nilai_sistem' => $nilaiSistem,
        ]));
    }

    private function buatSaldo(int $pegawaiId, int $tahun, array $extra = []): SaldoCuti
    {
        return SaldoCuti::unguarded(fn() => SaldoCuti::create(array_merge([
            'pegawai_id'           => $pegawaiId,
            'tahun'                => $tahun,
            'kuota_tahunan'        => 12,
            'carry_forward_normal' => 0,
            'terpakai'             => 0,
            'sisa'                 => 12,
        ], $extra)));
    }

    private function buatTangguh(int $pegawaiId, int $tahunAsal, int $hari): void
    {
        CutiDitangguhkan::unguarded(fn() => CutiDitangguhkan::create([
            'pegawai_id'         => $pegawaiId,
            'jumlah_hari'        => $hari,
            'tahun_asal'         => $tahunAsal,
            'status_pakai'       => self::STATUS_PAKAI_TGH,
            'alasan_penangguhan' => 'uji',
        ]));
    }

    private function hitungQuery(callable $fn): int
    {
        $jumlah = 0;
        DB::listen(function () use (&$jumlah) {
            $jumlah++;
        });
        $fn();

        return $jumlah;
    }

    /** Data kasus tepi yang dipakai beberapa test. */
    private function siapkanKasus(): void
    {
        $t = self::TAHUN;

        $p1 = $this->buatPegawai();                       // tanpa cuti
        $this->buatSaldo($p1->id, $t);

        $p2 = $this->buatPegawai();                       // cuti disetujui biasa
        $this->buatSaldo($p2->id, $t, ['carry_forward_normal' => 3]);
        $this->buatCuti($p2->id, "$t-03-02", "$t-03-04", 3);

        $p3 = $this->buatPegawai();                       // ditolak & jenis lain diabaikan
        $this->buatSaldo($p3->id, $t);
        $this->buatCuti($p3->id, "$t-04-06", "$t-04-07", 2, 'ditolak');
        $this->buatCuti($p3->id, "$t-05-04", "$t-05-05", 2, 'disetujui', 'Cuti Sakit');
        $this->buatCuti($p3->id, "$t-06-01", "$t-06-01", 1);

        $p4 = $this->buatPegawai();                       // koreksi bulanan (selisih 0 tetap dianggap ada)
        $this->buatSaldo($p4->id, $t);
        $this->buatCuti($p4->id, "$t-02-02", "$t-02-03", 2);
        $this->buatKoreksiBulanan($p4->id, $t, 2, 5, 2);
        $this->buatKoreksiBulanan($p4->id, $t, 3, 0, 0);

        $p5 = $this->buatPegawai();                       // koreksi negatif besar -> minimal 0
        $this->buatSaldo($p5->id, $t);
        $this->buatKoreksiBulanan($p5->id, $t, 1, 0, 9);

        $p6 = $this->buatPegawai();                       // koreksi_saldo tahunan lama
        $this->buatSaldo($p6->id, $t, ['koreksi_saldo' => 4]);
        $this->buatCuti($p6->id, "$t-07-06", "$t-07-08", 3);

        $p7 = $this->buatPegawai();                       // lintas tahun
        $this->buatSaldo($p7->id, $t);
        $this->buatSaldo($p7->id, $t - 1, ['carry_forward_normal' => 2]);
        $this->buatCuti($p7->id, ($t - 1) . '-12-29', "$t-01-02", 5);

        $p8 = $this->buatPegawai();                       // tanpa baris saldo
        $this->buatCuti($p8->id, "$t-08-03", "$t-08-04", 2);

        $p9 = $this->buatPegawai();                       // cuti ditangguhkan
        $this->buatSaldo($p9->id, $t);
        $this->buatTangguh($p9->id, $t - 1, 2);
        $this->buatTangguh($p9->id, $t, 1);
        $this->buatCuti($p9->id, "$t-09-07", "$t-09-08", 2, 'menunggu');
    }

    // ------------------------------------------------------------------
    // A1. terpakaiEfektif: batch == lama
    // ------------------------------------------------------------------

    public function test_terpakai_efektif_batch_sama_dengan_jalur_lama(): void
    {
        $this->siapkanKasus();

        $t     = self::TAHUN;
        $ids   = Pegawai::pluck('id')->all();
        $tahun = [$t - 1, $t];
        $bahan = SaldoCuti::muatBahanTerpakai($ids, $tahun);

        foreach ($ids as $id) {
            foreach ($tahun as $th) {
                $saldo = SaldoCuti::where('pegawai_id', $id)->where('tahun', $th)->first();

                $lama = SaldoCuti::terpakaiEfektif($id, $th, $saldo);
                $baru = SaldoCuti::terpakaiEfektifDariBahan($id, $th, $saldo, $bahan);

                $this->assertSame(
                    (int) $lama,
                    (int) $baru,
                    "Beda untuk pegawai {$id} tahun {$th}: lama={$lama}, batch={$baru}"
                );
            }
        }
    }

    // ------------------------------------------------------------------
    // A2. RincianSaldo::lengkapi (batch) == untukPegawaiTahun (jalur lama)
    // ------------------------------------------------------------------

    public function test_lengkapi_sama_dengan_untuk_pegawai_tahun(): void
    {
        $this->siapkanKasus();

        foreach ([null, self::TAHUN] as $filter) {
            $daftar = PengajuanCuti::with('pegawai')->get();
            RincianSaldo::lengkapi($daftar, $filter);

            foreach ($daftar as $item) {
                $tahun   = RincianSaldo::tahunUntuk($item, $filter);
                $diharap = RincianSaldo::untukPegawaiTahun((int) $item->pegawai_id, $tahun);
                $label   = "pengajuan {$item->id}, pegawai {$item->pegawai_id}, filter " . var_export($filter, true);

                $this->assertEquals($diharap, $item->rincian_saldo, "rincian_saldo beda: {$label}");
                $this->assertSame(
                    $diharap['total_cuti_tersedia'],
                    $item->pegawai->total_cuti_tersedia,
                    "total_cuti_tersedia beda: {$label}"
                );
                $this->assertSame(
                    $diharap['cuti_terpakai'],
                    $item->pegawai->cuti_terpakai,
                    "cuti_terpakai beda: {$label}"
                );
            }
        }
    }

    // ------------------------------------------------------------------
    // A3. petaKetua() == accessor lama
    // ------------------------------------------------------------------

    public function test_peta_ketua_sama_dengan_accessor_lama(): void
    {
        $this->buatPegawai(['jabatan' => 'KETUA TIM KERJA PERENCANAAN', 'tim_kerja' => 'Tim A', 'kelompok_substansi' => 'Kel X']);
        $this->buatPegawai(['jabatan' => 'KETUA KELOMPOK SUBSTANSI X', 'tim_kerja' => 'Tim B', 'kelompok_substansi' => 'Kel X']);
        $this->buatPegawai(['tim_kerja' => 'Tim A', 'kelompok_substansi' => 'Kel X']);
        $this->buatPegawai(['tim_kerja' => 'tim a ', 'kelompok_substansi' => 'kel x']); // beda huruf/spasi
        $this->buatPegawai(['tim_kerja' => null, 'kelompok_substansi' => null]);

        $semua = Pegawai::all();
        $peta  = Pegawai::petaKetua($semua);
        $nama  = fn($v) => is_object($v) ? ($v->nama ?? null) : $v;

        foreach ($semua as $p) {
            $this->assertSame($nama($p->ketua_tim_kerja), $peta[$p->id]['nama_l1'], "L1 beda untuk pegawai {$p->id}");
            $this->assertSame($nama($p->ketua_kelompok), $peta[$p->id]['nama_l2'], "L2 beda untuk pegawai {$p->id}");
        }
    }

    // ------------------------------------------------------------------
    // B1. Query batch tidak naik seiring jumlah pegawai
    // ------------------------------------------------------------------

    private function isiPegawaiBanyak(int $n): void
    {
        $t = self::TAHUN;

        for ($i = 0; $i < $n; $i++) {
            $p = $this->buatPegawai(['tim_kerja' => 'Tim ' . $this->urut, 'kelompok_substansi' => 'Kel ' . $this->urut]);
            $this->buatSaldo($p->id, $t);
            $this->buatCuti($p->id, "$t-03-02", "$t-03-03", 2, 'menunggu');
            $this->buatKoreksiBulanan($p->id, $t, 3, 3, 2);
            $this->buatTangguh($p->id, $t - 1, 1);
        }
    }

    public function test_query_lengkapi_tidak_naik_seiring_jumlah_pegawai(): void
    {
        $ukur = function () {
            $daftar = PengajuanCuti::with('pegawai')->get(); // di luar hitungan
            return $this->hitungQuery(fn() => RincianSaldo::lengkapi($daftar, self::TAHUN));
        };

        $this->isiPegawaiBanyak(5);
        $kecil = $ukur();

        $this->isiPegawaiBanyak(45); // total 50
        $besar = $ukur();

        $this->assertSame($kecil, $besar, "lengkapi(): {$kecil} query (5 pegawai) vs {$besar} query (50 pegawai). Masih ada N+1.");
        $this->assertLessThanOrEqual(6, $besar, 'lengkapi() seharusnya hanya beberapa query tetap.');
    }

    public function test_query_petaKetua_dan_batch_tidak_naik(): void
    {
        $t = self::TAHUN;

        $ukur = function () use ($t) {
            $ids   = Pegawai::pluck('id')->all();
            $semua = Pegawai::all();

            return $this->hitungQuery(function () use ($ids, $t, $semua) {
                $bahan = SaldoCuti::muatBahanTerpakai($ids, [$t]);
                foreach ($ids as $id) {
                    SaldoCuti::terpakaiEfektifDariBahan($id, $t, null, $bahan);
                }
                Pegawai::petaKetua($semua);
            });
        };

        $this->isiPegawaiBanyak(5);
        $kecil = $ukur();

        $this->isiPegawaiBanyak(45);
        $besar = $ukur();

        $this->assertSame($kecil, $besar, "Batch: {$kecil} query (5) vs {$besar} query (50).");
        $this->assertLessThanOrEqual(6, $besar);
    }

    // ------------------------------------------------------------------
    // E. Rumus Total Tersedia satu sumber (poin 2)
    // ------------------------------------------------------------------

    public function test_hitung_total_kasus_dasar(): void
    {
        // Hak 12, terpakai 5.
        $this->assertSame(
            ['cuti_terpakai_total' => 5, 'sisa_hak_tahun_berjalan' => 7, 'total_cuti_tersedia' => 7],
            RincianSaldo::hitungTotal(12, 0, 0, 5, 0)
        );
        // Tgh tahun lalu 3 hari -> tersedia 10.
        $this->assertSame(10, RincianSaldo::hitungTotal(12, 0, 3, 5, 0)['total_cuti_tersedia']);
        // Tgh tahun ini 4 hari -> tersedia 3.
        $this->assertSame(3, RincianSaldo::hitungTotal(12, 0, 0, 5, 4)['total_cuti_tersedia']);
        // Tidak boleh negatif.
        $this->assertSame(0, RincianSaldo::hitungTotal(12, 0, 0, 20, 4)['total_cuti_tersedia']);
    }

    public function test_total_tersedia_sama_di_rincian_cuti_controller_dan_dashboard(): void
    {
        $this->siapkanKasus();

        $t    = self::TAHUN;
        $cuti = new CutiController();
        $dash = new DashboardController();

        $panggilC = new ReflectionMethod($cuti, 'hitungSaldoCuti');
        $panggilC->setAccessible(true);
        $panggilD = new ReflectionMethod($dash, 'hitungSaldoPegawai');
        $panggilD->setAccessible(true);

        foreach (Pegawai::pluck('id') as $id) {
            $rincian = RincianSaldo::untukPegawaiTahun((int) $id, $t)['total_cuti_tersedia'];
            $dariC   = $panggilC->invoke($cuti, (int) $id, $t)['total_cuti_tersedia'];
            $dariD   = $panggilD->invoke($dash, (int) $id, $t)['total_cuti_tersedia'];

            $this->assertSame($rincian, (int) $dariC, "CutiController beda untuk pegawai {$id}");
            $this->assertSame($rincian, (int) $dariD, "Dashboard beda untuk pegawai {$id}");
        }
    }

    public function test_total_tersedia_sama_di_rekap_kuota_dan_monitoring(): void
    {
        $this->siapkanKasus();

        $t     = self::TAHUN;
        $rekap = new RekapKuotaDetailController();
        $mon   = new MonitoringCutiController();

        $panggilR = new ReflectionMethod($rekap, 'hitungKuota');
        $panggilR->setAccessible(true);
        $panggilM = new ReflectionMethod($mon, 'hitungInfoSaldo');
        $panggilM->setAccessible(true);

        foreach (Pegawai::all() as $pegawai) {
            $id             = (int) $pegawai->id;
            $rincian        = RincianSaldo::untukPegawaiTahun($id, $t)['total_cuti_tersedia'];
            $saldo          = SaldoCuti::where('pegawai_id', $id)->where('tahun', $t)->first();
            $saldoTahunLalu = SaldoCuti::where('pegawai_id', $id)->where('tahun', $t - 1)->first();
            $terpakai       = SaldoCuti::terpakaiEfektif($id, $t, $saldo);
            $tangguhIni     = (int) CutiDitangguhkan::where('pegawai_id', $id)
                ->where('tahun_asal', $t)
                ->where('status_pakai', self::STATUS_PAKAI_TGH)
                ->sum('jumlah_hari');
            $tangguhLalu    = (int) CutiDitangguhkan::where('pegawai_id', $id)
                ->where('tahun_asal', $t - 1)
                ->where('status_pakai', self::STATUS_PAKAI_TGH)
                ->sum('jumlah_hari');

            $hasilR = $panggilR->invoke($rekap, $pegawai, $t);
            $dariR  = $hasilR['saldo_akhir'] ?? $hasilR['sisa_kuota_tahun_ini'] ?? 0;
            $dariM  = $panggilM->invoke($mon, $saldo, $saldoTahunLalu, $terpakai, $tangguhIni, $tangguhLalu)['total_cuti_tersedia'];

            $this->assertSame($rincian, (int) $dariR, "RekapKuotaDetailController beda untuk pegawai {$id}");
            $this->assertSame($rincian, (int) $dariM, "MonitoringCutiController beda untuk pegawai {$id}");
        }
    }

    // ------------------------------------------------------------------
    // C. Dashboard (HTTP). Aktif setelah ROLE_ADMIN_HR diisi.
    // ------------------------------------------------------------------

    public function test_dashboard_query_tidak_naik_seiring_jumlah_pegawai(): void 
    {
        if (self::ROLE_ADMIN_HR === null) {
            $this->markTestSkipped('Isi ROLE_ADMIN_HR (role_id Admin HR) dan URL_DASHBOARD untuk mengaktifkan test ini.');
        }

        $admin = $this->buatPegawai(['role_id' => self::ROLE_ADMIN_HR, 'jabatan' => 'ADMIN HR']);

        $ukur = fn() => $this->hitungQuery(function () use ($admin) {
            $this->actingAs($admin)->get(self::URL_DASHBOARD)->assertOk();
        });

        $this->isiPegawaiBanyak(5);
        $ukur();            // pemanasan
        $kecil = $ukur();

        $this->isiPegawaiBanyak(35);
        $besar = $ukur();

        // Toleransi kecil: paginasi / daftar "recent" bisa membatasi baris yang tampil.
        $this->assertLessThanOrEqual($kecil + 3, $besar, "Dashboard: {$kecil} query (5 pegawai) vs {$besar} query (40 pegawai).");
    }

    // ------------------------------------------------------------------
    // D. Rekap Kuota Detail (khusus Admin HR, role 5)
    // ------------------------------------------------------------------

    public function test_rekap_kuota_detail_query_tidak_naik_seiring_jumlah_pegawai(): void
    {
        $admin = $this->buatPegawai(['role_id' => self::ROLE_ADMIN_HR, 'jabatan' => 'ADMIN HR']);

        $ukur = fn() => $this->hitungQuery(function () use ($admin) {
            $this->actingAs($admin)->get('/admin/monitoring')->assertOk();
        });

        $this->isiPegawaiBanyak(2);
        $ukur();            // pemanasan
        $kecil = $ukur();

        $this->isiPegawaiBanyak(38);
        $besar = $ukur();

        // Halaman ini berpaginasi: kalau ukuran halaman kecil, baris yang tampil
        // tertahan di batas itu. N+1 tetap terlihat sebagai kenaikan dari 2 ke batas halaman.
        $this->assertLessThanOrEqual($kecil + 3, $besar, "Rekap Kuota Detail: {$kecil} query (2 pegawai) vs {$besar} query (40 pegawai).");
    }

    public function test_rekap_kuota_detail_export_excel_berhasil(): void
    {
        $admin = $this->buatPegawai(['role_id' => self::ROLE_ADMIN_HR, 'jabatan' => 'ADMIN HR']);

        $this->siapkanKasus();

        $this->actingAs($admin)->get('/admin/monitoring/export')->assertOk();
    }

    /**
     * DIAGNOSA (bukan pengujian): menulis query yang paling sering berulang di
     * /admin/monitoring ke storage/logs/diagnosa-rekap.txt.
     * Hanya berisi SQL dengan tanda tanya (?), tanpa data pegawai.
     * Jalankan:  php artisan test --filter=diagnosa_rekap
     */
    public function test_diagnosa_rekap_kuota_detail(): void
    {
        $admin = $this->buatPegawai(['role_id' => self::ROLE_ADMIN_HR, 'jabatan' => 'ADMIN HR']);
        $this->isiPegawaiBanyak(40);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->get('/admin/monitoring')->assertOk();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $hitung = [];
        foreach ($log as $q) {
            $hitung[$q['query']] = ($hitung[$q['query']] ?? 0) + 1;
        }
        arsort($hitung);

        $baris = ['Total query: ' . count($log), 'Query berbeda: ' . count($hitung), ''];
        foreach ($hitung as $sql => $n) {
            $baris[] = str_pad((string) $n, 4) . 'x  ' . mb_substr($sql, 0, 400);
        }

        file_put_contents(storage_path('logs/diagnosa-rekap.txt'), implode(PHP_EOL, $baris));
        $this->assertTrue(true);
    }
}
