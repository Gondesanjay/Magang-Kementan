<script setup>
import MainLayout from '@/Layouts/MainLayout.vue';
import { Head, router } from '@inertiajs/vue3';

defineProps({
    antrean: Array
});

// Fungsi memformat tanggal (YYYY-MM-DD ke DD-MM-YYYY)
const formatDate = (dateString) => {
    if (!dateString) return '-';
    const date = new Date(dateString);
    return new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }).format(date);
};

// Fungsi memproses aksi Setujui / Tolak
const processApproval = (id, action) => {
    const actionText = action === 'approve' ? 'MENYETUJUI' : 'MENOLAK';
    if (confirm(`Apakah Anda yakin ingin ${actionText} pengajuan cuti ini?`)) {
        router.post(route('atasan.approval.process', id), { action: action }, {
            preserveScroll: true
        });
    }
};
</script>

<template>
    <Head title="Antrean Approval" />

    <MainLayout>
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
            <h2 class="text-2xl font-bold mb-6 text-gray-800">Antrean Persetujuan Cuti</h2>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nama Karyawan</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tanggal Cuti</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Durasi</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Keterangan</th>
                            <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <tr v-for="item in antrean" :key="item.id" class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900">{{ item.pegawai.nama }}</div>
                                <div class="text-xs text-gray-500">{{ item.pegawai.departemen }}</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                {{ formatDate(item.tanggal_mulai) }} s/d {{ formatDate(item.tanggal_selesai) }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ item.jumlah_hari }} Hari</td>
                            <td class="px-6 py-4 text-sm text-gray-500 max-w-xs truncate" :title="item.keterangan">{{ item.keterangan }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium space-x-2">
                                <button @click="processApproval(item.id, 'approve')" class="inline-flex items-center px-3 py-1 bg-green-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-green-700 transition ease-in-out duration-150">
                                    Setujui
                                </button>
                                <button @click="processApproval(item.id, 'reject')" class="inline-flex items-center px-3 py-1 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-700 transition ease-in-out duration-150">
                                    Tolak
                                </button>
                            </td>
                        </tr>
                        <tr v-if="antrean.length === 0">
                            <td colspan="5" class="px-6 py-8 text-center text-gray-500">
                                Saat ini tidak ada antrean pengajuan cuti yang memerlukan persetujuan Anda.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </MainLayout>
</template>