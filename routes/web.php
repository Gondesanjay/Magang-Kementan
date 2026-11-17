<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\MonitoringCutiController;
use App\Http\Controllers\RekapKuotaDetailController;
use App\Http\Controllers\CutiController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FcmTokenController;
use App\Http\Controllers\LampiranController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PembatalanController;
use App\Http\Controllers\NotificationController;
use App\Http\Middleware\PreventBackHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;


Route::get('/', function () {
    return redirect()->route('login');
});


// 1. Rute Ganti Password Pertama Kali ('auth' + PreventBackHistory)
Route::middleware(['auth', PreventBackHistory::class])->group(function () {

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

// 2. Rute Utama Aplikasi (butuh 'auth' + 'force.password' + PreventBackHistory)
// PreventBackHistory: melarang browser menyimpan halaman di cache Back/Forward,
// sehingga setelah logout tombol "Back" tidak menampilkan halaman lama.
Route::middleware(['auth', 'force.password', PreventBackHistory::class])->group(function () {

    // ==========================================
    // UMUM: semua pegawai yang sudah login
    // ==========================================
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Lampiran cuti (disk private) — hak akses dicek di controller
    Route::get('/lampiran/{id}', [LampiranController::class, 'unduh'])
        ->whereNumber('id')
        ->name('lampiran.unduh');

    // --- Notifikasi ---
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead'])->name('notifications.readAll');
    Route::delete('/notifications/clear-all', [NotificationController::class, 'destroyAll'])->name('notifications.destroyAll');
    Route::delete('/notifikasi/{id}', [NotificationController::class, 'destroy'])
        ->whereNumber('id')
        ->name('notifikasi.destroy');

    // Simpan token perangkat untuk Web Push (FCM)
    Route::post('/fcm-token', [FcmTokenController::class, 'store'])
        ->middleware('throttle:60,1')
        ->name('fcm.store');
    Route::delete('/fcm-token', [FcmTokenController::class, 'destroy'])
        ->middleware('throttle:20,1')
        ->name('fcm.destroy');

    // Profile (Breeze)
    // CATATAN: route DELETE /profile (ProfileController@destroy, name: profile.destroy)
    // SENGAJA tidak didaftarkan karena memungkinkan pegawai menghapus akunnya
    // sendiri. Kalau suatu saat dibutuhkan lagi, tambahkan:
    //   Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    // (dan pastikan tombol hapus akun di Profile/Edit.vue juga ikut dikembalikan).
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');

    // ==========================================
    // Modul Karyawan — semua role yang sudah login
    // (atasan juga mengajukan cuti sendiri; kepemilikan data dicek di controller)
    // ==========================================
    Route::where(['id' => '[0-9]+'])->group(function () {
        Route::get('/karyawan/ajukan-cuti', [CutiController::class, 'create'])->name('karyawan.ajukan');
        Route::post('/karyawan/ajukan-cuti', [CutiController::class, 'store'])->name('karyawan.ajukan.store');
        Route::get('/karyawan/riwayat-cuti', [CutiController::class, 'history'])->name('karyawan.riwayat');
        Route::post('/karyawan/riwayat-cuti/{id}/batal', [CutiController::class, 'cancel'])->name('karyawan.cuti.batal');
        Route::post('/karyawan/cuti/{id}/batalkan-mandiri', [CutiController::class, 'batalkanMandiri'])->name('karyawan.cuti.batalkan-mandiri');
        Route::post('/karyawan/cuti/{id}/revisi', [CutiController::class, 'revisi'])->name('karyawan.cuti.revisi');
        Route::get('/karyawan/kalender-tim', [CutiController::class, 'teamCalendar'])->name('karyawan.kalender');
        Route::get('/karyawan/riwayat-cuti/{id}/pdf', [CutiController::class, 'downloadPdf'])->name('karyawan.cuti.pdf');
    });

    // ==========================================
    // Monitoring Cuti — LIHAT: atasan (2,3,4,6) + Admin HR (5)
    // Admin hanya boleh MELIHAT. Aksi mengubah data ada di grup berikutnya
    // dan tetap khusus atasan, supaya Admin tidak ikut menyetujui cuti.
    // ==========================================
    // Controller: MonitoringCutiController (ex ApprovalController)
    // Nama route 'atasan.approval' & 'atasan.approval.process' sengaja tetap
    // supaya tidak mematahkan link di MainLayout.vue dan notifikasi.
    Route::middleware('role:2,3,4,5,6')->where(['id' => '[0-9]+'])->group(function () {
        Route::get('/atasan/antrean-approval', [MonitoringCutiController::class, 'index'])->name('atasan.approval');
    });

    // ==========================================
    // Modul Atasan — aksi memproses: HANYA role 2, 3, 4, 6
    // Pegawai biasa (1) dan Admin HR (5) ditolak dengan 403.
    // ==========================================
    Route::middleware('role:2,3,4,6')->where(['id' => '[0-9]+'])->group(function () {
        Route::post('/atasan/antrean-approval/{id}', [MonitoringCutiController::class, 'process'])->name('atasan.approval.process');

        // Quick Approve / Reject
        Route::post('/atasan/approval/{id}/approve', [MonitoringCutiController::class, 'approve'])->name('atasan.approval.approve');
        Route::post('/atasan/approval/{id}/reject', [MonitoringCutiController::class, 'reject'])->name('atasan.approval.reject');

        // Rekap Kuota Pegawai (Atasan) — read-only, tanpa Impor & tanpa Export
        Route::get('/atasan/rekap-kuota', [RekapKuotaDetailController::class, 'indexAtasan'])->name('atasan.kuota');
    });

    // ==========================================
    // Penangguhan cuti oleh Kepala Biro — HANYA role 6
    // Termasuk AdminController::suspendCuti() yang mensyaratkan role 6 (L4),
    // bukan Admin HR, sehingga ditaruh di grup ini dan bukan di grup Admin.
    // ==========================================
    Route::middleware('role:6')->where(['id' => '[0-9]+'])->group(function () {
        Route::get('/atasan/penangguhan-cuti', [PembatalanController::class, 'index'])->name('atasan.penangguhan');
        Route::post('/atasan/penangguhan-cuti/{id}', [PembatalanController::class, 'process'])->name('atasan.penangguhan.process');
        Route::post('/admin/pengajuan-cuti/{id}/tangguhkan', [AdminController::class, 'suspendCuti'])->name('admin.cuti.tangguhkan');
    });

    // ==========================================
    // Modul Admin HR — HANYA role 5
    // Semua method di sini (termasuk ubah role, reset password, impor)
    // tertutup untuk role lain, baik GET maupun POST/PUT/DELETE.
    // ==========================================
    Route::middleware('role:5')->where(['id' => '[0-9]+'])->group(function () {
        Route::get('/admin/pegawai', [AdminController::class, 'kelolaPegawai'])->name('admin.pegawai');
        Route::post('/admin/pegawai', [AdminController::class, 'storePegawai'])->name('admin.pegawai.store');
        Route::put('/admin/pegawai/{id}', [AdminController::class, 'updatePegawai'])->name('admin.pegawai.update');
        Route::post('/admin/pegawai/import', [AdminController::class, 'importPegawai'])->name('admin.pegawai.import');
        Route::post('/admin/pegawai/{id}/reset-password', [AdminController::class, 'resetPasswordPegawai'])->name('admin.pegawai.reset-password');
        Route::delete('/admin/pegawai/{id}', [AdminController::class, 'destroyPegawai'])->name('admin.pegawai.destroy');

        // Hapus massal (checkbox di KelolaPegawai.vue)
        // Path statis, tidak bentrok dengan {id} karena constraint [0-9]+
        Route::delete('/admin/pegawai/destroy-massal', [AdminController::class, 'destroyMassal'])
            ->name('admin.pegawai.destroy-massal');

        Route::get('/admin/saldo-cuti', [AdminController::class, 'kelolaSaldo'])->name('admin.saldo');

        // Generate Saldo Tahun Baru (manual) — WAJIB di atas route {id}
        Route::post('/admin/saldo-cuti/generate', [AdminController::class, 'generateSaldoManual'])->name('admin.saldo.generate');
        Route::post('/admin/saldo-cuti/{id}', [AdminController::class, 'updateSaldo'])->name('admin.saldo.update');

        // Rekap Kuota Detail (khusus Admin HR — role 5)
        // Termasuk Export Excel & Impor Kuota
        Route::get('/admin/monitoring', [RekapKuotaDetailController::class, 'index'])->name('admin.monitoring');
        Route::get('/admin/monitoring/export', [RekapKuotaDetailController::class, 'exportExcel'])->name('admin.monitoring.export');
        Route::post('/admin/monitoring/import', [RekapKuotaDetailController::class, 'importKuota'])->name('admin.monitoring.import');
        Route::put('/admin/rekap-kuota/{id}/update-saldo', [RekapKuotaDetailController::class, 'updateSaldo'])->name('admin.rekap-kuota.update-saldo');

        Route::get('/admin/rekap-laporan', [AdminController::class, 'rekapLaporan'])->name('admin.rekap');
        Route::get('/admin/rekap-laporan/export', [AdminController::class, 'exportExcel'])->name('admin.rekap.export');
        Route::post('/admin/rekap-laporan/{id}/koreksi', [AdminController::class, 'updateKoreksiLaporan'])->name('admin.rekap.koreksi');

        Route::get('/admin/hari-libur', [AdminController::class, 'kelolaLibur'])->name('admin.libur');
        Route::post('/admin/hari-libur', [AdminController::class, 'storeLibur'])->name('admin.libur.store');
        Route::post('/admin/hari-libur/import', [AdminController::class, 'importLiburCsv'])->name('admin.libur.import');
        Route::delete('/admin/hari-libur/{id}', [AdminController::class, 'destroyLibur'])->name('admin.libur.destroy');
    });
});

require __DIR__ . '/auth.php';
