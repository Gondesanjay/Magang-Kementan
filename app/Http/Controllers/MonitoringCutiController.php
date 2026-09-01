<?php

namespace App\Http\Controllers;

use App\Models\PengajuanCuti; // <-- DIPERBAIKI: model aslinya PengajuanCuti, bukan Cuti
use Illuminate\Http\Request;
use Inertia\Inertia;


class MonitoringCutiController extends Controller
{
    public function index(Request $request)
    {
        // Ambil apa saja yang diketik Boss di kolom pencarian & filter
        $search = $request->input('search');
        $status = $request->input('status');
        $jenis_cuti = $request->input('jenis_cuti');
        $limit = $request->input('limit', 10); // Default tampil 10 baris


        // Cari data cuti ke database
        // <-- DIPERBAIKI: relasi 'user' -> 'pegawai' (sesuai relasi di Model PengajuanCuti)
        $query = PengajuanCuti::with('pegawai')->latest();


        // Kalau ada pencarian nama atau NIP
        if ($search) {
            $query->whereHas('pegawai', function ($q) use ($search) {
                $q->where('nama', 'like', "%{$search}%")
                    ->orWhere('nip', 'like', "%{$search}%");
            });
        }


        // Kalau difilter berdasarkan status atau jenis cuti
        if ($status) $query->where('status', $status);
        if ($jenis_cuti) $query->where('jenis_cuti', $jenis_cuti);


        // Bungkus datanya
        $pengajuan = $query->paginate($limit)->withQueryString();


        // Kirim ke tampilan Vue
        return Inertia::render('Admin/MonitoringCuti', [
            'pengajuan' => $pengajuan,
            'filters' => $request->only(['search', 'status', 'jenis_cuti', 'limit'])
        ]);
    }


    public function export(Request $request)
    {
        // Nanti kita isi logika download Excel/CSV-nya di sini ya Boss
        return back()->with('success', 'Fitur Export Segera Hadir!');
    }
}
