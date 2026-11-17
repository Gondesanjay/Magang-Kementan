<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Log perubahan saldo oleh Admin HR (satu baris per kolom yang berubah).
//   kolom      : hak, sisa_n1, sisa_n2, terpakai, tgh_n2, tgh_n1, tgh_n0
//   nilai_lama : angka efektif sebelum diubah (terpakai: angka yang dilihat pegawai)
//   nilai_baru : angka efektif sesudah diubah
// Baris log tidak pernah diubah atau dihapus oleh aplikasi.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('log_perubahan_saldos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pegawai_id')->index();
            $table->unsignedBigInteger('diubah_oleh')->nullable();
            $table->unsignedSmallInteger('tahun');
            $table->string('kolom', 30);
            $table->smallInteger('nilai_lama')->nullable();
            $table->smallInteger('nilai_baru')->nullable();
            $table->text('alasan')->nullable();
            $table->timestamps();
            $table->index(['pegawai_id', 'tahun']);
        });
    }


    public function down(): void
    {
        Schema::dropIfExists('log_perubahan_saldos');
    }
};
