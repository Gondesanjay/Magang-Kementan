<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['nama_role' => 'Karyawan', 'created_at' => now(), 'updated_at' => now()],       // ID: 1
            ['nama_role' => 'Atasan L1', 'created_at' => now(), 'updated_at' => now()],      // ID: 2
            ['nama_role' => 'Atasan L2', 'created_at' => now(), 'updated_at' => now()],      // ID: 3
            ['nama_role' => 'Atasan L3', 'created_at' => now(), 'updated_at' => now()],      // ID: 4
            ['nama_role' => 'Admin HR', 'created_at' => now(), 'updated_at' => now()],       // ID: 5
        ];

        DB::table('roles')->insert($roles);
    }
}
