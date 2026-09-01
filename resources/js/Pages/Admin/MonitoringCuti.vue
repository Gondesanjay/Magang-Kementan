<script setup>
import { ref, watch } from 'vue';
import { router, Head, Link } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue'; // Sesuaikan lokasi layout Boss


const props = defineProps({
    pengajuan: Object,
    filters: Object,
});


const search = ref(props.filters.search || '');
const status = ref(props.filters.status || '');


// <-- DIPERBAIKI: fungsi debounce custom (menggantikan 'lodash/debounce'
// yang belum ter-install), perilakunya sama persis: menunda eksekusi
// sampai user berhenti mengetik selama `delay` ms.
function debounce(fn, delay = 300) {
    let timeoutId;
    return (...args) => {
        clearTimeout(timeoutId);
        timeoutId = setTimeout(() => fn(...args), delay);
    };
}


// Supaya otomatis nge-search pas ngetik (nggak usah klik tombol cari)
watch([search, status], debounce(function ([newSearch, newStatus]) {
    // <-- DIPERBAIKI: pakai helper route() supaya otomatis sinkron dengan
    // routes/web.php (sebelumnya hardcode '/admin/monitoring-cuti' yang
    // tidak cocok dengan route terdaftar '/admin/monitoring').
    router.get(route('admin.monitoring'), {
        search: newSearch,
        status: newStatus
    }, { preserveState: true, replace: true });
}, 300));
</script>


<template>
    <Head title="Monitoring Cuti" />


    <MainLayout>
        <div class="p-6 bg-white rounded-xl shadow-sm">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-xl font-bold">Monitoring Cuti (HR Admin)</h2>


                <!-- Tombol Export -->
                <a :href="route('admin.monitoring.export')" class="bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700">
                    Export CSV
                </a>
            </div>


            <!-- Filter & Pencarian -->
            <div class="flex gap-4 mb-6">
                <input v-model="search" type="text" placeholder="Cari nama/NIP..." class="border rounded-lg px-4 py-2 w-64">
                <!-- <-- DIPERBAIKI: opsi status disesuaikan dengan status asli di
                     database (lihat Dashboard.vue / formatStatus). Sebelumnya
                     pakai 'menunggu' generik yang tidak akan pernah cocok
                     dengan exact match di controller. -->
                <select v-model="status" class="border rounded-lg px-4 py-2">
                    <option value="">Semua Status</option>
                    <option value="menunggu_l1">Menunggu Ketua Tim Kerja (L1)</option>
                    <option value="menunggu_l2">Menunggu Ketua Kelompok Substansi (L2)</option>
                    <option value="menunggu_l3">Menunggu Kasubag TU (L3)</option>
                    <option value="menunggu_l4">Menunggu Kepala Biro (L4)</option>
                    <option value="disetujui">Disetujui</option>
                    <option value="ditolak">Ditolak</option>
                    <option value="ditangguhkan">Ditangguhkan</option>
                </select>
            </div>


            <!-- Tabel Sederhana -->
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="border-b bg-gray-50">
                        <th class="p-3">Nama</th>
                        <th class="p-3">Jenis Cuti</th>
                        <th class="p-3">Tanggal</th>
                        <th class="p-3">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="item in pengajuan.data" :key="item.id" class="border-b">
                        <!-- <-- DIPERBAIKI: item.user -> item.pegawai (sesuai relasi
                             di MonitoringCutiController.php: with('pegawai')) -->
                        <td class="p-3">{{ item.pegawai?.nama }}</td>
                        <td class="p-3">{{ item.jenis_cuti }}</td>
                        <td class="p-3">{{ item.tanggal_mulai }} s/d {{ item.tanggal_selesai }}</td>
                        <td class="p-3 font-bold uppercase"
                            :class="{
                                'text-yellow-500': item.status?.includes('menunggu'),
                                'text-green-500': item.status === 'disetujui',
                                'text-red-500': item.status === 'ditolak',
                                'text-orange-500': item.status === 'ditangguhkan',
                            }">
                            {{ item.status?.replace(/_/g, ' ') }}
                        </td>
                    </tr>
                    <tr v-if="!pengajuan.data || pengajuan.data.length === 0">
                        <td colspan="4" class="p-6 text-center text-gray-400">
                            Tidak ada data pengajuan cuti yang cocok.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </MainLayout>
</template>

