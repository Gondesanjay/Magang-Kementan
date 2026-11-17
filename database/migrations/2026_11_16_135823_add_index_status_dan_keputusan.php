<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


return new class extends Migration
{
    public function up(): void
    {
        // Approval, Monitoring, Penangguhan: filter status + rentang tanggal.
        Schema::table('pengajuan_cutis', function (Blueprint $table) {
            if (! Schema::hasIndex('pengajuan_cutis', 'pengajuan_cutis_status_tanggal_idx')) {
                $table->index(
                    ['status', 'tanggal_mulai', 'tanggal_selesai'],
                    'pengajuan_cutis_status_tanggal_idx'
                );
            }
        });


        // Riwayat Approval: log per atasan, diurutkan menurut tanggal keputusan.
        Schema::table('approval_logs', function (Blueprint $table) {
            if (! Schema::hasIndex('approval_logs', 'approval_logs_approver_tanggal_idx')) {
                $table->index(
                    ['approver_id', 'tanggal_keputusan'],
                    'approval_logs_approver_tanggal_idx'
                );
            }
        });
    }


    public function down(): void
    {
        Schema::table('pengajuan_cutis', function (Blueprint $table) {
            $table->dropIndex('pengajuan_cutis_status_tanggal_idx');
        });


        Schema::table('approval_logs', function (Blueprint $table) {
            $table->dropIndex('approval_logs_approver_tanggal_idx');
        });
    }
};
