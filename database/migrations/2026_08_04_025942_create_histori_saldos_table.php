<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('histori_saldos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('saldo_cuti_id')->constrained('saldo_cutis')->onDelete('cascade');
            $table->foreignId('pegawai_id')->constrained('pegawais')->onDelete('cascade');
            $table->integer('jumlah_penyesuaian'); // Bisa minus/plus
            $table->text('alasan'); // Wajib isi catatan alasan penyesuaian
            $table->foreignId('diubah_oleh')->constrained('pegawais'); // Siapa Admin/HR yang ubah
            $table->datetime('tanggal_perubahan');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('histori_saldos');
    }
};
