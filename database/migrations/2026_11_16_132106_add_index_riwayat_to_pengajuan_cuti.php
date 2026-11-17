<?php

use App\Models\PengajuanCuti;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Index untuk query riwayat: where pegawai_id = ? AND tanggal_mulai <= ? AND tanggal_selesai >= ?
// Nama tabel diambil dari model supaya tidak salah tebak (pengajuan_cuti / pengajuan_cutis).
return new class extends Migration
{
    public function up(): void
    {
        $tabel = (new PengajuanCuti)->getTable();


        Schema::table($tabel, function (Blueprint $table) {
            $table->index(
                ['pegawai_id', 'tanggal_mulai', 'tanggal_selesai'],
                'idx_cuti_pegawai_tanggal'
            );
        });
    }

    public function down(): void
    {
        $tabel = (new PengajuanCuti)->getTable();

        Schema::table($tabel, function (Blueprint $table) {
            $table->dropIndex('idx_cuti_pegawai_tanggal');
        });
    }
};
