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
    // [KOREKSI BULANAN] Tahun laporan (dari server) & izin edit (hanya Admin HR).
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

// [FILTER BULAN] true bila filter Bulan aktif. Saat true: kolom Tgh
// disembunyikan dan Total hanya menampilkan cuti pada bulan terpilih.
// Saat "Semua Bulan" tampilan penuh (Jan-Des + Tgh + Total) tetap seperti biasa.
const modeBulan = computed(() => Boolean(filterBulan.value));

// Nilai cuti satu bulan untuk satu pegawai (dipakai tabel & kartu)
const nilaiBulan = (item, key) => {
    const nilai = item.cuti?.[key];
    return nilai !== undefined && nilai !== null ? nilai : 0;
};

// [KOREKSI BULANAN] Sel bulan yang dikoreksi Admin HR (kotak kuning + tanda *).
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
// TGH (CUTI DITANGGUHKAN), TANDA * & RINCIAN TOTAL
// Semua angka & status dihitung di BACKEND (AdminController::
// buildLaporanData()); halaman ini hanya menampilkannya, tidak
// menebak dari selisih jumlah bulan lagi.
//
// Field dari backend per pegawai:
//   terpakai / terpakai_sistem / terpakai_diedit
//   tgh      / tgh_sistem      / tgh_diedit
//   ada_edit, total, info_terpakai_edit, info_tgh_edit
//   cuti / cuti_sistem / cuti_diedit / cuti_basi (per bulan)
//   tgh_tahunan, koreksi_tahunan_lama, ada_koreksi_bulanan
//
// Total = Terpakai + Tgh (cuti ditangguhkan), sama dengan Terpakai
// di Rekap Kuota Detail. Teks di layar dibuat ringkas:
//   - tidak ada koreksi : angka saja (tanpa keterangan tambahan)
//   - ada koreksi       : "Koreksi, semula X hari"
//
// Tanda * di angka Total muncul bila SALAH SATU komponen dikoreksi Admin HR.
// Saat filter Bulan aktif, backend mengirim kedua komponen berstatus
// "sistem" (koreksi Admin bersifat tahunan), jadi tanda * tidak muncul.
//
// [FILTER BULAN] Saat filter Bulan aktif, kolom Tgh tidak ditampilkan dan
// Total = Terpakai bulan itu saja (Tgh tidak ikut dijumlahkan), supaya
// angka di layar konsisten: kolom bulan = Total.
//
// CATATAN PENGGABUNGAN: logika lama dari versi sebelumnya
// (totalDariBulan, adaKoreksi, adaItemDikoreksi, keteranganKoreksi)
// DIHAPUS karena digantikan sepenuhnya oleh field ada_edit dari backend.
// ================================================================
const adaEdit = (item) => Boolean(item.ada_edit);

const adaItemDiedit = computed(() => props.laporan.some(adaEdit));

// Tgh di kotak Tgh (selalu tampil di mode "Semua Bulan", nilai 0 pun tetap ditampilkan)
const nilaiTgh = (item) => Number(item.tgh) || 0;

// [FILTER BULAN] Total yang ditampilkan di tabel/kartu/modal.
//   - Semua Bulan : Total dari backend (Terpakai + Tgh)
//   - Filter bulan: hanya Terpakai bulan itu (tanpa Tgh)
const totalTampil = (item) =>
    modeBulan.value ? Number(item.terpakai) || 0 : Number(item.total) || 0;

// Nama bulan yang sedang difilter (null bila "Semua Bulan"). Memakai
// props.filters.bulan supaya berubah bersamaan dengan data dari server.
const namaBulanAktif = computed(() =>
    props.filters.bulan
        ? bulanList[parseInt(props.filters.bulan, 10) - 1]
        : null,
);

// Catatan koreksi: kosong bila angka sesuai sistem, supaya layar tidak
// dipenuhi kata "sistem" untuk kondisi yang normal.
const teksStatus = (diedit, sistem) =>
    diedit ? `koreksi, semula ${Number(sistem) || 0} hari` : "";

// Tooltip jejak koreksi: siapa, kapan, alasan (bila ada).
const teksInfoEdit = (info) => {
    if (!info) return undefined;
    let teks = `Dikoreksi oleh ${info.oleh || "Admin HR"}`;
    if (info.waktu) teks += `, ${info.waktu}`;
    if (info.alasan) teks += `. Alasan: ${info.alasan}`;
    return teks;
};

// Dua baris rincian, SELALU berurutan: Terpakai, lalu Tgh.
const semuaBarisRincian = (item) => [
    {
        key: "terpakai",
        label: "Terpakai",
        nilai: Number(item.terpakai) || 0,
        diedit: Boolean(item.terpakai_diedit),
        sistem: Number(item.terpakai_sistem) || 0,
        status: teksStatus(item.terpakai_diedit, item.terpakai_sistem),
        info: teksInfoEdit(item.info_terpakai_edit),
    },
    {
        key: "tgh",
        label: "Tgh",
        nilai: Number(item.tgh) || 0,
        diedit: Boolean(item.tgh_diedit),
        sistem: Number(item.tgh_sistem) || 0,
        status: teksStatus(item.tgh_diedit, item.tgh_sistem),
        info: teksInfoEdit(item.info_tgh_edit),
    },
];

// [FILTER BULAN] Saat filter bulan aktif, baris Tgh tidak ditampilkan
// (hanya Terpakai), sejalan dengan kolom Tgh yang disembunyikan.
const barisRincian = (item) => {
    const baris = semuaBarisRincian(item);
    return modeBulan.value ? baris.slice(0, 1) : baris;
};

// ================================================================
// MODAL RINCIAN TOTAL (tabel desktop)
// Klik tombol (i) di kolom Aksi -> rincian muncul sebagai MODAL di
// TENGAH layar (desktop) atau bottom sheet dari bawah (layar HP), dengan
// latar gelap di belakangnya. Tetap terbuka sampai ditutup: klik area
// gelap di luar kotak, tombol X, tombol "Tutup Rincian", atau tekan Esc.
// Dirender lewat Teleport ke <body> supaya tidak terpotong overflow tabel.
// Selama modal terbuka, scroll halaman di belakangnya dikunci. Fokus
// pindah ke tombol X saat dibuka dan kembali ke tombol (i) saat ditutup.
// ================================================================
const popover = ref(null);
const tombolTutupRef = ref(null); // tombol X di modal (menerima fokus saat dibuka)
let pemicuFokus = null; // tombol (i) yang membuka modal (non-reaktif)

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

// Kalimat penjelas asal angka tiap komponen (bahasa sederhana)
const keteranganBaris = (baris) => {
    if (baris.diedit) {
        return `Hasil sistem ${baris.sistem} hari, dikoreksi Admin HR jadi ${baris.nilai} hari.`;
    }
    if (baris.key === "terpakai") {
        return "Dihitung otomatis dari cuti yang disetujui.";
    }
    return "Cuti disetujui yang ditangguhkan. Tetap dihitung di Total.";
};

// [PERBAIKAN] Pada file asli, template string ini rusak (backtick dan ${ }
// hilang) sehingga judul tampil sebagai teks mentah. Sekarang sudah benar.
const judulRincian = (item) =>
    `Rincian Total: ${totalTampil(item)} hari${
        namaBulanAktif.value ? ` (${namaBulanAktif.value})` : ""
    }`;

// ================================================================
// [KOREKSI BULANAN] MODAL EDIT KOREKSI (hanya Admin HR / bisaEdit)
// Admin mengubah angka per bulan (Jan-Des) dan total Tgh tahun laporan
// dalam SATU form, lalu disimpan ke route admin.rekap.koreksi.
//
//   - Kolom bulan dikosongkan, atau diisi sama dengan angka sistem
//     -> koreksi bulan itu dihapus (kembali ke hitungan sistem).
//   - Form selalu memakai angka SEPENUHNYA SETAHUN (item.cuti & item.tgh_tahunan),
//     tidak terpengaruh filter Bulan.
//   - Batas (Terpakai <= Total Hak Tersedia) dijaga backend; pesan
//     galatnya ditampilkan di dalam modal.
// ================================================================
const editItem = ref(null);
const formCuti = ref({});
const formTgh = ref("");
const galatEdit = ref("");
const sedangSimpan = ref(false);
const tombolTutupEditRef = ref(null);
let pemicuFokusEdit = null;

const keAngka = (v) => {
    if (v === null || v === undefined) return null;
    const s = String(v).trim();
    if (s === "") return null;
    return Number(s);
};

const angkaValid = (n, maks) =>
    n === null || (Number.isInteger(n) && n >= 0 && n <= maks);

const bukaEdit = (item, event = null) => {
    if (!props.bisaEdit) return;
    tutupPopover();
    pemicuFokusEdit = event?.currentTarget ?? null;
    const cuti = {};
    keyBulan.forEach((key) => {
        cuti[key] = String(Number(item.cuti?.[key]) || 0);
    });
    formCuti.value = cuti;
    formTgh.value = String(Number(item.tgh_tahunan) || 0);
    galatEdit.value = "";
    editItem.value = item;
    nextTick(() => tombolTutupEditRef.value?.focus());
};

const tutupEdit = () => {
    if (!editItem.value || sedangSimpan.value) return;
    editItem.value = null;
    galatEdit.value = "";
    const pemicu = pemicuFokusEdit;
    pemicuFokusEdit = null;
    nextTick(() => pemicu?.focus?.());
};

const sistemBulan = (key) => Number(editItem.value?.cuti_sistem?.[key]) || 0;
const semulaBulan = (key) => Number(editItem.value?.cuti?.[key]) || 0;

// Kolom kosong = kembali ke angka sistem.
const nilaiForm = (key) => {
    const n = keAngka(formCuti.value[key]);
    return n === null ? sistemBulan(key) : n;
};

const bulanBerbedaDariSistem = (key) => nilaiForm(key) !== sistemBulan(key);

const kembalikanKeSistem = (key) => {
    formCuti.value[key] = String(sistemBulan(key));
};

const totalTerpakaiForm = computed(() =>
    keyBulan.reduce((jumlah, key) => {
        const n = nilaiForm(key);
        return jumlah + (Number.isFinite(n) ? n : 0);
    }, 0),
);

const tghForm = computed(() => {
    const n = keAngka(formTgh.value);
    return n === null || !Number.isFinite(n) ? 0 : n;
});

const adaInputTidakValid = computed(() => {
    if (!editItem.value) return false;
    const bulanSalah = keyBulan.some(
        (key) => !angkaValid(keAngka(formCuti.value[key]), 31),
    );
    return bulanSalah || !angkaValid(keAngka(formTgh.value), 365);
});

const adaPerubahanForm = computed(() => {
    if (!editItem.value) return false;
    const bulanBerubah = keyBulan.some(
        (key) => nilaiForm(key) !== semulaBulan(key),
    );
    const tghBerubah =
        tghForm.value !== (Number(editItem.value.tgh_tahunan) || 0);
    return bulanBerubah || tghBerubah;
});

const simpanEdit = () => {
    if (!editItem.value || sedangSimpan.value) return;
    if (adaInputTidakValid.value) {
        galatEdit.value =
            "Isi angka bulat: 0 sampai 31 untuk bulan, 0 sampai 365 untuk Tgh.";
        return;
    }
    if (!adaPerubahanForm.value) {
        galatEdit.value = "Belum ada angka yang berubah.";
        return;
    }

    const cuti = {};
    keyBulan.forEach((key) => {
        cuti[key] = keAngka(formCuti.value[key]);
    });

    router.post(
        route("admin.rekap.koreksi", editItem.value.id),
        {
            tahun: props.tahun || Number(filterTahun.value),
            cuti,
            tgh: keAngka(formTgh.value) ?? 0,
        },
        {
            preserveScroll: true,
            preserveState: true,
            onStart: () => {
                sedangSimpan.value = true;
                galatEdit.value = "";
            },
            onSuccess: () => {
                sedangSimpan.value = false;
                tutupEdit();
            },
            onError: (errors) => {
                galatEdit.value =
                    Object.values(errors || {})
                        .flat()
                        .join(" ") || "Gagal menyimpan koreksi.";
            },
            onFinish: () => {
                sedangSimpan.value = false;
            },
        },
    );
};

// Esc menutup modal yang paling atas (edit dulu, baru rincian).
const tutupPopoverSaatEsc = (event) => {
    if (event.key !== "Escape") return;
    if (editItem.value) tutupEdit();
    else tutupPopover();
};

// Kunci scroll halaman selama salah satu modal terbuka & fokuskan tombol X.
watch(
    () => [popover.value, editItem.value],
    ([rincian, edit]) => {
        document.body.style.overflow = rincian || edit ? "hidden" : "";
        if (rincian && !edit) nextTick(() => tombolTutupRef.value?.focus());
    },
);

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
    document.addEventListener("keydown", tutupPopoverSaatEsc);
});
onBeforeUnmount(() => {
    document.removeEventListener("click", tutupDropdownSaatKlikLuar);
    document.removeEventListener("keydown", tutupDropdownSaatEsc);
    document.removeEventListener("keydown", tutupPopoverSaatEsc);
    // Pastikan scroll halaman tidak terkunci bila halaman ditinggalkan
    // saat modal masih terbuka.
    document.body.style.overflow = "";
    clearTimeout(searchTimeout);
});
</script>

<template>
    <Head title="Rekap Laporan Cuti" />

    <MainLayout>
        <!-- Kartu putih menjadi satu-satunya elemen anak langsung MainLayout
             (slot MainLayout dibungkus <Transition> yang hanya boleh punya
             SATU elemen anak). -->
        <div
            class="bg-white shadow-sm sm:rounded-xl rounded-lg border border-gray-200 p-3 sm:p-4"
        >
            <!-- ===================== JUDUL + EXPORT (baris atas) ===================== -->
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
                            Admin HR dapat mengoreksi angka per bulan lewat
                            tombol pensil.
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

            <!-- ===================== TOOLBAR: SEARCH + FILTER =====================
                 Layout responsif:
                   - HP (< 640px)     : Search 1 baris, Kelompok 1 baris,
                                        Bulan & Tahun berdampingan
                   - Tablet (640-1023): Search 1 baris, lalu Kelompok | Bulan | Tahun
                                        dalam satu baris
                   - Desktop (>= 1024): Search + 3 filter dalam SATU baris;
                                        turun rapi ke baris kedua bila ruang
                                        tidak cukup. -->
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
                    <!-- Filter Kelompok Substansi -->
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

                    <!-- FILTER TAHUN — DINAMIS (composable useTahunFilter) -->
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
                 Di bawah breakpoint lg tabel diganti kartu: nama, NIP, dan
                 Total Cuti di bagian atas, lalu grid bulan (4 x 3) ditambah
                 kotak Tgh (cuti ditangguhkan) di ujungnya, dan di bawahnya
                 RINCIAN TOTAL dua baris (Terpakai & Tgh).
                 Bila angka dikoreksi Admin HR, muncul catatan
                 "koreksi, semula X hari". Warna kuning + tanda * di Total
                 hanya bila ada komponen yang dikoreksi Admin HR.
                 [FILTER BULAN] Grid hanya menampilkan bulan terpilih (kotak
                 Tgh disembunyikan), Total = cuti bulan itu, rincian hanya
                 baris Terpakai.
                 [KOREKSI BULANAN] Admin HR mendapat tombol "Edit Koreksi". -->
            <div class="lg:hidden">
                <ul v-if="laporan.length > 0" class="grid gap-3 sm:grid-cols-2">
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
                                    {{ totalTampil(item)
                                    }}<sup
                                        v-if="adaEdit(item)"
                                        class="ml-0.5 text-amber-600 font-black"
                                        >*</sup
                                    >
                                </span>
                            </div>
                        </div>

                        <!-- Grid bulan + kotak Tgh (kotak Tgh hanya di mode "Semua Bulan") -->
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
                                <span
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

                            <!-- Kotak Tgh: oranye bila ada, abu-abu bila 0.
                                 Tanda * = Tgh dikoreksi Admin HR.
                                 Disembunyikan saat filter Bulan aktif. -->
                            <div
                                v-if="!modeBulan"
                                :class="
                                    nilaiTgh(item) > 0
                                        ? 'bg-orange-100 border-orange-300/80 text-orange-800'
                                        : 'bg-slate-50 border-slate-200 text-slate-400'
                                "
                                class="flex flex-col items-center justify-center rounded-lg border py-1.5"
                                title="Tgh = cuti yang sudah disetujui lalu ditangguhkan. Harinya tetap dihitung di Total."
                            >
                                <span
                                    class="text-[10px] font-semibold leading-none"
                                    >Tgh</span
                                >
                                <span
                                    class="mt-1 text-sm leading-none tabular-nums"
                                    :class="
                                        nilaiTgh(item) > 0
                                            ? 'font-bold'
                                            : 'font-medium'
                                    "
                                    >{{ nilaiTgh(item)
                                    }}<sup
                                        v-if="item.tgh_diedit"
                                        class="ml-0.5 text-amber-600 font-black"
                                        >*</sup
                                    ></span
                                >
                            </div>
                        </div>

                        <!-- RINCIAN TOTAL: selalu tampil. Mode "Semua Bulan": dua baris
                             (Terpakai & Tgh). Mode filter bulan: hanya Terpakai.
                             Catatan koreksi hanya muncul bila angka diubah Admin HR. -->
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
                                        >Koreksi, semula
                                        {{ baris.sistem }} hari</span
                                    >
                                </li>
                            </ul>
                        </div>

                        <!-- [KOREKSI BULANAN] Tombol edit (hanya Admin HR) -->
                        <button
                            v-if="bisaEdit"
                            type="button"
                            @click="bukaEdit(item, $event)"
                            class="inline-flex min-h-[44px] w-full items-center justify-center gap-1.5 border-t border-slate-200 bg-white text-xs font-semibold text-emerald-700 transition-colors hover:bg-emerald-50 active:bg-emerald-100"
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
                                    d="M15.232 5.232l3.536 3.536M9 13l6.768-6.768a2 2 0 112.828 2.828L11.828 15.83a4 4 0 01-1.414.943L7 18l1.227-3.414A4 4 0 019 13z"
                                ></path>
                            </svg>
                            Edit Koreksi
                        </button>
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
                 Tgh + Total) terlihat sekaligus tanpa geser horizontal pada
                 browser zoom 100% dengan sidebar terbuka:
                   - header bulan disingkat, ditulis lengkap di layar 2xl
                   - sel & kapsul angka rapat
                   - kolom No & Nama menempel (sticky) di kiri
                 [FILTER BULAN] Kolom Tgh disembunyikan dan Total = cuti
                 bulan terpilih.
                 Tiap pegawai = SATU baris. RINCIAN TOTAL muncul sebagai MODAL
                 saat tombol (i) di kolom Aksi diklik. Admin HR juga mendapat
                 tombol pensil untuk MODAL EDIT KOREKSI. -->
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
                                v-if="!modeBulan"
                                class="px-1 2xl:px-2 py-3 text-center text-orange-600"
                                title="Tgh = cuti yang sudah disetujui lalu ditangguhkan. Harinya tetap dihitung di Total."
                            >
                                Tgh
                            </th>
                            <th
                                class="px-2 py-3 text-center text-slate-800 bg-slate-100/70"
                            >
                                Total
                            </th>
                            <th
                                class="px-2 py-3 text-center text-slate-500"
                                :title="
                                    bisaEdit
                                        ? 'Lihat rincian total atau edit koreksi'
                                        : 'Klik tombol untuk melihat rincian total'
                                "
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
                            <!-- Baris data -->
                            <tr
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
                                <!-- Kolom Tgh: oranye bila ada, abu-abu bila 0.
                                     Tanda * = Tgh dikoreksi Admin HR.
                                     Disembunyikan saat filter Bulan aktif. -->
                                <td
                                    v-if="!modeBulan"
                                    class="px-1 2xl:px-2 py-3 text-center"
                                >
                                    <span
                                        :class="
                                            nilaiTgh(item) > 0
                                                ? 'bg-orange-100 text-orange-800 font-bold border-orange-300/80'
                                                : 'bg-slate-100 text-slate-500 font-medium border-slate-200'
                                        "
                                        class="inline-block min-w-[28px] 2xl:min-w-[32px] px-1 2xl:px-2 py-1 rounded-md text-xs border"
                                        :title="
                                            item.tgh_diedit
                                                ? teksInfoEdit(
                                                      item.info_tgh_edit,
                                                  )
                                                : undefined
                                        "
                                    >
                                        {{ nilaiTgh(item)
                                        }}<sup
                                            v-if="item.tgh_diedit"
                                            class="ml-0.5 text-amber-600 font-black"
                                            >*</sup
                                        >
                                    </span>
                                </td>
                                <td
                                    class="px-2 py-3 text-center bg-slate-50/70"
                                >
                                    <!-- Tanda * = ada komponen (Terpakai atau
                                         Tgh) yang dikoreksi Admin HR. Rinciannya
                                         ada di modal kolom Aksi. -->
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

                                <!-- Kolom Aksi: (i) rincian + pensil edit koreksi -->
                                <td class="px-2 py-3 text-center">
                                    <div class="inline-flex items-center gap-1">
                                        <button
                                            type="button"
                                            @click.stop="
                                                togglePopover(
                                                    $event,
                                                    item,
                                                    index,
                                                )
                                            "
                                            aria-haspopup="dialog"
                                            :aria-expanded="
                                                popover?.key ===
                                                kunciItem(item, index)
                                            "
                                            title="Lihat rincian total"
                                            aria-label="Lihat rincian total"
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
                                        <button
                                            v-if="bisaEdit"
                                            type="button"
                                            @click.stop="bukaEdit(item, $event)"
                                            title="Edit koreksi cuti"
                                            aria-label="Edit koreksi cuti"
                                            class="inline-flex h-7 w-7 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-500 transition-colors hover:border-amber-300 hover:bg-amber-50 hover:text-amber-700 focus:outline-none focus:ring-2 focus:ring-amber-400"
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
                                                    d="M15.232 5.232l3.536 3.536M9 13l6.768-6.768a2 2 0 112.828 2.828L11.828 15.83a4 4 0 01-1.414.943L7 18l1.227-3.414A4 4 0 019 13z"
                                                ></path>
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>

                    <tbody v-else class="bg-white">
                        <tr>
                            <td
                                :colspan="
                                    (modeBulan ? 4 : 5) + columnsToShow.length
                                "
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

            <!-- Catatan kaki: penjelasan Tgh tampil di mode "Semua Bulan";
                 saat filter bulan aktif diganti penjelasan Total per bulan.
                 Catatan bintang hanya bila ada angka yang dikoreksi Admin HR. -->
            <div v-if="laporan.length > 0" class="mt-3 space-y-1 text-xs">
                <p v-if="!modeBulan" class="text-gray-500">
                    <span class="font-semibold text-orange-600">Tgh</span>
                    = cuti yang sudah disetujui lalu ditangguhkan, tetap
                    dihitung di Total. Total = Terpakai + Tgh.
                </p>
                <p v-else class="text-gray-500">
                    Total = jumlah cuti pada bulan yang dipilih.
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

            <!-- ===================== MODAL RINCIAN TOTAL & MODAL EDIT KOREKSI =====================
                 Modal berada DI DALAM kartu (bukan elemen kedua di MainLayout)
                 karena slot MainLayout dibungkus <Transition> yang hanya boleh
                 punya SATU elemen anak. Teleport tetap memindahkan tampilannya
                 ke <body>.
                 Desktop/tablet : kotak putih di TENGAH layar dengan latar gelap.
                 HP (< 640px)   : bottom sheet yang naik dari bawah.
                 Ditutup lewat: klik area gelap, tombol X, tombol "Tutup /
                 Batal", atau Esc. -->
            <Teleport to="body">
                <!-- ---------- MODAL RINCIAN TOTAL ---------- -->
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
                            aria-label="Rincian total cuti"
                            class="relative flex max-h-[90vh] w-full flex-col overflow-hidden rounded-t-3xl bg-white shadow-2xl sm:max-w-md sm:rounded-2xl"
                        >
                            <!-- Handle (hanya di HP) -->
                            <div class="flex justify-center pt-2 sm:hidden">
                                <span
                                    class="h-1.5 w-12 rounded-full bg-slate-300"
                                ></span>
                            </div>

                            <!-- Kepala ringkas: avatar + nama + NIP + tombol tutup -->
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

                            <!-- Isi (bisa di-scroll) -->
                            <div
                                class="flex-1 space-y-2 overflow-y-auto border-t border-slate-100 px-4 py-3"
                            >
                                <p
                                    class="text-[11px] font-bold uppercase tracking-wide text-slate-500"
                                >
                                    {{ judulRincian(popover.item) }}
                                </p>

                                <!-- Terpakai & Tgh: label + keterangan di kiri,
                                     angka + badge status di kanan.
                                     Saat filter bulan aktif hanya baris Terpakai. -->
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
                                                        ? "Koreksi Admin HR"
                                                        : "Sesuai sistem"
                                                }}</span
                                            >
                                        </div>
                                    </div>
                                    <p
                                        v-if="baris.diedit && baris.info"
                                        class="mt-1.5 rounded bg-amber-100/70 px-2 py-1 text-[11px] leading-snug text-amber-800"
                                    >
                                        {{ baris.info }}
                                    </p>
                                </div>

                                <!-- Total: satu baris -->
                                <div
                                    class="rounded-lg border border-emerald-200 bg-emerald-50/70 px-3 py-2"
                                >
                                    <div
                                        class="flex items-center justify-between gap-3"
                                    >
                                        <span
                                            class="text-xs font-semibold text-emerald-700"
                                            >Total</span
                                        >
                                        <div
                                            class="text-lg font-bold leading-none tabular-nums text-emerald-800"
                                        >
                                            <!-- Semua Bulan: Terpakai + Tgh = Total -->
                                            <template v-if="!modeBulan">
                                                {{
                                                    barisRincian(
                                                        popover.item,
                                                    )[0].nilai
                                                }}
                                                <span
                                                    class="text-sm font-medium text-emerald-600"
                                                    >+</span
                                                >
                                                {{
                                                    barisRincian(
                                                        popover.item,
                                                    )[1].nilai
                                                }}
                                                <span
                                                    class="text-sm font-medium text-emerald-600"
                                                    >=</span
                                                >
                                            </template>
                                            {{ totalTampil(popover.item) }}
                                            <span
                                                class="text-xs font-medium text-emerald-700"
                                                >hari</span
                                            >
                                        </div>
                                    </div>
                                    <p
                                        class="mt-1 text-[11px] leading-snug text-slate-500"
                                    >
                                        {{
                                            modeBulan
                                                ? "Total = cuti pada bulan yang dipilih."
                                                : "Total = Terpakai + Tgh."
                                        }}
                                    </p>
                                    <p
                                        v-if="adaEdit(popover.item)"
                                        class="mt-1.5 rounded bg-amber-100/70 px-2 py-1 text-[11px] leading-snug text-amber-800"
                                    >
                                        Ada angka yang dikoreksi Admin HR, jadi
                                        Total bisa berbeda dari jumlah kolom
                                        bulan.
                                    </p>
                                </div>
                            </div>

                            <!-- Kaki: tombol tutup (+ Edit Koreksi untuk Admin HR) -->
                            <div
                                class="flex flex-col-reverse gap-2 border-t border-slate-100 px-4 py-2.5 sm:flex-row sm:justify-end"
                            >
                                <button
                                    v-if="bisaEdit"
                                    type="button"
                                    @click="bukaEdit(popover.item)"
                                    class="w-full min-h-[40px] rounded-full border border-amber-300 bg-amber-50 px-6 text-sm font-semibold text-amber-800 transition-colors hover:bg-amber-100 sm:w-auto"
                                >
                                    Edit Koreksi
                                </button>
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

                <!-- ---------- [KOREKSI BULANAN] MODAL EDIT KOREKSI ---------- -->
                <Transition
                    enter-active-class="transition duration-200 ease-out"
                    enter-from-class="opacity-0"
                    enter-to-class="opacity-100"
                    leave-active-class="transition duration-150 ease-in"
                    leave-from-class="opacity-100"
                    leave-to-class="opacity-0"
                >
                    <div
                        v-if="editItem"
                        class="fixed inset-0 z-[10000] flex items-end justify-center bg-slate-900/60 sm:items-center sm:p-6"
                        @click.self="tutupEdit"
                    >
                        <form
                            role="dialog"
                            aria-modal="true"
                            aria-label="Edit koreksi cuti"
                            class="relative flex max-h-[92vh] w-full flex-col overflow-hidden rounded-t-3xl bg-white shadow-2xl sm:max-w-xl sm:rounded-2xl"
                            @submit.prevent="simpanEdit"
                        >
                            <!-- Handle (hanya di HP) -->
                            <div class="flex justify-center pt-2 sm:hidden">
                                <span
                                    class="h-1.5 w-12 rounded-full bg-slate-300"
                                ></span>
                            </div>

                            <!-- Kepala -->
                            <div
                                class="flex items-center gap-3 px-4 pb-2.5 pt-2.5 sm:pt-3.5"
                            >
                                <span
                                    class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-amber-500 text-sm font-bold text-white"
                                    >{{
                                        (editItem.nama || "?")
                                            .trim()
                                            .charAt(0)
                                            .toUpperCase()
                                    }}</span
                                >
                                <div class="min-w-0 flex-1">
                                    <p
                                        class="text-xs font-bold uppercase leading-snug text-slate-800 break-words"
                                    >
                                        Edit Koreksi: {{ editItem.nama }}
                                    </p>
                                    <p
                                        class="text-[11px] text-slate-500 tabular-nums"
                                    >
                                        NIP: {{ editItem.nip }} &middot; Tahun
                                        {{ tahun }}
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    @click="tutupEdit"
                                    aria-label="Tutup edit koreksi"
                                    ref="tombolTutupEditRef"
                                    class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-slate-200 bg-white text-sm text-slate-500 transition-colors hover:bg-slate-50 hover:text-slate-700 focus:outline-none focus:ring-2 focus:ring-amber-400"
                                >
                                    ✕
                                </button>
                            </div>

                            <!-- Isi (bisa di-scroll) -->
                            <div
                                class="flex-1 space-y-3 overflow-y-auto border-t border-slate-100 px-4 py-3"
                            >
                                <p
                                    class="text-[11px] leading-snug text-slate-500"
                                >
                                    Ubah jumlah hari cuti per bulan. Kosongkan
                                    kolom, atau klik "Sistem", untuk kembali ke
                                    hitungan sistem. Angka ini juga menjadi Cuti
                                    Terpakai di Rekap Kuota Detail.
                                </p>

                                <!-- Peringatan koreksi tahunan lama -->
                                <p
                                    v-if="
                                        editItem.koreksi_tahunan_lama !==
                                            null &&
                                        editItem.koreksi_tahunan_lama !==
                                            undefined
                                    "
                                    class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-[11px] leading-snug text-amber-800"
                                >
                                    Pegawai ini masih memakai koreksi tahunan
                                    lama ({{ editItem.koreksi_tahunan_lama }}
                                    hari). Begitu koreksi bulanan pertama
                                    disimpan, koreksi tahunan itu dihapus dan
                                    angka bulanan menjadi acuan. Tempatkan
                                    selisihnya ke bulan yang tepat.
                                </p>

                                <!-- Grid 12 bulan -->
                                <div
                                    class="grid grid-cols-3 gap-2 sm:grid-cols-4"
                                >
                                    <div
                                        v-for="(label, idx) in bulanList"
                                        :key="keyBulan[idx]"
                                        class="rounded-lg border p-1.5"
                                        :class="
                                            bulanBerbedaDariSistem(
                                                keyBulan[idx],
                                            )
                                                ? 'border-amber-300 bg-amber-50'
                                                : 'border-slate-200 bg-white'
                                        "
                                    >
                                        <label
                                            :for="`koreksi-${keyBulan[idx]}`"
                                            class="block text-[10px] font-bold uppercase tracking-wide text-slate-500"
                                            >{{ label.slice(0, 3) }}</label
                                        >
                                        <input
                                            :id="`koreksi-${keyBulan[idx]}`"
                                            v-model="formCuti[keyBulan[idx]]"
                                            type="number"
                                            inputmode="numeric"
                                            min="0"
                                            max="31"
                                            step="1"
                                            class="mt-0.5 w-full rounded-md border border-slate-300 px-2 py-1.5 text-base font-bold tabular-nums text-slate-800 focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500 sm:text-sm"
                                        />
                                        <button
                                            type="button"
                                            @click="
                                                kembalikanKeSistem(
                                                    keyBulan[idx],
                                                )
                                            "
                                            :disabled="
                                                !bulanBerbedaDariSistem(
                                                    keyBulan[idx],
                                                )
                                            "
                                            class="mt-1 block w-full text-left text-[10px] tabular-nums text-slate-500 enabled:underline enabled:hover:text-slate-700 disabled:cursor-default"
                                            title="Kembalikan ke angka sistem"
                                        >
                                            Sistem:
                                            {{ sistemBulan(keyBulan[idx]) }}
                                        </button>
                                        <p
                                            v-if="
                                                editItem.cuti_basi?.[
                                                    keyBulan[idx]
                                                ]
                                            "
                                            class="mt-0.5 text-[10px] font-semibold leading-tight text-red-600"
                                            title="Hitungan sistem bulan ini berubah sejak koreksi dibuat. Periksa ulang angkanya."
                                        >
                                            Sistem berubah, cek ulang
                                        </p>
                                    </div>
                                </div>

                                <!-- Tgh -->
                                <div
                                    class="rounded-lg border border-orange-200 bg-orange-50/60 p-2"
                                >
                                    <label
                                        for="koreksi-tgh"
                                        class="block text-[10px] font-bold uppercase tracking-wide text-orange-700"
                                        >Tgh (total setahun)</label
                                    >
                                    <input
                                        id="koreksi-tgh"
                                        v-model="formTgh"
                                        type="number"
                                        inputmode="numeric"
                                        min="0"
                                        max="365"
                                        step="1"
                                        class="mt-0.5 w-full rounded-md border border-slate-300 px-2 py-1.5 text-base font-bold tabular-nums text-slate-800 focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 sm:w-32 sm:text-sm"
                                    />
                                    <p
                                        class="mt-1 text-[10px] leading-snug text-slate-500"
                                    >
                                        Cuti ditangguhkan tahun {{ tahun }}.
                                        Sama dengan yang diedit di Rekap Kuota
                                        Detail.
                                    </p>
                                </div>

                                <!-- Pratinjau Total -->
                                <div
                                    class="rounded-lg border border-emerald-200 bg-emerald-50/70 px-3 py-2"
                                >
                                    <div
                                        class="flex items-center justify-between gap-3"
                                    >
                                        <span
                                            class="text-xs font-semibold text-emerald-700"
                                            >Total setelah disimpan</span
                                        >
                                        <div
                                            class="text-lg font-bold leading-none tabular-nums text-emerald-800"
                                        >
                                            {{ totalTerpakaiForm }}
                                            <span
                                                class="text-sm font-medium text-emerald-600"
                                                >+</span
                                            >
                                            {{ tghForm }}
                                            <span
                                                class="text-sm font-medium text-emerald-600"
                                                >=</span
                                            >
                                            {{ totalTerpakaiForm + tghForm }}
                                            <span
                                                class="text-xs font-medium text-emerald-700"
                                                >hari</span
                                            >
                                        </div>
                                    </div>
                                    <p
                                        class="mt-1 text-[11px] leading-snug text-slate-500"
                                    >
                                        Terpakai (jumlah 12 bulan) + Tgh. Cuti
                                        terpakai tidak boleh melebihi total hak
                                        yang tersedia.
                                    </p>
                                </div>

                                <p
                                    v-if="galatEdit"
                                    role="alert"
                                    class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs font-semibold leading-snug text-red-700"
                                >
                                    {{ galatEdit }}
                                </p>
                            </div>

                            <!-- Kaki -->
                            <div
                                class="flex flex-col-reverse gap-2 border-t border-slate-100 px-4 py-2.5 sm:flex-row sm:justify-end"
                            >
                                <button
                                    type="button"
                                    @click="tutupEdit"
                                    :disabled="sedangSimpan"
                                    class="w-full min-h-[40px] rounded-full border border-slate-300 bg-white px-6 text-sm font-semibold text-slate-600 transition-colors hover:bg-slate-50 disabled:opacity-50 sm:w-auto"
                                >
                                    Batal
                                </button>
                                <button
                                    type="submit"
                                    :disabled="
                                        sedangSimpan ||
                                        adaInputTidakValid ||
                                        !adaPerubahanForm
                                    "
                                    class="w-full min-h-[40px] rounded-full bg-emerald-700 px-6 text-sm font-semibold text-white transition-colors hover:bg-emerald-800 active:bg-emerald-900 disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto"
                                >
                                    {{
                                        sedangSimpan
                                            ? "Menyimpan..."
                                            : "Simpan Koreksi"
                                    }}
                                </button>
                            </div>
                        </form>
                    </div>
                </Transition>
            </Teleport>
        </div>
    </MainLayout>
</template>