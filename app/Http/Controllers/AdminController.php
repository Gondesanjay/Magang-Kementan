<?php

namespace App\Http\Controllers;

use App\Models\Pegawai;
use App\Models\PengajuanCuti;
use App\Models\SaldoCuti;
use App\Models\HistoriSaldo;
use App\Models\HariLibur;
use App\Models\CutiDitangguhkan; // [SINKRON RKD] sumber Tgh tahun laporan di buildLaporanData()
use App\Models\KoreksiCutiBulanan; // [KOREKSI BULANAN] koreksi Admin HR per bulan
use App\Models\LogPerubahanSaldo; // [KOREKSI BULANAN] jejak perubahan Terpakai & Tgh
use App\Services\CarryOverSaldoService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder; // type-hint closure di buildLaporanData()
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection; // type-hint di urutkanSesuaiHierarki()
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB; // [KOREKSI BULANAN] transaksi updateKoreksiLaporan()
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str; // [KOREKSI BULANAN] membatasi panjang alasan log
use Illuminate\Validation\Rule; // validasi status_aktif & role_id (Rule::in)
use Illuminate\Validation\ValidationException; // rapikanDanCekKelompokTim() & cekJabatanKetua()
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// ================= PERUBAHAN (KOREKSI BULANAN DI REKAP LAPORAN) =================
// Rekap Laporan adalah satu-satunya tempat mengedit "Cuti Terpakai" per bulan
// (tabel koreksi_cuti_bulanan, model KoreksiCutiBulanan). Rekap Kuota Detail
// hanya menampilkan totalnya.
//
//   - buildLaporanData()     : tiap bulan = hitungan sistem sekarang + selisih koreksi.
//                              Field baru ke Vue: cuti_sistem, cuti_diedit, cuti_basi,
//                              ada_koreksi_bulanan, tgh_tahunan, koreksi_tahunan_lama.
//   - updateKoreksiLaporan() : simpan koreksi bulanan + total Tgh tahun laporan
//                              (hanya Admin HR, role 5).
//   - exportExcel()          : sel bulan yang dikoreksi diberi warna kuning.
//   - hitungSisaOtomatis()   : memakai SaldoCuti::terpakaiEfektif() (ikut koreksi).
//
// [KOREKSI = SELISIH] Koreksi disimpan sebagai selisih, bukan angka mutlak:
//   tampil = sistem sekarang + (nilai - nilai_sistem)
// Contoh: sistem 3, koreksi jadi 4 (selisih +1); ada cuti baru -> sistem 4,
// tampil 5. `nilai` = angka tampil saat koreksi dibuat, `nilai_sistem` =
// hitungan sistem saat itu.
// ================= END PERUBAHAN =================

class AdminController extends Controller
{
    private array $keyBulan = ['jan', 'feb', 'mar', 'apr', 'mei', 'jun', 'jul', 'agu', 'sep', 'okt', 'nov', 'des'];
    private array $labelBulan = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    // Role ID akun Admin (HR/Kepegawaian), bukan pegawai/atasan biasa.
    private const ROLE_ID_ADMIN = 5;

    // Role ID Kepala Biro (L4 / approval tertinggi). Dipakai suspendCuti().
    private const ROLE_ID_KEPALA_BIRO = 6;

    // ================= PEMBATASAN ROLE ADMIN SDM =================
    // Role yang boleh dipilih saat menambah pegawai / mengubah role lewat form
    // Kelola Pegawai & Impor. Role Admin SDM (5) sengaja tidak ada di sini.
    // Pengecualian: pegawai yang sudah Admin SDM tetap boleh diedit datanya
    // (lihat updatePegawai()); KelolaPegawai.vue menyembunyikan opsi Admin SDM
    // kecuali untuk pegawai tersebut.
    private const ROLE_ID_BISA_DIPILIH = [1, 2, 3, 4, 6];
    // ================= END PEMBATASAN ROLE ADMIN SDM =================

    // Baris per halaman untuk Rekap Laporan (server-side pagination)
    private const REKAP_PER_PAGE = 10;

    // Nilai status pegawai yang diizinkan. Untuk status baru, tambahkan di sini
    // DAN di dropdown Status Pegawai pada KelolaPegawai.vue.
    private const STATUS_PEGAWAI = ['Aktif', 'Pensiun', 'Mutasi Keluar', 'Resign / Berhenti', 'Meninggal Dunia'];

    // Status kepegawaian (jenis ASN): PNS / PPPK. Untuk jenis baru, tambahkan di
    // sini DAN di props daftarStatusKepegawaian pada KelolaPegawai.vue.
    private const STATUS_KEPEGAWAIAN = ['PNS', 'PPPK'];

    // ================= GEMBOK PENGHAPUSAN PEGAWAI (2 LAPIS) =================
    // Hapus hanya untuk koreksi kesalahan administratif (data duplikat, salah
    // ketik NIP, batal penempatan). Pegawai boleh dihapus bila:
    //   1) belum punya riwayat cuti (status di STATUS_CUTI_BERRIWAYAT), dan
    //   2) akunnya dibuat kurang dari BATAS_HARI_HAPUS_PEGAWAI hari lalu.
    //
    // Lapis 1 (UI): kelolaPegawai() mengirim flag `bisa_dihapus` per pegawai.
    // Lapis 2 (backend): destroyPegawai() memvalidasi ulang dengan helper sama
    //                    (akunMasihBaru() & punyaRiwayatCuti()).
    // Bila tidak memenuhi syarat, Admin diarahkan mengubah Status Pegawai lewat
    // EDIT. Akun Admin SDM (role 5) tidak pernah boleh dihapus lewat menu ini.
    private const BATAS_HARI_HAPUS_PEGAWAI = 7;

    // Status pengajuan yang dianggap "sudah ada riwayat pemakaian cuti".
    private const STATUS_CUTI_BERRIWAYAT = ['disetujui', 'dibatalkan_ditangguhkan'];
    // ================= END GEMBOK PENGHAPUSAN PEGAWAI =================

    // ================= STRUKTUR ORGANISASI BIRO PERENCANAAN =================
    // Sumber: "Nama-Nama Pegawai Biro Perencanaan" (per 20 Agustus 2026).
    // Format: 'Nama Kelompok' => ['Tim Kerja 1', ...]. Array kosong = kelompok
    // tanpa tim kerja. Penulisan mengikuti toTitleCase() ("Dan" kapital), kecuali
    // angka romawi (I, II, III, IV) tetap huruf besar.
    private const STRUKTUR_ORGANISASI = [
        'Subbagian Tata Usaha' => [],
        'Kelompok Kebijakan Pembangunan Pertanian' => [
            'Tim Kerja Kebijakan Pertanian',
            'Tim Kerja Analisis Data',
            'Tim Kerja Penyiapan Bahan Koordinasi',
        ],
        'Kelompok Program Dan Anggaran Pembangunan Pertanian' => [
            'Tim Kerja Program',
            'Tim Kerja Anggaran I',
            'Tim Kerja Anggaran II',
        ],
        'Kelompok Pemantauan, Evaluasi Dan Pelaporan Program Pembangunan Pertanian' => [
            'Tim Kerja Pemantauan, Evaluasi Dan Pelaporan I',
            'Tim Kerja Pemantauan, Evaluasi Dan Pelaporan II',
            'Tim Kerja Pemantauan, Evaluasi Dan Pelaporan III',
            'Tim Kerja Pemantauan, Evaluasi Dan Pelaporan IV',
        ],
        'Kelompok Pengembangan Kawasan Pertanian' => [
            'Tim Kerja Pengembangan Kawasan I',
            'Tim Kerja Pengembangan Kawasan II',
            'Tim Kerja Pengembangan Kawasan III',
        ],
    ];

    // Salah ketik di file Excel -> nama tim yang benar. Kunci huruf kecil.
    private const ALIAS_TIM_KERJA = [
        'tim kerja bahan penyiapan koordinasi' => 'Tim Kerja Penyiapan Bahan Koordinasi',
    ];

    // Nama kelompok Kasubag TU (harus sama dengan kunci STRUKTUR_ORGANISASI)
    private const KELOMPOK_TU = 'Subbagian Tata Usaha';

    // Jabatan fungsional STAF (dropdown Jabatan untuk role Karyawan/Staf).
    private const JABATAN_FUNGSIONAL_STAF = [
        'Analis Sumber Daya Manusia Aparatur Ahli Pertama',
        'Arsiparis Mahir',
        'Arsiparis Terampil',
        'Operator Layanan Operasional',
        'Penata Layanan Operasional',
        'Penelaah Teknis Kebijakan',
        'Pengadministrasi Perkantoran',
        'Pengolah Data Dan Informasi',
        'Perencana Ahli Pertama',
        'Perencana Ahli Muda',
        'Perencana Ahli Madya',
        'Perencana Ahli Utama',
        'Pranata Komputer Ahli Pertama',
        'Statistisi Ahli Pertama',
    ];

    // Jabatan fungsional Ketua Tim / Ketua Kelompok di Excel (bagian sesudah
    // "/"). Hanya ini yang ditawarkan saat admin memilih "Ubah".
    private const JABATAN_FUNGSIONAL_KETUA = [
        'Perencana Ahli Muda',
        'Perencana Ahli Madya',
        'Analis Kebijakan Ahli Madya',
        'Analis Anggaran Ahli Muda',
    ];

    // Jabatan fungsional bawaan tiap Ketua menurut Excel (per Agustus 2026).
    // Kunci = nama Tim Kerja / Kelompok (sama dengan STRUKTUR_ORGANISASI).
    private const JABATAN_KETUA_DEFAULT = [
        // Ketua Tim Kerja
        'Tim Kerja Kebijakan Pertanian' => 'Perencana Ahli Muda',
        'Tim Kerja Analisis Data' => 'Analis Kebijakan Ahli Madya',
        'Tim Kerja Penyiapan Bahan Koordinasi' => 'Perencana Ahli Muda',
        'Tim Kerja Program' => 'Perencana Ahli Muda',
        'Tim Kerja Anggaran I' => 'Perencana Ahli Muda',
        'Tim Kerja Anggaran II' => 'Perencana Ahli Muda',
        'Tim Kerja Pemantauan, Evaluasi Dan Pelaporan I' => 'Perencana Ahli Muda',
        'Tim Kerja Pemantauan, Evaluasi Dan Pelaporan II' => 'Perencana Ahli Muda',
        'Tim Kerja Pemantauan, Evaluasi Dan Pelaporan III' => 'Perencana Ahli Muda',
        'Tim Kerja Pemantauan, Evaluasi Dan Pelaporan IV' => 'Perencana Ahli Muda',
        'Tim Kerja Pengembangan Kawasan I' => 'Analis Anggaran Ahli Muda',
        'Tim Kerja Pengembangan Kawasan II' => 'Perencana Ahli Muda',
        'Tim Kerja Pengembangan Kawasan III' => 'Perencana Ahli Muda',
        // Ketua Kelompok
        'Kelompok Kebijakan Pembangunan Pertanian' => 'Analis Kebijakan Ahli Madya',
        'Kelompok Program Dan Anggaran Pembangunan Pertanian' => 'Analis Anggaran Ahli Muda',
        'Kelompok Pemantauan, Evaluasi Dan Pelaporan Program Pembangunan Pertanian' => 'Perencana Ahli Madya',
        'Kelompok Pengembangan Kawasan Pertanian' => 'Perencana Ahli Madya',
    ];

    // Jabatan tetap per role_id (tidak dipilih manual di form)
    private const JABATAN_TETAP = [
        4 => 'Kepala Subbagian Tata Usaha',
        6 => 'Kepala Biro Perencanaan',
    ];
    // ================= END STRUKTUR ORGANISASI =================

    // ================= URUTAN HIERARKI JABATAN =================
    // role_id -> urutan tampil (kecil = lebih atas): 6 Kepala Biro (L4),
    // 4 Kasubag TU (L3), 3 Ketua Kelompok (L2), 2 Ketua Tim (L1), 1 Staf.
    // role_id lain otomatis paling bawah (?? 99).
    private array $urutanJabatan = [
        6 => 1,
        4 => 2,
        3 => 3,
        2 => 4,
        1 => 5,
    ];
    // ================= END URUTAN HIERARKI JABATAN =================

    // ================= HELPER: GEMBOK DOMPET ASLI =================
    // Baris saldo_cutis dianggap "dompet ASLI" (hak tahunan hasil generate/input
    // Admin) bila kuota_tahunan > 0. Sama dengan dompetTahunAsli() di
    // RekapKuotaDetailController. Baris "bayangan" tahun-1 (kuota 0, hanya untuk
    // carry-forward) tidak dihitung, jadi tidak memunculkan tahun lalu di Rekap
    // Laporan. Catatan: pegawai yang haknya sengaja 0 pada suatu tahun tidak
    // tampil di tahun itu (sangat jarang).
    private function dompetTahunAsli(Builder $query, int $tahun): Builder
    {
        return $query->where('tahun', $tahun)
            ->where('kuota_tahunan', '>', 0);
    }
    // ================= END HELPER GEMBOK DOMPET ASLI =================

    // ================= HELPER: FILTER STATUS PEGAWAI (AKTIF / NON-AKTIF) =================
    // Sama dengan hanyaAktifAtauPunyaCutiDiTahun() di RekapKuotaDetailController,
    // agar kedua rekap menampilkan pegawai yang sama.
    //   - Pegawai aktif (atau status_aktif NULL) selalu tampil.
    //   - Non-aktif (pensiun, mutasi keluar, dst.) hanya tampil bila pada tahun
    //     laporan punya Cuti Tahunan yang disetujui atau ditangguhkan setelah
    //     disetujui (arsip tahun lalu tetap utuh).
    // Dasarnya riwayat cuti, bukan baris saldo_cutis, sehingga saldo "siluman"
    // hasil Generate Saldo Tahun Baru untuk pegawai pensiun tidak memunculkannya.
    private function hanyaAktifAtauPunyaCutiDiTahun(Builder $query, int $tahun): Builder
    {
        return $query->where(function ($q) use ($tahun) {
            $q->where('status_aktif', 'Aktif')
                ->orWhereNull('status_aktif')
                ->orWhereHas('pengajuanCuti', function ($p) use ($tahun) {
                    $p->where('jenis_cuti', 'Cuti Tahunan')
                        ->whereYear('tanggal_mulai', $tahun)
                        ->where(function ($status) {
                            $status->where('status', 'disetujui')
                                ->orWhere(function ($ditangguhkan) {
                                    $ditangguhkan->where('status', 'dibatalkan_ditangguhkan')
                                        ->whereHas('cutiDitangguhkan');
                                });
                        });
                });
        });
    }
    // ================= END HELPER FILTER STATUS PEGAWAI =================

    // ================= HELPER: KELOMPOKKAN JABATAN UNTUK DROPDOWN =================
    // Mengubah daftar datar jabatan fungsional menjadi grup per rumpun untuk
    // <optgroup> / Combobox berkelompok. Rumpun = nama tanpa jenjang di akhir
    // (Ahli Pertama/Muda/Madya/Utama, Mahir, Terampil): "Perencana Ahli Muda"
    // -> "Perencana". Rumpun dengan 1 jabatan masuk grup "Lainnya" (paling akhir).
    // Hasil: [ ['label' => 'Perencana', 'options' => [...]], ... ]. Daftar datar
    // lama tetap dikirim ke frontend.
    private function kelompokkanJabatan(array $daftar): array
    {
        $rumpunPerJabatan = [];

        foreach ($daftar as $jabatan) {
            $rumpun = trim((string) preg_replace('/\s+(Ahli\s+(Pertama|Muda|Madya|Utama)|Mahir|Terampil)$/u', '', $jabatan));
            $rumpunPerJabatan[$jabatan] = $rumpun !== '' ? $rumpun : $jabatan;
        }

        $jumlahPerRumpun = array_count_values($rumpunPerJabatan);

        $grup = [];
        $lainnya = [];

        foreach ($daftar as $jabatan) {
            $rumpun = $rumpunPerJabatan[$jabatan];

            if (($jumlahPerRumpun[$rumpun] ?? 0) < 2) {
                $lainnya[] = $jabatan;
                continue;
            }

            $grup[$rumpun][] = $jabatan;
        }

        $hasil = [];
        foreach ($grup as $label => $options) {
            $hasil[] = ['label' => $label, 'options' => array_values($options)];
        }

        if (!empty($lainnya)) {
            $hasil[] = ['label' => 'Lainnya', 'options' => array_values($lainnya)];
        }

        return $hasil;
    }
    // ================= END HELPER KELOMPOKKAN JABATAN =================

    // ================= HELPER BERSAMA: URUTKAN KOLEKSI PEGAWAI SESUAI HIERARKI =================
    // Parameter & return bertipe Collection agar Intelephense (P1132) tidak
    // mengeluh (Eloquent\Collection turunan Support\Collection). Memakai satu
    // comparator (jabatan dulu, lalu nama A-Z).
    private function urutkanSesuaiHierarki(Collection $pegawais): Collection
    {
        return $pegawais
            ->sort(function ($a, $b) {
                $urutA = $this->urutanJabatan[$a->role_id] ?? 99;
                $urutB = $this->urutanJabatan[$b->role_id] ?? 99;

                if ($urutA !== $urutB) {
                    return $urutA <=> $urutB;
                }

                return strcmp(
                    mb_strtolower(trim($a->nama ?? '')),
                    mb_strtolower(trim($b->nama ?? ''))
                );
            })
            ->values();
    }
    // ================= END HELPER URUTKAN PEGAWAI =================

    // ================= HELPER BERSAMA: SYARAT BOLEH DIHAPUS =================
    // Dipakai kelolaPegawai() (tombol Hapus) dan destroyPegawai() (gembok
    // backend), agar aturannya satu sumber.

    // True bila akun dibuat kurang dari BATAS_HARI_HAPUS_PEGAWAI hari lalu.
    // created_at kosong dianggap akun lama (hapus diblokir) demi keamanan.
    private function akunMasihBaru(Pegawai $pegawai): bool
    {
        if ($pegawai->created_at === null) {
            return false;
        }

        return Carbon::parse($pegawai->created_at)
            ->greaterThan(now()->subDays(self::BATAS_HARI_HAPUS_PEGAWAI));
    }

    // True bila pegawai punya pengajuan berstatus STATUS_CUTI_BERRIWAYAT.
    private function punyaRiwayatCuti(int $pegawaiId): bool
    {
        return PengajuanCuti::where('pegawai_id', $pegawaiId)
            ->whereIn('status', self::STATUS_CUTI_BERRIWAYAT)
            ->exists();
    }
    // ================= END HELPER SYARAT BOLEH DIHAPUS =================

    // ================= HELPER: PERLINDUNGAN ADMIN SDM TERAKHIR =================
    // True bila masih ada Admin SDM aktif lain selain $kecualiId. Dipakai
    // updatePegawai() agar Admin SDM terakhir tidak diturunkan role-nya atau
    // dinonaktifkan.
    private function adaAdminAktifLain(int $kecualiId): bool
    {
        return Pegawai::where('role_id', self::ROLE_ID_ADMIN)
            ->where('id', '!=', $kecualiId)
            ->where(function ($q) {
                $q->where('status_aktif', 'Aktif')
                    ->orWhereNull('status_aktif');
            })
            ->exists();
    }
    // ================= END HELPER PERLINDUNGAN ADMIN SDM TERAKHIR =================

    // ================= HELPER: EKSTRAK TANGGAL MASUK DARI NIP =================
    // NIP 18 digit: tahun TMT di posisi 8-11, bulan TMT di posisi 12-13.
    private function extractTanggalMasukDariNip(?string $nip): ?string
    {
        if (empty($nip) || strlen($nip) !== 18 || !ctype_digit($nip)) {
            return null;
        }

        $tahunTmt = (int) substr($nip, 8, 4);
        $bulanTmt = (int) substr($nip, 12, 2);

        if ($bulanTmt < 1 || $bulanTmt > 12) {
            return null;
        }

        try {
            return Carbon::createFromDate($tahunTmt, $bulanTmt, 1)->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }
    // ================= END HELPER EKSTRAK TANGGAL MASUK DARI NIP =================

    // Rapikan teks ALL CAPS jadi Title Case (saat import Excel/CSV).
    private function toTitleCase(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return $text;
        }

        $hasil = mb_convert_case(trim($text), MB_CASE_TITLE, 'UTF-8');

        // Angka romawi ikut jadi "Ii", "Iii", "Iv"; kembalikan ke huruf besar
        // agar cocok dengan STRUKTUR_ORGANISASI.
        return preg_replace_callback('/\b(Ii|Iii|Iv)\b/', fn($m) => strtoupper($m[1]), $hasil);
    }

    // Samakan penulisan status kepegawaian dari Excel ("pns", "P3K", "P.P.P.K").
    // Mengembalikan null bila kosong / tidak dikenali (mis. CPNS tidak dipakai).
    private function normalisasiStatusKepegawaian(?string $teks): ?string
    {
        $mentah = trim((string) $teks);
        if ($mentah === '') {
            return null;
        }

        // Buang spasi, titik, strip, underscore; huruf besar
        $kunci = strtoupper((string) preg_replace('/[\s.\-_]+/', '', $mentah));

        // Alias singkat
        if (in_array($kunci, ['P3K', 'PPPK', 'P3KK'], true)) {
            return 'PPPK';
        }
        if (in_array($kunci, ['PNS', 'PN'], true)) {
            return 'PNS';
        }

        // Teks panjang dari Excel / Google Sheets
        if (
            str_contains($kunci, 'PPPK')
            || str_contains($kunci, 'PERJANJIANKERJA')
            || str_contains($kunci, 'PEGAWAIPEMERINTAH')
        ) {
            return 'PPPK';
        }
        if (
            str_contains($kunci, 'PNS')
            || str_contains($kunci, 'NEGERISIPIL')
            || str_contains($kunci, 'PEGAWAINEGERI')
        ) {
            return 'PNS';
        }

        return in_array($kunci, self::STATUS_KEPEGAWAIAN, true) ? $kunci : null;
    }

    // Tulis teks sebagai string murni (bukan rumus) dan beri quotePrefix bila
    // diawali karakter pemicu rumus. Aman terhadap Formula Injection (SEC-09).
    private function tulisTeksAman(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $koordinat, ?string $nilai): void
    {
        $nilai = (string) $nilai;

        $sheet->setCellValueExplicit($koordinat, $nilai, DataType::TYPE_STRING);

        if ($nilai !== '' && preg_match('/^[=+\-@\t\r]/', $nilai)) {
            $sheet->getStyle($koordinat)->setQuotePrefix(true);
        }
    }

    // Normalisasi teks untuk perbandingan (abaikan huruf besar/kecil & spasi ganda)
    private function kunciTeks(?string $teks): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/', ' ', (string) $teks)));
    }

    // Samakan nama kelompok dengan STRUKTUR_ORGANISASI; bila tidak cocok,
    // nilai asli dikembalikan.
    private function kanonikKelompok(?string $nilai): ?string
    {
        if ($nilai === null || trim($nilai) === '') {
            return $nilai;
        }

        $kunci = $this->kunciTeks($nilai);

        foreach (array_keys(self::STRUKTUR_ORGANISASI) as $nama) {
            if ($this->kunciTeks($nama) === $kunci) {
                return $nama;
            }
        }

        return $nilai;
    }

    // Samakan nama tim kerja dengan STRUKTUR_ORGANISASI (termasuk salah ketik
    // di ALIAS_TIM_KERJA)
    private function kanonikTimKerja(?string $nilai): ?string
    {
        if ($nilai === null || trim($nilai) === '') {
            return $nilai;
        }

        $kunci = $this->kunciTeks($nilai);

        if (isset(self::ALIAS_TIM_KERJA[$kunci])) {
            return self::ALIAS_TIM_KERJA[$kunci];
        }

        foreach (self::STRUKTUR_ORGANISASI as $daftarTim) {
            foreach ($daftarTim as $tim) {
                if ($this->kunciTeks($tim) === $kunci) {
                    return $tim;
                }
            }
        }

        return $nilai;
    }

    // Rapikan & cek kesesuaian Kelompok Substansi <-> Tim Kerja. Dipanggil di
    // storePegawai() & updatePegawai() setelah validate(). Aturan:
    //  0) Kepala Biro (6): kelompok "-" & tanpa tim; Kasubag TU (4): selalu
    //     Subbagian Tata Usaha & tanpa tim; Ketua Kelompok (3): tanpa tim.
    //  1) Kelompok tanpa tim di STRUKTUR_ORGANISASI -> Tim Kerja harus kosong.
    //  2) Tim milik kelompok LAIN ditolak.
    //  3) Tim Kerja kosong / "-" selalu boleh.
    // Bila lolos, kelompok & tim disamakan dengan penulisan resmi ($request->merge()).
    private function rapikanDanCekKelompokTim(Request $request): void
    {
        $role = (int) $request->role_id;

        if ($role === self::ROLE_ID_KEPALA_BIRO) {
            $request->merge(['kelompok_substansi' => '-', 'tim_kerja' => null]);
            return;
        }

        if ($role === 4) {
            $request->merge(['kelompok_substansi' => self::KELOMPOK_TU, 'tim_kerja' => null]);
            return;
        }

        // Ketua Kelompok: tanpa tim kerja. Lanjut ke pengecekan kelompok.
        if ($role === 3) {
            $request->merge(['tim_kerja' => null]);
        }

        $kelompok = $this->kanonikKelompok(trim((string) $request->kelompok_substansi));
        $tim = trim((string) $request->tim_kerja);
        $tim = ($tim === '' || $tim === '-') ? null : $this->kanonikTimKerja($tim);

        $pesan = null;

        if ($tim !== null) {
            $kunciKelompok = $this->kunciTeks($kelompok);
            $kunciTim = $this->kunciTeks($tim);

            foreach (self::STRUKTUR_ORGANISASI as $namaKelompok => $daftarTim) {
                $kelompokIni = $this->kunciTeks($namaKelompok) === $kunciKelompok;

                if ($kelompokIni && empty($daftarTim)) {
                    $pesan = 'Kelompok ini tidak memiliki tim kerja. Kosongkan kolom Tim Kerja.';
                    break;
                }

                if (!$kelompokIni) {
                    foreach ($daftarTim as $timResmi) {
                        if ($this->kunciTeks($timResmi) === $kunciTim) {
                            $pesan = "\"{$tim}\" adalah tim milik {$namaKelompok}, tidak cocok dengan kelompok yang dipilih.";
                            break 2;
                        }
                    }
                }
            }
        }

        if ($pesan !== null) {
            throw ValidationException::withMessages(['tim_kerja' => $pesan]);
        }

        $request->merge([
            'kelompok_substansi' => $kelompok,
            'tim_kerja' => $tim,
        ]);
    }

    // Cek jabatan Ketua Tim (role 2) / Ketua Kelompok (role 3):
    //  - Ketua Tim      : jabatan diawali "Ketua <Tim Kerja> / "
    //  - Ketua Kelompok : jabatan diawali "Ketua <Kelompok> / "
    // Saat EDIT, pengecekan hanya berjalan bila role/jabatan/kelompok/tim
    // berubah, agar data lama yang belum rapi tetap bisa diedit bagian lainnya.
    private function cekJabatanKetua(Request $request, ?Pegawai $lama = null): void
    {
        $role = (int) $request->role_id;

        if (!in_array($role, [2, 3], true)) {
            return;
        }

        if ($lama) {
            $berubah = (int) $lama->role_id !== $role
                || $this->kunciTeks($lama->jabatan) !== $this->kunciTeks($request->jabatan)
                || $this->kunciTeks($lama->kelompok_substansi) !== $this->kunciTeks($request->kelompok_substansi)
                || $this->kunciTeks($lama->tim_kerja) !== $this->kunciTeks($request->tim_kerja);

            if (!$berubah) {
                return;
            }
        }

        if ($role === 2) {
            if (empty($request->tim_kerja)) {
                throw ValidationException::withMessages([
                    'tim_kerja' => 'Ketua Tim Kerja harus memiliki Tim Kerja.',
                ]);
            }
            $awalan = 'Ketua ' . $request->tim_kerja . ' / ';
        } else {
            $awalan = 'Ketua ' . $request->kelompok_substansi . ' / ';
        }

        // kunciTeks() membuang spasi di ujung; spasi setelah "/" ditambahkan lagi
        // agar "Ketua X /Perencana" (tanpa spasi) tidak lolos.
        $kunciAwalan = $this->kunciTeks($awalan) . ' ';
        $kunciJabatan = $this->kunciTeks($request->jabatan);

        if (!str_starts_with($kunciJabatan, $kunciAwalan) || mb_strlen($kunciJabatan) <= mb_strlen($kunciAwalan)) {
            throw ValidationException::withMessages([
                'jabatan' => 'Jabatan harus diawali "' . trim($awalan) . '" diikuti jabatan fungsionalnya.',
            ]);
        }
    }

    // ================= HELPER: HITUNG HARI CUTI PER BULAN (LINTAS TAHUN) =================
    // Tanggal libur tahun laporan (key = 'Y-m-d') untuk lookup cepat.
    private function ambilTanggalLibur(int $tahun): array
    {
        return HariLibur::whereYear('tanggal', $tahun)
            ->pluck('tanggal')
            ->map(fn($t) => Carbon::parse($t)->toDateString())
            ->flip()
            ->all();
    }

    // Pecah satu pengajuan ke bucket bulan, hanya untuk hari yang jatuh di
    // $tahun. Cuti lintas tahun (mis. 31 Des 2026 - 4 Jan 2027) otomatis
    // terbagi. Hari yang dihitung: Senin-Jumat di luar tabel hari_liburs.
    private function hitungHariCutiPerBulan(PengajuanCuti $cuti, int $tahun, array $libur): array
    {
        $hasil = array_fill_keys($this->keyBulan, 0);

        $mulai = Carbon::parse($cuti->tanggal_mulai)->startOfDay();
        $selesai = Carbon::parse($cuti->tanggal_selesai ?? $cuti->tanggal_mulai)->startOfDay();

        for ($d = $mulai->copy(); $d->lte($selesai); $d->addDay()) {
            if ($d->year !== $tahun) {
                continue;
            }
            if ($d->isWeekend() || isset($libur[$d->toDateString()])) {
                continue;
            }
            $hasil[$this->keyBulan[$d->month - 1]]++;
        }

        return $hasil;
    }
    // ================= END HELPER HITUNG HARI CUTI PER BULAN =================

    // ================= PAGINATION SERVER-SIDE UNTUK REKAP LAPORAN =================
    // $paginate = true  -> rekapLaporan() (web): hanya hitung 10 pegawai di halaman aktif.
    // $paginate = false -> exportExcel(): semua pegawai.
    //
    // Pegawai tampil bila:
    //   1) punya dompet saldo_cutis ASLI (kuota_tahunan > 0, lihat
    //      dompetTahunAsli()) pada tahun laporan, DAN
    //   2) lolos filter status (hanyaAktifAtauPunyaCutiDiTahun()).
    //
    // [SINKRON HIERARKI] Memakai scope sesuaiHierarki() (model Pegawai), jadi
    // cakupan sama dengan Dashboard; akun Admin HR (role 5) sudah disingkirkan.
    //
    // [CUTI LINTAS TAHUN] Hari dihitung per hari kerja (Senin-Jumat, di luar
    // hari_liburs); hanya hari di tahun laporan yang masuk bucket bulan. Query
    // mengambil cuti yang beririsan dengan tahun laporan, bukan hanya yang
    // tanggal_mulai-nya di tahun itu.
    //
    // [KOREKSI BULANAN = SELISIH] Tiap bulan = hitungan sistem sekarang + selisih
    // koreksi Admin HR (nilai - nilai_sistem, tabel koreksi_cuti_bulanan).
    // Hitungan sistem tetap dikirim lewat `cuti_sistem` untuk angka "Sistem" di
    // form koreksi.
    //
    // [TGH IKUT TOTAL - SINKRON RKD] Tgh tahun laporan dibaca langsung dari
    // cuti_ditangguhkans (SUM jumlah_hari per tahun_asal), sumber sama dengan
    // 'potongan_tgh_tahun_ini' di RekapKuotaDetailController, sehingga Tgh hasil
    // edit manual Admin HR (baris tanpa pengajuan_asal_id) ikut terhitung. Kotak
    // per bulan tetap hanya memuat cuti disetujui; Tgh dikirim lewat key `tgh`.
    //
    // [TGH SISTEM vs EDIT ADMIN] Bagian "sistem" Tgh dihitung dari pengajuan
    // berstatus dibatalkan_ditangguhkan (hari kerja, di luar libur), bukan dari
    // pengajuan_asal_id, karena kolom itu menjadi NULL saat Admin HR mengedit
    // Tgh. Selisih total tabel vs bagian sistem dianggap hasil edit Admin HR.
    //
    // [RUMUS TOTAL = RUMUS RKD]
    //   Terpakai = (jumlah 12 bulan setelah koreksi) + Tgh tahun laporan
    //   Tanpa koreksi bulanan: (koreksi_saldo tahunan lama ?? cuti disetujui) + Tgh.
    //
    // [TOTAL SESUAI FILTER BULAN] Kolom `total` mengikuti filter bulan:
    //   - Kosong ("Semua Bulan"): Terpakai setahun (sama dengan RKD).
    //   - Terisi: cuti bulan itu (setelah koreksi) + Tgh bertanggal di bulan itu.
    //     Koreksi tahunan lama & Tgh edit manual tidak punya pembagian bulan,
    //     jadi tidak ikut. Export Excel ikut benar karena memakai fungsi ini.
    //
    // [RINCIAN TOTAL] Field per pegawai (dibaca RekapLaporan.vue & exportExcel()):
    //   'cuti'                : hari per bulan setelah koreksi
    //   'cuti_sistem'         : hari per bulan murni hitungan sistem
    //   'cuti_diedit'         : [key bulan => bool] sel yang dikoreksi Admin HR
    //   'cuti_basi'           : selalu false (koreksi berupa selisih, tak pernah
    //                           basi); dipertahankan demi kompatibilitas Vue lama
    //   'ada_koreksi_bulanan' : true bila ada minimal 1 koreksi bulanan
    //   'koreksi_tahunan_lama': koreksi_saldo tahunan lama (null bila tidak ada /
    //                           sudah digantikan koreksi bulanan)
    //   'terpakai' / 'terpakai_sistem' / 'terpakai_diedit'
    //   'tgh'                 : komponen Tgh (filter bulan: Tgh bertanggal bulan itu)
    //   'tgh_tahunan'         : total Tgh tahun laporan (setahun, untuk form edit)
    //   'tgh_sistem'          : Tgh dari pengajuan dibatalkan_ditangguhkan
    //   'tgh_diedit'          : true bila total Tgh berbeda dari bagian sistem
    //   'ada_edit'            : terpakai_diedit ATAU tgh_diedit (tanda * & kuning)
    //   'total' / 'total_sistem'
    //   'info_terpakai_edit'  : {oleh, waktu, alasan} koreksi bulanan terbaru / null
    //   'info_tgh_edit'       : null (belum tersedia)
    private function buildLaporanData(Request $request, bool $paginate = false): array
    {
        $tahun = $request->input('tahun', date('Y'));
        $search = $request->input('search');
        $kelompok = $request->input('kelompok_substansi');
        $bulanFilter = $request->input('bulan');

        // [SINKRON RKD] Sekalian muat saldo_cutis tahun laporan (eager load,
        // tanpa N+1) untuk membaca koreksi_saldo Admin HR.
        $query = Pegawai::sesuaiHierarki($request->user())
            ->with(['saldoCuti' => function ($q) use ($tahun) {
                $q->where('tahun', $tahun);
            }]);

        // Gembok kepemilikan dompet ASLI pada tahun laporan.
        $query->whereHas('saldoCuti', function (Builder $q) use ($tahun) {
            $this->dompetTahunAsli($q, (int) $tahun);
        });

        // [SINKRON RKD] Filter status pegawai (pensiun tanpa cuti di tahun
        // laporan tidak tampil).
        $this->hanyaAktifAtauPunyaCutiDiTahun($query, (int) $tahun);

        $query->when($kelompok, function (Builder $q) use ($kelompok) {
            return $q->where('kelompok_substansi', $kelompok);
        });

        if ($search) {
            $query->where(function (Builder $q) use ($search) {
                $q->where('nama', 'like', "%{$search}%")
                    ->orWhere('nip', 'like', "%{$search}%");
            });
        }

        $pegawais = $query->get();

        // Urutan hierarki dihitung atas seluruh hasil filter/search, sebelum
        // dipotong per halaman, agar konsisten antar halaman.
        $pegawais = $this->urutkanSesuaiHierarki($pegawais);

        $paginator = null;

        if ($paginate) {
            $perPage = self::REKAP_PER_PAGE;
            $page = max(1, (int) $request->input('page', 1));
            $total = $pegawais->count();

            $pegawais = $pegawais->slice(($page - 1) * $perPage, $perPage)->values();

            $paginator = new LengthAwarePaginator(
                $pegawais,
                $total,
                $perPage,
                $page,
                [
                    'path' => $request->url(),
                    'query' => $request->query(),
                ]
            );
        }

        // [CUTI LINTAS TAHUN] Hari libur tahun laporan dimuat sekali di luar
        // loop pegawai.
        $liburTahunIni = $this->ambilTanggalLibur((int) $tahun);
        $awalTahun = Carbon::create((int) $tahun, 1, 1)->toDateString();
        $akhirTahun = Carbon::create((int) $tahun, 12, 31)->toDateString();

        // [SINKRON RKD] Tgh tahun laporan dari cuti_ditangguhkans, sekali untuk
        // semua pegawai di halaman ini (tanpa N+1). Bagian "sistem" dihitung
        // terpisah dari pengajuan (di dalam loop).
        $idPegawai = $pegawais->pluck('id');

        $tghRekap = CutiDitangguhkan::where('tahun_asal', (int) $tahun)
            ->whereIn('pegawai_id', $idPegawai)
            ->selectRaw('pegawai_id, SUM(jumlah_hari) as total')
            ->groupBy('pegawai_id')
            ->get()
            ->keyBy('pegawai_id');

        // [KOREKSI BULANAN] Semua koreksi bulanan tahun laporan untuk pegawai di
        // halaman ini, sekali query, dikelompokkan per pegawai.
        $koreksiBulanan = KoreksiCutiBulanan::with('pengubah:id,nama')
            ->where('tahun', (int) $tahun)
            ->whereIn('pegawai_id', $idPegawai)
            ->get()
            ->groupBy('pegawai_id');

        // [TOTAL SESUAI FILTER BULAN] Filter bulan dihitung sekali di luar loop.
        $bulanDipilih = (int) $bulanFilter;
        $filterBulanAktif = $bulanDipilih >= 1 && $bulanDipilih <= 12;
        $keyDipilih = $filterBulanAktif ? $this->keyBulan[$bulanDipilih - 1] : null;

        $laporan = $pegawais->map(function ($pegawai) use (
            $tahun,
            $liburTahunIni,
            $awalTahun,
            $akhirTahun,
            $tghRekap,
            $koreksiBulanan,
            $filterBulanAktif,
            $keyDipilih
        ) {
            // Cuti disetujui yang beririsan dengan tahun laporan.
            $cutis = PengajuanCuti::where('pegawai_id', $pegawai->id)
                ->where('jenis_cuti', 'Cuti Tahunan')
                ->where('status', 'disetujui')
                ->where('tanggal_mulai', '<=', $akhirTahun)
                ->where('tanggal_selesai', '>=', $awalTahun)
                ->get();

            $rekapSistem = array_fill_keys($this->keyBulan, 0);

            foreach ($cutis as $cuti) {
                $perBulan = $this->hitungHariCutiPerBulan($cuti, (int) $tahun, $liburTahunIni);
                foreach ($perBulan as $key => $hari) {
                    $rekapSistem[$key] += $hari;
                }
            }

            // [KOREKSI BULANAN = SELISIH] tampil = sistem sekarang + (nilai -
            // nilai_sistem), jadi cuti baru di bulan yang sudah dikoreksi tetap
            // terhitung. $rekapBulan = angka tampil (setelah koreksi).
            $rekapBulan = $rekapSistem;
            $cutiDiedit = array_fill_keys($this->keyBulan, false);
            $cutiBasi = array_fill_keys($this->keyBulan, false); // selalu false (koreksi = selisih)
            $barisKoreksi = $koreksiBulanan->get($pegawai->id, collect());

            foreach ($barisKoreksi as $baris) {
                $key = $this->keyBulan[(int) $baris->bulan - 1] ?? null;
                if ($key === null) {
                    continue;
                }
                $selisih = (int) $baris->nilai - (int) $baris->nilai_sistem;
                $rekapBulan[$key] = max(0, (int) $rekapSistem[$key] + $selisih);
                $cutiDiedit[$key] = true;
            }

            $adaKoreksiBulanan = $barisKoreksi->isNotEmpty();

            $terbaru = $barisKoreksi->sortByDesc('updated_at')->first();
            $infoTerpakaiEdit = $terbaru
                ? [
                    'oleh' => $terbaru->pengubah->nama ?? '-',
                    'waktu' => $terbaru->updated_at?->format('d-m-Y H:i'),
                    'alasan' => $terbaru->alasan,
                ]
                : null;

            // Tgh dari PENGAJUAN (bertanggal), selalu dihitung. Dipakai untuk
            // filter bulan dan sebagai bagian "sistem" Tgh. Tgh edit manual Admin
            // HR tidak bertanggal, jadi tidak masuk di sini.
            // Sengaja tanpa whereHas('cutiDitangguhkan'): relasi itu lewat
            // pengajuan_asal_id yang menjadi NULL setelah Admin HR mengedit Tgh.
            $tghPerBulan = array_fill_keys($this->keyBulan, 0);

            $cutiTgh = PengajuanCuti::where('pegawai_id', $pegawai->id)
                ->where('jenis_cuti', 'Cuti Tahunan')
                ->where('status', 'dibatalkan_ditangguhkan')
                ->where('tanggal_mulai', '<=', $akhirTahun)
                ->where('tanggal_selesai', '>=', $awalTahun)
                ->get();

            foreach ($cutiTgh as $cuti) {
                $perBulan = $this->hitungHariCutiPerBulan($cuti, (int) $tahun, $liburTahunIni);
                foreach ($perBulan as $key => $hari) {
                    $tghPerBulan[$key] += $hari;
                }
            }

            // Tgh tahun laporan (sama dengan 'potongan_tgh_tahun_ini' di RKD),
            // dipecah menjadi bagian sistem (dari pengajuan) & selisih edit Admin HR.
            $rekapTgh = $tghRekap->get($pegawai->id);
            $tghTotal = (int) ($rekapTgh->total ?? 0);
            $tghSistem = (int) array_sum($tghPerBulan);   // dari pengajuan ditangguhkan
            $tghAdaManual = $tghTotal !== $tghSistem;     // beda = Admin HR mengedit

            // Koreksi saldo TAHUNAN lama dari Admin HR (NULL = tidak ada). Hanya
            // dipakai bila pegawai belum punya koreksi bulanan.
            $saldo = $pegawai->saldoCuti->first();
            $koreksiRaw = $saldo?->koreksi_saldo;
            $koreksiSaldo = $koreksiRaw === null ? null : max(0, (int) $koreksiRaw);

            // Rumus sama dengan RKD:
            //   Terpakai = (jumlah bulan setelah koreksi) + Tgh tahun laporan
            //   Tanpa koreksi bulanan: (koreksi tahunan lama ?? cuti disetujui) + Tgh
            $terpakaiSistem = array_sum($rekapSistem);
            if ($adaKoreksiBulanan) {
                $terpakai = array_sum($rekapBulan);
                $terpakaiDiedit = in_array(true, $cutiDiedit, true);
            } else {
                $terpakai = $koreksiSaldo ?? $terpakaiSistem;
                $terpakaiDiedit = $koreksiSaldo !== null && $koreksiSaldo !== $terpakaiSistem;
            }
            $tgh = $tghTotal;                                         // komponen "Tgh"
            $tghDiedit = $tghAdaManual;

            // [TOTAL SESUAI FILTER BULAN] Filter bulan aktif: Total = cuti bulan
            // itu (setelah koreksi) + Tgh bertanggal di bulan itu. Koreksi tahunan
            // lama & Tgh manual bersifat tahunan, jadi tidak dipakai.
            if ($filterBulanAktif) {
                $terpakai = $rekapBulan[$keyDipilih];
                $terpakaiSistem = $rekapSistem[$keyDipilih];
                $terpakaiDiedit = $cutiDiedit[$keyDipilih];
                $tgh = $tghPerBulan[$keyDipilih];
                $tghSistem = $tgh;
                $tghDiedit = false;
            }

            return [
                'id' => $pegawai->id,
                'nama' => $pegawai->nama,
                'nip' => $pegawai->nip,
                'jabatan' => $pegawai->jabatan,
                'kelompok_substansi' => $pegawai->kelompok_substansi,
                'tim_kerja' => $pegawai->tim_kerja,
                'role_id' => $pegawai->role_id,

                // Hari per bulan: setelah koreksi, hitungan sistem, penanda edit
                'cuti' => $rekapBulan,
                'cuti_sistem' => $rekapSistem,
                'cuti_diedit' => $cutiDiedit,
                'cuti_basi' => $cutiBasi,
                'ada_koreksi_bulanan' => $adaKoreksiBulanan,
                'koreksi_tahunan_lama' => $adaKoreksiBulanan ? null : $koreksiSaldo,

                // Dua komponen Total (dibaca RekapLaporan.vue & exportExcel())
                'terpakai' => $terpakai,
                'terpakai_sistem' => $terpakaiSistem,
                'terpakai_diedit' => $terpakaiDiedit,
                'tgh' => $tgh,
                'tgh_tahunan' => $tghTotal,
                'tgh_sistem' => $tghSistem,
                'tgh_diedit' => $tghDiedit,

                // Tanda * di Total & kotak kuning di Vue
                'ada_edit' => $terpakaiDiedit || $tghDiedit,
                // Total versi sistem (tanpa edit Admin HR)
                'total_sistem' => $terpakaiSistem + $tghSistem,

                // Semua Bulan: sama dengan Terpakai di RKD; filter bulan: cuti
                // bulan terpilih (+ Tgh bulan itu)
                'total' => $terpakai + $tgh,

                // Jejak edit untuk tooltip. null = belum tersedia (Vue aman).
                'info_terpakai_edit' => $infoTerpakaiEdit,
                'info_tgh_edit' => null,
            ];
        })->values();

        return [
            'laporan' => $laporan,
            'tahun' => (int) $tahun,
            'bulanFilter' => $bulanFilter ? (int) $bulanFilter : null,
            'paginator' => $paginator,
        ];
    }
    // ================= END PAGINATION SERVER-SIDE UNTUK REKAP LAPORAN =================

    // ================= HELPER BERSAMA: HITUNG SISA CUTI OTOMATIS =================
    // Memakai SaldoCuti::terpakaiEfektif() agar rumus "terpakai" satu sumber
    // dengan bagian lain aplikasi dan ikut koreksi Admin HR.
    private function hitungSisaOtomatis(int $pegawaiId, int $tahun, int $kuotaTahunan): int
    {
        $cutiTerpakai = SaldoCuti::terpakaiEfektif($pegawaiId, $tahun);

        return max(0, $kuotaTahunan - $cutiTerpakai);
    }
    // ================= END HELPER BERSAMA =================

    // ================= HELPER: PREVIEW CARRY-OVER =================
    // Memakai App\Services\CarryOverSaldoService (logika sama dengan Command
    // GenerateSaldoCutiTahunBaru), agar pratinjau dan hasil Generate tidak
    // berbeda. Hanya sisa saldo biasa; catatan Tgh tidak ikut. Hanya PREVIEW di
    // Kelola Saldo Cuti, tidak menulis ke database.
    private function hitungPreviewCarryOver(int $pegawaiId, int $tahunBaru): array
    {
        $carryDariSaldoBiasa = app(CarryOverSaldoService::class)
            ->carryDariSaldoBiasa($pegawaiId, $tahunBaru);

        return [
            'dari_saldo_biasa' => $carryDariSaldoBiasa,
            'total' => $carryDariSaldoBiasa,
        ];
    }
    // ================= END HELPER PREVIEW CARRY-OVER =================

    // 1. Menampilkan Daftar Pegawai
    //
    // Query sengaja tidak difilter status_aktif di server. Semua pegawai dikirim
    // ke Vue; KelolaPegawai.vue menyaringnya lewat dropdown "Status" (bawaan
    // 'Aktif'). Pegawai soft-deleted tersembunyi otomatis lewat SoftDeletes.
    //
    // [SINKRON HIERARKI] Scope sesuaiHierarki(): akun Admin HR (role 5) tidak
    // ditampilkan; cakupan selaras dengan Dashboard.
    //
    // Setiap pegawai membawa:
    //   - `saldo_cuti` tahun berjalan (eager load, prefill form edit saldo)
    //   - `bisa_dihapus` (boolean): pertahanan lapis 1 (pop-up dinamis)
    //   - `tanggal_masuk_form` (Y-m-d / null): kolom TMT di form edit
    //   - `status_kepegawaian` (PNS / PPPK / null)
    //
    // Props dropdown untuk KelolaPegawai.vue: daftarKelompok, daftarTimKerja,
    // timPerKelompok, daftarKelompokTanpaTim, daftarJabatanFungsional (+Grup),
    // daftarJabatanKetua (+Grup), jabatanKetuaDefault, jabatanTetap,
    // daftarStatusKepegawaian.
    public function kelolaPegawai(Request $request)
    {
        $tahun = date('Y');

        $pegawai = Pegawai::with(['saldoCuti' => function ($query) use ($tahun) {
            $query->where('tahun', $tahun);
        }])
            ->sesuaiHierarki($request->user()) // [SINKRON HIERARKI]
            ->orderBy('nama', 'asc')
            ->get();

        $pegawai = $this->urutkanSesuaiHierarki($pegawai);

        // Pertahanan lapis 1: tandai pegawai yang boleh dihapus. Satu query
        // untuk semua pegawai (bukan N+1).
        $idBerRiwayatCuti = PengajuanCuti::whereIn('pegawai_id', $pegawai->pluck('id'))
            ->whereIn('status', self::STATUS_CUTI_BERRIWAYAT)
            ->distinct()
            ->pluck('pegawai_id')
            ->all();

        $pegawai->each(function ($p) use ($idBerRiwayatCuti) {
            $p->bisa_dihapus = !in_array($p->id, $idBerRiwayatCuti, true)
                && $this->akunMasihBaru($p)
                && (int) $p->role_id !== self::ROLE_ID_ADMIN;

            // Format Y-m-d untuk <input type="date">. Jangan pakai tanggal_masuk
            // mentah: JSON-nya ISO UTC dan bisa mundur sehari.
            $p->tanggal_masuk_form = $p->tanggal_masuk
                ? Carbon::parse($p->tanggal_masuk)->toDateString()
                : null;
        });

        // Opsi dropdown: STRUKTUR_ORGANISASI (Excel) + data di database
        // 1) Mulai dari struktur resmi (lengkap, urut sesuai Excel).
        $timPerKelompok = self::STRUKTUR_ORGANISASI;

        // 2) Tambahkan kelompok/tim di database yang belum ada di struktur resmi
        //    (mis. hasil "+ Tambah baru"), penulisan disamakan agar tidak ganda.
        $nilaiValid = function ($v) {
            return $v !== null && trim($v) !== '' && trim($v) !== '-';
        };

        $ekstra = [];
        Pegawai::where('role_id', '!=', self::ROLE_ID_ADMIN)
            ->get(['kelompok_substansi', 'tim_kerja'])
            ->each(function ($row) use (&$timPerKelompok, &$ekstra, $nilaiValid) {
                $kelompok = $this->kanonikKelompok(trim((string) $row->kelompok_substansi));
                $tim = $this->kanonikTimKerja(trim((string) $row->tim_kerja));

                if (!$nilaiValid($kelompok)) {
                    return;
                }

                if (!array_key_exists($kelompok, $timPerKelompok)) {
                    $timPerKelompok[$kelompok] = [];
                    $ekstra[$kelompok] = true;
                }

                if ($nilaiValid($tim) && !in_array($tim, $timPerKelompok[$kelompok], true)) {
                    $timPerKelompok[$kelompok][] = $tim;
                }
            });

        // Kelompok bawaan Excel tetap di urutan Excel; tambahan A-Z di bawahnya.
        $kelompokBawaan = array_keys(self::STRUKTUR_ORGANISASI);
        $kelompokTambahan = array_keys($ekstra);
        sort($kelompokTambahan, SORT_NATURAL | SORT_FLAG_CASE);

        $daftarKelompok = array_values(array_merge($kelompokBawaan, $kelompokTambahan));

        // Tim tambahan diurutkan natural; tim bawaan Excel tetap di urutan aslinya.
        foreach ($timPerKelompok as $kelompok => $daftarTim) {
            $jumlahBawaan = count(self::STRUKTUR_ORGANISASI[$kelompok] ?? []);
            $bawaan = array_slice($daftarTim, 0, $jumlahBawaan);
            $tambahan = array_slice($daftarTim, $jumlahBawaan);
            sort($tambahan, SORT_NATURAL | SORT_FLAG_CASE);
            $timPerKelompok[$kelompok] = array_merge($bawaan, $tambahan);
        }

        // Daftar datar semua tim kerja (unik)
        $daftarTimKerja = array_values(array_unique(array_merge(...array_values($timPerKelompok))));

        return Inertia::render('Admin/KelolaPegawai', [
            'pegawai' => $pegawai,
            'tahunBerjalan' => $tahun,
            'daftarKelompok' => $daftarKelompok,
            'daftarTimKerja' => $daftarTimKerja,
            'timPerKelompok' => (object) $timPerKelompok,
            // Kelompok tanpa tim kerja menurut struktur resmi (Tim Kerja dikunci di form)
            'daftarKelompokTanpaTim' => array_values(array_keys(array_filter(
                self::STRUKTUR_ORGANISASI,
                function ($daftarTim) {
                    return empty($daftarTim);
                }
            ))),
            // Data dropdown Jabatan (daftar datar lama tetap dipertahankan)
            'daftarJabatanFungsional' => self::JABATAN_FUNGSIONAL_STAF,
            'daftarJabatanKetua' => self::JABATAN_FUNGSIONAL_KETUA,
            // Versi berkelompok per rumpun, untuk <optgroup> / Combobox berkelompok
            'daftarJabatanFungsionalGrup' => $this->kelompokkanJabatan(self::JABATAN_FUNGSIONAL_STAF),
            'daftarJabatanKetuaGrup' => $this->kelompokkanJabatan(self::JABATAN_FUNGSIONAL_KETUA),
            'jabatanKetuaDefault' => (object) self::JABATAN_KETUA_DEFAULT,
            'jabatanTetap' => (object) self::JABATAN_TETAP,
            // Pilihan dropdown Status Kepegawaian (PNS / PPPK)
            'daftarStatusKepegawaian' => self::STATUS_KEPEGAWAIAN,
        ]);
    }

    // ---> Menyimpan Data Pegawai Baru dari Modal <---
    //
    // Catatan NIP milik pegawai yang sudah di-soft-delete: barisnya masih ada
    // dan tetap terikat unique constraint kolom nip. Karena itu:
    //   - Validasi unique hanya memeriksa pegawai yang masih aktif
    //     (whereNull('deleted_at')).
    //   - Sebelum create(), dicek apakah NIP itu milik pegawai terhapus
    //     (onlyTrashed()). Bila ya, baris lama dipulihkan (restore) dan datanya
    //     diperbarui dari form, bukan membuat baris baru. Riwayat cuti & saldo
    //     ikut kembali.
    //
    // [PEMBATASAN ROLE ADMIN SDM] role_id hanya boleh salah satu dari
    // ROLE_ID_BISA_DIPILIH, jadi akun Admin SDM tidak bisa dibuat lewat form.
    public function storePegawai(Request $request)
    {
        $request->validate([
            'nip' => ['required', 'string', 'digits:18', 'max:50', Rule::unique('pegawais', 'nip')->whereNull('deleted_at')],
            'nama' => ['required', 'string', 'max:255'],
            'kelompok_substansi' => ['required_unless:role_id,6', 'nullable', 'string', 'max:255'],
            'tim_kerja' => ['nullable', 'string', 'max:255'],
            'jabatan' => ['required', 'string', 'max:255'],
            'status_kepegawaian' => ['required', 'string', Rule::in(self::STATUS_KEPEGAWAIAN)],
            'role_id' => ['required', 'integer', Rule::in(self::ROLE_ID_BISA_DIPILIH)],
            'tanggal_masuk' => ['nullable', 'date'],
            'no_telepon' => ['nullable', 'string', 'max:25'],
            'alamat_domisili' => ['nullable', 'string'],
            'status_aktif' => ['nullable', 'string', Rule::in(self::STATUS_PEGAWAI)],
            'kuota_tahunan' => ['nullable', 'integer', 'min:0'],
            'sisa' => ['nullable', 'integer', 'min:0'],
            'carry_forward_normal' => ['nullable', 'integer', 'min:0'],
        ], $this->pesanValidasiPegawai());

        $this->rapikanDanCekKelompokTim($request);
        $this->cekJabatanKetua($request);

        $tanggalMasuk = $request->tanggal_masuk
            ?? $this->extractTanggalMasukDariNip($request->nip)
            ?? now()->format('Y-m-d');

        // ---> PEMULIHAN PEGAWAI YANG SEBELUMNYA DIHAPUS <---
        $pegawaiLama = Pegawai::onlyTrashed()->where('nip', $request->nip)->first();

        if ($pegawaiLama) {
            $pegawaiLama->restore();

            // Field posisi/jabatan selalu ditimpa isi form. Field opsional
            // (tanggal_masuk, no_telepon, alamat_domisili, status_kepegawaian)
            // hanya menimpa bila diisi. password & is_first_login sengaja tidak
            // disentuh (Admin bisa pakai Reset Password).
            $pegawaiLama->update([
                'nama' => $request->nama,
                'kelompok_substansi' => $request->kelompok_substansi,
                'tim_kerja' => $request->tim_kerja,
                'jabatan' => $request->jabatan,
                'role_id' => $request->role_id,
                'status_aktif' => $request->status_aktif ?? 'Aktif',
                ...($request->filled('status_kepegawaian') ? ['status_kepegawaian' => $request->status_kepegawaian] : []),
                ...($request->filled('tanggal_masuk') ? ['tanggal_masuk' => $request->tanggal_masuk] : []),
                ...($request->filled('no_telepon') ? ['no_telepon' => $request->no_telepon] : []),
                ...($request->filled('alamat_domisili') ? ['alamat_domisili' => $request->alamat_domisili] : []),
            ]);

            // Pastikan ada baris saldo cuti tahun berjalan (saldo & riwayat
            // tahun sebelumnya tetap utuh).
            $tahunIni = (int) date('Y');
            $kuotaTahunan = $request->kuota_tahunan ?? 12;

            SaldoCuti::firstOrCreate(
                ['pegawai_id' => $pegawaiLama->id, 'tahun' => $tahunIni],
                [
                    'kuota_tahunan' => $kuotaTahunan,
                    'sisa' => $this->hitungSisaOtomatis($pegawaiLama->id, $tahunIni, $kuotaTahunan),
                    'carry_forward_normal' => $request->carry_forward_normal ?? 0,
                ]
            );

            return back()->with('success', "NIP {$request->nip} sebelumnya pernah dihapus. Data pegawai dipulihkan dan diperbarui, riwayat cutinya tetap utuh. Password lama tetap berlaku.");
        }

        $pegawai = Pegawai::create([
            'nip' => $request->nip,
            'nama' => $request->nama,
            'kelompok_substansi' => $request->kelompok_substansi,
            'tim_kerja' => $request->tim_kerja,
            'jabatan' => $request->jabatan,
            'status_kepegawaian' => $request->status_kepegawaian ?: null,
            'role_id' => $request->role_id,
            'tanggal_masuk' => $tanggalMasuk,
            'no_telepon' => $request->no_telepon,
            'alamat_domisili' => $request->alamat_domisili,
            // Default 'Aktif' bila tidak dikirim dari form
            'status_aktif' => $request->status_aktif ?? 'Aktif',
            'password' => Hash::make('password123'),
            'is_first_login' => true,
        ]);

        $tahunIni = (int) date('Y');
        $kuotaTahunan = $request->kuota_tahunan ?? 12;

        SaldoCuti::create([
            'pegawai_id' => $pegawai->id,
            'tahun' => $tahunIni,
            'kuota_tahunan' => $kuotaTahunan,
            'sisa' => $this->hitungSisaOtomatis($pegawai->id, $tahunIni, $kuotaTahunan),
            'carry_forward_normal' => $request->carry_forward_normal ?? 0,
        ]);

        return back()->with('success', 'Data pegawai berhasil ditambahkan.');
    }

    // ---> Mengupdate Data Pegawai dari Modal Edit <---
    //
    // [PEMBATASAN ROLE ADMIN SDM]
    //   - role_id hanya boleh salah satu dari ROLE_ID_BISA_DIPILIH.
    //   - Bila pegawai yang diedit sudah Admin SDM, role 5 tetap diizinkan untuk
    //     dirinya sendiri.
    //   - Admin SDM aktif terakhir tidak boleh diturunkan role-nya atau
    //     dinonaktifkan, agar selalu ada yang bisa mengelola pegawai.
    public function updatePegawai(Request $request, int $id)
    {
        $pegawai = Pegawai::findOrFail($id);

        // Format NIP (18 digit angka) hanya diwajibkan bila NIP-nya DIUBAH.
        // Pegawai lama yang NIP-nya belum 18 digit tetap bisa diedit data lainnya.
        $nipRules = ['required', 'string', 'max:50', 'unique:pegawais,nip,' . $id];
        if ($request->nip !== $pegawai->nip) {
            $nipRules[] = 'digits:18';
        }

        // Role yang boleh dipilih: daftar biasa + role 5 hanya bila pegawai ini
        // memang sudah Admin SDM.
        $roleBoleh = self::ROLE_ID_BISA_DIPILIH;
        $sudahAdmin = (int) $pegawai->role_id === self::ROLE_ID_ADMIN;
        if ($sudahAdmin) {
            $roleBoleh[] = self::ROLE_ID_ADMIN;
        }

        // 'unique:pegawais,nip,' . $id mengecualikan NIP pegawai ini sendiri.
        $request->validate([
            'nip' => $nipRules,
            'nama' => ['required', 'string', 'max:255'],
            'kelompok_substansi' => ['required_unless:role_id,6', 'nullable', 'string', 'max:255'],
            'tim_kerja' => ['nullable', 'string', 'max:255'],
            'jabatan' => ['required', 'string', 'max:255'],
            'status_kepegawaian' => ['required', 'string', Rule::in(self::STATUS_KEPEGAWAIAN)],
            'role_id' => ['required', 'integer', Rule::in($roleBoleh)],
            'tanggal_masuk' => ['nullable', 'date'],
            'no_telepon' => ['nullable', 'string', 'max:25'],
            'alamat_domisili' => ['nullable', 'string'],
            'status_aktif' => ['required', 'string', Rule::in(self::STATUS_PEGAWAI)],
            'kuota_tahunan' => ['nullable', 'integer', 'min:0'],
            'sisa' => ['nullable', 'integer', 'min:0'],
            'carry_forward_normal' => ['nullable', 'integer', 'min:0'],
        ], $this->pesanValidasiPegawai());

        // Lindungi Admin SDM aktif terakhir dari penurunan role / penonaktifan.
        if ($sudahAdmin) {
            $akanTurunRole = (int) $request->role_id !== self::ROLE_ID_ADMIN;
            $akanNonaktif = $request->status_aktif !== 'Aktif';

            if (($akanTurunRole || $akanNonaktif) && !$this->adaAdminAktifLain((int) $pegawai->id)) {
                throw ValidationException::withMessages([
                    ($akanTurunRole ? 'role_id' : 'status_aktif') =>
                    'Tidak dapat dilakukan: ini adalah satu-satunya Admin SDM aktif. '
                        . 'Pastikan ada Admin SDM aktif lain sebelum mengubah role atau status akun ini.',
                ]);
            }
        }

        $this->rapikanDanCekKelompokTim($request);
        $this->cekJabatanKetua($request, $pegawai);

        // Tanggal masuk: hormati edit manual; bila NIP diganti, ikutkan TMT dari NIP baru.
        $tanggalMasukLama = $pegawai->tanggal_masuk
            ? Carbon::parse($pegawai->tanggal_masuk)->toDateString()
            : null;
        $tanggalMasukForm = $request->filled('tanggal_masuk')
            ? Carbon::parse($request->tanggal_masuk)->toDateString()
            : null;

        if ($tanggalMasukForm !== null && $tanggalMasukForm !== $tanggalMasukLama) {
            // Admin sengaja mengubah tanggal masuk: hormati.
            $tanggalMasukBaru = $tanggalMasukForm;
        } elseif ($request->nip !== $pegawai->nip) {
            // NIP dikoreksi: ikutkan TMT dari NIP baru (null bila tidak terbaca).
            $tanggalMasukBaru = $this->extractTanggalMasukDariNip($request->nip);
        } else {
            $tanggalMasukBaru = $tanggalMasukForm;
        }

        $pegawai->update([
            'nip' => $request->nip,
            'nama' => $request->nama,
            'kelompok_substansi' => $request->kelompok_substansi,
            'tim_kerja' => $request->tim_kerja,
            'jabatan' => $request->jabatan,
            // Ditugaskan langsung (bukan filled()) karena wajib diisi di form.
            'status_kepegawaian' => $request->status_kepegawaian ?: null,
            'role_id' => $request->role_id,
            'status_aktif' => $request->status_aktif,
            ...($tanggalMasukBaru ? ['tanggal_masuk' => $tanggalMasukBaru] : []),
            ...($request->filled('no_telepon') ? ['no_telepon' => $request->no_telepon] : []),
            ...($request->filled('alamat_domisili') ? ['alamat_domisili' => $request->alamat_domisili] : []),
        ]);

        if ($request->filled('kuota_tahunan') || $request->filled('sisa') || $request->filled('carry_forward_normal')) {
            $tahunIni = (int) date('Y');

            $saldoSaatIni = SaldoCuti::where('pegawai_id', $pegawai->id)
                ->where('tahun', $tahunIni)
                ->first();

            $kuotaTahunan = $request->filled('kuota_tahunan')
                ? (int) $request->kuota_tahunan
                : ($saldoSaatIni->kuota_tahunan ?? 12);

            $carryForward = $request->filled('carry_forward_normal')
                ? (int) $request->carry_forward_normal
                : ($saldoSaatIni->carry_forward_normal ?? 0);

            $sisaTerhitung = $this->hitungSisaOtomatis($pegawai->id, $tahunIni, $kuotaTahunan);

            SaldoCuti::updateOrCreate(
                ['pegawai_id' => $pegawai->id, 'tahun' => $tahunIni],
                [
                    'kuota_tahunan' => $kuotaTahunan,
                    'sisa' => $sisaTerhitung,
                    'carry_forward_normal' => $carryForward,
                ]
            );
        }

        return back()->with('success', 'Data pegawai berhasil diperbarui.');
    }

    // ---> Pesan validasi berbahasa Indonesia <---
    // Dipakai storePegawai() & updatePegawai(). Khusus 'nip.unique': saat TAMBAH,
    // pesan hanya muncul untuk NIP pegawai yang masih ada (yang sudah dihapus
    // otomatis dipulihkan). Saat EDIT, pesan juga berlaku bila NIP baru bentrok
    // dengan pegawai yang sudah dihapus/diarsipkan.
    private function pesanValidasiPegawai(): array
    {
        return [
            'nip.required' => 'NIP wajib diisi.',
            'nip.max' => 'NIP maksimal 50 karakter.',
            'nip.digits' => 'NIP harus terdiri dari 18 digit angka (tanpa spasi atau tanda baca).',
            'nip.unique' => 'NIP ini sudah dipakai pegawai lain (termasuk pegawai yang sudah dihapus/diarsipkan).',
            'nama.required' => 'Nama pegawai wajib diisi.',
            'kelompok_substansi.required' => 'Kelompok substansi wajib diisi.',
            'kelompok_substansi.required_unless' => 'Kelompok substansi wajib diisi.',
            'jabatan.required' => 'Jabatan wajib diisi.',
            'status_kepegawaian.required' => 'Status kepegawaian wajib dipilih.',
            'status_kepegawaian.in' => 'Status kepegawaian tidak valid. Pilih salah satu: ' . implode(', ', self::STATUS_KEPEGAWAIAN) . '.',
            'role_id.required' => 'Hak akses (role) wajib dipilih.',
            'role_id.in' => 'Hak akses (role) tidak valid. Role Admin SDM tidak dapat dibuat atau diberikan lewat menu ini.',
            'no_telepon.max' => 'No telepon maksimal 25 karakter.',
            'status_aktif.required' => 'Status pegawai wajib dipilih.',
            'status_aktif.in' => 'Status pegawai tidak valid. Pilih salah satu: ' . implode(', ', self::STATUS_PEGAWAI) . '.',
        ];
    }

    // ---> Import Data Pegawai dari Excel/CSV <---
    // Nama, Kelompok Substansi, Tim Kerja, dan Jabatan dirapikan ke Title Case
    // lewat toTitleCase().
    //
    // Baris header dideteksi otomatis (baris pertama dalam 15 baris awal yang
    // memuat kolom "NIP"), jadi file dengan judul di baris 1-5 tetap terbaca.
    // Header dinormalisasi (huruf kecil, spasi/baris baru ganda dirapatkan).
    //
    // Kolom "Status Kepegawaian" (PNS / PPPK) opsional: hanya ditulis bila
    // kolomnya ada & nilainya dikenali, agar impor file lama tidak menghapus
    // data yang ada.
    //
    // Pengecekan NIP manual (bukan updateOrCreate) dengan withTrashed():
    //   - NIP sudah ada -> data umum diperbarui; nip/password/is_first_login tidak
    //     disentuh. Bila sebelumnya soft-deleted -> di-restore & status kembali
    //     'Aktif'. Tanggal masuk yang sudah terisi tidak ditimpa, kecuali terlihat
    //     placeholder (sama dengan tanggal akun dibuat) dan NIP bisa dibaca.
    //   - NIP belum ada -> Pegawai::create() dengan `nip`, `password`, dan
    //     `is_first_login` langsung di payload (kolom NOT NULL tanpa default).
    // SaldoCuti tahun berjalan memakai firstOrCreate(): saldo yang ada tidak
    // di-reset saat re-import; bila belum ada, dibuat dengan kuota default 12 hari.
    //
    // [PEMBATASAN ROLE ADMIN SDM]
    //   - Baris dengan role Admin dilewati (Admin SDM tidak dibuat lewat impor).
    //   - Baris yang NIP-nya milik akun Admin SDM yang ada juga dilewati, agar
    //     impor tidak mengubah/menurunkan role akun Admin SDM.
    public function importPegawai(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv'],
        ]);

        $path = $request->file('file')->getRealPath();
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, false);

        if (empty($rows)) {
            return back()->with('error', 'File kosong atau tidak terbaca.');
        }

        // Normalisasi header: huruf kecil, spasi/baris baru ganda jadi satu spasi.
        $normalisasiHeader = fn($h) => strtolower(trim((string) preg_replace('/\s+/', ' ', (string) $h)));

        // Cari baris header: baris pertama (dalam 15 baris awal) yang memuat kolom "nip".
        $barisHeader = 0;
        foreach (array_slice($rows, 0, 15, true) as $i => $r) {
            if (in_array('nip', array_map($normalisasiHeader, $r), true)) {
                $barisHeader = $i;
                break;
            }
        }

        $header = array_map($normalisasiHeader, $rows[$barisHeader]);

        $findCol = function (array $aliases) use ($header) {
            foreach ($aliases as $alias) {
                $idx = array_search($alias, $header, true);
                if ($idx !== false) {
                    return $idx;
                }
            }
            return false;
        };

        $idxNip = $findCol(['nip']);
        $idxNama = $findCol(['nama']);
        $idxKelompokSubstansi = $findCol(['kelompok substansi', 'subbagian/kelompok']);
        $idxTimKerja = $findCol(['tim kerja']);
        $idxJabatan = $findCol(['jabatan']);
        $idxStatusKepegawaian = $findCol([
            'status kepegawaian',
            'status kepegawaian (pns/pppk)',
            'jenis kepegawaian',
            'jenis asn',
            'pns/pppk',
            'status pns/pppk',
        ]);
        $idxRoleOrLevel = $findCol(['role', 'level', 'hak akses']);

        if ($idxNip === false || $idxNama === false || $idxJabatan === false || $idxRoleOrLevel === false) {
            return back()->with('error', 'Header file tidak sesuai. Pastikan ada kolom: NIP, Nama, Jabatan, dan Role/Level.');
        }

        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $tahunIni = (int) date('Y');

        for ($i = $barisHeader + 1; $i < count($rows); $i++) {
            $row = $rows[$i];

            $nip = trim((string) ($row[$idxNip] ?? ''));
            $nama = $this->toTitleCase((string) ($row[$idxNama] ?? ''));
            $jabatan = $this->toTitleCase((string) ($row[$idxJabatan] ?? ''));
            $roleTeks = trim((string) ($row[$idxRoleOrLevel] ?? '')); // dipetakan ke role_id, tanpa title case
            $kelompokSubstansi = $idxKelompokSubstansi !== false ? $this->toTitleCase((string) ($row[$idxKelompokSubstansi] ?? '')) : '';
            $timKerja = $idxTimKerja !== false ? $this->toTitleCase((string) ($row[$idxTimKerja] ?? '')) : null;

            if ($timKerja === '-' || $timKerja === '') {
                $timKerja = null;
            }

            // Samakan penulisan dengan STRUKTUR_ORGANISASI & perbaiki salah ketik tim.
            $kelompokSubstansi = $this->kanonikKelompok($kelompokSubstansi);
            $timKerja = $this->kanonikTimKerja($timKerja);

            $statusKepegawaian = $idxStatusKepegawaian !== false
                ? $this->normalisasiStatusKepegawaian((string) ($row[$idxStatusKepegawaian] ?? ''))
                : null;

            if ($nip === '' && $nama === '') {
                continue;
            }

            if ($nip === '' || $nama === '' || $jabatan === '' || $roleTeks === '') {
                $skipped++;
                continue;
            }

            $roleId = $this->mapRoleOrLevelTeks($roleTeks);
            if ($roleId === null) {
                $skipped++;
                continue;
            }

            // [PEMBATASAN ROLE ADMIN SDM] Role Admin tidak dibuat lewat impor.
            if (!in_array($roleId, self::ROLE_ID_BISA_DIPILIH, true)) {
                $skipped++;
                continue;
            }

            $tanggalMasuk = $this->extractTanggalMasukDariNip($nip) ?? now()->format('Y-m-d');

            // withTrashed() agar pegawai yang sudah di-soft-delete tetap terdeteksi
            // (kalau tidak, create() ditolak MySQL karena unique constraint).
            $pegawai = Pegawai::withTrashed()->where('nip', $nip)->first();

            // [PEMBATASAN ROLE ADMIN SDM] Akun Admin SDM yang ada tidak boleh
            // diubah lewat impor.
            if ($pegawai && (int) $pegawai->role_id === self::ROLE_ID_ADMIN) {
                $skipped++;
                continue;
            }

            $dataUmum = [
                'nama' => $nama,
                'kelompok_substansi' => $kelompokSubstansi ?: '-',
                'tim_kerja' => $timKerja,
                'jabatan' => $jabatan,
                'role_id' => $roleId,
                'tanggal_masuk' => $tanggalMasuk,
            ];

            // Hanya diisi bila kolomnya ada & nilainya dikenali, agar impor file
            // lama (tanpa kolom ini) tidak menghapus data yang ada.
            if ($statusKepegawaian !== null) {
                $dataUmum['status_kepegawaian'] = $statusKepegawaian;
            }

            if ($pegawai) {
                if ($pegawai->trashed()) {
                    $pegawai->restore();
                    // Pegawai yang dipulihkan otomatis 'Aktif' lagi
                    $dataUmum['status_aktif'] = 'Aktif';
                }

                // Jangan menimpa tanggal masuk yang sudah terisi (bisa hasil
                // koreksi manual), KECUALI terlihat placeholder (sama dengan
                // tanggal akun dibuat) dan NIP bisa dibaca.
                if (!empty($pegawai->tanggal_masuk)) {
                    $samaDenganDibuat = $pegawai->created_at
                        && Carbon::parse($pegawai->tanggal_masuk)->toDateString()
                        === Carbon::parse($pegawai->created_at)->toDateString();

                    $tanggalDariNip = $this->extractTanggalMasukDariNip($nip);

                    if (!($samaDenganDibuat && $tanggalDariNip)) {
                        unset($dataUmum['tanggal_masuk']);
                    }
                }

                $pegawai->update($dataUmum);
                $isBaru = false;
            } else {
                $pegawai = Pegawai::create([
                    'nip' => $nip,
                    ...$dataUmum,
                    'status_aktif' => 'Aktif',
                    'password' => Hash::make('password123'),
                    'is_first_login' => true,
                ]);
                $isBaru = true;
            }

            // Daftarkan ke Rekap Kuota Cuti tahun berjalan bila belum ada
            // (firstOrCreate), sehingga saldo pegawai lama tidak ter-reset.
            $kuotaTahunan = 12;
            SaldoCuti::firstOrCreate(
                [
                    'pegawai_id' => $pegawai->id,
                    'tahun' => $tahunIni,
                ],
                [
                    'kuota_tahunan' => $kuotaTahunan,
                    'sisa' => $this->hitungSisaOtomatis($pegawai->id, $tahunIni, $kuotaTahunan),
                    'carry_forward_normal' => 0,
                ]
            );

            if ($isBaru) {
                $inserted++;
            } else {
                $updated++;
            }
        }

        return back()->with(
            'success',
            "Berhasil mengimpor: {$inserted} pegawai baru ditambahkan, {$updated} pegawai diperbarui. {$skipped} baris dilewati (data kosong, role/level tidak dikenali, atau role Admin)."
        );
    }

    private function mapRoleOrLevelTeks(string $teks): ?int
    {
        $key = strtolower(trim($teks));

        return match (true) {
            $key === 'staff', $key === 'staf' => 1,
            $key === 'level 1' => 2,
            $key === 'level 2' => 3,
            $key === 'level 3' => 4,
            $key === 'level 4' => self::ROLE_ID_KEPALA_BIRO,
            str_contains($key, 'kepala biro') => self::ROLE_ID_KEPALA_BIRO,
            str_contains($key, 'kasubag') => 4,
            str_contains($key, 'ketua kelompok') => 3,
            str_contains($key, 'ketua tim') => 2,
            str_contains($key, 'admin') => self::ROLE_ID_ADMIN,
            str_contains($key, 'karyawan'), str_contains($key, 'staf'), str_contains($key, 'staff') => 1,
            default => null,
        };
    }

    // ---> Reset Password Pegawai yang Lupa Sandi <---
    public function resetPasswordPegawai(int $id)
    {
        $pegawai = Pegawai::findOrFail($id);

        $pegawai->update([
            'password' => Hash::make('password123'),
            'is_first_login' => true,
        ]);

        return back()->with('success', "Password untuk {$pegawai->nama} berhasil direset ke default. Pegawai wajib menggantinya saat login berikutnya.");
    }

    // ---> Menghapus Data Pegawai (hanya untuk koreksi kesalahan administratif) <---
    // Route: DELETE /admin/pegawai/{id} (name: admin.pegawai.destroy).
    //
    // Pegawai Resign / Pensiun tidak dihapus, melainkan diubah Status Pegawainya
    // lewat EDIT.
    //
    // Pertahanan lapis 2 (gembok backend): request langsung ke endpoint ini
    // divalidasi ulang dengan helper yang sama dengan kelolaPegawai(). Ditolak bila:
    //   - pegawai punya riwayat cuti (STATUS_CUTI_BERRIWAYAT), ATAU
    //   - akun berusia >= BATAS_HARI_HAPUS_PEGAWAI hari, ATAU
    //   - akun adalah Admin SDM (role 5).
    // Pesan penolakan lewat error key 'hapus' (toast merah di KelolaPegawai.vue).
    //
    // Pegawai memakai SoftDeletes: delete() hanya mengisi `deleted_at` (bisa
    // dipulihkan lewat storePegawai()). SaldoCuti tidak dihapus.
    public function destroyPegawai(Request $request, int $id)
    {
        // Hanya Admin HR yang boleh menghapus data induk pegawai.
        abort_unless((int) $request->user()->role_id === self::ROLE_ID_ADMIN, 403);

        $pegawai = Pegawai::findOrFail($id);

        // Admin tidak boleh menghapus akunnya sendiri.
        if ((int) $pegawai->id === (int) $request->user()->id) {
            return back()->withErrors([
                'hapus' => 'Akses Ditolak. Anda tidak dapat menghapus akun Anda sendiri.',
            ]);
        }

        // Akun Admin SDM tidak boleh dihapus lewat menu ini.
        if ((int) $pegawai->role_id === self::ROLE_ID_ADMIN) {
            return back()->withErrors([
                'hapus' => 'Akses Ditolak. Akun Admin SDM tidak dapat dihapus lewat menu ini.',
            ]);
        }

        $bolehDihapus = !$this->punyaRiwayatCuti($pegawai->id)
            && $this->akunMasihBaru($pegawai);

        if (!$bolehDihapus) {
            return back()->withErrors([
                'hapus' => 'Akses Ditolak. Pegawai sudah tercatat sebagai karyawan aktif/memiliki riwayat. Silakan ubah status menjadi Pensiun/Non-Aktif.',
            ]);
        }

        $pegawai->delete();

        return back()->with('success', 'Data pegawai berhasil dihapus.');
    }

    /**
     * Hapus massal pegawai (soft delete).
     * Route: DELETE /admin/pegawai/destroy-massal (name: admin.pegawai.destroy-massal).
     *
     * Helper sama dengan destroyPegawai() / kelolaPegawai(): belum punya riwayat
     * cuti, akun masih baru (< BATAS_HARI_HAPUS_PEGAWAI hari), dan bukan Admin SDM.
     * Pegawai yang tidak memenuhi syarat dilewati (batch tidak digagalkan).
     * Admin tidak bisa menghapus akunnya sendiri.
     */
    public function destroyMassal(Request $request)
    {
        abort_unless((int) $request->user()->role_id === self::ROLE_ID_ADMIN, 403);

        $data = $request->validate([
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct', 'exists:pegawais,id'],
        ], [
            'ids.required' => 'Pilih minimal satu pegawai.',
            'ids.min'      => 'Pilih minimal satu pegawai.',
        ]);

        $ids = $data['ids'];
        $adminId = (int) $request->user()->id;

        $kandidat = Pegawai::whereIn('id', $ids)->get();

        $bolehDihapus = $kandidat->filter(function (Pegawai $pegawai) use ($adminId) {
            // Tidak boleh menghapus akun sendiri
            if ((int) $pegawai->id === $adminId) {
                return false;
            }

            // Akun Admin SDM tidak boleh dihapus lewat menu ini
            if ((int) $pegawai->role_id === self::ROLE_ID_ADMIN) {
                return false;
            }

            return !$this->punyaRiwayatCuti((int) $pegawai->id)
                && $this->akunMasihBaru($pegawai);
        });

        if ($bolehDihapus->isEmpty()) {
            return back()->withErrors([
                'hapus' => 'Tidak ada pegawai terpilih yang memenuhi syarat untuk dihapus. '
                    . 'Pegawai yang sudah punya riwayat cuti, akun lebih dari '
                    . self::BATAS_HARI_HAPUS_PEGAWAI . ' hari, akun Admin SDM, atau akun Anda sendiri tidak bisa dihapus.',
            ]);
        }

        $idYangDihapus = $bolehDihapus->pluck('id')->all();
        $jumlah = count($idYangDihapus);

        // Soft delete (model Pegawai memakai SoftDeletes)
        Pegawai::whereIn('id', $idYangDihapus)->delete();

        $dilewati = count($ids) - $jumlah;

        $pesan = "{$jumlah} pegawai berhasil dihapus.";
        if ($dilewati > 0) {
            $pesan .= " {$dilewati} pegawai dilewati karena tidak memenuhi syarat hapus.";
        }

        return back()->with('success', $pesan);
    }

    // 2. Menampilkan Daftar Saldo Cuti Pegawai Tahun Ini
    //
    // Setiap pegawai dilengkapi `preview_carry_over` (`dari_saldo_biasa`, `total`):
    // perkiraan carry-over andai saldo digenerate lewat Command
    // GenerateSaldoCutiTahunBaru. Hanya preview untuk Admin, bukan nilai tersimpan.
    //
    // [SINKRON HIERARKI] Scope sesuaiHierarki(); akun Admin HR (role 5) tidak tampil.
    public function kelolaSaldo(Request $request)
    {
        $tahun = date('Y');

        $pegawai = Pegawai::with(['saldoCuti' => function ($query) use ($tahun) {
            $query->where('tahun', $tahun);
        }])
            ->sesuaiHierarki($request->user()) // [SINKRON HIERARKI]
            ->orderBy('nama', 'asc')
            ->get();

        $pegawai->each(function ($p) use ($tahun) {
            $p->preview_carry_over = $this->hitungPreviewCarryOver($p->id, (int) $tahun);
        });

        return Inertia::render('Admin/KelolaSaldo', [
            'pegawai' => $pegawai,
            'tahun' => $tahun,
        ]);
    }

    // 3. Memperbarui atau Membuat Saldo Cuti Baru
    //
    // Catatan Tgh hanya untuk pelaporan, bukan hitungan Terpakai atau carry-over.
    //
    // Setiap kali Admin mengubah `sisa` secara manual dari angka yang ada,
    // perubahan dicatat sebagai satu baris di `histori_saldos` (siapa, kapan,
    // berapa), hanya bila `sisa` benar-benar berubah.
    public function updateSaldo(Request $request, int $id)
    {
        abort_unless((int) $request->user()->role_id === self::ROLE_ID_ADMIN, 403);

        $request->validate([
            'kuota_tahunan' => ['required', 'numeric', 'min:0'],
            'carry_forward_normal' => ['nullable', 'numeric', 'min:0', 'required_without:sisa'],
            'sisa' => ['nullable', 'numeric', 'min:0', 'required_without:carry_forward_normal'],
        ]);

        $tahun = date('Y');

        $saldoSebelumnya = SaldoCuti::where('pegawai_id', $id)->where('tahun', $tahun)->first();
        $sisaSebelumnya = $saldoSebelumnya->sisa ?? null;

        // filled() (bukan has()) agar input kosong/null tidak menimpa nilai lama dengan null.
        $sisa = $request->filled('sisa')
            ? $request->sisa
            : ($saldoSebelumnya->sisa ?? 0);
        $carryForward = $request->filled('carry_forward_normal')
            ? $request->carry_forward_normal
            : ($saldoSebelumnya->carry_forward_normal ?? 0);

        $saldo = SaldoCuti::updateOrCreate(
            ['pegawai_id' => $id, 'tahun' => $tahun],
            [
                'kuota_tahunan' => $request->kuota_tahunan,
                'sisa' => $sisa,
                'carry_forward_normal' => $carryForward,
            ]
        );

        // Catat ke histori hanya bila ada baris sebelumnya DAN nilai sisa berubah.
        if ($sisaSebelumnya !== null && (int) $sisaSebelumnya !== (int) $sisa) {
            HistoriSaldo::create([
                'saldo_cuti_id' => $saldo->id,
                'pegawai_id' => $id,
                'jumlah_penyesuaian' => (int) $sisa - (int) $sisaSebelumnya,
                'alasan' => 'Penyesuaian manual oleh Admin HR lewat halaman Kelola Saldo Cuti.',
                // $request->user()->id (bukan auth()->id()) untuk menghindari Intelephense P1013.
                'diubah_oleh' => $request->user()->id,
                'tanggal_perubahan' => now(),
            ]);
        }

        return back()->with('success', 'Saldo cuti berhasil diperbarui.');
    }

    // ================= GENERATE SALDO TAHUN BARU (TOMBOL MANUAL) =================
    // Dipanggil dari tombol "Generate Saldo Tahun Baru" di Rekap Kuota Detail.
    // Sengaja hanya memanggil Artisan Command `saldo-cuti:generate-tahun-baru`
    // yang sama dengan scheduler tiap 1 Januari, jadi hasil manual dan otomatis
    // identik. Berguna bila scheduler gagal jalan, atau untuk generate ulang
    // setelah koreksi data.
    //
    // Kode keluar command diperiksa. Bila command menolak (mis. tahun di luar
    // batas), pesannya dikembalikan sebagai error di field 'tahun' (tampil di
    // modal), bukan banner sukses.
    public function generateSaldoManual(Request $request)
    {
        abort_unless((int) $request->user()->role_id === self::ROLE_ID_ADMIN, 403);

        $request->validate([
            'tahun' => ['nullable', 'integer', 'between:2000,2100'],
        ]);

        $tahun = (int) $request->input('tahun', date('Y'));

        $kode = Artisan::call('saldo-cuti:generate-tahun-baru', [
            '--tahun' => $tahun,
        ]);
        $keluaran = trim(Artisan::output());

        if ($kode !== 0) {
            return back()->withErrors([
                'tahun' => $keluaran !== '' ? $keluaran : "Generate saldo tahun {$tahun} gagal.",
            ]);
        }

        return back()->with('success', $keluaran !== '' ? $keluaran : "Saldo cuti tahun {$tahun} berhasil digenerate.");
    }
    // ================= END GENERATE SALDO MANUAL =================

    // 4. Menampilkan Rekap Laporan Cuti Seluruh Pegawai (per bulan, dengan pagination)
    public function rekapLaporan(Request $request)
    {
        // paginate=true, ambil 10 pegawai per halaman
        $data = $this->buildLaporanData($request, true);

        // Dropdown kelompok memakai aturan yang sama dengan buildLaporanData():
        // dompet ASLI + filter status pegawai + scope hierarki. Tanpa ini,
        // kelompok yang isinya hanya pegawai pensiun tetap muncul tetapi
        // hasilnya kosong.
        $queryKelompok = Pegawai::sesuaiHierarki($request->user())
            ->whereHas('saldoCuti', function (Builder $q) use ($data) {
                $this->dompetTahunAsli($q, (int) $data['tahun']);
            });
        $this->hanyaAktifAtauPunyaCutiDiTahun($queryKelompok, (int) $data['tahun']);

        $daftarKelompok = $queryKelompok
            ->whereNotNull('kelompok_substansi')
            ->where('kelompok_substansi', '!=', '')
            ->distinct()
            ->orderBy('kelompok_substansi')
            ->pluck('kelompok_substansi');

        return Inertia::render('Admin/RekapLaporan', [
            'laporan' => $data['laporan'],
            'tahun' => $data['tahun'],
            'filters' => $request->only(['search', 'tahun', 'bulan', 'kelompok_substansi']),
            'daftarKelompokSubstansi' => $daftarKelompok,
            // [KOREKSI BULANAN] Hanya Admin HR yang boleh mengedit sel bulan & Tgh.
            'bisaEdit' => (int) $request->user()->role_id === self::ROLE_ID_ADMIN,
            // info pagination dikirim ke frontend
            'pagination' => [
                'current_page' => $data['paginator']->currentPage(),
                'last_page' => $data['paginator']->lastPage(),
                'per_page' => $data['paginator']->perPage(),
                'total' => $data['paginator']->total(),
            ],
        ]);
    }

    // ================= KOREKSI BULANAN REKAP LAPORAN =================
    // Route: POST /admin/rekap-laporan/{id}/koreksi (name: admin.rekap.koreksi).
    // Hanya Admin HR (role 5). {id} = id pegawai.
    //
    // Payload:
    //   tahun : tahun laporan (wajib)
    //   cuti  : objek [key bulan => angka], key = jan,feb,...,des. Hanya bulan
    //           yang dikirim yang diproses. Angka sama dengan hitungan sistem,
    //           atau kosong/null, berarti koreksi bulan itu dihapus.
    //   tgh   : total Tgh tahun laporan (opsional), disimpan lewat
    //           RekapKuotaDetailController::setTotalDitangguhkan() yang sama
    //           dengan Rekap Kuota Detail.
    //
    // [KOREKSI = SELISIH] Angka yang diketik disimpan sebagai selisih terhadap
    // hitungan sistem saat itu:
    //   `nilai`        = angka yang diketik (angka tampil saat koreksi dibuat)
    //   `nilai_sistem` = hitungan sistem saat koreksi dibuat
    //   selisih        = nilai - nilai_sistem
    // Angka tampil seterusnya = hitungan sistem sekarang + selisih, jadi cuti
    // baru yang disetujui di bulan itu tetap terhitung.
    //
    // Aturan:
    //   - Terpakai efektif baru tidak boleh melebihi Total Hak Tersedia
    //     (Hak + Bawaan Eligible + Tgh tahun lalu), sama dengan batas di RKD.
    //   - Koreksi bulanan pertama pada suatu tahun menggantikan koreksi_saldo
    //     tahunan lama: koreksi_saldo & kolom koreksi_* dikosongkan.
    //   - Setelah tersimpan: saldo `sisa` disegarkan, bawaan tahun depan
    //     disinkronkan (SaldoCuti::sinkronkanBawaan), perubahan dicatat ke
    //     LogPerubahanSaldo, dan pegawai dinotifikasi bila angka Terpakai yang
    //     dilihatnya berubah.
    public function updateKoreksiLaporan(Request $request, int $id)
    {
        abort_unless((int) $request->user()->role_id === self::ROLE_ID_ADMIN, 403);

        $request->validate([
            'tahun' => ['required', 'integer', 'between:2000,2100'],
            'cuti' => ['nullable', 'array'],
            'cuti.*' => ['nullable', 'integer', 'between:0,31'],
            'tgh' => ['nullable', 'integer', 'between:0,365'],
        ], [
            'cuti.*.integer' => 'Jumlah hari cuti harus berupa angka bulat.',
            'cuti.*.between' => 'Jumlah hari cuti per bulan harus antara 0 dan 31.',
            'tgh.integer' => 'Jumlah Tgh harus berupa angka bulat.',
            'tgh.between' => 'Jumlah Tgh harus antara 0 dan 365.',
        ]);

        $pegawai = Pegawai::findOrFail($id);
        abort_if((int) $pegawai->role_id === self::ROLE_ID_ADMIN, 404);

        $tahun = (int) $request->input('tahun');
        $pegawaiId = (int) $pegawai->id;

        $saldo = SaldoCuti::where('pegawai_id', $pegawaiId)->where('tahun', $tahun)->first();
        if (!$saldo) {
            throw ValidationException::withMessages([
                'cuti' => "Pegawai ini belum punya saldo cuti tahun {$tahun}.",
            ]);
        }

        // ---- Hitung perubahan per bulan (belum menulis apa pun) ----
        // $deltaLama / $deltaBaru = [bulan => selisih koreksi (nilai - nilai_sistem)].
        $kiriman = (array) $request->input('cuti', []);
        $sistemPerBulan = SaldoCuti::terpakaiMurniPerBulan($pegawaiId, $tahun);
        $deltaLama = SaldoCuti::petaSelisihTahun($pegawaiId, $tahun);
        $deltaBaru = $deltaLama;
        $perubahanBulan = [];

        foreach ($this->keyBulan as $idx => $key) {
            if (!array_key_exists($key, $kiriman)) {
                continue;
            }

            $bulan = $idx + 1;
            $nilai = $kiriman[$key];
            $sistem = (int) ($sistemPerBulan[$bulan] ?? 0);

            if ($nilai === null || $nilai === '' || (int) $nilai === $sistem) {
                unset($deltaBaru[$bulan]);                    // kembali ke hitungan sistem
            } else {
                $deltaBaru[$bulan] = (int) $nilai - $sistem;  // simpan sebagai selisih
            }

            $tampilLama = max(0, $sistem + ($deltaLama[$bulan] ?? 0));
            $tampilBaru = max(0, $sistem + ($deltaBaru[$bulan] ?? 0));

            if ($tampilLama !== $tampilBaru) {
                $perubahanBulan[] = [
                    'bulan' => $bulan,
                    'sistem' => $sistem,
                    'lama' => $tampilLama,
                    'baru' => $tampilBaru,
                ];
            }
        }

        // ---- Tgh (opsional) ----
        $tghDikirim = $request->has('tgh')
            && $request->input('tgh') !== null
            && $request->input('tgh') !== '';
        $tghTarget = $tghDikirim ? (int) $request->input('tgh') : null;
        $tghLama = (int) CutiDitangguhkan::where('pegawai_id', $pegawaiId)
            ->where('tahun_asal', $tahun)
            ->sum('jumlah_hari');
        $tghBerubah = $tghDikirim && $tghTarget !== $tghLama;

        if (empty($perubahanBulan) && !$tghBerubah) {
            return back()->with('success', 'Tidak ada angka yang berubah.');
        }

        // ---- Validasi batas: Terpakai tidak boleh melebihi Total Hak Tersedia ----
        $terpakaiLama = SaldoCuti::terpakaiEfektif($pegawaiId, $tahun, $saldo);
        $murni = SaldoCuti::terpakaiMurni($pegawaiId, $tahun);

        if (!empty($deltaBaru)) {
            $terpakaiHitung = max(0, $murni + array_sum($deltaBaru));
        } else {
            $terpakaiHitung = $saldo->koreksi_saldo !== null
                ? max(0, (int) $saldo->koreksi_saldo)
                : $murni;
        }

        $hak = (int) $saldo->kuota_tahunan;
        $carry = (int) $saldo->carry_forward_normal;
        $duaTahunLalu = (int) (SaldoCuti::where('pegawai_id', $pegawaiId)
            ->where('tahun', $tahun - 1)
            ->value('carry_forward_normal') ?? 0);
        $tghTahunLalu = (int) CutiDitangguhkan::where('pegawai_id', $pegawaiId)
            ->where('tahun_asal', $tahun - 1)
            ->sum('jumlah_hari');
        $maksimal = $hak + SaldoCuti::hitungBawaan($duaTahunLalu, $carry, $hak) + $tghTahunLalu;

        if (!empty($perubahanBulan) && $terpakaiHitung > $maksimal) {
            throw ValidationException::withMessages([
                'cuti' => "Gagal menyimpan. Cuti terpakai ({$terpakaiHitung} hari) melebihi total hak yang tersedia ({$maksimal} hari).",
            ]);
        }

        // ---- Tulis ke database (satu transaksi) ----
        $terpakaiBaru = $terpakaiLama;

        DB::transaction(function () use (
            $pegawaiId,
            $tahun,
            $saldo,
            $request,
            $perubahanBulan,
            $deltaBaru,
            $tghBerubah,
            $tghTarget,
            $tghLama,
            $terpakaiLama,
            &$terpakaiBaru
        ) {
            foreach ($perubahanBulan as $p) {
                if (isset($deltaBaru[$p['bulan']])) {
                    // nilai = angka tampil saat ini, nilai_sistem = hitungan sistem
                    // saat ini; selisihnya = $deltaBaru[bulan].
                    KoreksiCutiBulanan::updateOrCreate(
                        ['pegawai_id' => $pegawaiId, 'tahun' => $tahun, 'bulan' => $p['bulan']],
                        [
                            'nilai' => $p['baru'],
                            'nilai_sistem' => $p['sistem'],
                            'diubah_oleh' => $request->user()->id,
                        ]
                    );
                } else {
                    KoreksiCutiBulanan::where('pegawai_id', $pegawaiId)
                        ->where('tahun', $tahun)
                        ->where('bulan', $p['bulan'])
                        ->delete();
                }
            }

            // Koreksi bulanan menggantikan koreksi tahunan lama.
            if (!empty($perubahanBulan) && !empty($deltaBaru) && $saldo->koreksi_saldo !== null) {
                DB::table('saldo_cutis')
                    ->where('pegawai_id', $pegawaiId)
                    ->where('tahun', $tahun)
                    ->update([
                        'koreksi_saldo' => null,
                        'koreksi_alasan' => null,
                        'koreksi_terpakai_sistem' => null,
                        'koreksi_oleh' => null,
                        'koreksi_pada' => null,
                    ]);
            }

            // Tgh: satu jalur dengan Rekap Kuota Detail.
            if ($tghBerubah) {
                app(RekapKuotaDetailController::class)
                    ->setTotalDitangguhkan($pegawaiId, $tahun, $tghTarget);
            }

            // Angka efektif sesudah tersimpan (satu rumus dengan halaman lain).
            $saldoSegar = SaldoCuti::where('pegawai_id', $pegawaiId)->where('tahun', $tahun)->first();
            $terpakaiBaru = SaldoCuti::terpakaiEfektif($pegawaiId, $tahun, $saldoSegar);

            if ($saldoSegar) {
                $saldoSegar->update([
                    'sisa' => max(0, (int) $saldoSegar->kuota_tahunan - $terpakaiBaru),
                ]);
            }

            // Jejak perubahan: Terpakai (satu baris, rincian bulan di alasan) & Tgh.
            if (!empty($perubahanBulan) && $terpakaiLama !== $terpakaiBaru) {
                $rincian = collect($perubahanBulan)
                    ->map(fn($p) => ($this->labelBulan[$p['bulan'] - 1]) . " {$p['lama']} → {$p['baru']}")
                    ->implode(', ');

                LogPerubahanSaldo::create([
                    'pegawai_id' => $pegawaiId,
                    'diubah_oleh' => $request->user()->id,
                    'tahun' => $tahun,
                    'kolom' => 'terpakai',
                    'nilai_lama' => $terpakaiLama,
                    'nilai_baru' => $terpakaiBaru,
                    'alasan' => Str::limit('Rekap Laporan: ' . $rincian, 480),
                ]);
            }

            if ($tghBerubah) {
                LogPerubahanSaldo::create([
                    'pegawai_id' => $pegawaiId,
                    'diubah_oleh' => $request->user()->id,
                    'tahun' => $tahun,
                    'kolom' => 'tgh_n0',
                    'nilai_lama' => $tghLama,
                    'nilai_baru' => $tghTarget,
                    'alasan' => 'Rekap Laporan: edit total Tgh',
                ]);
            }
        });

        // [AUTO-SYNC] Terpakai / Tgh berubah -> bawaan tahun depan dihitung ulang.
        SaldoCuti::sinkronkanBawaan($pegawaiId, $tahun);

        // Notifikasi ke pegawai bila angka Terpakai yang dilihatnya berubah.
        // 'koreksi' non-null hanya untuk memilih kalimat ("disesuaikan menjadi ...").
        app(RekapKuotaDetailController::class)->kirimNotifikasiPerubahanSaldo(
            $pegawai,
            $tahun,
            [
                'hak' => $hak,
                'carry' => $carry,
                'dua_tahun_lalu' => $duaTahunLalu,
                'koreksi' => !empty($deltaLama) ? $terpakaiLama : null,
                'terpakai' => $terpakaiLama,
            ],
            [
                'hak' => $hak,
                'carry' => $carry,
                'dua_tahun_lalu' => $duaTahunLalu,
                'koreksi' => !empty($deltaBaru) ? $terpakaiBaru : null,
                'terpakai' => $terpakaiBaru,
            ],
            $terpakaiBaru
        );

        return back()->with('success', 'Koreksi cuti ' . $pegawai->nama . ' tahun ' . $tahun . ' berhasil disimpan.');
    }
    // ================= END KOREKSI BULANAN REKAP LAPORAN =================

    // 5. Export Data Rekap Cuti ke Excel
    //
    // Kolom: No | Nama | NIP | bulan... | Tgh | Total Cuti | Keterangan
    //   - Kolom Jabatan sengaja tidak ada di file Excel.
    //   - Bulan      : angka setelah koreksi Admin HR; sel yang dikoreksi kuning.
    //   - Tgh        : hari cuti ditangguhkan (sudah termasuk di Total Cuti)
    //   - Total Cuti : Terpakai + Tgh (sama dengan Rekap Kuota Detail)
    //   - Keterangan : format tetap "Terpakai: X. Tgh: Y." Bila ada angka yang
    //                  dikoreksi Admin HR, baris diberi "*" di depan dan warna
    //                  kuning (tanpa teks "semula").
    //   - Freeze pane: hanya baris header (baris 1-5). Kolom No/Nama/NIP sengaja
    //                  tidak dibekukan agar mudah digeser.
    public function exportExcel(Request $request)
    {
        // Export mengambil semua data (paginate=false): file Excel harus berisi
        // seluruh pegawai, bukan hanya halaman aktif.
        $data = $this->buildLaporanData($request);
        $laporan = $data['laporan'];
        $tahun = $data['tahun'];
        $bulanFilter = $data['bulanFilter'];

        // [FILTER BULAN] true bila export dibuat dengan filter bulan terpilih.
        $filterBulanAktif = (bool) ($bulanFilter && isset($this->keyBulan[$bulanFilter - 1]));

        if ($filterBulanAktif) {
            $exportKeyBulan = [$this->keyBulan[$bulanFilter - 1]];
            $exportLabelBulan = [$this->labelBulan[$bulanFilter - 1]];
        } else {
            $exportKeyBulan = $this->keyBulan;
            $exportLabelBulan = $this->labelBulan;
        }

        $bulanBerjalanIdx = null;
        if ($tahun == date('Y')) {
            $currentKey = $this->keyBulan[(int) date('n') - 1];
            $idxInExport = array_search($currentKey, $exportKeyBulan);
            $bulanBerjalanIdx = $idxInExport !== false ? $idxInExport : null;
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle("Rekap Cuti {$tahun}");

        $nBulan = count($exportLabelBulan);
        $bulanStartCol = 4; // D (setelah No, Nama, NIP)

        // [FILTER BULAN] Kolom Tgh hanya ada di mode "Semua Bulan".
        $tghCol = $filterBulanAktif ? null : $bulanStartCol + $nBulan;
        $totalCol = $filterBulanAktif ? $bulanStartCol + $nBulan : $tghCol + 1;
        $ketCol = $totalCol + 1;              // kolom Keterangan (paling kanan)
        $lastColLetter = Coordinate::stringFromColumnIndex($ketCol);

        // ---------- Warna ----------
        $GREEN_DARK = '1D9E75';
        $GREEN_LIGHT = 'E1F5EE';
        $GREEN_HEADER = '97C459';
        $GREEN_TOTAL = '639922';
        $AMBER = 'FAC775';
        $BLUE_BADGE = '85B7EB';
        $TOTAL_ROW_BG = 'FAEEDA';
        $ZEBRA = 'F4F8F0';
        $ORANGE_TGH = 'FED7AA';
        $KUNING_EDIT = 'FEF3C7'; // sel bulan yang dikoreksi Admin HR

        // ---------- Judul ----------
        $sheet->mergeCells("A1:{$lastColLetter}1");
        $sheet->setCellValue('A1', 'REKAPITULASI CUTI TAHUNAN PEGAWAI — TAHUN ' . $tahun);
        $sheet->getStyle('A1')->getFont()->setName('Arial')->setSize(14)->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($GREEN_DARK);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(26);

        $sheet->mergeCells("A2:{$lastColLetter}2");
        // Sistem ini untuk Biro Perencanaan (lihat STRUKTUR_ORGANISASI).
        $sheet->setCellValue('A2', 'Kementerian Pertanian RI — Biro Perencanaan');
        $sheet->getStyle('A2')->getFont()->setName('Arial')->setSize(10)->getColor()->setRGB('04342C');
        $sheet->getStyle('A2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($GREEN_LIGHT);
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(2)->setRowHeight(18);
        $sheet->getRowDimension(3)->setRowHeight(6);

        // ---------- Header 2 tingkat ----------
        $headerRow1 = 4;
        $headerRow2 = 5;
        $headersFixed = ['No', 'Nama Pegawai', 'NIP'];

        foreach ($headersFixed as $idx => $label) {
            $col = $idx + 1;
            $letter = Coordinate::stringFromColumnIndex($col);
            $sheet->mergeCells("{$letter}{$headerRow1}:{$letter}{$headerRow2}");
            $sheet->setCellValue("{$letter}{$headerRow1}", $label);
            $style = $sheet->getStyle("{$letter}{$headerRow1}:{$letter}{$headerRow2}");
            $style->getFont()->setName('Arial')->setSize(10)->setBold(true)->getColor()->setRGB('FFFFFF');
            $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('26215C');
            $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
            $style->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('B4B2A9');
        }

        $bulanStartLetter = Coordinate::stringFromColumnIndex($bulanStartCol);
        $bulanEndLetter = Coordinate::stringFromColumnIndex($bulanStartCol + $nBulan - 1);
        $sheet->mergeCells("{$bulanStartLetter}{$headerRow1}:{$bulanEndLetter}{$headerRow1}");
        $sheet->setCellValue("{$bulanStartLetter}{$headerRow1}", 'Jumlah Hari Cuti per Bulan');
        $sheet->getStyle("{$bulanStartLetter}{$headerRow1}:{$bulanEndLetter}{$headerRow1}")->applyFromArray([
            'font' => ['name' => 'Arial', 'size' => 10, 'bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $GREEN_HEADER]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
        ]);

        foreach ($exportLabelBulan as $i => $label) {
            $col = $bulanStartCol + $i;
            $letter = Coordinate::stringFromColumnIndex($col);
            $sheet->setCellValue("{$letter}{$headerRow2}", $label);
            $isCurrent = $bulanBerjalanIdx !== null && $i === $bulanBerjalanIdx;
            $sheet->getStyle("{$letter}{$headerRow2}")->applyFromArray([
                'font' => [
                    'name' => 'Arial',
                    'size' => 10,
                    'bold' => true,
                    'color' => ['rgb' => $isCurrent ? '412402' : 'FFFFFF'],
                ],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $isCurrent ? $AMBER : $GREEN_HEADER]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
            ]);
        }

        // Header kolom Tgh, Total Cuti, dan Keterangan (masing-masing digabung 2 baris)
        $tghLetter = $tghCol !== null ? Coordinate::stringFromColumnIndex($tghCol) : null;
        $totalLetter = Coordinate::stringFromColumnIndex($totalCol);
        $ketLetter = Coordinate::stringFromColumnIndex($ketCol);

        $headerKanan = [];
        if (!$filterBulanAktif) {
            $headerKanan[] = [$tghLetter, 'Tgh', 'C2410C'];
        }
        $headerKanan[] = [$totalLetter, 'Total Cuti', $GREEN_TOTAL];
        $headerKanan[] = [$ketLetter, 'Keterangan', '5F5E5A'];

        foreach ($headerKanan as [$huruf, $judul, $warna]) {
            $sheet->mergeCells("{$huruf}{$headerRow1}:{$huruf}{$headerRow2}");
            $sheet->setCellValue("{$huruf}{$headerRow1}", $judul);
            $sheet->getStyle("{$huruf}{$headerRow1}:{$huruf}{$headerRow2}")->applyFromArray([
                'font' => ['name' => 'Arial', 'size' => 10, 'bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $warna]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
            ]);
        }

        $sheet->getRowDimension($headerRow1)->setRowHeight(18);
        $sheet->getRowDimension($headerRow2)->setRowHeight(16);

        // ---------- Data ----------
        $dataStartRow = 6;
        $r = $dataStartRow;

        foreach ($laporan as $i => $item) {
            $zebra = $i % 2 === 1 ? $ZEBRA : null;

            $sheet->setCellValue("A{$r}", $i + 1);
            $sheet->setCellValueExplicit("C{$r}", (string) $item['nip'], DataType::TYPE_STRING);

            $sheet->getStyle("A{$r}")->applyFromArray([
                'font' => ['name' => 'Arial', 'size' => 10],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
                'fill' => $zebra ? ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $zebra]] : [],
            ]);
            $sheet->getStyle("B{$r}")->applyFromArray([
                'font' => ['name' => 'Arial', 'size' => 10],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
                'fill' => $zebra ? ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $zebra]] : [],
            ]);
            $sheet->getStyle("C{$r}")->applyFromArray([
                'font' => ['name' => 'Arial', 'size' => 10],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
                'fill' => $zebra ? ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $zebra]] : [],
            ]);

            // Nama: string murni + quotePrefix (aman Formula Injection SEC-09).
            // Dipanggil setelah applyFromArray agar quotePrefix tidak tertimpa style.
            $this->tulisTeksAman($sheet, "B{$r}", $item['nama'] ?? '');

            foreach ($exportKeyBulan as $j => $key) {
                $col = $bulanStartCol + $j;
                $letter = Coordinate::stringFromColumnIndex($col);
                $val = $item['cuti'][$key] ?? 0;
                $sheet->setCellValue("{$letter}{$r}", $val);

                // [KOREKSI BULANAN] Sel yang dikoreksi Admin HR: kuning, menimpa
                // warna biru/zebra.
                $diedit = (bool) ($item['cuti_diedit'][$key] ?? false);

                if ($diedit) {
                    $sheet->getStyle("{$letter}{$r}")->applyFromArray([
                        'font' => ['name' => 'Arial', 'size' => 10, 'bold' => true, 'color' => ['rgb' => '92400E']],
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $KUNING_EDIT]],
                    ]);
                } elseif ($val > 0) {
                    $sheet->getStyle("{$letter}{$r}")->applyFromArray([
                        'font' => ['name' => 'Arial', 'size' => 10, 'bold' => true, 'color' => ['rgb' => '042C53']],
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $BLUE_BADGE]],
                    ]);
                } else {
                    $sheet->getStyle("{$letter}{$r}")->applyFromArray([
                        'font' => ['name' => 'Arial', 'size' => 10, 'color' => ['rgb' => '888780']],
                        'fill' => $zebra ? ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $zebra]] : [],
                    ]);
                }
                $sheet->getStyle("{$letter}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
                $sheet->getStyle("{$letter}{$r}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('B4B2A9');
            }

            // [FILTER BULAN] Hanya dibuat di mode "Semua Bulan".
            if (!$filterBulanAktif) {
                $tghVal = (int) ($item['tgh'] ?? 0);
                $sheet->setCellValue("{$tghLetter}{$r}", $tghVal);
                $sheet->getStyle("{$tghLetter}{$r}")->applyFromArray([
                    'font' => [
                        'name' => 'Arial',
                        'size' => 10,
                        'bold' => $tghVal > 0,
                        'color' => ['rgb' => $tghVal > 0 ? '7C2D12' : '888780'],
                    ],
                    'fill' => $tghVal > 0
                        ? ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $ORANGE_TGH]]
                        : ($zebra ? ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $zebra]] : []),
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
                ]);
            }

            // [FILTER BULAN] Total = Terpakai bulan itu saja (tanpa Tgh).
            $totalVal = $filterBulanAktif
                ? (int) ($item['terpakai'] ?? 0)
                : $item['total'];
            $sheet->setCellValue("{$totalLetter}{$r}", $totalVal);
            $sheet->getStyle("{$totalLetter}{$r}")->applyFromArray([
                'font' => ['name' => 'Arial', 'size' => 10, 'bold' => true, 'color' => ['rgb' => '173404']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EAF3DE']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
            ]);

            // Keterangan ringkas, format tetap: "Terpakai: 3. Tgh: 2." (tanpa Tgh
            // bila filter bulan). "*" di depan (plus warna kuning) bila ada
            // komponen yang dikoreksi Admin HR.
            $ket = ($item['ada_edit'] ? '* ' : '')
                . 'Terpakai: ' . (int) $item['terpakai'] . '.'
                . ($filterBulanAktif ? '' : ' Tgh: ' . (int) $item['tgh'] . '.');
            $sheet->getStyle("{$ketLetter}{$r}")->applyFromArray([
                'font' => ['name' => 'Arial', 'size' => 9, 'color' => ['rgb' => $item['ada_edit'] ? '92400E' : '5F5E5A']],
                'fill' => $item['ada_edit']
                    ? ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FEF3C7']]
                    : ($zebra ? ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $zebra]] : []),
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
            ]);
            // Dipanggil setelah applyFromArray agar quotePrefix tidak tertimpa style.
            $this->tulisTeksAman($sheet, "{$ketLetter}{$r}", $ket);

            $r++;
        }

        $dataEndRow = $r - 1;

        // ---------- Baris Total Keseluruhan ----------
        $totalRow = $dataEndRow + 2;
        $sheet->mergeCells("A{$totalRow}:C{$totalRow}");
        $sheet->setCellValue("A{$totalRow}", 'Total Keseluruhan');
        $sheet->getStyle("A{$totalRow}:C{$totalRow}")->applyFromArray([
            'font' => ['name' => 'Arial', 'size' => 10, 'bold' => true, 'color' => ['rgb' => '412402']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $TOTAL_ROW_BG]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
        ]);

        foreach ($exportKeyBulan as $j => $key) {
            $col = $bulanStartCol + $j;
            $letter = Coordinate::stringFromColumnIndex($col);
            $sheet->setCellValue("{$letter}{$totalRow}", "=SUM({$letter}{$dataStartRow}:{$letter}{$dataEndRow})");
            $sheet->getStyle("{$letter}{$totalRow}")->applyFromArray([
                'font' => ['name' => 'Arial', 'size' => 10, 'bold' => true, 'color' => ['rgb' => '412402']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $TOTAL_ROW_BG]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
            ]);
        }

        // Total kolom Tgh (hanya "Semua Bulan")
        if (!$filterBulanAktif) {
            $sheet->setCellValue("{$tghLetter}{$totalRow}", "=SUM({$tghLetter}{$dataStartRow}:{$tghLetter}{$dataEndRow})");
            $sheet->getStyle("{$tghLetter}{$totalRow}")->applyFromArray([
                'font' => ['name' => 'Arial', 'size' => 10, 'bold' => true, 'color' => ['rgb' => '412402']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $TOTAL_ROW_BG]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
            ]);
        }

        $sheet->setCellValue("{$totalLetter}{$totalRow}", "=SUM({$totalLetter}{$dataStartRow}:{$totalLetter}{$dataEndRow})");
        $sheet->getStyle("{$totalLetter}{$totalRow}")->applyFromArray([
            'font' => ['name' => 'Arial', 'size' => 11, 'bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $GREEN_DARK]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
        ]);

        // ---------- Legenda warna (memanjang ke bawah) ----------
        $legendTitleRow = $totalRow + 2;
        $sheet->setCellValue("A{$legendTitleRow}", 'Keterangan warna:');
        $sheet->getStyle("A{$legendTitleRow}")->getFont()->setName('Arial')->setSize(9)->setItalic(true)->setBold(true)->getColor()->setRGB('5F5E5A');

        $legends = [
            ['Ada cuti di bulan tersebut', $BLUE_BADGE],
            ['Bulan berjalan', $AMBER],
            ['Angka bulan dikoreksi Admin HR', $KUNING_EDIT],
        ];

        // [FILTER BULAN] Legenda Tgh hanya relevan saat kolom Tgh ada.
        if (!$filterBulanAktif) {
            $legends[] = ['Tgh = cuti yang sudah disetujui lalu ditangguhkan (sudah termasuk di Total Cuti)', $ORANGE_TGH];
        }

        foreach ($legends as $i => $item) {
            [$label, $color] = $item;
            $row = $legendTitleRow + 1 + $i;
            $sheet->getStyle("A{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($color);
            $sheet->getStyle("A{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('B4B2A9');
            $sheet->mergeCells("B{$row}:C{$row}");
            $sheet->setCellValue("B{$row}", $label);
            $sheet->getStyle("B{$row}")->getFont()->setName('Arial')->setSize(9)->getColor()->setRGB('2C2C2A');
            $sheet->getStyle("B{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getRowDimension($row)->setRowHeight(16);
        }

        // Catatan penjelasan singkat di bawah legenda warna
        $catatanRow = $legendTitleRow + 1 + count($legends) + 1;
        $catatan = [
            $filterBulanAktif
                ? 'Total Cuti = jumlah cuti pada bulan yang dipilih.'
                : 'Total Cuti = Terpakai + Tgh.',
            'Tanda * di Keterangan = ada angka yang dikoreksi Admin HR.',
        ];

        foreach ($catatan as $k => $teks) {
            $row = $catatanRow + $k;
            $sheet->mergeCells("A{$row}:C{$row}");
            $sheet->setCellValue("A{$row}", $teks);
            $sheet->getStyle("A{$row}")->getFont()->setName('Arial')->setSize(9)->setItalic(true)->getColor()->setRGB('5F5E5A');
            $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setVertical(Alignment::VERTICAL_CENTER);
        }

        // ---------- Lebar kolom ----------
        $sheet->getColumnDimension('A')->setWidth(5);
        $sheet->getColumnDimension('B')->setWidth(38);
        $sheet->getColumnDimension('C')->setWidth(20);
        foreach ($exportKeyBulan as $j => $key) {
            $letter = Coordinate::stringFromColumnIndex($bulanStartCol + $j);
            // [FILTER BULAN] Satu kolom bulan dibuat lebih lebar agar "September" tidak terpotong.
            $sheet->getColumnDimension($letter)->setWidth($filterBulanAktif ? 12 : 6);
        }
        if (!$filterBulanAktif) {
            $sheet->getColumnDimension($tghLetter)->setWidth(8);
        }
        $sheet->getColumnDimension($totalLetter)->setWidth(11);
        $sheet->getColumnDimension($ketLetter)->setWidth(60);

        // Bekukan hanya baris header (baris 1-5). Kolom No/Nama/NIP tidak
        // dibekukan agar tabel bebas digeser ke kiri-kanan.
        $sheet->freezePane('A' . $dataStartRow);

        $filename = "Rekap_Cuti_Tahunan_{$tahun}_" . date('Y-m-d') . '.xlsx';

        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    // 6. Menangguhkan cuti (hanya L4 / Kepala Biro)
    //
    // [PERBAIKAN TGH] Method ini sengaja hanya meneruskan ke
    // PembatalanController::process(), agar satu-satunya logika penangguhan ada
    // di sana. Sebelumnya method ini hanya mengubah status & notifikasi, tanpa
    // baris CutiDitangguhkan (penyebab kolom TGH di Rekap Kuota Detail 0) dan
    // tanpa ApprovalLog. Sekarang kedua jalur identik:
    //   - status -> dibatalkan_ditangguhkan
    //   - ApprovalLog (keputusan 'tangguh', level 5)
    //   - CutiDitangguhkan (hanya Cuti Tahunan yang sebelumnya disetujui)
    //   - saldo sisa dikembalikan & notifikasi ke pegawai
    // Semua di dalam DB::transaction milik process().
    public function suspendCuti(Request $request, int $id)
    {
        abort_unless(
            (int) $request->user()->role_id === self::ROLE_ID_KEPALA_BIRO,
            403,
            'Hanya L4 yang dapat menangguhkan cuti.'
        );

        return app(PembatalanController::class)->process($request, $id);
    }

    // 7. Menampilkan Halaman Kelola Hari Libur
    public function kelolaLibur()
    {
        $libur = HariLibur::where('tanggal', '>=', Carbon::today()->toDateString())
            ->orderBy('tanggal', 'asc')
            ->paginate(7);

        return Inertia::render('Admin/KelolaLibur', [
            'libur' => $libur,
        ]);
    }

    // 8. Menyimpan Data Hari Libur Baru
    public function storeLibur(Request $request)
    {
        $request->validate([
            'tanggal' => ['required', 'date', 'unique:hari_liburs,tanggal'],
            'keterangan' => ['required', 'string', 'max:255'],
            'is_cuti_bersama' => ['required', 'boolean'],
        ], [
            'tanggal.unique' => 'Tanggal ini sudah didaftarkan sebagai hari libur.',
        ]);

        HariLibur::create([
            'tanggal' => $request->tanggal,
            'keterangan' => $request->keterangan,
            'is_cuti_bersama' => $request->is_cuti_bersama,
        ]);

        return back();
    }

    // 9. Mengimpor Data Hari Libur dari File CSV
    public function importLiburCsv(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt'],
        ]);

        $file = $request->file('file');
        $path = $file->getRealPath();
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return back()->with('error', 'File CSV tidak dapat dibuka.');
        }

        // Deteksi delimiter otomatis (',' atau ';') dari baris pertama.
        $delimiter = ',';
        $firstLine = fgets($handle);

        if ($firstLine !== false) {
            $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        }

        rewind($handle);

        $inserted = 0;
        $skipped = 0;
        $headerSkipped = false;

        while (($row = fgetcsv($handle, 1000, $delimiter)) !== false) {
            $row = array_map(function ($value) {
                return trim((string) $value);
            }, $row);

            if (empty(array_filter($row, fn($value) => $value !== ''))) {
                continue;
            }

            $firstCell = strtolower($row[0] ?? '');
            if (!$headerSkipped && in_array($firstCell, ['tanggal', 'date', 'tgl'], true)) {
                $headerSkipped = true;
                continue;
            }

            if (count($row) < 2) {
                $skipped++;
                continue;
            }

            $tanggal = $row[0];
            $keterangan = $row[1];
            $isCutiBersama = $row[2] ?? 'false';

            if (empty($tanggal) || empty($keterangan)) {
                $skipped++;
                continue;
            }

            try {
                $parsedDate = Carbon::parse($tanggal)->format('Y-m-d');
            } catch (\Exception $e) {
                $skipped++;
                continue;
            }

            if (HariLibur::whereDate('tanggal', $parsedDate)->exists()) {
                $skipped++;
                continue;
            }

            $jenis = strtolower((string) $isCutiBersama);
            $isCutiBersamaFlag = in_array($jenis, ['1', 'true', 'yes', 'ya', 'cuti bersama', 'cuti_bersama'], true);

            HariLibur::create([
                'tanggal' => $parsedDate,
                'keterangan' => $keterangan,
                'is_cuti_bersama' => $isCutiBersamaFlag ? 1 : 0,
            ]);

            $inserted++;
        }

        fclose($handle);

        return back()->with(
            'success',
            "Berhasil mengimpor {$inserted} hari libur dari CSV. {$skipped} baris dilewati."
        );
    }

    // 10. Menghapus Data Hari Libur
    public function destroyLibur(int $id)
    {
        HariLibur::findOrFail($id)->delete();

        return back();
    }
}
