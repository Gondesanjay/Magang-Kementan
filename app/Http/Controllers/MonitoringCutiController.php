<?php

namespace App\Http\Controllers;

use App\Models\PengajuanCuti;
use App\Models\SaldoCuti;
use App\Models\Notifikasi;
use App\Models\Pegawai;
use App\Models\ApprovalLog;
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
// Digabung dari dua varian MonitoringCutiController yang sempat berkembang
// terpisah, supaya kedua fitur berikut sama-sama jalan bersamaan:
//
//   A) buildFilterPropsUntukAtasan() — dropdown filter berjenjang yang
//      dipakai MonitoringCuti.vue (userTimKerja untuk badge L1, listTimKerja
//      untuk dropdown L2, listKelompok untuk dropdown L3/L4/HR).
//   B) atasan_l1_nama / atasan_l2_nama — nama ASLI atasan L1 (Ketua Tim
//      Kerja) & L2 (Ketua Kelompok Substansi) untuk tiap baris pengajuan,
//      dikirim ke Vue supaya Modal Detail Cuti bisa menampilkan nama
//      persis orangnya (lewat getLevelDisplayName() di Vue), bukan cuma
//      teks generik "Ketua Tim Kerja Pegawai" / "Ketua Kelompok Substansi".
//
// Semua fitur/perbaikan lain (transaksi DB + lockForUpdate di process(),
// perbaikan wording "Ditolak", validasi alasan penolakan kosong, N+1 fix,
// batasan wilayah L1 per Tim Kerja & L2 per Kelompok Substansi, dll) tetap
// dipertahankan seperti sebelumnya — TIDAK ada logika bisnis yang dihapus.
// ================= END CATATAN PENGGABUNGAN =================
class MonitoringCutiController extends Controller
{
    // ================= HELPER: DATA FILTER BERJENJANG UNTUK ATASAN =================
    // Pola sama dengan RekapKuotaDetailController::buildFilterPropsUntukAtasan()
    // supaya kedua halaman (Rekap Kuota & Monitoring/Approval Cuti) konsisten:
    //   L1 (role_id 2) : TANPA dropdown, badge "Menampilkan tim Anda: ..."
    //     (userTimKerja).
    //   L2 (role_id 3) : dropdown Tim Kerja, HANYA di dalam Kelompok
    //     Substansi miliknya sendiri (listTimKerja / opsi_tim_kerja).
    //   L3, L4, HR (role_id 4, 6, 5) : dropdown Kelompok/Subbagian, lintas
    //     kelompok (listKelompok).
    private function buildFilterPropsUntukAtasan($user): array
    {
        $listTimKerja = [];
        $listKelompok = [];

        if ($user->role_id === 3) {
            // L2 (Ketua Kelompok Substansi): daftar Tim Kerja HANYA di
            // dalam Kelompok Substansi miliknya sendiri.
            $listTimKerja = Pegawai::where('kelompok_substansi', $user->kelompok_substansi)
                ->whereNotNull('tim_kerja')
                ->where('tim_kerja', '!=', '-')
                ->distinct()
                ->orderBy('tim_kerja')
                ->pluck('tim_kerja')
                ->values();
        } elseif (in_array($user->role_id, [4, 6, 5], true)) {
            // L3, L4, & HR (Monitoring readonly): daftar SEMUA Kelompok
            // Substansi (lintas kelompok), sama seperti Rekap Kuota.
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
            // 'opsi_tim_kerja' dipertahankan sebagai nama prop yang sudah
            // dipakai MonitoringCuti.vue untuk dropdown Tim Kerja L2 —
            // diisi dari $listTimKerja supaya tidak perlu ganti nama prop.
            'opsi_tim_kerja' => $listTimKerja,
        ];
    }
    // ================= END HELPER FILTER BERJENJANG ATASAN =================

    // 1. Menampilkan Daftar Antrean — berfungsi sebagai Pemantau Seluruh
    //    Transaksi (bisa melihat semua aksi: menunggu, disetujui, ditolak,
    //    ditangguhkan, dll), dengan search & filter jenis cuti + status +
    //    kelompok/tim kerja.
    public function index(Request $request)
    {
        $user = Auth::user();
        // Relasi 'saldoCutiTahunIni' dipertahankan agar sisa cuti terbawa
        // ke frontend. Relasi 'approvalLogs.approver' supaya riwayat aksi
        // tiap atasan (level_approval, keputusan setuju/tolak, catatan,
        // nama approver) ikut terkirim ke Vue sebagai `approval_logs`.
        $query = PengajuanCuti::with(['pegawai.saldoCutiTahunIni', 'approvalLogs.approver']);

        // --- BATASAN WILAYAH WAJIB ---
        // L1 (role_id 2) dibatasi ke Tim Kerja miliknya sendiri — bukan
        // seluruh Departemen/Kelompok, karena satu Departemen/Kelompok bisa
        // berisi beberapa Tim Kerja dengan L1 masing-masing. L2 (role_id 3)
        // dibatasi ke Departemen/Kelompok miliknya sendiri, mencakup SEMUA
        // Tim Kerja & L1 di bawah kelompok itu.
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
        } elseif (!in_array($user->role_id, [4, 5, 6])) {
            // Jika bukan L1, L2, L3, L4, atau Admin HR -> blokir aksesnya
            $query->where('id', 0);
        }
        // L3 (Kasubag TU), L4 (Kepala Biro), dan Admin HR (role 5) tetap
        // bebas melihat skala biro/pusat (lintas departemen).

        // ================= FILTER OPSIONAL DARI DROPDOWN =================
        // Berbeda dari batasan wilayah WAJIB di atas (yang mengunci L1/L2
        // ke wilayahnya sendiri otomatis), filter ini murni respons dari
        // pilihan dropdown user di UI:
        //   - L2 (role_id 3): mempersempit ke Tim Kerja TERTENTU di dalam
        //     kelompoknya sendiri lewat ?tim_kerja=... (AND dengan batasan
        //     wajib di atas, jadi tidak mungkin keluar dari kelompoknya).
        //   - L3, L4, HR (role_id 4, 6, 5): mempersempit ke Kelompok
        //     Substansi tertentu lewat ?kelompok=... (karena mereka
        //     defaultnya lintas kelompok).
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
                // tangguh). Keterangan NULL ikut diloloskan — di SQL,
                // `NULL NOT LIKE '%x%'` bernilai NULL (bukan true).
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

        // Fitur pencarian nama / NIP pegawai (dibungkus satu grup supaya
        // tidak mungkin "bocor" dari batasan relasi pegawai)
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

        // ================= PENGURUTAN PRIORITAS =================
        // Status yang masih bisa di-approve oleh role yang login ditaruh
        // di atas. Sisanya diurutkan dari yang terbaru.
        $priorityStatus = match ($user->role_id) {
            2 => 'menunggu_l1', // Ketua Tim Kerja
            3 => 'menunggu_l2', // Ketua Kelompok Substansi
            4 => 'menunggu_l3', // Kasubag TU
            6 => 'menunggu_l4', // Kepala Biro
            default => null,
        };

        if ($priorityStatus) {
            $query->orderByRaw("CASE WHEN status = ? THEN 0 ELSE 1 END", [$priorityStatus]);
        }

        $antrean = $query->orderBy('created_at', 'desc')
            ->paginate(10)
            ->withQueryString();
        // ================= END PENGURUTAN PRIORITAS =================

        // Hitung sisa cuti dinamis (sama persis logika Dashboard) untuk
        // setiap item. Total cuti terpakai dihitung dengan SATU query untuk
        // seluruh pegawai di halaman ini (GROUP BY pegawai_id), bukan satu
        // query per baris (hindari N+1).
        $tahun = date('Y');

        $pegawaiIdsHalamanIni = $antrean->getCollection()
            ->pluck('pegawai_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $terpakaiPerPegawai = empty($pegawaiIdsHalamanIni)
            ? collect()
            : PengajuanCuti::selectRaw('pegawai_id, SUM(jumlah_hari) as total_terpakai')
            ->whereIn('pegawai_id', $pegawaiIdsHalamanIni)
            ->where('jenis_cuti', 'Cuti Tahunan')
            ->where('status', 'disetujui')
            ->whereYear('tanggal_mulai', $tahun)
            ->groupBy('pegawai_id')
            ->pluck('total_terpakai', 'pegawai_id');

        // ================= NAMA ASLI ATASAN L1 & L2 =================
        // Supaya Modal Detail Cuti di Vue bisa menampilkan nama PERSIS
        // orangnya (via getLevelDisplayName()) alih-alih teks generik
        // "Ketua Tim Kerja Pegawai" / "Ketua Kelompok Substansi", kita
        // bangun DUA PETA (bukan query per baris, supaya tidak menimbulkan
        // N+1 baru):
        //   $petaAtasanL2 : kelompok_substansi -> Pegawai Ketua Kelompok (role_id 3)
        //   $petaAtasanL1 : tim_kerja          -> Pegawai Ketua Tim Kerja (role_id 2)
        // dibangun HANYA dari nilai Kelompok/Tim Kerja unik yang benar-benar
        // muncul di 10 baris halaman ini.
        $kelompokUnikHalamanIni = $antrean->getCollection()
            ->pluck('pegawai.kelompok_substansi')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $timKerjaUnikHalamanIni = $antrean->getCollection()
            ->pluck('pegawai.tim_kerja')
            ->filter(fn($t) => $t && $t !== '-')
            ->unique()
            ->values()
            ->all();

        $petaAtasanL2 = empty($kelompokUnikHalamanIni)
            ? collect()
            : Pegawai::where('role_id', 3)
            ->whereIn('kelompok_substansi', $kelompokUnikHalamanIni)
            ->get()
            ->keyBy('kelompok_substansi');

        $petaAtasanL1 = empty($timKerjaUnikHalamanIni)
            ? collect()
            : Pegawai::where('role_id', 2)
            ->whereIn('tim_kerja', $timKerjaUnikHalamanIni)
            ->get()
            ->keyBy('tim_kerja');
        // ================= END NAMA ASLI ATASAN L1 & L2 =================

        $antrean->getCollection()->transform(function ($item) use ($terpakaiPerPegawai, $petaAtasanL1, $petaAtasanL2) {
            // Lewati kalau relasi pegawai tidak ada (mis. data pegawai terhapus)
            if (!$item->pegawai) {
                return $item;
            }

            $saldo = $item->pegawai->saldoCutiTahunIni ?? null;

            $kuotaTahunan  = $saldo->kuota_tahunan ?? 0;
            $sisaTahunLalu = $saldo->carry_forward_normal ?? 0;

            $cutiTerpakai = ($terpakaiPerPegawai[$item->pegawai_id] ?? 0) + 0;

            $totalTersedia = ($kuotaTahunan + $sisaTahunLalu) - $cutiTerpakai;

            // Tambahkan property baru ke object pegawai agar bisa dibaca di Vue
            $item->pegawai->sisa_cuti_tersedia = $totalTersedia;
            $item->pegawai->kuota_tahunan      = $kuotaTahunan;
            $item->pegawai->sisa_tahun_lalu    = $sisaTahunLalu;
            $item->pegawai->cuti_terpakai      = $cutiTerpakai;

            // Nama asli atasan L1 & L2 untuk baris ini (lihat blok komentar
            // "NAMA ASLI ATASAN L1 & L2" di atas). Kalau atasannya belum
            // ada di tabel Pegawai (mis. jabatan lowong), fallback ke teks
            // generik supaya tidak tampil kosong/null di Vue.
            $timKerjaPegawai = $item->pegawai->tim_kerja;
            $kelompokPegawai = $item->pegawai->kelompok_substansi;

            $atasanL1 = ($timKerjaPegawai && $timKerjaPegawai !== '-')
                ? ($petaAtasanL1[$timKerjaPegawai] ?? null)
                : null;
            $atasanL2 = $petaAtasanL2[$kelompokPegawai] ?? null;

            $item->atasan_l1_nama = $atasanL1->nama ?? 'Ketua Tim Kerja Pegawai';
            $item->atasan_l2_nama = $atasanL2->nama ?? 'Ketua Kelompok Substansi';

            return $item;
        });

        // Inertia::render diarahkan ke 'Atasan/MonitoringCuti' — pastikan
        // file resources/js/Pages/Atasan/MonitoringCuti.vue ada di project.
        // buildFilterPropsUntukAtasan() mengembalikan: userRoleId,
        // userTimKerja, listTimKerja, listKelompok, opsi_tim_kerja —
        // semuanya di-merge ke props Inertia tanpa mengubah struktur
        // 'antrean' & 'filters' yang sudah ada. 'kelompok' & 'tim_kerja'
        // ditambahkan ke filters supaya nilai dropdown yang sedang aktif
        // tetap ke-load ulang saat halaman di-refresh/paginasi.
        return Inertia::render('Atasan/MonitoringCuti', array_merge([
            'antrean' => $antrean,
            'filters' => $request->only(['search', 'jenis_cuti', 'status', 'kelompok', 'tim_kerja']),
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

            // L1 (role_id 2) dibatasi ke Tim Kerja miliknya sendiri, BUKAN
            // ke seluruh Departemen/Kelompok.
            if ($user->role_id === 2 && $pengajuan->pegawai->tim_kerja !== $user->tim_kerja) {
                abort(403, 'Pengajuan berada di luar tim kerja Anda.');
            }

            // L2 (role_id 3) dibatasi ke Departemen/Kelompok miliknya
            // sendiri — mencakup semua Tim Kerja & L1 di bawah kelompok
            // itu, sesuai wewenangnya sebagai Ketua Kelompok Substansi.
            if ($user->role_id === 3 && $pengajuan->pegawai->kelompok_substansi !== $user->kelompok_substansi) {
                abort(403, 'Pengajuan berada di luar kelompok substansi Anda.');
            }

            // $levelApproval = angka level (1-4) tempat pengajuan SEDANG
            // berada sebelum diproses, didapat dari status 'menunggu_lX'
            // yang SUDAH divalidasi lewat pengecekan $roleYangDibutuhkan di
            // atas — dipakai sebagai SATU-SATUNYA sumber kebenaran level,
            // baik untuk ApprovalLog maupun kolom level_saat_ini.
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

            // TAHAP 2: L2 MENGAPPROVE (Hanya terjadi jika staf tanpa Tim Kerja yang mengajukan cuti)
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

                $saldo = null;

                if ($this->isCutiTahunan($pengajuan->jenis_cuti)) {
                    // lockForUpdate: kunci baris saldo supaya pemotongan
                    // tidak bisa terjadi dua kali secara bersamaan.
                    $saldo = SaldoCuti::where('pegawai_id', $targetUserId)
                        ->where('tahun', date('Y', strtotime($pengajuan->tanggal_mulai)))
                        ->lockForUpdate()
                        ->first();

                    if (!$saldo || $saldo->sisa < $pengajuan->jumlah_hari) {
                        return back()->with('error', 'Saldo Cuti Tahunan tidak mencukupi untuk menyetujui pengajuan ini.');
                    }
                }

                $pengajuan->update([
                    'status' => 'disetujui',
                    'level_saat_ini' => 6,
                    'atasan_l4_id' => $user->id,
                ]);

                if ($saldo) {
                    $saldo->decrement('sisa', $pengajuan->jumlah_hari);
                }

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

        // Logika filter riwayat: Tampilkan data yang SUDAH melewati tahap/level atasan tersebut
        if ($user->role_id === 2) {
            // L1 dibatasi ke Tim Kerja miliknya sendiri, BUKAN seluruh
            // Departemen/Kelompok.
            $query->whereHas('pegawai', function ($q) use ($user) {
                $q->where('tim_kerja', $user->tim_kerja)
                    ->where('id', '!=', $user->id);
            })->whereNotIn('status', ['menunggu_l1']);
        } elseif ($user->role_id === 3) {
            // L2 dibatasi ke Departemen/Kelompok miliknya sendiri, mencakup
            // semua Tim Kerja & L1 di bawahnya.
            $query->whereHas('pegawai', function ($q) use ($user) {
                $q->where('kelompok_substansi', $user->kelompok_substansi)
                    ->where('id', '!=', $user->id);
            })->whereNotIn('status', ['menunggu_l1', 'menunggu_l2']);
        } elseif ($user->role_id === 4) {
            // L3: Menampilkan cuti yang sudah diproses L3 (lintas departemen, tidak dibatasi)
            $query->whereNotIn('status', ['menunggu_l1', 'menunggu_l2', 'menunggu_l3']);
        } elseif ($user->role_id === 6) { // L4 (ROLE 6)
            // L4: Hanya menampilkan cuti yang sudah final (lintas departemen, tidak dibatasi)
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
