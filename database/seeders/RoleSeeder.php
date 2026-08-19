<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['nama_role' => 'Staf / Pegawai Biasa', 'created_at' => now(), 'updated_at' => now()], // ID: 1
            ['nama_role' => 'L1 - Ketua Tim Kerja', 'created_at' => now(), 'updated_at' => now()], // ID: 2
            ['nama_role' => 'L2 - Ketua Kelompok Substansi', 'created_at' => now(), 'updated_at' => now()], // ID: 3
            ['nama_role' => 'L3 - Kasubag TU', 'created_at' => now(), 'updated_at' => now()], // ID: 4
            ['nama_role' => 'Admin HR', 'created_at' => now(), 'updated_at' => now()], // ID: 5
            ['nama_role' => 'L4 - Kepala Biro Perencanaan', 'created_at' => now(), 'updated_at' => now()], // ID: 6
        ];

        DB::table('roles')->insert($roles);
    }
}
