<script setup>
import MainLayout from "@/Layouts/MainLayout.vue";
import { Head, router } from "@inertiajs/vue3";
import { ref, watch, computed } from "vue";


const props = defineProps({
    laporan: {
        type: Array,
        default: () => [],
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
});


// State untuk filter, mengambil nilai default dari backend jika ada
const searchQuery = ref(props.filters.search || "");
const filterTahun = ref(
    props.filters.tahun || new Date().getFullYear().toString(),
);
const filterBulan = ref(props.filters.bulan || "");


// Daftar bulan untuk looping header tabel dan key data
const bulanList = [
    "Januari",
    "Februari",
    "Maret",
    "April",
    "Mei",
    "Juni",
    "Juli",
    "Agustus",
    "September",
    "Oktober",
    "November",
    "Desember",
];
const keyBulan = [
    "jan",
    "feb",
    "mar",
    "apr",
    "mei",
    "jun",
    "jul",
    "agu",
    "sep",
    "okt",
    "nov",
    "des",
];


// Fungsi Auto-Submit menggunakan Inertia router.get
const fetchFilteredData = () => {
    router.get(
        route("admin.rekap"), // Nama route sesuai pendaftaran di routes/web.php
        {
            search: searchQuery.value,
            tahun: filterTahun.value,
            bulan: filterBulan.value,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
};


// Watcher untuk Dropdown (Langsung fetch saat dipilih)
watch([filterTahun, filterBulan], () => {
    fetchFilteredData();
});


// Watcher untuk Input Pencarian (Menggunakan delay/debounce agar tidak spam request saat mengetik)
let searchTimeout = null;
watch(searchQuery, () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        fetchFilteredData();
    }, 500); // delay 500ms
});


// Kolom bulan yang ditampilkan di tabel.
// Jika filterBulan dipilih (misal Agustus), tabel hanya menampilkan 1 kolom bulan itu.
// Jika "Semua Bulan", tabel menampilkan 12 kolom seperti biasa.
const columnsToShow = computed(() => {
    if (filterBulan.value) {
        const index = parseInt(filterBulan.value, 10) - 1;
        return [
            {
                label: bulanList[index],
                key: keyBulan[index],
            },
        ];
    }


    return bulanList.map((label, index) => ({
        label,
        key: keyBulan[index],
    }));
});


// ================= PERBAIKAN TAMPILAN EMPTY STATE =================
// Sebelumnya pesan kosong selalu generik ("Belum ada data rekapitulasi cuti
// di sistem") walau penyebabnya cuma filter (bulan/pencarian) yang membuat
// hasilnya nihil — ini bisa menyesatkan Admin HR, seolah SELURUH sistem
// kosong padahal cuma bulan/pencarian tertentu saja yang tidak ada datanya.
// Sekarang pesan menyesuaikan filter yang sedang aktif, plus disediakan
// tombol Reset Filter supaya Admin HR mudah kembali melihat semua data.


// Apakah ada filter yang sedang aktif (selain tahun, karena tahun selalu terisi)
const isFilterAktif = computed(() => {
    return Boolean(searchQuery.value) || Boolean(filterBulan.value);
});


// Pesan kontekstual untuk empty state, menyebutkan filter yang sedang aktif
const emptyStateMessage = computed(() => {
    const bagianBulan = filterBulan.value
        ? bulanList[parseInt(filterBulan.value, 10) - 1]
        : null;


    if (bagianBulan && searchQuery.value) {
        return `Tidak ditemukan pengajuan cuti untuk "${searchQuery.value}" pada bulan ${bagianBulan} ${filterTahun.value}.`;
    }
    if (bagianBulan) {
        return `Tidak ada pengajuan cuti pada bulan ${bagianBulan} ${filterTahun.value}.`;
    }
    if (searchQuery.value) {
        return `Tidak ditemukan pegawai dengan nama atau NIP "${searchQuery.value}".`;
    }
    return `Belum ada data rekapitulasi cuti untuk tahun ${filterTahun.value}.`;
});


// Reset semua filter (kecuali tahun, biarkan tahun berjalan tetap dipilih)
// supaya Admin HR bisa langsung melihat kembali seluruh data.
const resetFilters = () => {
    searchQuery.value = "";
    filterBulan.value = "";
    fetchFilteredData();
};
// ================= END PERBAIKAN TAMPILAN EMPTY STATE =================
</script>


<template>
    <Head title="Rekap Laporan Cuti" />


    <MainLayout>
        <div class="p-6">
            <div
                class="bg-white overflow-hidden shadow-sm sm:rounded-xl border border-gray-200 p-6"
            >
                <!-- Bagian Header & Filter -->
                <div
                    class="flex flex-col xl:flex-row justify-between items-start xl:items-center mb-6 gap-4"
                >
                    <h2 class="text-xl font-bold text-gray-700">
                        Rekapitulasi Cuti Tahunan Pegawai
                    </h2>


                    <div
                        class="flex flex-wrap items-center gap-3 w-full xl:w-auto"
                    >
                        <!-- Input Pencarian -->
                        <div class="relative flex-grow md:flex-grow-0">
                            <span
                                class="absolute inset-y-0 left-0 flex items-center pl-3"
                            >
                                <svg
                                    class="w-4 h-4 text-gray-400"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
                                    ></path>
                                </svg>
                            </span>
                            <input
                                type="text"
                                v-model="searchQuery"
                                placeholder="Cari nama atau NIP..."
                                class="pl-10 pr-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500 w-full md:w-64"
                            />
                        </div>


                        <!-- Dropdown Bulan -->
                        <div class="relative">
                            <select
                                v-model="filterBulan"
                                class="pl-4 pr-8 py-2 border border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500 appearance-none bg-white min-w-[120px]"
                            >
                                <option value="">Semua Bulan</option>
                                <option
                                    v-for="(bulan, index) in bulanList"
                                    :key="index"
                                    :value="index + 1"
                                >
                                    {{ bulan }}
                                </option>
                            </select>
                            <span
                                class="absolute inset-y-0 right-0 flex items-center pr-2 pointer-events-none"
                            >
                                <svg
                                    class="w-4 h-4 text-gray-400"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M19 9l-7 7-7-7"
                                    ></path>
                                </svg>
                            </span>
                        </div>


                        <!-- Dropdown Tahun -->
                        <div class="relative">
                            <span
                                class="absolute inset-y-0 left-0 flex items-center pl-3"
                            >
                                <svg
                                    class="w-4 h-4 text-gray-400"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"
                                    ></path>
                                </svg>
                            </span>
                            <select
                                v-model="filterTahun"
                                class="pl-10 pr-8 py-2 border border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500 appearance-none bg-white"
                            >
                                <option value="2026">2026</option>
                                <option value="2025">2025</option>
                                <option value="2024">2024</option>
                            </select>
                            <span
                                class="absolute inset-y-0 right-0 flex items-center pr-2 pointer-events-none"
                            >
                                <svg
                                    class="w-4 h-4 text-gray-400"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M19 9l-7 7-7-7"
                                    ></path>
                                </svg>
                            </span>
                        </div>


                        <!-- Tombol Export Data Excel -->
                        <a
                            :href="
                                route('admin.rekap.export', {
                                    search: searchQuery,
                                    tahun: filterTahun,
                                    bulan: filterBulan,
                                })
                            "
                            target="_blank"
                            class="px-4 py-2 bg-green-600 text-white rounded-lg text-sm font-semibold hover:bg-green-700 flex items-center transition-colors"
                        >
                            <svg
                                class="w-4 h-4 mr-2"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                                ></path>
                            </svg>
                            Export Excel
                        </a>
                    </div>
                </div>


                <!-- Tabel Matriks Sesuai Screenshot -->
                <div class="overflow-x-auto rounded-lg border border-gray-200">
                    <table class="divide-y divide-gray-200" style="width: auto">
                        <thead class="bg-gray-50 border-b-2 border-gray-200">
                            <tr>
                                <th
                                    class="px-4 py-4 text-center text-xs font-bold text-gray-700 w-12 border-r border-gray-200"
                                >
                                    No
                                </th>
                                <th
                                    class="px-6 py-4 text-left text-xs font-bold text-gray-700 min-w-[280px] border-r border-gray-200"
                                >
                                    Nama Pegawai
                                </th>
                                <!-- Looping Header Bulan (dinamis: 1 kolom jika difilter, 12 jika Semua Bulan) -->
                                <th
                                    v-for="col in columnsToShow"
                                    :key="col.key"
                                    class="px-3 py-4 text-center text-xs font-bold text-gray-700 whitespace-nowrap border-r border-gray-200 last:border-r-0 w-32 max-w-[8rem]"
                                >
                                    {{ col.label }}
                                </th>
                                <th
                                    class="px-4 py-4 text-center text-xs font-bold text-gray-700 whitespace-nowrap border-r border-gray-200 bg-slate-50"
                                >
                                    Total Cuti
                                </th>
                            </tr>
                        </thead>
                        <tbody
                            v-if="laporan.length > 0"
                            class="bg-white divide-y divide-gray-200"
                        >
                            <tr
                                v-for="(item, index) in laporan"
                                :key="item.id || index"
                                class="hover:bg-violet-50 hover:shadow-[inset_4px_0_0_0_theme(colors.violet.400)] transition-all duration-150"
                            >
                                <td
                                    class="px-4 py-4 whitespace-nowrap text-center text-sm font-semibold text-gray-800 border-r border-gray-200"
                                >
                                    {{ index + 1 }}
                                </td>
                                <td
                                    class="px-6 py-4 whitespace-nowrap border-r border-gray-200"
                                >
                                    <div
                                        class="text-sm font-bold text-gray-800 uppercase"
                                    >
                                        {{ item.nama }}
                                    </div>
                                    <div class="text-xs text-gray-500 mt-1">
                                        '{{ item.nip }}
                                    </div>
                                </td>
                                <!-- Looping Data Angka Cuti Per Bulan (dinamis, ikut columnsToShow) -->
                                <td
                                    v-for="col in columnsToShow"
                                    :key="col.key"
                                    class="px-3 py-4 whitespace-nowrap text-center text-sm border-r border-gray-200 last:border-r-0 w-32 max-w-[8rem]"
                                >
                                    <div
                                        :class="
                                            item.cuti && item.cuti[col.key] > 0
                                                ? 'mx-auto w-10 py-1.5 bg-blue-100 text-blue-800 font-bold rounded-lg shadow-sm'
                                                : 'mx-auto w-10 py-1.5 bg-gray-100 text-gray-500 font-medium rounded-lg'
                                        "
                                    >
                                        {{
                                            item.cuti &&
                                            item.cuti[col.key] !== undefined
                                                ? item.cuti[col.key]
                                                : 0
                                        }}
                                    </div>
                                </td>
                                <!-- Total Cuti (Pembanding) -->
                                <td
                                    class="px-4 py-4 whitespace-nowrap text-center border-r border-gray-200 bg-slate-50"
                                >
                                    <div
                                        class="mx-auto w-12 py-1.5 bg-emerald-100 text-emerald-800 font-bold rounded-lg shadow-sm text-sm"
                                    >
                                        {{ item.total ?? 0 }}
                                    </div>
                                </td>
                            </tr>
                        </tbody>


                        <!-- ================= PERBAIKAN EMPTY STATE =================
                             Pesan sekarang menyesuaikan filter yang sedang aktif (lihat
                             computed emptyStateMessage), dilengkapi ikon supaya tidak
                             polos, dan tombol Reset Filter kalau memang ada filter yang
                             aktif (bulan dan/atau pencarian) supaya Admin HR mudah
                             kembali melihat seluruh data tanpa harus reset manual satu
                             per satu. -->
                        <tbody v-else class="bg-white">
                            <tr>
                                <td
                                    :colspan="3 + columnsToShow.length"
                                    class="px-6 py-14"
                                >
                                    <div
                                        class="flex flex-col items-center justify-center text-center gap-3"
                                    >
                                        <div
                                            class="w-14 h-14 rounded-2xl bg-slate-100 flex items-center justify-center"
                                        >
                                            <svg
                                                class="w-7 h-7 text-slate-400"
                                                fill="none"
                                                stroke="currentColor"
                                                viewBox="0 0 24 24"
                                            >
                                                <path
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                    stroke-width="1.5"
                                                    d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"
                                                ></path>
                                            </svg>
                                        </div>
                                        <p
                                            class="text-sm font-semibold text-gray-600 max-w-sm"
                                        >
                                            {{ emptyStateMessage }}
                                        </p>
                                        <button
                                            v-if="isFilterAktif"
                                            type="button"
                                            @click="resetFilters"
                                            class="mt-1 inline-flex items-center gap-1.5 px-4 py-2 bg-white border border-gray-300 rounded-lg text-xs font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-800 transition-colors"
                                        >
                                            <svg
                                                class="w-3.5 h-3.5"
                                                fill="none"
                                                stroke="currentColor"
                                                viewBox="0 0 24 24"
                                            >
                                                <path
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                    stroke-width="2"
                                                    d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
                                                ></path>
                                            </svg>
                                            Reset Filter
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                        <!-- ================= END PERBAIKAN EMPTY STATE ================= -->
                    </table>
                </div>
            </div>
        </div>
    </MainLayout>
</template>




