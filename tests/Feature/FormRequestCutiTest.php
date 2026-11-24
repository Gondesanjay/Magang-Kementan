<?php

namespace Tests\Feature;

use App\Models\Pegawai;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

// Menguji perbaikan poin 5 (Form Request), batas rentang tanggal (poin 1),
// dan kelayakan jenis cuti di backend (poin 3).
// Semua kasus berhenti di tahap validasi, jadi TIDAK menyentuh database dan
// TIDAK mengubah data apa pun. CSRF otomatis dilewati saat testing.
//
// Jalankan: php artisan test --filter=FormRequestCutiTest
class FormRequestCutiTest extends TestCase
{
    // Pengguna sementara (tidak disimpan ke database).
    private function pegawai(): Pegawai
    {
        $p = new Pegawai();
        $p->forceFill([
            'id'             => 999999,
            'nama'           => 'Tester',
            'role_id'        => 1,
            'is_first_login' => false,
        ]);

        return $p;
    }

    private function payloadCuti(array $ubah = []): array
    {
        return array_merge([
            'jenis_cuti'      => 'Cuti Tahunan',
            'tanggal_mulai'   => now()->addDays(3)->toDateString(),
            'tanggal_selesai' => now()->addDays(4)->toDateString(),
            'keterangan'      => 'Keperluan keluarga',
            'alamat_cuti'     => 'Jakarta',
            'no_telp'         => '08123456789',
        ], $ubah);
    }

    // ================= StoreCutiRequest =================

    public function test_store_form_kosong_ditolak_dengan_pesan_indonesia(): void
    {
        $res = $this->actingAs($this->pegawai())
            ->postJson('/karyawan/ajukan-cuti', []);

        $res->assertStatus(422)
            ->assertJsonValidationErrors([
                'jenis_cuti',
                'tanggal_mulai',
                'tanggal_selesai',
                'keterangan',
                'alamat_cuti',
                'no_telp',
            ])
            ->assertJsonPath('errors.jenis_cuti.0', 'Jenis cuti wajib dipilih.')
            ->assertJsonPath('errors.tanggal_mulai.0', 'Tanggal mulai wajib diisi.');
    }

    public function test_store_tanggal_selesai_sebelum_mulai_ditolak(): void
    {
        $res = $this->actingAs($this->pegawai())
            ->postJson('/karyawan/ajukan-cuti', $this->payloadCuti([
                'tanggal_mulai'   => now()->addDays(10)->toDateString(),
                'tanggal_selesai' => now()->addDays(5)->toDateString(),
            ]));

        $res->assertStatus(422)
            ->assertJsonPath(
                'errors.tanggal_selesai.0',
                'Tanggal selesai tidak boleh sebelum tanggal mulai.'
            );
    }

    public function test_store_jenis_cuti_tidak_valid_ditolak(): void
    {
        $res = $this->actingAs($this->pegawai())
            ->postJson('/karyawan/ajukan-cuti', $this->payloadCuti([
                'jenis_cuti' => 'Cuti Liburan',
            ]));

        $res->assertStatus(422)
            ->assertJsonPath('errors.jenis_cuti.0', 'Jenis cuti tidak valid.');
    }

    public function test_store_cuti_melahirkan_wajib_anak_ke(): void
    {
        $res = $this->actingAs($this->pegawai())
            ->postJson('/karyawan/ajukan-cuti', $this->payloadCuti([
                'jenis_cuti' => 'Cuti Melahirkan',
            ]));

        $res->assertStatus(422)
            ->assertJsonPath(
                'errors.anak_ke.0',
                'Kolom anak ke- wajib diisi untuk Cuti Melahirkan.'
            );
    }

    public function test_store_lampiran_lebih_dari_2mb_ditolak(): void
    {
        $res = $this->actingAs($this->pegawai())
            ->post(
                '/karyawan/ajukan-cuti',
                $this->payloadCuti([
                    'lampiran' => UploadedFile::fake()->create('surat.pdf', 3000, 'application/pdf'),
                ]),
                ['Accept' => 'application/json']
            );

        $res->assertStatus(422)
            ->assertJsonPath('errors.lampiran.0', 'Ukuran lampiran maksimal 2 MB.');
    }

    public function test_store_lampiran_format_salah_ditolak(): void
    {
        $res = $this->actingAs($this->pegawai())
            ->post(
                '/karyawan/ajukan-cuti',
                $this->payloadCuti([
                    'lampiran' => UploadedFile::fake()->create('virus.exe', 10),
                ]),
                ['Accept' => 'application/json']
            );

        $res->assertStatus(422)
            ->assertJsonPath('errors.lampiran.0', 'Format lampiran harus PDF, JPG, atau PNG.');
    }

    // ================= Batas tanggal di backend (poin 1) =================

    public function test_store_rentang_100_hari_ditolak_backend(): void
    {
        $res = $this->actingAs($this->pegawai())
            ->postJson('/karyawan/ajukan-cuti', $this->payloadCuti([
                'tanggal_mulai'   => now()->toDateString(),
                'tanggal_selesai' => now()->addDays(99)->toDateString(), // 100 hari kalender
            ]));

        $res->assertStatus(422)->assertJsonValidationErrors(['tanggal_selesai']);

        $this->assertStringContainsString(
            'Rentang cuti terlalu panjang',
            $res->json('errors.tanggal_selesai.0')
        );
    }

    public function test_store_tanggal_terlalu_jauh_ke_depan_ditolak_backend(): void
    {
        $res = $this->actingAs($this->pegawai())
            ->postJson('/karyawan/ajukan-cuti', $this->payloadCuti([
                'tanggal_mulai'   => now()->addYears(10)->toDateString(),
                'tanggal_selesai' => now()->addYears(10)->addDay()->toDateString(),
            ]));

        $res->assertStatus(422)->assertJsonValidationErrors(['tanggal_mulai']);

        $this->assertStringContainsString(
            'terlalu jauh ke depan',
            $res->json('errors.tanggal_mulai.0')
        );
    }

    public function test_store_tanggal_terlalu_lama_ke_belakang_ditolak_backend(): void
    {
        $res = $this->actingAs($this->pegawai())
            ->postJson('/karyawan/ajukan-cuti', $this->payloadCuti([
                'tanggal_mulai'   => now()->subYears(10)->toDateString(),
                'tanggal_selesai' => now()->subYears(10)->addDay()->toDateString(),
            ]));

        $res->assertStatus(422)->assertJsonValidationErrors(['tanggal_mulai']);

        $this->assertStringContainsString(
            'terlalu lama ke belakang',
            $res->json('errors.tanggal_mulai.0')
        );
    }

    // ================= Kelayakan jenis cuti di backend (poin 3) =================

    public function test_store_cuti_besar_masa_kerja_kurang_ditolak_backend(): void
    {
        $pegawaiBaru = $this->pegawai();
        $pegawaiBaru->forceFill([
            'tanggal_masuk' => now()->subYears(2)->toDateString(),
        ]);

        $res = $this->actingAs($pegawaiBaru)
            ->postJson('/karyawan/ajukan-cuti', $this->payloadCuti([
                'jenis_cuti' => 'Cuti Besar',
            ]));

        $res->assertStatus(302)
            ->assertSessionHasErrors([
                'jenis_cuti' => 'Cuti Besar hanya dapat diajukan jika masa kerja sudah mencapai minimal 5 tahun. Masa kerja Anda saat ini: 2 tahun.',
            ]);
    }

    public function test_store_cuti_melahirkan_pegawai_laki_laki_ditolak_backend(): void
    {
        $pegawaiLaki = $this->pegawai();
        $pegawaiLaki->forceFill([
            // Digit ke-15 NIP = '1' (laki-laki)
            'nip' => '199501012020011001',
        ]);

        $res = $this->actingAs($pegawaiLaki)
            ->postJson('/karyawan/ajukan-cuti', $this->payloadCuti([
                'jenis_cuti' => 'Cuti Melahirkan',
                'anak_ke'    => 1,
            ]));

        $res->assertStatus(302)
            ->assertSessionHasErrors([
                'jenis_cuti' => 'Cuti Melahirkan hanya dapat diajukan oleh pegawai perempuan.',
            ]);
    }

    // ================= BatalkanMandiriRequest =================

    public function test_batalkan_mandiri_tanpa_alasan_ditolak(): void
    {
        $res = $this->actingAs($this->pegawai())
            ->postJson('/karyawan/cuti/1/batalkan-mandiri', []);

        $res->assertStatus(422)
            ->assertJsonPath('errors.alasan_pembatalan.0', 'Alasan pembatalan wajib diisi.');
    }

    public function test_batalkan_mandiri_alasan_lebih_dari_255_karakter_ditolak(): void
    {
        $res = $this->actingAs($this->pegawai())
            ->postJson('/karyawan/cuti/1/batalkan-mandiri', [
                'alasan_pembatalan' => str_repeat('a', 256),
            ]);

        $res->assertStatus(422)->assertJsonValidationErrors(['alasan_pembatalan']);
    }

    // ================= GantiPasswordRequest =================

    public function test_ganti_password_terlalu_pendek_ditolak(): void
    {
        $res = $this->actingAs($this->pegawai())
            ->postJson('/ganti-password', [
                'password'              => 'abc',
                'password_confirmation' => 'abc',
            ]);

        $res->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'Password minimal 8 karakter.');
    }

    public function test_ganti_password_konfirmasi_tidak_cocok_ditolak(): void
    {
        $res = $this->actingAs($this->pegawai())
            ->postJson('/ganti-password', [
                'password'              => 'password123',
                'password_confirmation' => 'beda',
            ]);

        $res->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'Konfirmasi password tidak cocok.');
    }

    // ================= Fitur revisi sudah dihapus =================

    public function test_rute_revisi_sudah_tidak_ada(): void
    {
        $this->actingAs($this->pegawai())
            ->postJson('/karyawan/cuti/1/revisi', [])
            ->assertStatus(404);
    }
}
