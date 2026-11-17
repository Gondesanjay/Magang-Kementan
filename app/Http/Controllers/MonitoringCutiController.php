<?php

namespace App\Http\Controllers;

use App\Models\PengajuanCuti;
use App\Models\SaldoCuti;
use App\Models\Notifikasi;
use App\Models\Pegawai;
use App\Models\ApprovalLog;
use App\Support\RincianSaldo;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

// ================= PENTING: NAMA FILE & NAMA CLASS =================
// File ini WAJIB disimpan persis di:
//     app/Http/Controllers/MonitoringCutiController.php
// dengan nama class persis "MonitoringCutiController".
// ================= END CATATAN PENTING =================
//
// ================= FILE INI ADALAH HASIL PENGGABUNGAN 2 VERSI =================
//   A) buildFilterPropsUntukAtasan() — dropdown filter berjenjang untuk
//      MonitoringCuti.vue (userTimKerja, listTimKerja, listKelompok).
//   B) atasan_l1_nama / atasan_l2_nama — nama ASLI atasan L1 & L2 untuk
//      Modal Detail Cuti.
// Semua fitur lain (transaksi DB + lockForUpdate di process(), wording
// "Ditolak", validasi alasan penolakan kosong, N+1 fix, batasan wilayah
// L1 per Tim Kerja & L2 per Kelompok Substansi, dll) tetap dipertahankan.
// ================= END CATATAN PENGGABUNGAN =================
//
// ================= PERBAIKAN (SINKRONISASI RUMUS SALDO) =================
// Rumus saldo disamakan dengan DashboardController::hitungSaldoPegawai():
//   bawaan eligible = 12 jika sisa (tahun-2) & sisa (tahun-1) sama-sama 12,
//                     selain itu min(6, sisa tahun-1)
//   terpakai total  = digunakan (koreksi Admin HR bila ada) + Tgh tahun ini
//   dikembalikan    = Tgh (cuti ditangguhkan) tahun LALU
//   total tersedia  = kuota + bawaan eligible + dikembalikan - terpakai total
// ================= END PERBAIKAN SINKRONISASI RUMUS SALDO =================
//
// ================= PERBAIKAN BARU (CUTI LINTAS TAHUN) =================
//   - process(): saat L4 menyetujui, saldo dicek PER TAHUN (hari kerja di tiap
//                tahun vs total tersedia tahun itu). Kolom legacy `sisa`
//                dikurangi per tahun lewat SaldoCuti::sesuaikanSisaLegacy().
//                Setiap tahun yang disentuh harus punya baris saldo.
//   - process(): setelah saldo per tahun dikurangi, bawaan disinkronkan lewat
//                SaldoCuti::sinkronkanBawaanPengajuan().
// ================= END PERBAIKAN LINTAS TAHUN =================
//
// ================= PERBAIKAN BARU (FILTER TAHUN, DUA BAGIAN) =================
//   - index(): hasil dipecah jadi DUA daftar:
//       1) 'menunggu' : semua pengajuan berstatus menunggu_l1..l4 (sesuai
//          batasan wilayah & filter nama/kelompok/jenis/status). TIDAK ikut
//          filter tahun dan TIDAK dipaginasi, supaya antrean tidak terlewat.
//       2) 'antrean'  : riwayat yang sudah diproses (status selain menunggu),
//          HANYA yang rentang cutinya beririsan dengan tahun terpilih,
//          dipaginasi. Nama prop 'antrean' dipertahankan untuk pagination.
//   - Jendela tahun: 2 tahun ke belakang, 1 ke depan.
// ================= END PERBAIKAN FILTER TAHUN =================
//
// ================= PERBAIKAN BARU (RINCIAN SALDO PER PENGAJUAN) =================
//   - index(): "Rincian Sisa Cuti" di modal Detail kini dihitung PER PENGAJUAN
//     sesuai tahun cutinya lewat App\Support\RincianSaldo::lengkapi()
//     (sebelumnya semua pengajuan memakai saldo tahun berjalan/2026).
//     Blok perhitungan batch lama (terpakai/Tgh/dikembalikan) dipindahkan ke
//     RincianSaldo. hitungInfoSaldo() tetap dipakai oleh process().
// ================= END PERBAIKAN RINCIAN SALDO =================
//
// ================= PERBAIKAN BARU (BATASAN WILAYAH L3) =================
//   - bangunQueryDasar() & history(): L3 (Kasubag TU, role_id 4) tidak lagi
//     melihat pengajuan milik Kepala Biro (role_id 6), karena Kepala Biro
//     berada DI ATAS L3 dalam hierarki. Perubahan ini hanya menyembunyikan
//     tampilan; alur persetujuan (process()) tidak berubah.
// ================= END PERBAIKAN BATASAN WILAYAH L3 =================
class MonitoringCutiController extends Controller
{
    // Status yang dianggap "masih menunggu persetujuan".
    private const STATUS_MENUNGGU = ['menunggu_l1', 'menunggu_l2', 'menunggu_l3', 'menunggu_l4'];

    // Role ID Kepala Biro (L4 / approval tertinggi). Dipakai untuk menyaring
    // tampilan L3 supaya pengajuan atasannya tidak ikut muncul.
    private const ROLE_ID_KEPALA_BIRO = 6;

    // ================= HELPER BARU: RUMUS SALDO CUTI TAHUNAN =================
    // SATU-SATUNYA tempat rumus saldo di controller ini.
    // $terpakaiMurni = total hari Cuti Tahunan berstatus disetujui (pada tahun itu).
    // koreksi_saldo: NULL = ikut sistem; angka (termasuk 0) = override Admin HR.
    // $tangguhTahunIni = Tgh (cuti ditangguhkan) tahun ini -> ikut dihitung terpakai.
    // $dikembalikan    = Tgh tahun LALU -> ditambahkan ke total tersedia.
    // Dua parameter terakhir default 0 supaya pemanggil lama (process()) tetap jalan.
    private function hitungInfoSaldo(
        ?SaldoCuti $saldo,
        ?SaldoCuti $saldoTahunLalu,
        int $terpakaiMurni,
        int $tangguhTahunIni = 0,
        int $dikembalikan = 0
    ): array {
        $kuota            = (int) ($saldo->kuota_tahunan ?? 12);
        $sisaTahunLalu    = (int) ($saldo->carry_forward_normal ?? 0);
        $sisaDuaTahunLalu = (int) ($saldoTahunLalu->carry_forward_normal ?? 0);

        $koreksi  = $saldo?->koreksi_saldo;
        $terpakai = $koreksi !== null
            ? max(0, (int) $koreksi)
            : max(0, $terpakaiMurni);

        $terpakaiTotal = $terpakai + $tangguhTahunIni;

        $bawaanEligible = SaldoCuti::hitungBawaan(
            $sisaDuaTahunLalu,
            $sisaTahunLalu,
            $kuota
        );

        $sisaHakTahunIni = max($kuota - $terpakaiTotal, 0);
        $totalTersedia   = max(($kuota + $bawaanEligible + $dikembalikan) - $terpakaiTotal, 0);

        return [
            'kuota_tahunan'            => $kuota,
            'sisa_tahun_lalu'          => $sisaTahunLalu,
            'sisa_dua_tahun_lalu'      => $sisaDuaTahunLalu,
            'saldo_bawaan_eligible'    => $bawaanEligible,
            'cuti_terpakai'            => $terpakai,
            'cuti_terpakai_total'      => $terpakaiTotal,
            'cuti_ditangguhkan'        => $tangguhTahunIni,
            'cuti_dikembalikan'        => $dikembalikan,
            'sisa_hak_tahun_berjalan'  => $sisaHakTahunIni,
            'total_cuti_tersedia'      => $totalTersedia,
        ];
    }

    private function hitungTerpakaiMurni(int $pegawaiId, int $tahun): int
    {
        return SaldoCuti::terpakaiMurni($pegawaiId, $tahun);
    }
    // ================= END HELPER RUMUS SALDO =================

    // ================= HELPER: DATA FILTER BERJENJANG UNTUK ATASAN =================
    //   L1 (role_id 2) : TANPA dropdown, badge "Menampilkan tim Anda: ..."
    //   L2 (role_id 3) : dropdown Tim Kerja di dalam Kelompok Substansinya.
    //   L3, L4, HR (role_id 4, 6, 5) : dropdown Kelompok/Subbagian lintas kelompok.
    private function buildFilterPropsUntukAtasan(Pegawai $user): array
    {
        $listTimKerja = [];
        $listKelompok = [];

        if ($user->role_id === 3) {
            $listTimKerja = Pegawai::where('kelompok_substansi', $user->kelompok_substansi)
                ->whereNotNull('tim_kerja')
                ->where('tim_kerja', '!=', '-')
                ->distinct()
                ->orderBy('tim_kerja')
                ->pluck('tim_kerja')
                ->values();
        } elseif (in_array($user->role_id, [4, 6, 5], true)) {
            $listKelompok = Pegawai::where('role_id', '!=', 5)
                ->whereNotNull('kelompok_substansi')
                ->distinct()
                ->orderBy('kelompok_substansi')
                ->pluck('kelompok_substansi')
                ->values();
        }

        return [
            'userRoleId'   => $user->role_id,
            'userTimKerja' => $user->tim_kerja,
            'listTimKerja' => $listTimKerja,
            'listKelompok' => $listKelompok,
            // Nama prop yang sudah dipakai MonitoringCuti.vue untuk dropdown Tim Kerja L2.
            'opsi_tim_kerja' => $listTimKerja,
        ];
    }
    // ================= END HELPER FILTER BERJENJANG ATASAN =================

    // ================= FILTER TAHUN =================
    private const TAHUN_KE_BELAKANG = 2;
    private const TAHUN_KE_DEPAN    = 1;

    private function daftarTahunFilter(): array
    {
        $sekarang = (int) date('Y');

        return range($sekarang + self::TAHUN_KE_DEPAN, $sekarang - self::TAHUN_KE_BELAKANG);
    }

    // ?tahun= di luar jendela (mis. 2019) otomatis kembali ke tahun berjalan.
    private function tahunDipilih(Request $request): int
    {
        $tahun = (int) $request->input('tahun');

        return in_array($tahun, $this->daftarTahunFilter(), true) ? $tahun : (int) date('Y');
    }
    // ================= END FILTER TAHUN =================

    // ================= HELPER BARU: QUERY DASAR =================
    // Batasan wilayah + semua filter dari dropdown/search/jenis/status.
    // BELUM ada filter tahun dan BELUM dibedakan menunggu/riwayat; itu
    // ditambahkan oleh index() supaya dua daftar memakai aturan filter yang SAMA.
    private function bangunQueryDasar(Request $request, Pegawai $user)
    {
        $query = PengajuanCuti::with(['pegawai.saldoCutiTahunIni', 'approvalLogs.approver:id,nama,jabatan']);

        // --- BATASAN WILAYAH WAJIB ---
        // L1 dibatasi ke Tim Kerja miliknya; L2 ke Kelompok Substansi miliknya;
        // L3 tidak melihat pengajuan milik Kepala Biro (atasannya).
        if ($user->role_id === 2) {
            $query->whereHas('pegawai', function ($q) use ($user) {
                $q->where('tim_kerja', $user->tim_kerja)
                    ->where('id', '!=', $user->id);
            });
        } elseif ($user->role_id === 3) {
            $query->whereHas('pegawai', function ($q) use ($user) {
                $q->where('kelompok_substansi', $user->kelompok_substansi)
                    ->where('id', '!=', $user->id);
            });
        } elseif ($user->role_id === 4) {
            // L3 tidak melihat pengajuan milik jabatan di atasnya (Kepala Biro, role 6).
            $query->whereHas('pegawai', function ($q) {
                $q->where('role_id', '!=', self::ROLE_ID_KEPALA_BIRO);
            });
        } elseif (!in_array($user->role_id, [4, 5, 6])) {
            // Jika bukan L1, L2, L3, L4, atau Admin HR -> blokir aksesnya
            $query->where('id', 0);
        }
        // L4 dan Admin HR bebas melihat skala biro/pusat.

        // ================= FILTER OPSIONAL DARI DROPDOWN =================
        if ($user->role_id === 3 && $request->filled('tim_kerja')) {
            $query->whereHas('pegawai', function ($q) use ($request) {
                $q->where('tim_kerja', $request->tim_kerja);
            });
        }

        if (in_array($user->role_id, [4, 6, 5], true) && $request->filled('kelompok')) {
            $query->whereHas('pegawai', function ($q) use ($request) {
                $q->where('kelompok_substansi', $request->kelompok);
            });
        }
        // ================= END FILTER OPSIONAL DARI DROPDOWN =================

        // Fitur filter status dari dropdown Vue
        if ($request->filled('status') && $request->status !== '' && $request->status !== 'Semua Status') {
            $stVal = $request->status;
            if ($stVal === 'ditangguhkan') {
                // Syarat keterangan HANYA berlaku ketika status memang
                // 'ditolak' (dibungkus sub-closure sendiri), sehingga
                // status menunggu_l1/l2/l3/l4 tidak pernah ikut ke-match.
                $query->where(function ($sub) {
                    $sub->where('status', 'ditangguhkan')
                        ->orWhere('status', 'dibatalkan_ditangguhkan')
                        ->orWhere(function ($ditolakDitangguhkan) {
                            $ditolakDitangguhkan->where('status', 'ditolak')
                                ->where(function ($k) {
                                    $k->where('keterangan', 'like', '%Ditangguhkan%')
                                        ->orWhere('keterangan', 'like', '%ditangguhkan%');
                                });
                        });
                });
            } elseif ($stVal === 'ditolak') {
                // Hanya ambil yang ditolak murni (tidak mengandung kata
                // tangguh). Keterangan NULL ikut diloloskan.
                $query->where('status', 'ditolak')
                    ->where(function ($k) {
                        $k->whereNull('keterangan')
                            ->orWhere(function ($murni) {
                                $murni->where('keterangan', 'not like', '%Ditangguhkan%')
                                    ->where('keterangan', 'not like', '%ditangguhkan%');
                            });
                    });
            } else {
                $query->where('status', $stVal);
            }
        }

        // Fitur pencarian nama / NIP pegawai (satu grup supaya tidak "bocor" dari batasan relasi)
        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('pegawai', function ($q) use ($search) {
                $q->where(function ($cari) use ($search) {
                    $cari->where('nama', 'like', '%' . $search . '%')
                        ->orWhere('nip', 'like', '%' . $search . '%');
                });
            });
        }

        // Fitur filter jenis cuti yang aman dari string "Semua Jenis Cuti"
        if ($request->filled('jenis_cuti') && $request->jenis_cuti !== '' && $request->jenis_cuti !== 'Semua Jenis Cuti') {
            $query->where('jenis_cuti', $request->jenis_cuti);
        }

        return $query;
    }
    // ================= END HELPER QUERY DASAR =================

    // 1. Menampilkan Daftar Antrean — berfungsi sebagai Pemantau Seluruh
    //    Transaksi. Hasil dibagi dua:
    //      'menunggu' : antrean yang masih menunggu (selalu tampil, tanpa filter tahun)
    //      'antrean'  : riwayat diproses, difilter per tahun + paginasi
    public function index(Request $request)
    {
        $user = Auth::user();

        $tahunFilter    = $this->tahunDipilih($request);
        $awalTahun      = "{$tahunFilter}-01-01";
        $awalTahunDepan = ($tahunFilter + 1) . '-01-01';

        // ================= BAGIAN 1: MENUNGGU PERSETUJUAN =================
        // Tidak ikut filter tahun dan tidak dipaginasi, supaya tidak ada
        // pengajuan yang terlewat hanya karena tanggalnya di tahun lain.
        $queryMenunggu = $this->bangunQueryDasar($request, $user)
            ->whereIn('status', self::STATUS_MENUNGGU);

        // Status yang bisa di-approve oleh role yang login ditaruh paling atas.
        $priorityStatus = match ($user->role_id) {
            2 => 'menunggu_l1', // Ketua Tim Kerja
            3 => 'menunggu_l2', // Ketua Kelompok Substansi
            4 => 'menunggu_l3', // Kasubag TU
            6 => 'menunggu_l4', // Kepala Biro
            default => null,
        };

        if ($priorityStatus) {
            $queryMenunggu->orderByRaw("CASE WHEN status = ? THEN 0 ELSE 1 END", [$priorityStatus]);
        }

        $menunggu = $queryMenunggu
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        // ================= BAGIAN 2: RIWAYAT DIPROSES (FILTER TAHUN) =================
        // Status selain menunggu, rentang cuti beririsan dengan tahun terpilih.
        // Perbandingan langsung (tanpa whereDate) supaya aman untuk kolom date
        // maupun datetime. Urutan ditambah id supaya pagination stabil.
        $antrean = $this->bangunQueryDasar($request, $user)
            ->whereNotIn('status', self::STATUS_MENUNGGU)
            ->where('tanggal_mulai', '<', $awalTahunDepan)
            ->where('tanggal_selesai', '>=', $awalTahun)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate(10)
            ->withQueryString();

        $semuaItem = $menunggu->concat($antrean->getCollection());

        // Rincian Sisa Cuti per pengajuan, sesuai tahun cutinya.
        RincianSaldo::lengkapi($menunggu, $tahunFilter);
        RincianSaldo::lengkapi($antrean->getCollection(), $tahunFilter);

        // ================= NAMA ASLI ATASAN L1 & L2 =================
        // Dua PETA (bukan query per baris, supaya tidak N+1):
        //   $petaAtasanL2 : kelompok_substansi -> Ketua Kelompok (role_id 3)
        //   $petaAtasanL1 : tim_kerja          -> Ketua Tim Kerja (role_id 2)
        $kelompokUnik = $semuaItem
            ->pluck('pegawai.kelompok_substansi')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $timKerjaUnik = $semuaItem
            ->pluck('pegawai.tim_kerja')
            ->filter(fn($t) => $t && $t !== '-')
            ->unique()
            ->values()
            ->all();

        $petaAtasanL2 = empty($kelompokUnik)
            ? collect()
            : Pegawai::where('role_id', 3)
            ->whereIn('kelompok_substansi', $kelompokUnik)
            ->get()
            ->keyBy('kelompok_substansi');

        $petaAtasanL1 = empty($timKerjaUnik)
            ? collect()
            : Pegawai::where('role_id', 2)
            ->whereIn('tim_kerja', $timKerjaUnik)
            ->get()
            ->keyBy('tim_kerja');

        $namaPejabatAktif = fn(int $roleId) => Pegawai::where('role_id', $roleId)
            ->where(fn($q) => $q->where('status_aktif', 'Aktif')->orWhereNull('status_aktif'))
            ->orderBy('id')
            ->value('nama');

        $namaAtasanL3 = $namaPejabatAktif(4) ?? 'Kasubag TU';
        $namaAtasanL4 = $namaPejabatAktif(6) ?? 'Kepala Biro Perencanaan';
        // ================= END NAMA ASLI ATASAN L1 & L2 =================

        // Satu closure yang sama dipakai untuk KEDUA daftar.
        $perkaya = function ($item) use ($petaAtasanL1, $petaAtasanL2, $namaAtasanL3, $namaAtasanL4) {
            // Lewati kalau relasi pegawai tidak ada (mis. data pegawai terhapus)
            if (!$item->pegawai) {
                return $item;
            }

            // Nama asli atasan L1 & L2; fallback ke teks generik kalau jabatan lowong.
            $tim      = $item->pegawai->tim_kerja;
            $kelompok = $item->pegawai->kelompok_substansi;

            $atasanL1 = ($tim && $tim !== '-') ? ($petaAtasanL1[$tim] ?? null) : null;
            $atasanL2 = $kelompok ? ($petaAtasanL2[$kelompok] ?? null) : null;

            $item->atasan_l1_nama = $atasanL1->nama ?? 'Ketua Tim Kerja Pegawai';
            $item->atasan_l2_nama = $atasanL2->nama ?? 'Ketua Kelompok Substansi';
            $item->atasan_l3_nama = $namaAtasanL3;
            $item->atasan_l4_nama = $namaAtasanL4;

            return $item;
        };

        $menunggu->transform($perkaya);
        $antrean->getCollection()->transform($perkaya);

        // 'kelompok' & 'tim_kerja' ada di filters supaya nilai dropdown yang
        // sedang aktif tetap ke-load ulang saat refresh/paginasi.
        return Inertia::render('Atasan/MonitoringCuti', array_merge([
            'menunggu'     => $menunggu,
            'antrean'      => $antrean,
            'filters'      => $request->only(['search', 'jenis_cuti', 'status', 'kelompok', 'tim_kerja']),
            'daftarTahun'  => $this->daftarTahunFilter(),
            'tahunDipilih' => $tahunFilter,
        ], $this->buildFilterPropsUntukAtasan($user)));
    }

    private function isCutiTahunan(?string $jenisCuti): bool
    {
        if ($jenisCuti === null || $jenisCuti === '') {
            return true;
        }

        return strtolower(trim((string) $jenisCuti)) === 'cuti tahunan';
    }

    // 2. Memproses Persetujuan atau Penolakan
    //
    // Seluruh proses dibungkus DB::transaction + lockForUpdate(). Kalau ada
    // langkah yang gagal, semua perubahan dibatalkan (rollback); abort(403)
    // di dalam transaksi juga otomatis me-rollback.
    //
    // CATATAN: redirect memakai back(), jadi query string halaman (tahun, page,
    // filter) ikut terbawa lewat header Referer. Tidak perlu diubah.
    public function process(Request $request, int $id)
    {
        $request->validate([
            'action' => ['required', 'in:approve,reject'],
            'alasan' => ['nullable', 'string', 'max:500'],
        ]);

        $user = Auth::user();

        return DB::transaction(function () use ($request, $id, $user) {
            // lockForUpdate: kunci baris pengajuan supaya dua klik bersamaan
            // tidak bisa memproses pengajuan yang sama dua kali.
            $pengajuan = PengajuanCuti::with('pegawai')->lockForUpdate()->findOrFail($id);
            $targetUserId = $pengajuan->pegawai_id ?? $pengajuan->user_id;
            $statusSaatIni = $pengajuan->status;
            $roleYangDibutuhkan = [
                'menunggu_l1' => 2,
                'menunggu_l2' => 3,
                'menunggu_l3' => 4,
                'menunggu_l4' => 6,
            ][$statusSaatIni] ?? null;

            if ($roleYangDibutuhkan === null || $user->role_id !== $roleYangDibutuhkan) {
                abort(403, 'Anda tidak berwenang memproses pengajuan pada tahap ini.');
            }

            // L1 dibatasi ke Tim Kerja miliknya sendiri.
            if ($user->role_id === 2 && $pengajuan->pegawai->tim_kerja !== $user->tim_kerja) {
                abort(403, 'Pengajuan berada di luar tim kerja Anda.');
            }

            // L2 dibatasi ke Kelompok Substansi miliknya (semua Tim Kerja & L1 di bawahnya).
            if ($user->role_id === 3 && $pengajuan->pegawai->kelompok_substansi !== $user->kelompok_substansi) {
                abort(403, 'Pengajuan berada di luar kelompok substansi Anda.');
            }

            // $levelApproval = level (1-4) tempat pengajuan SEDANG berada sebelum
            // diproses; SATU-SATUNYA sumber kebenaran level untuk ApprovalLog & level_saat_ini.
            $levelApproval = [
                'menunggu_l1' => 1,
                'menunggu_l2' => 2,
                'menunggu_l3' => 3,
                'menunggu_l4' => 4,
            ][$statusSaatIni];

            // --- JIKA DITOLAK ---
            if ($request->action === 'reject') {
                $alasanPenolakan = trim((string) $request->input('alasan', ''));
                if ($alasanPenolakan === '') {
                    $alasanPenolakan = 'Tidak disetujui';
                }
                $namaApprover = $user->nama ?? 'Atasan';

                $alasanUtama = trim(explode('|', (string) $pengajuan->keterangan)[0]);
                if (empty($alasanUtama) || $alasanUtama === '-') {
                    $alasanUtama = 'Tidak ada alasan awal';
                }

                $keteranganBaru = "{$alasanUtama} (Ditolak oleh {$namaApprover}: {$alasanPenolakan} — Pengajuan Cuti Ulang)";

                $pengajuan->update([
                    'status' => 'ditolak',
                    'keterangan' => $keteranganBaru,
                    'level_saat_ini' => (int) $levelApproval,
                ]);

                ApprovalLog::create([
                    'pengajuan_id' => $pengajuan->id,
                    'approver_id' => $user->id,
                    'level_approval' => $levelApproval,
                    'keputusan' => 'tolak',
                    'catatan' => $alasanPenolakan,
                    'tanggal_keputusan' => now(),
                ]);

                Notifikasi::create([
                    'pegawai_id' => $targetUserId,
                    'judul'      => 'Pengajuan Cuti Ditolak',
                    'pesan'      => 'Pengajuan Anda ditolak oleh ' . $namaApprover . '. Alasan: ' . $alasanPenolakan,
                    'tautan'     => route('karyawan.riwayat'),
                    'is_read'    => false,
                ]);

                return back()->with('success', 'Pengajuan cuti berhasil ditolak.');
            }

            // --- JIKA DISETUJUI (LOGIKA ALUR BARU KEMENTAN) ---
            $statusPesan = '';

            // TAHAP 1: L1 MENGAPPROVE (Otomatis lompat L2, langsung ke L3)
            if ($statusSaatIni === 'menunggu_l1' && $user->role_id === 2) {
                Notifikasi::where('pegawai_id', $user->id)->where('judul', 'Pengajuan Cuti Baru')->delete();

                $pengajuan->update(['status' => 'menunggu_l3', 'level_saat_ini' => 3, 'atasan_l1_id' => $user->id]);

                $atasanL3 = Pegawai::where('role_id', 4)->where('kelompok_substansi', $pengajuan->pegawai->kelompok_substansi)->first()
                    ?? Pegawai::where('role_id', 4)->first();

                if ($atasanL3) {
                    Notifikasi::create([
                        'pegawai_id' => $atasanL3->id,
                        'judul'      => 'Pengajuan Cuti Baru',
                        'pesan'      => 'Ada pengajuan cuti dari ' . $pengajuan->pegawai->nama . ' yang butuh persetujuan Anda.',
                        'tautan'     => route('atasan.approval'),
                        'is_read'    => false,
                    ]);
                }
                $statusPesan = 'Disetujui Ketua Tim Kerja (L1) - Menunggu Kasubag TU (L3)';
            }

            // TAHAP 2: L2 MENGAPPROVE (Hanya jika staf tanpa Tim Kerja yang mengajukan cuti)
            elseif ($statusSaatIni === 'menunggu_l2' && $user->role_id === 3) {
                Notifikasi::where('pegawai_id', $user->id)->where('judul', 'Pengajuan Cuti Baru')->delete();

                $pengajuan->update(['status' => 'menunggu_l3', 'level_saat_ini' => 3]);

                $atasanL3 = Pegawai::where('role_id', 4)->where('kelompok_substansi', $pengajuan->pegawai->kelompok_substansi)->first()
                    ?? Pegawai::where('role_id', 4)->first();

                if ($atasanL3) {
                    Notifikasi::create([
                        'pegawai_id' => $atasanL3->id,
                        'judul'      => 'Pengajuan Cuti Baru',
                        'pesan'      => 'Ada pengajuan cuti dari ' . $pengajuan->pegawai->nama . ' yang butuh persetujuan Anda.',
                        'tautan'     => route('atasan.approval'),
                        'is_read'    => false,
                    ]);
                }
                $statusPesan = 'Disetujui Ketua Kelompok Substansi (L2) - Menunggu Kasubag TU (L3)';
            }

            // TAHAP 3: L3 MENGAPPROVE (Diteruskan ke L4)
            elseif ($statusSaatIni === 'menunggu_l3' && $user->role_id === 4) {
                Notifikasi::where('pegawai_id', $user->id)->where('judul', 'Pengajuan Cuti Baru')->delete();

                $pengajuan->update(['status' => 'menunggu_l4', 'level_saat_ini' => 4, 'atasan_l3_id' => $user->id]);

                $atasanL4 = Pegawai::where('role_id', 6)->where('kelompok_substansi', $pengajuan->pegawai->kelompok_substansi)->first()
                    ?? Pegawai::where('role_id', 6)->first();

                if ($atasanL4) {
                    Notifikasi::create([
                        'pegawai_id' => $atasanL4->id,
                        'judul'      => 'Pengajuan Cuti Baru',
                        'pesan'      => 'Ada pengajuan cuti dari ' . $pengajuan->pegawai->nama . ' yang butuh persetujuan Anda.',
                        'tautan'     => route('atasan.approval'),
                        'is_read'    => false,
                    ]);
                }
                $statusPesan = 'Disetujui Kasubag TU (L3) - Menunggu Kepala Biro Perencanaan (L4)';
            }

            // TAHAP 4: L4 MENGAPPROVE (FINAL)
            elseif ($statusSaatIni === 'menunggu_l4' && $user->role_id === 6) { // KABIRO = ROLE 6
                Notifikasi::where('pegawai_id', $user->id)->where('judul', 'Pengajuan Cuti Baru')->delete();

                $adalahCutiTahunan = $this->isCutiTahunan($pengajuan->jenis_cuti);

                if ($adalahCutiTahunan) {
                    // Cek saldo PER TAHUN. Cuti lintas tahun (mis. 29 Des -
                    // 4 Jan) dicek terhadap saldo 2026 untuk hari kerja Desember dan
                    // saldo 2027 untuk hari kerja Januari. Rumus saldo sama dengan
                    // Dashboard & Rekap Kuota. Pengajuan ini masih berstatus
                    // 'menunggu_l4', jadi belum ikut terhitung sebagai terpakai.
                    $rincianPerTahun = SaldoCuti::hariPerTahunDariPengajuan(
                        $pengajuan->tanggal_mulai,
                        $pengajuan->tanggal_selesai,
                        (int) $pengajuan->jumlah_hari
                    );
                    $lintasTahun = count($rincianPerTahun) > 1;

                    foreach ($rincianPerTahun as $tahunCuti => $hari) {
                        if ($hari <= 0) {
                            continue;
                        }

                        // lockForUpdate: kunci baris saldo supaya pemotongan tidak
                        // bisa terjadi dua kali secara bersamaan.
                        $saldoTahun = SaldoCuti::where('pegawai_id', $targetUserId)
                            ->where('tahun', $tahunCuti)
                            ->lockForUpdate()
                            ->first();

                        if (!$saldoTahun) {
                            return back()->with(
                                'error',
                                $lintasTahun
                                    ? "Saldo Cuti Tahunan tahun {$tahunCuti} belum tersedia, pengajuan ini belum dapat disetujui."
                                    : 'Saldo Cuti Tahunan tidak mencukupi untuk menyetujui pengajuan ini.'
                            );
                        }

                        $infoSaldo = $this->hitungInfoSaldo(
                            $saldoTahun,
                            SaldoCuti::where('pegawai_id', $targetUserId)->where('tahun', $tahunCuti - 1)->first(),
                            $this->hitungTerpakaiMurni((int) $targetUserId, (int) $tahunCuti)
                        );

                        if ($infoSaldo['total_cuti_tersedia'] < $hari) {
                            return back()->with(
                                'error',
                                $lintasTahun
                                    ? "Saldo Cuti Tahunan tahun {$tahunCuti} tidak mencukupi: dibutuhkan {$hari} hari, tersedia {$infoSaldo['total_cuti_tersedia']} hari."
                                    : 'Saldo Cuti Tahunan tidak mencukupi untuk menyetujui pengajuan ini.'
                            );
                        }
                    }
                }

                $pengajuan->update([
                    'status' => 'disetujui',
                    'level_saat_ini' => 6,
                    'atasan_l4_id' => $user->id,
                ]);

                // Kolom legacy `sisa` tetap dikurangi, kini PER TAHUN, dan tidak
                // pernah di bawah 0 (saldo bawaan bisa membuat pemakaian melebihi
                // hak tahun berjalan).
                if ($adalahCutiTahunan) {
                    SaldoCuti::sesuaikanSisaLegacy($pengajuan, -1);
                }

                // Sinkronkan bawaan setelah status 'disetujui' tersimpan dan
                // saldo per tahun sudah dikurangi.
                SaldoCuti::sinkronkanBawaanPengajuan($pengajuan);

                $statusPesan = 'Disetujui Kepala Biro Perencanaan (L4)';
            }

            if (!empty($statusPesan)) {
                ApprovalLog::create([
                    'pengajuan_id' => $pengajuan->id,
                    'approver_id' => $user->id,
                    'level_approval' => $levelApproval,
                    'keputusan' => 'setuju',
                    'catatan' => null,
                    'tanggal_keputusan' => now(),
                ]);

                Notifikasi::create([
                    'pegawai_id' => $targetUserId,
                    'judul'      => 'Status Cuti Diperbarui',
                    'pesan'      => $statusPesan,
                    'tautan'     => route('karyawan.riwayat'),
                    'is_read'    => false,
                ]);
            }

            return back()->with('success', 'Pengajuan cuti berhasil diproses dan diteruskan.');
        });
    }

    public function approve(Request $request, int $id)
    {
        $request->merge(['action' => 'approve']);
        return $this->process($request, $id);
    }

    public function reject(Request $request, int $id)
    {
        $request->merge(['action' => 'reject']);
        return $this->process($request, $id);
    }

    // 3. Menampilkan Riwayat Approval
    public function history(Request $request)
    {
        $user = Auth::user();
        $query = PengajuanCuti::with(['pegawai.saldoCutiTahunIni']);

        // Tampilkan data yang SUDAH melewati tahap/level atasan tersebut
        if ($user->role_id === 2) {
            // L1 dibatasi ke Tim Kerja miliknya sendiri.
            $query->whereHas('pegawai', function ($q) use ($user) {
                $q->where('tim_kerja', $user->tim_kerja)
                    ->where('id', '!=', $user->id);
            })->whereNotIn('status', ['menunggu_l1']);
        } elseif ($user->role_id === 3) {
            // L2 dibatasi ke Kelompok Substansi miliknya.
            $query->whereHas('pegawai', function ($q) use ($user) {
                $q->where('kelompok_substansi', $user->kelompok_substansi)
                    ->where('id', '!=', $user->id);
            })->whereNotIn('status', ['menunggu_l1', 'menunggu_l2']);
        } elseif ($user->role_id === 4) {
            // L3: cuti yang sudah diproses L3 (lintas departemen), kecuali milik
            // Kepala Biro yang berada di atas L3.
            $query->whereHas('pegawai', function ($q) {
                $q->where('role_id', '!=', self::ROLE_ID_KEPALA_BIRO);
            })->whereNotIn('status', ['menunggu_l1', 'menunggu_l2', 'menunggu_l3']);
        } elseif ($user->role_id === 6) { // L4 (ROLE 6)
            // L4: hanya cuti yang sudah final (lintas departemen, tidak dibatasi)
            $query->whereIn('status', ['disetujui', 'ditolak', 'dibatalkan_reguler', 'dibatalkan_ditangguhkan']);
        } else {
            $query->where('id', 0);
        }

        // Fitur pencarian nama pegawai
        if ($request->filled('search')) {
            $query->whereHas('pegawai', function ($q) use ($request) {
                $q->where('nama', 'like', '%' . $request->search . '%');
            });
        }

        $riwayat = $query->orderBy('updated_at', 'desc')->paginate(10)->withQueryString();

        return Inertia::render('Atasan/RiwayatApproval', [
            'riwayat' => $riwayat,
            'filters' => $request->only(['search'])
        ]);
    }
}
