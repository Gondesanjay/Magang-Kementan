<?php

namespace App\Http\Controllers;

use App\Models\Pegawai;
use App\Models\PengajuanCuti;
use App\Models\SaldoCuti;
use App\Models\HistoriSaldo;
use App\Models\HariLibur;
use App\Models\Notifikasi;
use App\Services\CarryOverSaldoService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder; // dipakai type-hint closure di buildLaporanData()
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection; // dipakai type-hint di urutkanSesuaiHierarki()
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule; // dipakai untuk validasi status_aktif & role_id (Rule::in)
use Illuminate\Validation\ValidationException; // dipakai oleh rapikanDanCekKelompokTim() & cekJabatanKetua()
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class AdminController extends Controller
{
    private array $keyBulan = ['jan', 'feb', 'mar', 'apr', 'mei', 'jun', 'jul', 'agu', 'sep', 'okt', 'nov', 'des'];
    private array $labelBulan = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    // Role ID akun Admin (HR/Kepegawaian), BUKAN pegawai/atasan biasa.
    // Dipusatkan sebagai konstanta supaya konsisten di seluruh controller.
    private const ROLE_ID_ADMIN = 5;

    // Role ID Kepala Biro (L4 / approval tertinggi). Dipakai suspendCuti().
    private const ROLE_ID_KEPALA_BIRO = 6;

    // ================= PEMBATASAN ROLE ADMIN SDM =================
    // Role yang BOLEH dipilih saat menambah pegawai baru / mengubah role lewat
    // form Kelola Pegawai & Impor. Role Admin SDM (5) sengaja TIDAK ada di sini,
    // jadi tidak bisa dibuat atau diberikan lewat menu ini.
    //
    // Pengecualian: pegawai yang SUDAH berperan Admin SDM tetap boleh diedit
    // datanya (role 5 tetap diizinkan khusus untuk dirinya sendiri, lihat
    // updatePegawai()). Sisi tampilan: KelolaPegawai.vue menyembunyikan opsi
    // Admin SDM kecuali pegawai yang diedit memang sudah Admin SDM.
    private const ROLE_ID_BISA_DIPILIH = [1, 2, 3, 4, 6];
    // ================= END PEMBATASAN ROLE ADMIN SDM =================

    // Jumlah baris per halaman untuk Rekap Laporan (server-side pagination)
    private const REKAP_PER_PAGE = 10;

    // Daftar nilai status pegawai yang diizinkan. Kalau perlu status lain,
    // tambahkan di array ini DAN di dropdown Status Pegawai pada KelolaPegawai.vue.
    private const STATUS_PEGAWAI = ['Aktif', 'Pensiun', 'Mutasi Keluar', 'Resign / Berhenti', 'Meninggal Dunia'];

    // ================= GEMBOK PENGHAPUSAN PEGAWAI (2 LAPIS PERTAHANAN) =================
    // Fitur hapus HANYA untuk koreksi kesalahan administratif (data duplikat,
    // salah ketik NIP, batal penempatan). Pegawai baru boleh dihapus kalau
    // KEDUA syarat terpenuhi:
    //   1) belum punya riwayat pemakaian cuti (status di STATUS_CUTI_BERRIWAYAT), dan
    //   2) akunnya didaftarkan kurang dari BATAS_HARI_HAPUS_PEGAWAI hari yang lalu.
    //
    // Lapis 1 (UI, pop-up dinamis): kelolaPegawai() mengirim flag `bisa_dihapus`
    //                     per pegawai; KelolaPegawai.vue menampilkan modal konfirmasi
    //                     "Ya, Hapus" kalau true, atau modal info "Tidak Bisa Dihapus"
    //                     kalau false.
    // Lapis 2 (Backend)  : destroyPegawai() memvalidasi ulang dengan helper yang SAMA
    //                     (akunMasihBaru() & punyaRiwayatCuti()).
    // Kalau tidak memenuhi syarat, Admin diarahkan mengubah Status Kepegawaian
    // lewat EDIT (Pensiun / Resign / dsb).
    //
    // Tambahan: akun Admin SDM (role 5) tidak pernah boleh dihapus lewat menu ini.
    private const BATAS_HARI_HAPUS_PEGAWAI = 7;

    // Status pengajuan cuti yang dianggap "sudah ada riwayat pemakaian cuti".
    private const STATUS_CUTI_BERRIWAYAT = ['disetujui', 'dibatalkan_ditangguhkan'];
    // ================= END GEMBOK PENGHAPUSAN PEGAWAI =================

    // ================= STRUKTUR ORGANISASI BIRO PERENCANAAN =================
    // Sumber: file "Nama-Nama Pegawai Biro Perencanaan" (per 20 Agustus 2026).
    // Format: 'Nama Kelompok' => ['Tim Kerja 1', 'Tim Kerja 2', ...]
    // Array kosong = kelompok tanpa tim kerja (mis. Subbagian Tata Usaha).
    // Penulisan mengikuti toTitleCase() ("Dan" berhuruf kapital), kecuali
    // angka romawi (I, II, III, IV) yang tetap huruf besar.
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

    // Salah ketik yang ditemukan di file Excel -> nama tim yang benar.
    // Kunci ditulis huruf kecil.
    private const ALIAS_TIM_KERJA = [
        'tim kerja bahan penyiapan koordinasi' => 'Tim Kerja Penyiapan Bahan Koordinasi',
    ];

    // Nama kelompok Kasubag TU (harus sama dengan kunci di STRUKTUR_ORGANISASI)
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

    // Jabatan fungsional yang dipakai KETUA Tim / Ketua Kelompok di Excel
    // (bagian sesudah tanda "/"). Hanya ini yang ditawarkan saat admin memilih "Ubah".
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
    // Mapping role_id -> urutan tampil (angka lebih kecil = tampil lebih atas).
    //   role_id 6 -> Kepala Biro Perencanaan (L4)
    //   role_id 4 -> Kasubag TU (L3)
    //   role_id 3 -> Ketua Kelompok Substansi (L2)
    //   role_id 2 -> Ketua Tim Kerja (L1)
    //   role_id 1 -> Pegawai / Staf
    // role_id lain otomatis ditempatkan paling bawah (?? 99).
    private array $urutanJabatan = [
        6 => 1,
        4 => 2,
        3 => 3,
        2 => 4,
        1 => 5,
    ];
    // ================= END URUTAN HIERARKI JABATAN =================

    // ================= HELPER: GEMBOK DOMPET ASLI =================
    // Baris saldo_cutis dianggap "dompet ASLI" (hasil generate / input hak
    // tahunan oleh Admin) kalau kuota_tahunan > 0.
    //
    // SAMA PERSIS dengan dompetTahunAsli() di RekapKuotaDetailController.
    // Baris "bayangan" tahun-1 (kuota_tahunan = 0, hanya untuk carry-forward)
    // TIDAK dianggap dompet, sehingga tidak memunculkan tahun lalu di Rekap Laporan.
    //
    // CATATAN: pegawai yang haknya sengaja diisi 0 pada suatu tahun tidak akan
    // tampil di Rekap Laporan tahun tersebut (kasus sangat jarang).
    private function dompetTahunAsli(Builder $query, int $tahun): Builder
    {
        return $query->where('tahun', $tahun)
            ->where('kuota_tahunan', '>', 0);
    }
    // ================= END HELPER GEMBOK DOMPET ASLI =================

    // ================= HELPER: FILTER STATUS KEPEGAWAIAN =================
    // SAMA PERSIS dengan hanyaAktifAtauPunyaCutiDiTahun() di
    // RekapKuotaDetailController, supaya Rekap Laporan & Rekap Kuota Detail
    // selalu menampilkan pegawai yang sama.
    //
    //   - Pegawai AKTIF (atau status_aktif NULL) selalu tampil.
    //   - Pegawai NON-AKTIF (pensiun, mutasi keluar, dst.) HANYA tampil kalau
    //     pada tahun laporan punya Cuti Tahunan yang disetujui atau
    //     ditangguhkan setelah disetujui (arsip tahun-tahun lalu tetap utuh).
    //
    // Dasarnya riwayat cuti, BUKAN keberadaan baris saldo_cutis, sehingga saldo
    // "siluman" hasil Generate Saldo Tahun Baru untuk pegawai yang sudah pensiun
    // tidak membuatnya muncul di tahun yang baru.
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
    // ================= END HELPER FILTER STATUS KEPEGAWAIAN =================

    // ================= HELPER: KELOMPOKKAN JABATAN UNTUK DROPDOWN =================
    // Mengubah daftar datar jabatan fungsional menjadi grup per rumpun jabatan
    // untuk <optgroup> / Combobox berkelompok. Rumpun diambil dengan membuang
    // jenjang di akhir nama (Ahli Pertama/Muda/Madya/Utama, Mahir, Terampil):
    //   "Perencana Ahli Muda" -> "Perencana"
    // Rumpun dengan 1 jabatan digabung ke grup "Lainnya" (selalu paling akhir).
    //
    // Hasil: [ ['label' => 'Perencana', 'options' => [...]], ... ]
    // Daftar datar lama tetap dikirim ke frontend, jadi Vue lama tidak rusak.
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
    // Parameter & return bertipe Collection supaya Intelephense (P1132) tidak
    // mengeluh. Eloquent\Collection turunan dari Support\Collection.
    //
    // Memakai SATU comparator (jabatan dulu, lalu nama A-Z), jadi tidak
    // bergantung pada kestabilan sort dua tahap.
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
    // Dipakai oleh kelolaPegawai() (tombol Hapus di UI) DAN destroyPegawai()
    // (gembok backend), supaya aturannya SATU sumber.

    // True kalau akun didaftarkan kurang dari BATAS_HARI_HAPUS_PEGAWAI hari lalu.
    // created_at kosong dianggap akun lama (hapus diblokir) demi keamanan.
    private function akunMasihBaru(Pegawai $pegawai): bool
    {
        if ($pegawai->created_at === null) {
            return false;
        }

        return Carbon::parse($pegawai->created_at)
            ->greaterThan(now()->subDays(self::BATAS_HARI_HAPUS_PEGAWAI));
    }

    // True kalau pegawai sudah punya pengajuan cuti berstatus STATUS_CUTI_BERRIWAYAT.
    private function punyaRiwayatCuti(int $pegawaiId): bool
    {
        return PengajuanCuti::where('pegawai_id', $pegawaiId)
            ->whereIn('status', self::STATUS_CUTI_BERRIWAYAT)
            ->exists();
    }
    // ================= END HELPER SYARAT BOLEH DIHAPUS =================

    // ================= HELPER: PERLINDUNGAN ADMIN SDM TERAKHIR =================
    // True kalau masih ada Admin SDM AKTIF lain selain $kecualiId. Dipakai
    // updatePegawai() supaya Admin SDM terakhir tidak bisa diturunkan role-nya
    // atau dinonaktifkan (kalau tidak, tidak ada lagi yang bisa mengelola pegawai).
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

    // Rapikan teks ALL CAPS jadi Title Case. Dipakai saat import Excel/CSV,
    // supaya "IGNATIUS AGUS HENDARTO" jadi "Ignatius Agus Hendarto".
    private function toTitleCase(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return $text;
        }

        $hasil = mb_convert_case(trim($text), MB_CASE_TITLE, 'UTF-8');

        // Angka romawi ikut berubah jadi "Ii", "Iii", "Iv" oleh Title Case.
        // Dikembalikan ke huruf besar supaya cocok dengan STRUKTUR_ORGANISASI.
        return preg_replace_callback('/\b(Ii|Iii|Iv)\b/', fn($m) => strtoupper($m[1]), $hasil);
    }

    // Tulis teks sebagai STRING murni (bukan rumus) dan beri quotePrefix
    // bila diawali karakter pemicu rumus. Aman terhadap Formula Injection (SEC-09).
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

    // Samakan nama kelompok dengan penulisan di STRUKTUR_ORGANISASI.
    // Kalau tidak ada yang cocok, nilai aslinya dikembalikan apa adanya.
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

    // Samakan nama tim kerja dengan STRUKTUR_ORGANISASI (termasuk salah ketik di ALIAS_TIM_KERJA)
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

    // Rapikan & cek kesesuaian Kelompok Substansi <-> Tim Kerja.
    // Dipanggil di storePegawai() & updatePegawai() setelah validate().
    // Aturan:
    //  0) Role khusus: Kepala Biro (6) -> kelompok "-" & tanpa tim; Kasubag TU (4)
    //     -> selalu Subbagian Tata Usaha & tanpa tim; Ketua Kelompok (3) -> tanpa tim.
    //  1) Kelompok yang di STRUKTUR_ORGANISASI tidak punya tim -> Tim Kerja HARUS kosong.
    //  2) Tim Kerja yang jelas milik kelompok LAIN di STRUKTUR_ORGANISASI ditolak.
    //  3) Tim Kerja kosong / "-" selalu boleh.
    // Kalau lolos, kelompok & tim disamakan dengan penulisan resmi lewat $request->merge().
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

        // Ketua Kelompok: tidak punya tim kerja. Lanjut ke pengecekan kelompok.
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

    // Cek jabatan Ketua Tim / Ketua Kelompok sesuai tim & kelompoknya.
    // Aturan (hanya role 2 = Ketua Tim dan role 3 = Ketua Kelompok):
    //  - Ketua Tim      : jabatan harus diawali "Ketua <Tim Kerja> / "
    //  - Ketua Kelompok : jabatan harus diawali "Ketua <Kelompok> / "
    // Saat EDIT, pengecekan hanya berjalan kalau role/jabatan/kelompok/tim
    // berubah, supaya data lama yang belum rapi tetap bisa diedit bagian lainnya.
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

        // kunciTeks() membuang spasi di ujung, jadi spasi setelah "/" ditambahkan
        // lagi supaya "Ketua X /Perencana" (tanpa spasi) tidak lolos.
        $kunciAwalan = $this->kunciTeks($awalan) . ' ';
        $kunciJabatan = $this->kunciTeks($request->jabatan);

        if (!str_starts_with($kunciJabatan, $kunciAwalan) || mb_strlen($kunciJabatan) <= mb_strlen($kunciAwalan)) {
            throw ValidationException::withMessages([
                'jabatan' => 'Jabatan harus diawali "' . trim($awalan) . '" diikuti jabatan fungsionalnya.',
            ]);
        }
    }

    // ================= PAGINATION SERVER-SIDE UNTUK REKAP LAPORAN =================
    // $paginate = true  -> dipakai rekapLaporan() (tampilan web), hanya hitung
    //                      rekap cuti untuk 10 pegawai di halaman aktif.
    // $paginate = false -> dipakai exportExcel(), mengambil SEMUA pegawai.
    //
    // Pegawai tampil kalau:
    //   1) punya dompet saldo_cutis ASLI (kuota_tahunan > 0, lihat
    //      dompetTahunAsli()) pada tahun laporan, DAN
    //   2) lolos filter status (lihat hanyaAktifAtauPunyaCutiDiTahun()):
    //      Aktif selalu tampil; non-aktif hanya jika punya cuti tahunan
    //      disetujui/ditangguhkan di tahun laporan.
    //
    // [SINKRON HIERARKI] Memakai scope sesuaiHierarki() (model Pegawai), sehingga
    // Rekap Laporan & Export Excel selalu sama dengan cakupan Dashboard. Scope
    // sudah menyingkirkan akun Admin HR (role 5).
    //
    // [TOTAL SESUAI FILTER BULAN] Kolom `total` mengikuti filter bulan:
    //   - Filter bulan KOSONG ("Semua Bulan") -> total = Cuti Terpakai EFEKTIF
    //     setahun (ikut koreksi_saldo Admin HR), sama dengan Rekap Kuota Detail.
    //   - Filter bulan TERISI -> total = jumlah cuti pada bulan itu saja.
    //     koreksi_saldo tidak dipakai karena bersifat tahunan (tidak punya
    //     pembagian per bulan). Export Excel ikut benar otomatis karena memakai
    //     fungsi ini.
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

        // [SINKRON RKD] Filter status kepegawaian (pensiun tanpa cuti di tahun
        // laporan tidak ikut tampil).
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

        // Urutan hierarki dihitung terhadap SELURUH hasil filter/search,
        // sebelum dipotong per halaman, supaya konsisten antar halaman.
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

        // [TOTAL SESUAI FILTER BULAN] $bulanFilter ikut dibawa ke closure.
        $laporan = $pegawais->map(function ($pegawai) use ($tahun, $bulanFilter) {
            $cutis = PengajuanCuti::where('pegawai_id', $pegawai->id)
                ->where('jenis_cuti', 'Cuti Tahunan')
                ->whereYear('tanggal_mulai', $tahun)
                ->where('status', 'disetujui')
                ->get();

            $rekapBulan = array_fill_keys($this->keyBulan, 0);

            foreach ($cutis as $cuti) {
                $bulanAngka = (int) date('n', strtotime($cuti->tanggal_mulai));
                $keyBulan = $this->keyBulan[$bulanAngka - 1];
                $rekapBulan[$keyBulan] += $cuti->jumlah_hari;
            }

            // [SINKRON RKD] Koreksi saldo manual dari Admin HR (jika ada).
            // NULL = ikut hitungan sistem (jumlah riwayat cuti per bulan).
            $saldo = $pegawai->saldoCuti->first();
            $koreksiRaw = $saldo?->koreksi_saldo;
            $koreksiSaldo = $koreksiRaw === null ? null : max(0, (int) $koreksiRaw);

            $terpakaiMurni = array_sum($rekapBulan);
            $terpakaiEfektif = $koreksiSaldo !== null ? $koreksiSaldo : $terpakaiMurni;

            // [TOTAL SESUAI FILTER BULAN] Kalau filter bulan aktif, Total = cuti
            // pada bulan itu saja. Koreksi saldo Admin HR bersifat tahunan, jadi
            // tidak dipakai di sini.
            $bulanDipilih = (int) $bulanFilter;
            if ($bulanDipilih >= 1 && $bulanDipilih <= 12) {
                $terpakaiEfektif = $rekapBulan[$this->keyBulan[$bulanDipilih - 1]];
            }

            return [
                'id' => $pegawai->id,
                'nama' => $pegawai->nama,
                'nip' => $pegawai->nip,
                'jabatan' => $pegawai->jabatan,
                'kelompok_substansi' => $pegawai->kelompok_substansi,
                'tim_kerja' => $pegawai->tim_kerja,
                'role_id' => $pegawai->role_id,
                'cuti' => $rekapBulan,
                'total' => $terpakaiEfektif, // Semua Bulan: sama dengan Cuti Terpakai Efektif di Rekap Kuota Detail; filter bulan: cuti bulan terpilih
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
    // Memakai SaldoCuti::terpakaiMurni() supaya rumus "terpakai" satu sumber
    // dengan bagian lain aplikasi (Rekap Kuota Detail, dsb).
    private function hitungSisaOtomatis(int $pegawaiId, int $tahun, int $kuotaTahunan): int
    {
        $cutiTerpakai = SaldoCuti::terpakaiMurni($pegawaiId, $tahun);

        return max(0, $kuotaTahunan - $cutiTerpakai);
    }
    // ================= END HELPER BERSAMA =================

    // ================= HELPER: PREVIEW CARRY-OVER =================
    // Memakai App\Services\CarryOverSaldoService, logika yang SAMA dengan
    // Command GenerateSaldoCutiTahunBaru, supaya pratinjau dan hasil Generate
    // tidak pernah berbeda. Carry-over hanya mengambil sisa saldo biasa;
    // catatan Tgh (penangguhan) tidak ikut.
    //
    // HANYA untuk PREVIEW di halaman Kelola Saldo Cuti, TIDAK menulis ke database.
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
    // Query SENGAJA tidak difilter berdasarkan status_aktif di sisi server.
    // Semua pegawai dikirim ke Vue, lalu KelolaPegawai.vue menyaringnya lewat
    // dropdown "Status" (bawaannya 'Aktif'). Pegawai yang di-soft-delete tetap
    // tersembunyi otomatis lewat SoftDeletes.
    //
    // [SINKRON HIERARKI] Memakai scope sesuaiHierarki(): akun Admin HR (role 5)
    // tidak ditampilkan, dan cakupan data selaras dengan Dashboard.
    //
    // Setiap pegawai membawa:
    //   - `bisa_dihapus` (boolean): PERTAHANAN LAPIS 1 (pop-up dinamis).
    //   - `tanggal_masuk_form` (Y-m-d / null): untuk kolom TMT di form edit.
    //
    // Props dropdown untuk KelolaPegawai.vue:
    //   - daftarKelompok, daftarTimKerja, timPerKelompok, daftarKelompokTanpaTim
    //   - daftarJabatanFungsional (+ ...Grup), daftarJabatanKetua (+ ...Grup)
    //   - jabatanKetuaDefault, jabatanTetap
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

        // PERTAHANAN LAPIS 1: tandai pegawai yang BOLEH dihapus.
        // Satu query untuk semua pegawai (bukan N+1).
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
            // mentah karena JSON-nya berformat ISO UTC dan bisa mundur sehari.
            $p->tanggal_masuk_form = $p->tanggal_masuk
                ? Carbon::parse($p->tanggal_masuk)->toDateString()
                : null;
        });

        // Bangun opsi dropdown: STRUKTUR_ORGANISASI (Excel) + data di database
        // 1) Mulai dari struktur resmi (lengkap, urut sesuai Excel).
        $timPerKelompok = self::STRUKTUR_ORGANISASI;

        // 2) Tambahkan kelompok/tim di database yang belum ada di struktur resmi
        //    (mis. hasil "+ Tambah baru"). Penulisan disamakan dulu supaya tidak ganda.
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

        // Kelompok bawaan Excel tetap di urutan Excel; kelompok tambahan A-Z di bawahnya.
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
            // Kelompok yang menurut struktur resmi TIDAK punya tim kerja (Tim Kerja dikunci di form)
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
        ]);
    }

    // ---> Menyimpan Data Pegawai Baru dari Modal <---
    //
    // CATATAN (NIP milik pegawai yang sudah di-soft-delete):
    // Baris yang di-soft-delete masih ADA secara fisik dan tetap terikat unique
    // constraint kolom nip. Karena itu:
    //   - Validasi unique hanya memeriksa pegawai yang MASIH AKTIF (whereNull('deleted_at')).
    //   - Sebelum create(), dicek dulu apakah NIP itu milik pegawai yang sudah dihapus
    //     (onlyTrashed()). Kalau ya, baris lama DIPULIHKAN (restore) dan datanya
    //     diperbarui dari form, BUKAN membuat baris baru. Riwayat cuti & saldo ikut kembali.
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

            // Field posisi/jabatan SELALU ditimpa dengan isi form. Field opsional
            // (tanggal_masuk, no_telepon, alamat_domisili) HANYA menimpa kalau diisi.
            // password & is_first_login SENGAJA tidak disentuh (Admin bisa pakai Reset Password).
            $pegawaiLama->update([
                'nama' => $request->nama,
                'kelompok_substansi' => $request->kelompok_substansi,
                'tim_kerja' => $request->tim_kerja,
                'jabatan' => $request->jabatan,
                'role_id' => $request->role_id,
                'status_aktif' => $request->status_aktif ?? 'Aktif',
                ...$request->filled('tanggal_masuk') ? ['tanggal_masuk' => $request->tanggal_masuk] : [],
                ...$request->filled('no_telepon') ? ['no_telepon' => $request->no_telepon] : [],
                ...$request->filled('alamat_domisili') ? ['alamat_domisili' => $request->alamat_domisili] : [],
            ]);

            // Pastikan pegawai punya baris saldo cuti tahun berjalan
            // (saldo & riwayat tahun-tahun sebelumnya tetap utuh).
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
            'role_id' => $request->role_id,
            'tanggal_masuk' => $tanggalMasuk,
            'no_telepon' => $request->no_telepon,
            'alamat_domisili' => $request->alamat_domisili,
            // Default 'Aktif' kalau tidak dikirim dari form
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
    //   - Kalau pegawai yang diedit SUDAH Admin SDM, role 5 tetap diizinkan
    //     untuk dirinya sendiri (supaya datanya tetap bisa diedit).
    //   - Admin SDM AKTIF TERAKHIR tidak boleh diturunkan role-nya atau
    //     dinonaktifkan, supaya selalu ada yang bisa mengelola pegawai.
    public function updatePegawai(Request $request, int $id)
    {
        $pegawai = Pegawai::findOrFail($id);

        // Format NIP (18 digit angka) hanya diwajibkan kalau NIP-nya DIUBAH.
        // Pegawai lama yang NIP-nya belum 18 digit tetap bisa diedit data lainnya.
        $nipRules = ['required', 'string', 'max:50', 'unique:pegawais,nip,' . $id];
        if ($request->nip !== $pegawai->nip) {
            $nipRules[] = 'digits:18';
        }

        // Role yang boleh dipilih: daftar biasa + role 5 HANYA jika pegawai ini
        // memang sudah Admin SDM.
        $roleBoleh = self::ROLE_ID_BISA_DIPILIH;
        $sudahAdmin = (int) $pegawai->role_id === self::ROLE_ID_ADMIN;
        if ($sudahAdmin) {
            $roleBoleh[] = self::ROLE_ID_ADMIN;
        }

        // 'unique:pegawais,nip,' . $id mengecualikan NIP milik pegawai ini sendiri.
        $request->validate([
            'nip' => $nipRules,
            'nama' => ['required', 'string', 'max:255'],
            'kelompok_substansi' => ['required_unless:role_id,6', 'nullable', 'string', 'max:255'],
            'tim_kerja' => ['nullable', 'string', 'max:255'],
            'jabatan' => ['required', 'string', 'max:255'],
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
                    $akanTurunRole ? 'role_id' : 'status_aktif' =>
                    'Tidak dapat dilakukan: ini adalah satu-satunya Admin SDM aktif. '
                        . 'Pastikan ada Admin SDM aktif lain sebelum mengubah role atau status akun ini.',
                ]);
            }
        }

        $this->rapikanDanCekKelompokTim($request);
        $this->cekJabatanKetua($request, $pegawai);

        // Tanggal masuk: hormati edit manual; kalau NIP diganti, ikutkan TMT dari NIP baru.
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
            // NIP dikoreksi: ikutkan TMT dari NIP baru (null bila NIP tidak terbaca).
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
            'role_id' => $request->role_id,
            'status_aktif' => $request->status_aktif,
            ...$tanggalMasukBaru ? ['tanggal_masuk' => $tanggalMasukBaru] : [],
            ...$request->filled('no_telepon') ? ['no_telepon' => $request->no_telepon] : [],
            ...$request->filled('alamat_domisili') ? ['alamat_domisili' => $request->alamat_domisili] : [],
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
    // Dipakai bersama oleh storePegawai() & updatePegawai(). Khusus 'nip.unique':
    // saat TAMBAH, pesan hanya muncul untuk NIP pegawai yang masih ada (yang
    // sudah dihapus otomatis dipulihkan). Saat EDIT, pesan juga berlaku bila NIP
    // baru bentrok dengan pegawai yang sudah dihapus/diarsipkan.
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
            'role_id.required' => 'Hak akses (role) wajib dipilih.',
            'role_id.in' => 'Hak akses (role) tidak valid. Role Admin SDM tidak dapat dibuat atau diberikan lewat menu ini.',
            'no_telepon.max' => 'No telepon maksimal 25 karakter.',
            'status_aktif.required' => 'Status pegawai wajib dipilih.',
            'status_aktif.in' => 'Status pegawai tidak valid. Pilih salah satu: ' . implode(', ', self::STATUS_PEGAWAI) . '.',
        ];
    }

    // ---> Import Data Pegawai dari Excel/CSV <---
    // Nama, Kelompok Substansi, Tim Kerja, dan Jabatan otomatis dirapikan ke
    // Title Case lewat toTitleCase().
    //
    // Pengecekan NIP dilakukan manual (bukan updateOrCreate) dan memakai withTrashed():
    //   - NIP sudah ada -> data umum DIPERBARUI; nip/password/is_first_login tidak
    //     disentuh. Kalau sebelumnya soft-deleted -> di-restore & status kembali 'Aktif'.
    //     Tanggal masuk yang sudah terisi TIDAK ditimpa, kecuali terlihat placeholder
    //     (sama dengan tanggal akun dibuat) dan NIP bisa dibaca.
    //   - NIP belum ada -> Pegawai::create() dengan `nip`, `password`, dan
    //     `is_first_login` disertakan LANGSUNG di payload (kolom NOT NULL tanpa default).
    // SaldoCuti tahun berjalan memakai firstOrCreate(): saldo yang sudah ada tidak
    // ikut ke-reset saat re-import; kalau belum ada, dibuat dengan kuota default 12 hari.
    //
    // [PEMBATASAN ROLE ADMIN SDM]
    //   - Baris dengan role Admin dilewati (akun Admin SDM tidak dibuat lewat impor).
    //   - Baris yang NIP-nya milik akun Admin SDM yang sudah ada juga dilewati,
    //     supaya impor tidak bisa mengubah/menurunkan role akun Admin SDM.
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

        $header = array_map(fn($h) => strtolower(trim((string) $h)), $rows[0]);

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
        $idxRoleOrLevel = $findCol(['role', 'level', 'hak akses']);

        if ($idxNip === false || $idxNama === false || $idxJabatan === false || $idxRoleOrLevel === false) {
            return back()->with('error', 'Header file tidak sesuai. Pastikan ada kolom: NIP, Nama, Jabatan, dan Role/Level.');
        }

        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $tahunIni = (int) date('Y');

        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];

            $nip = trim((string) ($row[$idxNip] ?? ''));
            $nama = $this->toTitleCase((string) ($row[$idxNama] ?? ''));
            $jabatan = $this->toTitleCase((string) ($row[$idxJabatan] ?? ''));
            $roleTeks = trim((string) ($row[$idxRoleOrLevel] ?? '')); // dipetakan ke role_id, tidak perlu title case
            $kelompokSubstansi = $idxKelompokSubstansi !== false ? $this->toTitleCase((string) ($row[$idxKelompokSubstansi] ?? '')) : '';
            $timKerja = $idxTimKerja !== false ? $this->toTitleCase((string) ($row[$idxTimKerja] ?? '')) : null;

            if ($timKerja === '-' || $timKerja === '') {
                $timKerja = null;
            }

            // Samakan penulisan dengan STRUKTUR_ORGANISASI & perbaiki salah ketik tim.
            $kelompokSubstansi = $this->kanonikKelompok($kelompokSubstansi);
            $timKerja = $this->kanonikTimKerja($timKerja);

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

            // PAKAI withTrashed() supaya pegawai yang sudah di-soft-delete TETAP
            // TERDETEKSI (kalau tidak, create() ditolak MySQL karena unique constraint).
            $pegawai = Pegawai::withTrashed()->where('nip', $nip)->first();

            // [PEMBATASAN ROLE ADMIN SDM] Akun Admin SDM yang sudah ada tidak
            // boleh diubah lewat impor.
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

            if ($pegawai) {
                if ($pegawai->trashed()) {
                    $pegawai->restore();
                    // Pegawai yang dipulihkan otomatis berstatus 'Aktif' lagi
                    $dataUmum['status_aktif'] = 'Aktif';
                }

                // Jangan menimpa tanggal masuk yang sudah terisi (bisa hasil koreksi
                // manual), KECUALI tanggal lama terlihat placeholder (sama dengan
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

            // Daftarkan ke Rekap Kuota Cuti tahun berjalan kalau belum ada
            // (firstOrCreate) -> saldo pegawai lama tidak ikut ke-reset.
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

    // ---> Menghapus Data Pegawai — HANYA untuk Koreksi Kesalahan Administratif <---
    // Route: DELETE /admin/pegawai/{id} (name: admin.pegawai.destroy).
    //
    // Pegawai Resign / Pensiun TIDAK dihapus, melainkan diubah Status
    // Kepegawaiannya lewat EDIT.
    //
    // PERTAHANAN LAPIS 2 (gembok backend): request langsung ke endpoint ini tetap
    // divalidasi ulang dengan helper yang SAMA dengan kelolaPegawai(). Penghapusan
    // DITOLAK kalau:
    //   - pegawai sudah punya riwayat cuti (STATUS_CUTI_BERRIWAYAT), ATAU
    //   - akun sudah berusia >= BATAS_HARI_HAPUS_PEGAWAI hari, ATAU
    //   - akun adalah Admin SDM (role 5).
    // Pesan penolakan dikirim lewat error key 'hapus' (toast merah di KelolaPegawai.vue).
    //
    // Pegawai memakai SoftDeletes, jadi delete() hanya mengisi `deleted_at`
    // (bisa dipulihkan lewat storePegawai()). SaldoCuti tidak dihapus.
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
     * Memakai helper yang SAMA dengan destroyPegawai() / kelolaPegawai():
     *   - belum punya riwayat cuti (STATUS_CUTI_BERRIWAYAT), DAN
     *   - akun masih baru (< BATAS_HARI_HAPUS_PEGAWAI hari), DAN
     *   - bukan akun Admin SDM.
     *
     * Pegawai yang tidak memenuhi syarat dilewati (tidak digagalkan seluruh batch).
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
    // Setiap pegawai dilengkapi `preview_carry_over` (`dari_saldo_biasa`, `total`)
    // — perkiraan carry-over andai saldo digenerate lewat Command
    // GenerateSaldoCutiTahunBaru. HANYA preview untuk Admin, BUKAN nilai tersimpan.
    //
    // [SINKRON HIERARKI] Memakai scope sesuaiHierarki(); akun Admin HR (role 5)
    // tidak ikut tampil.
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
    // Setiap kali Admin mengubah `sisa` secara manual dari angka yang sudah ada,
    // perubahan dicatat sebagai satu baris di `histori_saldos` (siapa, kapan,
    // berapa). Baris histori HANYA dibuat kalau `sisa` benar-benar berubah.
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

        // filled() (bukan has()) supaya input kosong/null tidak menimpa nilai lama dengan null.
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

        // Catat ke histori HANYA kalau ada baris sebelumnya DAN nilai sisa berubah.
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
    // Dipanggil dari tombol "Generate Saldo Tahun Baru" di halaman Rekap Kuota
    // Detail. SENGAJA hanya memanggil Artisan Command `saldo-cuti:generate-tahun-baru`
    // yang SAMA dengan scheduler tiap 1 Januari, jadi hasil manual dan otomatis
    // selalu identik. Berguna sebagai cadangan kalau scheduler gagal jalan, atau
    // untuk generate ulang setelah koreksi data.
    //
    // Kode keluar command DIPERIKSA. Kalau command menolak (mis. tahun di luar
    // batas), pesan command dikembalikan sebagai error di field 'tahun'
    // (tampil di modal), BUKAN banner sukses.
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

    // 4. Menampilkan Rekap Laporan Cuti Seluruh Pegawai (Rekap Per Bulan, dengan pagination)
    public function rekapLaporan(Request $request)
    {
        // paginate=true, ambil 10 pegawai per halaman
        $data = $this->buildLaporanData($request, true);

        // Dropdown kelompok memakai aturan yang SAMA dengan buildLaporanData():
        // dompet ASLI + filter status kepegawaian + scope hierarki. Tanpa ini,
        // kelompok yang isinya hanya pegawai pensiun tetap muncul di dropdown
        // tetapi hasilnya kosong.
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
            // info pagination dikirim ke frontend
            'pagination' => [
                'current_page' => $data['paginator']->currentPage(),
                'last_page' => $data['paginator']->lastPage(),
                'per_page' => $data['paginator']->perPage(),
                'total' => $data['paginator']->total(),
            ],
        ]);
    }

    // 5. Export Data Rekap Cuti ke Excel
    public function exportExcel(Request $request)
    {
        // Export TETAP mengambil semua data (paginate=false), karena file
        // Excel harus berisi seluruh pegawai, bukan hanya halaman aktif.
        $data = $this->buildLaporanData($request);
        $laporan = $data['laporan'];
        $tahun = $data['tahun'];
        $bulanFilter = $data['bulanFilter'];

        if ($bulanFilter && isset($this->keyBulan[$bulanFilter - 1])) {
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
        $bulanStartCol = 5; // E
        $totalCol = $bulanStartCol + $nBulan;
        $lastColLetter = Coordinate::stringFromColumnIndex($totalCol);

        // ---------- Warna ----------
        $GREEN_DARK = '1D9E75';
        $GREEN_LIGHT = 'E1F5EE';
        $GREEN_HEADER = '97C459';
        $GREEN_TOTAL = '639922';
        $AMBER = 'FAC775';
        $BLUE_BADGE = '85B7EB';
        $PURPLE_TINGGI = 'AFA9EC';
        $PURPLE_MUDA = 'CECBF6';
        $GRAY_STAF = 'D3D1C7';
        $TOTAL_ROW_BG = 'FAEEDA';
        $ZEBRA = 'F4F8F0';

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
        $headersFixed = ['No', 'Nama Pegawai', 'NIP', 'Jabatan'];

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

        $totalLetter = Coordinate::stringFromColumnIndex($totalCol);
        $sheet->mergeCells("{$totalLetter}{$headerRow1}:{$totalLetter}{$headerRow2}");
        $sheet->setCellValue("{$totalLetter}{$headerRow1}", 'Total Cuti');
        $sheet->getStyle("{$totalLetter}{$headerRow1}")->applyFromArray([
            'font' => ['name' => 'Arial', 'size' => 10, 'bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $GREEN_TOTAL]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
        ]);

        $sheet->getRowDimension($headerRow1)->setRowHeight(18);
        $sheet->getRowDimension($headerRow2)->setRowHeight(16);

        // ---------- Data ----------
        $dataStartRow = 6;
        $r = $dataStartRow;

        foreach ($laporan as $i => $item) {
            $zebra = $i % 2 === 1 ? $ZEBRA : null;

            $roleId = $item['role_id'] ?? 1;
            if ($roleId >= 3) {
                $jabatanColor = $PURPLE_TINGGI;
                $jabatanTextColor = '26215C';
            } elseif ($roleId === 2) {
                $jabatanColor = $PURPLE_MUDA;
                $jabatanTextColor = '3C3489';
            } else {
                $jabatanColor = $GRAY_STAF;
                $jabatanTextColor = '444441';
            }

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
            $sheet->getStyle("D{$r}")->applyFromArray([
                'font' => ['name' => 'Arial', 'size' => 9, 'bold' => true, 'color' => ['rgb' => $jabatanTextColor]],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $jabatanColor]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
            ]);

            // Nama & Jabatan: string murni + quotePrefix (aman Formula Injection SEC-09)
            // Dipanggil SETELAH applyFromArray supaya quotePrefix tidak tertimpa style.
            $this->tulisTeksAman($sheet, "B{$r}", $item['nama'] ?? '');
            $this->tulisTeksAman($sheet, "D{$r}", $item['jabatan'] ?? '-');

            foreach ($exportKeyBulan as $j => $key) {
                $col = $bulanStartCol + $j;
                $letter = Coordinate::stringFromColumnIndex($col);
                $val = $item['cuti'][$key] ?? 0;
                $sheet->setCellValue("{$letter}{$r}", $val);

                if ($val > 0) {
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

            // [SINKRON RKD] Total per pegawai memakai nilai efektif (ikut koreksi
            // Admin HR), bukan rumus =SUM bulan, supaya sama dengan Rekap Kuota Detail.
            // [TOTAL SESUAI FILTER BULAN] Kalau filter bulan aktif, nilai ini sudah
            // berisi cuti bulan terpilih saja (dihitung di buildLaporanData()).
            $sheet->setCellValue("{$totalLetter}{$r}", $item['total']);
            $sheet->getStyle("{$totalLetter}{$r}")->applyFromArray([
                'font' => ['name' => 'Arial', 'size' => 10, 'bold' => true, 'color' => ['rgb' => '173404']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EAF3DE']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4B2A9']]],
            ]);

            $r++;
        }

        $dataEndRow = $r - 1;

        // ---------- Baris Total Keseluruhan ----------
        $totalRow = $dataEndRow + 2;
        $sheet->mergeCells("A{$totalRow}:D{$totalRow}");
        $sheet->setCellValue("A{$totalRow}", 'Total Keseluruhan');
        $sheet->getStyle("A{$totalRow}:D{$totalRow}")->applyFromArray([
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
            ['Jabatan struktural (Kepala Biro / Kasubag)', $PURPLE_TINGGI],
            ['Ketua Kelompok / Ketua Tim Kerja', $PURPLE_MUDA],
            ['Staf', $GRAY_STAF],
            ['Ada cuti di bulan tersebut', $BLUE_BADGE],
            ['Bulan berjalan', $AMBER],
        ];

        foreach ($legends as $i => $item) {
            [$label, $color] = $item;
            $row = $legendTitleRow + 1 + $i;
            $sheet->getStyle("A{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($color);
            $sheet->getStyle("A{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('B4B2A9');
            $sheet->mergeCells("B{$row}:D{$row}");
            $sheet->setCellValue("B{$row}", $label);
            $sheet->getStyle("B{$row}")->getFont()->setName('Arial')->setSize(9)->getColor()->setRGB('2C2C2A');
            $sheet->getStyle("B{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getRowDimension($row)->setRowHeight(16);
        }

        // ---------- Lebar kolom ----------
        $sheet->getColumnDimension('A')->setWidth(5);
        $sheet->getColumnDimension('B')->setWidth(38);
        $sheet->getColumnDimension('C')->setWidth(20);
        $sheet->getColumnDimension('D')->setWidth(22);
        foreach ($exportKeyBulan as $j => $key) {
            $letter = Coordinate::stringFromColumnIndex($bulanStartCol + $j);
            $sheet->getColumnDimension($letter)->setWidth(6);
        }
        $sheet->getColumnDimension($totalLetter)->setWidth(11);

        $sheet->freezePane('E' . $dataStartRow);

        $filename = "Rekap_Cuti_Tahunan_{$tahun}_" . date('Y-m-d') . '.xlsx';

        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    // 6. Menangguhkan cuti (HANYA L4 / Kepala Biro)
    //
    // [PERBAIKAN TGH] Method ini SENGAJA hanya meneruskan ke
    // PembatalanController::process(), supaya SATU-SATUNYA logika penangguhan
    // ada di sana. Sebelumnya method ini mengubah status & notifikasi saja, tanpa
    // membuat baris CutiDitangguhkan (penyebab kolom TGH di Rekap Kuota Detail
    // tampil 0) dan tanpa ApprovalLog. Sekarang kedua jalur identik:
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