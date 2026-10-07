<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


return new class extends Migration
{
    public function up()
    {
        Schema::table('pegawais', function (Blueprint $table) {
            // Menambahkan kolom status_aktif dengan nilai bawaan 'Aktif'
            // after('jabatan') opsional, agar posisinya rapi di database
            $table->string('status_aktif', 50)->default('Aktif')->after('jabatan');
        });
    }


    public function down()
    {
        Schema::table('pegawais', function (Blueprint $table) {
            $table->dropColumn('status_aktif');
        });
    }
};
