<?php

namespace App\Http\Controllers;

use App\Models\ApprovalLog;
use App\Models\CutiDitangguhkan;
use App\Models\Notifikasi;
use App\Models\Pegawai;
use App\Models\PengajuanCuti;
use App\Models\SaldoCuti;
use App\Support\RincianSaldo;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PembatalanController extends Controller
{
    private const ROLE_PENANGGUH = 6;

    /**
     * Melengkapi pengajuan dengan data modal Detail Cuti
     * (Rincian Sisa Cuti dan nama atasan L1-L4). Query ter-batch, tanpa N+1.
     */
    private function lengkapiDataModal(Collection $daftar, int $tahunFilter): void
    {
        if ($daftar->isEmpty()) {
            return;
        }

        RincianSaldo::lengkapi($daftar, $tahunFilter);

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
     * Daftar cuti Tahunan untuk halaman Penangguhan: yang BISA ditangguhkan dan
     * yang SUDAH ditangguhkan (tombol nonaktif di Vue lewat `bisa_ditangguhkan`,
     * badge lewat `sudah_ditangguhkan`). Semua status difilter tahun: cuti lintas
     * tahun muncul di kedua tahunnya. Jendela tahun diatur di config/leave.php
     * (bawaan mundur 2, maju 1).
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
            ->tampilDiPenangguhan()
            // Rentang cuti beririsan dengan tahun terpilih.
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
            // Yang belum ditangguhkan di atas, yang sudah di bawah; lalu urut tanggal cuti.
            ->orderByRaw('CASE WHEN status = ? THEN 1 ELSE 0 END', [PengajuanCuti::STATUS_DITANGGUHKAN])
            ->orderBy('tanggal_mulai')
            ->orderBy('id')
            ->paginate(10)
            ->withQueryString();

        // Halaman kosong: ke halaman terakhir.
        if ($halaman->isEmpty() && $halaman->currentPage() > 1) {
            return redirect()->route('atasan.penangguhan', array_merge(
                $request->query(),
                ['page' => $halaman->lastPage()]
            ));
        }

        $halaman->getCollection()->each(function (PengajuanCuti $pengajuan) {
            $pengajuan->bisa_ditangguhkan = $pengajuan->bisaDitangguhkan();
            $pengajuan->alasan_tidak_bisa = $pengajuan->alasanTidakBisaDitangguhkan();
            $pengajuan->sudah_ditangguhkan = $pengajuan->status === PengajuanCuti::STATUS_DITANGGUHKAN;
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
     * Menangguhkan cuti oleh Kepala Biro (hanya Cuti Tahunan).
     * Catatan Tgh dibuat untuk semua cuti yang ditangguhkan, per tahun asal.
     * Kolom legacy `sisa` hanya dikembalikan bila sebelumnya disetujui.
     * Notifikasi antrean (bila masih menunggu) dibersihkan, pemohon diberi tahu.
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

            // Cek jenis cuti (hanya Cuti Tahunan) dan status (tolak bila sudah ditangguhkan).
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
                'status' => PengajuanCuti::STATUS_DITANGGUHKAN,
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

            // Saldo legacy dikembalikan hanya bila sebelumnya disetujui.
            if ($statusSebelum === 'disetujui') {
                SaldoCuti::sesuaikanSisaLegacy($pengajuan, +1);
            }

            // Tgh dicatat untuk SEMUA status yang bisa ditangguhkan (termasuk
            // Menunggu): hari yang ditangguhkan menjadi tambahan saldo tahun
            // berikutnya. JANGAN dibatasi ke status 'disetujui'.
            // Satu catatan per tahun asal (sama dengan "Tgh sistem" di Rekap Laporan).
            // Nilai Tgh hasil edit manual Admin HR tetap ada; di sini hanya DITAMBAH.
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

            // Status, saldo, dan catatan CutiDitangguhkan sudah final.
            SaldoCuti::sinkronkanBawaanPengajuan($pengajuan);

            // Notifikasi: bersihkan antrean approval (bila pengajuan masih menunggu),
            // lalu beri tahu pemohon. Di dalam transaksi, jadi push baru terkirim
            // setelah commit dan batal bila rollback.
            Notifikasi::hapusAntreanPengajuan($pengajuan->id);

            Notifikasi::create([
                'pegawai_id'   => $pengajuan->pegawai_id,
                'pengajuan_id' => $pengajuan->id,
                'judul'        => 'Cuti Ditangguhkan',
                'pesan'        => "Cuti {$pengajuan->jenis_cuti} Anda ("
                    . Carbon::parse($pengajuan->tanggal_mulai)->format('d/m/Y') . ' - '
                    . Carbon::parse($pengajuan->tanggal_selesai)->format('d/m/Y')
                    . ") ditangguhkan oleh {$user->nama}. Alasan: {$request->alasan}. "
                    . 'Hari yang ditangguhkan ditambahkan ke saldo tahun berikutnya.',
                'tautan'       => route('karyawan.riwayat'),
                'is_read'      => false,
            ]);
        });

        return back()->with('success', 'Cuti berhasil ditangguhkan.');
    }
}
