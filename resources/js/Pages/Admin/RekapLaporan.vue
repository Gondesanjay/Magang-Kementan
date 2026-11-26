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
    // Tahun laporan (dari server) & izin edit (hanya Admin HR).
    tahun: {
        type: Number,
        default: () => new Date().getFullYear(),
    },
    bisaEdit: {
        type: Boolean,
        default: false,
    },
});

const searchQuery = ref(props.filters.search || "");
const filterTahun = ref(
    String(props.filters.tahun || new Date().getFullYear()),
);
const filterBulan = ref(props.filters.bulan ? String(props.filters.bulan) : "");
const filterKelompok = ref(props.filters.kelompok_substansi || "");

// Opsi tahun dinamis (relatif terhadap tahun berjalan).
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

// Kelompok "-" tampil sebagai "Tanpa kelompok"; nilai ke server tetap "-".
const tampilKelompok = (nilai) => (nilai === "-" ? "Tanpa kelompok" : nilai);

// ================================================================
// FETCH DATA
// `lewatiWatch` mencegah request ganda (mis. saat Reset Filter).
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

// Sinkronkan state lokal dengan props.filters (mis. tombol Back/Forward),
// tanpa memicu fetch ulang.
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

// Filter Bulan aktif: Total hanya cuti pada bulan terpilih.
const modeBulan = computed(() => Boolean(filterBulan.value));

// Nilai cuti satu bulan untuk satu pegawai.
const nilaiBulan = (item, key) => {
    const nilai = item.cuti?.[key];
    return nilai !== undefined && nilai !== null ? nilai : 0;
};

// Sel bulan yang dikoreksi Admin HR (kuning + tanda *).
const bulanDiedit = (item, key) => Boolean(item.cuti_diedit?.[key]);

const kelasBulanTabel = (item, key) => {
    if (bulanDiedit(item, key)) {
        return "bg-amber-100 text-amber-900 font-bold border-amber-300";
    }
    return nilaiBulan(item, key) > 0
        ? "bg-blue-100 text-blue-800 font-bold border-blue-300/80"
        : "bg-slate-100 text-slate-500 font-medium border-slate-200";
};

const kelasBulanKartu = (item, key) => {
    if (bulanDiedit(item, key)) {
        return "bg-amber-100 border-amber-300 text-amber-900";
    }
    return nilaiBulan(item, key) > 0
        ? "bg-blue-100 border-blue-300/80 text-blue-800"
        : "bg-slate-50 border-slate-200 text-slate-400";
};

const titleBulan = (item, key) =>
    bulanDiedit(item, key)
        ? `Dikoreksi Admin HR, semula ${Number(item.cuti_sistem?.[key]) || 0} hari`
        : undefined;

// ================================================================
// TOTAL, TANDA * & RINCIAN
// Angka dihitung di backend (AdminController::buildLaporanData()).
// Total = Terpakai (cuti disetujui setelah koreksi Admin HR); cuti
// ditangguhkan dikembalikan ke saldo, jadi tidak dihitung.
// ================================================================
const adaEdit = (item) => Boolean(item.ada_edit);

const adaItemDiedit = computed(() => props.laporan.some(adaEdit));

const totalTampil = (item) => Number(item.total) || 0;

// Nama bulan yang difilter (null bila "Semua Bulan").
const namaBulanAktif = computed(() =>
    props.filters.bulan
        ? bulanList[parseInt(props.filters.bulan, 10) - 1]
        : null,
);

// Catatan koreksi; kosong bila angka sesuai sistem.
const teksStatus = (diedit, sistem) =>
    diedit ? `koreksi, semula ${Number(sistem) || 0} hari` : "";

// Jejak koreksi terakhir: siapa, kapan, alasan.
const teksInfoEdit = (info) => {
    if (!info) return undefined;
    let teks = `Koreksi terakhir oleh ${info.oleh || "Admin HR"}`;
    if (info.waktu) teks += `, ${info.waktu}`;
    if (info.alasan) teks += `. Alasan: ${info.alasan}`;
    return teks;
};

// Bulan yang dikoreksi: [{ key, bulan, sistem, sekarang }].
// Filter bulan aktif -> hanya bulan itu.
const daftarKoreksiBulan = (item) =>
    keyBulan
        .map((key, i) => ({
            key,
            bulan: bulanList[i],
            sistem: Number(item.cuti_sistem?.[key]) || 0,
            sekarang: nilaiBulan(item, key),
        }))
        .filter(
            (b) =>
                bulanDiedit(item, b.key) &&
                (!namaBulanAktif.value || b.bulan === namaBulanAktif.value),
        );

// Baris rincian: hanya Terpakai.
const barisRincian = (item) => [
    {
        key: "terpakai",
        label: "Terpakai",
        nilai: Number(item.terpakai) || 0,
        diedit: Boolean(item.terpakai_diedit),
        sistem: Number(item.terpakai_sistem) || 0,
        status: teksStatus(item.terpakai_diedit, item.terpakai_sistem),
        info: teksInfoEdit(item.info_terpakai_edit),
        bulanKoreksi: daftarKoreksiBulan(item),
    },
];

// Kalimat penjelas asal angka.
const keteranganBaris = (baris) => {
    if (!baris.diedit) {
        return "Dihitung otomatis dari cuti yang disetujui.";
    }
    // Koreksi per bulan (cara edit sekarang).
    if (baris.bulanKoreksi.length > 0) {
        const selisih = baris.nilai - baris.sistem;
        if (selisih === 0) {
            return `Total tetap ${baris.nilai} hari, tetapi ada bulan yang dikoreksi Admin HR (lihat rincian di bawah).`;
        }
        const tanda = selisih > 0 ? "+" : "";
        return `Sistem mencatat ${baris.sistem} hari. Setelah koreksi per bulan oleh Admin HR menjadi ${baris.nilai} hari (${tanda}${selisih}).`;
    }
    // Koreksi tahunan lama (tanpa rincian bulan).
    return `Hasil sistem ${baris.sistem} hari, dikoreksi Admin HR jadi ${baris.nilai} hari.`;
};

const judulRincian = (item) =>
    `Rincian Terpakai: ${totalTampil(item)} hari${
        namaBulanAktif.value ? ` (${namaBulanAktif.value})` : ""
    }`;

// ================================================================
// MODAL RINCIAN
// Tombol (i) di kolom Aksi membuka modal (tengah di desktop, bottom
// sheet di HP) lewat Teleport. Tutup: area gelap, X, tombol, atau Esc.
// ================================================================
const popover = ref(null);
const tombolTutupRef = ref(null);
let pemicuFokus = null; // tombol (i) pembuka modal, untuk mengembalikan fokus

const kunciItem = (item, index) => item.id ?? `idx-${index}`;

const tutupPopover = () => {
    if (!popover.value) return;
    popover.value = null;
    const pemicu = pemicuFokus;
    pemicuFokus = null;
    nextTick(() => pemicu?.focus?.());
};

const togglePopover = (event, item, index) => {
    const key = kunciItem(item, index);
    if (popover.value?.key === key) {
        tutupPopover();
        return;
    }
    pemicuFokus = event.currentTarget;
    popover.value = { key, item };
};

// Data baru dari server (filter/halaman berganti) -> tutup modal lama.
watch(
    () => props.laporan,
    () => tutupPopover(),
);

// ================================================================
// EDIT LANGSUNG DI SEL BULAN (hanya Admin HR)
// Klik angka -> input. Enter / ✓ = simpan, Esc / ✕ = batal. Tidak ada
// simpan otomatis saat blur. Kosong atau sama dengan angka sistem =
// koreksi dihapus. Batas hak dijaga backend; galat tampil di banner.
// ================================================================
const selAktif = ref(null); // { id, key }
const nilaiEdit = ref("");
const sedangSimpan = ref(false);
const galatSel = ref("");

const sedangEditSel = (item, key) =>
    selAktif.value?.id === item.id && selAktif.value?.key === key;

// Baris ini punya sel yang sedang diedit? (✓ / ✕ di kolom Aksi)
const sedangEditBaris = (item) => selAktif.value?.id === item.id;

// Tabel & kartu sama-sama ada di DOM; fokus ke input yang terlihat.
const fokusInputSel = () => {
    const daftar = Array.from(document.querySelectorAll("[data-sel-edit]"));
    const el = daftar.find((n) => n.offsetParent !== null) || daftar[0];
    el?.focus();
    el?.select?.();
};

const mulaiEditSel = (item, key) => {
    if (!props.bisaEdit || sedangSimpan.value) return;
    tutupPopover();
    selAktif.value = { id: item.id, key };
    nilaiEdit.value = String(nilaiBulan(item, key));
    galatSel.value = "";
    nextTick(fokusInputSel);
};

const batalEditSel = () => {
    if (sedangSimpan.value) return;
    selAktif.value = null;
};

const simpanSel = (item) => {
    if (!selAktif.value || sedangSimpan.value) return;
    const key = selAktif.value.key;
    const teks = String(nilaiEdit.value ?? "").trim();
    const n = teks === "" ? null : Number(teks);

    if (n !== null && !(Number.isInteger(n) && n >= 0 && n <= 31)) {
        galatSel.value = "Isi angka bulat 0 sampai 31.";
        selAktif.value = null;
        return;
    }

    // Kosong = kembali ke hitungan sistem.
    const baru = n === null ? Number(item.cuti_sistem?.[key]) || 0 : n;
    if (baru === nilaiBulan(item, key)) {
        selAktif.value = null;
        return;
    }

    router.post(
        route("admin.rekap.koreksi", item.id),
        { tahun: props.tahun || Number(filterTahun.value), cuti: { [key]: n } },
        {
            preserveScroll: true,
            preserveState: true,
            onStart: () => {
                sedangSimpan.value = true;
                galatSel.value = "";
            },
            onSuccess: () => {
                selAktif.value = null;
            },
            onError: (errors) => {
                galatSel.value =
                    Object.values(errors || {})
                        .flat()
                        .join(" ") || "Gagal menyimpan koreksi.";
                selAktif.value = null;
            },
            onFinish: () => {
                sedangSimpan.value = false;
            },
        },
    );
};

// Esc menutup modal rincian (Esc di dalam input hanya membatalkan edit).
const tutupPopoverSaatEsc = (event) => {
    if (event.key === "Escape" && !selAktif.value) tutupPopover();
};

// Kunci scroll halaman selama modal terbuka & fokuskan tombol X.
watch(popover, (rincian) => {
    document.body.style.overflow = rincian ? "hidden" : "";
    if (rincian) nextTick(() => tombolTutupRef.value?.focus());
});

const isFilterAktif = computed(
    () =>
        Boolean(searchQuery.value) ||
        Boolean(filterBulan.value) ||
        Boolean(filterKelompok.value),
);

// Chip filter aktif.
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
// DROPDOWN FILTER KUSTOM (Kelompok, Bulan, Tahun)
// Satu terbuka sekaligus; tutup saat klik di luar atau Esc.
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
    document.addEventListener("keydown", tutupPopoverSaatEsc);
});
onBeforeUnmount(() => {
    document.removeEventListener("click", tutupDropdownSaatKlikLuar);
    document.removeEventListener("keydown", tutupDropdownSaatEsc);
    document.removeEventListener("keydown", tutupPopoverSaatEsc);
    // Jangan biarkan scroll terkunci bila halaman ditinggalkan saat modal terbuka.
    document.body.style.overflow = "";
    clearTimeout(searchTimeout);
});
</script>

<template>
    <Head title="Rekap Laporan Cuti" />

    <MainLayout>
        <!-- Kartu putih harus satu-satunya elemen anak MainLayout. -->
        <div
            class="bg-white shadow-sm sm:rounded-xl rounded-lg border border-gray-200 p-3 sm:p-4"
        >
            <!-- ===================== JUDUL + EXPORT ===================== -->
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
                        <span v-if="bisaEdit">
                            Admin HR dapat mengoreksi angka per bulan dengan
                            klik angkanya, lalu tekan ✓ (Simpan) untuk
                            menyimpan.
                        </span>
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

            <!-- Banner galat simpan koreksi -->
            <p
                v-if="galatSel"
                role="alert"
                class="mb-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs font-semibold text-red-700"
            >
                {{ galatSel }}
            </p>

            <!-- ===================== TOOLBAR: SEARCH + FILTER ===================== -->
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

                <!-- Filter group: dropdown kustom -->
                <div
                    class="grid grid-cols-2 gap-2 sm:grid-cols-[minmax(0,1fr)_9rem_6.5rem] lg:flex lg:flex-none lg:items-center"
                >
                    <!-- Kelompok Substansi -->
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

                    <!-- Bulan -->
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
                            <span class="block truncate">{{ labelBulan }}</span>
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

                    <!-- Tahun -->
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
                            <li
                                v-for="tahunOpsi in daftarTahunFilter"
                                :key="tahunOpsi"
                            >
                                <button
                                    type="button"
                                    role="option"
                                    :aria-selected="
                                        String(filterTahun) ===
                                        String(tahunOpsi)
                                    "
                                    @click="pilihTahun(tahunOpsi)"
                                    :class="
                                        String(filterTahun) ===
                                        String(tahunOpsi)
                                            ? 'bg-emerald-50 text-emerald-700 font-semibold'
                                            : 'text-gray-700 hover:bg-gray-50'
                                    "
                                    class="block w-full px-3 py-2 text-left text-sm"
                                >
                                    {{ tahunOpsi }}
                                </button>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- ===================== CHIP FILTER AKTIF ===================== -->
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
                 Nama, NIP, Total; grid bulan; bar Batal/Simpan saat edit;
                 rincian Terpakai. Kuning + * = koreksi Admin HR. -->
            <div class="lg:hidden">
                <ul v-if="laporan.length > 0" class="grid gap-3 sm:grid-cols-2">
                    <li
                        v-for="(item, index) in laporan"
                        :key="item.id || startIndex + index"
                        class="flex flex-col rounded-xl border bg-white overflow-hidden transition-colors"
                        :class="
                            sedangEditBaris(item)
                                ? 'border-emerald-400 ring-2 ring-emerald-200'
                                : 'border-slate-200 active:bg-violet-50/40'
                        "
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
                                    {{ totalTampil(item)
                                    }}<sup
                                        v-if="adaEdit(item)"
                                        class="ml-0.5 text-amber-600 font-black"
                                        >*</sup
                                    >
                                </span>
                            </div>
                        </div>

                        <!-- Grid bulan -->
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
                                :class="kelasBulanKartu(item, col.key)"
                                :title="titleBulan(item, col.key)"
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

                                <!-- Sedang diedit -->
                                <input
                                    v-if="sedangEditSel(item, col.key)"
                                    data-sel-edit
                                    v-model="nilaiEdit"
                                    type="number"
                                    inputmode="numeric"
                                    min="0"
                                    max="31"
                                    step="1"
                                    :disabled="sedangSimpan"
                                    @keydown.enter.prevent="simpanSel(item)"
                                    @keydown.esc.prevent="batalEditSel"
                                    class="mt-1 w-12 rounded-md border-2 border-emerald-500 bg-white px-1 py-0.5 text-center text-base font-bold tabular-nums text-slate-800 focus:outline-none [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
                                />

                                <!-- Admin HR: ketuk untuk edit -->
                                <button
                                    v-else-if="bisaEdit"
                                    type="button"
                                    @click="mulaiEditSel(item, col.key)"
                                    class="mt-1 min-w-[32px] rounded-md px-1 text-sm leading-none tabular-nums focus:outline-none focus:ring-2 focus:ring-emerald-400"
                                    :class="
                                        nilaiBulan(item, col.key) > 0 ||
                                        bulanDiedit(item, col.key)
                                            ? 'font-bold'
                                            : 'font-medium'
                                    "
                                >
                                    {{ nilaiBulan(item, col.key)
                                    }}<sup
                                        v-if="bulanDiedit(item, col.key)"
                                        class="ml-0.5 text-amber-600 font-black"
                                        >*</sup
                                    >
                                </button>

                                <!-- Bukan Admin HR: hanya tampil -->
                                <span
                                    v-else
                                    class="mt-1 text-sm leading-none tabular-nums"
                                    :class="
                                        nilaiBulan(item, col.key) > 0 ||
                                        bulanDiedit(item, col.key)
                                            ? 'font-bold'
                                            : 'font-medium'
                                    "
                                    >{{ nilaiBulan(item, col.key)
                                    }}<sup
                                        v-if="bulanDiedit(item, col.key)"
                                        class="ml-0.5 text-amber-600 font-black"
                                        >*</sup
                                    ></span
                                >
                            </div>
                        </div>

                        <!-- Aksi edit: Batal + Simpan (seperti kartu Rekap Kuota Detail) -->
                        <div
                            v-if="sedangEditBaris(item)"
                            class="flex items-center justify-between gap-2 border-t border-emerald-200 bg-emerald-50/60 px-3 py-2"
                        >
                            <p
                                class="min-w-0 text-[11px] leading-snug text-emerald-800"
                            >
                                Ubah angka bulan, lalu tekan Simpan.
                            </p>
                            <div class="flex shrink-0 items-center gap-1.5">
                                <button
                                    type="button"
                                    @click="batalEditSel"
                                    :disabled="sedangSimpan"
                                    class="min-h-[40px] cursor-pointer rounded-full bg-gray-200 px-3.5 py-1.5 text-xs font-semibold text-gray-700 transition hover:bg-gray-300 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    Batal
                                </button>
                                <button
                                    type="button"
                                    @click="simpanSel(item)"
                                    :disabled="sedangSimpan"
                                    class="inline-flex min-h-[40px] cursor-pointer items-center justify-center rounded-full bg-emerald-600 px-3.5 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {{
                                        sedangSimpan
                                            ? "Menyimpan..."
                                            : "Simpan"
                                    }}
                                </button>
                            </div>
                        </div>

                        <!-- Rincian Terpakai; kuning bila ada koreksi Admin HR -->
                        <div
                            class="mt-auto border-t px-3 py-2 text-[11px] leading-snug"
                            :class="
                                adaEdit(item)
                                    ? 'border-amber-200 bg-amber-50 text-amber-800'
                                    : 'border-slate-200 bg-slate-50 text-slate-600'
                            "
                        >
                            <p class="font-bold">{{ judulRincian(item) }}</p>
                            <ul class="mt-0.5 space-y-0.5">
                                <li
                                    v-for="baris in barisRincian(item)"
                                    :key="baris.key"
                                >
                                    &bull; {{ baris.label }}:
                                    <span class="font-bold">{{
                                        baris.nilai
                                    }}</span>
                                    hari
                                    <span
                                        v-if="baris.diedit"
                                        class="mt-0.5 block pl-3 font-semibold"
                                        :title="baris.info"
                                    >
                                        <template
                                            v-if="baris.bulanKoreksi.length > 0"
                                        >
                                            Dikoreksi:
                                            {{
                                                baris.bulanKoreksi
                                                    .map(
                                                        (b) =>
                                                            `${b.bulan.slice(0, 3)} ${b.sistem}→${b.sekarang}`,
                                                    )
                                                    .join(", ")
                                            }}
                                        </template>
                                        <template v-else
                                            >Koreksi, semula
                                            {{ baris.sistem }} hari</template
                                        >
                                    </span>
                                </li>
                            </ul>
                        </div>
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
                 Kolom No & Nama sticky. Saat sebuah baris diedit, kolom Aksi
                 berganti dari (i) menjadi ✓ (simpan) dan ✕ (batal). -->
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
                            <th
                                class="px-2 py-3 text-center text-slate-500 min-w-[6.5rem]"
                                title="Klik tombol untuk melihat rincian"
                            >
                                Aksi
                            </th>
                        </tr>
                    </thead>
                    <tbody
                        v-if="laporan.length > 0"
                        class="text-sm align-middle"
                    >
                        <template
                            v-for="(item, index) in laporan"
                            :key="item.id || startIndex + index"
                        >
                            <tr
                                class="group border-b border-slate-100 transition-all duration-150"
                                :class="
                                    sedangEditBaris(item)
                                        ? 'bg-emerald-50/60 shadow-[inset_4px_0_0_0_theme(colors.emerald.500)]'
                                        : 'hover:bg-violet-50 hover:shadow-[inset_4px_0_0_0_theme(colors.violet.400)]'
                                "
                            >
                                <td
                                    class="sticky left-0 z-10 w-10 min-w-[2.5rem] px-1 py-3 text-center text-sm font-semibold text-slate-800 border-r border-slate-200 transition-colors duration-150"
                                    :class="
                                        sedangEditBaris(item)
                                            ? 'bg-emerald-50'
                                            : 'bg-white group-hover:bg-violet-50'
                                    "
                                >
                                    {{ startIndex + index + 1 }}
                                </td>
                                <td
                                    class="sticky left-10 z-10 min-w-[170px] 2xl:min-w-[220px] px-3 py-3 border-r-2 border-slate-300 shadow-[2px_0_4px_-2px_rgba(0,0,0,0.08)] transition-colors duration-150"
                                    :class="
                                        sedangEditBaris(item)
                                            ? 'bg-emerald-50'
                                            : 'bg-white group-hover:bg-violet-50'
                                    "
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

                                <!-- Sel bulan -->
                                <td
                                    v-for="col in columnsToShow"
                                    :key="col.key"
                                    class="px-1 2xl:px-2 py-3 text-center"
                                >
                                    <!-- Sedang diedit: Enter / ✓ = simpan, Esc / ✕ = batal -->
                                    <input
                                        v-if="sedangEditSel(item, col.key)"
                                        data-sel-edit
                                        v-model="nilaiEdit"
                                        type="number"
                                        inputmode="numeric"
                                        min="0"
                                        max="31"
                                        step="1"
                                        :disabled="sedangSimpan"
                                        @keydown.enter.prevent="simpanSel(item)"
                                        @keydown.esc.prevent="batalEditSel"
                                        class="w-10 rounded-md border-2 border-emerald-500 px-1 py-1 text-center text-xs font-bold tabular-nums focus:outline-none [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
                                    />

                                    <!-- Admin HR: klik angka untuk mengedit -->
                                    <button
                                        v-else-if="bisaEdit"
                                        type="button"
                                        @click="mulaiEditSel(item, col.key)"
                                        :class="kelasBulanTabel(item, col.key)"
                                        :title="
                                            titleBulan(item, col.key) ||
                                            'Klik untuk mengubah'
                                        "
                                        class="inline-block min-w-[28px] 2xl:min-w-[32px] cursor-text px-1 2xl:px-2 py-1 rounded-md text-xs border hover:ring-2 hover:ring-emerald-300 focus:outline-none focus:ring-2 focus:ring-emerald-400"
                                    >
                                        {{ nilaiBulan(item, col.key)
                                        }}<sup
                                            v-if="bulanDiedit(item, col.key)"
                                            class="ml-0.5 text-amber-600 font-black"
                                            >*</sup
                                        >
                                    </button>

                                    <!-- Bukan Admin HR: hanya tampil -->
                                    <span
                                        v-else
                                        :class="kelasBulanTabel(item, col.key)"
                                        :title="titleBulan(item, col.key)"
                                        class="inline-block min-w-[28px] 2xl:min-w-[32px] px-1 2xl:px-2 py-1 rounded-md text-xs border"
                                    >
                                        {{ nilaiBulan(item, col.key)
                                        }}<sup
                                            v-if="bulanDiedit(item, col.key)"
                                            class="ml-0.5 text-amber-600 font-black"
                                            >*</sup
                                        >
                                    </span>
                                </td>

                                <!-- Total; * = ada angka yang dikoreksi Admin HR -->
                                <td
                                    class="px-2 py-3 text-center bg-slate-50/70"
                                >
                                    <span
                                        class="inline-block min-w-[36px] px-2 py-1 bg-emerald-100 text-emerald-800 rounded-md font-bold text-xs border border-emerald-300/80"
                                    >
                                        {{ totalTampil(item)
                                        }}<sup
                                            v-if="adaEdit(item)"
                                            class="ml-0.5 text-amber-600 font-black"
                                            >*</sup
                                        >
                                    </span>
                                </td>

                                <!-- Aksi: (i) rincian, atau ✓ / ✕ saat diedit -->
                                <td class="px-2 py-3 text-center">
                                    <div class="inline-flex items-center gap-1">
                                        <button
                                            v-if="!sedangEditBaris(item)"
                                            type="button"
                                            @click.stop="
                                                togglePopover($event, item, index)
                                            "
                                            aria-haspopup="dialog"
                                            :aria-expanded="
                                                popover?.key ===
                                                kunciItem(item, index)
                                            "
                                            title="Lihat rincian"
                                            aria-label="Lihat rincian"
                                            class="inline-flex h-7 w-7 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-500 transition-colors hover:border-emerald-300 hover:bg-emerald-50 hover:text-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-400"
                                            :class="
                                                popover?.key ===
                                                kunciItem(item, index)
                                                    ? '!border-emerald-300 !bg-emerald-100 !text-emerald-700'
                                                    : ''
                                            "
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
                                                    d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                                                ></path>
                                            </svg>
                                        </button>

                                        <template v-else>
                                            <button
                                                type="button"
                                                @click="simpanSel(item)"
                                                :disabled="sedangSimpan"
                                                class="inline-flex items-center px-2.5 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-full shadow-sm transition cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
                                                title="Simpan (Enter)"
                                                aria-label="Simpan"
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
                                                        d="M5 13l4 4L19 7"
                                                    />
                                                </svg>
                                            </button>
                                            <button
                                                type="button"
                                                @click="batalEditSel"
                                                :disabled="sedangSimpan"
                                                class="inline-flex items-center px-2.5 py-1.5 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-full transition cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
                                                title="Batal (Esc)"
                                                aria-label="Batal edit"
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
                                                        d="M6 18L18 6M6 6l12 12"
                                                    />
                                                </svg>
                                            </button>
                                        </template>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>

                    <!-- Empty state tabel (4 = No, Nama, Total, Aksi) -->
                    <tbody v-else class="bg-white">
                        <tr>
                            <td
                                :colspan="4 + columnsToShow.length"
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

            <!-- Catatan kaki -->
            <div v-if="laporan.length > 0" class="mt-3 space-y-1 text-xs">
                <p v-if="modeBulan" class="text-gray-500">
                    Total = jumlah cuti pada bulan yang dipilih.
                </p>
                <p v-else class="text-gray-500">
                    Total = jumlah cuti terpakai. Cuti yang ditangguhkan
                    dikembalikan ke saldo, jadi tidak dihitung.
                </p>
                <p v-if="adaItemDiedit" class="text-amber-700">
                    <span class="font-black">*</span> = ada angka yang dikoreksi
                    Admin HR (kotak kuning = bulan yang dikoreksi, lihat Rincian
                    di tiap pegawai). Total bisa berbeda dari hitungan sistem.
                </p>
            </div>

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

                <!-- HP: tombol besar + penanda halaman -->
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

            <!-- ===================== MODAL RINCIAN =====================
                 Teleport ke body. Desktop: di tengah; HP: bottom sheet. -->
            <Teleport to="body">
                <Transition
                    enter-active-class="transition duration-200 ease-out"
                    enter-from-class="opacity-0"
                    enter-to-class="opacity-100"
                    leave-active-class="transition duration-150 ease-in"
                    leave-from-class="opacity-100"
                    leave-to-class="opacity-0"
                >
                    <div
                        v-if="popover"
                        class="fixed inset-0 z-[9999] flex items-end justify-center bg-slate-900/60 sm:items-center sm:p-6"
                        @click.self="tutupPopover"
                    >
                        <div
                            role="dialog"
                            aria-modal="true"
                            aria-label="Rincian cuti terpakai"
                            class="relative flex max-h-[90vh] w-full flex-col overflow-hidden rounded-t-3xl bg-white shadow-2xl sm:max-w-md sm:rounded-2xl"
                        >
                            <!-- Handle (HP) -->
                            <div class="flex justify-center pt-2 sm:hidden">
                                <span
                                    class="h-1.5 w-12 rounded-full bg-slate-300"
                                ></span>
                            </div>

                            <!-- Kepala: avatar, nama, NIP, tombol tutup -->
                            <div
                                class="flex items-center gap-3 px-4 pb-2.5 pt-2.5 sm:pt-3.5"
                            >
                                <span
                                    class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-emerald-600 text-sm font-bold text-white"
                                    >{{
                                        (popover.item.nama || "?")
                                            .trim()
                                            .charAt(0)
                                            .toUpperCase()
                                    }}</span
                                >
                                <div class="min-w-0 flex-1">
                                    <p
                                        class="text-xs font-bold uppercase leading-snug text-slate-800 break-words"
                                    >
                                        {{ popover.item.nama }}
                                    </p>
                                    <p
                                        class="text-[11px] text-slate-500 tabular-nums"
                                    >
                                        NIP: {{ popover.item.nip }}
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    @click="tutupPopover"
                                    aria-label="Tutup rincian"
                                    ref="tombolTutupRef"
                                    class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-slate-200 bg-white text-sm text-slate-500 transition-colors hover:bg-slate-50 hover:text-slate-700 focus:outline-none focus:ring-2 focus:ring-emerald-400"
                                >
                                    ✕
                                </button>
                            </div>

                            <!-- Isi -->
                            <div
                                class="flex-1 space-y-2 overflow-y-auto border-t border-slate-100 px-4 py-3"
                            >
                                <p
                                    class="text-[11px] font-bold uppercase tracking-wide text-slate-500"
                                >
                                    {{ judulRincian(popover.item) }}
                                </p>

                                <!-- Terpakai -->
                                <div
                                    v-for="baris in barisRincian(popover.item)"
                                    :key="baris.key"
                                    class="rounded-lg border px-3 py-2"
                                    :class="
                                        baris.diedit
                                            ? 'border-amber-300 bg-amber-50/40'
                                            : 'border-slate-200'
                                    "
                                >
                                    <div
                                        class="flex items-start justify-between gap-3"
                                    >
                                        <div class="min-w-0">
                                            <p
                                                class="text-xs font-semibold text-slate-700"
                                            >
                                                {{ baris.label }}
                                            </p>
                                            <p
                                                class="mt-0.5 text-[11px] leading-snug text-slate-500"
                                            >
                                                {{ keteranganBaris(baris) }}
                                            </p>
                                        </div>
                                        <div class="shrink-0 text-right">
                                            <div
                                                class="text-lg font-bold leading-none tabular-nums text-slate-800"
                                            >
                                                {{ baris.nilai }}
                                                <span
                                                    class="text-xs font-medium text-slate-500"
                                                    >hari</span
                                                >
                                            </div>
                                            <span
                                                class="mt-1 inline-block rounded-full px-2 py-0.5 text-[10px] font-bold"
                                                :class="
                                                    baris.diedit
                                                        ? 'bg-amber-100 text-amber-800'
                                                        : 'bg-slate-100 text-slate-600'
                                                "
                                                >{{
                                                    baris.diedit
                                                        ? baris.bulanKoreksi
                                                              .length > 0
                                                            ? "Dikoreksi per bulan"
                                                            : "Koreksi Admin HR"
                                                        : "Sesuai sistem"
                                                }}</span
                                            >
                                        </div>
                                    </div>

                                    <!-- Bulan yang dikoreksi: sistem → sekarang -->
                                    <ul
                                        v-if="baris.bulanKoreksi.length > 0"
                                        class="mt-2 divide-y divide-amber-100 rounded border border-amber-200 bg-white text-[11px]"
                                    >
                                        <li
                                            v-for="b in baris.bulanKoreksi"
                                            :key="b.key"
                                            class="flex items-center justify-between px-2 py-1"
                                        >
                                            <span class="text-slate-600">{{
                                                b.bulan
                                            }}</span>
                                            <span
                                                class="font-semibold tabular-nums text-slate-800"
                                                >{{ b.sistem }} →
                                                {{ b.sekarang }} hari</span
                                            >
                                        </li>
                                    </ul>

                                    <p
                                        v-if="baris.diedit && baris.info"
                                        class="mt-1.5 rounded bg-amber-100/70 px-2 py-1 text-[11px] leading-snug text-amber-800"
                                    >
                                        {{ baris.info }}
                                    </p>
                                </div>
                            </div>

                            <!-- Kaki -->
                            <div
                                class="flex flex-col-reverse gap-2 border-t border-slate-100 px-4 py-2.5 sm:flex-row sm:justify-end"
                            >
                                <button
                                    type="button"
                                    @click="tutupPopover"
                                    class="w-full min-h-[40px] rounded-full bg-slate-800 px-6 text-sm font-semibold text-white transition-colors hover:bg-slate-900 active:bg-black sm:w-auto"
                                >
                                    Tutup Rincian
                                </button>
                            </div>
                        </div>
                    </div>
                </Transition>
            </Teleport>
        </div>
    </MainLayout>
</template>