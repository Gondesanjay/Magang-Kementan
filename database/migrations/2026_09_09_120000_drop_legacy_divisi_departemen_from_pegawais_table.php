<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pegawais', function (Blueprint $table) {
            if (Schema::hasColumn('pegawais', 'divisi')) {
                $table->dropColumn('divisi');
            }

            if (Schema::hasColumn('pegawais', 'departemen')) {
                $table->dropColumn('departemen');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pegawais', function (Blueprint $table) {
            if (!Schema::hasColumn('pegawais', 'departemen')) {
                $table->string('departemen')->nullable();
            }

            if (!Schema::hasColumn('pegawais', 'divisi')) {
                $table->string('divisi')->nullable();
            }
        });
    }
};
