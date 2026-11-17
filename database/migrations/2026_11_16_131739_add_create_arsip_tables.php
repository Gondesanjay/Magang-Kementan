<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel arsip = salinan struktur tabel utama (CREATE TABLE ... LIKE),
 * sehingga semua kolom otomatis sama, termasuk kolom dari migrasi lain.
 * CREATE TABLE ... LIKE tidak menyalin foreign key, jadi arsip berdiri sendiri
 * dan tidak ikut terhapus saat data induknya (pegawai/pengajuan) dihapus.
 */
return new class extends Migration
{
    private array $tabel = [
        'pengajuan_cutis',
        'approval_logs',
        'bukti_pengajuans',
        'surat_persetujuans',
    ];

    public function up(): void
    {
        foreach ($this->tabel as $sumber) {
            $arsip = $sumber . '_arsip';


            if (Schema::hasTable($arsip)) {
                continue;
            }

            DB::statement("CREATE TABLE `{$arsip}` LIKE `{$sumber}`");

            // Penanda kapan baris dipindah ke arsip.
            DB::statement("ALTER TABLE `{$arsip}` ADD COLUMN `diarsipkan_at` TIMESTAMP NULL");
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->tabel) as $sumber) {
            Schema::dropIfExists($sumber . '_arsip');
        }
    }
};
