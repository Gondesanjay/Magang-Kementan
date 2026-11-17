<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


return new class extends Migration
{
    public function up(): void
    {
        Schema::create('koreksi_cuti_bulanan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pegawai_id')->constrained('pegawais')->cascadeOnDelete();
            $table->unsignedSmallInteger('tahun');
            $table->unsignedTinyInteger('bulan');          // 1-12
            $table->unsignedSmallInteger('nilai');         // nilai AKHIR hari cuti di bulan itu
            $table->unsignedSmallInteger('nilai_sistem');  // hitungan sistem saat koreksi dibuat
            $table->string('alasan', 500)->nullable();
            $table->foreignId('diubah_oleh')->nullable()->constrained('pegawais')->nullOnDelete();
            $table->timestamps();


            // Satu sel (pegawai, tahun, bulan) hanya boleh punya satu koreksi.
            $table->unique(['pegawai_id', 'tahun', 'bulan'], 'uniq_koreksi_cuti_sel');
        });
    }


    public function down(): void
    {
        Schema::dropIfExists('koreksi_cuti_bulanan');
    }
};
