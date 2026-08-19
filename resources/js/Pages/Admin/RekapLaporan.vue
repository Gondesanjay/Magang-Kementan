<script setup>
import MainLayout from '@/Layouts/MainLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    laporan: Array
});

// Fungsi memformat tanggal
const formatDate = (dateString) => {
    if (!dateString) return '-';
    const date = new Date(dateString);
    return new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }).format(date);
};

// Fungsi memformat badge status (Gabungan Logika Baru & Class Styling)
const formatStatus = (status) => {
    if (status === 'menunggu_l1' || status === 'menunggu_l2' || status === 'menunggu_l3') {
        return { text: 'Menunggu Atasan Langsung', class: 'bg-yellow-100 text-yellow-800' };
    }
    if (status === 'disetujui') {
        return { text: 'Disetujui', class: 'bg-green-100 text-green-800' };
    }
    if (status === 'ditolak') {
        return { text: 'Ditolak', class: 'bg-red-100 text-red-800' };
    }
    return { text: status.replace('_', ' ').toUpperCase(), class: 'bg-gray-100 text-gray-800' };
};
</script>

<template>
    <Head title="Rekap Laporan Cuti" />

    <MainLayout>
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-2xl font-bold text-gray-800">Rekapitulasi Cuti Instansi</h2>
                
                <!-- Tombol Export Data -->
                <a :href="route('admin.rekap.export')" target="_blank" class="px-4 py-2 bg-green-600 text-white rounded-md text-sm font-semibold hover:bg-green-700 flex items-center">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    Export ke Excel
                </a>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nama Pegawai</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Departemen</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tanggal Cuti</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Durasi</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <tr v-for="item in laporan" :key="item.id" class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{{ item.pegawai?.nama }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ item.pegawai?.departemen }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                {{ formatDate(item.tanggal_mulai) }} - {{ formatDate(item.tanggal_selesai) }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ item.jumlah_hari }} Hari</td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full" :class="formatStatus(item.status).class">
                                    {{ formatStatus(item.status).text }}
                                </span>
                            </td>
                        </tr>
                        <tr v-if="laporan.length === 0">
                            <td colspan="5" class="px-6 py-8 text-center text-gray-500">Belum ada data pengajuan cuti di sistem.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </MainLayout>
</template>