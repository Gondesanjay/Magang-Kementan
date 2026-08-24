<?php


namespace App\Http\Controllers;


use App\Models\Pegawai;
use App\Models\PengajuanCuti;
use App\Models\SaldoCuti;
use App\Models\HariLibur;
use App\Exports\RekapCutiExport;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;


class AdminController extends Controller
{
    // ================= PERBAIKAN URUTAN HIERARKI JABATAN =================
    // Mapping role_id -> urutan tampil (angka lebih kecil = tampil lebih atas).
    // Dipakai untuk Rekap Laporan supaya urutan pegawai mengikuti struktur
    // organisasi (jabatan tertinggi ke terendah), BUKAN hardcode nama satu-satu,
    // supaya otomatis menyesuaikan walau ada penambahan/perubahan data pegawai.
    //
    // Urutan hierarki (dari atas ke bawah):
    // role_id 6 -> Kepala Biro Perencanaan (L4 / Approval tertinggi)
    // role_id 4 -> Kasubag TU (L3)
    // role_id 3 -> Ketua Kelompok Substansi (L2)
    // role_id 2 -> Ketua Tim Kerja (L1)
    // role_id 1 -> Pegawai / Staf (paling bawah)
    // role_id lain yang tidak terdaftar di mapping ini akan otomatis
    // ditempatkan paling bawah (lihat fallback ?? 99 di bawah).
    private array $urutanJabatan = [
        6 => 1,
        4 => 2,
        3 => 3,
        2 => 4,
        1 => 5,
    ];
    // ================= END PERBAIKAN URUTAN HIERARKI JABATAN =================


    // 1. Menampilkan Daftar Pegawai
    public function kelolaPegawai()
    {
        $pegawai = Pegawai::orderBy('nama', 'asc')->get();
        return Inertia::render('Admin/KelolaPegawai', [
            'pegawai' => $pegawai
        ]);
    }


    // 2. Menampilkan Daftar Saldo Cuti Pegawai Tahun Ini
    public function kelolaSaldo()
    {
        $tahun = date('Y');


        // Ambil data pegawai beserta data saldo cutinya khusus tahun ini
        $pegawai = Pegawai::with(['saldoCuti' => function ($query) use ($tahun) {
            $query->where('tahun', $tahun);
        }])->orderBy('nama', 'asc')->get();


        return Inertia::render('Admin/KelolaSaldo', [
            'pegawai' => $pegawai,
            'tahun' => $tahun
        ]);
    }


    // 3. Memperbarui atau Membuat Saldo Cuti Baru
    public function updateSaldo(Request $request, $id)
    {
        $request->validate([
            'kuota_tahunan' => ['required', 'numeric', 'min:0'],
            'sisa' => ['required', 'numeric', 'min:0'],
        ]);


        $tahun = date('Y');


        // Update data jika sudah ada, atau buat baru jika belum punya saldo tahun ini
        SaldoCuti::updateOrCreate(
            ['pegawai_id' => $id, 'tahun' => $tahun],
            ['kuota_tahunan' => $request->kuota_tahunan, 'sisa' => $request->sisa]
        );


        return back();
    }


    // ================= HELPER BERSAMA: BANGUN DATA REKAP =================
    // PERBAIKAN: Logic pembuatan data rekap (ambil pegawai, urutkan sesuai
    // hierarki jabatan, hitung cuti tahunan disetujui per bulan) DIPINDAHKAN
    // ke sini, supaya rekapLaporan() (tampilan tabel) dan exportExcel() (file
    // CSV) SELALU memakai data & aturan yang SAMA PERSIS. Sebelumnya kedua
    // method itu punya query sendiri-sendiri yang berbeda, sehingga isi file
    // Excel yang di-export tidak nyambung dengan yang tampil di layar.
    private function buildRekapData(Request $request): array
    {
        $tahun = $request->input('tahun', date('Y'));
        $search = $request->input('search');


        // Ambil data Pegawai (kecuali Admin HR / role_id 5)
        $query = Pegawai::where('role_id', '!=', 5);


        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('nama', 'like', "%{$search}%")
                    ->orWhere('nip', 'like', "%{$search}%");
            });
        }


        $pegawais = $query->get();


        // Urutkan dulu berdasarkan nama (abjad) sebagai tie-breaker,
        // baru urutkan berdasarkan hierarki jabatan (role_id) sebagai kunci utama.
        // PHP sort sudah stable sejak PHP 8, jadi urutan nama di dalam
        // role_id yang sama akan tetap terjaga.
        $pegawais = $pegawais
            ->sortBy('nama')
            ->sortBy(function ($pegawai) {
                // Fallback: role_id yang tidak terdaftar di mapping akan
                // ditempatkan paling bawah (urutan 99).
                return $this->urutanJabatan[$pegawai->role_id] ?? 99;
            })
            ->values();


        $keys = ['jan', 'feb', 'mar', 'apr', 'mei', 'jun', 'jul', 'agu', 'sep', 'okt', 'nov', 'des'];


        $laporan = $pegawais->map(function ($pegawai) use ($tahun, $keys) {
            // Rekap laporan HANYA menghitung Cuti Tahunan yang berstatus
            // disetujui. Jenis cuti lain (Melahirkan, Besar, Alasan Penting)
            // tidak ikut dihitung karena tidak memotong kuota cuti tahunan.
            $cutis = PengajuanCuti::where('pegawai_id', $pegawai->id)
                ->where('jenis_cuti', 'Cuti Tahunan')
                ->whereYear('tanggal_mulai', $tahun)
                ->where('status', 'disetujui')
                ->get();


            $rekapBulan = array_fill_keys($keys, 0);


            foreach ($cutis as $cuti) {
                $bulanAngka = (int) date('n', strtotime($cuti->tanggal_mulai));
                $keyBulan = $keys[$bulanAngka - 1];
                $rekapBulan[$keyBulan] += $cuti->jumlah_hari;
            }


            return [
                'id' => $pegawai->id,
                'nama' => $pegawai->nama,
                'nip' => $pegawai->nip,
                'departemen' => $pegawai->departemen,
                'cuti' => $rekapBulan,
                'total' => array_sum($rekapBulan),
            ];
        });


        // Dropdown "Bulan" HANYA mempersempit kolom yang ditampilkan/di-export,
        // BUKAN menyaring baris pegawai. Semua pegawai tetap muncul walau
        // nilainya 0 di bulan tersebut — supaya nomor urut konsisten dan
        // laporan tetap menunjukkan "bukti negatif" (siapa yang TIDAK cuti),
        // sesuai konvensi laporan resmi instansi pemerintah.


        return [
            'tahun' => (int) $tahun,
            'laporan' => $laporan,
        ];
    }
    // ================= END HELPER BERSAMA =================


    // 4. Menampilkan Rekap Laporan Cuti Seluruh Pegawai (Rekap Per Bulan)
    // Menampilkan total hari cuti disetujui per pegawai, dipecah per bulan (Jan-Des),
    // dengan dukungan filter tahun, pencarian nama/NIP, dan filter bulan.
    //
    // Urutan pegawai mengikuti HIERARKI JABATAN (role_id), dari jabatan
    // tertinggi ke terendah — bukan abjad A-Z. Ini lebih sesuai untuk laporan
    // resmi instansi pemerintah yang biasanya dibaca berdasarkan struktur
    // organisasi. Di dalam jabatan yang sama, tetap diurutkan abjad sebagai
    // urutan kedua (tie-breaker).
    public function rekapLaporan(Request $request)
    {
        $data = $this->buildRekapData($request);


        return Inertia::render('Admin/RekapLaporan', [
            'laporan' => $data['laporan'],
            'tahun' => $data['tahun'],
            'filters' => $request->only(['search', 'tahun', 'bulan']),
        ]);
    }


    // 5. Export Data Rekap Cuti ke Excel (.xlsx)
    // Memakai buildRekapData() yang SAMA dengan rekapLaporan(), jadi kolom,
    // urutan pegawai, filter (search/tahun/bulan), dan aturan (Cuti Tahunan +
    // disetujui saja) di file Excel SELALU sama persis dengan yang tampil
    // di layar.
    //
    // PERBAIKAN: sebelumnya file yang di-export berformat CSV (bukan Excel
    // asli). Sekarang memakai package Laravel Excel (maatwebsite/excel) yang
    // menghasilkan file .xlsx sungguhan lewat class App\Exports\RekapCutiExport.
    // Pastikan package sudah terpasang: composer require maatwebsite/excel
    public function exportExcel(Request $request)
    {
        $data = $this->buildRekapData($request);
        $tahun = $data['tahun'];
        $laporan = $data['laporan'];


        $bulanFilter = $request->input('bulan');


        $bulanList = [
            'Januari',
            'Februari',
            'Maret',
            'April',
            'Mei',
            'Juni',
            'Juli',
            'Agustus',
            'September',
            'Oktober',
            'November',
            'Desember',
        ];
        $keyBulan = [
            'jan',
            'feb',
            'mar',
            'apr',
            'mei',
            'jun',
            'jul',
            'agu',
            'sep',
            'okt',
            'nov',
            'des',
        ];


        // Tentukan kolom bulan yang di-export: kalau ada filter bulan aktif,
        // export cuma 1 kolom bulan itu (samakan dengan tampilan layar yang
        // memakai computed columnsToShow); kalau tidak, export 12 kolom.
        if ($bulanFilter && isset($keyBulan[((int) $bulanFilter) - 1])) {
            $index = ((int) $bulanFilter) - 1;
            $kolomBulan = [
                ['label' => $bulanList[$index], 'key' => $keyBulan[$index]],
            ];
        } else {
            $kolomBulan = array_map(
                fn($label, $key) => ['label' => $label, 'key' => $key],
                $bulanList,
                $keyBulan
            );
        }


        // Susun baris header: No, Nama Pegawai, NIP, [Bulan...], Total Cuti
        $headings = ['No', 'Nama Pegawai', 'NIP'];
        foreach ($kolomBulan as $bulan) {
            $headings[] = $bulan['label'];
        }
        $headings[] = 'Total Cuti';


        // Susun baris data: sama persis dengan yang tampil di tabel matriks
        $rows = [];
        foreach ($laporan as $index => $item) {
            $row = [
                $index + 1,
                $item['nama'],
                $item['nip'],
            ];
            foreach ($kolomBulan as $bulan) {
                $row[] = $item['cuti'][$bulan['key']] ?? 0;
            }
            $row[] = $item['total'];
            $rows[] = $row;
        }


        $filename = "Rekap_Cuti_Tahunan_{$tahun}_" . date('Y-m-d') . ".xlsx";


        return Excel::download(new RekapCutiExport($headings, $rows), $filename);
    }


    // 6. Fungsi Menangguhkan Cuti (Oleh Admin/L3)
    public function suspendCuti(Request $request, $id)
    {
        abort_unless(auth()->user()->role_id === 6, 403, 'Hanya L4 yang dapat menangguhkan cuti.');


        $request->validate([
            'alasan' => ['required', 'string', 'max:255']
        ]);


        $pengajuan = PengajuanCuti::findOrFail($id);


        // Hanya cuti yang sudah disetujui yang bisa ditangguhkan
        if ($pengajuan->status !== 'disetujui') {
            return back()->with('error', 'Hanya cuti yang telah disetujui yang dapat ditangguhkan.');
        }


        // Kembalikan Saldo Cuti Pegawai (Berdasarkan tahun cuti tersebut)
        $tahunCuti = date('Y', strtotime($pengajuan->tanggal_mulai));
        $saldo = SaldoCuti::where('pegawai_id', $pengajuan->pegawai_id)
            ->where('tahun', $tahunCuti)
            ->first();


        if ($saldo) {
            $saldo->update([
                'sisa' => $saldo->sisa + $pengajuan->jumlah_hari
            ]);
        }


        // Sisipkan alasan penangguhan ke kolom keterangan yang sudah ada
        $keteranganBaru = $pengajuan->keterangan . ' | [DITANGGUHKAN: ' . $request->alasan . ']';


        // Update status menjadi dibatalkan_ditangguhkan
        $pengajuan->update([
            'status' => 'dibatalkan_ditangguhkan',
            'keterangan' => $keteranganBaru
        ]);


        return back()->with('success', 'Cuti berhasil ditangguhkan dan saldo telah dikembalikan.');
    }


    // 7. Menampilkan Halaman Kelola Hari Libur
    public function kelolaLibur()
    {
        // HANYA MENAMPILKAN LIBUR MULAI HARI INI KE DEPAN, MAKSIMAL 7 BARIS
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


            if (empty(array_filter($row, fn($value) => $value !== ''))) {
                continue;
            }


            $firstCell = strtolower($row[0] ?? '');
            if (!$headerSkipped && in_array($firstCell, ['tanggal', 'date', 'tgl'])) {
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
    public function destroyLibur($id)
    {
        HariLibur::findOrFail($id)->delete();
        return back();
    }
}
