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
    daftarKelompokSubstansi: {
        type: Array,
        default: () => [],
    },
    pagination: {
        type: Object,
        default: () => ({
            current_page: 1,
            last_page: 1,
            per_page: 10,
            total: 0,
        }),
    },
});


const searchQuery = ref(props.filters.search || "");
const filterTahun = ref(
    props.filters.tahun || new Date().getFullYear().toString(),
);
const filterBulan = ref(props.filters.bulan || "");
const filterKelompok = ref(props.filters.kelompok_substansi || "");


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


const fetchFilteredData = (page = 1) => {
    router.get(
        route("admin.rekap"),
        {
            search: searchQuery.value,
            tahun: filterTahun.value,
            bulan: filterBulan.value,
            kelompok_substansi: filterKelompok.value,
            page,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
};


watch([filterTahun, filterBulan, filterKelompok], () => {
    fetchFilteredData(1);
});


let searchTimeout = null;
watch(searchQuery, () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        fetchFilteredData(1);
    }, 500);
});


const goToPage = (page) => {
    if (page < 1 || page > props.pagination.last_page) return;
    if (page === props.pagination.current_page) return;
    fetchFilteredData(page);
};


const startIndex = computed(
    () => (props.pagination.current_page - 1) * props.pagination.per_page,
);


const pageNumbers = computed(() => {
    const total = props.pagination.last_page;
    const current = props.pagination.current_page;
    const delta = 2;
    const range = [];
    for (
        let i = Math.max(1, current - delta);
        i <= Math.min(total, current + delta);
        i++
    ) {
        range.push(i);
    }
    return range;
});


const columnsToShow = computed(() => {
    if (filterBulan.value) {
        const index = parseInt(filterBulan.value, 10) - 1;
        return [{ label: bulanList[index], key: keyBulan[index] }];
    }
    return bulanList.map((label, index) => ({ label, key: keyBulan[index] }));
});


const isFilterAktif = computed(() => {
    return (
        Boolean(searchQuery.value) ||
        Boolean(filterBulan.value) ||
        Boolean(filterKelompok.value)
    );
});


// Daftar chip filter aktif — hanya berisi filter yang bukan nilai default,
// jadi baris chip ini otomatis kosong (dan disembunyikan) saat admin belum memfilter apa pun.
const activeFilterChips = computed(() => {
    const chips = [];
    if (searchQuery.value) {
        chips.push({
            key: "search",
            label: `Pencarian: "${searchQuery.value}"`,
            clear: () => {
                searchQuery.value = "";
            },
        });
    }
    if (filterKelompok.value) {
        chips.push({
            key: "kelompok",
            label: filterKelompok.value,
            clear: () => {
                filterKelompok.value = "";
            },
        });
    }
    if (filterBulan.value) {
        chips.push({
            key: "bulan",
            label: bulanList[parseInt(filterBulan.value, 10) - 1],
            clear: () => {
                filterBulan.value = "";
            },
        });
    }
    return chips;
});


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


const resetFilters = () => {
    searchQuery.value = "";
    filterBulan.value = "";
    filterKelompok.value = "";
    fetchFilteredData(1);
};
</script>


<template>
    <Head title="Rekap Laporan Cuti" />


    <MainLayout>
        <div class="p-4 sm:p-6">
            <div
                class="bg-white overflow-hidden shadow-sm sm:rounded-xl border border-gray-200 p-4 sm:p-6"
            >
                <!-- ===================== JUDUL + EXPORT (baris atas) ===================== -->
                <!-- Judul & subjudul di kiri, tombol Export selalu di
                 kanan atas baris judul — sesuai aturan layout prototype. -->
                <div
                    class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-5 gap-4"
                >
                    <div>
                        <h3 class="text-lg font-bold text-gray-800">
                            Rekapitulasi Cuti Tahunan Pegawai
                        </h3>
                        <p class="text-sm text-gray-500 mt-0.5">
                            Rekap pemakaian cuti tiap pegawai per bulan dalam
                            satu tahun.
                        </p>
                    </div>


                    <a
                        :href="
                            route('admin.rekap.export', {
                                search: searchQuery,
                                tahun: filterTahun,
                                bulan: filterBulan,
                                kelompok_substansi: filterKelompok,
                            })
                        "
                        target="_blank"
                        class="w-full sm:w-auto inline-flex items-center justify-center gap-1.5 px-5 py-2.5 bg-green-700 hover:bg-green-800 text-white rounded-full text-sm font-semibold transition-colors shadow-sm whitespace-nowrap shrink-0"
                    >
                        <svg
                            class="w-4 h-4"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"
                            ></path>
                        </svg>
                        Export Excel
                    </a>
                </div>


                <!-- ===================== TOOLBAR: SEARCH + FILTER ===================== -->
                <!-- Baris tersendiri tepat di bawah judul: search melebar
                 mengisi sisa ruang, filter-filter berjajar rapat di ujung
                 kanan dengan lebar tetap — sesuai proporsi di prototype. -->
                <div
                    class="flex flex-col sm:flex-row sm:items-center gap-2 mb-3"
                >
                    <!-- Search -->
                    <div class="relative w-full sm:flex-1">
                        <div
                            class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"
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
                        </div>
                        <input
                            type="text"
                            v-model="searchQuery"
                            placeholder="Cari nama / NIP..."
                            class="w-full pl-9 pr-3 py-2 text-xs border border-gray-300 focus:border-emerald-500 focus:ring-emerald-500 rounded-lg shadow-sm bg-white"
                        />
                    </div>


                    <!-- Filter group: satu baris yang bisa digeser di mobile, bukan ditumpuk satu-satu.
                     Lebar tiap filter dipatok (bukan min-width) supaya tidak melebar mengikuti
                     panjang teks opsi terpilih, konsisten dengan lebar di prototype. -->
                    <div
                        class="flex items-center gap-2 overflow-x-auto sm:overflow-visible -mx-4 sm:mx-0 px-4 sm:px-0 pb-1 sm:pb-0 scrollbar-thin"
                    >
                        <select
                            v-model="filterKelompok"
                            class="flex-none w-56 truncate text-xs border border-gray-300 focus:border-emerald-500 focus:ring-emerald-500 rounded-lg shadow-sm text-gray-600 py-2 pl-3 pr-8 bg-white"
                        >
                            <option value="">Semua Kelompok Substansi</option>
                            <option
                                v-for="kelompok in daftarKelompokSubstansi"
                                :key="kelompok"
                                :value="kelompok"
                            >
                                {{ kelompok }}
                            </option>
                        </select>


                        <div class="relative flex-none">
                            <select
                                v-model="filterBulan"
                                class="w-36 truncate text-xs border border-gray-300 focus:border-emerald-500 focus:ring-emerald-500 rounded-lg shadow-sm text-gray-600 py-2 pl-3 pr-8 appearance-none bg-none bg-white"
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
                                    class="w-3.5 h-3.5 text-gray-500"
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


                        <div class="relative flex-none">
                            <select
                                v-model="filterTahun"
                                class="w-24 text-xs border border-gray-300 focus:border-emerald-500 focus:ring-emerald-500 rounded-lg shadow-sm text-gray-600 py-2 pl-3 pr-8 appearance-none bg-none bg-white"
                            >
                                <option value="2026">2026</option>
                                <option value="2025">2025</option>
                                <option value="2024">2024</option>
                            </select>
                            <span
                                class="absolute inset-y-0 right-0 flex items-center pr-2 pointer-events-none"
                            >
                                <svg
                                    class="w-3.5 h-3.5 text-gray-500"
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
                    </div>
                </div>


                <!-- ===================== CHIP FILTER AKTIF ===================== -->
                <!-- Hanya dirender kalau ada minimal 1 filter aktif (search / kelompok / bulan) -->
                <div
                    v-if="activeFilterChips.length > 0"
                    class="flex flex-wrap items-center gap-2 mb-4 text-xs text-gray-500"
                >
                    <span>Filter aktif:</span>
                    <span
                        v-for="chip in activeFilterChips"
                        :key="chip.key"
                        class="inline-flex items-center gap-1.5 bg-emerald-50 text-emerald-700 font-semibold pl-3 pr-2 py-1 rounded-full"
                    >
                        {{ chip.label }}
                        <button
                            type="button"
                            @click="chip.clear()"
                            class="text-emerald-600/70 hover:text-emerald-700"
                            aria-label="Hapus filter"
                        >
                            ✕
                        </button>
                    </span>
                    <button
                        type="button"
                        @click="resetFilters"
                        class="text-gray-400 underline hover:text-gray-600"
                    >
                        Hapus semua
                    </button>
                </div>


                <!-- ===================== TABLE ===================== -->
                <div class="overflow-x-auto rounded-lg border border-gray-200">
                    <table class="divide-y divide-gray-200" style="width: auto">
                        <thead class="bg-gray-50 border-b-2 border-gray-200">
                            <tr>
                                <th
                                    class="sticky left-0 z-20 bg-gray-50 px-4 py-4 text-center text-xs font-bold text-gray-700 w-12 border-r border-gray-200"
                                >
                                    No
                                </th>
                                <th
                                    class="sticky left-12 z-20 bg-gray-50 px-6 py-4 text-left text-xs font-bold text-gray-700 min-w-[220px] sm:min-w-[260px] border-r-2 border-gray-300 shadow-[2px_0_4px_-2px_rgba(0,0,0,0.08)]"
                                >
                                    Nama Pegawai
                                </th>
                                <th
                                    v-for="col in columnsToShow"
                                    :key="col.key"
                                    class="px-2 py-3 text-center text-xs font-bold text-gray-700 whitespace-nowrap border-r border-gray-200 last:border-r-0 w-20 max-w-[5rem]"
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
                                :key="item.id || startIndex + index"
                                class="group hover:bg-violet-50 hover:shadow-[inset_4px_0_0_0_theme(colors.violet.400)] transition-all duration-150"
                            >
                                <td
                                    class="sticky left-0 z-10 bg-white group-hover:bg-violet-50 px-4 py-4 whitespace-nowrap text-center text-sm font-semibold text-gray-800 border-r border-gray-200 transition-colors duration-150"
                                >
                                    {{ startIndex + index + 1 }}
                                </td>
                                <td
                                    class="sticky left-12 z-10 bg-white group-hover:bg-violet-50 px-6 py-4 whitespace-nowrap border-r-2 border-gray-300 shadow-[2px_0_4px_-2px_rgba(0,0,0,0.08)] transition-colors duration-150"
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
                                <td
                                    v-for="col in columnsToShow"
                                    :key="col.key"
                                    class="px-2 py-3 whitespace-nowrap text-center text-sm border-r border-gray-200 last:border-r-0 w-20 max-w-[5rem]"
                                >
                                    <div
                                        :class="
                                            item.cuti && item.cuti[col.key] > 0
                                                ? 'mx-auto w-9 py-1.5 bg-blue-100 text-blue-800 font-bold rounded-lg shadow-sm text-xs'
                                                : 'mx-auto w-9 py-1.5 bg-gray-100 text-gray-500 font-medium rounded-lg text-xs'
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
                                            class="mt-1 inline-flex items-center gap-1.5 px-4 py-2 bg-white border border-gray-300 rounded-full text-xs font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-800 transition-colors"
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
                    </table>
                </div>


                <div
                    v-if="laporan.length > 0"
                    class="flex flex-col sm:flex-row items-center justify-between gap-3 mt-4"
                >
                    <p class="text-xs text-gray-500">
                        Menampilkan
                        <span class="font-semibold text-gray-700">{{
                            startIndex + 1
                        }}</span>
                        -
                        <span class="font-semibold text-gray-700">{{
                            Math.min(
                                startIndex + pagination.per_page,
                                pagination.total,
                            )
                        }}</span>
                        dari
                        <span class="font-semibold text-gray-700">{{
                            pagination.total
                        }}</span>
                        pegawai
                    </p>


                    <div
                        v-if="pagination.last_page > 1"
                        class="flex items-center gap-1"
                    >
                        <button
                            type="button"
                            :disabled="pagination.current_page === 1"
                            @click="goToPage(pagination.current_page - 1)"
                            class="px-3 py-1.5 text-xs font-semibold rounded-full border border-gray-300 text-gray-600 hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                        >
                            Sebelumnya
                        </button>


                        <button
                            v-if="pageNumbers[0] > 1"
                            type="button"
                            @click="goToPage(1)"
                            class="w-8 h-8 text-xs font-semibold rounded-full border border-gray-300 text-gray-600 hover:bg-gray-50 transition-colors"
                        >
                            1
                        </button>
                        <span
                            v-if="pageNumbers[0] > 2"
                            class="px-1 text-xs text-gray-400"
                            >...</span
                        >


                        <button
                            v-for="page in pageNumbers"
                            :key="page"
                            type="button"
                            @click="goToPage(page)"
                            :class="[
                                'w-8 h-8 text-xs font-semibold rounded-full border transition-colors',
                                page === pagination.current_page
                                    ? 'bg-blue-600 border-blue-600 text-white'
                                    : 'border-gray-300 text-gray-600 hover:bg-gray-50',
                            ]"
                        >
                            {{ page }}
                        </button>


                        <span
                            v-if="
                                pageNumbers[pageNumbers.length - 1] <
                                pagination.last_page - 1
                            "
                            class="px-1 text-xs text-gray-400"
                            >...</span
                        >
                        <button
                            v-if="
                                pageNumbers[pageNumbers.length - 1] <
                                pagination.last_page
                            "
                            type="button"
                            @click="goToPage(pagination.last_page)"
                            class="w-8 h-8 text-xs font-semibold rounded-full border border-gray-300 text-gray-600 hover:bg-gray-50 transition-colors"
                        >
                            {{ pagination.last_page }}
                        </button>


                        <button
                            type="button"
                            :disabled="
                                pagination.current_page === pagination.last_page
                            "
                            @click="goToPage(pagination.current_page + 1)"
                            class="px-3 py-1.5 text-xs font-semibold rounded-full border border-gray-300 text-gray-600 hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                        >
                            Berikutnya
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </MainLayout>
</template>





