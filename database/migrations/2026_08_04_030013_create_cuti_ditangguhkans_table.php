<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuti_ditangguhkans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pegawai_id')->constrained('pegawais')->onDelete('cascade');
            $table->foreignId('pengajuan_asal_id')->constrained('pengajuan_cutis')->onDelete('cascade');
            $table->integer('jumlah_hari');
            $table->integer('tahun_asal');
            $table->integer('tahun_penggunaan')->nullable(); // Tahun berapa digunakan
            $table->string('status_pakai')->default('belum_dipakai');
            $table->text('alasan_penangguhan'); // Alasan pekerjaan instansi mendesak
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cuti_ditangguhkans');
    }
};
