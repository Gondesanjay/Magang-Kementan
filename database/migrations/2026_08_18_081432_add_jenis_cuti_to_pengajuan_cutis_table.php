<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pengajuan_cutis', function (Blueprint $table) {
            $table->string('jenis_cuti')->default('Cuti Tahunan')->after('pegawai_id');
        });
    }

    public function down(): void
    {
        Schema::table('pengajuan_cutis', function (Blueprint $table) {
            $table->dropColumn('jenis_cuti');
        });
    }
};
