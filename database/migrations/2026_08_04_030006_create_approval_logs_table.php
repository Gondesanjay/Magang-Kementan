<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pengajuan_id')->constrained('pengajuan_cutis')->onDelete('cascade');
            $table->foreignId('approver_id')->constrained('pegawais');
            $table->integer('level_approval'); // 1, 2, atau 3
            $table->string('keputusan'); // setuju, tolak
            $table->text('catatan')->nullable();
            $table->datetime('tanggal_keputusan');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_logs');
    }
};
