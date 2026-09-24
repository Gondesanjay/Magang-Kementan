<?php


use App\Http\Controllers\AdminController;
use App\Http\Controllers\MonitoringCutiController; // <-- Hasil rename dari ApprovalController (Tahap 2)
use App\Http\Controllers\RekapKuotaDetailController; // <-- Tambahan untuk Rekap Kuota Detail
use App\Http\Controllers\CutiController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PembatalanController;
use App\Http\Controllers\NotificationController; // <-- Tambahan untuk Notifikasi
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;


Route::get('/', function () {
    return redirect()->route('login');
});


// 1. Rute Khusus untuk Ganti Password Pertama Kali (Hanya butuh 'auth')
Route::middleware('auth')->group(function () {


    Route::get('/ganti-password', function () {
        return Inertia::render('Auth/GantiPassword');
    })->name('password.change');


    Route::post('/ganti-password', function (Request $request) {
        $request->validate([
            'password' => ['required', 'confirmed', 'min:8'],
        ]);


        $request->user()->update([
            'password' => Hash::make($request->password),
            'is_first_login' => false,
        ]);


        return redirect()->route('dashboard');
    })->name('password.change.store');
});


// 2. Rute Utama Aplikasi (Butuh 'auth' DAN 'force.password')
Route::middleware(['auth', 'force.password'])->group(function () {


    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');


    // --- RUTE NOTIFIKASI ---
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead'])->name('notifications.readAll');
    Route::delete('/notifikasi/{id}', [NotificationController::class, 'destroy'])->name('notifikasi.destroy');


    // ==========================================
    // Rute Modul Karyawan
    // ==========================================
    Route::get('/karyawan/ajukan-cuti', [CutiController::class, 'create'])->name('karyawan.ajukan');
    Route::post('/karyawan/ajukan-cuti', [CutiController::class, 'store'])->name('karyawan.ajukan.store');
    Route::get('/karyawan/riwayat-cuti', [CutiController::class, 'history'])->name('karyawan.riwayat');


    // Karyawan membatalkan pengajuan cutinya sendiri (Antrean)
    Route::post('/karyawan/riwayat-cuti/{id}/batal', [CutiController::class, 'cancel'])->name('karyawan.cuti.batal');


    // Karyawan membatalkan pengajuan cuti mandiri (Sudah Disetujui)
    Route::post('/karyawan/cuti/{id}/batalkan-mandiri', [CutiController::class, 'batalkanMandiri'])->name('karyawan.cuti.batalkan-mandiri');


    // Karyawan merevisi cuti yang ditangguhkan
    Route::post('/karyawan/cuti/{id}/revisi', [CutiController::class, 'revisi'])->name('karyawan.cuti.revisi');


    Route::get('/karyawan/kalender-tim', [CutiController::class, 'teamCalendar'])->name('karyawan.kalender');
    Route::get('/karyawan/riwayat-cuti/{id}/pdf', [CutiController::class, 'downloadPdf'])->name('karyawan.cuti.pdf');


    // ==========================================
    // Rute Modul Atasan
    // ==========================================
    // PERBAIKAN (Tahap 2 selesai): controller diganti dari
    // ApprovalController -> MonitoringCutiController, karena label menu di
    // sidebar untuk fitur approve/reject L1-L4 ini sekarang "Monitoring Cuti"
    // (lihat MainLayout.vue).
    //
    // URL & NAMA ROUTE SENGAJA DIBIARKAN SAMA PERSIS ('atasan.approval',
    // 'atasan.approval.process', dst) — TIDAK diganti jadi
    // 'atasan.approval.index' meskipun sempat direncanakan begitu, karena:
    // 1. MainLayout.vue memakai route('atasan.approval') & isActive('atasan.approval')
    //    untuk link + highlight menu "Monitoring Cuti" — akan patah kalau nama
    //    route berubah.
    // 2. Di dalam MonitoringCutiController.php sendiri ada beberapa
    //    route('atasan.approval') dipakai untuk membangun link notifikasi
    //    saat pengajuan naik ke level approval berikutnya.
    // Route 'atasan.approval.process' juga TETAP dipertahankan (bukan dihapus)
    // karena method process() di controller masih dipakai untuk approval biasa,
    // terpisah dari route Quick Approve di bawahnya.
    //
    // CATATAN DIAGNOSTIK: rute 'atasan.approval.process' di bawah ini SUDAH
    // terdaftar dan menunjuk ke MonitoringCutiController::process(). Kalau
    // muncul error "Route [atasan.approval.process] not defined" di
    // Boss punya server padahal baris ini sudah ada, itu HAMPIR PASTI karena
    // route cache lama (jalankan `php artisan route:clear`) atau file
    // web.php yang aktif di server belum ditimpa dengan versi ini — BUKAN
    // karena rute ini kurang/salah nama.
    Route::get('/atasan/antrean-approval', [MonitoringCutiController::class, 'index'])->name('atasan.approval');
    Route::post('/atasan/antrean-approval/{id}', [MonitoringCutiController::class, 'process'])->name('atasan.approval.process');


    // ---> RUTE QUICK APPROVE <---
    Route::post('/atasan/approval/{id}/approve', [MonitoringCutiController::class, 'approve'])->name('atasan.approval.approve');
    Route::post('/atasan/approval/{id}/reject', [MonitoringCutiController::class, 'reject'])->name('atasan.approval.reject');


    // ---> PERBAIKAN <---
    // Nama route & URL diganti dari 'atasan.pembatalan' (/atasan/pembatalan-cuti)
    // menjadi 'atasan.penangguhan' (/atasan/penangguhan-cuti), agar konsisten
    // dengan label menu "Penangguhan Cuti" di MainLayout.vue dan pemanggilan
    // route('atasan.penangguhan.process', ...) di PembatalanCuti.vue.
    // Controller TIDAK diganti (tetap PembatalanController), karena isi
    // logic-nya (index() & process()) tidak berubah, hanya penamaan route
    // yang disesuaikan.
    Route::get('/atasan/penangguhan-cuti', [PembatalanController::class, 'index'])->name('atasan.penangguhan');
    Route::post('/atasan/penangguhan-cuti/{id}', [PembatalanController::class, 'process'])->name('atasan.penangguhan.process');


    // ---> BARU: Rekap Kuota Cuti Pegawai (versi Atasan) <---
    // Ini adalah versi "read-only" dari Rekap Kuota Detail milik Admin HR
    // (lihat 'admin.monitoring' di bawah), khusus untuk atasan L1-L4
    // (role 2,3,4,6) — TANPA fitur "Impor Kuota". Controller-nya TETAP
    // RekapKuotaDetailController (bukan controller baru), memakai method
    // baru indexAtasan()/exportExcelAtasan() yang reuse semua query &
    // perhitungan kuota yang sama dengan versi Admin, cuma beda komponen
    // Vue yang dirender ('Atasan/RekapKuotaPegawai').
    //
    // URL & nama route sengaja dibuat baru & terpisah dari 'admin.monitoring'
    // (bukan dipakai bareng), supaya lebih gampang nanti kalau mau dipasangi
    // middleware role yang berbeda antara Admin HR & Atasan.
    Route::get('/atasan/rekap-kuota', [RekapKuotaDetailController::class, 'indexAtasan'])->name('atasan.kuota');
    Route::get('/atasan/rekap-kuota/export', [RekapKuotaDetailController::class, 'exportExcelAtasan'])->name('atasan.kuota.export');


    // ==========================================
    // Rute Modul Admin HR
    // ==========================================
    Route::get('/admin/pegawai', [AdminController::class, 'kelolaPegawai'])->name('admin.pegawai');


    // ---> RUTE PEGAWAI: Tambah, Edit, & Reset Password <---
    Route::post('/admin/pegawai', [AdminController::class, 'storePegawai'])->name('admin.pegawai.store');
    Route::put('/admin/pegawai/{id}', [AdminController::class, 'updatePegawai'])->name('admin.pegawai.update');


    // ---> BARU: Impor Data Pegawai dari Excel/CSV <---
    Route::post('/admin/pegawai/import', [AdminController::class, 'importPegawai'])->name('admin.pegawai.import');


    // ---> Admin HR reset password pegawai yang lupa sandi <---
    // Password dikembalikan ke default 'password123' + is_first_login diset true,
    // sehingga pegawai WAJIB mengganti password saat login berikutnya
    // (memakai alur /ganti-password yang sudah ada di atas).
    Route::post('/admin/pegawai/{id}/reset-password', [AdminController::class, 'resetPasswordPegawai'])->name('admin.pegawai.reset-password');


    // ---> BARU: Admin HR menghapus data pegawai <---
    // Method destroyPegawai() ditaruh di AdminController (bukan controller
    // baru Admin\PegawaiController), supaya konsisten dengan
    // storePegawai/updatePegawai/resetPasswordPegawai di atas yang semuanya
    // memakai controller yang sama untuk modul Kelola Pegawai.
    Route::delete('/admin/pegawai/{id}', [AdminController::class, 'destroyPegawai'])->name('admin.pegawai.destroy');
    Route::get('/admin/saldo-cuti', [AdminController::class, 'kelolaSaldo'])->name('admin.saldo');


    // ================= [BARU] GENERATE SALDO CUTI TAHUN BARU (MANUAL) =================
    // Dipanggil dari tombol "Generate Saldo Tahun Baru (Manual)" di halaman
    // Admin/RekapKuotaDetail.vue. Method generateSaldoManual() di
    // AdminController hanya memanggil Artisan Command
    // 'saldo-cuti:generate-tahun-baru' — command YANG SAMA PERSIS dengan
    // yang dijalankan otomatis oleh Laravel Scheduler setiap 1 Januari
    // (lihat routes/console.php), supaya hasil generate manual dan
    // otomatis selalu identik.
    //
    // PENTING — URUTAN ROUTE: baris ini WAJIB didaftarkan SEBELUM route
    // '/admin/saldo-cuti/{id}' (admin.saldo.update) di bawah. Laravel
    // mencocokkan route dari atas ke bawah dan berhenti di kecocokan
    // PERTAMA. Kalau '/admin/saldo-cuti/{id}' didaftarkan lebih dulu, maka
    // POST ke '/admin/saldo-cuti/generate' akan tertangkap oleh pola
    // '{id}' tsb (dengan $id diisi string "generate"), menyebabkan
    // TypeError pada updateSaldo(Request $request, int $id) karena $id
    // wajib bertipe int. Riwayat bug ini sudah pernah terjadi persis
    // seperti itu — JANGAN pindahkan baris ini ke bawah route {id} lagi.
    Route::post('/admin/saldo-cuti/generate', [AdminController::class, 'generateSaldoManual'])->name('admin.saldo.generate');
    // ================= [END BARU] =================


    Route::post('/admin/saldo-cuti/{id}', [AdminController::class, 'updateSaldo'])->name('admin.saldo.update');


    // <--- Rute Rekap Kuota Detail, diletakkan sebelum Rekap Laporan
    //      sesuai urutan menu di sidebar (MainLayout.vue) --->
    // PERBAIKAN (Tahap 1 selesai): controller diganti dari
    // MonitoringCutiController -> RekapKuotaDetailController, dan method
    // export diganti dari 'export' (CSV yang menyamar jadi .xlsx) menjadi
    // 'exportExcel' (file .xlsx asli lewat PhpSpreadsheet, dengan badge
    // warna jabatan, header wrap, legenda, dan formula SUM).
    //
    // URL & nama route SENGAJA dibiarkan sama ('/admin/monitoring',
    // 'admin.monitoring') supaya link yang sudah ada di MainLayout.vue /
    // tempat lain tidak langsung patah. Kalau nanti mau URL & nama route-nya
    // juga disamakan dengan nama fitur ("Rekap Kuota Detail"), itu bisa jadi
    // langkah lanjutan terpisah — cukup ganti path & ->name() di bawah ini,
    // lalu sesuaikan semua pemanggilan route('admin.monitoring...') di Vue.
    //
    // Route ini KHUSUS Admin HR (role 5) — versi untuk Atasan (role 2,3,4,6)
    // ada terpisah di atas: 'atasan.kuota' / 'atasan.kuota.export', karena
    // halaman Atasan tidak menampilkan fitur "Impor Kuota".
    Route::get('/admin/monitoring', [RekapKuotaDetailController::class, 'index'])->name('admin.monitoring');
    Route::get('/admin/monitoring/export', [RekapKuotaDetailController::class, 'exportExcel'])->name('admin.monitoring.export');
    Route::put('/admin/rekap-kuota/{id}/update-saldo', [RekapKuotaDetailController::class, 'updateSaldo'])->name('admin.rekap-kuota.update-saldo');


    Route::get('/admin/rekap-laporan', [AdminController::class, 'rekapLaporan'])->name('admin.rekap');
    Route::get('/admin/rekap-laporan/export', [AdminController::class, 'exportExcel'])->name('admin.rekap.export');
    Route::post('/admin/pengajuan-cuti/{id}/tangguhkan', [AdminController::class, 'suspendCuti'])->name('admin.cuti.tangguhkan');
    Route::get('/admin/hari-libur', [AdminController::class, 'kelolaLibur'])->name('admin.libur');
    Route::post('/admin/hari-libur', [AdminController::class, 'storeLibur'])->name('admin.libur.store');
    Route::post('/admin/hari-libur/import', [AdminController::class, 'importLiburCsv'])->name('admin.libur.import');
    Route::delete('/admin/hari-libur/{id}', [AdminController::class, 'destroyLibur'])->name('admin.libur.destroy');


    // Rute Profile Bawaan Breeze
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});


require __DIR__ . '/auth.php';
