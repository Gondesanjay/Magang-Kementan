<script setup>
import MainLayout from "@/Layouts/MainLayout.vue";
import { Head, router } from "@inertiajs/vue3";
import {
    ref,
    computed,
    watch,
    onMounted,
    onBeforeUnmount,
    nextTick,
} from "vue";

const props = defineProps({
    cutiTim: Array,
    // [BARU] Bulan yang sedang dilihat, format "YYYY-MM". Dikirim dari
    // CutiController::teamCalendar(), yang hanya mengirim cuti beririsan
    // dengan bulan ini (termasuk yang sudah lewat).
    bulan: String,
    kelompok_substansi: String,
    hariLiburs: Array, // Menerima data dari Controller (Database Admin HR)
    listKelompok: Array, // Dipakai untuk filter L3 & L4
    listTimKerja: Array, // Dipakai untuk filter L1 & L2
    userRoleId: Number, // 1=Staff, 2=L1, 3=L2, 4=L3, 6=L4
    // Nama tim kerja user. Sebelumnya dipakai badge info L1
    // ("Menampilkan tim Anda: ..."); badge itu sudah digantikan dropdown
    // filter, prop tetap diterima supaya kontrak dengan controller tidak berubah.
    userTimKerja: String,
    // ID pegawai yang sedang login, dikirim dari controller. Dipakai
    // untuk menandai entri milik sendiri dengan "(Anda)".
    userPegawaiId: Number,
    // Jumlah pengajuan disetujui pada bulan yang sedang dilihat,
    // dihitung di CutiController::teamCalendar() ($cutiTim->count()).
    disetujuiBulanIni: {
        type: Number,
        default: 0,
    },
});

// ================= BULAN YANG DILIHAT =================
const parseBulan = (s) => {
    const m = /^(\d{4})-(\d{2})$/.exec(s || "");
    return m
        ? new Date(Number(m[1]), Number(m[2]) - 1, 1)
        : new Date(new Date().getFullYear(), new Date().getMonth(), 1);
};
const formatBulan = (d) =>
    `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;

const currentDate = ref(parseBulan(props.bulan));
const memuat = ref(false); // true selama data bulan baru dimuat dari server

// Jaga sinkron bila server mengirim bulan lain (mis. tombol Back browser).
watch(
    () => props.bulan,
    (val) => {
        if (val && val !== formatBulan(currentDate.value)) {
            currentDate.value = parseBulan(val);
        }
    },
);

const namaBulan = [
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
const bulanAktif = computed(() => namaBulan[currentDate.value.getMonth()]);
const tahunAktif = computed(() => currentDate.value.getFullYear());
// ================= END BULAN YANG DILIHAT =================

// ================= [BARU] HELPER LAMPAU / HARI INI =================
// Pembeda dihitung PER TANGGAL SEL, bukan per pengajuan. Cuti 13-19 Okt
// akan tampak pudar di tanggal yang sudah lewat, normal di tanggal
// mendatang, dan jelas di hari ini. Lebih akurat untuk cuti yang sedang
// berjalan.
const toDateStr = (d) =>
    `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
const todayStr = toDateStr(new Date());

const isLampau = (dateStr) => !!dateStr && dateStr < todayStr;
const isHariIni = (dateStr) => dateStr === todayStr;
// ================= END HELPER LAMPAU / HARI INI =================

// --- FITUR FILTER BERJENJANG SESUAI LEVEL ATASAN ---
// L1 (role_id 2) & L2 (role_id 3): filter berdasarkan Tim Kerja (dalam
//                        kelompoknya). [DIUBAH] Sebelumnya L1 TANPA dropdown
//                        dan hanya melihat tim_kerja-nya sendiri; sekarang L1
//                        memakai mode filter yang sama dengan L2 (mode "l2").
// L3 & L4 (role_id 4/6): filter berdasarkan Subbagian/Kelompok (lintas kelompok)
// Staff & lainnya      : tanpa filter (perilaku lama)
const filterMode = computed(() => {
    if (props.userRoleId === 2 || props.userRoleId === 3) return "l2";
    if (props.userRoleId === 4 || props.userRoleId === 6) return "l3l4";
    return "none";
});

const showFilterDropdown = computed(
    () => filterMode.value === "l2" || filterMode.value === "l3l4",
);

// Field di object pegawai yang dipakai untuk mencocokkan filter lokal
const filterFieldName = computed(() =>
    filterMode.value === "l2" ? "tim_kerja" : "kelompok_substansi",
);
const filterAllLabel = computed(() =>
    filterMode.value === "l2" ? "Semua Tim Kerja" : "Semua Kelompok",
);
const filterOptions = computed(() => {
    if (filterMode.value === "l2") {
        return props.listTimKerja && props.listTimKerja.length > 0
            ? props.listTimKerja
            : [];
    }
    if (filterMode.value === "l3l4") {
        if (props.listKelompok && props.listKelompok.length > 0) {
            return props.listKelompok;
        }
        // Fallback data statis jika tidak dikirim dari backend
        return [
            "Subbagian Tata Usaha",
            "Kebijakan Pertanian",
            "Program dan Anggaran",
            "Pengembangan Kawasan",
            "Pemantauan & Evaluasi",
        ];
    }
    return [];
});

// Membaca parameter URL agar pilihan tidak kereset saat halaman dimuat ulang.
const getInitialFilter = () => {
    if (typeof window !== "undefined") {
        const params = new URLSearchParams(window.location.search);
        const filterParam = params.get("filter");
        if (filterParam) return filterParam;
    }
    return null;
};
const initialFilterFromUrl = getInitialFilter();
const selectedFilter = ref(initialFilterFromUrl ?? filterAllLabel.value);

// ================= [BARU] MUAT DATA PER BULAN DARI SERVER =================
// Data cuti dikirim per bulan, jadi setiap pindah bulan (atau ganti filter)
// kita minta ulang ke server. `only` membuat permintaan ringan: hariLiburs
// dan prop lain tidak dikirim ulang. Parameter `bulan` ada di URL, jadi
// refresh atau berbagi tautan tetap membuka bulan yang sama.
// 'disetujuiBulanIni' ikut di `only` supaya angka di kartu ikut diperbarui
// saat pindah bulan.
const muatKalender = (targetDate) => {
    router.get(
        window.location.pathname,
        {
            bulan: formatBulan(targetDate),
            filter:
                selectedFilter.value === filterAllLabel.value
                    ? undefined
                    : selectedFilter.value,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ["cutiTim", "bulan", "disetujuiBulanIni"],
            onStart: () => (memuat.value = true),
            onFinish: () => (memuat.value = false),
        },
    );
};

const gantiBulan = (selisih) => {
    const target = new Date(
        currentDate.value.getFullYear(),
        currentDate.value.getMonth() + selisih,
        1,
    );
    currentDate.value = target; // judul bulan langsung berubah
    muatKalender(target);
};
const prevMonth = () => gantiBulan(-1);
const nextMonth = () => gantiBulan(1);
const keBulanIni = () => {
    const target = new Date(new Date().getFullYear(), new Date().getMonth(), 1);
    currentDate.value = target;
    muatKalender(target);
};

// Dropdown filter ikut membawa bulan yang sedang dilihat
const applyFilter = () => muatKalender(currentDate.value);
// ================= END MUAT DATA PER BULAN =================

// ================================================================
// DROPDOWN FILTER KUSTOM (Tim Kerja / Kelompok)
// BARU: menggantikan <select> bawaan browser (yang daftarnya melebar
// melewati layar & tampilannya tidak bisa diatur). Gaya disamakan dengan
// dropdown filter di Riwayat Pengajuan: tombol berikon + panah, daftar
// SELALU terbuka ke bawah dengan lebar SAMA dengan tombolnya, tinggi
// dibatasi (bisa di-scroll), teks panjang membungkus (tidak terpotong),
// opsi terpilih berwarna hijau + tanda centang. Menutup saat klik di luar
// (elemen tanpa atribut data-dropdown) atau menekan Esc.
// Nilai tetap disimpan di `selectedFilter` dan tetap memanggil
// applyFilter(), sehingga logika filter & parameter URL TIDAK berubah.
// ================================================================
const dropdownTerbuka = ref(false);

const toggleDropdown = () => {
    dropdownTerbuka.value = !dropdownTerbuka.value;
};
const tutupDropdown = () => {
    dropdownTerbuka.value = false;
};

// Opsi bernilai "-" (pegawai yang tidak punya tim/kelompok) ditampilkan
// sebagai teks yang jelas. Hanya LABEL yang diganti; nilai asli ("-") tetap
// dipakai untuk mencocokkan filter & parameter URL, jadi logika tidak berubah.
const labelOpsi = (opsi) => {
    const kosong = !opsi || String(opsi).trim() === "-";
    if (!kosong) return opsi;
    return filterMode.value === "l2" ? "Tanpa Tim Kerja" : "Tanpa Kelompok";
};

const opsiFilterLengkap = computed(() => [
    filterAllLabel.value,
    ...filterOptions.value,
]);

const pilihFilter = (opsi) => {
    const berubah = selectedFilter.value !== opsi;
    selectedFilter.value = opsi;
    tutupDropdown();
    if (berubah) applyFilter();
};

const tutupDropdownSaatKlikLuar = (event) => {
    if (!event.target.closest?.("[data-dropdown]")) {
        dropdownTerbuka.value = false;
    }
};
// ================= END DROPDOWN FILTER KUSTOM =================

// DAFTAR HARI LIBUR DINAMIS DIAMBIL DARI DATABASE ADMIN HR
const holidayList = computed(() => {
    let holidays = {};
    if (props.hariLiburs && props.hariLiburs.length > 0) {
        props.hariLiburs.forEach((item) => {
            holidays[item.tanggal] = item.keterangan;
        });
    }
    return holidays;
});

const calendarDays = computed(() => {
    const year = currentDate.value.getFullYear();
    const month = currentDate.value.getMonth();
    // index 0 = Sunday, 1 = Monday, dll. Senin jadi awal minggu
    let firstDayIndex = new Date(year, month, 1).getDay();
    firstDayIndex = firstDayIndex === 0 ? 6 : firstDayIndex - 1;
    const totalDays = new Date(year, month + 1, 0).getDate();
    let days = [];
    for (let i = 0; i < firstDayIndex; i++) {
        days.push({ dateString: null, dayNum: null, isCurrentMonth: false });
    }
    for (let i = 1; i <= totalDays; i++) {
        const formattedMonth = String(month + 1).padStart(2, "0");
        const formattedDay = String(i).padStart(2, "0");
        const dateStr = `${year}-${formattedMonth}-${formattedDay}`;
        days.push({
            dateString: dateStr,
            dayNum: i,
            isCurrentMonth: true,
        });
    }
    return days;
});

const getHolidayName = (dateStr) => {
    return holidayList.value[dateStr] || null;
};

// ================= PENANDA "(ANDA)" & FILTER STATUS =================
// Entri milik user yang sedang login ditandai "(Anda)".
//
// Aturan tampil di kalender:
//   - disetujui                                        -> tampil untuk semua orang
//   - menunggu_l1..l4 / ditolak / dibatalkan / dll.    -> TIDAK tampil
// Catatan: filter ini lapisan kedua. Pembatasan utama ada di
// CutiController::teamCalendar(), yang juga hanya mengirim pengajuan
// berstatus 'disetujui'. Cuti yang sudah lewat TETAP tampil (dibedakan
// secara visual lewat isLampau()).
const isMilikSaya = (item) => {
    if (!props.userPegawaiId) return false;
    const idPegawai = item?.pegawai_id ?? item?.pegawai?.id;
    return Number(idPegawai) === Number(props.userPegawaiId);
};
// Data lama tanpa field status dianggap sudah disetujui (perilaku sebelumnya).
const isDisetujui = (item) => !item?.status || item.status === "disetujui";
const bolehTampil = (item) => isDisetujui(item);
// ================= END PENANDA =================

// Apakah filter dropdown sedang aktif memilih satu tim/kelompok tertentu.
const filterAktif = computed(
    () =>
        showFilterDropdown.value &&
        selectedFilter.value !== filterAllLabel.value &&
        selectedFilter.value !== "",
);

// Filter lokal (fallback) sesuai mode filter yang aktif.
const cocokDenganFilter = (item) => {
    if (!filterAktif.value) return true;
    const nilaiPegawai =
        item.pegawai?.[filterFieldName.value] ??
        (filterFieldName.value === "kelompok_substansi"
            ? props.kelompok_substansi
            : null);
    return nilaiPegawai === selectedFilter.value;
};

// Filter cuti: menyaring status yang boleh tampil, lalu filter lokal
// (fallback) sesuai mode filter yang aktif.
const getCutiOnDate = (dateStr) => {
    if (!dateStr) return [];
    let filteredCuti = (props.cutiTim || []).filter(bolehTampil);
    if (filterAktif.value) {
        filteredCuti = filteredCuti.filter(cocokDenganFilter);
    }
    return filteredCuti.filter((item) => {
        return dateStr >= item.tanggal_mulai && dateStr <= item.tanggal_selesai;
    });
};

// ================= OPSI D: RINGKAS DAFTAR NAMA DI GRID KALENDER (DESKTOP) =================
// Entri milik sendiri diurutkan paling atas supaya tidak pernah tersembunyi
// di balik badge "+X lainnya".
const MAX_NAMA_DI_GRID = 3;
const getCutiRingkasDiGrid = (dateStr) => {
    return [...getCutiOnDate(dateStr)]
        .sort((a, b) => Number(isMilikSaya(b)) - Number(isMilikSaya(a)))
        .slice(0, MAX_NAMA_DI_GRID);
};
const getSisaCutiDiGrid = (dateStr) => {
    const total = getCutiOnDate(dateStr).length;
    return total > MAX_NAMA_DI_GRID ? total - MAX_NAMA_DI_GRID : 0;
};
// ================= END OPSI D =================

// ================= INDIKATOR TITIK (HP) =================
// Di layar kecil nama tidak muat di dalam sel, jadi cukup titik berwarna
// per jenis cuti. Detail lengkap tampil di pop up saat tanggal diketuk.
const MAX_TITIK_DI_GRID = 4;
const getCutiTitikDiGrid = (dateStr) => {
    return [...getCutiOnDate(dateStr)]
        .sort((a, b) => Number(isMilikSaya(b)) - Number(isMilikSaya(a)))
        .slice(0, MAX_TITIK_DI_GRID);
};
const getSisaTitikDiGrid = (dateStr) => {
    const total = getCutiOnDate(dateStr).length;
    return total > MAX_TITIK_DI_GRID ? total - MAX_TITIK_DI_GRID : 0;
};
// ================= END INDIKATOR TITIK =================

const getJenisCuti = (cuti) => (cuti?.jenis_cuti || "Cuti Tahunan").trim();
const getCutiColor = (cuti) => {
    switch (getJenisCuti(cuti).toLowerCase()) {
        case "cuti melahirkan":
            return {
                container: "bg-rose-50 border-rose-200 text-rose-800",
                dot: "bg-rose-500",
                label: "text-rose-400",
                heading: "text-rose-600",
            };
        case "cuti besar":
            return {
                container: "bg-blue-50 border-blue-200 text-blue-800",
                dot: "bg-blue-500",
                label: "text-blue-400",
                heading: "text-blue-600",
            };
        case "cuti alasan penting":
        case "cuti_alasan_penting":
            return {
                container: "bg-amber-50 border-amber-200 text-amber-800",
                dot: "bg-amber-500",
                label: "text-amber-400",
                heading: "text-amber-600",
            };
        default:
            return {
                container: "bg-green-50 border-green-200 text-green-800",
                dot: "bg-green-500",
                label: "text-green-400",
                heading: "text-green-600",
            };
    }
};

// Kelas tambahan untuk chip di grid kalender:
//  - milik sendiri : ring hijau tegas supaya cepat ditemukan
const getChipExtraClass = (cuti) => [
    isMilikSaya(cuti) ? "ring-2 ring-green-500 ring-offset-1" : "",
];

// ================= FORMAT TANGGAL & DURASI (UNTUK POP UP) =================
const parseTanggal = (dateStr) => {
    if (!dateStr) return null;
    const [y, m, d] = String(dateStr).slice(0, 10).split("-").map(Number);
    if (!y || !m || !d) return null;
    return new Date(y, m - 1, d);
};
const formatTanggalPanjang = (dateStr) => {
    const tgl = parseTanggal(dateStr);
    if (!tgl) return dateStr || "-";
    return new Intl.DateTimeFormat("id-ID", {
        weekday: "long",
        day: "numeric",
        month: "long",
        year: "numeric",
    }).format(tgl);
};
const formatTanggalPendek = (dateStr, denganTahun = true) => {
    const tgl = parseTanggal(dateStr);
    if (!tgl) return dateStr || "-";
    return new Intl.DateTimeFormat("id-ID", {
        day: "numeric",
        month: "short",
        ...(denganTahun ? { year: "numeric" } : {}),
    }).format(tgl);
};
const formatRentang = (item) => {
    const mulai = item?.tanggal_mulai;
    const selesai = item?.tanggal_selesai;
    if (!mulai) return "-";
    if (!selesai || mulai === selesai) return formatTanggalPendek(mulai);
    const sama = String(mulai).slice(0, 4) === String(selesai).slice(0, 4);
    return `${formatTanggalPendek(mulai, !sama)} – ${formatTanggalPendek(selesai)}`;
};
const getDurasiHari = (item) => {
    if (item?.jumlah_hari && Number(item.jumlah_hari) > 0) {
        return Number(item.jumlah_hari);
    }
    const a = parseTanggal(item?.tanggal_mulai);
    const b = parseTanggal(item?.tanggal_selesai);
    if (!a || !b) return 1;
    const selisih = Math.round((b - a) / (1000 * 60 * 60 * 24)) + 1;
    return selisih > 0 ? selisih : 1;
};
const getInisial = (nama) => {
    const kata = String(nama || "?")
        .split(",")[0]
        .trim()
        .split(/\s+/)
        .filter((k) => k && !/^(dr|ir|prof|drs|dra|h|hj)\.?$/i.test(k));
    return kata
        .slice(0, 2)
        .map((k) => k.charAt(0).toUpperCase())
        .join("");
};
// ================= END FORMAT TANGGAL & DURASI =================

// MODAL DATA
const selectedDateData = ref(null);

// ================= OPSI A: SEARCH NAMA DI DALAM MODAL =================
const searchModal = ref("");
const searchInputRef = ref(null);
const filteredModalList = computed(() => {
    if (!selectedDateData.value) return [];
    const keyword = searchModal.value.trim().toLowerCase();
    if (!keyword) return selectedDateData.value.list;
    return selectedDateData.value.list.filter((item) =>
        (item.pegawai?.nama || "").toLowerCase().includes(keyword),
    );
});
// ================= END OPSI A =================

// ================= OPSI B: GROUPING PER JENIS CUTI =================
const URUTAN_JENIS_CUTI = [
    "Cuti Tahunan",
    "Cuti Melahirkan",
    "Cuti Besar",
    "Cuti Alasan Penting",
];
const groupedModalList = computed(() => {
    const groups = {};
    filteredModalList.value.forEach((item) => {
        const jenis = getJenisCuti(item);
        if (!groups[jenis]) {
            groups[jenis] = [];
        }
        groups[jenis].push(item);
    });
    const urutanTerpakai = [
        ...URUTAN_JENIS_CUTI,
        ...Object.keys(groups).filter((j) => !URUTAN_JENIS_CUTI.includes(j)),
    ];
    return urutanTerpakai
        .filter((jenis) => groups[jenis] && groups[jenis].length > 0)
        .map((jenis) => ({
            jenis,
            items: groups[jenis],
            color: getCutiColor({ jenis_cuti: jenis }),
        }));
});
// ================= END OPSI B =================

const openModal = (day) => {
    if (!day.isCurrentMonth) return;
    const listCuti = getCutiOnDate(day.dateString);
    const holidayName = getHolidayName(day.dateString);
    if (listCuti.length > 0 || holidayName) {
        searchModal.value = "";
        selectedDateData.value = {
            date: day.dateString,
            holiday: holidayName,
            // Milik sendiri paling atas, sisanya urut nama
            list: [...listCuti].sort((a, b) => {
                const selisihMilik =
                    Number(isMilikSaya(b)) - Number(isMilikSaya(a));
                if (selisihMilik !== 0) return selisihMilik;
                return (a.pegawai?.nama || "").localeCompare(
                    b.pegawai?.nama || "",
                    "id",
                );
            }),
        };
        // Fokus otomatis hanya di layar lebar, supaya keyboard HP
        // tidak langsung menutupi daftar.
        nextTick(() => {
            if (
                typeof window !== "undefined" &&
                window.matchMedia("(min-width: 640px)").matches
            ) {
                searchInputRef.value?.focus();
            }
        });
    }
};
const closeModal = () => {
    selectedDateData.value = null;
    searchModal.value = "";
};
const tanggalModalPanjang = computed(() =>
    formatTanggalPanjang(selectedDateData.value?.date),
);

// Tekan Esc untuk menutup dropdown filter, lalu pop up
const onKeydown = (e) => {
    if (e.key !== "Escape") return;
    if (dropdownTerbuka.value) {
        tutupDropdown();
        return;
    }
    if (selectedDateData.value) closeModal();
};
onMounted(() => {
    window.addEventListener("keydown", onKeydown);
    document.addEventListener("click", tutupDropdownSaatKlikLuar);
});
onBeforeUnmount(() => {
    window.removeEventListener("keydown", onKeydown);
    document.removeEventListener("click", tutupDropdownSaatKlikLuar);
});
</script>

<template>
    <Head title="Jadwal Cuti Tim" />
    <MainLayout>
        <div class="max-w-7xl mx-auto space-y-4 sm:space-y-6 pb-12">
            <!-- HEADER SECTION -->
            <div
                class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 sm:gap-5"
            >
                <div>
                    <h1
                        class="text-2xl md:text-3xl font-bold text-slate-800 tracking-tight"
                    >
                        Jadwal Cuti Tim
                    </h1>
                    <p class="text-slate-500 mt-1 text-sm">
                        Pantau kalender kehadiran dan jadwal cuti anggota tim
                        Anda.
                    </p>
                    <!-- [DIHAPUS] Badge info khusus L1 ("Menampilkan tim Anda: ...").
                         Digantikan oleh dropdown filter "Semua Tim Kerja" yang
                         sekarang juga tampil untuk L1. -->
                </div>

                <!-- TOOLBAR AKSI (Filter & Navigasi Bulan) -->
                <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
                    <!-- ================= DROPDOWN FILTER KUSTOM =================
                         Di HP: selebar layar. Di layar >= sm: lebar tetap 18rem.
                         Daftar pilihan selalu selebar tombolnya, teks panjang
                         membungkus ke baris berikutnya. -->
                    <div
                        v-if="showFilterDropdown"
                        data-dropdown
                        class="relative w-full sm:w-72"
                    >
                        <div
                            class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"
                        >
                            <svg
                                class="h-4 w-4 text-slate-400"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"
                                ></path>
                            </svg>
                        </div>

                        <button
                            id="filter-kalender-tim"
                            type="button"
                            aria-label="Filter tim atau kelompok pada kalender"
                            aria-haspopup="listbox"
                            :aria-expanded="dropdownTerbuka"
                            :title="labelOpsi(selectedFilter)"
                            @click="toggleDropdown"
                            class="w-full min-h-[44px] text-left text-sm font-medium bg-white border rounded-xl shadow-sm text-slate-700 py-2.5 pl-9 pr-10 cursor-pointer hover:border-slate-300 focus:outline-none focus:border-green-500 focus:ring-1 focus:ring-green-500 transition-colors"
                            :class="
                                dropdownTerbuka
                                    ? 'border-green-500 ring-1 ring-green-500'
                                    : 'border-slate-200'
                            "
                        >
                            <span class="block truncate">{{
                                labelOpsi(selectedFilter)
                            }}</span>
                        </button>

                        <span
                            class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none"
                        >
                            <svg
                                class="h-4 w-4 text-slate-400 transition-transform"
                                :class="{ 'rotate-180': dropdownTerbuka }"
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
                            v-if="dropdownTerbuka"
                            role="listbox"
                            class="absolute left-0 right-0 top-full z-30 mt-1 max-h-64 overflow-y-auto rounded-xl border border-slate-200 bg-white py-1 shadow-lg"
                        >
                            <li v-for="opsi in opsiFilterLengkap" :key="opsi">
                                <button
                                    type="button"
                                    role="option"
                                    :title="labelOpsi(opsi)"
                                    :aria-selected="selectedFilter === opsi"
                                    @click="pilihFilter(opsi)"
                                    class="flex w-full items-start justify-between gap-2 whitespace-normal break-words leading-snug px-3.5 py-2.5 text-left text-sm cursor-pointer transition-colors"
                                    :class="
                                        selectedFilter === opsi
                                            ? 'bg-green-50 text-green-700 font-semibold'
                                            : 'text-slate-700 hover:bg-slate-50'
                                    "
                                >
                                    <span class="min-w-0">{{
                                        labelOpsi(opsi)
                                    }}</span>
                                    <svg
                                        v-if="selectedFilter === opsi"
                                        class="w-4 h-4 shrink-0 mt-0.5 text-green-600"
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2.5"
                                            d="M5 13l4 4L19 7"
                                        ></path>
                                    </svg>
                                </button>
                            </li>
                        </ul>
                    </div>
                    <!-- ================= END DROPDOWN FILTER KUSTOM ================= -->

                    <!-- Navigasi Bulan -->
                    <div
                        class="flex items-center w-full sm:w-auto justify-between sm:justify-center gap-3 bg-white px-3 py-2 rounded-xl shadow-sm border border-slate-200"
                    >
                        <button
                            type="button"
                            @click.prevent="prevMonth"
                            class="p-1.5 hover:bg-slate-100 rounded-lg text-slate-600 transition cursor-pointer"
                            aria-label="Bulan sebelumnya"
                        >
                            <svg
                                class="w-5 h-5"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M15 19l-7-7 7-7"
                                ></path>
                            </svg>
                        </button>
                        <span
                            class="font-bold text-slate-800 text-sm w-32 text-center"
                            >{{ bulanAktif }} {{ tahunAktif }}</span
                        >
                        <button
                            type="button"
                            @click.prevent="nextMonth"
                            class="p-1.5 hover:bg-slate-100 rounded-lg text-slate-600 transition cursor-pointer"
                            aria-label="Bulan berikutnya"
                        >
                            <svg
                                class="w-5 h-5"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M9 5l7 7-7 7"
                                ></path>
                            </svg>
                        </button>
                        <!-- Kembali ke bulan berjalan -->
                        <button
                            type="button"
                            @click.prevent="keBulanIni"
                            class="whitespace-nowrap text-xs font-semibold text-green-700 hover:bg-green-50 px-2 py-1 rounded-lg transition cursor-pointer"
                        >
                            Hari ini
                        </button>
                    </div>
                </div>
            </div>

            <!-- GRID KALENDER -->
            <div
                class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden"
            >
                <!-- Nama hari: singkat di HP, lengkap di layar >= sm -->
                <div
                    class="grid grid-cols-7 bg-slate-50 border-b border-slate-200 text-center text-[10px] sm:text-xs font-bold text-slate-600 py-2 sm:py-3 uppercase tracking-normal sm:tracking-wider"
                >
                    <span>
                        <span class="sm:hidden">Sen</span
                        ><span class="hidden sm:inline">Senin</span>
                    </span>
                    <span>
                        <span class="sm:hidden">Sel</span
                        ><span class="hidden sm:inline">Selasa</span>
                    </span>
                    <span>
                        <span class="sm:hidden">Rab</span
                        ><span class="hidden sm:inline">Rabu</span>
                    </span>
                    <span>
                        <span class="sm:hidden">Kam</span
                        ><span class="hidden sm:inline">Kamis</span>
                    </span>
                    <span>
                        <span class="sm:hidden">Jum</span
                        ><span class="hidden sm:inline">Jumat</span>
                    </span>
                    <span class="text-red-500">
                        <span class="sm:hidden">Sab</span
                        ><span class="hidden sm:inline">Sabtu</span>
                    </span>
                    <span class="text-red-500">
                        <span class="sm:hidden">Min</span
                        ><span class="hidden sm:inline">Minggu</span>
                    </span>
                </div>
                <div
                    class="grid grid-cols-7 auto-rows-fr bg-slate-200 gap-px transition-opacity"
                    :class="{ 'opacity-60': memuat }"
                    :aria-busy="memuat"
                >
                    <div
                        v-for="(day, index) in calendarDays"
                        :key="index"
                        class="bg-white min-h-[60px] sm:min-h-[100px] lg:min-h-[120px] p-1 sm:p-2 flex flex-col justify-between transition"
                        :class="{
                            'bg-slate-50/50 text-slate-300':
                                !day.isCurrentMonth,
                            'cursor-pointer hover:bg-slate-50 active:bg-slate-100':
                                day.isCurrentMonth &&
                                (getCutiOnDate(day.dateString).length > 0 ||
                                    getHolidayName(day.dateString)),
                        }"
                        @click="openModal(day)"
                    >
                        <div class="flex flex-col gap-1">
                            <div
                                class="flex justify-between items-center"
                                v-if="day.dayNum"
                            >
                                <span
                                    class="text-[11px] sm:text-xs font-semibold px-1.5 sm:px-2 py-0.5 rounded-md"
                                    :class="{
                                        'text-slate-700':
                                            day.isCurrentMonth &&
                                            !getHolidayName(day.dateString),
                                        'bg-red-500 text-white font-bold shadow-sm':
                                            getHolidayName(day.dateString),
                                        'ring-2 ring-green-500 ring-offset-1':
                                            isHariIni(day.dateString),
                                    }"
                                >
                                    {{ day.dayNum }}
                                </span>
                                <span
                                    v-if="
                                        day.isCurrentMonth &&
                                        getCutiOnDate(day.dateString).length > 0
                                    "
                                    class="hidden sm:inline-block text-[10px] font-bold px-1.5 py-0.5 rounded-full"
                                    :class="
                                        isLampau(day.dateString)
                                            ? 'bg-slate-100 text-slate-400'
                                            : 'bg-green-100 text-green-700'
                                    "
                                >
                                    {{ getCutiOnDate(day.dateString).length }}
                                    cuti
                                </span>
                            </div>
                            <!-- Label hari libur: disembunyikan di HP
                                 (angka merah sudah menandakan libur,
                                 nama libur ada di pop up) -->
                            <div
                                v-if="getHolidayName(day.dateString)"
                                class="hidden sm:block text-[10px] bg-red-50 text-red-600 border border-red-100 font-semibold px-1.5 py-0.5 rounded mt-1 truncate"
                                :title="getHolidayName(day.dateString)"
                            >
                                🎉 {{ getHolidayName(day.dateString) }}
                            </div>
                        </div>

                        <!-- INDIKATOR TITIK (HP & tablet): detail ada di pop up -->
                        <div
                            v-if="
                                day.isCurrentMonth &&
                                getCutiOnDate(day.dateString).length > 0
                            "
                            class="lg:hidden flex flex-wrap items-center gap-0.5 sm:gap-1 mt-1 px-0.5"
                            :class="
                                isLampau(day.dateString) ? 'opacity-50' : ''
                            "
                        >
                            <span
                                v-for="cuti in getCutiTitikDiGrid(
                                    day.dateString,
                                )"
                                :key="cuti.id"
                                class="w-2 h-2 rounded-full shrink-0"
                                :class="[
                                    getCutiColor(cuti).dot,
                                    isMilikSaya(cuti)
                                        ? 'ring-2 ring-green-600 ring-offset-1'
                                        : '',
                                ]"
                            ></span>
                            <span
                                v-if="getSisaTitikDiGrid(day.dateString) > 0"
                                class="text-[9px] font-bold text-slate-500 leading-none"
                                >+{{ getSisaTitikDiGrid(day.dateString) }}</span
                            >
                        </div>

                        <!-- OPSI D (DESKTOP): hanya beberapa nama teratas, sisanya
                             "+X lainnya". Entri milik sendiri selalu di atas,
                             bertanda "(Anda)" + ring hijau. Tanggal yang sudah
                             lewat dipudarkan. -->
                        <div
                            class="hidden lg:block space-y-1 mt-1"
                            :class="
                                isLampau(day.dateString)
                                    ? 'opacity-50 grayscale-[40%]'
                                    : ''
                            "
                            v-if="day.isCurrentMonth"
                        >
                            <template
                                v-for="cuti in getCutiRingkasDiGrid(
                                    day.dateString,
                                )"
                                :key="cuti.id"
                            >
                                <div
                                    class="border text-[11px] font-medium px-2 py-1 rounded-md truncate shadow-sm flex items-center gap-1"
                                    :class="[
                                        getCutiColor(cuti).container,
                                        ...getChipExtraClass(cuti),
                                    ]"
                                    :title="`${cuti.pegawai?.nama || 'Pegawai'}${isMilikSaya(cuti) ? ' (Anda)' : ''} - ${getJenisCuti(cuti)}`"
                                >
                                    <span
                                        class="w-1.5 h-1.5 rounded-full shrink-0"
                                        :class="getCutiColor(cuti).dot"
                                    ></span>
                                    <span class="truncate">
                                        {{ cuti.pegawai?.nama || "Pegawai" }}
                                        <span
                                            v-if="isMilikSaya(cuti)"
                                            class="font-bold"
                                            >(Anda)</span
                                        >
                                        <span class="font-bold"
                                            >({{ getJenisCuti(cuti) }})</span
                                        >
                                    </span>
                                </div>
                            </template>
                            <button
                                type="button"
                                v-if="getSisaCutiDiGrid(day.dateString) > 0"
                                @click.stop="openModal(day)"
                                class="w-full text-[11px] font-semibold text-slate-500 hover:text-slate-700 bg-slate-50 hover:bg-slate-100 px-2 py-1 rounded-md transition cursor-pointer"
                            >
                                +{{ getSisaCutiDiGrid(day.dateString) }} lainnya
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Petunjuk untuk HP -->
            <p class="lg:hidden text-center text-xs text-slate-400 -mt-1">
                Ketuk tanggal bertanda titik untuk melihat siapa saja yang cuti.
            </p>

            <div
                class="flex flex-wrap items-center justify-center gap-x-4 sm:gap-x-5 gap-y-2 text-[11px] font-semibold text-slate-600"
            >
                <div class="flex items-center gap-1.5">
                    <span class="w-2.5 h-2.5 rounded-sm bg-green-500"></span>
                    Cuti Tahunan
                </div>
                <div class="flex items-center gap-1.5">
                    <span class="w-2.5 h-2.5 rounded-sm bg-rose-500"></span>
                    Cuti Melahirkan
                </div>
                <div class="flex items-center gap-1.5">
                    <span class="w-2.5 h-2.5 rounded-sm bg-blue-500"></span>
                    Cuti Besar
                </div>
                <div class="flex items-center gap-1.5">
                    <span class="w-2.5 h-2.5 rounded-sm bg-amber-500"></span>
                    Cuti Alasan Penting
                </div>
                <!-- Keterangan penanda cuti milik sendiri -->
                <div class="flex items-center gap-1.5">
                    <span
                        class="w-4 h-2.5 rounded-sm bg-white ring-2 ring-green-500"
                    ></span>
                    Cuti Anda
                </div>
                <!-- Keterangan tanggal yang sudah lewat -->
                <div class="flex items-center gap-1.5">
                    <span
                        class="w-4 h-2.5 rounded-sm bg-slate-300 opacity-60"
                    ></span>
                    Sudah lewat
                </div>
            </div>
        </div>
    </MainLayout>

    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-150 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-100 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <!-- HP: tampil sebagai bottom sheet. Layar >= sm: modal di tengah. -->
            <div
                v-if="selectedDateData"
                class="fixed inset-0 z-[9999] flex items-end sm:items-center justify-center bg-slate-900/50 backdrop-blur-sm p-0 sm:p-4"
                @click.self="closeModal"
            >
                <div
                    class="bg-white w-full sm:max-w-md rounded-t-2xl sm:rounded-2xl shadow-2xl ring-1 ring-slate-900/5 overflow-hidden flex flex-col max-h-[85vh]"
                    role="dialog"
                    aria-modal="true"
                >
                    <!-- Header -->
                    <div
                        class="shrink-0 flex items-start justify-between gap-3 px-5 pt-4 pb-3 border-b border-slate-100"
                    >
                        <div class="min-w-0">
                            <p
                                class="text-[10px] font-bold uppercase tracking-wider text-slate-400"
                            >
                                Informasi Jadwal
                            </p>
                            <h3
                                class="text-base font-bold text-slate-800 leading-tight mt-0.5"
                            >
                                {{ tanggalModalPanjang }}
                            </h3>
                            <p
                                v-if="selectedDateData.list.length > 0"
                                class="text-xs text-slate-500 mt-0.5"
                            >
                                {{ selectedDateData.list.length }} pegawai cuti
                            </p>
                            <!-- Status tanggal: riwayat / hari ini -->
                            <p
                                v-if="isLampau(selectedDateData.date)"
                                class="text-[11px] font-semibold text-slate-400 mt-0.5"
                            >
                                Tanggal ini sudah lewat (riwayat)
                            </p>
                            <p
                                v-else-if="isHariIni(selectedDateData.date)"
                                class="text-[11px] font-semibold text-green-600 mt-0.5"
                            >
                                Hari ini
                            </p>
                        </div>
                        <button
                            type="button"
                            @click.prevent="closeModal"
                            class="shrink-0 text-slate-400 hover:text-slate-700 hover:bg-slate-100 p-1.5 rounded-lg cursor-pointer transition"
                            title="Tutup (Esc)"
                            aria-label="Tutup"
                        >
                            <svg
                                class="w-5 h-5"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M6 18L18 6M6 6l12 12"
                                ></path>
                            </svg>
                        </button>
                    </div>

                    <!-- OPSI A: KOTAK PENCARIAN NAMA (hanya jika >5 orang) -->
                    <div
                        v-if="selectedDateData.list.length > 5"
                        class="shrink-0 px-5 pt-3"
                    >
                        <div class="relative">
                            <div
                                class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"
                            >
                                <svg
                                    class="h-4 w-4 text-slate-400"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"
                                    ></path>
                                </svg>
                            </div>
                            <input
                                ref="searchInputRef"
                                v-model="searchModal"
                                type="text"
                                name="cari_pegawai"
                                id="cari_pegawai"
                                aria-label="Cari nama pegawai"
                                placeholder="Cari nama pegawai..."
                                class="w-full pl-9 pr-3 py-2 text-base sm:text-sm bg-slate-50 border border-slate-200 rounded-lg focus:outline-none focus:border-green-500 focus:ring-1 focus:ring-green-500 transition-colors"
                            />
                        </div>
                    </div>

                    <!-- Isi -->
                    <div class="flex-1 overflow-y-auto px-5 py-3">
                        <!-- Alert Hari Libur -->
                        <div
                            v-if="selectedDateData.holiday"
                            class="flex items-center gap-2 px-3 py-2 mb-2 rounded-lg bg-red-50 border border-red-100 text-red-700"
                        >
                            <svg
                                class="w-4 h-4 text-red-500 shrink-0"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
                                ></path>
                            </svg>
                            <p class="text-xs leading-snug">
                                <span class="font-bold"
                                    >Hari Libur Nasional</span
                                >
                                <span class="text-red-600">
                                    · {{ selectedDateData.holiday }}</span
                                >
                            </p>
                        </div>

                        <!-- Pesan kalau hasil pencarian kosong -->
                        <p
                            v-if="
                                selectedDateData.list.length > 0 &&
                                groupedModalList.length === 0
                            "
                            class="text-center text-sm text-slate-400 py-6"
                        >
                            Tidak ada pegawai dengan nama tersebut.
                        </p>

                        <!-- OPSI B: DAFTAR PEGAWAI CUTI PER JENIS CUTI -->
                        <div
                            v-if="groupedModalList.length > 0"
                            class="space-y-4"
                        >
                            <div
                                v-for="grup in groupedModalList"
                                :key="grup.jenis"
                            >
                                <p
                                    class="text-[11px] font-bold uppercase tracking-wider mb-1.5"
                                    :class="grup.color.heading"
                                >
                                    {{ grup.jenis }} ({{ grup.items.length }})
                                </p>
                                <ul class="divide-y divide-slate-100">
                                    <li
                                        v-for="item in grup.items"
                                        :key="item.id"
                                        class="flex gap-3 py-3 first:pt-1 last:pb-1"
                                    >
                                        <!-- Avatar: foto_profil jika ada, fallback ke inisial -->
                                        <div
                                            class="w-9 h-9 rounded-full text-white text-xs font-bold flex items-center justify-center shrink-0 overflow-hidden"
                                            :class="[
                                                getCutiColor(item).dot,
                                                isMilikSaya(item)
                                                    ? 'ring-2 ring-green-500 ring-offset-2'
                                                    : '',
                                            ]"
                                        >
                                            <img
                                                v-if="item.pegawai?.foto_profil"
                                                :src="`/storage/${item.pegawai.foto_profil}`"
                                                :alt="
                                                    item.pegawai?.nama ||
                                                    'Pegawai'
                                                "
                                                class="w-full h-full object-cover"
                                            />
                                            <span v-else>{{
                                                getInisial(item.pegawai?.nama)
                                            }}</span>
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <div
                                                class="flex items-center justify-between gap-2"
                                            >
                                                <p
                                                    class="font-semibold text-slate-800 text-sm truncate"
                                                    :title="item.pegawai?.nama"
                                                >
                                                    {{
                                                        item.pegawai?.nama ||
                                                        "Pegawai"
                                                    }}
                                                    <span
                                                        v-if="isMilikSaya(item)"
                                                        class="text-green-600 font-bold"
                                                        >(Anda)</span
                                                    >
                                                </p>
                                            </div>
                                            <div
                                                class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1"
                                            >
                                                <span
                                                    class="text-xs text-slate-500"
                                                >
                                                    {{ formatRentang(item) }}
                                                    <span class="text-slate-300"
                                                        >·</span
                                                    >
                                                    <span
                                                        class="font-semibold text-slate-700"
                                                        >{{
                                                            getDurasiHari(item)
                                                        }}
                                                        Hari</span
                                                    >
                                                </span>
                                            </div>
                                        </div>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <!-- Footer tipis -->
                    <div
                        class="shrink-0 flex justify-end px-5 py-2.5 bg-slate-50 border-t border-slate-100"
                    >
                        <button
                            type="button"
                            @click.prevent="closeModal"
                            class="w-full sm:w-auto px-4 py-2 sm:py-1.5 bg-slate-800 text-white rounded-lg text-sm font-semibold hover:bg-slate-900 transition cursor-pointer shadow-sm"
                        >
                            Tutup
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>