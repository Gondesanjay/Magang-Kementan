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

class ApprovalController extends Controller
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
        if ($user->role_id === 2) {
            // L1 (Ketua Tim): hanya bisa melihat pengajuan di departemennya saja
            $query->whereHas('pegawai', function ($q) use ($user) {
                $q->where('departemen', $user->departemen);
            });
        } elseif (!in_array($user->role_id, [3, 4, 5, 6])) {
            // Jika bukan L1, L2, L3, L4, atau Admin HR -> blokir aksesnya
            $query->where('id', 0);
        }
        // Catatan: L2, L3, L4, dan Admin HR (role 5) bebas melihat skala biro/pusat.

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
        $antrean = $query->orderBy('created_at', 'desc')
            ->paginate(10)
            ->withQueryString();

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

        return Inertia::render('Atasan/AntreanApproval', [
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

        if ($user->role_id === 2 && $pengajuan->pegawai->departemen !== $user->departemen) {
            abort(403, 'Pengajuan berada di luar departemen Anda.');
        }

        // $levelApproval = angka level (1-4) tempat pengajuan SEDANG berada
        // sebelum diproses. Nilai ini didapat dari status 'menunggu_lX' yang
        // SUDAH divalidasi lewat pengecekan $roleYangDibutuhkan di atas, jadi
        // sudah pasti sinkron dengan role atasan yang sedang login — tidak
        // perlu ditebak ulang lewat switch/if role_id seperti biasanya
        // dilakukan, karena bisa berisiko tidak sinkron kalau suatu saat
        // pemetaan role_id berubah di satu tempat tapi lupa diubah di tempat
        // lain.
        $levelApproval = [
            'menunggu_l1' => 1,
            'menunggu_l2' => 2,
            'menunggu_l3' => 3,
            'menunggu_l4' => 4,
        ][$statusSaatIni];

        // --- JIKA DITOLAK / DITANGGUHKAN ---
        if ($request->action === 'reject') {
            $alasanPenolakan = $request->input('catatan', 'Ada agenda/tugas kantor');
            $namaApprover = $user->nama ?? 'Atasan'; // Mengambil nama atasan yang sedang login

            // Ambil alasan murni pegawai (bagian sebelum tanda '|')
            $alasanUtama = trim(explode('|', $pengajuan->keterangan)[0]);
            if (empty($alasanUtama) || $alasanUtama === '-') {
                $alasanUtama = 'Tidak ada alasan awal';
            }

            // Format kalimat rapi memuat nama atasan & keterangan cuti ulang
            $keteranganBaru = "{$alasanUtama} (Ditangguhkan oleh {$namaApprover}: {$alasanPenolakan} — Pengajuan Cuti Ulang)";

            // ================= PERBAIKAN: SUMBER KEBENARAN LEVEL PENOLAK =================
            // Root cause bug "penolakan L1 tampil di kotak L4" di halaman Monitoring
            // Cuti: frontend sebelumnya harus MENEBAK level penolak dari teks
            // `keterangan` (rawan meleset kalau format teks berubah/tidak lengkap).
            // Sekarang kita simpan ANGKA LEVEL PENOLAK secara eksplisit & langsung
            // ke kolom `level_saat_ini` memakai $levelApproval — nilai ini sumber
            // kebenaran (source of truth) satu-satunya yang dipakai juga di
            // ApprovalLog di bawah, sehingga dijamin selalu sinkron dan tidak
            // pernah kosong/salah level saat pengajuan ditolak.
            // (int) ditambahkan sebagai jaga-jaga tipe data supaya kolom
            // `level_saat_ini` selalu berupa angka murni, bukan string.
            $pengajuan->update([
                'status' => 'ditolak',
                'keterangan' => $keteranganBaru,
                'level_saat_ini' => (int) $levelApproval,
            ]);
            // ================= END PERBAIKAN =================

            ApprovalLog::create([
                'pengajuan_id' => $pengajuan->id,
                'approver_id' => $user->id,
                'level_approval' => $levelApproval,
                'keputusan' => 'tolak',
                'catatan' => $alasanPenolakan,
                'tanggal_keputusan' => now(),
            ]);

            // Kirim notifikasi penolakan ke pegawai
            Notifikasi::create([
                'pegawai_id' => $targetUserId,
                'judul'      => 'Pengajuan Cuti Ditangguhkan',
                'pesan'      => 'Pengajuan Anda ditangguhkan oleh ' . $namaApprover . '. Alasan: ' . $alasanPenolakan,
                'is_read'    => false,
            ]);

            return back()->with('success', 'Pengajuan cuti berhasil ditangguhkan.');
        }

        // --- JIKA DISETUJUI (LOGIKA ALUR BARU KEMENTAN) ---
        $statusPesan = '';

        // TAHAP 1: L1 MENGAPPROVE (Otomatis lompat L2, langsung ke L3)
        if ($statusSaatIni === 'menunggu_l1' && $user->role_id === 2) {
            // Hapus notifikasi lama milik L1 agar tidak menumpuk
            Notifikasi::where('pegawai_id', $user->id)->where('judul', 'Pengajuan Cuti Baru')->delete();

            // ALUR STAF: Langsung melompat ke L3 (Kasubag TU)
            $pengajuan->update(['status' => 'menunggu_l3', 'level_saat_ini' => 3, 'atasan_l1_id' => $user->id]);

            // Kirim Notifikasi ke L3 (Role 4)
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
            // Hapus notifikasi lama milik L2
            Notifikasi::where('pegawai_id', $user->id)->where('judul', 'Pengajuan Cuti Baru')->delete();

            // L2 Setuju -> Lanjut ke L3
            $pengajuan->update(['status' => 'menunggu_l3', 'level_saat_ini' => 3]);

            // Notifikasi ke L3 (Role 4)
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
            // Hapus notifikasi lama milik L3
            Notifikasi::where('pegawai_id', $user->id)->where('judul', 'Pengajuan Cuti Baru')->delete();

            // L3 Setuju -> Lanjut ke L4
            $pengajuan->update(['status' => 'menunggu_l4', 'level_saat_ini' => 4, 'atasan_l3_id' => $user->id]);

            // Notifikasi ke L4 (KABIRO - ROLE 6)
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
            // Hapus notifikasi lama milik L4
            Notifikasi::where('pegawai_id', $user->id)->where('judul', 'Pengajuan Cuti Baru')->delete();

            if ($this->isCutiTahunan($pengajuan->jenis_cuti)) {
                $saldo = SaldoCuti::where('pegawai_id', $targetUserId)
                    ->where('tahun', date('Y', strtotime($pengajuan->tanggal_mulai)))
                    ->first();

                if (!$saldo || $saldo->sisa < $pengajuan->jumlah_hari) {
                    return back()->with('error', 'Saldo Cuti Tahunan tidak mencukupi untuk menyetujui pengajuan ini.');
                }
            }

            // L4 Setuju -> Status FINAL (Disetujui)
            $pengajuan->update([
                'status' => 'disetujui',
                'level_saat_ini' => 6,
                'atasan_l4_id' => $user->id,
            ]);

            // Potong saldo HANYA untuk jenis cuti tahunan (Dieksekusi di akhir/final)
            if ($this->isCutiTahunan($pengajuan->jenis_cuti)) {
                $saldo->decrement('sisa', $pengajuan->jumlah_hari);
            }

            $statusPesan = 'Disetujui Kepala Biro Perencanaan (L4)';
        }

        // Kirim Notifikasi Update Status ke Pegawai Pemohon
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
        // PERBAIKAN: Menambahkan relasi 'saldoCutiTahunIni' pada riwayat
        $query = PengajuanCuti::with(['pegawai.saldoCutiTahunIni']);

        // Logika filter riwayat: Tampilkan data yang SUDAH melewati tahap/level atasan tersebut
        if ($user->role_id === 2) {
            // Atasan L1: Menampilkan cuti di departemennya yang statusnya sudah bukan 'menunggu_l1'
            $query->whereHas('pegawai', function ($q) use ($user) {
                $q->where('departemen', $user->departemen);
            })->whereNotIn('status', ['menunggu_l1']);
        } elseif ($user->role_id === 3) {
            // L2: Menampilkan cuti yang sudah diproses L2
            $query->whereNotIn('status', ['menunggu_l1', 'menunggu_l2']);
        } elseif ($user->role_id === 4) {
            // L3: Menampilkan cuti yang sudah diproses L3
            $query->whereNotIn('status', ['menunggu_l1', 'menunggu_l2', 'menunggu_l3']);
        } elseif ($user->role_id === 6) { // L4 (ROLE 6)
            // L4: Hanya menampilkan cuti yang sudah final
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
