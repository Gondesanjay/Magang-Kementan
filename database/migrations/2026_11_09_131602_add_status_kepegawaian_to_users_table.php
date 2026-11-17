<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Jenis kepegawaian ASN: PNS / PPPK (beda dengan status_aktif: Aktif/Pensiun/dst).
    public function up(): void
    {
        Schema::table('pegawais', function (Blueprint $table) {
            $table->string('status_kepegawaian', 10)->nullable()->after('jabatan');
        });
    }

    public function down(): void
    {
        Schema::table('pegawais', function (Blueprint $table) {
            $table->dropColumn('status_kepegawaian');
        });
    }
};
