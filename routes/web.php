<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\MonitoringCutiController;
use App\Http\Controllers\RekapKuotaDetailController;
use App\Http\Controllers\CutiController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PembatalanController;
use App\Http\Controllers\NotificationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return redirect()->route('login');
});

// 1. Rute Ganti Password Pertama Kali (hanya 'auth')
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

// 2. Rute Utama Aplikasi (butuh 'auth' + 'force.password')
Route::middleware(['auth', 'force.password'])->group(function () {

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // --- Notifikasi ---
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead'])->name('notifications.readAll');
    Route::delete('/notifications/clear-all', [NotificationController::class, 'destroyAll'])->name('notifications.destroyAll');
    Route::delete('/notifikasi/{id}', [NotificationController::class, 'destroy'])->name('notifikasi.destroy');

    // ==========================================
    // Modul Karyawan
    // ==========================================
    Route::get('/karyawan/ajukan-cuti', [CutiController::class, 'create'])->name('karyawan.ajukan');
    Route::post('/karyawan/ajukan-cuti', [CutiController::class, 'store'])->name('karyawan.ajukan.store');
    Route::get('/karyawan/riwayat-cuti', [CutiController::class, 'history'])->name('karyawan.riwayat');
    Route::post('/karyawan/riwayat-cuti/{id}/batal', [CutiController::class, 'cancel'])->name('karyawan.cuti.batal');
    Route::post('/karyawan/cuti/{id}/batalkan-mandiri', [CutiController::class, 'batalkanMandiri'])->name('karyawan.cuti.batalkan-mandiri');
    Route::post('/karyawan/cuti/{id}/revisi', [CutiController::class, 'revisi'])->name('karyawan.cuti.revisi');
    Route::get('/karyawan/kalender-tim', [CutiController::class, 'teamCalendar'])->name('karyawan.kalender');
    Route::get('/karyawan/riwayat-cuti/{id}/pdf', [CutiController::class, 'downloadPdf'])->name('karyawan.cuti.pdf');

    // ==========================================
    // Modul Atasan
    // ==========================================
    // Controller: MonitoringCutiController (ex ApprovalController)
    // Nama route 'atasan.approval' & 'atasan.approval.process' sengaja tetap
    // supaya tidak mematahkan link di MainLayout.vue dan notifikasi.
    Route::get('/atasan/antrean-approval', [MonitoringCutiController::class, 'index'])->name('atasan.approval');
    Route::post('/atasan/antrean-approval/{id}', [MonitoringCutiController::class, 'process'])->name('atasan.approval.process');

    // Quick Approve / Reject
    Route::post('/atasan/approval/{id}/approve', [MonitoringCutiController::class, 'approve'])->name('atasan.approval.approve');
    Route::post('/atasan/approval/{id}/reject', [MonitoringCutiController::class, 'reject'])->name('atasan.approval.reject');

    // Penangguhan Cuti (ex pembatalan)
    Route::get('/atasan/penangguhan-cuti', [PembatalanController::class, 'index'])->name('atasan.penangguhan');
    Route::post('/atasan/penangguhan-cuti/{id}', [PembatalanController::class, 'process'])->name('atasan.penangguhan.process');

    // Rekap Kuota Pegawai (Atasan) — read-only, tanpa Impor & tanpa Export
    Route::get('/atasan/rekap-kuota', [RekapKuotaDetailController::class, 'indexAtasan'])->name('atasan.kuota');

    // ==========================================
    // Modul Admin HR
    // ==========================================
    Route::get('/admin/pegawai', [AdminController::class, 'kelolaPegawai'])->name('admin.pegawai');
    Route::post('/admin/pegawai', [AdminController::class, 'storePegawai'])->name('admin.pegawai.store');
    Route::put('/admin/pegawai/{id}', [AdminController::class, 'updatePegawai'])->name('admin.pegawai.update');
    Route::post('/admin/pegawai/import', [AdminController::class, 'importPegawai'])->name('admin.pegawai.import');
    Route::post('/admin/pegawai/{id}/reset-password', [AdminController::class, 'resetPasswordPegawai'])->name('admin.pegawai.reset-password');
    Route::delete('/admin/pegawai/{id}', [AdminController::class, 'destroyPegawai'])->name('admin.pegawai.destroy');

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
    Route::post('/admin/pengajuan-cuti/{id}/tangguhkan', [AdminController::class, 'suspendCuti'])->name('admin.cuti.tangguhkan');

    Route::get('/admin/hari-libur', [AdminController::class, 'kelolaLibur'])->name('admin.libur');
    Route::post('/admin/hari-libur', [AdminController::class, 'storeLibur'])->name('admin.libur.store');
    Route::post('/admin/hari-libur/import', [AdminController::class, 'importLiburCsv'])->name('admin.libur.import');
    Route::delete('/admin/hari-libur/{id}', [AdminController::class, 'destroyLibur'])->name('admin.libur.destroy');

    // Profile (Breeze)
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__ . '/auth.php';
