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


// ================= PENTING: NAMA FILE & NAMA CLASS =================
// File ini WAJIB disimpan persis di:
//     app/Http/Controllers/MonitoringCutiController.php
// dengan nama class persis "MonitoringCutiController" (sama seperti di
// bawah ini). Laravel memakai autoload PSR-4: nama file HARUS sama persis
// dengan nama class di dalamnya.
// ================= END CATATAN PENTING =================
//
// ================= RIWAYAT PERBAIKAN YANG SUDAH DIGABUNG DI FILE INI =================
// 1. SINKRONISASI WORDING "DITOLAK": saat Atasan menekan tombol Tolak, teks
//    keterangan sekarang konsisten memakai kata "Ditolak" (bukan lagi
//    "Ditangguhkan") supaya sinkron dengan kolom status yang memang di-set
//    'ditolak'.
// 2. SUMBER KEBENARAN LEVEL PENOLAK: kolom `level_saat_ini` SEKARANG SELALU
//    diisi dengan angka level yang benar-benar menolak (1-4), diambil dari
//    $levelApproval yang sudah tervalidasi lebih dulu lewat pengecekan
//    $roleYangDibutuhkan di awal method process().
// 3. KONFIRMASI LOGIKA POTONG SALDO CUTI TAHUNAN: pemotongan saldo (kolom
//    `sisa` di tabel SaldoCuti) HANYA terjadi satu kali, yaitu tepat di
//    TAHAP 4 (approval final oleh L4/Kabiro, role_id 6).
//
// ================= PERBAIKAN TERBARU (Granularitas Batasan Wilayah L1 vs L2) =================
// Struktur organisasi yang benar (dari data spreadsheet pegawai):
//   Departemen/Kelompok (kolom `departemen`, mis. "KELOMPOK KEBIJAKAN
//   PEMBANGUNAN PERTANIAN") -> bisa berisi BEBERAPA Tim Kerja (kolom
//   `divisi`, berlabel "Tim Kerja" di form Kelola Pegawai, mis. "TIM KERJA
//   KEBIJAKAN PERTANIAN", "TIM KERJA ANALISIS DATA", dst), masing-masing
//   Tim Kerja punya L1 (Ketua Tim Kerja) SENDIRI, sedangkan satu
//   Departemen/Kelompok hanya punya SATU L2 (Ketua Kelompok Substansi)
//   yang membawahi semua Tim Kerja di dalamnya.
//
// SEBELUMNYA: L1 (role_id 2) dibatasi berdasarkan `departemen` — ini
// SALAH GRANULARITAS, karena artinya L1 dari Tim Kerja A bisa melihat staf
// dari Tim Kerja B selama masih satu Departemen/Kelompok yang sama.
//
// PERBAIKAN: L1 (role_id 2) SEKARANG dibatasi berdasarkan `divisi` (Tim
// Kerja) miliknya sendiri — hanya melihat/memproses staf yang SATU TIM
// KERJA dengannya. L2 (role_id 3) TETAP dibatasi berdasarkan `departemen`
// seperti perbaikan sebelumnya — L2 memang berwenang melihat SELURUH staf
// dan SELURUH L1 yang berada dalam Departemen/Kelompok yang sama dengannya
// (karena `departemen` mereka semua sama, terlepas dari `divisi`/Tim Kerja
// masing-masing). L3 (role_id 4), L4 (role_id 6), dan Admin HR (role_id 5)
// TIDAK berubah — tetap bebas lintas departemen.
//
// Perbaikan granularitas ini diterapkan konsisten di tiga tempat:
//   1. index()   — daftar "Monitoring Transaksi Cuti"
//   2. process() — validasi wewenang approve/reject
//   3. history() — riwayat approval
// ================= END PERBAIKAN =================
class MonitoringCutiController extends Controller
{
    // 1. Menampilkan Daftar Antrean — sekarang berfungsi sebagai Pemantau
    //    Seluruh Transaksi (bisa melihat semua aksi: menunggu, disetujui,
    //    ditolak, ditangguhkan, dll), dengan search & filter jenis cuti + status.
    public function index(Request $request)
    {
        $user = Auth::user();
        // Relasi 'saldoCutiTahunIni' tetap dipertahankan agar sisa cuti terbawa ke frontend
        $query = PengajuanCuti::with(['pegawai.saldoCutiTahunIni']);


        // --- BATASAN WILAYAH (bukan lagi batasan status) ---
        // Kunci hardcode status 'menunggu_lX' per role sudah DIHAPUS supaya
        // halaman ini bisa menampilkan semua aksi transaksi, bukan cuma yang
        // masih menunggu. Yang tersisa hanya batasan wilayah/akses:
        //
        // PERBAIKAN GRANULARITAS: L1 (role_id 2) dibatasi ke Tim Kerja
        // (`divisi`) miliknya sendiri — bukan seluruh Departemen/Kelompok,
        // karena satu Departemen/Kelompok bisa berisi beberapa Tim Kerja
        // dengan L1 masing-masing. L2 (role_id 3) dibatasi ke
        // Departemen/Kelompok (`departemen`) miliknya sendiri, mencakup
        // SEMUA Tim Kerja & L1 di bawah kelompok itu.
        if ($user->role_id === 2) {
            // L1 (Ketua Tim Kerja): hanya bisa melihat pengajuan dari staf
            // yang berada di Tim Kerja (`divisi`) yang sama dengannya.
            $query->whereHas('pegawai', function ($q) use ($user) {
                $q->where('divisi', $user->divisi);
            });
        } elseif ($user->role_id === 3) {
            // L2 (Ketua Kelompok Substansi): melihat SEMUA staf & L1 dalam
            // Departemen/Kelompok (`departemen`) yang sama dengannya,
            // mencakup seluruh Tim Kerja di bawah kelompok itu.
            $query->whereHas('pegawai', function ($q) use ($user) {
                $q->where('departemen', $user->departemen);
            });
        } elseif (!in_array($user->role_id, [4, 5, 6])) {
            // Jika bukan L1, L2, L3, L4, atau Admin HR -> blokir aksesnya
            $query->where('id', 0);
        }
        // Catatan: L3 (Kasubag TU), L4 (Kepala Biro), dan Admin HR (role 5)
        // tetap bebas melihat skala biro/pusat (lintas departemen), karena
        // posisi mereka memang di puncak struktur Biro.


        // Fitur filter status dari dropdown Vue yang disempurnakan
        // PERBAIKAN LANJUTAN: filter jenis_cuti dan status kini eksplisit
        // mengabaikan string placeholder "Semua Jenis Cuti"/"Semua Status"
        // maupun string kosong, supaya tidak pernah mencari baris dengan
        // nilai kolom yang memang tidak ada di database (yang membuat
        // tabel tampak kosong padahal datanya ada).
        if ($request->filled('status') && $request->status !== '' && $request->status !== 'Semua Status') {
            $stVal = $request->status;
            if ($stVal === 'ditangguhkan') {
                // PERBAIKAN: sebelumnya orWhere('keterangan', 'like', '%ditangguhkan%')
                // berdiri sendiri di level teratas, sehingga baris yang statusnya
                // MASIH 'menunggu_lX' tapi kebetulan keterangannya membawa kata
                // "ditangguhkan" (mis. dari teks pengajuan cuti ulang) ikut lolos
                // dan tampil sebagai "Menunggu ..." padahal filter yang dipilih
                // adalah "Ditangguhkan". Sekarang syarat keterangan HANYA berlaku
                // ketika status memang 'ditolak' (dibungkus sub-closure sendiri),
                // sehingga status menunggu_l1/l2/l3/l4 tidak pernah ikut ke-match.
                //
                // CATATAN TAMBAHAN setelah perbaikan sinkronisasi status "Ditolak":
                // Karena teks keterangan penolakan sekarang memakai kata "Ditolak"
                // (bukan lagi "Ditangguhkan"), sub-closure pencarian keyword
                // '%Ditangguhkan%'/'%ditangguhkan%' pada status 'ditolak' ini
                // secara alami tidak akan match lagi untuk pengajuan BARU yang
                // ditolak — filter "Ditangguhkan" jadi murni mengacu ke status asli
                // 'ditangguhkan'/'dibatalkan_ditangguhkan'. Baris lama (sebelum
                // perbaikan ini) yang keterangannya masih memuat kata lama tetap
                // ikut match, supaya data historis tidak "hilang" dari pencarian.
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
                // Hanya ambil yang ditolak murni (tidak mengandung kata tangguh)
                $query->where('status', 'ditolak')
                    ->where('keterangan', 'not like', '%Ditangguhkan%')
                    ->where('keterangan', 'not like', '%ditangguhkan%');
            } else {
                $query->where('status', $stVal);
            }
        }


        // Fitur pencarian nama / NIP pegawai
        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('pegawai', function ($q) use ($search) {
                $q->where('nama', 'like', '%' . $search . '%')
                    ->orWhere('nip', 'like', '%' . $search . '%');
            });
        }


        // Fitur filter jenis cuti yang aman dari string "Semua Jenis Cuti"
        if ($request->filled('jenis_cuti') && $request->jenis_cuti !== '' && $request->jenis_cuti !== 'Semua Jenis Cuti') {
            $query->where('jenis_cuti', $request->jenis_cuti);
        }


        // Pengurutan data terbaru di paling atas
        // ================= PENGURUTAN PRIORITAS =================
        // Status yang masih bisa di-approve oleh role yang login ditaruh di atas.
        // Sisanya diurutkan dari yang terbaru.
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


        // Setelah prioritas, urutkan berdasarkan tanggal terbaru
        $antrean = $query->orderBy('created_at', 'desc')
            ->paginate(10)
            ->withQueryString();
        // ================= END PENGURUTAN PRIORITAS =================


        // Hitung sisa cuti dinamis (sama persis logika Dashboard) untuk setiap item
        $tahun = date('Y');
        $antrean->getCollection()->transform(function ($item) use ($tahun) {
            $saldo = $item->pegawai->saldoCutiTahunIni ?? null;


            $kuotaTahunan  = $saldo->kuota_tahunan ?? 0;
            $sisaTahunLalu = $saldo->sisa_cuti_tahun_lalu ?? 0;


            $cutiTerpakai = PengajuanCuti::where('pegawai_id', $item->pegawai_id)
                ->where('jenis_cuti', 'Cuti Tahunan')
                ->where('status', 'disetujui')
                ->whereYear('tanggal_mulai', $tahun)
                ->sum('jumlah_hari');


            $totalTersedia = ($kuotaTahunan + $sisaTahunLalu) - $cutiTerpakai;


            // Tambahkan property baru ke object pegawai agar bisa dibaca di Vue
            $item->pegawai->sisa_cuti_tersedia = $totalTersedia;
            $item->pegawai->kuota_tahunan      = $kuotaTahunan;
            $item->pegawai->sisa_tahun_lalu    = $sisaTahunLalu;
            $item->pegawai->cuti_terpakai      = $cutiTerpakai;


            return $item;
        });


        // Inertia::render diarahkan ke 'Atasan/MonitoringCuti' — pastikan file
        // resources/js/Pages/Atasan/MonitoringCuti.vue ada di project Boss
        // (hasil salinan dari AntreanApproval.vue yang sudah diperbaiki
        // approvalTimeline-nya di percakapan sebelumnya).
        return Inertia::render('Atasan/MonitoringCuti', [
            'antrean' => $antrean,
            // 'status' ditambahkan agar filter dropdown Vue tetap terisi setelah reload/paginate
            'filters' => $request->only(['search', 'jenis_cuti', 'status']),
        ]);
    }


    private function isCutiTahunan(?string $jenisCuti): bool
    {
        if ($jenisCuti === null || $jenisCuti === '') {
            return true;
        }


        return strtolower(trim((string) $jenisCuti)) === 'cuti tahunan';
    }


    // 2. Memproses Persetujuan atau Penolakan
    public function process(Request $request, int $id)
    {
        $request->validate([
            'action' => ['required', 'in:approve,reject'],
        ]);


        $pengajuan = PengajuanCuti::with('pegawai')->findOrFail($id);
        $user = Auth::user();
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


        // PERBAIKAN GRANULARITAS: L1 (role_id 2) dibatasi ke Tim Kerja
        // (`divisi`) miliknya sendiri, BUKAN ke seluruh departemen —
        // sebelumnya memakai `departemen` sehingga L1 bisa approve staf
        // dari Tim Kerja lain selama masih satu Departemen/Kelompok.
        if ($user->role_id === 2 && $pengajuan->pegawai->divisi !== $user->divisi) {
            abort(403, 'Pengajuan berada di luar Tim Kerja Anda.');
        }


        // L2 (role_id 3) TETAP dibatasi ke Departemen/Kelompok
        // (`departemen`) miliknya sendiri — mencakup semua Tim Kerja & L1
        // di bawah kelompok itu, sesuai wewenangnya sebagai Ketua Kelompok
        // Substansi.
        if ($user->role_id === 3 && $pengajuan->pegawai->departemen !== $user->departemen) {
            abort(403, 'Pengajuan berada di luar departemen Anda.');
        }


        // $levelApproval = angka level (1-4) tempat pengajuan SEDANG berada
        // sebelum diproses. Nilai ini didapat dari status 'menunggu_lX' yang
        // SUDAH divalidasi lewat pengecekan $roleYangDibutuhkan di atas, jadi
        // sudah pasti sinkron dengan role atasan yang sedang login — dipakai
        // sebagai SATU-SATUNYA sumber kebenaran level, baik untuk ApprovalLog
        // maupun kolom level_saat_ini di database.
        $levelApproval = [
            'menunggu_l1' => 1,
            'menunggu_l2' => 2,
            'menunggu_l3' => 3,
            'menunggu_l4' => 4,
        ][$statusSaatIni];


        // --- JIKA DITOLAK ---
        if ($request->action === 'reject') {
            $alasanPenolakan = $request->input('alasan', 'Tidak disetujui');
            $namaApprover = $user->nama ?? 'Atasan';


            $alasanUtama = trim(explode('|', $pengajuan->keterangan)[0]);
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


            $atasanL3 = Pegawai::where('role_id', 4)->where('departemen', $pengajuan->pegawai->departemen)->first()
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


        // TAHAP 2: L2 MENGAPPROVE (Hanya terjadi jika L1 yang mengajukan cuti)
        elseif ($statusSaatIni === 'menunggu_l2' && $user->role_id === 3) {
            Notifikasi::where('pegawai_id', $user->id)->where('judul', 'Pengajuan Cuti Baru')->delete();


            $pengajuan->update(['status' => 'menunggu_l3', 'level_saat_ini' => 3]);


            $atasanL3 = Pegawai::where('role_id', 4)->where('departemen', $pengajuan->pegawai->departemen)->first()
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


            $atasanL4 = Pegawai::where('role_id', 6)->where('departemen', $pengajuan->pegawai->departemen)->first()
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


            if ($this->isCutiTahunan($pengajuan->jenis_cuti)) {
                $saldo = SaldoCuti::where('pegawai_id', $targetUserId)
                    ->where('tahun', date('Y', strtotime($pengajuan->tanggal_mulai)))
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


            if ($this->isCutiTahunan($pengajuan->jenis_cuti)) {
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
            // PERBAIKAN GRANULARITAS: L1 dibatasi ke Tim Kerja (`divisi`)
            // miliknya sendiri, BUKAN seluruh departemen — sebelumnya
            // memakai `departemen`.
            $query->whereHas('pegawai', function ($q) use ($user) {
                $q->where('divisi', $user->divisi);
            })->whereNotIn('status', ['menunggu_l1']);
        } elseif ($user->role_id === 3) {
            // L2 TETAP dibatasi ke Departemen/Kelompok (`departemen`)
            // miliknya sendiri, mencakup semua Tim Kerja & L1 di bawahnya.
            $query->whereHas('pegawai', function ($q) use ($user) {
                $q->where('departemen', $user->departemen);
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