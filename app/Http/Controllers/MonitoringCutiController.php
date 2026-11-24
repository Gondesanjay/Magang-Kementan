<?php

namespace App\Http\Controllers;

use App\Models\CutiDitangguhkan;
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

// File ini WAJIB disimpan di app/Http/Controllers/MonitoringCutiController.php
// dengan nama class persis "MonitoringCutiController".
//
// RINGKASAN FITUR / PERBAIKAN YANG SUDAH ADA:
//   - buildFilterPropsUntukAtasan(): dropdown filter berjenjang untuk MonitoringCuti.vue.
//   - atasan_l1_nama..atasan_l4_nama: nama asli atasan untuk Modal Detail Cuti.
//   - process(): transaksi DB + lockForUpdate, validasi alasan tolak, batasan wilayah
//     L1 per Tim Kerja & L2 per Kelompok Substansi.
//   - Rumus saldo disamakan dengan DashboardController::hitungSaldoPegawai()
//     (bawaan eligible, terpakai total, dikembalikan, total tersedia). Rumus
//     aritmetikanya kini dipanggil dari RincianSaldo::hitungTotal() (satu sumber).
//   - Cuti lintas tahun: saldo dicek per tahun saat L4 menyetujui, kolom legacy `sisa`
//     disesuaikan lewat SaldoCuti::sesuaikanSisaLegacy(), bawaan disinkronkan lewat
//     SaldoCuti::sinkronkanBawaanPengajuan().
//   - index(): dua daftar. 'menunggu' (semua menunggu_l1..l4, tanpa filter tahun & paginasi)
//     dan 'antrean' (riwayat diproses, difilter tahun, dipaginasi). Jendela tahun: 2 ke
//     belakang, 1 ke depan.
//   - Rincian saldo per pengajuan dihitung lewat App\Support\RincianSaldo::lengkapi().
//   - L3 (role 4) tidak melihat pengajuan milik sendiri maupun milik Kepala Biro.
//
// PERBAIKAN (KEPALA BIRO TIDAK MELIHAT RIWAYAT CUTI SENDIRI):
//   - index() (query $antrean) & history(): Kepala Biro (role 6) tidak melihat riwayat
//     pengajuan miliknya sendiri. Daftar "Menunggu Persetujuan" TIDAK diubah, jadi
//     pengajuan yang butuh persetujuannya tetap muncul. Admin HR (role 5) tidak terpengaruh.
//
// PERBAIKAN BARU (SATU RUMUS TERPAKAI & ATASAN AKTIF):
//   - process() (L4 menyetujui): pengecekan saldo kini memakai
//     SaldoCuti::terpakaiEfektif() (koreksi bulanan -> koreksi tahunan -> hitungan
//     sistem), SATU rumus dengan Rekap Kuota Detail & Rekap Laporan. Sebelumnya hanya
//     koreksi tahunan + terpakai murni, sehingga pegawai berkoreksi bulanan bisa dicek
//     dengan angka yang berbeda dari yang tampil di Rekap.
//     hitungInfoSaldo() sekarang menerima Terpakai yang SUDAH efektif.
//   - process() (L4 menyetujui): pengecekan saldo kini memasukkan cuti ditangguhkan (Tgh)
//     seperti Rekap Kuota Detail: Tgh tahun yang dicek MEMOTONG saldo, Tgh tahun
//     sebelumnya MENAMBAH total tersedia. Sebelumnya Tgh diabaikan.
//   - index(): peta nama atasan L1 (Ketua Tim) & L2 (Ketua Kelompok) hanya memakai
//     pejabat AKTIF (status_aktif 'Aktif' / NULL), dan bila ada lebih dari satu dipilih
//     id terkecil. Sama dengan aturan di RekapKuotaDetailController::getApproverName().
//
// PERBAIKAN (RUMUS SALDO SATU SUMBER):
//   - hitungInfoSaldo(): aritmetika (terpakai total, sisa hak, total tersedia)
//     dipanggil dari RincianSaldo::hitungTotal(). Pengambilan datanya tidak berubah.
class MonitoringCutiController extends Controller
{
    private const STATUS_MENUNGGU = ['menunggu_l1', 'menunggu_l2', 'menunggu_l3', 'menunggu_l4'];
    private const ROLE_ID_KEPALA_BIRO = 6;

    /**
     * @param int $terpakaiEfektif Terpakai yang SUDAH efektif (ikut koreksi bulanan /
     *                             koreksi tahunan), dari SaldoCuti::terpakaiEfektif().
     */
    private function hitungInfoSaldo(
        ?SaldoCuti $saldo,
        ?SaldoCuti $saldoTahunLalu,
        int $terpakaiEfektif,
        int $tangguhTahunIni = 0,
        int $dikembalikan = 0
    ): array {
        $kuota            = (int) ($saldo->kuota_tahunan ?? 12);
        $sisaTahunLalu    = (int) ($saldo->carry_forward_normal ?? 0);
        $sisaDuaTahunLalu = (int) ($saldoTahunLalu->carry_forward_normal ?? 0);

        $terpakai = max(0, $terpakaiEfektif);

        $bawaanEligible = SaldoCuti::hitungBawaan(
            $sisaDuaTahunLalu,
            $sisaTahunLalu,
            $kuota
        );

        // Rumus aritmetika dipusatkan di RincianSaldo::hitungTotal().
        $hasil = RincianSaldo::hitungTotal(
            $kuota,
            (int) $bawaanEligible,
            $dikembalikan,
            $terpakai,
            $tangguhTahunIni
        );

        $terpakaiTotal   = $hasil['cuti_terpakai_total'];
        $sisaHakTahunIni = $hasil['sisa_hak_tahun_berjalan'];
        $totalTersedia   = $hasil['total_cuti_tersedia'];

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
            'opsi_tim_kerja' => $listTimKerja,
        ];
    }

    private const TAHUN_KE_BELAKANG = 2;
    private const TAHUN_KE_DEPAN    = 1;

    private function daftarTahunFilter(): array
    {
        $sekarang = (int) date('Y');

        return range($sekarang + self::TAHUN_KE_DEPAN, $sekarang - self::TAHUN_KE_BELAKANG);
    }

    private function tahunDipilih(Request $request): int
    {
        $tahun = (int) $request->input('tahun');

        return in_array($tahun, $this->daftarTahunFilter(), true) ? $tahun : (int) date('Y');
    }

    private function bangunQueryDasar(Request $request, Pegawai $user)
    {
        $query = PengajuanCuti::with(['pegawai.saldoCutiTahunIni', 'approvalLogs.approver:id,nama,jabatan']);

        // --- BATASAN WILAYAH WAJIB ---
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
            // L3 tidak melihat pengajuan miliknya sendiri maupun milik Kepala Biro.
            $query->whereHas('pegawai', function ($q) use ($user) {
                $q->where('role_id', '!=', self::ROLE_ID_KEPALA_BIRO)
                    ->where('id', '!=', $user->id);
            });
        } elseif (!in_array($user->role_id, [4, 5, 6])) {
            $query->where('id', 0);
        }

        // --- FILTER OPSIONAL DARI DROPDOWN ---
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

        if ($request->filled('status') && $request->status !== '' && $request->status !== 'Semua Status') {
            $stVal = $request->status;
            if ($stVal === 'ditangguhkan') {
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

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('pegawai', function ($q) use ($search) {
                $q->where(function ($cari) use ($search) {
                    $cari->where('nama', 'like', '%' . $search . '%')
                        ->orWhere('nip', 'like', '%' . $search . '%');
                });
            });
        }

        if ($request->filled('jenis_cuti') && $request->jenis_cuti !== '' && $request->jenis_cuti !== 'Semua Jenis Cuti') {
            $query->where('jenis_cuti', $request->jenis_cuti);
        }

        return $query;
    }

    public function index(Request $request)
    {
        $user = Auth::user();

        $tahunFilter    = $this->tahunDipilih($request);
        $awalTahun      = "{$tahunFilter}-01-01";
        $awalTahunDepan = ($tahunFilter + 1) . '-01-01';

        $queryMenunggu = $this->bangunQueryDasar($request, $user)
            ->whereIn('status', self::STATUS_MENUNGGU);

        $priorityStatus = match ($user->role_id) {
            2 => 'menunggu_l1',
            3 => 'menunggu_l2',
            4 => 'menunggu_l3',
            6 => 'menunggu_l4',
            default => null,
        };

        if ($priorityStatus) {
            $queryMenunggu->orderByRaw("CASE WHEN status = ? THEN 0 ELSE 1 END", [$priorityStatus]);
        }

        $menunggu = $queryMenunggu
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        $antrean = $this->bangunQueryDasar($request, $user)
            ->whereNotIn('status', self::STATUS_MENUNGGU)
            ->where('tanggal_mulai', '<', $awalTahunDepan)
            ->where('tanggal_selesai', '>=', $awalTahun)
            // Kepala Biro (L4) tidak melihat riwayat cutinya sendiri di daftar ini
            // (sudah ada di Riwayat Pengajuan miliknya). "Menunggu Persetujuan" tidak diubah.
            ->when($user->role_id === self::ROLE_ID_KEPALA_BIRO, function ($q) use ($user) {
                $q->whereHas('pegawai', fn($p) => $p->where('id', '!=', $user->id));
            })
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate(10)
            ->withQueryString();

        $semuaItem = $menunggu->concat($antrean->getCollection());

        RincianSaldo::lengkapi($menunggu, $tahunFilter);
        RincianSaldo::lengkapi($antrean->getCollection(), $tahunFilter);

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

        // Hanya pejabat AKTIF (status_aktif 'Aktif' / NULL), urut id terkecil.
        // unique() mempertahankan yang PERTAMA, jadi bila ada dua pejabat di
        // tim/kelompok yang sama, yang id-nya terkecil yang tampil (sama dengan
        // RekapKuotaDetailController::getApproverName()).
        $hanyaAktif = fn($q) => $q->where(
            fn($s) => $s->where('status_aktif', 'Aktif')->orWhereNull('status_aktif')
        );

        $petaAtasanL2 = empty($kelompokUnik)
            ? collect()
            : $hanyaAktif(Pegawai::where('role_id', 3)->whereIn('kelompok_substansi', $kelompokUnik))
            ->orderBy('id')
            ->get()
            ->unique('kelompok_substansi')
            ->keyBy('kelompok_substansi');

        $petaAtasanL1 = empty($timKerjaUnik)
            ? collect()
            : $hanyaAktif(Pegawai::where('role_id', 2)->whereIn('tim_kerja', $timKerjaUnik))
            ->orderBy('id')
            ->get()
            ->unique('tim_kerja')
            ->keyBy('tim_kerja');

        $namaPejabatAktif = fn(int $roleId) => Pegawai::where('role_id', $roleId)
            ->where(fn($q) => $q->where('status_aktif', 'Aktif')->orWhereNull('status_aktif'))
            ->orderBy('id')
            ->value('nama');

        $namaAtasanL3 = $namaPejabatAktif(4) ?? 'Kasubag TU';
        $namaAtasanL4 = $namaPejabatAktif(6) ?? 'Kepala Biro Perencanaan';

        $perkaya = function ($item) use ($petaAtasanL1, $petaAtasanL2, $namaAtasanL3, $namaAtasanL4) {
            if (!$item->pegawai) {
                return $item;
            }

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

    public function process(Request $request, int $id)
    {
        $request->validate([
            'action' => ['required', 'in:approve,reject'],
            'alasan' => ['nullable', 'string', 'max:500'],
        ]);

        $user = Auth::user();

        return DB::transaction(function () use ($request, $id, $user) {
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

            if ($user->role_id === 2 && $pengajuan->pegawai->tim_kerja !== $user->tim_kerja) {
                abort(403, 'Pengajuan berada di luar tim kerja Anda.');
            }

            if ($user->role_id === 3 && $pengajuan->pegawai->kelompok_substansi !== $user->kelompok_substansi) {
                abort(403, 'Pengajuan berada di luar kelompok substansi Anda.');
            }

            $levelApproval = [
                'menunggu_l1' => 1,
                'menunggu_l2' => 2,
                'menunggu_l3' => 3,
                'menunggu_l4' => 4,
            ][$statusSaatIni];

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

            $statusPesan = '';

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
            } elseif ($statusSaatIni === 'menunggu_l2' && $user->role_id === 3) {
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
            } elseif ($statusSaatIni === 'menunggu_l3' && $user->role_id === 4) {
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
            } elseif ($statusSaatIni === 'menunggu_l4' && $user->role_id === 6) {
                Notifikasi::where('pegawai_id', $user->id)->where('judul', 'Pengajuan Cuti Baru')->delete();

                $adalahCutiTahunan = $this->isCutiTahunan($pengajuan->jenis_cuti);

                if ($adalahCutiTahunan) {
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

                        // Cuti ditangguhkan (Tgh), aturan SAMA dengan Rekap Kuota Detail:
                        //   - Tgh tahun yang dicek   -> MEMOTONG saldo tahun itu
                        //   - Tgh tahun sebelumnya   -> MENAMBAH total tersedia (dikembalikan)
                        $tghTahunIni = (int) CutiDitangguhkan::where('pegawai_id', $targetUserId)
                            ->where('tahun_asal', $tahunCuti)
                            ->sum('jumlah_hari');
                        $tghTahunLalu = (int) CutiDitangguhkan::where('pegawai_id', $targetUserId)
                            ->where('tahun_asal', $tahunCuti - 1)
                            ->sum('jumlah_hari');

                        // Terpakai EFEKTIF (koreksi bulanan -> koreksi tahunan -> sistem),
                        // satu rumus dengan Rekap Kuota Detail & Rekap Laporan.
                        $infoSaldo = $this->hitungInfoSaldo(
                            $saldoTahun,
                            SaldoCuti::where('pegawai_id', $targetUserId)->where('tahun', $tahunCuti - 1)->first(),
                            SaldoCuti::terpakaiEfektif((int) $targetUserId, (int) $tahunCuti, $saldoTahun),
                            $tghTahunIni,
                            $tghTahunLalu
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

                if ($adalahCutiTahunan) {
                    SaldoCuti::sesuaikanSisaLegacy($pengajuan, -1);
                }

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

    public function history(Request $request)
    {
        $user = Auth::user();
        $query = PengajuanCuti::with(['pegawai.saldoCutiTahunIni']);

        if ($user->role_id === 2) {
            $query->whereHas('pegawai', function ($q) use ($user) {
                $q->where('tim_kerja', $user->tim_kerja)
                    ->where('id', '!=', $user->id);
            })->whereNotIn('status', ['menunggu_l1']);
        } elseif ($user->role_id === 3) {
            $query->whereHas('pegawai', function ($q) use ($user) {
                $q->where('kelompok_substansi', $user->kelompok_substansi)
                    ->where('id', '!=', $user->id);
            })->whereNotIn('status', ['menunggu_l1', 'menunggu_l2']);
        } elseif ($user->role_id === 4) {
            // L3: cuti yang sudah diproses, kecuali milik sendiri dan milik Kepala Biro.
            $query->whereHas('pegawai', function ($q) use ($user) {
                $q->where('role_id', '!=', self::ROLE_ID_KEPALA_BIRO)
                    ->where('id', '!=', $user->id);
            })->whereNotIn('status', ['menunggu_l1', 'menunggu_l2', 'menunggu_l3']);
        } elseif ($user->role_id === 6) {
            // Kepala Biro: semua riwayat diproses, kecuali milik sendiri.
            $query->whereHas('pegawai', function ($q) use ($user) {
                $q->where('id', '!=', $user->id);
            })->whereIn('status', ['disetujui', 'ditolak', 'dibatalkan_reguler', 'dibatalkan_ditangguhkan']);
        } else {
            $query->where('id', 0);
        }

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