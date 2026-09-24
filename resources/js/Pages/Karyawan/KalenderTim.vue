<script setup>
import MainLayout from "@/Layouts/MainLayout.vue";
import { Head, router } from "@inertiajs/vue3";
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from "vue";

const props = defineProps({
    cutiTim: Array,
    kelompok_substansi: String,
    hariLiburs: Array, // Menerima data dari Controller (Database Admin HR)
    listKelompok: Array, // Dipakai untuk filter L3 & L4
    listTimKerja: Array, // Dipakai untuk filter L2
    userRoleId: Number, // 1=Staff, 2=L1, 3=L2, 4=L3, 6=L4
    userTimKerja: String, // Nama tim kerja user (dipakai untuk badge info L1)
});

const currentDate = ref(new Date());
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

// --- FITUR FILTER BERJENJANG SESUAI LEVEL ATASAN ---
// L1 (role_id 2)       : TANPA dropdown filter, otomatis hanya lihat
//                        staff di tim_kerja-nya sendiri. Ditampilkan
//                        badge info "Menampilkan tim Anda: ...".
// L2 (role_id 3)       : filter berdasarkan Tim Kerja (dalam kelompoknya)
// L3 & L4 (role_id 4/6): filter berdasarkan Subbagian/Kelompok (lintas kelompok)
// Staff & lainnya      : tanpa filter (perilaku lama)
const filterMode = computed(() => {
    if (props.userRoleId === 2) return "l1";
    if (props.userRoleId === 3) return "l2";
    if (props.userRoleId === 4 || props.userRoleId === 6) return "l3l4";
    return "none";
});

const showFilterDropdown = computed(
    () => filterMode.value === "l2" || filterMode.value === "l3l4",
);
const showBadgeTimL1 = computed(
    () => filterMode.value === "l1" && !!props.userTimKerja,
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
// Jika tidak ada parameter, defaultnya "Semua Tim Kerja" / "Semua Kelompok"
// tergantung level user.
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

// Fungsi memicu perubahan data ketika dropdown dipilih
const applyFilter = () => {
    router.get(
        window.location.pathname,
        // Jika memilih "Semua ...", parameter dihapus (undefined) dari URL
        // agar bersih
        {
            filter:
                selectedFilter.value === filterAllLabel.value
                    ? undefined
                    : selectedFilter.value,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};
// ----------------------------------------------------

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

const prevMonth = () => {
    currentDate.value = new Date(
        currentDate.value.getFullYear(),
        currentDate.value.getMonth() - 1,
        1,
    );
};
const nextMonth = () => {
    currentDate.value = new Date(
        currentDate.value.getFullYear(),
        currentDate.value.getMonth() + 1,
        1,
    );
};

const calendarDays = computed(() => {
    const year = currentDate.value.getFullYear();
    const month = currentDate.value.getMonth();
    // index 0 = Sunday, 1 = Monday, dll. Kita sesuaikan agar Senin jadi awal minggu (1)
    let firstDayIndex = new Date(year, month, 1).getDay();
    firstDayIndex = firstDayIndex === 0 ? 6 : firstDayIndex - 1; // Konversi ke format Senin=0, Minggu=6
    const totalDays = new Date(year, month + 1, 0).getDate();
    let days = [];
    // Kotak kosong di awal bulan
    for (let i = 0; i < firstDayIndex; i++) {
        days.push({ dateString: null, dayNum: null, isCurrentMonth: false });
    }
    // Tanggal asli di bulan berjalan
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

// Filter cuti diperbarui agar dapat menyaring secara lokal (fallback),
// mengikuti mode filter yang aktif sesuai level user (L1/L2/L3&L4)
const getCutiOnDate = (dateStr) => {
    if (!dateStr) return [];
    let filteredCuti = props.cutiTim || [];
    // Filter lokal (fallback) jika backend mengirimkan seluruh data tanpa
    // difilter terlebih dahulu. Hanya berlaku untuk mode yang punya dropdown
    // (L2 & L3/L4) — L1 dan Staff tidak melakukan filter tambahan di sini
    // karena scope datanya sudah ditentukan oleh backend.
    if (
        showFilterDropdown.value &&
        selectedFilter.value !== filterAllLabel.value &&
        selectedFilter.value !== ""
    ) {
        filteredCuti = filteredCuti.filter((item) => {
            const nilaiPegawai =
                item.pegawai?.[filterFieldName.value] ??
                (filterFieldName.value === "kelompok_substansi"
                    ? props.kelompok_substansi
                    : null);
            return nilaiPegawai === selectedFilter.value;
        });
    }
    return filteredCuti.filter((item) => {
        return dateStr >= item.tanggal_mulai && dateStr <= item.tanggal_selesai;
    });
};

// ================= OPSI D: RINGKAS DAFTAR NAMA DI GRID KALENDER =================
// Supaya kotak tanggal tidak "meluber" saat banyak pegawai cuti dalam satu
// hari, grid hanya menampilkan beberapa nama teratas dan sisanya diringkas
// jadi badge "+X lainnya" yang tetap bisa diklik untuk buka modal detail.
const MAX_NAMA_DI_GRID = 3;
const getCutiRingkasDiGrid = (dateStr) => {
    return getCutiOnDate(dateStr).slice(0, MAX_NAMA_DI_GRID);
};
const getSisaCutiDiGrid = (dateStr) => {
    const total = getCutiOnDate(dateStr).length;
    return total > MAX_NAMA_DI_GRID ? total - MAX_NAMA_DI_GRID : 0;
};
// ================= END OPSI D =================

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
// Membantu ketika satu tanggal punya banyak pegawai cuti — user tinggal
// ketik nama untuk cari, tanpa perlu scroll manual satu-satu.
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
// Mengelompokkan daftar pegawai (hasil filter search di atas) berdasarkan
// jenis cutinya, supaya atasan bisa cepat scan "siapa saja yang Cuti Besar"
// dsb tanpa perlu membaca satu-satu dari daftar campur. Urutan grup
// mengikuti urutan legenda di bawah kalender (Tahunan, Melahirkan, Besar,
// Alasan Penting), bukan alfabetis atau urutan kemunculan data.
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
    // Urutkan sesuai URUTAN_JENIS_CUTI dulu, jenis lain (di luar 4 standar,
    // kalau ada) ditaruh di akhir mengikuti urutan kemunculan.
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
            list: [...listCuti].sort((a, b) =>
                (a.pegawai?.nama || "").localeCompare(
                    b.pegawai?.nama || "",
                    "id",
                ),
            ),
        };
        // Fokus otomatis ke kotak pencarian saat modal terbuka, supaya user
        // bisa langsung ketik tanpa perlu klik dulu (hanya berguna kalau
        // daftarnya cukup panjang, tapi tidak mengganggu kalau sedikit).
        nextTick(() => {
            searchInputRef.value?.focus();
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

// Tekan Esc untuk menutup pop up
const onKeydown = (e) => {
    if (e.key === "Escape" && selectedDateData.value) closeModal();
};
onMounted(() => window.addEventListener("keydown", onKeydown));
onBeforeUnmount(() => window.removeEventListener("keydown", onKeydown));
</script>

<template>
    <Head title="Jadwal Cuti Tim" />
    <MainLayout>
        <div class="max-w-7xl mx-auto space-y-6 pb-12">
            <!-- HEADER SECTION -->
            <div
                class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-5"
            >
                <div>
                    <h1
                        class="text-2xl md:text-3xl font-bold text-slate-800 tracking-tight"
                    >
                        Jadwal Cuti Tim
                    </h1>
                    <p class="text-slate-500 mt-1 text-sm">
                        Pantau kalender kehadiran dan jadwal cuti anggota tim Anda.
                    </p>
                    <!-- BADGE INFO KHUSUS L1: tidak ada dropdown filter untuk
                         level ini, jadi ditampilkan info tim yang sedang
                         dilihat supaya tetap jelas bagi user -->
                    <div
                        v-if="showBadgeTimL1"
                        class="inline-flex items-center gap-1.5 mt-2 px-2.5 py-1 rounded-full bg-green-50 border border-green-200 text-green-700 text-xs font-semibold"
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
                                d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 100-8 4 4 0 000 8zm6 3c0-2.21-3.582-4-8-4s-8 1.79-8 4"
                            ></path>
                        </svg>
                        Menampilkan tim Anda: {{ userTimKerja }}
                    </div>
                </div>

                <!-- TOOLBAR AKSI (Filter & Navigasi Bulan) -->
                <div class="flex flex-col sm:flex-row items-center gap-3">
                    <!-- Filter Dropdown - hanya tampil untuk L2, L3, L4.
                         L1 & Staff tidak butuh dropdown filter. -->
                    <div class="relative w-full sm:w-auto" v-if="showFilterDropdown">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <!-- Ikon Filter/Corong -->
                            <svg class="h-4 w-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"></path>
                            </svg>
                        </div>
                        <select
                            v-model="selectedFilter"
                            @change="applyFilter"
                            class="appearance-none pl-9 pr-10 py-2.5 w-full sm:min-w-[240px] bg-white border border-slate-200 rounded-xl shadow-sm text-sm font-medium text-slate-700 hover:border-slate-300 focus:outline-none focus:border-green-500 focus:ring-1 focus:ring-green-500 transition-colors cursor-pointer"
                        >
                            <option :value="filterAllLabel">{{ filterAllLabel }}</option>
                            <option
                                v-for="opsi in filterOptions"
                                :key="opsi"
                                :value="opsi"
                            >
                                {{ opsi }}
                            </option>
                        </select>
                        <!-- Panah Dropdown Custom agar terlihat konsisten di semua browser -->
                        <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                            <svg class="h-4 w-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </div>
                    </div>

                    <!-- Navigasi Bulan -->
                    <div
                        class="flex items-center w-full sm:w-auto justify-between sm:justify-center gap-3 bg-white px-3 py-2 rounded-xl shadow-sm border border-slate-200"
                    >
                        <button
                            type="button"
                            @click.prevent="prevMonth"
                            class="p-1.5 hover:bg-slate-100 rounded-lg text-slate-600 transition cursor-pointer"
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
                    </div>
                </div>
            </div>

            <!-- GRID KALENDER -->
            <div
                class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden"
            >
                <div
                    class="grid grid-cols-7 bg-slate-50 border-b border-slate-200 text-center text-xs font-bold text-slate-600 py-3 uppercase tracking-wider"
                >
                    <span>Senin</span>
                    <span>Selasa</span>
                    <span>Rabu</span>
                    <span>Kamis</span>
                    <span>Jumat</span>
                    <span class="text-red-500">Sabtu</span>
                    <span class="text-red-500">Minggu</span>
                </div>
                <div class="grid grid-cols-7 auto-rows-fr bg-slate-200 gap-px">
                    <div
                        v-for="(day, index) in calendarDays"
                        :key="index"
                        class="bg-white min-h-[120px] p-2 flex flex-col justify-between transition"
                        :class="{
                            'bg-slate-50/50 text-slate-300':
                                !day.isCurrentMonth,
                            'cursor-pointer hover:bg-slate-50':
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
                                    class="text-xs font-semibold px-2 py-0.5 rounded-md"
                                    :class="{
                                        'text-slate-700':
                                            day.isCurrentMonth &&
                                            !getHolidayName(day.dateString),
                                        'bg-red-500 text-white font-bold shadow-sm':
                                            getHolidayName(day.dateString),
                                    }"
                                >
                                    {{ day.dayNum }}
                                </span>
                                <span
                                    v-if="
                                        day.isCurrentMonth &&
                                        getCutiOnDate(day.dateString).length > 0
                                    "
                                    class="text-[10px] bg-green-100 text-green-700 font-bold px-1.5 py-0.5 rounded-full"
                                >
                                    {{ getCutiOnDate(day.dateString).length }}
                                    cuti
                                </span>
                            </div>
                            <div
                                v-if="getHolidayName(day.dateString)"
                                class="text-[10px] bg-red-50 text-red-600 border border-red-100 font-semibold px-1.5 py-0.5 rounded mt-1 truncate"
                                :title="getHolidayName(day.dateString)"
                            >
                                🎉 {{ getHolidayName(day.dateString) }}
                            </div>
                        </div>

                        <!-- OPSI D: hanya tampilkan beberapa nama teratas
                             (MAX_NAMA_DI_GRID), sisanya diringkas jadi badge
                             "+X lainnya" supaya kotak tidak meluber saat
                             banyak pegawai cuti dalam satu hari -->
                        <div
                            class="space-y-1 mt-1"
                            v-if="day.isCurrentMonth"
                        >
                            <template
                                v-for="cuti in getCutiRingkasDiGrid(day.dateString)"
                                :key="cuti.id"
                            >
                                <div
                                    class="border text-[11px] font-medium px-2 py-1 rounded-md truncate shadow-sm flex items-center gap-1"
                                    :class="getCutiColor(cuti).container"
                                    :title="`${cuti.pegawai?.nama || 'Pegawai'} - ${getJenisCuti(cuti)}`"
                                >
                                    <span
                                        class="w-1.5 h-1.5 rounded-full shrink-0"
                                        :class="getCutiColor(cuti).dot"
                                    ></span>
                                    <span class="truncate">
                                        {{ cuti.pegawai?.nama || "Pegawai" }}
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

            <div
                class="flex flex-wrap items-center justify-center gap-x-5 gap-y-2 text-[11px] font-semibold text-slate-600"
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
            <div
                v-if="selectedDateData"
                class="fixed inset-0 z-[9999] flex items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4"
                @click.self="closeModal"
            >
                <div
                    class="bg-white w-full max-w-md rounded-2xl shadow-2xl ring-1 ring-slate-900/5 overflow-hidden flex flex-col max-h-[85vh]"
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
                                {{ selectedDateData.list.length }} pegawai
                                sedang cuti
                            </p>
                        </div>
                        <button
                            type="button"
                            @click.prevent="closeModal"
                            class="shrink-0 text-slate-400 hover:text-slate-700 hover:bg-slate-100 p-1.5 rounded-lg cursor-pointer transition"
                            title="Tutup (Esc)"
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

                    <!-- OPSI A: KOTAK PENCARIAN NAMA -->
                    <!-- Hanya ditampilkan kalau daftar cukup panjang (>5 orang),
                         supaya tidak mengganggu tampilan saat cuma 1-2 orang -->
                    <div
                        v-if="selectedDateData.list.length > 5"
                        class="shrink-0 px-5 pt-3"
                    >
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <svg class="h-4 w-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"></path>
                                </svg>
                            </div>
                            <input
                                ref="searchInputRef"
                                v-model="searchModal"
                                type="text"
                                placeholder="Cari nama pegawai..."
                                class="w-full pl-9 pr-3 py-2 text-sm bg-slate-50 border border-slate-200 rounded-lg focus:outline-none focus:border-green-500 focus:ring-1 focus:ring-green-500 transition-colors"
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
                            v-if="selectedDateData.list.length > 0 && groupedModalList.length === 0"
                            class="text-center text-sm text-slate-400 py-6"
                        >
                            Tidak ada pegawai dengan nama tersebut.
                        </p>

                        <!-- OPSI B: DAFTAR PEGAWAI CUTI, DIKELOMPOKKAN PER
                             JENIS CUTI. Setiap grup punya sub-header kecil
                             berwarna (sesuai warna badge jenis cutinya) +
                             jumlah orang di grup tsb, supaya atasan bisa
                             cepat scan jenis cuti tertentu tanpa membaca
                             satu-satu dari daftar campur. -->
                        <div v-if="groupedModalList.length > 0" class="space-y-4">
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
                                            :class="getCutiColor(item).dot"
                                        >
                                            <img
                                                v-if="item.pegawai?.foto_profil"
                                                :src="`/storage/${item.pegawai.foto_profil}`"
                                                :alt="item.pegawai?.nama || 'Pegawai'"
                                                class="w-full h-full object-cover"
                                            />
                                            <span v-else>{{ getInisial(item.pegawai?.nama) }}</span>
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <div
                                                class="flex items-center justify-between gap-2"
                                            >
                                                <p
                                                    class="font-semibold text-slate-800 text-sm truncate"
                                                    :title="item.pegawai?.nama"
                                                >
                                                    {{ item.pegawai?.nama || "Pegawai" }}
                                                </p>
                                                <span
                                                    class="shrink-0 inline-flex items-center gap-1 text-[10px] font-semibold text-emerald-700 bg-emerald-50 px-1.5 py-0.5 rounded"
                                                >
                                                    <svg
                                                        class="w-3 h-3"
                                                        fill="none"
                                                        stroke="currentColor"
                                                        viewBox="0 0 24 24"
                                                    >
                                                        <path
                                                            stroke-linecap="round"
                                                            stroke-linejoin="round"
                                                            stroke-width="3"
                                                            d="M5 13l4 4L19 7"
                                                        ></path>
                                                    </svg>
                                                    Disetujui
                                                </span>
                                            </div>
                                            <div
                                                class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1"
                                            >
                                                <span class="text-xs text-slate-500">
                                                    {{ formatRentang(item) }}
                                                    <span class="text-slate-300"
                                                        >·</span
                                                    >
                                                    <span
                                                        class="font-semibold text-slate-700"
                                                        >{{ getDurasiHari(item) }}
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
                            class="px-4 py-1.5 bg-slate-800 text-white rounded-lg text-sm font-semibold hover:bg-slate-900 transition cursor-pointer shadow-sm"
                        >
                            Tutup
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>