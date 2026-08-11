<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\ApprovalController;
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
    
    // Rute Modul Karyawan
    Route::get('/karyawan/ajukan-cuti', [CutiController::class, 'create'])->name('karyawan.ajukan');
    Route::post('/karyawan/ajukan-cuti', [CutiController::class, 'store'])->name('karyawan.ajukan.store');
    Route::get('/karyawan/riwayat-cuti', [CutiController::class, 'history'])->name('karyawan.riwayat');

    // Rute Baru: Karyawan membatalkan pengajuan cutinya sendiri
    Route::post('/karyawan/riwayat-cuti/{id}/batal', [CutiController::class, 'cancel'])->name('karyawan.cuti.batal');

    Route::get('/karyawan/kalender-tim', [CutiController::class, 'teamCalendar'])->name('karyawan.kalender');
    Route::get('/karyawan/riwayat-cuti/{id}/pdf', [CutiController::class, 'downloadPdf'])->name('karyawan.cuti.pdf');

    // ==========================================
    // Rute Modul Atasan
    // ==========================================
    Route::get('/atasan/antrean-approval', [ApprovalController::class, 'index'])->name('atasan.approval');
    Route::post('/atasan/antrean-approval/{id}', [ApprovalController::class, 'process'])->name('atasan.approval.process');
    
    // ---> RUTE QUICK APPROVE (SUDAH DIPINDAHKAN KE DALAM SINI) <---
    Route::post('/atasan/approval/{id}/approve', [ApprovalController::class, 'approve'])->name('atasan.approval.approve');
    Route::post('/atasan/approval/{id}/reject', [ApprovalController::class, 'reject'])->name('atasan.approval.reject');
    
    Route::get('/atasan/pembatalan-cuti', [PembatalanController::class, 'index'])->name('atasan.pembatalan');
    Route::post('/atasan/pembatalan-cuti/{id}', [PembatalanController::class, 'process'])->name('atasan.pembatalan.process');

    // Rute Modul Admin HR
    Route::get('/admin/pegawai', [AdminController::class, 'kelolaPegawai'])->name('admin.pegawai');
    Route::get('/admin/saldo-cuti', [AdminController::class, 'kelolaSaldo'])->name('admin.saldo');
    Route::post('/admin/saldo-cuti/{id}', [AdminController::class, 'updateSaldo'])->name('admin.saldo.update');
    Route::get('/admin/rekap-laporan', [AdminController::class, 'rekapLaporan'])->name('admin.rekap');
    Route::get('/admin/rekap-laporan/export', [AdminController::class, 'exportExcel'])->name('admin.rekap.export');
    Route::post('/admin/pengajuan-cuti/{id}/tangguhkan', [AdminController::class, 'suspendCuti'])->name('admin.cuti.tangguhkan');
    Route::get('/admin/hari-libur', [AdminController::class, 'kelolaLibur'])->name('admin.libur');
    Route::post('/admin/hari-libur', [AdminController::class, 'storeLibur'])->name('admin.libur.store');
    Route::delete('/admin/hari-libur/{id}', [AdminController::class, 'destroyLibur'])->name('admin.libur.destroy');

    // Rute Profile Bawaan Breeze
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__ . '/auth.php';