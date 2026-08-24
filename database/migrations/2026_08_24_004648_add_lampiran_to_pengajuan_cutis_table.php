<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('pengajuan_cutis', function (Blueprint $table) {
            $table->string('lampiran')->nullable(); // Untuk menyimpan path file
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pengajuan_cutis', function (Blueprint $table) {
            //
        });
    }
};
