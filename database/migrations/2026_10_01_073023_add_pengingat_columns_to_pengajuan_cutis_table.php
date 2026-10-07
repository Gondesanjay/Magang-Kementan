<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pengajuan_cutis', function (Blueprint $table) {
            // Berapa kali pengingat sudah dikirim untuk level saat ini (0, 1, atau 2).
            $table->unsignedTinyInteger('jumlah_pengingat')->default(0);
            // Kapan pengingat terakhir dikirim; dipakai agar tidak terkirim ganda.
            $table->timestamp('pengingat_terakhir_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pengajuan_cutis', function (Blueprint $table) {
            $table->dropColumn(['jumlah_pengingat', 'pengingat_terakhir_at']);
        });
    }
};