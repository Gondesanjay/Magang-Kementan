<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pegawais', function (Blueprint $table) {
            if (!Schema::hasColumn('pegawais', 'nama')) {
                $table->string('nama')->nullable()->after('id');
            }
            if (!Schema::hasColumn('pegawais', 'nip')) {
                $table->string('nip', 50)->nullable()->unique()->after('nama');
            }
            if (!Schema::hasColumn('pegawais', 'departemen')) {
                $table->string('departemen', 100)->nullable()->after('nip');
            }
            if (!Schema::hasColumn('pegawais', 'divisi')) {
                $table->string('divisi', 100)->nullable()->after('departemen');
            }
            if (!Schema::hasColumn('pegawais', 'jabatan')) {
                $table->string('jabatan', 150)->nullable()->after('divisi');
            }
            if (!Schema::hasColumn('pegawais', 'role_id')) {
                $table->unsignedInteger('role_id')->default(1)->after('jabatan');
            }
        });
    }


    public function down(): void
    {
        Schema::table('pegawais', function (Blueprint $table) {
            $table->dropColumn(['nama', 'nip', 'departemen', 'divisi', 'jabatan', 'role_id']);
        });
    }
};
