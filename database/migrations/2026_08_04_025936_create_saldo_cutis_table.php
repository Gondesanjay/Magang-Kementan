<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saldo_cutis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pegawai_id')->constrained('pegawais')->onDelete('cascade');
            $table->integer('tahun');
            $table->integer('kuota_tahunan')->default(12); // Default jatah per tahun
            $table->integer('carry_forward_normal')->default(0); // Sisa dari tahun lalu (maks 6)
            $table->integer('terpakai')->default(0);
            $table->integer('sisa')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saldo_cutis');
    }
};
