<script setup>
import MainLayout from "@/Layouts/MainLayout.vue";
import { Head, router } from "@inertiajs/vue3";
import {
    ref,
    watch,
    computed,
    onMounted,
    onBeforeUnmount,
    nextTick,
} from "vue";
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
    String(props.filters.tahun || new Date().getFullYear()),
);
const filterBulan = ref(props.filters.bulan ? String(props.filters.bulan) : "");
const filterKelompok = ref(props.filters.kelompok_substansi || "");




// ================================================================
// DAFTAR TAHUN FILTER — DINAMIS (composable reusable useTahunFilter)
// Opsi tahun otomatis relatif terhadap tahun berjalan, jadi tidak perlu
// edit kode setiap pergantian tahun. Composable yang sama bisa dipakai
// di halaman lain (Rekap Kuota Detail, Monitoring Cuti, dsb).
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




// Nilai kelompok "-" (pegawai tanpa kelompok) ditampilkan sebagai
// "Tanpa kelompok", sama seperti di halaman Monitoring Cuti. Nilai yang
// dikirim ke server tetap "-", jadi filter di backend tidak berubah.
const tampilKelompok = (nilai) => (nilai === "-" ? "Tanpa kelompok" : nilai);




// ================================================================
// FETCH DATA
// - `lewatiWatch` mencegah request ganda (mis. saat Reset Filter).
// - Timer debounce pencarian dibersihkan saat halaman ditinggalkan.
// ================================================================
let lewatiWatch = false;
let searchTimeout = null;




const fetchFilteredData = (page = 1) => {
    clearTimeout(searchTimeout);
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
    if (lewatiWatch) return;
    fetchFilteredData(1);
});




watch(searchQuery, () => {
    if (lewatiWatch) return;
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        fetchFilteredData(1);
    }, 500);
});




// Sinkronkan state lokal dengan props.filters (mis. saat tombol
// Back/Forward browser dipakai), tanpa memicu fetch ulang.
watch(
    () => props.filters,
    (f) => {
        lewatiWatch = true;
        searchQuery.value = f.search || "";
        filterTahun.value = String(f.tahun || new Date().getFullYear());
        filterBulan.value = f.bulan ? String(f.bulan) : "";
        filterKelompok.value = f.kelompok_substansi || "";
        nextTick(() => {
            lewatiWatch = false;
        });
    },
);




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
        return [
            {
                label: bulanList[index],
                short: bulanList[index].slice(0, 3),
                key: keyBulan[index],
            },
        ];
    }
    return bulanList.map((label, index) => ({
        label,
        short: label.slice(0, 3),
        key: keyBulan[index],
    }));
});




// Nilai cuti satu bulan untuk satu pegawai (dipakai tabel & kartu)
const nilaiBulan = (item, key) => {
    const nilai = item.cuti?.[key];
    return nilai !== undefined && nilai !== null ? nilai : 0;
};




// ================================================================
// PENANDA KOREKSI SALDO ADMIN HR
// Field `total` dari backend:
//   - Filter bulan KOSONG  -> Cuti Terpakai EFEKTIF setahun (ikut
//     koreksi_saldo Admin HR), sama dengan Rekap Kuota Detail.
//   - Filter bulan TERISI  -> jumlah cuti pada bulan itu saja.
// Saat "Semua Bulan", total bisa berbeda dari jumlah kolom bulan, jadi
// baris yang berbeda diberi tanda *. Saat filter bulan aktif, total memang
// sengaja berbeda dari jumlah 12 bulan, jadi tanda * TIDAK ditampilkan
// (kalau tidak, semua baris yang punya cuti di bulan lain salah ditandai).
// Perhitungan memakai SEMUA 12 bulan, tetap benar walau filter Bulan
// hanya menampilkan satu kolom.
// ================================================================
const totalDariBulan = (item) =>
    keyBulan.reduce(
        (jumlah, key) => jumlah + (Number(item.cuti?.[key]) || 0),
        0,
    );




// Pakai props.filters.bulan (bukan filterBulan.value) supaya penanda berubah
// bersamaan dengan data dari server, bukan lebih dulu saat dropdown diklik.
const adaKoreksi = (item) =>
    !props.filters.bulan &&
    (Number(item.total) || 0) !== totalDariBulan(item);




const adaItemDikoreksi = computed(() => props.laporan.some(adaKoreksi));




const keteranganKoreksi = (item) =>
    `Angka ini telah disesuaikan secara manual oleh Admin HR (Riwayat asli: ${totalDariBulan(item)} hari).`;




const isFilterAktif = computed(() => {
    return (
        Boolean(searchQuery.value) ||
        Boolean(filterBulan.value) ||
        Boolean(filterKelompok.value)
    );
});




// Chip filter aktif — hanya filter yang bukan nilai default
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
            label: tampilKelompok(filterKelompok.value),
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




// Satu kali fetch saja: watcher dilewati selama reset.
const resetFilters = () => {
    lewatiWatch = true;
    clearTimeout(searchTimeout);
    searchQuery.value = "";
    filterBulan.value = "";
    filterKelompok.value = "";
    fetchFilteredData(1);
    nextTick(() => {
        lewatiWatch = false;
    });
};




// ================================================================
// DROPDOWN FILTER KUSTOM (Kelompok Substansi, Bulan, Tahun)
// Di semua ukuran layar (termasuk HP) daftar pilihan muncul TEPAT di
// bawah kotak dropdown-nya, tinggi dibatasi (bisa di-scroll), teks
// panjang membungkus. Lebar daftar selalu sama dengan lebar kotaknya.
// Hanya satu dropdown terbuka sekaligus; menutup saat klik di luar atau
// menekan Esc (listener di document).
// ================================================================
const toolbarRef = ref(null);
const dropdownTerbuka = ref(null); // 'kelompok' | 'bulan' | 'tahun' | null




const toggleDropdown = (nama) => {
    dropdownTerbuka.value = dropdownTerbuka.value === nama ? null : nama;
};




const pilihKelompok = (nilai) => {
    filterKelompok.value = nilai;
    dropdownTerbuka.value = null;
};
const pilihBulan = (nilai) => {
    filterBulan.value = nilai === "" ? "" : String(nilai);
    dropdownTerbuka.value = null;
};
const pilihTahun = (nilai) => {
    filterTahun.value = String(nilai);
    dropdownTerbuka.value = null;
};




const labelKelompok = computed(() =>
    filterKelompok.value
        ? tampilKelompok(filterKelompok.value)
        : "Semua Kelompok Substansi",
);
const labelBulan = computed(() =>
    filterBulan.value
        ? bulanList[parseInt(filterBulan.value, 10) - 1]
        : "Semua Bulan",
);




const tutupDropdownSaatKlikLuar = (event) => {
    if (toolbarRef.value && !toolbarRef.value.contains(event.target)) {
        dropdownTerbuka.value = null;
    }
};
const tutupDropdownSaatEsc = (event) => {
    if (event.key === "Escape") dropdownTerbuka.value = null;
};




onMounted(() => {
    document.addEventListener("click", tutupDropdownSaatKlikLuar);
    document.addEventListener("keydown", tutupDropdownSaatEsc);
});
onBeforeUnmount(() => {
    document.removeEventListener("click", tutupDropdownSaatKlikLuar);
    document.removeEventListener("keydown", tutupDropdownSaatEsc);
    clearTimeout(searchTimeout);
});
</script>




<template>
    <Head title="Rekap Laporan Cuti" />




    <MainLayout>
        <!-- Pembungkus luar 'p-3 sm:p-6' sudah DIHAPUS supaya tidak ada
             jarak ganda di sekeliling kartu. Kartu putih menjadi satu-satunya
             elemen anak langsung MainLayout. Padding dalam kartu dikurangi
             dari 'sm:p-6' menjadi 'sm:p-4' supaya tabel terasa lebih lebar. -->
        <div
            class="bg-white shadow-sm sm:rounded-xl rounded-lg border border-gray-200 p-3 sm:p-4"
        >
            <!-- ===================== JUDUL + EXPORT (baris atas) ===================== -->
            <!-- Judul & subjudul di kiri, tombol Export di kanan atas
                 (di HP tombol turun ke bawah judul selebar layar). -->
            <div
                class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-4 sm:mb-5 gap-3 sm:gap-4"
            >
                <div>
                    <h3
                        class="text-base sm:text-lg font-bold text-gray-800 leading-snug"
                    >
                        Rekapitulasi Cuti Tahunan Pegawai
                    </h3>
                    <p class="text-xs sm:text-sm text-gray-500 mt-0.5">
                        Rekap pemakaian cuti tiap pegawai per bulan dalam satu
                        tahun.
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
                    class="w-full sm:w-auto inline-flex items-center justify-center gap-1.5 px-5 min-h-[44px] sm:min-h-0 sm:py-2.5 bg-green-700 hover:bg-green-800 active:bg-green-900 text-white rounded-full text-sm font-semibold transition-colors shadow-sm whitespace-nowrap shrink-0"
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




            <!-- ===================== TOOLBAR: SEARCH + FILTER =====================
                 Layout responsif:
                   - HP (< 640px)     : Search 1 baris, Kelompok 1 baris,
                                        Bulan & Tahun berdampingan
                   - Tablet (640-1023): Search 1 baris, lalu Kelompok | Bulan | Tahun
                                        dalam satu baris
                   - Desktop (>= 1024): Search + 3 filter dalam SATU baris.
                                        Search melebar mengisi sisa ruang; lebar
                                        filter menyesuaikan isinya (Kelompok
                                        mengikuti panjang teks pilihan, Bulan
                                        & Tahun ringkas). Kalau ruang tidak
                                        cukup (mis. sidebar terbuka di layar
                                        sempit) filter turun rapi ke baris
                                        kedua. -->
            <div
                ref="toolbarRef"
                class="flex flex-col gap-2 mb-3 lg:flex-row lg:flex-wrap lg:items-center"
            >
                <!-- Search -->
                <div
                    class="relative w-full lg:w-auto lg:flex-1 lg:min-w-[14rem]"
                >
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
                        type="search"
                        inputmode="search"
                        autocomplete="off"
                        v-model="searchQuery"
                        placeholder="Cari nama / NIP..."
                        aria-label="Cari nama atau NIP pegawai"
                        class="w-full min-h-[44px] pl-9 pr-3 py-2 text-base sm:text-sm border border-gray-300 focus:border-emerald-500 focus:ring-emerald-500 rounded-lg shadow-sm bg-white"
                    />
                </div>




                <!-- Filter group: dropdown KUSTOM (bukan <select> bawaan
                     browser) supaya lebar & arah buka bisa dikontrol. -->
                <div
                    class="grid grid-cols-2 gap-2 sm:grid-cols-[minmax(0,1fr)_9rem_6.5rem] lg:flex lg:flex-none lg:items-center"
                >
                    <!-- Filter Kelompok Substansi: lebar mengikuti isi
                         (minimal 17rem supaya tidak melompat-lompat, maksimal
                         24rem), teks yang lebih panjang dipotong dengan "...". -->
                    <div
                        class="relative col-span-2 min-w-0 sm:col-span-1 lg:col-auto lg:shrink-0 lg:min-w-[17rem] lg:max-w-[24rem]"
                    >
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




                        <button
                            id="filter-kelompok-laporan"
                            type="button"
                            aria-label="Filter kelompok substansi"
                            aria-haspopup="listbox"
                            :aria-expanded="dropdownTerbuka === 'kelompok'"
                            @click="toggleDropdown('kelompok')"
                            class="w-full min-h-[44px] text-left text-base sm:text-sm border border-gray-300 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 focus:outline-none rounded-lg shadow-sm text-gray-600 py-2 pl-9 pr-8 bg-white"
                        >
                            <span class="block truncate">{{
                                labelKelompok
                            }}</span>
                        </button>




                        <span
                            class="absolute inset-y-0 right-0 flex items-center pr-2 pointer-events-none"
                        >
                            <svg
                                class="w-3.5 h-3.5 text-gray-500 transition-transform"
                                :class="{
                                    'rotate-180':
                                        dropdownTerbuka === 'kelompok',
                                }"
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




                        <!-- Daftar pilihan: tepat di bawah kotak dan selebar kotaknya
                             (di HP maupun desktop); nama panjang membungkus. -->
                        <ul
                            v-if="dropdownTerbuka === 'kelompok'"
                            role="listbox"
                            class="absolute left-0 right-0 top-full z-30 mt-1 max-h-60 overflow-y-auto rounded-lg border border-gray-200 bg-white py-1 shadow-lg"
                        >
                            <li>
                                <button
                                    type="button"
                                    role="option"
                                    :aria-selected="!filterKelompok"
                                    @click="pilihKelompok('')"
                                    :class="
                                        !filterKelompok
                                            ? 'bg-emerald-50 text-emerald-700 font-semibold'
                                            : 'text-gray-700 hover:bg-gray-50'
                                    "
                                    class="block w-full break-words px-3 py-2 text-left text-sm"
                                >
                                    Semua Kelompok Substansi
                                </button>
                            </li>
                            <li
                                v-for="kelompok in daftarKelompokSubstansi"
                                :key="kelompok"
                            >
                                <button
                                    type="button"
                                    role="option"
                                    :aria-selected="filterKelompok === kelompok"
                                    @click="pilihKelompok(kelompok)"
                                    :class="
                                        filterKelompok === kelompok
                                            ? 'bg-emerald-50 text-emerald-700 font-semibold'
                                            : 'text-gray-700 hover:bg-gray-50'
                                    "
                                    class="block w-full break-words px-3 py-2 text-left text-sm"
                                >
                                    {{ tampilKelompok(kelompok) }}
                                </button>
                            </li>
                        </ul>
                    </div>




                    <!-- Filter Bulan -->
                    <div class="relative min-w-0 lg:w-36 lg:shrink-0">
                        <button
                            id="filter-bulan-laporan"
                            type="button"
                            aria-label="Filter bulan"
                            aria-haspopup="listbox"
                            :aria-expanded="dropdownTerbuka === 'bulan'"
                            @click="toggleDropdown('bulan')"
                            class="w-full min-h-[44px] text-left text-base sm:text-sm border border-gray-300 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 focus:outline-none rounded-lg shadow-sm text-gray-600 py-2 pl-3 pr-8 bg-white"
                        >
                            <span class="block truncate">{{
                                labelBulan
                            }}</span>
                        </button>
                        <span
                            class="absolute inset-y-0 right-0 flex items-center pr-2 pointer-events-none"
                        >
                            <svg
                                class="w-3.5 h-3.5 text-gray-500 transition-transform"
                                :class="{
                                    'rotate-180': dropdownTerbuka === 'bulan',
                                }"
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




                        <ul
                            v-if="dropdownTerbuka === 'bulan'"
                            role="listbox"
                            class="absolute left-0 right-0 top-full z-30 mt-1 max-h-60 overflow-y-auto rounded-lg border border-gray-200 bg-white py-1 shadow-lg"
                        >
                            <li>
                                <button
                                    type="button"
                                    role="option"
                                    :aria-selected="!filterBulan"
                                    @click="pilihBulan('')"
                                    :class="
                                        !filterBulan
                                            ? 'bg-emerald-50 text-emerald-700 font-semibold'
                                            : 'text-gray-700 hover:bg-gray-50'
                                    "
                                    class="block w-full px-3 py-2 text-left text-sm"
                                >
                                    Semua Bulan
                                </button>
                            </li>
                            <li
                                v-for="(bulan, index) in bulanList"
                                :key="index"
                            >
                                <button
                                    type="button"
                                    role="option"
                                    :aria-selected="
                                        String(filterBulan) ===
                                        String(index + 1)
                                    "
                                    @click="pilihBulan(index + 1)"
                                    :class="
                                        String(filterBulan) ===
                                        String(index + 1)
                                            ? 'bg-emerald-50 text-emerald-700 font-semibold'
                                            : 'text-gray-700 hover:bg-gray-50'
                                    "
                                    class="block w-full px-3 py-2 text-left text-sm"
                                >
                                    {{ bulan }}
                                </button>
                            </li>
                        </ul>
                    </div>




                    <!-- ================================================
                         FILTER TAHUN — DINAMIS
                         Opsi di-generate dari daftarTahunFilter
                         (composable useTahunFilter). -->
                    <div class="relative min-w-0 lg:w-24 lg:shrink-0">
                        <button
                            id="filter-tahun-laporan"
                            type="button"
                            aria-label="Filter tahun"
                            aria-haspopup="listbox"
                            :aria-expanded="dropdownTerbuka === 'tahun'"
                            @click="toggleDropdown('tahun')"
                            class="w-full min-h-[44px] text-left text-base sm:text-sm border border-gray-300 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 focus:outline-none rounded-lg shadow-sm text-gray-600 py-2 pl-3 pr-8 bg-white"
                        >
                            <span class="block truncate">{{
                                filterTahun
                            }}</span>
                        </button>
                        <span
                            class="absolute inset-y-0 right-0 flex items-center pr-2 pointer-events-none"
                        >
                            <svg
                                class="w-3.5 h-3.5 text-gray-500 transition-transform"
                                :class="{
                                    'rotate-180': dropdownTerbuka === 'tahun',
                                }"
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




                        <ul
                            v-if="dropdownTerbuka === 'tahun'"
                            role="listbox"
                            class="absolute left-0 right-0 top-full z-30 mt-1 max-h-60 overflow-y-auto rounded-lg border border-gray-200 bg-white py-1 shadow-lg"
                        >
                            <li v-for="tahun in daftarTahunFilter" :key="tahun">
                                <button
                                    type="button"
                                    role="option"
                                    :aria-selected="
                                        String(filterTahun) === String(tahun)
                                    "
                                    @click="pilihTahun(tahun)"
                                    :class="
                                        String(filterTahun) === String(tahun)
                                            ? 'bg-emerald-50 text-emerald-700 font-semibold'
                                            : 'text-gray-700 hover:bg-gray-50'
                                    "
                                    class="block w-full px-3 py-2 text-left text-sm"
                                >
                                    {{ tahun }}
                                </button>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>




            <!-- ===================== CHIP FILTER AKTIF ===================== -->
            <!-- Hanya dirender kalau ada minimal 1 filter aktif -->
            <div
                v-if="activeFilterChips.length > 0"
                class="flex flex-wrap items-center gap-2 mb-4 text-xs text-gray-500"
            >
                <span>Filter aktif:</span>
                <span
                    v-for="chip in activeFilterChips"
                    :key="chip.key"
                    class="inline-flex items-center gap-1.5 max-w-full bg-emerald-50 text-emerald-700 font-semibold pl-3 pr-1 py-1 rounded-full"
                >
                    <span class="truncate">{{ chip.label }}</span>
                    <button
                        type="button"
                        @click="chip.clear()"
                        class="shrink-0 w-6 h-6 inline-flex items-center justify-center rounded-full text-emerald-600/70 hover:text-emerald-700 hover:bg-emerald-100"
                        aria-label="Hapus filter"
                    >
                        ✕
                    </button>
                </span>
                <button
                    type="button"
                    @click="resetFilters"
                    class="py-1 text-gray-400 underline hover:text-gray-600"
                >
                    Hapus semua
                </button>
            </div>




            <!-- ===================== KARTU PER PEGAWAI (HP & TABLET, < 1024px) =====================
                 Di bawah breakpoint lg tabel 14 kolom diganti kartu: nama,
                 NIP, dan Total Cuti di bagian atas, lalu grid 12 bulan
                 (4 x 3) yang terbaca tanpa geser ke samping. Di tablet kartu
                 tersusun 2 kolom. Bulan berisi cuti berwarna biru. Saat
                 filter Bulan aktif, grid hanya menampilkan bulan itu.
                 Keterangan koreksi Admin HR ditulis langsung di kartu
                 (tooltip tidak bisa dipakai di layar sentuh). -->
            <div class="lg:hidden">
                <ul
                    v-if="laporan.length > 0"
                    class="grid gap-3 sm:grid-cols-2"
                >
                    <li
                        v-for="(item, index) in laporan"
                        :key="item.id || startIndex + index"
                        class="flex flex-col rounded-xl border border-slate-200 bg-white overflow-hidden active:bg-violet-50/40 transition-colors"
                    >
                        <div class="flex items-start gap-3 px-3 pt-3 pb-2.5">
                            <span
                                class="shrink-0 w-7 h-7 rounded-full bg-slate-100 text-slate-600 text-xs font-bold inline-flex items-center justify-center"
                            >
                                {{ startIndex + index + 1 }}
                            </span>
                            <div class="min-w-0 flex-1">
                                <div
                                    class="text-sm font-bold text-slate-800 uppercase leading-snug break-words"
                                >
                                    {{ item.nama }}
                                </div>
                                <div
                                    class="text-[11px] text-slate-500 mt-0.5 tabular-nums"
                                >
                                    NIP {{ item.nip }}
                                </div>
                            </div>
                            <div class="shrink-0 text-right">
                                <div
                                    class="text-[10px] font-bold text-slate-500 leading-none mb-1"
                                >
                                    Total cuti
                                </div>
                                <span
                                    class="inline-block min-w-[40px] px-2.5 py-1 bg-emerald-100 text-emerald-800 rounded-md font-bold text-sm border border-emerald-300/80 text-center"
                                >
                                    {{ item.total ?? 0
                                    }}<sup
                                        v-if="adaKoreksi(item)"
                                        class="ml-0.5 text-amber-600 font-black"
                                        >*</sup
                                    >
                                </span>
                            </div>
                        </div>




                        <div
                            class="grid gap-1.5 px-3 pb-3"
                            :class="
                                columnsToShow.length === 1
                                    ? 'grid-cols-1'
                                    : 'grid-cols-4'
                            "
                        >
                            <div
                                v-for="col in columnsToShow"
                                :key="col.key"
                                :class="
                                    nilaiBulan(item, col.key) > 0
                                        ? 'bg-blue-100 border-blue-300/80 text-blue-800'
                                        : 'bg-slate-50 border-slate-200 text-slate-400'
                                "
                                class="flex flex-col items-center justify-center rounded-lg border py-1.5"
                            >
                                <span
                                    class="text-[10px] font-semibold leading-none"
                                    >{{
                                        columnsToShow.length === 1
                                            ? col.label
                                            : col.short
                                    }}</span
                                >
                                <span
                                    class="mt-1 text-sm leading-none tabular-nums"
                                    :class="
                                        nilaiBulan(item, col.key) > 0
                                            ? 'font-bold'
                                            : 'font-medium'
                                    "
                                    >{{ nilaiBulan(item, col.key) }}</span
                                >
                            </div>
                        </div>




                        <p
                            v-if="adaKoreksi(item)"
                            class="mt-auto border-t border-amber-200 bg-amber-50 px-3 py-2 text-[11px] leading-snug text-amber-800"
                        >
                            <span class="font-black">*</span>
                            {{ keteranganKoreksi(item) }}
                        </p>
                    </li>
                </ul>




                <!-- Empty state kartu -->
                <div
                    v-else
                    class="flex flex-col items-center justify-center text-center gap-3 rounded-xl border border-slate-200 bg-white px-5 py-12"
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
                    <p class="text-sm font-semibold text-gray-600 max-w-xs">
                        {{ emptyStateMessage }}
                    </p>
                    <button
                        v-if="isFilterAktif"
                        type="button"
                        @click="resetFilters"
                        class="mt-1 inline-flex items-center justify-center gap-1.5 px-5 min-h-[40px] bg-white border border-gray-300 rounded-full text-xs font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-800 transition-colors"
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
            </div>




            <!-- ===================== TABEL (DESKTOP, >= 1024px) =====================
                 Dirancang KOMPAK supaya seluruh kolom (Januari-Desember +
                 Total) terlihat sekaligus tanpa geser horizontal pada
                 browser zoom 100% dengan sidebar terbuka:
                   - header bulan disingkat (Jan, Feb, ...) dan baru ditulis
                     lengkap di layar 2xl (>= 1536px)
                   - sel & kapsul angka rapat (px-1, kapsul 28px)
                   - kolom No ramping (2.5rem), kolom Nama min 170px dan
                     boleh membungkus
                 'overflow-x-auto' tetap dipasang sebagai pengaman kalau
                 layar memang terlalu sempit. Kolom No & Nama menempel
                 (sticky) di kiri saat digeser. -->
            <div
                class="hidden lg:block overflow-x-auto rounded-lg border border-slate-200 bg-white"
            >
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr
                            class="bg-slate-50 border-b border-slate-200 text-[10px] leading-tight text-slate-500 uppercase tracking-wide font-bold align-middle"
                        >
                            <th
                                class="sticky left-0 z-20 bg-slate-50 w-10 min-w-[2.5rem] px-1 py-3 text-center border-r border-slate-200"
                            >
                                No
                            </th>
                            <th
                                class="sticky left-10 z-20 bg-slate-50 min-w-[170px] 2xl:min-w-[220px] px-3 py-3 border-r-2 border-slate-300 shadow-[2px_0_4px_-2px_rgba(0,0,0,0.08)]"
                            >
                                Nama Pegawai
                            </th>
                            <th
                                v-for="col in columnsToShow"
                                :key="col.key"
                                class="px-1 2xl:px-2 py-3 text-center"
                            >
                                <template v-if="columnsToShow.length === 1">
                                    {{ col.label }}
                                </template>
                                <template v-else>
                                    <span class="2xl:hidden">{{
                                        col.short
                                    }}</span>
                                    <span class="hidden 2xl:inline">{{
                                        col.label
                                    }}</span>
                                </template>
                            </th>
                            <th
                                class="px-2 py-3 text-center text-slate-800 bg-slate-100/70"
                            >
                                Total
                            </th>
                        </tr>
                    </thead>
                    <tbody
                        v-if="laporan.length > 0"
                        class="text-sm align-middle"
                    >
                        <tr
                            v-for="(item, index) in laporan"
                            :key="item.id || startIndex + index"
                            class="group border-b border-slate-100 hover:bg-violet-50 hover:shadow-[inset_4px_0_0_0_theme(colors.violet.400)] transition-all duration-150"
                        >
                            <td
                                class="sticky left-0 z-10 bg-white group-hover:bg-violet-50 w-10 min-w-[2.5rem] px-1 py-3 text-center text-sm font-semibold text-slate-800 border-r border-slate-200 transition-colors duration-150"
                            >
                                {{ startIndex + index + 1 }}
                            </td>
                            <td
                                class="sticky left-10 z-10 bg-white group-hover:bg-violet-50 min-w-[170px] 2xl:min-w-[220px] px-3 py-3 border-r-2 border-slate-300 shadow-[2px_0_4px_-2px_rgba(0,0,0,0.08)] transition-colors duration-150"
                            >
                                <div
                                    class="text-xs 2xl:text-sm font-bold text-slate-800 uppercase leading-snug break-words"
                                >
                                    {{ item.nama }}
                                </div>
                                <div
                                    class="text-[11px] text-slate-500 mt-0.5 tabular-nums"
                                >
                                    {{ item.nip }}
                                </div>
                            </td>
                            <td
                                v-for="col in columnsToShow"
                                :key="col.key"
                                class="px-1 2xl:px-2 py-3 text-center"
                            >
                                <span
                                    :class="
                                        item.cuti && item.cuti[col.key] > 0
                                            ? 'bg-blue-100 text-blue-800 font-bold border-blue-300/80'
                                            : 'bg-slate-100 text-slate-500 font-medium border-slate-200'
                                    "
                                    class="inline-block min-w-[28px] 2xl:min-w-[32px] px-1 2xl:px-2 py-1 rounded-md text-xs border"
                                >
                                    {{ nilaiBulan(item, col.key) }}
                                </span>
                            </td>
                            <td class="px-2 py-3 text-center bg-slate-50/70">
                                <!-- Tanda * muncul kalau total (efektif) berbeda
                                     dari jumlah riwayat bulanan (koreksi Admin HR).
                                     Tidak muncul saat filter Bulan aktif. -->
                                <span
                                    class="inline-block min-w-[36px] px-2 py-1 bg-emerald-100 text-emerald-800 rounded-md font-bold text-xs border border-emerald-300/80"
                                    :title="
                                        adaKoreksi(item)
                                            ? keteranganKoreksi(item)
                                            : undefined
                                    "
                                >
                                    {{ item.total ?? 0
                                    }}<sup
                                        v-if="adaKoreksi(item)"
                                        class="ml-0.5 text-amber-600 font-black"
                                        >*</sup
                                    >
                                </span>
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




            <!-- Catatan kaki: hanya tampil kalau ada minimal 1 baris yang dikoreksi -->
            <p v-if="adaItemDikoreksi" class="mt-3 text-xs text-amber-700">
                * Catatan: Angka Total Cuti bisa berbeda dari hasil
                penjumlahan per bulan jika ada penyesuaian manual oleh Admin
                HR.
            </p>




            <!-- ===================== PAGINASI ===================== -->
            <div
                v-if="laporan.length > 0"
                class="flex flex-col sm:flex-row items-center justify-between gap-3 mt-4"
            >
                <p class="text-xs text-gray-500 text-center sm:text-left">
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




                <!-- Mobile: tombol besar + penanda halaman ringkas -->
                <div
                    v-if="pagination.last_page > 1"
                    class="flex w-full items-center justify-between gap-2 sm:hidden"
                >
                    <button
                        type="button"
                        :disabled="pagination.current_page === 1"
                        @click="goToPage(pagination.current_page - 1)"
                        class="flex-1 min-h-[44px] px-3 text-xs font-semibold rounded-full border border-gray-300 text-gray-600 hover:bg-gray-50 active:bg-gray-100 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                    >
                        Sebelumnya
                    </button>
                    <span
                        class="shrink-0 px-1 text-xs font-semibold text-gray-600 tabular-nums"
                    >
                        {{ pagination.current_page }} /
                        {{ pagination.last_page }}
                    </span>
                    <button
                        type="button"
                        :disabled="
                            pagination.current_page === pagination.last_page
                        "
                        @click="goToPage(pagination.current_page + 1)"
                        class="flex-1 min-h-[44px] px-3 text-xs font-semibold rounded-full border border-gray-300 text-gray-600 hover:bg-gray-50 active:bg-gray-100 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                    >
                        Berikutnya
                    </button>
                </div>




                <!-- Tablet & desktop: paginasi bernomor -->
                <div
                    v-if="pagination.last_page > 1"
                    class="hidden sm:flex items-center gap-1"
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
    </MainLayout>
</template>

