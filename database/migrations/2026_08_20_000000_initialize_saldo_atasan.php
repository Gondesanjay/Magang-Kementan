<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $tahun = now()->year;

        DB::table('pegawais')
            ->whereIn('role_id', [2, 3, 4, 6])
            ->get(['id'])
            ->each(function ($pegawai) use ($tahun) {
                $saldoExists = DB::table('saldo_cutis')
                    ->where('pegawai_id', $pegawai->id)
                    ->where('tahun', $tahun)
                    ->exists();

                if (!$saldoExists) {
                    DB::table('saldo_cutis')->insert([
                        'pegawai_id' => $pegawai->id,
                        'tahun' => $tahun,
                        'kuota_tahunan' => 12,
                        'sisa' => 12,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('saldo_cutis')
            ->where('tahun', now()->year)
            ->whereIn('pegawai_id', function ($query) {
                $query->select('id')
                    ->from('pegawais')
                    ->whereIn('role_id', [2, 3, 4, 6]);
            })
            ->delete();
    }
};
