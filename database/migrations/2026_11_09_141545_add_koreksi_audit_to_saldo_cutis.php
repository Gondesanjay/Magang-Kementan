<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


// Menyimpan jejak koreksi "Terpakai" oleh Admin HR pada baris saldo_cutis:
//   koreksi_alasan          -> alasan koreksi (wajib diisi dari form)
//   koreksi_terpakai_sistem -> angka sistem SAAT koreksi dibuat; dipakai untuk
//                              mendeteksi koreksi basi (sistem berubah sesudahnya)
//   koreksi_oleh            -> id pegawai (Admin HR) yang mengoreksi
//   koreksi_pada            -> waktu koreksi
// Semua nullable, jadi data lama tidak terpengaruh.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saldo_cutis', function (Blueprint $table) {
            $table->text('koreksi_alasan')->nullable();
            $table->unsignedSmallInteger('koreksi_terpakai_sistem')->nullable();
            $table->unsignedBigInteger('koreksi_oleh')->nullable();
            $table->timestamp('koreksi_pada')->nullable();
        });
    }


    public function down(): void
    {
        Schema::table('saldo_cutis', function (Blueprint $table) {
            $table->dropColumn([
                'koreksi_alasan',
                'koreksi_terpakai_sistem',
                'koreksi_oleh',
                'koreksi_pada',
            ]);
        });
    }
};
