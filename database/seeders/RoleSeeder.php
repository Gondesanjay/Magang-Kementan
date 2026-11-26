<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            1 => 'Staf / Pegawai Biasa',
            2 => 'L1 - Ketua Tim Kerja',
            3 => 'L2 - Ketua Kelompok Substansi',
            4 => 'L3 - Kasubag TU',
            5 => 'Admin HR',
            6 => 'L4 - Kepala Biro Perencanaan',
        ];

        foreach ($roles as $id => $nama) {
            DB::table('roles')->updateOrInsert(
                ['id' => $id],
                ['nama_role' => $nama, 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }
}
