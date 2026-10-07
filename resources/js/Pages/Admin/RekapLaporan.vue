<script setup>
import MainLayout from "@/Layouts/MainLayout.vue";
import { Head, router } from "@inertiajs/vue3";
import { ref, watch, computed } from "vue";
import { useTahunFilter } from "@/composables/useTahunFilter";


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

// ================================================================
// [BARU] DAFTAR TAHUN FILTER — SEKARANG DINAMIS (bukan hardcode lagi)
// Sebelumnya opsi tahun di-hardcode langsung di template
// (<option value="2026">2026</option>, dst), sehingga setiap
// pergantian tahun WAJIB edit kode & deploy ulang, dan rawan halaman
// lain (Rekap Kuota Detail, Monitoring Cuti, dsb) jadi tidak
// konsisten kalau lupa diupdate juga.
//
// Sekarang dipakai composable reusable useTahunFilter() yang
// menghasilkan daftar tahun otomatis relatif terhadap tahun berjalan
// (default: 2 tahun ke belakang + 1 tahun ke depan dari tahun
// sekarang), sehingga TIDAK PERNAH perlu disentuh lagi setiap tahun.
// Composable yang sama bisa dipakai ulang di halaman lain supaya
// semua filter tahun otomatis seragam.
// ================================================================
const daftarTahunFilter = useTahunFilter();


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
                            id="cari-laporan"
                            name="cari_laporan"
                            type="text"
                            v-model="searchQuery"
                            placeholder="Cari nama / NIP..."
                            aria-label="Cari nama atau NIP pegawai"
                            class="w-full min-h-[44px] pl-9 pr-3 py-2 text-base sm:text-sm border border-gray-300 focus:border-emerald-500 focus:ring-emerald-500 rounded-lg shadow-sm bg-white"
                        />
                    </div>


                    <!-- Filter group: satu baris yang bisa digeser di mobile, bukan ditumpuk satu-satu.
                     Lebar tiap filter dipatok (bukan min-width) supaya tidak melebar mengikuti
                     panjang teks opsi terpilih, konsisten dengan lebar di prototype. -->
                    <div
                        class="flex flex-col items-stretch gap-2 pb-1 sm:flex-row sm:flex-wrap sm:items-center"
                    >
                        <!-- Filter Kelompok Substansi dengan Icon — DIPERBARUI:
                         lebar sekarang mengikuti panjang teks opsi yang sedang
                         dipilih (w-auto, tanpa truncate, dengan lebar minimum
                         min-w-[190px] supaya tidak terlalu sempit saat opsi
                         "Semua Kelompok Substansi" yang dipilih), diberi ikon
                         funnel di kiri + panah custom di kanan, supaya
                         konsisten dengan tampilan filter yang sama di halaman
                         Monitoring Cuti. -->
                        <div class="relative flex-none">
                            <!-- Icon Funnel/Filter di sebelah kiri -->
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
                                        d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"
                                    />
                                </svg>
                            </div>


                            <select
                                id="filter-kelompok-laporan"
                                name="kelompok"
                                v-model="filterKelompok"
                                aria-label="Filter kelompok substansi"
                                class="w-full min-h-[44px] min-w-0 text-base sm:w-auto sm:min-w-[190px] sm:text-sm border border-gray-300 focus:border-emerald-500 focus:ring-emerald-500 rounded-lg shadow-sm text-gray-600 py-2 pl-9 pr-8 appearance-none bg-none bg-white"
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


                            <!-- Icon Panah Bawah di sebelah kanan -->
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
                                id="filter-bulan-laporan"
                                name="bulan"
                                v-model="filterBulan"
                                aria-label="Filter bulan"
                                class="w-full min-h-[44px] truncate text-base sm:w-36 sm:text-sm border border-gray-300 focus:border-emerald-500 focus:ring-emerald-500 rounded-lg shadow-sm text-gray-600 py-2 pl-3 pr-8 appearance-none bg-none bg-white"
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


                        <!-- ================================================
                             FILTER TAHUN — SEKARANG DINAMIS
                             Sebelumnya: 3 <option> hardcode (2026/2025/2024).
                             Sekarang: di-generate dari daftarTahunFilter
                             (computed dari composable useTahunFilter), jadi
                             otomatis menyesuaikan tahun berjalan tanpa perlu
                             edit kode setiap pergantian tahun. Tampilan &
                             class TIDAK berubah sama sekali (tetap dropdown
                             seperti sebelumnya). -->
                        <div class="relative flex-none">
                            <select
                                id="filter-tahun-laporan"
                                name="tahun"
                                v-model="filterTahun"
                                aria-label="Filter tahun"
                                class="w-full min-h-[44px] text-base sm:w-24 sm:text-sm border border-gray-300 focus:border-emerald-500 focus:ring-emerald-500 rounded-lg shadow-sm text-gray-600 py-2 pl-3 pr-8 appearance-none bg-none bg-white"
                            >
                                <option
                                    v-for="tahun in daftarTahunFilter"
                                    :key="tahun"
                                    :value="tahun.toString()"
                                >
                                    {{ tahun }}
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
                <div class="hidden overflow-x-auto rounded-lg border border-gray-200 md:block">
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
                <div class="space-y-3 md:hidden">
                    <article
                        v-for="(item, index) in laporan"
                        :key="item.id || startIndex + index"
                        class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm"
                    >
                        <div class="flex items-start gap-3">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-sm font-semibold text-slate-700">
                                {{ startIndex + index + 1 }}
                            </span>
                            <div class="min-w-0">
                                <h3 class="break-words text-sm font-bold uppercase text-gray-800">{{ item.nama }}</h3>
                                <p class="mt-1 break-words text-xs text-gray-500">NIP {{ item.nip }}</p>
                            </div>
                        </div>
                        <dl class="mt-3 grid grid-cols-2 gap-2">
                            <div
                                v-for="col in columnsToShow"
                                :key="col.key"
                                class="flex min-w-0 items-center justify-between gap-2 rounded-lg bg-slate-50 px-3 py-2"
                            >
                                <dt class="break-words text-xs text-gray-600">{{ col.label }}</dt>
                                <dd class="shrink-0 rounded-md px-2 py-1 text-xs font-bold"
                                    :class="item.cuti && item.cuti[col.key] > 0 ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-500'"
                                >
                                    {{ item.cuti && item.cuti[col.key] !== undefined ? item.cuti[col.key] : 0 }}
                                </dd>
                            </div>
                            <div class="col-span-2 flex items-center justify-between rounded-lg bg-emerald-50 px-3 py-2">
                                <dt class="text-sm font-semibold text-emerald-800">Total cuti</dt>
                                <dd class="rounded-md bg-emerald-100 px-2 py-1 text-sm font-bold text-emerald-800">{{ item.total ?? 0 }}</dd>
                            </div>
                        </dl>
                    </article>
                    <div
                        v-if="laporan.length === 0"
                        class="rounded-xl border border-gray-200 bg-white p-6 text-center text-sm text-gray-500"
                    >
                        {{ emptyStateMessage }}
                        <button
                            v-if="isFilterAktif"
                            type="button"
                            @click="resetFilters"
                            class="mt-3 min-h-[40px] rounded-lg border border-gray-300 px-4 py-2 font-semibold text-gray-700"
                        >
                            Atur ulang filter
                        </button>
                    </div>
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