<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cuti_ditangguhkans', function (Blueprint $table) {
            // Baris hasil edit manual Admin HR tidak punya pengajuan asal.
            $table->unsignedBigInteger('pengajuan_asal_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Baris dengan pengajuan_asal_id NULL harus dihapus dulu,
        // kalau tidak rollback akan gagal.
        DB::table('cuti_ditangguhkans')->whereNull('pengajuan_asal_id')->delete();

        Schema::table('cuti_ditangguhkans', function (Blueprint $table) {
            $table->unsignedBigInteger('pengajuan_asal_id')->nullable(false)->change();
        });
    }
};
