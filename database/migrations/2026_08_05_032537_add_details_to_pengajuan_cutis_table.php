<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pengajuan_cutis', function (Blueprint $table) {
            $table->text('alamat_cuti')->nullable()->after('keterangan');
            $table->string('no_telp', 20)->nullable()->after('alamat_cuti');
            $table->unsignedBigInteger('atasan_l1_id')->nullable()->after('level_saat_ini');
            $table->unsignedBigInteger('atasan_l3_id')->nullable()->after('atasan_l1_id');
        });
    }

    public function down(): void
    {
        Schema::table('pengajuan_cutis', function (Blueprint $table) {
            $table->dropColumn(['alamat_cuti', 'no_telp', 'atasan_l1_id', 'atasan_l3_id']);
        });
    }
};
