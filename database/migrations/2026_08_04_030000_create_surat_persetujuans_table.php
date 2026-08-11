<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surat_persetujuans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pengajuan_id')->constrained('pengajuan_cutis')->onDelete('cascade');
            $table->string('nomor_surat')->nullable();
            $table->string('path_file_pdf');
            $table->string('kode_barcode')->nullable(); // Untuk QR TTE
            $table->datetime('tanggal_terbit');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surat_persetujuans');
    }
};
