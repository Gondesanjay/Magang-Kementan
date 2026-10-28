<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saldo_cutis', function (Blueprint $table) {
            $table->integer('koreksi_ditangguhkan')->nullable()->after('koreksi_saldo');
        });
    }

    public function down(): void
    {
        Schema::table('saldo_cutis', function (Blueprint $table) {
            $table->dropColumn('koreksi_ditangguhkan');
        });
    }
};
