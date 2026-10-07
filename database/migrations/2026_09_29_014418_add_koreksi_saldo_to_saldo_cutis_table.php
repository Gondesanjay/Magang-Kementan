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
        Schema::table('saldo_cutis', function (Blueprint $table) {
            // Tipe integer agar bisa menampung angka minus (misal: -2 atau 2)
            $table->integer('koreksi_saldo')->default(0)->after('kuota_tahunan');
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('saldo_cutis', function (Blueprint $table) {
            //
        });
    }
};
