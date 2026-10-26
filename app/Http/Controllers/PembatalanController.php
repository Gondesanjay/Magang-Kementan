<?php

namespace App\Http\Controllers;

use App\Models\ApprovalLog;
use App\Models\CutiDitangguhkan;
use App\Models\Notifikasi; // BARU
use App\Models\Pegawai;
use App\Models\PengajuanCuti;
use App\Models\SaldoCuti;
use Carbon\Carbon; // BARU
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PembatalanController extends Controller
{
    private const ROLE_PENANGGUH = 6;

    /**
     * Menampilkan daftar cuti yang bisa ditangguhkan.
     */
    public function index(Request $request)
    {
        abort_unless((int) $request->user()->role_id === self::ROLE_PENANGGUH, 403, 'Hanya L4 yang dapat menangguhkan cuti.');

        $search = $request->input('search');
        $kelompok = $request->input('kelompok_substansi');

        $daftarCuti = PengajuanCuti::with('pegawai')
            ->bisaDitangguhkan()
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
            ->get()
            ->each(function (PengajuanCuti $pengajuan) {
                $pengajuan->bisa_ditangguhkan = $pengajuan->bisaDitangguhkan();
                $pengajuan->alasan_tidak_bisa = $pengajuan->alasanTidakBisaDitangguhkan();
            });

        $daftarKelompokSubstansi = Pegawai::query()
            ->whereNotNull('kelompok_substansi')
            ->where('kelompok_substansi', '!=', '')
            ->where('kelompok_substansi', '!=', '-')
            ->distinct()
            ->orderBy('kelompok_substansi')
            ->pluck('kelompok_substansi');

        return Inertia::render('Atasan/PembatalanCuti', [
            'daftarCuti' => $daftarCuti,
            'daftarKelompokSubstansi' => $daftarKelompokSubstansi,
            'filters' => $request->only(['search', 'kelompok_substansi']),
        ]);
    }

    /**
     * Memproses eksekusi penangguhan oleh Kepala Biro.
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

            if (
                strtolower(trim((string) $pengajuan->jenis_cuti)) === 'cuti tahunan'
                && $statusSebelum === 'disetujui'
            ) {
                $tahunAsal = (int) date('Y', strtotime($pengajuan->tanggal_mulai));
                SaldoCuti::where('pegawai_id', $pengajuan->pegawai_id)
                    ->where('tahun', $tahunAsal)
                    ->increment('sisa', (int) $pengajuan->jumlah_hari);

                // Catatan ini hanya untuk pelaporan; bukan carry-over dan saldo sudah dikembalikan.
                CutiDitangguhkan::updateOrCreate(
                    ['pengajuan_asal_id' => $pengajuan->id],
                    [
                        'pegawai_id' => $pengajuan->pegawai_id,
                        'tahun_asal' => $tahunAsal,
                        'jumlah_hari' => (int) $pengajuan->jumlah_hari,
                        'status_pakai' => 'belum_dipakai',
                        'alasan_penangguhan' => $request->alasan,
                    ]
                );
            }

            // BARU: beri tahu pegawai pemilik cuti (notifikasi lonceng + push lewat
            // hook di model Notifikasi). Sebelumnya penangguhan tidak memberi
            // notifikasi sama sekali. Teks sengaja tidak memuat kata "batal"/"tolak"
            // supaya warna badge di lonceng tetap kuning (penangguhan).
            if ($pengajuan->pegawai) {
                Notifikasi::create([
                    'pegawai_id' => $pengajuan->pegawai_id,
                    'judul'      => 'Cuti Ditangguhkan',
                    'pesan'      => 'Cuti Anda tanggal ' . Carbon::parse($pengajuan->tanggal_mulai)->format('d/m/Y')
                        . ' ditangguhkan oleh ' . ($user->nama ?? 'Kepala Biro')
                        . '. Alasan: ' . $request->alasan
                        . '. Anda dapat mengajukan revisi tanggal di Riwayat Pengajuan.',
                    'tautan'     => route('karyawan.riwayat', [], false),
                    'is_read'    => false,
                ]);
            }
        });

        return back()->with('success', 'Cuti berhasil ditangguhkan.');
    }
}
