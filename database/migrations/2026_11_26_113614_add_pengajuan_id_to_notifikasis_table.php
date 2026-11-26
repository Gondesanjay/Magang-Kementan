<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifikasis', function (Blueprint $table) {
            $table->unsignedBigInteger('pengajuan_id')->nullable()->after('pegawai_id');
            $table->index(['pengajuan_id', 'judul']);
        });
    }

    public function down(): void
    {
        Schema::table('notifikasis', function (Blueprint $table) {
            $table->dropIndex(['pengajuan_id', 'judul']);
            $table->dropColumn('pengajuan_id');
        });
    }
};
