<?php

namespace Database\Seeders;

use App\Models\Pegawai;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class PegawaiSeeder extends Seeder
{
    public function run(): void
    {
        $nip      = env('ADMIN_SEED_NIP', 'adminhr');
        $password = env('ADMIN_SEED_PASSWORD');

        if (empty($password)) {
            $this->command->error('ADMIN_SEED_PASSWORD belum diisi di .env');
            return;
        }

        // withTrashed: kalau akun ini pernah di-soft-delete, dipakai ulang
        $admin = Pegawai::withTrashed()->firstOrNew(['nip' => $nip]);

        $admin->fill([
            'nama'               => 'Admin HR Kementan',
            'jabatan'            => 'Staf Pengelola Kepegawaian',
            'status_kepegawaian' => 'PNS',
            'kelompok_substansi' => '-',
            'tim_kerja'          => '-',
            'status_aktif'       => 'Aktif',
            'role_id'            => 5,
            'password'           => Hash::make($password),
            'is_first_login'     => true,
            'tanggal_masuk'      => now()->toDateString(),
        ]);

        if ($admin->trashed()) {
            $admin->restore();
        }

        $admin->save();

        $this->command->info("Akun Admin HR siap (NIP: {$nip}).");
    }
}
