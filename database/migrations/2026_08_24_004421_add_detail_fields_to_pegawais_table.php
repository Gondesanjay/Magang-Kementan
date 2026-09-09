<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('pegawais', function (Blueprint $table) { // Sesuaikan nama tabel
            $table->string('kelompok_substansi')->nullable();
            $table->string('tim_kerja')->nullable();
            $table->text('alamat_domisili')->nullable();
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pegawais', function (Blueprint $table) {
            //
        });
    }
};
