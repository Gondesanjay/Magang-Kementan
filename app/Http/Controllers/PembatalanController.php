<?php

namespace App\Http\Controllers;

use App\Models\ApprovalLog;
use App\Models\CutiDitangguhkan;
use App\Models\Pegawai;
use App\Models\PengajuanCuti;
use App\Models\SaldoCuti;
use App\Support\RincianSaldo;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PembatalanController extends Controller
{
    private const ROLE_PENANGGUH = 6;

    // ================= HELPER: DATA MODAL DETAIL CUTI =================
    /**
     * Melengkapi setiap pengajuan dengan data modal Detail Cuti
     * (Rincian Sisa Cuti lewat RincianSaldo + nama atasan L1-L4).
     * Query ter-batch, tanpa N+1.
     *
     * Rumus saldo (kuota, bawaan, terpakai, Tgh, dikembalikan) sebelumnya
     * ada di controller ini (hitungInfoSaldo) dan kini dipusatkan di
     * App\Support\RincianSaldo agar satu sumber dengan Dashboard.
     */
    private function lengkapiDataModal(Collection $daftar, int $tahunFilter): void
    {
        if ($daftar->isEmpty()) {
            return;
        }

        // Rincian Sisa Cuti per pegawai untuk tahun yang dipilih.
        RincianSaldo::lengkapi($daftar, $tahunFilter);

        // Nama asli atasan L1 & L2 (peta, bukan query per baris).
        $kelompokUnik = $daftar->pluck('pegawai.kelompok_substansi')->filter()->unique()->values()->all();
        $timKerjaUnik = $daftar->pluck('pegawai.tim_kerja')
            ->filter(fn($t) => $t && $t !== '-')->unique()->values()->all();

        $petaAtasanL2 = empty($kelompokUnik) ? collect()
            : Pegawai::where('role_id', 3)->whereIn('kelompok_substansi', $kelompokUnik)
            ->get()->keyBy('kelompok_substansi');

        $petaAtasanL1 = empty($timKerjaUnik) ? collect()
            : Pegawai::where('role_id', 2)->whereIn('tim_kerja', $timKerjaUnik)
            ->get()->keyBy('tim_kerja');

        $namaPejabatAktif = fn(int $roleId) => Pegawai::where('role_id', $roleId)
            ->where(fn($q) => $q->where('status_aktif', 'Aktif')->orWhereNull('status_aktif'))
            ->orderBy('id')
            ->value('nama');

        $namaAtasanL3 = $namaPejabatAktif(4) ?? 'Kasubag TU';
        $namaAtasanL4 = $namaPejabatAktif(6) ?? 'Kepala Biro Perencanaan';

        $daftar->each(function (PengajuanCuti $item) use ($petaAtasanL1, $petaAtasanL2, $namaAtasanL3, $namaAtasanL4) {
            if (!$item->pegawai) {
                return;
            }

            $tim      = $item->pegawai->tim_kerja;
            $kelompok = $item->pegawai->kelompok_substansi;

            $atasanL1 = ($tim && $tim !== '-') ? ($petaAtasanL1[$tim] ?? null) : null;
            $atasanL2 = $kelompok ? ($petaAtasanL2[$kelompok] ?? null) : null;

            $item->atasan_l1_nama = $atasanL1->nama ?? 'Ketua Tim Kerja Pegawai';
            $item->atasan_l2_nama = $atasanL2->nama ?? 'Ketua Kelompok Substansi';
            $item->atasan_l3_nama = $namaAtasanL3;
            $item->atasan_l4_nama = $namaAtasanL4;
        });
    }
    // ================= END HELPER =================

    /**
     * Menampilkan daftar cuti yang bisa ditangguhkan.
     *
     * Filter jenis cuti (hanya Cuti Tahunan) sudah ada di scope
     * PengajuanCuti::bisaDitangguhkan(), jadi tidak diulang di sini.
     *
     * ================= PERBAIKAN (FILTER TAHUN) =================
     * SEMUA status (menunggu_l1..l4 maupun disetujui) kini ikut filter tahun:
     * hanya pengajuan yang rentang cutinya beririsan dengan tahun terpilih
     * yang tampil. Cuti lintas tahun (mis. 31 Des 2026 - 4 Jan 2027) muncul
     * di kedua tahunnya. Sebelumnya pengajuan berstatus menunggu SELALU tampil
     * di semua tahun, sehingga filter terlihat tidak berfungsi. Halaman ini
     * adalah daftar cuti yang bisa ditangguhkan, bukan antrean persetujuan,
     * jadi tidak ada yang "terlewat" bila ikut filter tahun.
     *
     * Jendela tahun: tahun_mundur ke belakang dan tahun_maju ke depan
     * (config/leave.php; bawaan 2 dan 1).
     *
     * Perbandingan tanggal memakai pembanding langsung (tanpa whereDate)
     * supaya index kolom tanggal bisa dipakai database.
     * ================= END PERBAIKAN FILTER TAHUN =================
     */
    public function index(Request $request)
    {
        abort_unless((int) $request->user()->role_id === self::ROLE_PENANGGUH, 403, 'Hanya L4 yang dapat menangguhkan cuti.');

        $search = $request->input('search');
        $kelompok = $request->input('kelompok_substansi');

        // ===== Jendela tahun =====
        $tahunIni    = (int) date('Y');
        $tahunMundur = (int) config('leave.tahun_mundur', 2);
        $tahunMaju   = (int) config('leave.tahun_maju', 1);
        $daftarTahun = range($tahunIni + $tahunMaju, $tahunIni - $tahunMundur, -1);

        $tahun = (int) $request->input('tahun', $tahunIni);
        if (!in_array($tahun, $daftarTahun, true)) {
            $tahun = $tahunIni;
        }

        $awalTahun      = "{$tahun}-01-01";
        $awalTahunDepan = ($tahun + 1) . '-01-01';

        $halaman = PengajuanCuti::with(['pegawai.saldoCutiTahunIni', 'approvalLogs.approver:id,nama,jabatan'])
            ->bisaDitangguhkan()
            // Semua status ikut filter tahun (rentang cuti beririsan dengan tahun terpilih).
            ->where('tanggal_mulai', '<', $awalTahunDepan)
            ->where('tanggal_selesai', '>=', $awalTahun)
            ->when($search, function ($query) use ($search) {
                $query->whereHas('pegawai', function ($q) use ($search) {
                    $q->where('nama', 'like', "%{$search}%")
                        ->orWhere('nip', 'like', "%{$search}%");
                });
            })
            ->when($kelompok, function ($query) use ($kelompok) {
                $query->whereHas('pegawai', function ($q) use ($kelompok) {
                    $q->where('kelompok_substansi', $kelompok);
                });
            })
            ->orderBy('tanggal_mulai')
            ->orderBy('id')
            ->paginate(10)
            ->withQueryString();

        // Halaman melebihi batas (mis. baris terakhir di halaman ini baru saja
        // ditangguhkan dan hilang dari daftar): arahkan ke halaman terakhir.
        if ($halaman->isEmpty() && $halaman->currentPage() > 1) {
            return redirect()->route('atasan.penangguhan', array_merge(
                $request->query(),
                ['page' => $halaman->lastPage()]
            ));
        }

        $halaman->getCollection()->each(function (PengajuanCuti $pengajuan) {
            $pengajuan->bisa_ditangguhkan = $pengajuan->bisaDitangguhkan();
            $pengajuan->alasan_tidak_bisa = $pengajuan->alasanTidakBisaDitangguhkan();
        });

        $this->lengkapiDataModal($halaman->getCollection(), $tahun);

        $daftarKelompokSubstansi = Pegawai::query()
            ->whereNotNull('kelompok_substansi')
            ->where('kelompok_substansi', '!=', '')
            ->where('kelompok_substansi', '!=', '-')
            ->distinct()
            ->orderBy('kelompok_substansi')
            ->pluck('kelompok_substansi');

        return Inertia::render('Atasan/PembatalanCuti', [
            'daftarCuti' => $halaman,
            'daftarKelompokSubstansi' => $daftarKelompokSubstansi,
            'daftarTahun' => $daftarTahun,
            'filters' => array_merge(
                $request->only(['search', 'kelompok_substansi']),
                ['tahun' => $tahun]
            ),
        ]);
    }

    /**
     * Memproses penangguhan oleh Kepala Biro (hanya Cuti Tahunan).
     *
     * Aturan "hanya Cuti Tahunan" dan status yang boleh ditangguhkan dicek
     * lewat PengajuanCuti::alasanTidakBisaDitangguhkan(), jadi satu sumber
     * aturan dengan tampilan di halaman.
     *
     * Cuti lintas tahun (mis. 29 Des 2026 - 4 Jan 2027) dikembalikan PER TAHUN:
     * hari Desember ke saldo 2026, hari Januari ke saldo 2027. Catatan
     * CutiDitangguhkan juga dibuat satu per tahun asal.
     *
     * Di akhir transaksi, bawaan disinkronkan lewat
     * SaldoCuti::sinkronkanBawaanPengajuan() (ikut rollback bila ada error).
     *
     * CATATAN: redirect memakai back(), jadi query string halaman (tahun,
     * pencarian, kelompok) ikut terbawa lewat header Referer.
     */
    public function process(Request $request, int $id)
    {
        $user = $request->user();

        abort_unless((int) $user->role_id === self::ROLE_PENANGGUH, 403, 'Hanya L4 yang dapat menangguhkan cuti.');

        $request->validate([
            'alasan' => ['required', 'string', 'max:255'],
        ], [
            'alasan.required' => 'Alasan penangguhan wajib diisi.',
        ]);

        DB::transaction(function () use ($request, $user, $id) {
            $pengajuan = PengajuanCuti::with('pegawai')->lockForUpdate()->findOrFail($id);
            $statusSebelum = $pengajuan->status;

            // Mencakup pengecekan jenis cuti (hanya Cuti Tahunan) dan status.
            $alasanTidakBisa = $pengajuan->alasanTidakBisaDitangguhkan();

            if ($alasanTidakBisa !== null) {
                throw ValidationException::withMessages([
                    'status' => [$alasanTidakBisa],
                ]);
            }

            $keteranganBaru = trim((string) $pengajuan->keterangan) === ''
                ? '[DITANGGUHKAN: ' . $request->alasan . ']'
                : $pengajuan->keterangan . ' | [DITANGGUHKAN: ' . $request->alasan . ']';

            $pengajuan->update([
                'status' => 'dibatalkan_ditangguhkan',
                'keterangan' => $keteranganBaru,
            ]);

            ApprovalLog::create([
                'pengajuan_id' => $pengajuan->id,
                'approver_id' => $user->id,
                'level_approval' => 5,
                'keputusan' => 'tangguh',
                'catatan' => $request->alasan,
                'tanggal_keputusan' => now(),
            ]);

            // Saldo & catatan Tgh hanya untuk cuti yang sebelumnya sudah disetujui.
            if ($statusSebelum === 'disetujui') {
                // Kembalikan kolom legacy `sisa` PER TAHUN.
                SaldoCuti::sesuaikanSisaLegacy($pengajuan, +1);

                // Catatan untuk pelaporan; bukan carry-over (saldo sudah dikembalikan).
                // Satu catatan per tahun asal (cuti lintas tahun = dua catatan).
                $rincian = SaldoCuti::hariPerTahunDariPengajuan(
                    $pengajuan->tanggal_mulai,
                    $pengajuan->tanggal_selesai,
                    (int) $pengajuan->jumlah_hari
                );

                foreach ($rincian as $tahunAsal => $hari) {
                    if ($hari <= 0) {
                        continue;
                    }

                    CutiDitangguhkan::updateOrCreate(
                        [
                            'pengajuan_asal_id' => $pengajuan->id,
                            'tahun_asal' => (int) $tahunAsal,
                        ],
                        [
                            'pegawai_id' => $pengajuan->pegawai_id,
                            'jumlah_hari' => (int) $hari,
                            'status_pakai' => 'belum_dipakai',
                            'alasan_penangguhan' => $request->alasan,
                        ]
                    );
                }
            }

            // Paling akhir di dalam transaksi: status, saldo per tahun, dan
            // catatan CutiDitangguhkan sudah final.
            SaldoCuti::sinkronkanBawaanPengajuan($pengajuan);
        });

        return back()->with('success', 'Cuti berhasil ditangguhkan.');
    }
}
