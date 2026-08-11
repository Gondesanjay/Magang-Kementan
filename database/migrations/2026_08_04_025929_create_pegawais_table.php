<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pegawais', function (Blueprint $table) {
            $table->id();
            $table->string('nip')->unique();
            $table->string('nama');
            $table->string('password');
            $table->foreignId('role_id')->constrained('roles')->onDelete('restrict');
            // Self-referencing Foreign Key untuk atasan
            $table->foreignId('atasan_id')->nullable()->constrained('pegawais')->onDelete('set null');
            $table->string('jabatan');
            $table->string('departemen');
            $table->date('tanggal_masuk');

            // Kolom tambahan untuk cek login pertama (Wajib ganti password)
            $table->boolean('is_first_login')->default(true);

            // Tambahkan baris ini untuk fitur Remember Me
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pegawais');
    }
};
