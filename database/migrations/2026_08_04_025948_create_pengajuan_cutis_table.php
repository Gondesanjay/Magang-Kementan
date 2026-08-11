<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengajuan_cutis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pegawai_id')->constrained('pegawais')->onDelete('cascade');
            $table->date('tanggal_mulai');
            $table->date('tanggal_selesai');
            $table->integer('jumlah_hari');
            $table->text('keterangan');

            // Enum Status: menunggu_l1, menunggu_l2, menunggu_l3, disetujui, ditolak, dibatalkan_reguler, dibatalkan_ditangguhkan
            $table->string('status')->default('menunggu_l1');
            $table->integer('level_saat_ini')->default(1);
            $table->timestamps(); // Otomatis meng-generate kolom created_at dan updated_at
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengajuan_cutis');
    }
};
