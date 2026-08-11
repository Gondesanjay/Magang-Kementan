<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            // Diubah mengarah ke tabel 'pegawais' dan kolom 'id'
            $table->foreignId('user_id')->constrained('pegawais')->onDelete('cascade');
            $table->string('title');
            $table->string('status');
            $table->string('type'); // success, warning, danger
            $table->boolean('read')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
