<?php

namespace App\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\PengajuanCuti;
use App\Models\SaldoCuti;
use App\Models\Notifikasi;
use App\Models\Pegawai;
use App\Models\HariLibur;
use App\Models\ApprovalLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Carbon\Carbon;

// ================= CATATAN =================
// File ini adalah controller SISI KARYAWAN (form pengajuan cuti, riwayat,
// kalender tim, unduh PDF, batal, revisi). Logika approve/reject (termasuk
// perbaikan field 'alasan' & level_saat_ini) ada di app/Http/Controllers/
// MonitoringCutiController.php (controller SISI ATASAN), bukan di sini.
//
// ================= PERBAIKAN (LAMPIRAN DISK PRIVATE) =================
// Lampiran disimpan di disk 'local' (storage/app/private), bukan 'public'.
// Unduh hanya lewat route lampiran.unduh + LampiranController (cek hak akses).
// Path di DB tetap relatif (lampiran-cuti/...), accessor model mengubahnya
// menjadi URL route. Hapus file yatim memakai getRawOriginal / path mentah.
// ================= END LAMPIRAN DISK PRIVATE =================
//
// ================= PERBAIKAN TERBARU (MASA KERJA / TANGGAL MASUK EFEKTIF) =================
// BUG: masa kerja untuk syarat Cuti Besar dihitung langsung dari kolom
// tanggal_masuk. Kalau kolom itu kosong / placeholder (0000-00-00) / tidak
// bisa di-parse, hasilnya 0 tahun dan opsi Cuti Besar terkunci keliru.
// downloadPdf() juga memakai Carbon::parse(null) yang menghasilkan "sekarang",
// sehingga PDF mencetak masa kerja "0 Tahun / 0 Bulan".
//
// PERBAIKAN: helper tanggalMasukEfektif() dengan prioritas
//   1) kolom tanggal_masuk (jika valid: tahun >= 1950 dan bukan masa depan)
//   2) cadangan: TMT dari NIP 18 digit (digit 9-12 = tahun, 13-14 = bulan)
// hitungMasaKerjaTahun() dan downloadPdf() sama-sama memakai helper ini.
// CATATAN: kalau tanggal_masuk TERISI tapi salah (mis. tanggal akun dibuat),
// helper tetap mempercayainya. Datanya harus dikoreksi di Kelola Pegawai.
//
// ================= PERBAIKAN TERBARU (ATURAN TUNGGAL BATAL MANDIRI) =================
// Aturan "boleh dibatalkan mandiri atau tidak" sekarang hanya ada di SATU
// tempat: bisaDibatalkanMandiri() = cuti berstatus 'disetujui' DAN tanggal
// mulainya masih SESUDAH hari ini.
//   - batalkanMandiri() memakai helper ini (penjaga akhir di server).
//   - history() mengirim flag 'bisa_batal_mandiri' (true/false) pada setiap
//     baris riwayat, dan RiwayatPengajuan.vue memakainya untuk menampilkan
//     atau menyembunyikan tombol Batalkan. Jadi tampilan dan server tidak
//     mungkin berbeda, dan jam perangkat karyawan tidak berpengaruh.
//   - Contoh cuti 13-19 Okt: sampai 12 Okt tombol tampil dan berfungsi;
//     mulai 13 Okt (hari pertama cuti) tombol tidak aktif dan server menolak.
//   - Pastikan 'timezone' di config/app.php bernilai 'Asia/Jakarta'.
//
// ================= PERBAIKAN TERBARU (REVIEW CONTROLLER) =================
//   1. LAMPIRAN: validasi store() kini punya pesan kustom berbahasa Indonesia
//      (lihat pesanValidasiLampiran()). Jika transaksi gagal karena error
//      APA PUN (bukan hanya ValidationException), file lampiran yang sudah
//      terunggah dihapus supaya tidak jadi file yatim.
//   2. KALENDER TIM: teamCalendar() menerima parameter ?bulan=YYYY-MM dan
//      mengirim cuti yang beririsan dengan bulan itu (termasuk yang sudah
//      lewat), bukan lagi hanya cuti mendatang. Pembedaan lampau/mendatang
//      dilakukan di KalenderTim.vue. Prop 'bulan' ikut dikirim.
//   3. BATAL MANDIRI: batalkanMandiri() menolak pembatalan cuti yang sudah
//      dimulai atau sudah lewat, supaya saldo cuti lampau tidak bisa pulih.
//   4. SALDO DI store(): pengecekan saldo diulang DI DALAM transaksi setelah
//      baris pegawai dikunci (lockForUpdate), sehingga dua request bersamaan
//      dengan tanggal berbeda tidak bisa sama-sama lolos.
//   5. SALDO DI revisi(): pengecekan saldo Cuti Tahunan kini berlaku untuk
//      SEMUA role (sebelumnya hanya jalur Kepala Biro).
//   6. NOTIFIKASI: penerima notifikasi dicari lewat cariAtasanTujuan().
//      Untuk L1 (role_id 2) dicari per tim_kerja (sesuai wewenang di
//      MonitoringCutiController::process()), bukan per kelompok_substansi.
//
// ================= PERBAIKAN BARU (PENGAJUAN GANDA / TANGGAL BENTROK) =================
// BUG: pegawai bisa mengajukan cuti berkali-kali untuk tanggal yang sama atau
// rentang yang tumpang tindih (contoh nyata: pegawai_id 5, id 76-79, semuanya
// 27-29 Okt 2026, sempat aktif bersamaan).
//
// PERBAIKAN:
//   - Konstanta STATUS_AKTIF: status yang dianggap "masih aktif" dan memblokir
//     pengajuan lain di tanggal yang sama (menunggu_l1..l4 + disetujui).
//     Status ditolak / dibatalkan_reguler / dibatalkan / ditangguhkan /
//     dibatalkan_ditangguhkan TIDAK memblokir, jadi tanggalnya boleh diajukan lagi.
//   - Helper pengajuanBentrok() & pesanBentrok(): cek tumpang tindih rentang
//     (mulai_baru <= selesai_lama AND selesai_baru >= mulai_lama). Berlaku untuk
//     SEMUA jenis cuti.
//   - store(): dicek dua kali — sekali sebelum transaksi (pesan error cepat) dan
//     sekali LAGI di dalam transaksi setelah baris pegawai dikunci
//     (lockForUpdate), supaya klik ganda / dua request bersamaan tidak lolos.
//     Lampiran yang sudah terunggah dihapus kalau transaksi ditolak.
//   - revisi(): dicek dengan mengecualikan pengajuan yang sedang direvisi.
//     Posisinya sebelum percabangan L4, jadi jalur L4 ikut tercakup.
//   - create(): prop 'pengajuan_aktif' dikirim ke AjukanCuti.vue untuk
//     peringatan dini (backend tetap penjaga akhir).
// ================= END PERBAIKAN PENGAJUAN GANDA =================
//
// ================= PERBAIKAN BARU (L4 / KEPALA BIRO DISETUJUI OTOMATIS) =================
// L4 (role_id 6) adalah pejabat tertinggi dan tidak punya atasan, sehingga
// pengajuan cutinya LANGSUNG berstatus 'disetujui' tanpa antrean.
//   - Berlaku untuk SEMUA jenis cuti (Tahunan, Melahirkan, Besar, Alasan Penting).
//   - Validasi di store() tetap berlaku (saldo cukup, saldo tahun ada, masa
//     kerja Cuti Besar), jadi persetujuan otomatis tidak menembus aturan saldo.
//   - tentukanLevelAwalCuti() mengembalikan status 'disetujui' untuk role 6
//     (sebelumnya L4 jatuh ke cascade staf dan malah masuk 'menunggu_l3').
//   - Helper setujuiOtomatisKabiro(): status disetujui, kolom legacy `sisa`
//     dikurangi (hanya Cuti Tahunan), ApprovalLog dicatat, Admin HR (role 5)
//     menerima notifikasi INFO saja.
//   - Dipakai juga di revisi() (cuti L4 yang ditangguhkan lalu direvisi).
//   - downloadPdf() mengirim flag 'pemohonKabiro' ke surat-cuti.blade.php
//     supaya blok tanda tangan L3/L4 tidak ditampilkan untuk surat L4.
// ================= END PERBAIKAN L4 =================
//
// ================= PERBAIKAN (SINKRONISASI RUMUS SALDO) =================
// BUG: create(), store(), history(), dan downloadPdf() masing-masing menghitung
// saldo sendiri dengan rumus (kuota + carry_forward_normal) - terpakai, yaitu
// carry-forward dipakai UTUH tanpa batas bawaan 6 hari dan tanpa koreksi_saldo
// Admin HR. Akibatnya angka di form Ajukan Cuti (mis. 22) tidak sama dengan
// Dashboard & Rekap Kuota Admin (mis. 18), dan validasi saldo di store() terlalu
// longgar (pegawai bisa mengajukan melebihi hak sebenarnya).
//
// PERBAIKAN: semua perhitungan sekarang lewat SATU helper, hitungSaldoCuti(),
// dengan rumus yang SAMA dengan DashboardController::hitungSaldoPegawai() dan
// RekapKuotaDetailController::hitungKuota():
//   bawaan eligible = 12 jika sisa (tahun-2) & sisa (tahun-1) sama-sama 12,
//                     selain itu min(6, sisa tahun-1)
//   total tersedia  = (kuota + bawaan eligible) - terpakai EFEKTIF
//                     (ikut koreksi_saldo Admin HR lewat SaldoCuti::terpakaiEfektif)
//   sisa            = total tersedia - cuti yang masih menunggu persetujuan
// ================= END PERBAIKAN SINKRONISASI RUMUS SALDO =================
//
// ================= PERBAIKAN (SALDO TAHUN BELUM ADA, KHUSUS CUTI TAHUNAN) =================
// ATURAN: Cuti Tahunan HANYA boleh diajukan untuk tahun yang saldonya
// (baris saldo_cutis) sudah dibuat Admin HR lewat Generate Saldo Tahun Baru.
// Kalau rentang tanggal cuti menyentuh tahun yang belum punya baris saldo
// (tanggal mana pun, bukan hanya tanggal mulai), pengajuan DITOLAK.
//   - Berlaku untuk SEMUA role. SaldoCuti::firstOrCreate() otomatis untuk
//     atasan (role 2,3,4,6) di store() DIHAPUS, karena itu yang membuat
//     atasan lolos mengajukan cuti tahun yang saldonya belum ada.
//   - Jenis cuti lain (Cuti Alasan Penting, Melahirkan, Besar) TIDAK dicek,
//     karena tidak memotong saldo.
//   - Pengecekan yang sama dipasang di revisi() (hanya untuk Cuti Tahunan).
//   - Helper: tahunTanpaSaldo().
// ================= END PERBAIKAN SALDO TAHUN BELUM ADA =================
//
// ================= PERBAIKAN (ANAK KE-BERAPA UNTUK CUTI MELAHIRKAN) =================
// Sebelumnya field "Anak ke-berapa" ada di form (AjukanCuti.vue) tetapi
// TIDAK divalidasi dan TIDAK disimpan oleh store(), sehingga datanya hilang
// dan tidak bisa ditampilkan di modal Detail Cuti (Dashboard / Riwayat).
// Sekarang:
//   - Kolom baru `anak_ke` (migration add_anak_ke_to_pengajuan_cutis_table).
//   - store() memvalidasi 'anak_ke' (wajib hanya untuk Cuti Melahirkan).
//   - store() menyimpan 'anak_ke' hanya untuk Cuti Melahirkan; jenis cuti
//     lain otomatis null.
//   - Model PengajuanCuti memakai $guarded = ['id'], jadi tidak perlu
//     mengubah $fillable.
//   - revisi() TIDAK diubah karena hanya mengubah tanggal.
// ================= END PERBAIKAN ANAK KE-BERAPA =================
//
// ================= PERBAIKAN (Unit Tanpa Level Approval L1) =================
// Staf (role_id 1) punya 3 skenario level awal approval (CASCADING):
//   1. Staf yang punya tim_kerja DAN tim_kerja itu punya L1 (Ketua Tim
//      Kerja) -> mulai dari L1.
//   2. Staf yang tim_kerja-nya KOSONG ("-"/null) atau tim_kerjanya tidak
//      punya L1, TAPI kelompok_substansi-nya punya L2 -> skip L1, mulai L2.
//   3. Staf yang kelompok_substansi-nya tidak punya L1 MAUPUN L2 (mis.
//      Subbagian Tata Usaha) -> skip keduanya, mulai dari L3.
// Pengecekan L1 di-scope per tim_kerja (bukan kelompok_substansi), karena
// wewenang L1 di MonitoringCutiController::process() juga di-scope per
// tim_kerja. Cascade yang sama dipakai di revisi(). Untuk Atasan yang
// mengajukan cuti sendiri (role_id 2,3,4) mulai dari level SETELAH
// levelnya sendiri. L4 (role_id 6) langsung disetujui (lihat di atas).
// Flag `ada_atasan_l1` yang dikirim lewat history() dihitung berdasarkan
// tim_kerja milik staf yang login (timKerjaPunyaAtasanL1).
//
// ================= PERBAIKAN (NAMA ATASAN L1 & L2 DI MODAL DETAIL) =================
// history() menghitung nama atasan L1/L2 MILIK $user (pemilik pengajuan)
// memakai accessor di model Pegawai (getKetuaTimKerjaAttribute /
// getKetuaKelompokAttribute), lalu menempelkannya ke
// $item->pegawai->nama_l1 / nama_l2 untuk setiap baris riwayat. Dihitung
// sekali di luar loop karena halaman ini selalu berisi pengajuan $user.
//
// ================= PERBAIKAN (KALENDER TIM) =================
// teamCalendar(): hanya pengajuan berstatus 'disetujui' (rekan maupun milik
// sendiri). Data dikirim PER BULAN (?bulan=YYYY-MM) dan MENCAKUP cuti yang
// sudah lewat. Prop 'userPegawaiId' dikirim supaya KalenderTim.vue bisa
// menandai entri sendiri dengan "(Anda)".
// Prop 'disetujuiBulanIni' = jumlah pengajuan disetujui yang beririsan
// dengan bulan yang sedang dilihat (sudah sesuai role & bulan).
// Field privat (keterangan, alamat_cuti, no_telp, lampiran, dll.) di-hide
// lewat makeHidden() supaya tidak bocor di response Inertia.
//
// ================= PERBAIKAN (FILTER STATUS RIWAYAT + rute_level) =================
// 1. FILTER STATUS di history() memakai STATUS EFEKTIF (sama dengan tabel &
//    modal RiwayatPengajuan.vue):
//      - 'ditangguhkan'      -> 'ditangguhkan' / 'dibatalkan_ditangguhkan',
//                               plus data lama 'ditolak' yang keterangannya
//                               mengandung "ditangguhkan".
//      - 'ditolak'           -> penolakan murni (keterangan TIDAK
//                               mengandung "ditangguhkan").
//      - 'dibatalkan_reguler'-> mencakup nama lama 'dibatalkan'.
//      - lainnya (menunggu_lX, disetujui) -> pencocokan langsung.
// 2. Prop 'rute_level' dikirim ke frontend lewat history() (hitungRuteLevel()),
//    dipakai untuk opsi dropdown filter status.
// ================= END CATATAN =================
class CutiController extends Controller
{
    // Syarat minimal masa kerja (tahun) untuk bisa mengajukan Cuti Besar.
    private const MASA_KERJA_MINIMAL_CUTI_BESAR = 5;

    // Status yang dianggap "masih aktif" dan memblokir pengajuan di tanggal yang
    // sama. 'ditolak', 'dibatalkan_reguler', 'dibatalkan', 'ditangguhkan', dan
    // 'dibatalkan_ditangguhkan' sengaja TIDAK ikut, jadi tanggalnya boleh
    // diajukan lagi.
    private const STATUS_AKTIF = ['menunggu_l1', 'menunggu_l2', 'menunggu_l3', 'menunggu_l4', 'disetujui'];


    // Batas keamanan: cegah loop hitungHariKerja pada tanggal ekstrem (mis. tahun 9999).
    private const MAKS_RENTANG_HARI = 92;           // hari kalender (mulai → selesai, inklusif)
    private const MAKS_MULAI_TAHUN_KE_DEPAN = 2;   // tanggal mulai maksimal N tahun dari hari ini

    /**
     * Tolak rentang cuti yang tidak wajar SEBELUM hitungHariKerja / transaksi DB.
     */
    private function pastikanRentangWajar(
        string $mulai,
        string $selesai,
        string $fieldError = 'tanggal_selesai'
    ): void {
        try {
            $tMulai = Carbon::parse($mulai)->startOfDay();
            $tSelesai = Carbon::parse($selesai)->startOfDay();
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'tanggal_mulai' => 'Tanggal cuti tidak valid.',
            ]);
        }

        if ($tSelesai->lt($tMulai)) {
            throw ValidationException::withMessages([
                'tanggal_selesai' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',
            ]);
        }

        $batasMulai = Carbon::today()->addYears(self::MAKS_MULAI_TAHUN_KE_DEPAN);
        if ($tMulai->gt($batasMulai)) {
            throw ValidationException::withMessages([
                'tanggal_mulai' => 'Tanggal mulai terlalu jauh ke depan. Maksimal '
                    . self::MAKS_MULAI_TAHUN_KE_DEPAN . ' tahun dari hari ini.',
            ]);
        }

        // +1 karena rentang inklusif (1 Jan–1 Jan = 1 hari)
        $hariKalender = $tMulai->diffInDays($tSelesai) + 1;
        if ($hariKalender > self::MAKS_RENTANG_HARI) {
            throw ValidationException::withMessages([
                $fieldError => 'Rentang cuti terlalu panjang. Maksimal '
                    . self::MAKS_RENTANG_HARI
                    . " hari kalender, Anda memilih {$hariKalender} hari.",
            ]);
        }
    }


    private function normalizeJenisCuti(?string $value)
    {
        if ($value === null) {
            return 'Cuti Tahunan';
        }

        $normalized = trim((string) $value);

        $map = [
            'cuti tahunan' => 'Cuti Tahunan',
            'cuti melahirkan' => 'Cuti Melahirkan',
            'cuti besar' => 'Cuti Besar',
            'cuti alasan penting' => 'Cuti Alasan Penting',
            'cuti_alasan_penting' => 'Cuti Alasan Penting',
        ];

        $lower = strtolower($normalized);

        return $map[$lower] ?? $normalized;
    }

    // ================= HELPER: PESAN VALIDASI PENGAJUAN =================
    // Pesan berbahasa Indonesia untuk store() (dan field lampiran).
    // 'lampiran.uploaded' muncul bila file ditolak PHP (melebihi
    // upload_max_filesize di php.ini) sebelum sempat divalidasi Laravel.
    private function pesanValidasiLampiran(): array
    {
        return [
            // Jenis & anak ke-
            'jenis_cuti.required' => 'Jenis cuti wajib dipilih.',
            'jenis_cuti.in'       => 'Jenis cuti tidak valid.',
            'anak_ke.required_if' => 'Kolom anak ke- wajib diisi untuk Cuti Melahirkan.',
            'anak_ke.integer'     => 'Kolom anak ke- harus berupa angka.',
            'anak_ke.min'         => 'Kolom anak ke- minimal 1.',
            'anak_ke.max'         => 'Kolom anak ke- maksimal 20.',

            // Tanggal
            'tanggal_mulai.required'       => 'Tanggal mulai wajib diisi.',
            'tanggal_mulai.date'           => 'Tanggal mulai tidak valid.',
            'tanggal_mulai.after_or_equal' => 'Tanggal mulai tidak boleh sebelum hari ini.',
            'tanggal_selesai.required'     => 'Tanggal selesai wajib diisi.',
            'tanggal_selesai.date'         => 'Tanggal selesai tidak valid.',
            'tanggal_selesai.after_or_equal' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',

            // Keterangan, alamat, telepon
            'keterangan.required'  => 'Keterangan / alasan cuti wajib diisi.',
            'alamat_cuti.required' => 'Alamat selama cuti wajib diisi.',
            'no_telp.required'     => 'Nomor telepon wajib diisi.',
            'no_telp.max'          => 'Nomor telepon maksimal 20 karakter.',

            // Lampiran
            'lampiran.file'     => 'Lampiran harus berupa file.',
            'lampiran.mimes'    => 'Format lampiran harus PDF, JPG, atau PNG.',
            'lampiran.max'      => 'Ukuran lampiran maksimal 2 MB.',
            'lampiran.uploaded' => 'Lampiran gagal diunggah. Pastikan ukurannya tidak lebih dari 2 MB.',
        ];
    }
    // ================= END HELPER PESAN VALIDASI PENGAJUAN =================

    // ================= HELPER: HITUNG SALDO CUTI TAHUNAN =================
    // SATU-SATUNYA tempat perhitungan saldo di controller ini. Rumus sama
    // dengan DashboardController::hitungSaldoPegawai() dan
    // RekapKuotaDetailController::hitungKuota() (halaman admin).
    //
    // Mengembalikan:
    //   kuota_tahunan, carry_forward_normal, sisa_dua_tahun_lalu,
    //   saldo_bawaan_eligible, cuti_terpakai (efektif, ikut koreksi Admin HR),
    //   cuti_menunggu, total_cuti_tersedia, sisa_cuti (= total - menunggu)
    private function hitungSaldoCuti(
        int $pegawaiId,
        int $tahun,
        int $kuotaDefault = 12
    ): array {
        $saldo = SaldoCuti::where('pegawai_id', $pegawaiId)->where('tahun', $tahun)->first();
        $saldoTahunLalu = SaldoCuti::where('pegawai_id', $pegawaiId)->where('tahun', $tahun - 1)->first();

        $kuota            = (int) ($saldo->kuota_tahunan ?? $kuotaDefault);
        $sisaTahunLalu    = (int) ($saldo->carry_forward_normal ?? 0);
        $sisaDuaTahunLalu = (int) ($saldoTahunLalu->carry_forward_normal ?? 0);

        // Ikut koreksi_saldo Admin HR (NULL = ikut sistem).
        $terpakai = (int) SaldoCuti::terpakaiEfektif($pegawaiId, $tahun, $saldo);

        $bawaanEligible = SaldoCuti::hitungBawaan(
            $sisaDuaTahunLalu,
            $sisaTahunLalu,
            $kuota
        );

        $menunggu = (int) PengajuanCuti::where('pegawai_id', $pegawaiId)
            ->where('jenis_cuti', 'Cuti Tahunan')
            ->whereIn('status', ['menunggu_l1', 'menunggu_l2', 'menunggu_l3', 'menunggu_l4'])
            ->whereYear('tanggal_mulai', $tahun)
            ->sum('jumlah_hari');

        $totalTersedia = max(($kuota + $bawaanEligible) - $terpakai, 0);

        return [
            'kuota_tahunan'         => $kuota,
            'carry_forward_normal'  => $sisaTahunLalu,
            'sisa_dua_tahun_lalu'   => $sisaDuaTahunLalu,
            'saldo_bawaan_eligible' => $bawaanEligible,
            'cuti_terpakai'         => $terpakai,
            'cuti_menunggu'         => $menunggu,
            'total_cuti_tersedia'   => $totalTersedia,
            'sisa_cuti'             => max($totalTersedia - $menunggu, 0),
        ];
    }
    // ================= END HELPER HITUNG SALDO CUTI =================

    // ================= HELPER: TANGGAL MASUK EFEKTIF =================
    // Prioritas: kolom tanggal_masuk (jika valid). Cadangan: TMT dari NIP
    // (digit 9-12 = tahun, digit 13-14 = bulan).
    // Kolom yang terisi tetap dipercaya, jadi tanggal yang SALAH tapi terisi
    // (mis. tanggal akun dibuat) harus dikoreksi di data pegawai.
    private function tanggalMasukEfektif(Pegawai $pegawai): ?Carbon
    {
        if (!empty($pegawai->tanggal_masuk)) {
            try {
                $tgl = Carbon::parse($pegawai->tanggal_masuk);
                // Abaikan placeholder seperti 0000-00-00 dan tanggal masa depan.
                if ($tgl->year >= 1950 && !$tgl->isFuture()) {
                    return $tgl->startOfDay();
                }
            } catch (\Exception $e) {
                // jatuh ke cadangan NIP
            }
        }

        $nip = preg_replace('/\D/', '', (string) $pegawai->nip);
        if (strlen($nip) === 18) {
            $tahun = (int) substr($nip, 8, 4);
            $bulan = (int) substr($nip, 12, 2);
            if ($tahun >= 1950 && $bulan >= 1 && $bulan <= 12) {
                return Carbon::create($tahun, $bulan, 1)->startOfDay();
            }
        }

        return null;
    }
    // ================= END HELPER TANGGAL MASUK EFEKTIF =================

    // ================= HELPER: MASA KERJA (SYARAT CUTI BESAR) =================
    private function hitungMasaKerjaTahun(Pegawai $pegawai): int
    {
        $tanggalMasuk = $this->tanggalMasukEfektif($pegawai);

        if (!$tanggalMasuk) {
            return 0;
        }

        return (int) floor($tanggalMasuk->diffInYears(Carbon::now()));
    }
    // ================= END HELPER MASA KERJA =================

    // ================= HELPER: HARI LIBUR NASIONAL & CUTI BERSAMA =================
    private function getHariLiburDates(): array
    {
        return HariLibur::pluck('tanggal')
            ->map(function ($tgl) {
                return Carbon::parse($tgl)->toDateString();
            })
            ->all();
    }

    private function hitungHariKerja(string $mulai, string $selesai, ?array $hariLiburDates = null): int
    {
        $hariLiburDates = $hariLiburDates ?? $this->getHariLiburDates();

        $startDate = Carbon::parse($mulai);
        $endDate = Carbon::parse($selesai);
        $jumlahHari = 0;

        $currentDate = $startDate->copy();
        while ($currentDate->lte($endDate)) {
            $tanggalStr = $currentDate->toDateString();
            $isHariLibur = in_array($tanggalStr, $hariLiburDates, true);

            if (!$currentDate->isWeekend() && !$isHariLibur) {
                $jumlahHari++;
            }

            $currentDate->addDay();
        }

        return $jumlahHari;
    }
    // ================= END HELPER HARI LIBUR =================

    // ================= HELPER: PENGAJUAN GANDA / TANGGAL BENTROK =================
    // Mengembalikan pengajuan AKTIF milik pegawai yang rentang tanggalnya
    // bersinggungan dengan [$mulai, $selesai], atau null kalau tidak ada.
    // Dua rentang bentrok jika: mulai_baru <= selesai_lama DAN selesai_baru >= mulai_lama.
    // $kecualiId dipakai saat revisi supaya pengajuan itu tidak bentrok dengan
    // dirinya sendiri.
    private function pengajuanBentrok(int $pegawaiId, string $mulai, string $selesai, ?int $kecualiId = null): ?PengajuanCuti
    {
        return PengajuanCuti::where('pegawai_id', $pegawaiId)
            ->whereIn('status', self::STATUS_AKTIF)
            ->when($kecualiId, fn($q) => $q->where('id', '!=', $kecualiId))
            ->whereDate('tanggal_mulai', '<=', $selesai)
            ->whereDate('tanggal_selesai', '>=', $mulai)
            ->first();
    }

    private function pesanBentrok(PengajuanCuti $bentrok): string
    {
        $mulai = Carbon::parse($bentrok->tanggal_mulai)->format('d/m/Y');
        $selesai = Carbon::parse($bentrok->tanggal_selesai)->format('d/m/Y');
        $rentang = $mulai === $selesai ? $mulai : "{$mulai} - {$selesai}";
        $status = $bentrok->status === 'disetujui' ? 'sudah disetujui' : 'masih menunggu persetujuan';

        return "Tanggal yang Anda pilih bentrok dengan pengajuan {$bentrok->jenis_cuti} ({$rentang}) yang {$status}. "
            . 'Batalkan pengajuan tersebut terlebih dahulu atau pilih tanggal lain.';
    }
    // ================= END HELPER PENGAJUAN GANDA =================

    // ================= [BARU] HELPER: ATURAN TUNGGAL BATAL MANDIRI =================
    // SATU-SATUNYA tempat yang menentukan apakah sebuah cuti boleh dibatalkan
    // mandiri oleh pegawai: statusnya 'disetujui' DAN tanggal mulainya masih
    // SESUDAH hari ini (cuti belum dimulai).
    // Dipakai oleh:
    //   - history()          -> flag 'bisa_batal_mandiri' untuk tombol di Vue
    //   - batalkanMandiri()  -> penjaga akhir di server
    // Dengan satu aturan ini, tampilan dan server tidak mungkin berbeda.
    private function bisaDibatalkanMandiri(PengajuanCuti $cuti): bool
    {
        return $cuti->status === 'disetujui'
            && Carbon::parse($cuti->tanggal_mulai)->startOfDay()->gt(Carbon::today());
    }
    // ================= END HELPER ATURAN BATAL MANDIRI =================

    // ================= HELPER: SALDO TAHUN BELUM ADA (KHUSUS CUTI TAHUNAN) =================
    // Mengembalikan tahun PERTAMA dalam rentang tanggal cuti yang belum punya
    // baris saldo_cutis untuk pegawai ini, atau null kalau semua tahun sudah
    // ada. "Dompet" tahun baru hanya dibuat Admin HR (Generate Saldo Tahun
    // Baru). Yang dicek adalah SEMUA tahun yang disentuh rentang tanggal
    // (mis. 31 Des 2026 - 2 Jan 2027 menyentuh 2026 dan 2027).
    private function tahunTanpaSaldo(int $pegawaiId, string $mulai, string $selesai): ?int
    {
        $tahunAwal = Carbon::parse($mulai)->year;
        $tahunAkhir = Carbon::parse($selesai)->year;

        for ($tahun = $tahunAwal; $tahun <= $tahunAkhir; $tahun++) {
            $ada = SaldoCuti::where('pegawai_id', $pegawaiId)
                ->where('tahun', $tahun)
                ->exists();

            if (!$ada) {
                return $tahun;
            }
        }

        return null;
    }
    // ================= END HELPER SALDO TAHUN BELUM ADA =================

    // ================= HELPER: DAFTAR TAHUN YANG SUDAH PUNYA SALDO =================
    // Dikirim ke frontend (create() & history()) sebagai prop
    // 'tahun_saldo_tersedia' supaya form Ajukan Cuti DAN modal Revisi bisa
    // memberi peringatan dini bila rentang tanggal menyentuh tahun yang
    // saldonya belum dibuat Admin HR. Backend (tahunTanpaSaldo) tetap
    // penjaga akhir.
    private function tahunSaldoTersedia(int $pegawaiId): array
    {
        return SaldoCuti::where('pegawai_id', $pegawaiId)
            ->pluck('tahun')
            ->map(fn($t) => (int) $t)
            ->unique()
            ->values()
            ->all();
    }
    // ================= END HELPER DAFTAR TAHUN SALDO =================

    // ================= HELPER: STRUKTUR APPROVAL PER DEPARTEMEN =================
    // Cek apakah suatu kelompok_substansi (departemen) punya pegawai dengan
    // role_id tertentu (dipakai untuk cek L2/role_id 3).
    private function departemenPunyaAtasanLevel(int $roleId, ?string $kelompokSubstansi): bool
    {
        if (empty($kelompokSubstansi)) {
            return false;
        }

        return Pegawai::where('role_id', $roleId)
            ->where('kelompok_substansi', $kelompokSubstansi)
            ->exists();
    }
    // ================= END HELPER STRUKTUR APPROVAL PER DEPARTEMEN =================

    // ================= HELPER: CEK L1 SESUAI TIM KERJA SPESIFIK =================
    // Wewenang L1 di MonitoringCutiController::process() di-scope ketat per
    // tim_kerja (bukan kelompok_substansi). Jadi pengecekan "apakah staf ini
    // punya atasan L1" HARUS ikut di-scope ke tim_kerja miliknya sendiri.
    private function timKerjaPunyaAtasanL1(?string $timKerja): bool
    {
        if (empty($timKerja)) {
            return false;
        }

        return Pegawai::where('role_id', 2)
            ->where('tim_kerja', $timKerja)
            ->exists();
    }
    // ================= END HELPER CEK L1 PER TIM KERJA =================

    // ================= [BARU] HELPER: CARI PENERIMA NOTIFIKASI ATASAN =================
    // L1 (role_id 2) di-scope per tim_kerja, selaras dengan wewenang L1 di
    // MonitoringCutiController::process(). Level lain dicari per
    // kelompok_substansi dengan cadangan ke siapa pun yang berperan sama
    // (L3/L4 hanya satu orang untuk seluruh biro).
    // Untuk L1 TIDAK ada cadangan lintas tim: tentukanLevelAwalCuti() hanya
    // memilih L1 bila tim_kerja pemohon memang punya Ketua Tim Kerja.
    private function cariAtasanTujuan(Pegawai $pemohon, int $targetRole): ?Pegawai
    {
        if ($targetRole === 2) {
            if (empty($pemohon->tim_kerja)) {
                return null;
            }

            return Pegawai::where('role_id', 2)
                ->where('tim_kerja', $pemohon->tim_kerja)
                ->first();
        }

        return Pegawai::where('role_id', $targetRole)
            ->where('kelompok_substansi', $pemohon->kelompok_substansi)
            ->first()
            ?? Pegawai::where('role_id', $targetRole)->first();
    }
    // ================= END HELPER CARI PENERIMA NOTIFIKASI =================

    private function tentukanLevelAwalCuti(Pegawai $user): array
    {
        // L4 (Kepala Biro) tidak punya atasan -> langsung disetujui.
        if ((int) $user->role_id === 6) {
            return ['status' => 'disetujui', 'level' => 6, 'target_role' => 5];
        }

        if ($user->role_id === 2) {
            return ['status' => 'menunggu_l2', 'level' => 2, 'target_role' => 3];
        }
        if ($user->role_id === 3) {
            return ['status' => 'menunggu_l3', 'level' => 3, 'target_role' => 4];
        }
        if ($user->role_id === 4) {
            return ['status' => 'menunggu_l4', 'level' => 4, 'target_role' => 6];
        }

        // ================= STAF (role_id 1): CASCADE L1 -> L2 -> L3 =================
        // 1. Staf punya tim_kerja DAN tim_kerja itu punya L1 -> mulai dari L1.
        if ($this->timKerjaPunyaAtasanL1($user->tim_kerja)) {
            return ['status' => 'menunggu_l1', 'level' => 1, 'target_role' => 2];
        }

        // 2. Tidak ada L1 yang cocok, tapi kelompok_substansi punya L2 -> mulai L2.
        if ($this->departemenPunyaAtasanLevel(3, $user->kelompok_substansi)) {
            return ['status' => 'menunggu_l2', 'level' => 2, 'target_role' => 3];
        }

        // 3. Tidak ada L1 maupun L2 -> langsung ke L3 (mis. Subbagian Tata Usaha).
        return ['status' => 'menunggu_l3', 'level' => 3, 'target_role' => 4];
        // ================= END CASCADE L1 -> L2 -> L3 =================
    }

    // ================= HELPER: PERSETUJUAN OTOMATIS KEPALA BIRO (L4) =================
    // Dipanggil DI DALAM DB::transaction. Meniru efek approval final L4 di
    // MonitoringCutiController::process(): status disetujui, kolom legacy
    // `sisa` dikurangi (hanya Cuti Tahunan, tidak pernah di bawah 0),
    // ApprovalLog dicatat. Admin HR (role 5) menerima notifikasi INFO saja
    // (tanpa tautan ke halaman approval).
    private function setujuiOtomatisKabiro(PengajuanCuti $pengajuan, Pegawai $user): void
    {
        $pengajuan->update([
            'status'         => 'disetujui',
            'level_saat_ini' => 6,
            'atasan_l4_id'   => $user->id,
        ]);

        if ($pengajuan->jenis_cuti === 'Cuti Tahunan') {
            $saldo = SaldoCuti::where('pegawai_id', $user->id)
                ->where('tahun', Carbon::parse($pengajuan->tanggal_mulai)->year)
                ->lockForUpdate()
                ->first();

            if ($saldo) {
                $saldo->update(['sisa' => max(0, (int) $saldo->sisa - (int) $pengajuan->jumlah_hari)]);
            }
        }

        ApprovalLog::create([
            'pengajuan_id'      => $pengajuan->id,
            'approver_id'       => $user->id,
            'level_approval'    => 4,
            'keputusan'         => 'setuju',
            'catatan'           => 'Disetujui otomatis (pemohon adalah Kepala Biro)',
            'tanggal_keputusan' => now(),
        ]);

        foreach (Pegawai::where('role_id', 5)->get() as $hr) {
            Notifikasi::create([
                'pegawai_id' => $hr->id,
                'judul'      => 'Info Cuti Kepala Biro',
                'pesan'      => $user->nama . ' (Kepala Biro) mengajukan cuti ' . $pengajuan->jenis_cuti
                    . ' dan disetujui otomatis. Info saja, tidak perlu tindakan.',
                'tautan'     => route('admin.rekap'),
                'is_read'    => false,
            ]);
        }
    }
    // ================= END HELPER PERSETUJUAN OTOMATIS KEPALA BIRO =================

    // ================= HELPER: RUTE LEVEL UNTUK FILTER STATUS RIWAYAT =================
    //   Admin HR (role 5)            -> [1,2,3,4]
    //   Atasan L4 (role 6, tertinggi)-> []
    //   Atasan L1 (role 2)           -> [2,3,4]
    //   Atasan L2 (role 3)           -> [3,4]
    //   Atasan L3 (role 4)           -> [4]
    //   Staf                         -> mengikuti cascade tentukanLevelAwalCuti()
    private function hitungRuteLevel(Pegawai $user): array
    {
        switch ((int) $user->role_id) {
            case 5:
                return [1, 2, 3, 4];
            case 6:
                return [];
            case 2:
                return [2, 3, 4];
            case 3:
                return [3, 4];
            case 4:
                return [4];
        }

        return match ($this->tentukanLevelAwalCuti($user)['level']) {
            1 => [1, 3, 4],
            2 => [2, 3, 4],
            default => [3, 4],
        };
    }
    // ================= END HELPER RUTE LEVEL =================

    // 1. Menampilkan Halaman Form Pengajuan
    public function create()
    {
        /** @var \App\Models\Pegawai $user */
        $user = Auth::user();
        $tahunCuti = (int) date('Y');

        // Rumus saldo lewat helper bersama (sama dengan Dashboard).
        $saldoInfo = $this->hitungSaldoCuti($user->id, $tahunCuti, (int) ($user->jatah_cuti ?? 12));

        $hariLiburs = HariLibur::orderBy('tanggal')
            ->get(['tanggal', 'keterangan', 'is_cuti_bersama'])
            ->map(function ($h) {
                return [
                    'tanggal' => Carbon::parse($h->tanggal)->toDateString(),
                    'keterangan' => $h->keterangan,
                    'is_cuti_bersama' => (bool) $h->is_cuti_bersama,
                ];
            });

        $masaKerjaTahun = $this->hitungMasaKerjaTahun($user);
        $bolehCutiBesar = $masaKerjaTahun >= self::MASA_KERJA_MINIMAL_CUTI_BESAR;

        // Rentang pengajuan aktif milik user, untuk peringatan dini
        // bentrok tanggal di form (backend tetap penjaga akhir).
        $rentangAktif = PengajuanCuti::where('pegawai_id', $user->id)
            ->whereIn('status', self::STATUS_AKTIF)
            ->whereDate('tanggal_selesai', '>=', Carbon::today())
            ->get(['jenis_cuti', 'tanggal_mulai', 'tanggal_selesai', 'status'])
            ->map(fn($p) => [
                'jenis_cuti' => $p->jenis_cuti,
                'tanggal_mulai' => Carbon::parse($p->tanggal_mulai)->toDateString(),
                'tanggal_selesai' => Carbon::parse($p->tanggal_selesai)->toDateString(),
                'status' => $p->status,
            ])
            ->values();

        return Inertia::render('Karyawan/AjukanCuti', [
            'sisa_cuti' => (int) $saldoInfo['sisa_cuti'],
            'total_cuti_tersedia' => (int) $saldoInfo['total_cuti_tersedia'],
            'hariLiburs' => $hariLiburs,
            // Dipakai AjukanCuti.vue sebagai sumber utama masa kerja
            // (konsisten dengan validasi store()).
            'masa_kerja_tahun' => $masaKerjaTahun,
            'boleh_cuti_besar' => $bolehCutiBesar,
            'masa_kerja_minimal_cuti_besar' => self::MASA_KERJA_MINIMAL_CUTI_BESAR,
            // Tahun yang sudah punya baris saldo (peringatan dini di form).
            'tahun_saldo_tersedia' => $this->tahunSaldoTersedia($user->id),
            // Pengajuan aktif (peringatan dini bentrok tanggal).
            'pengajuan_aktif' => $rentangAktif,
        ]);
    }

    // 2. Memproses Simpan Data
    public function store(Request $request)
    {
        $request->validate([
            'jenis_cuti' => ['required', 'string', 'in:Cuti Tahunan,Cuti Melahirkan,Cuti Besar,Cuti Alasan Penting'],
            // "Anak ke-berapa" wajib diisi hanya untuk Cuti Melahirkan.
            'anak_ke' => ['nullable', 'required_if:jenis_cuti,Cuti Melahirkan', 'integer', 'min:1', 'max:20'],
            'tanggal_mulai' => ['required', 'date', 'after_or_equal:today'],
            'tanggal_selesai' => ['required', 'date', 'after_or_equal:tanggal_mulai'],
            'keterangan' => ['required', 'string'],
            'alamat_cuti' => ['required', 'string'],
            'no_telp' => ['required', 'string', 'max:20'],
            // Lampiran OPSIONAL: PDF/JPG/PNG, maksimal 2 MB (2048 KB).
            'lampiran' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:2048'],
        ], $this->pesanValidasiLampiran());

        $this->pastikanRentangWajar($request->tanggal_mulai, $request->tanggal_selesai);

        /** @var \App\Models\Pegawai $user */
        $user = Auth::user();

        $jenisCuti = $this->normalizeJenisCuti($request->jenis_cuti);

        // ================= VALIDASI: SYARAT MASA KERJA UNTUK CUTI BESAR =================
        if ($jenisCuti === 'Cuti Besar') {
            $masaKerja = $this->hitungMasaKerjaTahun($user);

            if ($masaKerja < self::MASA_KERJA_MINIMAL_CUTI_BESAR) {
                return back()->withErrors([
                    'jenis_cuti' => 'Cuti Besar hanya dapat diajukan jika masa kerja sudah mencapai minimal '
                        . self::MASA_KERJA_MINIMAL_CUTI_BESAR
                        . " tahun. Masa kerja Anda saat ini: {$masaKerja} tahun.",
                ])->withInput();
            }
        }
        // ================= END VALIDASI MASA KERJA =================

        // ================= VALIDASI: SALDO TAHUN BELUM ADA (KHUSUS CUTI TAHUNAN) =================
        // Cuti Tahunan hanya boleh diajukan untuk tahun yang saldonya sudah
        // dibuat Admin HR. Berlaku untuk SEMUA role.
        if ($jenisCuti === 'Cuti Tahunan') {
            $tahunKosong = $this->tahunTanpaSaldo($user->id, $request->tanggal_mulai, $request->tanggal_selesai);

            if ($tahunKosong !== null) {
                return back()->withErrors([
                    'jenis_cuti' => "Saldo cuti tahun {$tahunKosong} belum tersedia. Cuti Tahunan untuk tahun tersebut baru bisa diajukan setelah Admin HR membuat saldonya.",
                ])->withInput();
            }
        }
        // ================= END VALIDASI SALDO TAHUN BELUM ADA =================

        // ================= PERHITUNGAN HARI KERJA =================
        $jumlah_hari = $this->hitungHariKerja($request->tanggal_mulai, $request->tanggal_selesai);

        if ($jumlah_hari === 0) {
            return back()->withErrors([
                'tanggal_mulai' => 'Tanggal yang dipilih tidak valid karena hanya mencakup akhir pekan dan/atau hari libur nasional/cuti bersama.',
            ])->withInput();
        }
        // ================= END PERHITUNGAN HARI KERJA =================

        // ================= VALIDASI: PENGAJUAN GANDA / TANGGAL BENTROK =================
        // Cek awal (cepat). Cek ulang yang kedap race condition ada di dalam
        // transaksi di bawah.
        if ($bentrok = $this->pengajuanBentrok($user->id, $request->tanggal_mulai, $request->tanggal_selesai)) {
            return back()->withErrors([
                'tanggal_mulai' => $this->pesanBentrok($bentrok),
            ])->withInput();
        }
        // ================= END VALIDASI PENGAJUAN GANDA =================

        // Cek saldo awal (cepat, pesan error langsung). Cek ulang yang kedap
        // race condition ada di dalam transaksi di bawah.
        $tahunCuti = Carbon::parse($request->tanggal_mulai)->year;
        $kuotaDefault = (int) ($user->jatah_cuti ?? 12);

        if ($jenisCuti === 'Cuti Tahunan') {
            // sisa = (kuota + bawaan eligible) - terpakai efektif - menunggu
            $saldoInfo = $this->hitungSaldoCuti($user->id, $tahunCuti, $kuotaDefault);
            $sisaTersedia = $saldoInfo['sisa_cuti'];

            if ($jumlah_hari > $sisaTersedia) {
                return back()->withErrors([
                    'jenis_cuti' => "Pengajuan Cuti Tahunan melebihi sisa saldo. Sisa yang tersedia saat ini: {$sisaTersedia} hari.",
                ])->withInput();
            }
        }

        // =========================================================
        // PROSES UPLOAD FILE LAMPIRAN (JIKA ADA)
        // =========================================================
        $lampiranPath = null;
        // [DIUBAH] Disk 'local' (private), bukan 'public'. File hanya bisa
        // dibuka lewat route lampiran.unduh (LampiranController).
        if ($request->hasFile('lampiran')) {
            $lampiranPath = $request->file('lampiran')->store('lampiran-cuti', 'local');
        }

        // =========================================================
        // MENENTUKAN STATUS AWAL & TARGET NOTIFIKASI (HIERARKI)
        // =========================================================
        $levelAwal = $this->tentukanLevelAwalCuti($user);
        $statusAwal = $levelAwal['status'];
        $levelSaatIni = $levelAwal['level'];
        $targetRoleNotifikasi = $levelAwal['target_role'];

        // L4 (Kepala Biro) disetujui otomatis di dalam transaksi yang
        // sama dengan pembuatan pengajuan, supaya tidak ada data setengah jadi.
        $isKabiro = (int) $user->role_id === 6;

        try {
            $pengajuan = DB::transaction(function () use (
                $user,
                $jenisCuti,
                $request,
                $jumlah_hari,
                $lampiranPath,
                $statusAwal,
                $levelSaatIni,
                $isKabiro,
                $tahunCuti,
                $kuotaDefault
            ) {
                // Kunci baris pegawai: request kedua dari user yang sama
                // (klik ganda / dua tab) harus menunggu transaksi ini selesai,
                // lalu cek ulang bentrok DAN saldo. Tanpa ini, dua request yang
                // masuk bersamaan bisa sama-sama lolos cek awal.
                Pegawai::where('id', $user->id)->lockForUpdate()->first();

                if ($bentrok = $this->pengajuanBentrok($user->id, $request->tanggal_mulai, $request->tanggal_selesai)) {
                    throw ValidationException::withMessages([
                        'tanggal_mulai' => $this->pesanBentrok($bentrok),
                    ]);
                }

                // [BARU] Cek ulang saldo setelah baris pegawai terkunci.
                if ($jenisCuti === 'Cuti Tahunan') {
                    $saldoTerkini = $this->hitungSaldoCuti($user->id, $tahunCuti, $kuotaDefault);

                    if ($jumlah_hari > $saldoTerkini['sisa_cuti']) {
                        throw ValidationException::withMessages([
                            'jenis_cuti' => "Pengajuan Cuti Tahunan melebihi sisa saldo. Sisa yang tersedia saat ini: {$saldoTerkini['sisa_cuti']} hari.",
                        ]);
                    }
                }

                $p = PengajuanCuti::create([
                    'pegawai_id' => $user->id,
                    'jenis_cuti' => $jenisCuti,
                    // Simpan "Anak ke-berapa" hanya untuk Cuti Melahirkan.
                    'anak_ke' => $jenisCuti === 'Cuti Melahirkan' ? $request->anak_ke : null,
                    'tanggal_mulai' => $request->tanggal_mulai,
                    'tanggal_selesai' => $request->tanggal_selesai,
                    'jumlah_hari' => $jumlah_hari,
                    'keterangan' => $request->keterangan,
                    'alamat_cuti' => $request->alamat_cuti,
                    'no_telp' => $request->no_telp,
                    'lampiran' => $lampiranPath,
                    'status' => $statusAwal,
                    'level_saat_ini' => $levelSaatIni,
                ]);

                if ($isKabiro) {
                    $this->setujuiOtomatisKabiro($p, $user);
                }

                return $p;
            });
        } catch (\Throwable $e) {
            // [DIUBAH] Hapus lampiran yang sudah terlanjur diunggah untuk
            // error APA PUN (validasi, database, dll) supaya tidak jadi file
            // yatim. Error aslinya tetap dilempar ulang.
            if ($lampiranPath) {
                Storage::disk('local')->delete($lampiranPath);
                // Cadangan: jika pernah tersimpan di public (deploy campuran).
                Storage::disk('public')->delete($lampiranPath);
            }

            throw $e;
        }

        // L4 tidak punya atasan -> tidak ada notifikasi ke atasan.
        if ($isKabiro) {
            return redirect()->route('karyawan.riwayat')
                ->with('success', 'Pengajuan cuti Anda disetujui otomatis.');
        }

        // =========================================================
        // PROSES TRIGGER NOTIFIKASI KE ATASAN YANG TEPAT
        // =========================================================
        $atasanTarget = $this->cariAtasanTujuan($user, (int) $targetRoleNotifikasi);

        if ($atasanTarget) {
            Notifikasi::create([
                'pegawai_id' => $atasanTarget->id,
                'judul'      => 'Pengajuan Cuti Baru',
                'pesan'      => 'Ada pengajuan cuti baru dari ' . $user->nama . ' yang butuh persetujuan Anda.',
                'tautan'     => route('atasan.approval'),
                'is_read'    => false,
            ]);
        }

        return redirect()->route('karyawan.riwayat')->with('success', 'Pengajuan cuti berhasil dikirim.');
    }

    // 3. Menampilkan Halaman Riwayat Pengajuan
    public function history(Request $request)
    {
        /** @var \App\Models\Pegawai $user */
        $user = Auth::user();

        // 'pegawai' ikut di-eager load supaya $item->pegawai tersedia untuk
        // ditempeli nama_l1/nama_l2 di bawah.
        $query = PengajuanCuti::with([
            'pegawai',
            'atasanL1:id,nama,jabatan',
            'atasanL3:id,nama,jabatan',
            'atasanL4:id,nama,jabatan',
            'approvalLogs.approver:id,nama,jabatan',
            'cutiDitangguhkan',
        ])
            ->where('pegawai_id', $user->id)
            ->orderBy('created_at', 'desc');

        if ($request->filled('search')) {
            $query->where('keterangan', 'like', '%' . $request->search . '%');
        }

        // ================= FILTER STATUS (STATUS EFEKTIF) =================
        if ($request->filled('status')) {
            $statusFilter = $request->status;

            $query->where(function ($q) use ($statusFilter) {
                if ($statusFilter === 'ditangguhkan') {
                    $q->whereIn('status', ['ditangguhkan', 'dibatalkan_ditangguhkan'])
                        ->orWhere(function ($x) {
                            $x->where('status', 'ditolak')
                                ->where('keterangan', 'like', '%ditangguhkan%');
                        });
                } elseif ($statusFilter === 'ditolak') {
                    $q->where('status', 'ditolak')
                        ->where(function ($x) {
                            $x->whereNull('keterangan')
                                ->orWhere('keterangan', 'not like', '%ditangguhkan%');
                        });
                } elseif ($statusFilter === 'dibatalkan_reguler') {
                    $q->whereIn('status', ['dibatalkan_reguler', 'dibatalkan']);
                } else {
                    $q->where('status', $statusFilter);
                }
            });
        }
        // ================= END FILTER STATUS =================

        if ($request->filled('jenis_cuti')) {
            $query->where('jenis_cuti', $request->jenis_cuti);
        }

        $riwayat = $query->paginate(5)->withQueryString();

        // ================= NAMA ATASAN L1 & L2 DINAMIS =================
        $namaL1 = optional($user->ketua_tim_kerja)->nama;
        $namaL2 = optional($user->ketua_kelompok)->nama;
        $namaL3 = Pegawai::where('role_id', 4)
            ->where(fn($q) => $q->where('status_aktif', 'Aktif')->orWhereNull('status_aktif'))
            ->orderBy('id')
            ->value('nama') ?? 'Kasubag TU';
        $namaL4 = Pegawai::where('role_id', 6)
            ->where(fn($q) => $q->where('status_aktif', 'Aktif')->orWhereNull('status_aktif'))
            ->orderBy('id')
            ->value('nama') ?? 'Kepala Biro Perencanaan';

        foreach ($riwayat->getCollection() as $item) {
            if ($item->pegawai) {
                $item->pegawai->nama_l1 = $namaL1;
                $item->pegawai->nama_l2 = $namaL2;
                $item->pegawai->nama_l3 = $namaL3;
                $item->pegawai->nama_l4 = $namaL4;
            }

            // [BARU] Flag dari SATU aturan di server (bisaDibatalkanMandiri).
            // RiwayatPengajuan.vue memakai flag ini untuk tombol Batalkan,
            // sehingga tombol tidak bergantung pada jam perangkat pengguna.
            $item->bisa_batal_mandiri = $this->bisaDibatalkanMandiri($item);
            $item->bisa_direvisi = $item->bisaDirevisi();
        }
        // ================= END NAMA ATASAN L1 & L2 DINAMIS =================

        // Rumus saldo lewat helper bersama (sama dengan Dashboard).
        // Catatan: kuota default sekarang 12 (sebelumnya 0) bila baris saldo
        // belum ada, sama dengan Dashboard & halaman admin.
        $saldoInfo = $this->hitungSaldoCuti($user->id, (int) date('Y'), (int) ($user->jatah_cuti ?? 12));

        $stats = [
            'kuota_tahunan' => $saldoInfo['kuota_tahunan'],
            'carry_forward_normal' => $saldoInfo['carry_forward_normal'],
            'saldo_bawaan_eligible' => $saldoInfo['saldo_bawaan_eligible'],
            'cuti_terpakai' => $saldoInfo['cuti_terpakai'],
            'total_cuti_tersedia' => $saldoInfo['total_cuti_tersedia'],
        ];

        // Flag "apakah STAF YANG LOGIN INI akan melalui tahap L1" — di-scope
        // ke tim_kerja milik user (konsisten dengan cascade L1->L2->L3).
        $adaAtasanL1 = $this->timKerjaPunyaAtasanL1($user->tim_kerja);

        return Inertia::render('Karyawan/RiwayatPengajuan', [
            'riwayat' => $riwayat,
            'filters' => $request->only(['search', 'status', 'jenis_cuti']),
            'sisa_cuti' => (int) $saldoInfo['sisa_cuti'],
            'stats' => $stats,
            'hariLiburs' => HariLibur::orderBy('tanggal')->get(),
            'ada_atasan_l1' => $adaAtasanL1,
            // Level "Menunggu" untuk opsi dropdown filter status.
            'rute_level' => $this->hitungRuteLevel($user),
            // Tahun yang sudah punya baris saldo (peringatan dini di modal Revisi).
            'tahun_saldo_tersedia' => $this->tahunSaldoTersedia($user->id),
        ]);
    }

    // 4. Menampilkan Kalender Tim
    //
    // [DIUBAH] Data dikirim PER BULAN lewat parameter ?bulan=YYYY-MM, dan
    // MENCAKUP cuti yang sudah lewat (sebelumnya hanya yang tanggal_selesai
    // >= hari ini). Cuti yang beririsan dengan bulan yang dilihat ikut
    // dikirim, termasuk yang mulai di bulan sebelumnya atau berakhir di
    // bulan berikutnya. Pembedaan lampau/mendatang dilakukan di
    // KalenderTim.vue.
    //
    // Hanya status 'disetujui':
    //   - rekan setim  : hanya status 'disetujui'
    //   - milik sendiri: hanya status 'disetujui'
    // Query rekan mengecualikan user sendiri (`id != $user->id`); data milik
    // sendiri diambil lewat query terpisah ($cutiSaya) lalu digabung.
    public function teamCalendar(Request $request)
    {
        /** @var \App\Models\Pegawai $user */
        $user = Auth::user();

        // Validasi parameter bulan (format YYYY-MM); nilai tak valid jatuh
        // ke bulan ini, supaya URL yang diketik sembarangan tidak error.
        $bulan = $request->input('bulan');
        if (!is_string($bulan) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $bulan)) {
            $bulan = now()->format('Y-m');
        }
        $awalBulan = Carbon::createFromFormat('Y-m-d', $bulan . '-01')->startOfMonth()->toDateString();
        $akhirBulan = Carbon::createFromFormat('Y-m-d', $bulan . '-01')->endOfMonth()->toDateString();

        $listTimKerja = [];
        $listKelompok = [];

        // foto_profil disertakan supaya avatar pegawai bisa ditampilkan di modal Kalender Tim
        $pegawaiSelect = 'pegawai:id,nama,kelompok_substansi,tim_kerja,jabatan,foto_profil';

        if ($user->role_id === 2) {
            // ================= L1 (Ketua Tim Kerja) =================
            $query = PengajuanCuti::with($pegawaiSelect)
                ->whereHas('pegawai', function ($q) use ($user) {
                    $q->where('tim_kerja', $user->tim_kerja)
                        ->where('id', '!=', $user->id);
                });
        } elseif ($user->role_id === 3) {
            // ================= L2 (Ketua Kelompok Substansi) =================
            $query = PengajuanCuti::with($pegawaiSelect)
                ->whereHas('pegawai', function ($q) use ($user) {
                    $q->where('kelompok_substansi', $user->kelompok_substansi)
                        ->where('id', '!=', $user->id);
                });

            $listTimKerja = Pegawai::where('kelompok_substansi', $user->kelompok_substansi)
                ->whereNotNull('tim_kerja')
                ->where('tim_kerja', '!=', '-')
                ->distinct()
                ->orderBy('tim_kerja')
                ->pluck('tim_kerja')
                ->values();
        } elseif (in_array($user->role_id, [4, 6], true)) {
            // ================= L3 & L4 =================
            $query = PengajuanCuti::with($pegawaiSelect)
                ->whereHas('pegawai', function ($q) use ($user) {
                    $q->where('id', '!=', $user->id);
                });

            $listKelompok = Pegawai::whereNotNull('kelompok_substansi')
                ->distinct()
                ->orderBy('kelompok_substansi')
                ->pluck('kelompok_substansi')
                ->values();
        } else {
            // ================= STAFF (role_id 1) & fallback =================
            $query = PengajuanCuti::with($pegawaiSelect)
                ->whereHas('pegawai', function ($q) use ($user) {
                    $q->where('kelompok_substansi', $user->kelompok_substansi)
                        ->where('id', '!=', $user->id);
                });
        }

        // Cuti REKAN: hanya yang sudah disetujui penuh, beririsan dengan bulan yang dilihat.
        $cutiRekan = $query
            ->where('status', 'disetujui')
            ->whereDate('tanggal_mulai', '<=', $akhirBulan)
            ->whereDate('tanggal_selesai', '>=', $awalBulan)
            ->orderBy('tanggal_mulai', 'asc')
            ->get();

        // ================= CUTI MILIK SENDIRI =================
        $cutiSaya = PengajuanCuti::with($pegawaiSelect)
            ->where('pegawai_id', $user->id)
            ->where('status', 'disetujui')
            ->whereDate('tanggal_mulai', '<=', $akhirBulan)
            ->whereDate('tanggal_selesai', '>=', $awalBulan)
            ->get();
        // ================= END CUTI MILIK SENDIRI =================

        $cutiTim = $cutiRekan
            ->concat($cutiSaya)
            ->sortBy('tanggal_mulai')
            ->values();

        // Sembunyikan kolom yang tidak dipakai KalenderTim.vue (privat / internal).
        $cutiTim->each->makeHidden([
            'keterangan',
            'alamat_cuti',
            'no_telp',
            'lampiran',
            'anak_ke',
            'atasan_l1_id',
            'atasan_l3_id',
            'atasan_l4_id',
            'level_saat_ini',
        ]);

        $hariLiburs = \App\Models\HariLibur::all();

        // Jumlah pengajuan disetujui pada bulan yang sedang dilihat
        // (sudah sesuai role & filter bulan, dihitung dari $cutiTim).
        $disetujuiBulanIni = $cutiTim->count();

        return Inertia::render('Karyawan/KalenderTim', [
            'cutiTim' => $cutiTim,
            'bulan' => $bulan, // bulan yang sedang dilihat, format YYYY-MM
            'kelompok_substansi' => $user->kelompok_substansi,
            'hariLiburs' => $hariLiburs,
            'userRoleId' => $user->role_id,
            'userTimKerja' => $user->tim_kerja, // dipakai untuk badge info L1
            'userPegawaiId' => $user->id,       // dipakai untuk penanda "(Anda)"
            'listTimKerja' => $listTimKerja,
            'listKelompok' => $listKelompok,
            // Jumlah pengajuan disetujui pada bulan yang sedang dilihat.
            'disetujuiBulanIni' => $disetujuiBulanIni,
        ]);
    }

    // 5. Unduh PDF Bukti Cuti
    public function downloadPdf(int $id)
    {
        /** @var \App\Models\Pegawai $user */
        $user = Auth::user();

        $pengajuan = PengajuanCuti::with(['pegawai', 'atasanL1', 'atasanL3', 'atasanL4', 'approvalLogs'])->findOrFail($id);

        if ($pengajuan->pegawai_id !== $user->id || $pengajuan->status !== 'disetujui') {
            abort(403, 'Anda tidak memiliki akses, atau cuti belum disetujui sepenuhnya.');
        }

        // [DIUBAH] Pakai tanggal masuk efektif (cadangan TMT dari NIP). Sebelumnya
        // Carbon::parse(null) menghasilkan "sekarang" sehingga masa kerja tercetak 0.
        $tanggalMasuk = $this->tanggalMasukEfektif($pengajuan->pegawai) ?? Carbon::now();
        $masaKerja = $tanggalMasuk->diff(Carbon::now())->format('%y Tahun / %m Bulan');

        $tahunCuti = (int) date('Y', strtotime($pengajuan->tanggal_mulai));

        // Sisa cuti di PDF memakai helper bersama, sehingga angkanya
        // sama dengan Dashboard & halaman admin (bawaan eligible + koreksi Admin HR).
        $saldoInfo = $this->hitungSaldoCuti(
            (int) $pengajuan->pegawai_id,
            $tahunCuti,
            (int) ($pengajuan->pegawai->jatah_cuti ?? 12)
        );
        $sisaCutiAsli = $saldoInfo['total_cuti_tersedia'];

        $kasubag = Pegawai::where('role_id', 4)
            ->where(fn($q) => $q->where('status_aktif', 'Aktif')->orWhereNull('status_aktif'))
            ->orderBy('id')
            ->first();
        $kabiro = Pegawai::where('role_id', 6)
            ->where(fn($q) => $q->where('status_aktif', 'Aktif')->orWhereNull('status_aktif'))
            ->orderBy('id')
            ->first();

        $data = [
            'pengajuan' => $pengajuan,
            'pegawai' => $pengajuan->pegawai,
            'masaKerja' => $masaKerja,
            'sisaCuti' => (int) $sisaCutiAsli,
            'kasubag' => $kasubag,
            'kabiro' => $kabiro,
            'pemohonKabiro' => (int) $pengajuan->pegawai->role_id === 6,
            // Pemohon Kasubag (L3): rantai approval hanya L4, tanpa blok "Atasan Langsung".
            'pemohonKasubag' => (int) $pengajuan->pegawai->role_id === 4,
        ];

        $pdf = Pdf::loadView('pdf.surat-cuti', $data)->setPaper('A4', 'portrait');
        $namaFile = 'Surat_Izin_Cuti_' . $pengajuan->pegawai->nama . '_' . $pengajuan->tanggal_mulai . '.pdf';

        return $pdf->download($namaFile);
    }

    // 6. Karyawan Membatalkan Pengajuan Cuti Sendiri (Yang Masih Antrean)
    public function cancel(int $id)
    {
        /** @var \App\Models\Pegawai $user */
        $user = Auth::user();

        $pengajuan = PengajuanCuti::findOrFail($id);

        if ($pengajuan->pegawai_id !== $user->id) {
            abort(403, 'Anda tidak diizinkan membatalkan pengajuan ini.');
        }

        if (!in_array($pengajuan->status, ['menunggu_l1', 'menunggu_l2', 'menunggu_l3', 'menunggu_l4'])) {
            return back()->with('error', 'Cuti ini sudah tidak dapat dibatalkan.');
        }

        $pengajuan->update([
            'status' => 'dibatalkan_reguler'
        ]);

        return back()->with('success', 'Pengajuan cuti berhasil dibatalkan.');
    }

    // 7. Karyawan Membatalkan Cuti Mandiri (Yang Sudah Disetujui)
    public function batalkanMandiri(Request $request, int $id)
    {
        $request->validate([
            'alasan_pembatalan' => 'required|string|max:255',
        ], [
            'alasan_pembatalan.required' => 'Alasan pembatalan wajib diisi.',
        ]);

        /** @var \App\Models\Pegawai $user */
        $user = Auth::user();

        $cuti = PengajuanCuti::findOrFail($id);

        // [DIUBAH] Pemilik cuti + aturan tunggal bisaDibatalkanMandiri()
        // (disetujui DAN belum dimulai). Aturan yang sama dipakai history()
        // untuk menampilkan tombol, jadi tampilan & server selalu konsisten.
        // Cuti yang sudah dimulai atau sudah lewat tidak boleh dibatalkan
        // mandiri, supaya saldo cuti yang sudah terpakai tidak bisa
        // dipulihkan setelah kejadian. Sesuaikan aturan di helper bila
        // kebijakan instansi mengizinkan cuti yang sedang berjalan dipotong.
        if ($cuti->pegawai_id !== $user->id || !$this->bisaDibatalkanMandiri($cuti)) {
            return redirect()->back()->with(
                'error',
                'Cuti ini tidak dapat dibatalkan mandiri (belum disetujui, sudah dimulai, atau sudah lewat). Hubungi Admin HR bila perlu koreksi.'
            );
        }

        // Saldo tidak perlu dikembalikan manual karena sisa dihitung dinamis
        // dari cuti terpakai. Mengubah status menjadi 'dibatalkan_reguler'
        // otomatis memulihkan sisa cuti saat dihitung ulang.
        $cuti->status = 'dibatalkan_reguler';
        $cuti->keterangan = $cuti->keterangan . ' | Batal Mandiri: ' . $request->alasan_pembatalan;
        $cuti->save();

        // Pesan saldo hanya relevan untuk Cuti Tahunan (jenis lain tidak memotong saldo).
        $pesanSukses = $cuti->jenis_cuti === 'Cuti Tahunan'
            ? 'Cuti berhasil dibatalkan dan saldo telah dikembalikan.'
            : 'Cuti berhasil dibatalkan.';

        return redirect()->back()->with('success', $pesanSukses);
    }

    // 8. Karyawan Merevisi Cuti (Khusus Status Ditangguhkan)
    public function revisi(Request $request, int $id)
    {
        $cuti = PengajuanCuti::findOrFail($id);

        /** @var \App\Models\Pegawai $userLogin */
        $userLogin = Auth::user();

        if ((int) $cuti->pegawai_id !== (int) $userLogin->id) {
            abort(403, 'Anda tidak diizinkan merevisi pengajuan ini.');
        }

        $request->validate([
            'tanggal_mulai' => ['required', 'date', 'after_or_equal:today'],
            'tanggal_selesai' => ['required', 'date', 'after_or_equal:tanggal_mulai'],
        ], [
            'tanggal_mulai.required' => 'Tanggal mulai wajib diisi.',
            'tanggal_mulai.after_or_equal' => 'Tanggal mulai tidak boleh sebelum hari ini.',
            'tanggal_selesai.required' => 'Tanggal selesai wajib diisi.',
            'tanggal_selesai.after_or_equal' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',
        ]);

        $this->pastikanRentangWajar($request->tanggal_mulai, $request->tanggal_selesai, 'tanggal_mulai');

        if (
            (int) $cuti->pegawai_id !== (int) $userLogin->id
            || $cuti->status !== 'dibatalkan_ditangguhkan'
        ) {
            return redirect()->back()->with('error', 'Cuti ini tidak dapat direvisi.');
        }

        if (!$cuti->bisaDirevisi()) {
            return redirect()->back()->with('error', 'Cuti ini tidak dapat direvisi.');
        }

        if ($cuti->jenis_cuti === 'Cuti Besar') {
            $masaKerja = $this->hitungMasaKerjaTahun($userLogin);

            if ($masaKerja < self::MASA_KERJA_MINIMAL_CUTI_BESAR) {
                return redirect()->back()->with(
                    'error',
                    'Cuti Besar hanya dapat direvisi/diajukan ulang jika masa kerja sudah mencapai minimal '
                        . self::MASA_KERJA_MINIMAL_CUTI_BESAR . ' tahun.'
                );
            }
        }

        // ================= VALIDASI: SALDO TAHUN BELUM ADA (KHUSUS CUTI TAHUNAN) =================
        if ($cuti->jenis_cuti === 'Cuti Tahunan') {
            $tahunKosong = $this->tahunTanpaSaldo($userLogin->id, $request->tanggal_mulai, $request->tanggal_selesai);

            if ($tahunKosong !== null) {
                // withErrors (bukan with('error')) supaya Inertia memanggil
                // onError di frontend dan modal revisi TIDAK tertutup.
                return back()->withErrors([
                    'tanggal_mulai' => "Saldo cuti tahun {$tahunKosong} belum tersedia. Revisi baru bisa diajukan setelah Admin HR membuat saldonya.",
                ]);
            }
        }
        // ================= END VALIDASI SALDO TAHUN BELUM ADA =================

        $jumlah_hari = $this->hitungHariKerja($request->tanggal_mulai, $request->tanggal_selesai);

        if ($jumlah_hari === 0) {
            return back()->withErrors([
                'tanggal_mulai' => 'Tanggal yang dipilih jatuh pada hari libur sepenuhnya (akhir pekan dan/atau hari libur nasional/cuti bersama).',
            ]);
        }

        // ================= VALIDASI: REVISI BENTROK DENGAN PENGAJUAN LAIN =================
        // Pengajuan yang sedang direvisi dikecualikan supaya tidak bentrok
        // dengan dirinya sendiri. Posisi sebelum percabangan L4 juga mencakup
        // jalur revisi Kepala Biro.
        if ($bentrok = $this->pengajuanBentrok($userLogin->id, $request->tanggal_mulai, $request->tanggal_selesai, $cuti->id)) {
            return back()->withErrors([
                'tanggal_mulai' => $this->pesanBentrok($bentrok),
            ]);
        }
        // ================= END VALIDASI REVISI BENTROK =================

        // ================= [BARU] VALIDASI: SALDO UNTUK REVISI (SEMUA ROLE) =================
        // Sebelumnya hanya jalur Kepala Biro yang dicek saldonya, sehingga
        // pegawai lain bisa merevisi Cuti Tahunan ke rentang yang lebih
        // panjang dari sisa saldonya. Pengajuan yang sedang direvisi berstatus
        // 'dibatalkan_ditangguhkan', jadi tidak ikut terhitung sebagai
        // "menunggu" maupun "terpakai" dan saldonya sudah dikembalikan.
        if ($cuti->jenis_cuti === 'Cuti Tahunan') {
            $saldoInfo = $this->hitungSaldoCuti(
                $userLogin->id,
                Carbon::parse($request->tanggal_mulai)->year,
                (int) ($userLogin->jatah_cuti ?? 12)
            );

            if ($jumlah_hari > $saldoInfo['sisa_cuti']) {
                return back()->withErrors([
                    'tanggal_mulai' => "Revisi melebihi sisa saldo. Sisa yang tersedia: {$saldoInfo['sisa_cuti']} hari.",
                ]);
            }
        }
        // ================= END VALIDASI SALDO REVISI =================

        $levelAwal = $this->tentukanLevelAwalCuti($userLogin);

        // ================= L4: REVISI LANGSUNG DISETUJUI =================
        // Kepala Biro tidak punya atasan, jadi revisi tidak masuk antrean.
        // (Pengecekan saldo sudah dilakukan di atas untuk semua role.)
        if ((int) $userLogin->role_id === 6) {
            DB::transaction(function () use ($cuti, $request, $jumlah_hari, $userLogin) {
                $cuti->cutiDitangguhkan()->delete();
                $cuti->update([
                    'tanggal_mulai' => $request->tanggal_mulai,
                    'tanggal_selesai' => $request->tanggal_selesai,
                    'jumlah_hari' => $jumlah_hari,
                    'keterangan' => $cuti->keterangan . ' | [DIREVISI]',
                ]);

                $this->setujuiOtomatisKabiro($cuti, $userLogin);
            });

            return redirect()->back()->with('success', 'Tanggal cuti berhasil direvisi dan disetujui otomatis.');
        }
        // ================= END L4 REVISI =================

        DB::transaction(function () use ($cuti, $request, $jumlah_hari, $levelAwal, $userLogin) {
            $cuti->cutiDitangguhkan()->delete();
            $cuti->update([
                'tanggal_mulai' => $request->tanggal_mulai,
                'tanggal_selesai' => $request->tanggal_selesai,
                'jumlah_hari' => $jumlah_hari,
                'status' => $levelAwal['status'],
                'level_saat_ini' => $levelAwal['level'],
                'keterangan' => $cuti->keterangan . ' | [DIREVISI]',
            ]);

            $atasanTarget = $this->cariAtasanTujuan($userLogin, (int) $levelAwal['target_role']);

            if ($atasanTarget) {
                Notifikasi::create([
                    'pegawai_id' => $atasanTarget->id,
                    'judul'      => 'Revisi Pengajuan Cuti',
                    'pesan'      => 'Ada revisi tanggal cuti dari ' . $userLogin->nama . ' yang butuh persetujuan Anda.',
                    'tautan'     => route('atasan.approval'),
                    'is_read'    => false,
                ]);
            }
        });

        return redirect()->back()->with('success', 'Tanggal cuti berhasil direvisi dan diajukan ulang.');
    }
}
