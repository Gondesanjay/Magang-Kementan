<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('saldo_cutis', function (Blueprint $table) {
            $table->unique(['pegawai_id', 'tahun'], 'saldo_cutis_pegawai_tahun_unique');
        });
    }

    public function down(): void
    {
        Schema::table('saldo_cutis', function (Blueprint $table) {
            $table->dropUnique('saldo_cutis_pegawai_tahun_unique');
        });
    }
};
