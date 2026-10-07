<?php


namespace App\Http\Controllers;


use App\Models\Pegawai;
use App\Models\PengajuanCuti;
use App\Models\SaldoCuti;
use App\Models\CutiDitangguhkan;
use App\Models\HistoriSaldo;
use App\Models\HariLibur;
use App\Services\CarryOverSaldoService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder; // dipakai type-hint closure di buildLaporanData()
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection; // [FIX] dipakai type-hint di urutkanSesuaiHierarki()
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule; // dipakai untuk validasi status_aktif (Rule::in)
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


    // Role ID yang dipakai untuk akun Admin (HR/Kepegawaian), BUKAN pegawai/atasan
    // biasa. Dipusatkan sebagai konstanta supaya definisinya konsisten di
    // seluruh controller (dipakai buildLaporanData() untuk Rekap Laporan &
    // kelolaPegawai() untuk Master Data Pegawai).
    private const ROLE_ID_ADMIN = 5;


    // Jumlah baris per halaman untuk Rekap Laporan (server-side pagination)
    private const REKAP_PER_PAGE = 10;


    // Daftar nilai status pegawai yang diizinkan. Dipusatkan di satu tempat
    // supaya validasi store/update konsisten. Kalau nanti perlu status lain,
    // tambahkan di array ini DAN di dropdown Status Pegawai pada KelolaPegawai.vue.
    private const STATUS_PEGAWAI = ['Aktif', 'Pensiun', 'Mutasi Keluar', 'Resign / Berhenti', 'Meninggal Dunia'];


    // ================= GEMBOK PENGHAPUSAN PEGAWAI (2 LAPIS PERTAHANAN) =================
    // Fitur hapus HANYA untuk koreksi kesalahan administratif (data duplikat,
    // salah ketik NIP, batal penempatan). Pegawai baru boleh dihapus kalau
    // KEDUA syarat terpenuhi:
    //   1) belum punya riwayat pemakaian cuti (status di STATUS_CUTI_BERRIWAYAT), dan
    //   2) akunnya didaftarkan kurang dari BATAS_HARI_HAPUS_PEGAWAI hari yang lalu.
    //
    // Lapis 1 (UI, pop-up dinamis): ikon Hapus selalu bisa diklik. kelolaPegawai()
    //                     mengirim flag `bisa_dihapus` per pegawai; KelolaPegawai.vue
    //                     menampilkan modal konfirmasi "Ya, Hapus" kalau true, atau
    //                     modal info "Tidak Bisa Dihapus" (tanpa opsi hapus) kalau false.
    // Lapis 2 (Backend)  : destroyPegawai() memvalidasi ulang dengan helper yang SAMA
    //                     (akunMasihBaru() & punyaRiwayatCuti()), jadi aturan di UI
    //                     dan backend tidak mungkin berbeda.
    // Kalau tidak memenuhi syarat, Admin diarahkan mengubah Status Kepegawaian
    // lewat EDIT (Pensiun / Resign / dsb).
    private const BATAS_HARI_HAPUS_PEGAWAI = 7;


    // Status pengajuan cuti yang dianggap "sudah ada riwayat pemakaian cuti".
    private const STATUS_CUTI_BERRIWAYAT = ['disetujui', 'dibatalkan_ditangguhkan'];
    // ================= END GEMBOK PENGHAPUSAN PEGAWAI =================


    // ================= STRUKTUR ORGANISASI BIRO PERENCANAAN =================
    // Sumber: file "Nama-Nama Pegawai Biro Perencanaan" (per 20 Agustus 2026).
    // Dipakai untuk mengisi dropdown "Kelompok Substansi" & "Tim Kerja" di
    // KelolaPegawai.vue supaya pilihannya SELALU lengkap dan sesuai Excel,
    // tidak bergantung pada siapa saja yang kebetulan sudah ada di database.
    //
    // Format: 'Nama Kelompok' => ['Tim Kerja 1', 'Tim Kerja 2', ...]
    // Kelompok dengan array kosong = kelompok tersebut tidak punya tim kerja
    // (contoh: Subbagian Tata Usaha, di Excel kolom Tim Kerja-nya "-").
    //
    // Penulisan mengikuti hasil toTitleCase() pada import ("Dan" berhuruf
    // kapital), kecuali angka romawi (I, II, III, IV) yang dijaga tetap
    // huruf besar. Kalau ada kelompok/tim baru di kemudian hari, cukup
    // tambahkan di sini (atau lewat opsi "+ Tambah baru" pada form).
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
    // Kunci ditulis huruf kecil. (Di Excel, GUSTI RAMADHAN NOOR tertulis
    // "TIM KERJA BAHAN PENYIAPAN KOORDINASI", sedangkan tim lainnya
    // "TIM KERJA PENYIAPAN BAHAN KOORDINASI".)
    private const ALIAS_TIM_KERJA = [
        'tim kerja bahan penyiapan koordinasi' => 'Tim Kerja Penyiapan Bahan Koordinasi',
    ];


    // Nama kelompok Kasubag TU (harus sama dengan kunci di STRUKTUR_ORGANISASI)
    private const KELOMPOK_TU = 'Subbagian Tata Usaha';


    // Jabatan fungsional STAF (dropdown Jabatan untuk role Karyawan/Staf).
    // Penulisan mengikuti toTitleCase() (mis. "Dan" berhuruf kapital).
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
    // Dipakai untuk mengurutkan pegawai dari jabatan tertinggi ke terendah,
    // BUKAN pencocokan teks nama jabatan yang rawan meleset.
    //
    // role_id 6 -> Kepala Biro Perencanaan (L4 / Approval tertinggi)
    // role_id 4 -> Kasubag TU (L3)
    // role_id 3 -> Ketua Kelompok Substansi (L2)
    // role_id 2 -> Ketua Tim Kerja (L1)
    // role_id 1 -> Pegawai / Staf (paling bawah)
    // role_id lain yang tidak terdaftar otomatis ditempatkan paling bawah (?? 99).
    private array $urutanJabatan = [
        6 => 1,
        4 => 2,
        3 => 3,
        2 => 4,
        1 => 5,
    ];
    // ================= END URUTAN HIERARKI JABATAN =================


    // ================= HELPER: GEMBOK DOMPET ASLI =================
    // [BARU] Sebuah baris saldo_cutis dianggap "dompet ASLI" (hasil generate /
    // input hak tahunan oleh Admin) kalau kuota_tahunan > 0.
    //
    // SAMA PERSIS dengan dompetTahunAsli() di RekapKuotaDetailController.
    // RekapKuotaDetailController::updateSaldo() & importKuota() membuat baris
    // "bayangan" untuk tahun-1 (hanya untuk menyimpan carry-forward) dengan
    // kuota_tahunan = 0, sehingga baris itu TIDAK dianggap dompet dan tidak
    // membuat tahun lalu muncul di Rekap Laporan.
    //
    // CATATAN: pegawai yang haknya sengaja diisi 0 pada suatu tahun tidak akan
    // tampil di Rekap Laporan tahun tersebut (kasus sangat jarang).
    private function dompetTahunAsli(Builder $query, int $tahun): Builder
    {
        return $query->where('tahun', $tahun)
            ->where('kuota_tahunan', '>', 0);
    }
    // ================= END HELPER GEMBOK DOMPET ASLI =================


    // ================= HELPER BARU: KELOMPOKKAN JABATAN UNTUK DROPDOWN =================
    // Mengubah daftar datar jabatan fungsional menjadi grup per rumpun jabatan,
    // supaya di frontend bisa dipakai untuk <optgroup> ATAU Combobox (Headless UI)
    // yang berkelompok. Nama rumpun diambil dengan membuang jenjang di akhir nama
    // (Ahli Pertama/Muda/Madya/Utama, Mahir, Terampil), contoh:
    //   "Perencana Ahli Muda" -> rumpun "Perencana"
    //   "Arsiparis Mahir"     -> rumpun "Arsiparis"
    // Rumpun yang hanya punya 1 jabatan digabung ke grup "Lainnya" supaya tidak
    // muncul banyak grup berisi satu item. Urutan jabatan di dalam grup mengikuti
    // urutan pada daftar sumber. Grup "Lainnya" selalu ditaruh paling akhir.
    //
    // Hasil: [ ['label' => 'Perencana', 'options' => ['Perencana Ahli Pertama', ...]], ... ]
    // Daftar datar lama (daftarJabatanFungsional / daftarJabatanKetua) TETAP
    // dikirim ke frontend, jadi KelolaPegawai.vue yang lama tidak akan rusak.
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
    // [FIX] Parameter & return diberi tipe Collection supaya Intelephense (P1132)
    // tidak lagi mengeluh. Eloquent\Collection turunan dari Support\Collection,
    // jadi aman dipakai untuk hasil Pegawai::...->get().
    private function urutkanSesuaiHierarki(Collection $pegawais): Collection
    {
        return $pegawais
            ->sortBy(function ($pegawai) {
                return mb_strtolower(trim($pegawai->nama ?? ''));
            })
            ->sortBy(function ($pegawai) {
                return $this->urutanJabatan[$pegawai->role_id] ?? 99;
            })
            ->values();
    }
    // ================= END HELPER URUTKAN PEGAWAI =================


    // ================= HELPER BERSAMA: SYARAT BOLEH DIHAPUS =================
    // Dipakai oleh kelolaPegawai() (menentukan tombol Hapus tampil/tidak di UI)
    // DAN destroyPegawai() (gembok backend), supaya aturannya SATU sumber.


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


    // ================= HELPER: EKSTRAK TANGGAL MASUK DARI NIP =================
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
        return preg_replace_callback('/\b(Ii|Iii|Iv)\b/', fn ($m) => strtoupper($m[1]), $hasil);
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


        // Kepala Biro: tidak punya kelompok & tim (di Excel dan hasil import
        // ditulis "-"), jadi disamakan supaya konsisten.
        if ($role === 6) {
            $request->merge(['kelompok_substansi' => '-', 'tim_kerja' => null]);
            return;
        }


        // Kasubag TU: selalu di Subbagian Tata Usaha, tanpa tim kerja.
        if ($role === 4) {
            $request->merge(['kelompok_substansi' => self::KELOMPOK_TU, 'tim_kerja' => null]);
            return;
        }


        // Ketua Kelompok: tidak punya tim kerja. Lalu lanjut ke pengecekan kelompok.
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
                    $pesan = "Kelompok ini tidak memiliki tim kerja. Kosongkan kolom Tim Kerja.";
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
    // Aturan (hanya untuk role 2 = Ketua Tim dan role 3 = Ketua Kelompok):
    //  - Ketua Tim      : jabatan harus diawali "Ketua <Tim Kerja> / "
    //  - Ketua Kelompok : jabatan harus diawali "Ketua <Kelompok> / "
    // Jabatan staf & lainnya TIDAK dipaksa mengikuti daftar (ada opsi "Lainnya").
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
    // $paginate = false -> dipakai exportExcel(), mengambil SEMUA pegawai
    //                      karena file Excel harus lengkap.
    //
    // [UBAH] Pegawai tampil kalau dan hanya kalau punya dompet saldo_cutis ASLI
    // (kuota_tahunan > 0, lihat dompetTahunAsli()) pada tahun laporan yang
    // dipilih. Tidak ada filter status_aktif:
    //   - Tahun yang belum di-generate / diinput -> laporan kosong otomatis.
    //   - Baris "bayangan" (kuota_tahunan = 0) yang dibuat untuk menyimpan
    //     carry-forward tidak dianggap dompet.
    //   - Pegawai pensiun tetap tampil di tahun ketika dompetnya masih ada
    //     (data historis utuh), dan tidak muncul di tahun tanpa dompet.
    private function buildLaporanData(Request $request, bool $paginate = false): array
    {
        $tahun = $request->input('tahun', date('Y'));
        $search = $request->input('search');
        $kelompok = $request->input('kelompok_substansi');
        $bulanFilter = $request->input('bulan');


        $query = Pegawai::where('role_id', '!=', self::ROLE_ID_ADMIN);


        // [UBAH] Gembok kepemilikan dompet ASLI pada tahun laporan.
        $query->whereHas('saldoCuti', function (Builder $q) use ($tahun) {
            $this->dompetTahunAsli($q, (int) $tahun);
        });


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


        // Urutan hierarki dihitung dulu terhadap SELURUH hasil filter/search,
        // sebelum dipotong per halaman, supaya urutan konsisten antar halaman.
        $pegawais = $this->urutkanSesuaiHierarki($pegawais);


        $paginator = null;


        if ($paginate) {
            $perPage = self::REKAP_PER_PAGE;
            $page = max(1, (int) $request->input('page', 1));
            $total = $pegawais->count();


            // Potong koleksi yang SUDAH terurut sesuai halaman aktif.
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


        $laporan = $pegawais->map(function ($pegawai) use ($tahun) {
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


            return [
                'id' => $pegawai->id,
                'nama' => $pegawai->nama,
                'nip' => $pegawai->nip,
                'jabatan' => $pegawai->jabatan,
                'kelompok_substansi' => $pegawai->kelompok_substansi,
                'tim_kerja' => $pegawai->tim_kerja,
                'role_id' => $pegawai->role_id,
                'cuti' => $rekapBulan,
                'total' => array_sum($rekapBulan),
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
    private function hitungSisaOtomatis(int $pegawaiId, int $tahun, int $kuotaTahunan): int
    {
        $cutiTerpakai = PengajuanCuti::where('pegawai_id', $pegawaiId)
            ->where('jenis_cuti', 'Cuti Tahunan')
            ->where('status', 'disetujui')
            ->whereYear('tanggal_mulai', $tahun)
            ->sum('jumlah_hari');


        return max(0, $kuotaTahunan - $cutiTerpakai);
    }
    // ================= END HELPER BERSAMA =================


    // ================= HELPER: PREVIEW CARRY-OVER (2 SUMBER) =================
    // Memakai logika yang SAMA dengan Command GenerateSaldoCutiTahunBaru
    // (app/Console/Commands/GenerateSaldoCutiTahunBaru.php), yaitu lewat
    // App\Services\CarryOverSaldoService, supaya pratinjau dan hasil Generate
    // tidak pernah berbeda:
    //   SUMBER 1: sisa saldo biasa (Jalur Normal maks 6 hari / Jalur Khusus
    //             kalau 2 tahun berturut-turut tidak pakai cuti) -> service.
    //   SUMBER 2: hari dari tabel `cuti_ditangguhkans` berstatus 'belum_dipakai'
    //             di tahun lalu (SELALU dibawa penuh, dicatat otomatis oleh
    //             PembatalanController@process saat L4 menangguhkan pengajuan).
    //
    // HANYA untuk PREVIEW di halaman Kelola Saldo Cuti, TIDAK menulis ke
    // database. Generate sesungguhnya selalu lewat Command yang sama
    // (scheduler otomatis maupun tombol manual generateSaldoManual()).
    private function hitungPreviewCarryOver(int $pegawaiId, int $tahunBaru): array
    {
        $tahunLalu = $tahunBaru - 1;


        // SUMBER 1: service yang sama dengan Command Generate
        $carryDariSaldoBiasa = app(CarryOverSaldoService::class)
            ->carryDariSaldoBiasa($pegawaiId, $tahunBaru);


        // SUMBER 2
        $carryDariPenangguhanResmi = (int) CutiDitangguhkan::belumDipakai($pegawaiId, $tahunLalu)
            ->sum('jumlah_hari');


        return [
            'dari_saldo_biasa' => $carryDariSaldoBiasa,
            'dari_penangguhan_resmi' => $carryDariPenangguhanResmi,
            'total' => $carryDariSaldoBiasa + $carryDariPenangguhanResmi,
        ];
    }
    // ================= END HELPER PREVIEW CARRY-OVER =================


    // 1. Menampilkan Daftar Pegawai
    //
    // Query SENGAJA tidak difilter berdasarkan status_aktif di sisi server.
    // Semua pegawai dikirim ke Vue, lalu KelolaPegawai.vue menyaringnya lewat
    // dropdown "Status" (bawaannya 'Aktif'). Pegawai yang dihapus (soft
    // delete) tetap tersembunyi otomatis lewat SoftDeletes.
    //
    // [BARU] Setiap pegawai membawa field `bisa_dihapus` (boolean) untuk
    // PERTAHANAN LAPIS 1 (pop-up dinamis): true = pegawai TANPA riwayat cuti DAN
    // akunnya masih baru -> klik ikon Hapus memunculkan konfirmasi; false ->
    // memunculkan pop-up info "Tidak Bisa Dihapus". Aturannya memakai helper
    // yang sama dengan destroyPegawai().
    //
    // Props dropdown untuk KelolaPegawai.vue:
    //   - daftarKelompok         : daftar unik Kelompok Substansi.
    //   - daftarTimKerja         : daftar unik Tim Kerja dari seluruh kelompok.
    //   - timPerKelompok         : { "Nama Kelompok": ["Tim A", "Tim B"] }.
    //   - daftarKelompokTanpaTim : kelompok yang menurut struktur resmi tidak punya tim.
    //   - daftarJabatanFungsional: dropdown jabatan STAF (14 jabatan), daftar datar.
    //   - daftarJabatanFungsionalGrup : jabatan STAF yang sama, dikelompokkan per rumpun
    //                              [{label, options[]}] untuk <optgroup> / Combobox berkelompok.
    //   - daftarJabatanKetua     : pilihan jabatan fungsional Ketua saat admin memilih "Ubah".
    //   - daftarJabatanKetuaGrup : pilihan jabatan Ketua yang sama, dikelompokkan per rumpun.
    //   - jabatanKetuaDefault    : jabatan fungsional bawaan tiap Ketua (per Tim / Kelompok).
    //   - jabatanTetap           : jabatan tetap per role (L3 & L4).
    // Akun Admin tidak ikut dihitung, nilai kosong / "-" dibuang dari pilihan.
    public function kelolaPegawai()
    {
        $tahun = date('Y');


        $pegawai = Pegawai::with(['saldoCuti' => function ($query) use ($tahun) {
            $query->where('tahun', $tahun);
        }])
            ->where('role_id', '!=', self::ROLE_ID_ADMIN)
            ->orderBy('nama', 'asc')
            ->get();


        $pegawai = $this->urutkanSesuaiHierarki($pegawai);


        // [BARU] PERTAHANAN LAPIS 1: tandai pegawai yang BOLEH dihapus.
        // Satu query untuk semua pegawai (bukan N+1): ambil id pegawai yang
        // sudah punya riwayat cuti, lalu cocokkan di memori.
        $idBerRiwayatCuti = PengajuanCuti::whereIn('pegawai_id', $pegawai->pluck('id'))
            ->whereIn('status', self::STATUS_CUTI_BERRIWAYAT)
            ->distinct()
            ->pluck('pegawai_id')
            ->all();


        $pegawai->each(function ($p) use ($idBerRiwayatCuti) {
            $p->bisa_dihapus = !in_array($p->id, $idBerRiwayatCuti, true)
                && $this->akunMasihBaru($p);
        });


        // Bangun opsi dropdown: STRUKTUR_ORGANISASI (Excel) + data di database
        // 1) Mulai dari struktur resmi (lengkap, urut sesuai Excel).
        $timPerKelompok = self::STRUKTUR_ORGANISASI;


        // 2) Tambahkan kelompok/tim yang ada di database tapi belum ada di
        //    struktur resmi (mis. hasil "+ Tambah baru" di form). Penulisannya
        //    disamakan dulu dengan struktur resmi supaya tidak muncul ganda.
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


        // Kelompok bawaan Excel tetap di urutan Excel; kelompok tambahan
        // dari database diurutkan A-Z di bawahnya.
        $kelompokBawaan = array_keys(self::STRUKTUR_ORGANISASI);
        $kelompokTambahan = array_keys($ekstra);
        sort($kelompokTambahan, SORT_NATURAL | SORT_FLAG_CASE);


        $daftarKelompok = array_values(array_merge($kelompokBawaan, $kelompokTambahan));


        // Tim tambahan (di luar Excel) diurutkan natural; tim bawaan Excel
        // dibiarkan di urutan aslinya.
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
    // CATATAN (NIP milik pegawai yang sudah dihapus / soft delete):
    // Baris pegawai yang di-soft-delete masih ADA secara fisik di tabel dan
    // tetap terikat unique constraint kolom nip di MySQL. Karena itu:
    //   - Validasi unique hanya memeriksa pegawai yang MASIH AKTIF
    //     (whereNull('deleted_at')).
    //   - Sebelum create(), dicek dulu apakah NIP itu milik pegawai yang sudah
    //     dihapus (onlyTrashed()). Kalau ya, baris lama DIPULIHKAN (restore)
    //     dan datanya diperbarui dari isi form, BUKAN membuat baris baru.
    //     Riwayat cuti & saldo lama ikut kembali utuh.
    public function storePegawai(Request $request)
    {
        $request->validate([
            'nip' => ['required', 'string', 'digits:18', 'max:50', Rule::unique('pegawais', 'nip')->whereNull('deleted_at')],
            'nama' => ['required', 'string', 'max:255'],
            'kelompok_substansi' => ['required_unless:role_id,6', 'nullable', 'string', 'max:255'],
            'tim_kerja' => ['nullable', 'string', 'max:255'],
            'jabatan' => ['required', 'string', 'max:255'],
            'role_id' => ['required', 'integer'],
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
            // (tanggal_masuk, no_telepon, alamat_domisili) HANYA menimpa kalau
            // diisi. password & is_first_login SENGAJA tidak disentuh (kalau
            // lupa, Admin bisa memakai tombol Reset Password).
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
    public function updatePegawai(Request $request, int $id)
    {
        $pegawai = Pegawai::findOrFail($id);


        // Format NIP (18 digit angka) hanya diwajibkan kalau NIP-nya DIUBAH.
        // Pegawai lama yang NIP-nya belum berformat 18 digit tetap bisa
        // diedit data lainnya tanpa dipaksa mengganti NIP.
        $nipRules = ['required', 'string', 'max:50', 'unique:pegawais,nip,' . $id];
        if ($request->nip !== $pegawai->nip) {
            $nipRules[] = 'digits:18';
        }


        // 'unique:pegawais,nip,' . $id mengecualikan NIP milik pegawai ini
        // sendiri, sehingga edit nama/jabatan saja tidak memicu error.
        $request->validate([
            'nip' => $nipRules,
            'nama' => ['required', 'string', 'max:255'],
            'kelompok_substansi' => ['required_unless:role_id,6', 'nullable', 'string', 'max:255'],
            'tim_kerja' => ['nullable', 'string', 'max:255'],
            'jabatan' => ['required', 'string', 'max:255'],
            'role_id' => ['required', 'integer'],
            'tanggal_masuk' => ['nullable', 'date'],
            'no_telepon' => ['nullable', 'string', 'max:25'],
            'alamat_domisili' => ['nullable', 'string'],
            'status_aktif' => ['required', 'string', Rule::in(self::STATUS_PEGAWAI)],
            'kuota_tahunan' => ['nullable', 'integer', 'min:0'],
            'sisa' => ['nullable', 'integer', 'min:0'],
            'carry_forward_normal' => ['nullable', 'integer', 'min:0'],
        ], $this->pesanValidasiPegawai());


        $this->rapikanDanCekKelompokTim($request);
        $this->cekJabatanKetua($request, $pegawai);


        $pegawai->update([
            'nip' => $request->nip,
            'nama' => $request->nama,
            'kelompok_substansi' => $request->kelompok_substansi,
            'tim_kerja' => $request->tim_kerja,
            'jabatan' => $request->jabatan,
            'role_id' => $request->role_id,
            'status_aktif' => $request->status_aktif,
            ...$request->filled('tanggal_masuk') ? ['tanggal_masuk' => $request->tanggal_masuk] : [],
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
    // Dipakai bersama oleh storePegawai() & updatePegawai(). Khusus
    // 'nip.unique': saat TAMBAH pegawai, pesan ini hanya muncul untuk NIP
    // pegawai yang masih ada (yang sudah dihapus otomatis dipulihkan). Saat
    // EDIT, pesan juga berlaku bila NIP baru bentrok dengan pegawai yang
    // sudah diarsipkan.
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
            'no_telepon.max' => 'No telepon maksimal 25 karakter.',
            'status_aktif.required' => 'Status pegawai wajib dipilih.',
            'status_aktif.in' => 'Status pegawai tidak valid. Pilih salah satu: ' . implode(', ', self::STATUS_PEGAWAI) . '.',
        ];
    }


    // ---> Import Data Pegawai dari Excel/CSV <---
    // Nama, Kelompok Substansi, Tim Kerja, dan Jabatan otomatis dirapikan ke
    // Title Case lewat toTitleCase().
    //
    // Pengecekan NIP dilakukan manual (bukan updateOrCreate) dan memakai
    // withTrashed():
    //   - NIP sudah ada -> data umum (nama, jabatan, kelompok, tim, role_id,
    //     tanggal_masuk) DIPERBARUI; nip/password/is_first_login tidak disentuh.
    //     Kalau sebelumnya soft-deleted -> di-restore & status kembali 'Aktif'.
    //   - NIP belum ada -> Pegawai::create() dengan `nip`, `password`, dan
    //     `is_first_login` disertakan LANGSUNG di payload (kolom NOT NULL tanpa
    //     default; riwayat error MySQL 1364 & UniqueConstraintViolation).
    // SaldoCuti tahun berjalan memakai firstOrCreate(): saldo yang sudah ada
    // tidak ikut ke-reset saat re-import; kalau belum ada, dibuat dengan
    // kuota default 12 hari. Pegawai baru berstatus 'Aktif'; pegawai lama yang
    // tidak terhapus dibiarkan dengan status_aktif yang sedang berjalan.
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


        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $rows[0]);


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


            // Samakan penulisan dengan STRUKTUR_ORGANISASI (angka romawi,
            // huruf besar/kecil) & perbaiki salah ketik nama tim yang diketahui.
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


            $tanggalMasuk = $this->extractTanggalMasukDariNip($nip) ?? now()->format('Y-m-d');


            // 1) Cek dulu apakah NIP sudah terdaftar. PAKAI withTrashed() supaya
            //    pegawai yang sudah di-soft-delete TETAP TERDETEKSI (kalau tidak,
            //    create() baru ditolak MySQL karena NIP masih terikat unique
            //    constraint).
            $pegawai = Pegawai::withTrashed()->where('nip', $nip)->first();


            $dataUmum = [
                'nama' => $nama,
                'kelompok_substansi' => $kelompokSubstansi ?: '-',
                'tim_kerja' => $timKerja,
                'jabatan' => $jabatan,
                'role_id' => $roleId,
                'tanggal_masuk' => $tanggalMasuk,
            ];


            if ($pegawai) {
                // NIP sudah ada -> update data umum saja. `nip` tidak diikutkan
                // (dipakai sebagai kunci pencarian). password & is_first_login
                // TIDAK disentuh.
                if ($pegawai->trashed()) {
                    $pegawai->restore();
                    // Pegawai yang dipulihkan otomatis berstatus 'Aktif' lagi
                    $dataUmum['status_aktif'] = 'Aktif';
                }
                $pegawai->update($dataUmum);
                $isBaru = false;
            } else {
                // NIP belum ada -> buat baru. `nip`, `password`, dan
                // `is_first_login` DISERTAKAN LANGSUNG di sini.
                $pegawai = Pegawai::create([
                    'nip' => $nip,
                    ...$dataUmum,
                    'status_aktif' => 'Aktif',
                    'password' => Hash::make('password123'),
                    'is_first_login' => true,
                ]);
                $isBaru = true;
            }


            // 2) Daftarkan ke Rekap Kuota Cuti tahun berjalan kalau belum ada
            //    (firstOrCreate) -> saldo pegawai lama tidak ikut ke-reset.
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
            "Berhasil mengimpor: {$inserted} pegawai baru ditambahkan, {$updated} pegawai diperbarui. {$skipped} baris dilewati (data kosong/role-level tidak dikenali)."
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
            $key === 'level 4' => 6,
            str_contains($key, 'kepala biro') => 6,
            str_contains($key, 'kasubag') => 4,
            str_contains($key, 'ketua kelompok') => 3,
            str_contains($key, 'ketua tim') => 2,
            str_contains($key, 'admin') => 5,
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
    // Dipanggil lewat tombol "Hapus" di halaman Kelola Pegawai
    // (route DELETE /admin/pegawai/{id}, name: admin.pegawai.destroy).
    //
    // Tujuan: mengoreksi data duplikat / salah ketik NIP / batal penempatan
    // di tahap awal. Pegawai Resign / Pensiun TIDAK dihapus, melainkan diubah
    // Status Kepegawaiannya lewat EDIT.
    //
    // PERTAHANAN LAPIS 2 (gembok backend). Di UI, pegawai yang tidak memenuhi
    // syarat hanya mendapat pop-up info tanpa opsi "Ya, Hapus" (Lapis 1, flag
    // `bisa_dihapus` dari kelolaPegawai()), tapi request langsung ke endpoint
    // ini tetap divalidasi ulang dengan helper yang SAMA. Penghapusan DITOLAK
    // otomatis kalau:
    //   - pegawai sudah punya riwayat cuti (STATUS_CUTI_BERRIWAYAT), ATAU
    //   - akun sudah berusia >= BATAS_HARI_HAPUS_PEGAWAI hari.
    // Pesan penolakan dikirim lewat error key 'hapus' dan tampil sebagai toast
    // merah di KelolaPegawai.vue.
    //
    // Model Pegawai memakai trait SoftDeletes, jadi delete() hanya mengisi
    // kolom `deleted_at` (bisa dipulihkan, dan storePegawai() otomatis
    // memulihkan NIP yang sama). SaldoCuti tidak dihapus.
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


        // Syarat 1: belum punya riwayat pemakaian cuti.
        // Syarat 2: akun masih baru (didaftarkan < BATAS_HARI_HAPUS_PEGAWAI hari lalu).
        // Dua-duanya memakai helper bersama dengan kelolaPegawai().
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


    // 2. Menampilkan Daftar Saldo Cuti Pegawai Tahun Ini
    //
    // Setiap pegawai dilengkapi field `preview_carry_over` (`dari_saldo_biasa`,
    // `dari_penangguhan_resmi`, `total`) — perkiraan carry-over andai saldo
    // digenerate lewat Command GenerateSaldoCutiTahunBaru. Ini HANYA preview
    // untuk Admin, BUKAN nilai yang otomatis tersimpan.
    public function kelolaSaldo()
    {
        $tahun = date('Y');


        $pegawai = Pegawai::with(['saldoCuti' => function ($query) use ($tahun) {
            $query->where('tahun', $tahun);
        }])->orderBy('nama', 'asc')->get();


        $pegawai->each(function ($p) use ($tahun) {
            $p->preview_carry_over = $this->hitungPreviewCarryOver($p->id, (int) $tahun);
        });


        return Inertia::render('Admin/KelolaSaldo', [
            'pegawai' => $pegawai,
            'tahun' => $tahun
        ]);
    }


    // 3. Memperbarui atau Membuat Saldo Cuti Baru
    //
    // Kolom `ditangguhkan_resmi` sudah tidak dipakai — penangguhan resmi
    // tercatat otomatis lewat tabel `cuti_ditangguhkans` (PembatalanController@process).
    //
    // Setiap kali Admin mengubah `sisa` secara manual dari angka yang sudah
    // ada, perubahan dicatat sebagai satu baris di `histori_saldos` (jejak
    // siapa, kapan, berapa). Baris histori HANYA dibuat kalau `sisa` benar-benar berubah.
    public function updateSaldo(Request $request, int $id)
    {
        abort_unless((int) $request->user()->role_id === 5, 403);


        $request->validate([
            'kuota_tahunan' => ['required', 'numeric', 'min:0'],
            'carry_forward_normal' => ['nullable', 'numeric', 'min:0', 'required_without:sisa'],
            'sisa' => ['nullable', 'numeric', 'min:0', 'required_without:carry_forward_normal'],
        ]);


        $tahun = date('Y');


        $saldoSebelumnya = SaldoCuti::where('pegawai_id', $id)->where('tahun', $tahun)->first();
        $sisaSebelumnya = $saldoSebelumnya->sisa ?? null;
        $sisa = $request->has('sisa')
            ? $request->sisa
            : ($saldoSebelumnya->sisa ?? 0);
        $carryForward = $request->has('carry_forward_normal')
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
                // [FIX] Sebelumnya auth()->id() (Intelephense P1013 "Undefined method 'id'").
                'diubah_oleh' => $request->user()->id,
                'tanggal_perubahan' => now(),
            ]);
        }


        return back()->with('success', 'Saldo cuti berhasil diperbarui.');
    }


    // ================= GENERATE SALDO TAHUN BARU (TOMBOL MANUAL) =================
    // Dipanggil dari tombol "Generate Saldo Tahun Baru" di halaman Rekap Kuota
    // Detail. SENGAJA hanya memanggil Artisan Command `saldo-cuti:generate-tahun-baru`
    // yang SAMA dengan yang dijalankan scheduler tiap 1 Januari, jadi hasil
    // manual dan otomatis selalu identik. Berguna sebagai cadangan kalau
    // scheduler gagal jalan, atau untuk generate ulang setelah koreksi data.
    //
    // PERBAIKAN: kode keluar command DIPERIKSA. Kalau command menolak (mis.
    // tahun di luar batas yang diizinkan), pesan command dikembalikan sebagai
    // error di field 'tahun' (tampil di modal), BUKAN banner sukses.
    public function generateSaldoManual(Request $request)
    {
        abort_unless((int) $request->user()->role_id === 5, 403);


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


        return Inertia::render('Admin/RekapLaporan', [
            'laporan' => $data['laporan'],
            'tahun' => $data['tahun'],
            'filters' => $request->only(['search', 'tahun', 'bulan', 'kelompok_substansi']),
            // [UBAH] Dropdown kelompok hanya dari pegawai yang punya dompet ASLI
            // di tahun laporan, selaras dengan gembok di buildLaporanData().
            'daftarKelompokSubstansi' => Pegawai::where('role_id', '!=', self::ROLE_ID_ADMIN)
                ->whereHas('saldoCuti', function (Builder $q) use ($data) {
                    $this->dompetTahunAsli($q, (int) $data['tahun']);
                })
                ->whereNotNull('kelompok_substansi')
                ->where('kelompok_substansi', '!=', '')
                ->distinct()
                ->orderBy('kelompok_substansi')
                ->pluck('kelompok_substansi'),
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
        $GREEN_DARK   = '1D9E75';
        $GREEN_LIGHT  = 'E1F5EE';
        $GREEN_HEADER = '97C459';
        $GREEN_TOTAL  = '639922';
        $AMBER        = 'FAC775';
        $BLUE_BADGE   = '85B7EB';
        $PURPLE_TINGGI = 'AFA9EC';
        $PURPLE_MUDA   = 'CECBF6';
        $GRAY_STAF    = 'D3D1C7';
        $TOTAL_ROW_BG = 'FAEEDA';
        $ZEBRA        = 'F4F8F0';


        // ---------- Judul ----------
        $sheet->mergeCells("A1:{$lastColLetter}1");
        $sheet->setCellValue('A1', 'REKAPITULASI CUTI TAHUNAN PEGAWAI — TAHUN ' . $tahun);
        $sheet->getStyle('A1')->getFont()->setName('Arial')->setSize(14)->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($GREEN_DARK);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(26);


        $sheet->mergeCells("A2:{$lastColLetter}2");
        $sheet->setCellValue('A2', 'Kementerian Pertanian RI — Biro Organisasi dan SDM Aparatur');
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
            $sheet->setCellValue("B{$r}", $item['nama']);
            $sheet->setCellValueExplicit("C{$r}", $item['nip'], DataType::TYPE_STRING);
            $sheet->setCellValue("D{$r}", $item['jabatan'] ?? '-');


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


            $sheet->setCellValue("{$totalLetter}{$r}", "=SUM({$bulanStartLetter}{$r}:{$bulanEndLetter}{$r})");
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


        $filename = "Rekap_Cuti_Tahunan_{$tahun}_" . date('Y-m-d') . ".xlsx";


        $writer = new Xlsx($spreadsheet);


        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }


    // 6. Fungsi Menangguhkan Cuti (Oleh Admin/L4)
    public function suspendCuti(Request $request, int $id)
    {
        abort_unless((int) $request->user()->role_id === 6, 403, 'Hanya L4 yang dapat menangguhkan cuti.');


        $request->validate([
            'alasan' => ['required', 'string', 'max:255']
        ]);


        $pengajuan = PengajuanCuti::findOrFail($id);


        if ($pengajuan->status !== 'disetujui') {
            return back()->with('error', 'Hanya cuti yang telah disetujui yang dapat ditangguhkan.');
        }


        $tahunCuti = date('Y', strtotime($pengajuan->tanggal_mulai));
        $saldo = SaldoCuti::where('pegawai_id', $pengajuan->pegawai_id)
            ->where('tahun', $tahunCuti)
            ->first();


        if ($saldo) {
            $saldo->update([
                'sisa' => $saldo->sisa + $pengajuan->jumlah_hari
            ]);
        }


        $keteranganBaru = $pengajuan->keterangan . ' | [DITANGGUHKAN: ' . $request->alasan . ']';


        $pengajuan->update([
            'status' => 'dibatalkan_ditangguhkan',
            'keterangan' => $keteranganBaru
        ]);


        return back()->with('success', 'Cuti berhasil ditangguhkan dan saldo telah dikembalikan.');
    }


    // 7. Menampilkan Halaman Kelola Hari Libur
    public function kelolaLibur()
    {
        $libur = HariLibur::where('tanggal', '>=', Carbon::today()->toDateString())
            ->orderBy('tanggal', 'asc')
            ->paginate(7);


        return Inertia::render('Admin/KelolaLibur', [
            'libur' => $libur
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
            'tanggal.unique' => 'Tanggal ini sudah didaftarkan sebagai hari libur.'
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


            if (empty(array_filter($row, fn ($value) => $value !== ''))) {
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
