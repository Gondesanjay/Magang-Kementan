<script setup>
import MainLayout from "@/Layouts/MainLayout.vue";
import { Head, useForm, usePage } from "@inertiajs/vue3";
import { computed, ref, watch } from "vue";
import Swal from "sweetalert2";

const props = defineProps({
    sisa_cuti: Number,
    total_cuti_tersedia: Number,
    // Daftar Hari Libur Nasional & Cuti Bersama dari tabel hari_libur
    // (dikirim CutiController@create)
    hariLiburs: {
        type: Array,
        default: () => [],
    },
    // Tahun yang sudah punya baris saldo (dikirim CutiController@create).
    // null = backend belum mengirim, pengecekan di form dilewati.
    tahun_saldo_tersedia: {
        type: Array,
        default: null,
    },
    // [BARU] Rentang pengajuan AKTIF milik user (menunggu_lX / disetujui),
    // dikirim CutiController@create. Dipakai untuk peringatan dini bila
    // tanggal yang dipilih bentrok dengan pengajuan lain. Backend
    // (CutiController::store) tetap penjaga akhir.
    pengajuan_aktif: {
        type: Array,
        default: () => [],
    },
});

// Jalur cadangan jika props sisa_cuti tidak dikirim
const page = usePage();
const authUser = computed(() => page.props.auth.user);

// ================= MASA KERJA DARI NIP (syarat Cuti Besar) =================
// Format NIP PNS 18 digit: 8 digit tgl lahir + 6 digit TMT (YYYYMM) + 6 digit lainnya
// Tahun pengangkatan = digit ke-9 s/d ke-12 → substring(8, 12)
const masaKerjaTahun = computed(() => {
    const nip = authUser.value?.nip || "";
    if (nip.length >= 12) {
        const tahunAngkat = parseInt(nip.substring(8, 12), 10);
        if (!isNaN(tahunAngkat) && tahunAngkat > 1900) {
            return new Date().getFullYear() - tahunAngkat;
        }
    }
    // Default aman: jangan mengunci opsi secara keliru. Backend tetap penjaga akhir.
    return 5;
});

const canChooseCutiBesar = computed(() => masaKerjaTahun.value >= 5);
// ================= END MASA KERJA =================

// ================= ALUR PERSETUJUAN DINAMIS =================
// Mengikuti kolom "Level Approval" di Excel Nama-Nama Pegawai Biro Perencanaan
const LEVEL_INFO = {
    1: { judul: "Ketua Tim Kerja", sub: "Atasan Langsung (L1)" },
    2: { judul: "Ketua Kelompok Substansi", sub: "(L2)" },
    3: { judul: "Kasubag TU", sub: "(L3)" },
    4: { judul: "Kepala Biro", sub: "Perencanaan (L4)" },
};

const teks = (v) => String(v ?? "").trim().toUpperCase();

const kategoriPemohon = computed(() => {
    const u = authUser.value || {};
    const jabatan = teks(u.jabatan);
    const unit = teks(u.kelompok_substansi ?? u.unit_kerja);
    const tim = teks(u.tim_kerja);
    const adaTim = tim !== "" && tim !== "-";
    const levelExcel = teks(u.level);

    if (jabatan.includes("KEPALA BIRO") || levelExcel === "LEVEL 4") {
        return { label: "Kepala Biro", levels: [] };
    }
    if (jabatan.includes("KEPALA SUBBAGIAN") || levelExcel === "LEVEL 3") {
        return { label: "Kepala Subbagian Tata Usaha", levels: [4] };
    }
    if (jabatan.startsWith("KETUA KELOMPOK") || levelExcel === "LEVEL 2") {
        return { label: "Ketua Kelompok Substansi", levels: [3, 4] };
    }
    if (jabatan.startsWith("KETUA TIM KERJA") || levelExcel === "LEVEL 1") {
        return { label: "Ketua Tim Kerja", levels: [2, 3, 4] };
    }
    // Staf
    if (unit.includes("SUBBAGIAN") || unit.includes("TATA USAHA")) {
        return { label: "Staf Subbagian Tata Usaha", levels: [3, 4] };
    }
    if (adaTim) {
        return { label: "Staf Tim Kerja", levels: [1, 3, 4] };
    }
    if (unit !== "") {
        return { label: "Staf Kelompok Substansi", levels: [2, 3, 4] };
    }
    // Fallback
    return { label: "Staf", levels: [1, 3, 4] };
});

const alurSteps = computed(() =>
    kategoriPemohon.value.levels.map((lv, i) => ({
        no: i + 1,
        level: lv,
        ...LEVEL_INFO[lv],
    })),
);

const warnaLingkaran = ["bg-blue-500", "bg-blue-400", "bg-blue-300"];
// ================= END ALUR PERSETUJUAN =================

// Form
const form = useForm({
    jenis_cuti: "Cuti Tahunan",
    anak_ke: "",
    tanggal_mulai: "",
    tanggal_selesai: "",
    keterangan: "",
    alamat_cuti: "",
    no_telp: "",
    lampiran: null,
});

const gunakanAlamatDomisili = ref(false);
const gunakanNomorTeleponProfil = ref(false);
const lampiranInput = ref(null);

const toggleAlamatDomisili = (e) => {
    form.alamat_cuti = e.target.checked
        ? authUser.value.alamat_domisili || ""
        : "";
};

const toggleNomorTeleponProfil = (e) => {
    form.no_telp = e.target.checked
        ? authUser.value.no_telepon || ""
        : "";
};

const resetSemuaForm = () => {
    form.reset();
    form.clearErrors();
    form.jenis_cuti = "Cuti Tahunan";
    gunakanAlamatDomisili.value = false;
    gunakanNomorTeleponProfil.value = false;
    if (lampiranInput.value) {
        lampiranInput.value.value = null;
    }
};

// ================= HELPER TANGGAL (hindari masalah timezone) =================
const toDateStr = (date) => {
    const yyyy = date.getFullYear();
    const mm = String(date.getMonth() + 1).padStart(2, "0");
    const dd = String(date.getDate()).padStart(2, "0");
    return `${yyyy}-${mm}-${dd}`;
};

const parseLocalDate = (dateStr) => new Date(`${dateStr}T00:00:00`);

const todayStr = computed(() => toDateStr(new Date()));

const isPastDate = computed(() => {
    if (!form.tanggal_mulai) return false;
    return form.tanggal_mulai < todayStr.value;
});

// ================= HARI LIBUR =================
const hariLiburSet = computed(() => {
    return new Set(props.hariLiburs.map((h) => h.tanggal));
});

const isHariLibur = (dateStr) => hariLiburSet.value.has(dateStr);

const hariLiburDalamRentang = computed(() => {
    if (!form.tanggal_mulai || !form.tanggal_selesai) return [];

    let start = parseLocalDate(form.tanggal_mulai);
    let end = parseLocalDate(form.tanggal_selesai);
    if (start > end) return [];

    const hasil = [];
    let current = new Date(start);
    while (current <= end) {
        const dateStr = toDateStr(current);
        const libur = props.hariLiburs.find((h) => h.tanggal === dateStr);
        if (libur) hasil.push(libur);
        current.setDate(current.getDate() + 1);
    }
    return hasil;
});

// Estimasi hari kerja (identik dengan backend hitungHariKerja)
const estimasiHari = computed(() => {
    if (!form.tanggal_mulai || !form.tanggal_selesai) return 0;

    let start = parseLocalDate(form.tanggal_mulai);
    let end = parseLocalDate(form.tanggal_selesai);
    if (start > end) return 0;

    let count = 0;
    let current = new Date(start);

    while (current <= end) {
        const dayOfWeek = current.getDay();
        const dateStr = toDateStr(current);
        const isWeekend = dayOfWeek === 0 || dayOfWeek === 6;

        if (!isWeekend && !isHariLibur(dateStr)) {
            count++;
        }
        current.setDate(current.getDate() + 1);
    }
    return count;
});

// Saldo
const saldoSaatIni = computed(() => {
    return (
        props.sisa_cuti ??
        props.total_cuti_tersedia ??
        page.props.auth?.user?.sisa_cuti ??
        page.props.auth?.user?.total_cuti_tersedia ??
        0
    );
});

const sisaSetelahPengajuan = computed(() => {
    if (form.jenis_cuti === "Cuti Tahunan") {
        return saldoSaatIni.value - estimasiHari.value;
    }
    return saldoSaatIni.value;
});

// ================= SALDO TAHUN BELUM ADA (KHUSUS CUTI TAHUNAN) =================
// Tahun PERTAMA dalam rentang tanggal cuti yang saldonya belum dibuat Admin HR.
// Yang dicek semua tahun yang disentuh rentang (mis. 31 Des 2026 - 1 Jan 2027
// menyentuh 2026 dan 2027), bukan hanya tanggal mulai. Jenis cuti lain tidak
// memotong saldo, jadi tidak dicek. Ini hanya peringatan dini; backend
// (CutiController::store/revisi) tetap penjaga akhir.
const tahunTanpaSaldo = computed(() => {
    if (!Array.isArray(props.tahun_saldo_tersedia)) return null;
    if (form.jenis_cuti !== "Cuti Tahunan") return null;
    if (!form.tanggal_mulai || !form.tanggal_selesai) return null;
    if (form.tanggal_mulai > form.tanggal_selesai) return null;

    const awal = parseInt(form.tanggal_mulai.slice(0, 4), 10);
    const akhir = parseInt(form.tanggal_selesai.slice(0, 4), 10);
    if (isNaN(awal) || isNaN(akhir)) return null;

    for (let t = awal; t <= akhir; t++) {
        if (!props.tahun_saldo_tersedia.includes(t)) return t;
    }
    return null;
});
// ================= END SALDO TAHUN BELUM ADA =================

// ================= [BARU] PENGAJUAN GANDA / TANGGAL BENTROK =================
// Pengajuan aktif milik user yang rentang tanggalnya bersinggungan dengan
// rentang yang sedang dipilih. Dua rentang bentrok jika:
//   mulai_baru <= selesai_lama  DAN  selesai_baru >= mulai_lama
// Berlaku untuk SEMUA jenis cuti. Ini hanya peringatan dini; backend
// (CutiController::store) tetap penjaga akhir.
const pengajuanBentrok = computed(() => {
    if (!form.tanggal_mulai || !form.tanggal_selesai) return null;
    if (form.tanggal_mulai > form.tanggal_selesai) return null;

    return (
        props.pengajuan_aktif.find(
            (p) =>
                form.tanggal_mulai <= p.tanggal_selesai &&
                form.tanggal_selesai >= p.tanggal_mulai,
        ) ?? null
    );
});
// ================= END PENGAJUAN GANDA / TANGGAL BENTROK =================

// ================= KALENDER MINI =================
const namaHariMini = ["Min", "Sen", "Sel", "Rab", "Kam", "Jum", "Sab"];

const calendarMonth = ref(
    new Date(new Date().getFullYear(), new Date().getMonth(), 1),
);

const calendarLabel = computed(() =>
    calendarMonth.value.toLocaleDateString("id-ID", {
        month: "long",
        year: "numeric",
    }),
);

const prevCalendarMonth = () => {
    const d = new Date(calendarMonth.value);
    d.setMonth(d.getMonth() - 1);
    calendarMonth.value = d;
};

const nextCalendarMonth = () => {
    const d = new Date(calendarMonth.value);
    d.setMonth(d.getMonth() + 1);
    calendarMonth.value = d;
};

watch(
    () => form.tanggal_mulai,
    (val) => {
        if (val) {
            const d = parseLocalDate(val);
            calendarMonth.value = new Date(d.getFullYear(), d.getMonth(), 1);
        }
    },
);

const calendarDays = computed(() => {
    const year = calendarMonth.value.getFullYear();
    const month = calendarMonth.value.getMonth();

    const firstDay = new Date(year, month, 1);
    const totalDaysInMonth = new Date(year, month + 1, 0).getDate();
    const leadingBlanks = firstDay.getDay();

    const cells = [];
    for (let i = 0; i < leadingBlanks; i++) {
        cells.push(null);
    }

    for (let day = 1; day <= totalDaysInMonth; day++) {
        const date = new Date(year, month, day);
        const dateStr = toDateStr(date);
        const dayOfWeek = date.getDay();

        let inRange = false;
        if (form.tanggal_mulai && form.tanggal_selesai) {
            inRange =
                dateStr >= form.tanggal_mulai &&
                dateStr <= form.tanggal_selesai;
        }

        cells.push({
            day,
            dateStr,
            isWeekend: dayOfWeek === 0 || dayOfWeek === 6,
            isHoliday: isHariLibur(dateStr),
            isToday: dateStr === todayStr.value,
            isRangeStart: dateStr === form.tanggal_mulai,
            isRangeEnd: dateStr === form.tanggal_selesai,
            inRange,
        });
    }
    return cells;
});

const hariLiburTerdekat = computed(() => {
    return [...props.hariLiburs]
        .filter((h) => h.tanggal >= todayStr.value)
        .sort((a, b) => a.tanggal.localeCompare(b.tanggal))
        .slice(0, 3);
});

const namaBulanSingkat = [
    "Jan", "Feb", "Mar", "Apr", "Mei", "Jun",
    "Jul", "Agu", "Sep", "Okt", "Nov", "Des",
];

const formatTanggalLibur = (dateStr) => {
    const d = parseLocalDate(dateStr);
    const dd = String(d.getDate()).padStart(2, "0");
    return `${dd} ${namaBulanSingkat[d.getMonth()]} ${d.getFullYear()}`;
};

const formatTanggalPendek = (dateStr) =>
    parseLocalDate(dateStr).toLocaleDateString("id-ID", {
        day: "2-digit",
        month: "short",
    });
// ================= END KALENDER MINI =================

const submit = () => {
    if (!form.jenis_cuti) {
        Swal.fire({
            icon: "error",
            title: "Jenis cuti belum dipilih",
            text: "Silakan pilih jenis cuti terlebih dahulu.",
            confirmButtonColor: "#ef4444",
        });
        return;
    }

    if (form.jenis_cuti === "Cuti Besar" && !canChooseCutiBesar.value) {
        Swal.fire({
            icon: "error",
            title: "Belum memenuhi syarat",
            text: `Cuti Besar hanya dapat diajukan jika masa kerja sudah mencapai 5 tahun. Masa kerja Anda saat ini terhitung ${masaKerjaTahun.value} tahun.`,
            confirmButtonColor: "#ef4444",
        });
        return;
    }

    if (tahunTanpaSaldo.value !== null) {
        Swal.fire({
            icon: "error",
            title: "Saldo belum tersedia",
            text: `Saldo cuti tahun ${tahunTanpaSaldo.value} belum tersedia. Cuti Tahunan untuk tahun tersebut baru bisa diajukan setelah Admin SDM membuat saldonya.`,
            confirmButtonColor: "#ef4444",
        });
        return;
    }

    // [BARU] Tanggal bentrok dengan pengajuan aktif lain.
    if (pengajuanBentrok.value) {
        Swal.fire({
            icon: "error",
            title: "Tanggal bentrok",
            text: "Tanggal yang dipilih bentrok dengan pengajuan cuti Anda yang masih aktif.",
            confirmButtonColor: "#ef4444",
        });
        return;
    }

    if (form.jenis_cuti !== "Cuti Melahirkan") {
        form.anak_ke = "";
    }

    form.post(route("karyawan.ajukan.store"), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            resetSemuaForm();
            Swal.fire({
                icon: "success",
                title: "Pengajuan Berhasil!",
                text: "Permohonan cuti Anda berhasil dikirim dan sedang menunggu persetujuan Atasan.",
                confirmButtonColor: "#10b981",
                confirmButtonText: "Mengerti",
            });
        },
    });
};
</script>

<template>
    <Head title="Ajukan Cuti" />

    <MainLayout>
        <div class="w-full min-w-0 max-w-6xl mx-auto space-y-5 sm:space-y-6 pb-8 sm:pb-12">
            <div>
                <h1 class="text-xl sm:text-2xl md:text-3xl font-bold text-slate-800 tracking-tight break-words">
                    Formulir Pengajuan Cuti
                </h1>
                <p class="text-slate-500 mt-1 text-sm break-words">
                    Silakan lengkapi data di bawah ini untuk mengajukan permohonan cuti baru.
                </p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 sm:gap-6 items-stretch">
                <!-- FORM UTAMA -->
                <div class="min-w-0 lg:col-span-2 bg-white p-4 sm:p-6 md:p-8 rounded-2xl shadow-sm border border-slate-200 h-full flex flex-col justify-between">
                    <div>
                        <!-- Error saldo -->
                        <div
                            v-if="form.errors.tanggal_mulai && form.errors.tanggal_mulai.includes('Saldo')"
                            class="mb-6 p-4 bg-red-50 text-red-700 rounded-xl border border-red-200 flex items-start gap-3"
                        >
                            <svg class="w-5 h-5 text-red-500 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                            </svg>
                            <span class="text-sm font-semibold">{{ form.errors.tanggal_mulai }}</span>
                        </div>

                        <!-- Error jenis cuti (masa kerja Cuti Besar / saldo tahun belum ada) -->
                        <div
                            v-if="form.errors.jenis_cuti"
                            class="mb-6 p-4 bg-red-50 text-red-700 rounded-xl border border-red-200 flex items-start gap-3"
                        >
                            <svg class="w-5 h-5 text-red-500 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                            </svg>
                            <span class="text-sm font-semibold">{{ form.errors.jenis_cuti }}</span>
                        </div>

                        <form @submit.prevent="submit" class="space-y-6">
                            <!-- Jenis Cuti -->
                            <div>
                                <label for="jenis-cuti" class="block text-sm font-semibold text-slate-700 mb-2">
                                    Jenis Cuti <span class="text-red-500">*</span>
                                </label>
                                <select
                                    id="jenis-cuti"
                                    name="jenis_cuti"
                                    v-model="form.jenis_cuti"
                                    class="w-full min-h-[44px] text-base sm:text-sm border-slate-200 rounded-xl focus:ring-green-500 focus:border-green-500 py-2.5 px-3 bg-white"
                                >
                                    <option value="Cuti Tahunan">Cuti Tahunan</option>
                                    <option value="Cuti Melahirkan">Cuti Melahirkan</option>
                                    <option value="Cuti Besar" :disabled="!canChooseCutiBesar">
                                        Cuti Besar{{ !canChooseCutiBesar ? " (Belum memenuhi syarat masa kerja 5 tahun)" : "" }}
                                    </option>
                                    <option value="Cuti Alasan Penting">Cuti Alasan Penting</option>
                                </select>
                                <span v-if="form.errors.jenis_cuti" class="text-xs text-red-500 mt-1 block">
                                    {{ form.errors.jenis_cuti }}
                                </span>
                                <span v-else-if="!canChooseCutiBesar" class="text-xs text-slate-400 mt-1 block">
                                    Opsi "Cuti Besar" terkunci karena masa kerja Anda saat ini baru
                                    {{ masaKerjaTahun }} tahun (syarat minimal 5 tahun).
                                </span>
                            </div>

                            <!-- Anak ke- (hanya Cuti Melahirkan) -->
                            <div v-if="form.jenis_cuti === 'Cuti Melahirkan'" class="animate-in fade-in duration-300">
                                <label for="anak-ke" class="block text-sm font-semibold text-slate-700 mb-2">
                                    Anak ke-berapa <span class="text-red-500">*</span>
                                </label>
                                <input
                                    id="anak-ke"
                                    name="anak_ke"
                                    v-model="form.anak_ke"
                                    type="number"
                                    min="1"
                                    required
                                    placeholder="Contoh: 1, 2, atau 3"
                                    class="w-full md:w-1/2 min-h-[44px] text-base sm:text-sm border-slate-200 rounded-xl focus:ring-green-500 focus:border-green-500 py-2.5 px-3"
                                />
                                <span v-if="form.errors.anak_ke" class="text-xs text-red-500 mt-1 block">
                                    {{ form.errors.anak_ke }}
                                </span>
                            </div>

                            <!-- Tanggal -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label for="tanggal-mulai" class="block text-sm font-semibold text-slate-700 mb-2">
                                        Tanggal Mulai <span class="text-red-500">*</span>
                                    </label>
                                    <input
                                        id="tanggal-mulai"
                                        name="tanggal_mulai"
                                        v-model="form.tanggal_mulai"
                                        type="date"
                                        :min="todayStr"
                                        required
                                        class="w-full min-h-[44px] text-base sm:text-sm border-slate-200 rounded-xl focus:ring-green-500 focus:border-green-500 py-2.5 px-3"
                                    />
                                    <span
                                        v-if="form.errors.tanggal_mulai && !form.errors.tanggal_mulai.includes('Saldo')"
                                        class="text-xs text-red-500 mt-1 block"
                                    >
                                        {{ form.errors.tanggal_mulai }}
                                    </span>
                                </div>
                                <div>
                                    <label for="tanggal-selesai" class="block text-sm font-semibold text-slate-700 mb-2">
                                        Tanggal Selesai <span class="text-red-500">*</span>
                                    </label>
                                    <input
                                        id="tanggal-selesai"
                                        name="tanggal_selesai"
                                        v-model="form.tanggal_selesai"
                                        type="date"
                                        :min="form.tanggal_mulai || todayStr"
                                        required
                                        class="w-full min-h-[44px] text-base sm:text-sm border-slate-200 rounded-xl focus:ring-green-500 focus:border-green-500 py-2.5 px-3"
                                    />
                                    <span v-if="form.errors.tanggal_selesai" class="text-xs text-red-500 mt-1 block">
                                        {{ form.errors.tanggal_selesai }}
                                    </span>
                                </div>
                            </div>

                            <!-- Estimasi Hari Kerja -->
                            <div class="space-y-2">
                                <div class="flex flex-col items-start gap-2 bg-slate-50 border border-slate-200 p-4 rounded-xl sm:flex-row sm:items-center sm:justify-between">
                                    <div class="flex items-center gap-2 text-slate-600">
                                        <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                        </svg>
                                        <span class="text-sm font-medium">Estimasi Jumlah Hari Kerja</span>
                                    </div>
                                    <span class="text-base font-bold text-green-700">{{ estimasiHari }} Hari</span>
                                </div>

                                <!-- Info hari libur di dalam rentang -->
                                <div
                                    v-if="hariLiburDalamRentang.length > 0"
                                    class="flex items-start gap-2 bg-blue-50 border border-blue-100 rounded-xl px-3.5 py-2.5"
                                >
                                    <svg class="w-4 h-4 text-blue-500 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                    <p class="min-w-0 text-xs text-blue-700 leading-relaxed break-words">
                                        <span class="font-semibold">{{ hariLiburDalamRentang.length }} tanggal tidak dihitung</span>
                                        karena bertepatan dengan hari libur:
                                        <span v-for="(libur, idx) in hariLiburDalamRentang" :key="libur.tanggal">
                                            <span class="font-semibold">{{ libur.keterangan }}</span>
                                            ({{ formatTanggalPendek(libur.tanggal) }})<span v-if="idx < hariLiburDalamRentang.length - 1">, </span>
                                        </span>
                                    </p>
                                </div>

                                <p
                                    v-if="estimasiHari === 0 && form.tanggal_mulai && form.tanggal_selesai && !isPastDate"
                                    class="text-xs font-bold text-red-500 animate-pulse"
                                >
                                    *Tanggal yang dipilih tidak valid karena hanya mencakup akhir pekan (Sabtu/Minggu) dan/atau hari libur nasional/cuti bersama.
                                </p>

                                <p v-if="isPastDate" class="text-xs font-bold text-red-500 animate-pulse">
                                    *Peringatan: Anda tidak dapat mengajukan cuti untuk tanggal yang sudah lewat.
                                </p>

                                <!-- Saldo tahun belum dibuat Admin SDM (khusus Cuti Tahunan) -->
                                <div
                                    v-if="tahunTanpaSaldo !== null"
                                    class="flex items-start gap-2 bg-red-50 border border-red-200 rounded-xl px-3.5 py-2.5"
                                >
                                    <svg class="w-4 h-4 text-red-500 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                                    </svg>
                                    <p class="min-w-0 text-xs text-red-700 leading-relaxed break-words">
                                        <span class="font-semibold">Saldo cuti tahun {{ tahunTanpaSaldo }} belum tersedia.</span>
                                        Cuti Tahunan untuk tahun tersebut baru bisa diajukan setelah Admin SDM membuat saldonya.
                                        Pilih tanggal yang masih dalam tahun yang saldonya sudah ada, atau pilih jenis cuti lain
                                        yang tidak memotong saldo.
                                    </p>
                                </div>

                                <!-- [BARU] Tanggal bentrok dengan pengajuan aktif lain -->
                                <div
                                    v-if="pengajuanBentrok"
                                    class="flex items-start gap-2 bg-red-50 border border-red-200 rounded-xl px-3.5 py-2.5"
                                >
                                    <svg class="w-4 h-4 text-red-500 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                                    </svg>
                                    <p class="min-w-0 text-xs text-red-700 leading-relaxed break-words">
                                        <span class="font-semibold">Tanggal bentrok dengan pengajuan lain.</span>
                                        Anda sudah punya {{ pengajuanBentrok.jenis_cuti }}
                                        ({{ formatTanggalPendek(pengajuanBentrok.tanggal_mulai) }} - {{ formatTanggalPendek(pengajuanBentrok.tanggal_selesai) }})
                                        yang {{ pengajuanBentrok.status === 'disetujui' ? 'sudah disetujui' : 'masih menunggu persetujuan' }}.
                                        Batalkan pengajuan tersebut terlebih dahulu atau pilih tanggal lain.
                                    </p>
                                </div>
                            </div>

                            <!-- Keterangan -->
                            <div>
                                <label for="keterangan" class="block text-sm font-semibold text-slate-700 mb-2">
                                    Keterangan / Alasan Cuti <span class="text-red-500">*</span>
                                </label>
                                <textarea
                                    id="keterangan"
                                    name="keterangan"
                                    v-model="form.keterangan"
                                    rows="3"
                                    required
                                    placeholder="Jelaskan alasan atau keperluan cuti Anda secara detail..."
                                    class="w-full min-h-[88px] text-base sm:text-sm border-slate-200 rounded-xl focus:ring-green-500 focus:border-green-500 py-2.5 px-3 resize-y"
                                ></textarea>
                                <span v-if="form.errors.keterangan" class="text-xs text-red-500 mt-1 block">
                                    {{ form.errors.keterangan }}
                                </span>
                            </div>

                            <!-- Alamat & Telepon -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <div class="flex flex-col items-start gap-2 mb-2 sm:flex-row sm:items-center sm:justify-between">
                                        <label for="alamat-cuti" class="block text-sm font-semibold text-slate-700">
                                            Alamat Selama Cuti <span class="text-red-500">*</span>
                                        </label>
                                        <label for="gunakan-alamat-domisili" class="flex min-h-[40px] items-center gap-2 text-xs font-medium text-slate-600 cursor-pointer">
                                            <input
                                                id="gunakan-alamat-domisili"
                                                name="gunakan_alamat_domisili"
                                                type="checkbox"
                                                v-model="gunakanAlamatDomisili"
                                                @change="toggleAlamatDomisili"
                                                class="rounded border-slate-300 text-green-600 focus:ring-green-500"
                                            />
                                            Sesuai alamat domisili di profil
                                        </label>
                                    </div>
                                    <textarea
                                        id="alamat-cuti"
                                        name="alamat_cuti"
                                        v-model="form.alamat_cuti"
                                        rows="2"
                                        required
                                        :disabled="gunakanAlamatDomisili"
                                        placeholder="Contoh: Jl. Merdeka No. 10..."
                                        class="w-full min-h-[72px] text-base sm:text-sm border-slate-200 rounded-xl focus:ring-green-500 focus:border-green-500 py-2.5 px-3 resize-y disabled:bg-slate-50 disabled:text-slate-500"
                                    ></textarea>
                                    <span v-if="form.errors.alamat_cuti" class="text-xs text-red-500 mt-1 block">
                                        {{ form.errors.alamat_cuti }}
                                    </span>
                                </div>

                                <div>
                                    <div class="flex flex-col items-start gap-2 mb-2 sm:flex-row sm:items-center sm:justify-between">
                                        <label for="no-telp" class="block text-sm font-semibold text-slate-700">
                                            No. Telepon / HP <span class="text-red-500">*</span>
                                        </label>
                                        <label for="gunakan-nomor-telepon-profil" class="flex min-h-[40px] items-center gap-2 text-xs font-medium text-slate-600 cursor-pointer">
                                            <input
                                                id="gunakan-nomor-telepon-profil"
                                                name="gunakan_nomor_telepon_profil"
                                                type="checkbox"
                                                v-model="gunakanNomorTeleponProfil"
                                                @change="toggleNomorTeleponProfil"
                                                class="rounded border-slate-300 text-green-600 focus:ring-green-500"
                                            />
                                            Sesuai nomor telepon di profil
                                        </label>
                                    </div>
                                    <input
                                        id="no-telp"
                                        name="no_telp"
                                        v-model="form.no_telp"
                                        type="tel"
                                        required
                                        :disabled="gunakanNomorTeleponProfil"
                                        placeholder="Contoh: 081234567890"
                                        class="w-full min-h-[44px] text-base sm:text-sm border-slate-200 rounded-xl focus:ring-green-500 focus:border-green-500 py-2.5 px-3 disabled:bg-slate-50 disabled:text-slate-500"
                                    />
                                    <span v-if="form.errors.no_telp" class="text-xs text-red-500 mt-1 block">
                                        {{ form.errors.no_telp }}
                                    </span>
                                </div>
                            </div>

                            <!-- Lampiran -->
                            <div>
                                <label for="lampiran" class="block text-sm font-semibold text-slate-700 mb-2">
                                    Lampiran Dokumen
                                    <span class="text-slate-400 font-normal">(Opsional: PDF/Gambar, Maks 2MB)</span>
                                </label>
                                <input
                                    id="lampiran"
                                    name="lampiran"
                                    ref="lampiranInput"
                                    type="file"
                                    @change="form.lampiran = $event.target.files[0] ?? null"
                                    accept=".pdf,.jpg,.jpeg,.png"
                                    class="w-full min-h-[44px] text-base sm:text-sm text-slate-500 file:mr-2 sm:file:mr-4 file:py-2 file:px-3 sm:file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-semibold file:bg-green-50 file:text-green-700 hover:file:bg-green-100 border border-slate-200 rounded-xl cursor-pointer p-1"
                                />
                                <span v-if="form.errors.lampiran" class="text-xs text-red-500 mt-1 block">
                                    {{ form.errors.lampiran }}
                                </span>
                            </div>
                        </form>
                    </div>

                    <!-- Tombol Aksi -->
                    <div class="flex flex-col-reverse sm:flex-row items-stretch sm:items-center justify-end gap-3 pt-6 mt-6 border-t border-slate-100">
                        <button
                            type="button"
                            @click="resetSemuaForm"
                            class="w-full sm:w-auto min-h-[44px] px-5 py-2.5 border border-slate-300 text-slate-600 rounded-xl text-sm font-semibold hover:bg-slate-50 transition"
                        >
                            Batal
                        </button>
                        <button
                            @click="submit"
                            :disabled="
                                form.processing ||
                                estimasiHari === 0 ||
                                isPastDate ||
                                tahunTanpaSaldo !== null ||
                                pengajuanBentrok !== null ||
                                (form.jenis_cuti === 'Cuti Tahunan' && sisaSetelahPengajuan < 0) ||
                                (form.jenis_cuti === 'Cuti Besar' && !canChooseCutiBesar)
                            "
                            class="w-full sm:w-auto min-h-[44px] px-6 py-2.5 bg-green-600 hover:bg-green-700 text-white rounded-xl text-sm font-semibold shadow-md shadow-green-600/20 transition disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            Ajukan Cuti
                        </button>
                    </div>
                </div>

                <!-- SIDEBAR KANAN -->
                <div class="min-w-0 lg:col-span-1 flex flex-col gap-4 sm:gap-6">
                    <!-- Ringkasan Saldo -->
                    <div class="bg-white p-5 rounded-2xl shadow-sm border border-slate-200">
                        <div class="flex items-center gap-2 mb-4">
                            <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                            </svg>
                            <h3 class="text-base font-bold text-slate-800">Ringkasan Saldo</h3>
                        </div>

                        <div class="space-y-3">
                            <div class="flex justify-between items-center text-sm">
                                <span class="text-slate-600 break-words">Sisa Saldo Saat Ini</span>
                                <span class="font-bold text-slate-800">{{ saldoSaatIni }} Hari</span>
                            </div>
                            <div class="flex justify-between items-center text-sm border-b border-slate-100 pb-3">
                                <span class="text-slate-600 break-words">Estimasi Pengajuan</span>
                                <span
                                    class="font-bold"
                                    :class="form.jenis_cuti === 'Cuti Tahunan' ? 'text-red-500' : 'text-slate-500'"
                                >
                                    {{ form.jenis_cuti === "Cuti Tahunan" ? estimasiHari : 0 }} Hari
                                </span>
                            </div>
                            <div class="flex justify-between items-center text-sm bg-green-50 p-3 rounded-lg border border-green-100">
                                <span class="font-semibold text-green-800 break-words">Sisa Setelah Pengajuan</span>
                                <span
                                    class="font-bold text-green-700 text-base"
                                    :class="{ 'text-red-600': sisaSetelahPengajuan < 0 }"
                                >
                                    {{ sisaSetelahPengajuan }} Hari
                                </span>
                            </div>
                            <div
                                v-if="form.jenis_cuti !== 'Cuti Tahunan'"
                                class="text-[10px] text-center text-slate-400 font-semibold italic mt-2"
                            >
                                *Jenis cuti ini tidak memotong saldo cuti tahunan.
                            </div>
                        </div>
                    </div>

                    <!-- Kalender Mini -->
                    <div class="bg-white p-5 rounded-2xl shadow-sm border border-slate-200">
                        <div class="flex items-center justify-between mb-4">
                            <button
                                type="button"
                                @click="prevCalendarMonth"
                                class="min-w-[40px] min-h-[40px] flex items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 transition"
                                aria-label="Bulan sebelumnya"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                                </svg>
                            </button>
                            <h3 class="text-sm font-bold text-slate-800 capitalize">{{ calendarLabel }}</h3>
                            <button
                                type="button"
                                @click="nextCalendarMonth"
                                class="min-w-[40px] min-h-[40px] flex items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 transition"
                                aria-label="Bulan berikutnya"
                            >
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </button>
                        </div>

                        <div class="grid grid-cols-7 gap-y-1 text-center">
                            <span
                                v-for="hari in namaHariMini"
                                :key="hari"
                                class="text-[10px] font-semibold text-slate-400 py-1"
                            >
                                {{ hari }}
                            </span>

                            <template v-for="(cell, idx) in calendarDays" :key="idx">
                                <div v-if="!cell" class="h-7"></div>
                                <div
                                    v-else
                                    class="h-7 min-w-0 flex items-center justify-center text-xs rounded-lg"
                                    :class="[
                                        cell.isRangeStart || cell.isRangeEnd
                                            ? 'bg-green-600 text-white font-bold'
                                            : cell.inRange
                                              ? 'bg-green-100 text-green-700 font-semibold'
                                              : cell.isHoliday
                                                ? 'bg-red-50 text-red-600 font-semibold'
                                                : cell.isWeekend
                                                  ? 'text-slate-400'
                                                  : 'text-slate-600',
                                        cell.isToday && !cell.isRangeStart && !cell.isRangeEnd
                                            ? 'ring-1 ring-green-500'
                                            : '',
                                    ]"
                                >
                                    {{ cell.day }}
                                </div>
                            </template>
                        </div>

                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5 mt-4 pt-3 border-t border-slate-100">
                            <div class="flex items-center gap-1.5">
                                <span class="w-2.5 h-2.5 rounded-full bg-green-600"></span>
                                <span class="text-[10px] sm:text-xs text-slate-500">Rentang dipilih</span>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <span class="w-2.5 h-2.5 rounded-full bg-red-400"></span>
                                <span class="text-[10px] sm:text-xs text-slate-500">Hari libur</span>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <span class="w-2.5 h-2.5 rounded-full border border-green-500"></span>
                                <span class="text-[10px] sm:text-xs text-slate-500">Hari ini</span>
                            </div>
                        </div>
                    </div>

                    <!-- Hari Libur Terdekat -->
                    <div class="bg-white p-5 rounded-2xl shadow-sm border border-slate-200">
                        <h3 class="text-sm font-bold text-slate-800 mb-4">Hari Libur Terdekat</h3>

                        <div v-if="hariLiburTerdekat.length > 0" class="space-y-2.5">
                            <div
                                v-for="libur in hariLiburTerdekat"
                                :key="libur.tanggal"
                                class="flex items-center gap-3 bg-slate-50 rounded-xl p-3"
                            >
                                <div class="w-9 h-9 shrink-0 rounded-lg bg-blue-50 flex items-center justify-center">
                                    <svg class="w-4 h-4 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <p class="text-xs font-bold text-slate-800 leading-snug break-words">{{ libur.keterangan }}</p>
                                    <p class="text-[11px] text-slate-400">{{ formatTanggalLibur(libur.tanggal) }}</p>
                                </div>
                            </div>
                        </div>

                        <p v-else class="text-xs text-slate-400 text-center py-2">
                            Tidak ada hari libur dalam waktu dekat.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Baris bawah: Ketentuan + Alur Persetujuan -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Ketentuan Pengajuan -->
                <div class="bg-slate-900 p-5 rounded-2xl shadow-sm border border-slate-800">
                    <div class="flex items-center gap-2 mb-3">
                        <svg class="w-5 h-5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <h3 class="text-sm font-bold text-white">Ketentuan Pengajuan</h3>
                    </div>
                    <ul class="text-xs text-slate-300 space-y-2 list-disc pl-4 marker:text-slate-500">
                        <li>Tidak ada batasan waktu minimum pengajuan, pegawai dapat mengajukan cuti darurat kapan saja.</li>
                        <li>Pengajuan cuti yang jatuh pada akhir pekan atau hari libur nasional tidak akan dihitung sebagai hari cuti.</li>
                        <li>Sisa cuti tahunan yang tidak terpakai dapat diakumulasikan ke tahun berikutnya (maksimal 6 hari).</li>
                        <li>Cuti Besar hanya dapat diajukan oleh pegawai dengan masa kerja minimal 5 tahun.</li>
                        <li>Cuti Tahunan hanya dapat diajukan untuk tahun yang saldonya sudah dibuat oleh Admin SDM.</li>
                        <li>Tanggal cuti tidak boleh bentrok dengan pengajuan lain yang masih menunggu persetujuan atau sudah disetujui.</li>
                    </ul>
                </div>

                <!-- Alur Persetujuan Dinamis -->
                <div class="bg-blue-50 p-5 rounded-2xl shadow-sm border border-blue-100">
                    <div class="flex items-center gap-2 mb-1">
                        <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                        </svg>
                        <h3 class="text-sm font-bold text-blue-900">Alur Persetujuan Anda</h3>
                    </div>
                    <p class="text-[11px] text-blue-700 mb-4 ml-7">{{ kategoriPemohon.label }}</p>

                    <p v-if="alurSteps.length === 0" class="text-xs text-blue-800 leading-relaxed">
                        Jabatan Anda berada di level tertinggi Biro, sehingga tidak ada jenjang persetujuan di dalam biro.
                    </p>

                    <div
                        v-else
                        class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 sm:gap-2"
                    >
                        <template v-for="(step, idx) in alurSteps" :key="step.level">
                            <div class="flex sm:flex-col items-center gap-2 sm:gap-1">
                                <div
                                    class="w-7 h-7 rounded-full flex items-center justify-center text-white text-xs font-bold shrink-0"
                                    :class="warnaLingkaran[idx] || 'bg-blue-300'"
                                >
                                    {{ step.no }}
                                </div>
                                <div class="sm:text-center">
                                    <p class="text-xs font-bold text-blue-900">{{ step.judul }}</p>
                                    <p class="text-[11px] text-blue-700">{{ step.sub }}</p>
                                </div>
                            </div>

                            <div
                                v-if="idx < alurSteps.length - 1"
                                class="hidden sm:block flex-1 h-0.5 bg-blue-200 rounded-full"
                            ></div>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </MainLayout>
</template>