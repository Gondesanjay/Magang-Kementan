<script setup>
import MainLayout from '@/Layouts/MainLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { reactive } from 'vue';

const props = defineProps({
    pegawai: Array,
    tahun: String
});

// Menyiapkan state reaktif untuk menampung isian form masing-masing baris tabel
const formData = reactive({});

props.pegawai.forEach(p => {
    // Cek apakah pegawai sudah memiliki data saldo di database untuk tahun ini
    const saldo = p.saldo_cuti && p.saldo_cuti.length > 0 ? p.saldo_cuti[0] : null;
    formData[p.id] = {
        kuota_tahunan: saldo ? saldo.kuota_tahunan : 12,
        sisa: saldo ? saldo.sisa : 12
    };
});

// Fungsi memproses simpan saldo per pegawai
const simpanSaldo = (id) => {
    router.post(route('admin.saldo.update', id), formData[id], {
        preserveScroll: true,
        onSuccess: () => alert('Saldo cuti berhasil diperbarui!')
    });
};
</script>

<template>
    <Head title="Kelola Saldo Cuti" />

    <MainLayout>
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
            <h2 class="text-2xl font-bold mb-2 text-gray-800">Kelola Saldo Cuti Pegawai</h2>
            <p class="text-gray-600 mb-6">Tahun Aktif: <strong class="text-indigo-600">{{ tahun }}</strong></p>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nama Pegawai</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Departemen</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Kuota Total (Hari)</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Sisa Cuti (Hari)</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <tr v-for="item in pegawai" :key="item.id" class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{{ item.nama }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ item.departemen }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-center">
                                <input type="number" v-model="formData[item.id].kuota_tahunan" class="w-20 text-center border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-center">
                                <input type="number" v-model="formData[item.id].sisa" class="w-20 text-center border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-center">
                                <button @click="simpanSaldo(item.id)" class="px-3 py-1 bg-indigo-100 text-indigo-700 font-semibold rounded hover:bg-indigo-200 text-xs">
                                    Simpan
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </MainLayout>
</template>