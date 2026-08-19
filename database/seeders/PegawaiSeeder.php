<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class PegawaiSeeder extends Seeder
{
    public function run(): void
    {
        $passwordDefault = Hash::make('password123');

        // 1. Admin HR (Tidak terkait hierarki approval cuti)
        DB::table('pegawais')->insert([
            'nip' => 'adminhr',
            'nama' => 'Admin HR Kementan',
            'password' => $passwordDefault,
            'role_id' => 5, // Admin HR
            'atasan_id' => null,
            'jabatan' => 'Staf Pengelola Kepegawaian',
            'departemen' => 'Biro Organisasi dan Kepegawaian',
            'tanggal_masuk' => '2015-01-10',
            'is_first_login' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Kepala Biro Perencanaan (L4 - Pimpinan Final)
        $idKepalaBiro = DB::table('pegawais')->insertGetId([
            'nip' => '197001011995031001',
            'nama' => 'Bapak Kepala Biro',
            'password' => $passwordDefault,
            'role_id' => 6, // L4
            'atasan_id' => null, // Puncak hierarki di biro ini
            'jabatan' => 'Kepala Biro',
            'departemen' => 'Biro Perencanaan',
            'tanggal_masuk' => '1995-03-01',
            'is_first_login' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 3. Kasubag TU (L3 - Lapor ke Kepala Biro)
        $idKasubagTU = DB::table('pegawais')->insertGetId([
            'nip' => '198002022005011002',
            'nama' => 'Ibu Kasubag TU',
            'password' => $passwordDefault,
            'role_id' => 4, // L3
            'atasan_id' => $idKepalaBiro,
            'jabatan' => 'Kepala Subbagian Tata Usaha',
            'departemen' => 'Biro Perencanaan',
            'tanggal_masuk' => '2005-01-01',
            'is_first_login' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 4. Ketua Kelompok Substansi (L2 - Lapor ke Kasubag TU)
        $idKetuaKelompok = DB::table('pegawais')->insertGetId([
            'nip' => '198204042008011006',
            'nama' => 'Ibu Ketua Kelompok',
            'password' => $passwordDefault,
            'role_id' => 3, // L2
            'atasan_id' => $idKasubagTU,
            'jabatan' => 'Ketua Kelompok Substansi',
            'departemen' => 'Biro Perencanaan',
            'tanggal_masuk' => '2008-01-01',
            'is_first_login' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 5. Ketua Tim Kerja (L1 - Lapor ke Ketua Kelompok)
        $idAtasanLangsung = DB::table('pegawais')->insertGetId([
            'nip' => '198503032010121003',
            'nama' => 'Bapak Ketua Tim',
            'password' => $passwordDefault,
            'role_id' => 2, // Atasan L1
            'atasan_id' => $idKetuaKelompok,
            'jabatan' => 'Ketua Tim Kerja',
            'departemen' => 'Biro Perencanaan',
            'tanggal_masuk' => '2010-12-01',
            'is_first_login' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 6. Karyawan Biasa (Pemohon Cuti - Lapor ke Ketua Tim)
        DB::table('pegawais')->insert([
            'nip' => '199004042015041004',
            'nama' => 'Pegawai Staf Analis',
            'password' => $passwordDefault,
            'role_id' => 1, // Karyawan
            'atasan_id' => $idAtasanLangsung,
            'jabatan' => 'Analis Perencanaan',
            'departemen' => 'Biro Perencanaan',
            'tanggal_masuk' => '2015-04-01',
            'is_first_login' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 7. Karyawan Biasa Tambahan (Untuk testing Kalender Tim)
        DB::table('pegawais')->insert([
            'nip' => '199205052018041005',
            'nama' => 'Pegawai Staf Evaluasi',
            'password' => $passwordDefault,
            'role_id' => 1, // Karyawan
            'atasan_id' => $idAtasanLangsung,
            'jabatan' => 'Analis Evaluasi',
            'departemen' => 'Biro Perencanaan',
            'tanggal_masuk' => '2018-04-01',
            'is_first_login' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
