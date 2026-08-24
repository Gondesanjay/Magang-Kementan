<script setup>
import MainLayout from "@/Layouts/MainLayout.vue";
import { Head, router, usePage } from "@inertiajs/vue3";
import { ref, watch, computed } from "vue";


// Debounce lokal (tidak butuh package lodash)
function debounce(fn, delay) {
    let timeoutId;
    return (...args) => {
        clearTimeout(timeoutId);
        timeoutId = setTimeout(() => fn(...args), delay);
    };
}


const props = defineProps({
    antrean: Object, // paginated: { data: [], links: [], ... }
    filters: Object,
});


// Admin HR (role 5) hanya memonitor, tidak bisa approve/reject
const page = usePage();
const isReadOnly = computed(() => page.props.auth.user.role_id === 5);


const search = ref(props.filters?.search || "");
const jenisCuti = ref(props.filters?.jenis_cuti || "Semua Jenis Cuti");


// Auto-search saat mengetik atau mengganti filter jenis cuti
watch(
    [search, jenisCuti],
    debounce(function ([newSearch, newJenis]) {
        router.get(
            route("atasan.approval"),
            {
                search: newSearch,
                jenis_cuti: newJenis,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    }, 300),
);


const statusLabels = {
    menunggu_l1: "Menunggu Ketua Tim Kerja (L1)",
    menunggu_l2: "Menunggu Ketua Kelompok Substansi (L2)",
    menunggu_l3: "Menunggu Kasubag TU (L3)",
    menunggu_l4: "Menunggu Kepala Biro Perencanaan (L4)",
};


// Fungsi memformat tanggal (YYYY-MM-DD ke DD-MM-YYYY)
const formatDate = (dateString) => {
    if (!dateString) return "-";
    const date = new Date(dateString);
    return new Intl.DateTimeFormat("id-ID", {
        day: "2-digit",
        month: "short",
        year: "numeric",
    }).format(date);
};


// Fungsi memproses aksi Setujui / Tolak
const processApproval = (id, action) => {
    const actionText = action === "approve" ? "MENYETUJUI" : "MENOLAK";
    if (confirm(`Apakah Anda yakin ingin ${actionText} pengajuan cuti ini?`)) {
        router.post(
            route("atasan.approval.process", id),
            { action: action },
            {
                preserveScroll: true,
            },
        );
    }
};


// Navigasi pagination (Laravel paginator links)
const goToPage = (url) => {
    if (!url) return;
    router.get(
        url,
        {},
        {
            preserveState: true,
            preserveScroll: true,
        },
    );
};
</script>


<template>
    <Head title="Antrean Approval" />


    <MainLayout>
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
            <div
                class="flex flex-col md:flex-row justify-between items-center mb-6 gap-4"
            >
                <h2 class="text-2xl font-bold text-gray-800">
                    Antrean Persetujuan Cuti
                </h2>


                <div class="flex flex-col sm:flex-row gap-3 w-full md:w-auto">
                    <!-- Dropdown Jenis Cuti -->
                    <select
                        v-model="jenisCuti"
                        class="border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-lg text-sm text-gray-600"
                    >
                        <option value="Semua Jenis Cuti">
                            Semua Jenis Cuti
                        </option>
                        <option value="Cuti Tahunan">Cuti Tahunan</option>
                        <option value="Cuti Sakit">Cuti Sakit</option>
                        <option value="Cuti Alasan Penting">
                            Cuti Alasan Penting
                        </option>
                        <option value="Cuti Melahirkan">
                            Cuti Melahirkan
                        </option>
                    </select>


                    <!-- Input Pencarian -->
                    <div class="relative">
                        <div
                            class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"
                        >
                            <svg
                                class="h-4 w-4 text-gray-400"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
                                />
                            </svg>
                        </div>
                        <input
                            v-model="search"
                            type="text"
                            placeholder="Cari nama, NIP..."
                            class="pl-10 border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-lg text-sm w-full md:w-64"
                        />
                    </div>
                </div>
            </div>


            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th
                                scope="col"
                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                            >
                                Nama Karyawan
                            </th>


                            <!-- PINDAHKAN TH JENIS CUTI KE SINI (Setelah Nama) -->
                            <th
                                scope="col"
                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                            >
                                Jenis Cuti
                            </th>


                            <!-- TH TANGGAL CUTI DIGESER KE SINI (Setelah Jenis Cuti) -->
                            <th
                                scope="col"
                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                            >
                                Tanggal Cuti
                            </th>


                            <th
                                scope="col"
                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                            >
                                Durasi
                            </th>
                            <th
                                scope="col"
                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                            >
                                Tahap
                            </th>
                            <th
                                scope="col"
                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                            >
                                Keterangan
                            </th>
                            <th
                                scope="col"
                                class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider"
                            >
                                Aksi
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <tr
                            v-for="item in antrean.data"
                            :key="item.id"
                            class="hover:bg-gray-50"
                        >
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900">
                                    {{ item.pegawai.nama }}
                                </div>
                                <div class="text-xs text-gray-500">
                                    {{ item.pegawai.departemen }}
                                </div>
                            </td>


                            <!-- KOLOM BARU: JENIS CUTI -->
                            <td
                                class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"
                            >
                                <span
                                    class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800"
                                >
                                    <!-- Sesuaikan jika format databasenya butuh item.jenis_cuti.nama_cuti -->
                                    {{ item.jenis_cuti }}
                                </span>
                            </td>


                            <td
                                class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"
                            >
                                {{ formatDate(item.tanggal_mulai) }} s/d
                                {{ formatDate(item.tanggal_selesai) }}
                            </td>
                            <td
                                class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"
                            >
                                {{ item.jumlah_hari }} Hari
                            </td>
                            <td
                                class="px-6 py-4 whitespace-nowrap text-sm font-medium text-amber-700"
                            >
                                {{ statusLabels[item.status] || item.status }}
                            </td>
                            <td
                                class="px-6 py-4 text-sm text-gray-500 max-w-xs truncate"
                                :title="item.keterangan"
                            >
                                {{ item.keterangan }}
                            </td>
                            <td
                                class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium space-x-2"
                            >
                                <template v-if="!isReadOnly">
                                    <button
                                        @click="processApproval(item.id, 'approve')"
                                        class="inline-flex items-center px-3 py-1 bg-green-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-green-700 transition ease-in-out duration-150"
                                    >
                                        Setujui
                                    </button>
                                    <button
                                        @click="processApproval(item.id, 'reject')"
                                        class="inline-flex items-center px-3 py-1 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-700 transition ease-in-out duration-150"
                                    >
                                        Tolak
                                    </button>
                                </template>
                                <span
                                    v-else
                                    class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-500"
                                >
                                    Monitor
                                </span>
                            </td>
                        </tr>
                        <tr v-if="antrean.data.length === 0">
                            <!-- COLSPAN DIUBAH MENJADI 7 KARENA ADA TAMBAHAN 1 KOLOM -->
                            <td
                                colspan="7"
                                class="px-6 py-8 text-center text-gray-500"
                            >
                                Saat ini tidak ada antrean pengajuan cuti yang
                                memerlukan persetujuan Anda.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>


            <!-- Pagination -->
            <div
                v-if="antrean.links && antrean.links.length > 3"
                class="flex flex-wrap items-center justify-between gap-2 mt-6"
            >
                <p class="text-sm text-gray-500">
                    Menampilkan {{ antrean.from ?? 0 }}–{{ antrean.to ?? 0 }}
                    dari {{ antrean.total ?? 0 }} data
                </p>
                <div class="flex flex-wrap gap-1">
                    <button
                        v-for="(link, index) in antrean.links"
                        :key="index"
                        v-html="link.label"
                        :disabled="!link.url"
                        @click="goToPage(link.url)"
                        class="px-3 py-1 text-sm rounded-md border"
                        :class="[
                            link.active
                                ? 'bg-blue-600 border-blue-600 text-white'
                                : 'bg-white border-gray-300 text-gray-600 hover:bg-gray-50',
                            !link.url
                                ? 'opacity-50 cursor-not-allowed'
                                : 'cursor-pointer',
                        ]"
                    ></button>
                </div>
            </div>
        </div>
    </MainLayout>
</template>

