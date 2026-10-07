<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;


/**
 * koreksi_saldo sekarang berarti "TOTAL CUTI TERPAKAI FINAL" dari Admin HR:
 *   NULL -> tidak ada koreksi, ikut angka sistem (riwayat disetujui)
 *   0    -> Admin memaksa Terpakai = 0 hari
 *   > 0  -> Admin memaksa Terpakai = angka tsb
 *
 * Nilai lama (0 default atau selisih +/-) semuanya di-NULL-kan karena
 * artinya sudah berubah, jadi semua pegawai kembali ikut angka sistem.
 *
 * CATATAN: sesuaikan nama tabel 'saldo_cutis' kalau di project Anda beda.
 * Kalau memakai Laravel < 10, ->change() butuh package doctrine/dbal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saldo_cutis', function (Blueprint $table) {
            $table->integer('koreksi_saldo')->nullable()->default(null)->change();
        });


        DB::table('saldo_cutis')->update(['koreksi_saldo' => null]);
    }


    public function down(): void
    {
        DB::table('saldo_cutis')->whereNull('koreksi_saldo')->update(['koreksi_saldo' => 0]);


        Schema::table('saldo_cutis', function (Blueprint $table) {
            $table->integer('koreksi_saldo')->nullable(false)->default(0)->change();
        });
    }
};
