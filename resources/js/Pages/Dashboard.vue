<script setup>
import MainLayout from "@/Layouts/MainLayout.vue";
import { Head, usePage, Link, router } from "@inertiajs/vue3";
import { computed, ref } from "vue";

const props = defineProps({
    stats: Object,
    recentCuti: Array,
    cutiDisetujuiData: {
        type: Array,
        default: () => [],
    },
    // === TAMBAHAN ===
    cutiDisetujuiPribadi: {
        type: Array,
        default: () => [],
    },
    chartDataBackend: {
        type: Array,
        default: () => [],
    },
    // === TAMBAHAN ===
    chartDataPribadi: {
        type: Array,
        default: () => [],
    },
    hariLiburs: {
        type: Array,
        default: () => [],
    },
    anggotaTim: {
        type: Array,
        default: () => [],
    },
    recentCutiPribadi: {
        type: Array,
        default: () => [],
    },
    // ================================================================
    // TAMBAHAN: Dukungan "Filter Divisi"
    // - listDivisi: daftar string unik dari kolom `pegawais.departemen`
    //   (berlabel "Divisi/Departemen" di form Kelola Pegawai), dikirim
    //   dari DashboardController@index.
    // - filter: object berisi filter yang sedang aktif, dipakai untuk
    //   menjaga pilihan dropdown tetap konsisten setelah reload data
    //   (mis. saat preserveState/preserveScroll dari Inertia).
    // ================================================================
    listDivisi: {
        type: Array,
        default: () => [],
    },
    filter: {
        type: Object,
        default: () => ({}),
    },
});

const getLihatSemuaHref = () => {
    if (user.value.role_id === 5) {
        return route("admin.rekap");
    }

    if (user.value.role_id === 1) {
        return route("karyawan.riwayat");
    }

    // Atasan
    if (activeTab.value === "pribadi") {
        return route("karyawan.riwayat"); // ← ini yang penting
    }

    return route("atasan.approval");
};

const page = usePage();
const user = computed(() => page.props.auth.user);

// <--- TAMBAHAN: Deteksi Admin HR (role_id 5) --->
// Dipakai untuk menyembunyikan widget cuti pribadi & toggle "Pribadi/Tim"
// karena Admin HR tidak memiliki saldo cuti pribadi yang relevan.
const isAdminHR = computed(() => user.value.role_id === 5);

// STATE UNTUK TAB AKTIF (Default ke 'pribadi')
// Hanya relevan untuk role_id 2, 3, 4, 6 (Atasan) yang punya
// dua ringkasan (Pribadi & Tim). Role 1 (Pegawai) selalu melihat ringkasan pribadi.
// Admin HR (role_id 5) langsung default ke 'tim' karena tidak punya ringkasan pribadi.
const activeTab = ref(isAdminHR.value ? "tim" : "pribadi");

// ================================================================
// TAMBAHAN: State & fungsi untuk "Filter Divisi"
// Hanya relevan/ditampilkan untuk role yang punya konteks tim
// (Atasan L1-L4 & Admin HR = role_id 2,3,4,5,6), karena Karyawan
// (role_id 1) tidak punya widget/ringkasan tim di dashboard.
// ================================================================
const selectedDivisi = ref(props.filter?.departemen ?? null);

const applyDivisiFilter = () => {
    router.get(
        route("dashboard"),
        { departemen: selectedDivisi.value },
        {
            preserveState: true,
            preserveScroll: true,
            // Hanya minta data yang benar-benar terpengaruh oleh filter
            // divisi, supaya widget lain (hariLiburs, recentCutiPribadi,
            // dst) tidak ikut re-fetch dan dashboard tetap ringan.
            only: [
                "stats",
                "recentCuti",
                "cutiDisetujuiData",
                "chartDataBackend",
                "anggotaTim",
                "timCutiHariIni",
                "filter",
            ],
        },
    );
};

// Data yang ditampilkan di tabel "Riwayat / Aktivitas"
const displayedRecentCuti = computed(() => {
    const isPribadi =
        !isAdminHR.value &&
        (user.value.role_id === 1 || activeTab.value === "pribadi");

    if (isPribadi) {
        // Pakai recentCutiPribadi jika ada, kalau tidak fallback ke recentCuti
        return props.recentCutiPribadi && props.recentCutiPribadi.length
            ? props.recentCutiPribadi
            : user.value.role_id === 1
              ? props.recentCuti
              : [];
    }

    // Tab Tim
    return props.recentCuti;
});
// Variabel untuk melacak batang grafik mana yang sedang diklik (untuk menampilkan
// tooltip rincian). Menggunakan klik (bukan hover) supaya tetap berfungsi konsisten
// di semua device, termasuk layar sentuh yang tidak mendukung hover.
const activeChartIndex = ref(null);
const toggleChartTooltip = (index) => {
    activeChartIndex.value = activeChartIndex.value === index ? null : index;
};

const formatDate = (dateString) => {
    if (!dateString) return "-";
    const date = new Date(dateString);
    return new Intl.DateTimeFormat("id-ID", {
        day: "2-digit",
        month: "short",
        year: "numeric",
    }).format(date);
};

const formatStatus = (status) => {
    switch (status) {
        case "menunggu_l1":
            return {
                text: "Menunggu Ketua Tim Kerja",
                class: "bg-amber-50 text-amber-700 border border-amber-200",
            };
        case "menunggu_l2":
            return {
                text: "Menunggu Ketua Kelompok Substansi",
                class: "bg-amber-50 text-amber-700 border border-amber-200",
            };
        case "menunggu_l3":
            return {
                text: "Menunggu Kasubag TU",
                class: "bg-amber-50 text-amber-700 border border-amber-200",
            };
        case "menunggu_l4":
            return {
                text: "Menunggu Kepala Biro Perencanaan",
                class: "bg-amber-50 text-amber-700 border border-amber-200",
            };
        case "disetujui":
            return {
                text: "Disetujui",
                class: "bg-emerald-50 text-emerald-700 border border-emerald-200",
            };
        case "ditolak":
            return {
                text: "Ditolak",
                class: "bg-red-50 text-red-700 border border-red-200",
            };
        case "dibatalkan_reguler":
            return {
                text: "Dibatalkan",
                class: "bg-slate-50 text-slate-600 border border-slate-200",
            };
        case "dibatalkan_ditangguhkan":
            return {
                text: "Ditangguhkan",
                class: "bg-orange-50 text-orange-700 border border-orange-200",
            };
        case "ditangguhkan":
            return {
                text: "Ditangguhkan",
                class: "bg-orange-50 text-orange-700 border border-orange-200",
            };
        default:
            return {
                text: status ? status.replace(/_/g, " ").toUpperCase() : "-",
                class: "bg-slate-50 text-slate-600 border border-slate-200",
            };
    }
};

const currentYear = new Date().getFullYear();

// ================= PERBAIKAN KALKULASI CUTI TAHUNAN (Merge Fix) =================
const cutiTahunanTerpakai = computed(() => {
    return props.stats?.cuti_terpakai || 0;
});

const sisaCutiTersedia = computed(() => {
    return props.stats?.total_cuti_tersedia || 0;
});
// ================= END PERBAIKAN KALKULASI CUTI TAHUNAN =================

const headerSubtitle = computed(() =>
    isAdminHR.value
        ? "Kelola permohonan dan rekap statistik cuti pegawai hari ini."
        : "Pantau sisa cuti dan kelola permohonan Anda dengan mudah hari ini.",
);

const toDateOnlyString = (dateValue) => {
    const date = new Date(dateValue);
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, "0");
    const day = String(date.getDate()).padStart(2, "0");
    return `${year}-${month}-${day}`;
};

const totalAnggota = computed(() => {
    return Array.isArray(props.anggotaTim) ? props.anggotaTim.length : 0;
});

const cutiHariIniCount = computed(() => {
    if (!Array.isArray(props.cutiDisetujuiData)) return 0;

    const today = toDateOnlyString(new Date());
    const pegawaiOnLeave = new Set();

    props.cutiDisetujuiData.forEach((cuti) => {
        if (cuti.status !== "disetujui") return;

        const tanggalMulai = toDateOnlyString(cuti.tanggal_mulai);
        const tanggalSelesai = toDateOnlyString(
            cuti.tanggal_selesai || cuti.tanggal_mulai,
        );

        if (today >= tanggalMulai && today <= tanggalSelesai) {
            const pegawaiId = cuti.pegawai_id ?? cuti.pegawai?.id ?? cuti.id;
            if (pegawaiId !== undefined && pegawaiId !== null) {
                pegawaiOnLeave.add(pegawaiId);
            }
        }
    });

    return pegawaiOnLeave.size;
});

const hadirHariIniCount = computed(() => {
    return Math.max(totalAnggota.value - cutiHariIniCount.value, 0);
});

const isPegawaiCutiHariIni = (pegawaiId) => {
    if (
        !Array.isArray(props.cutiDisetujuiData) ||
        pegawaiId === undefined ||
        pegawaiId === null
    ) {
        return false;
    }

    const today = toDateOnlyString(new Date());

    return props.cutiDisetujuiData.some((cuti) => {
        if (cuti.status !== "disetujui") return false;

        const cutiPegawaiId = cuti.pegawai_id ?? cuti.pegawai?.id;
        if (String(cutiPegawaiId) !== String(pegawaiId)) return false;

        const tanggalMulai = toDateOnlyString(cuti.tanggal_mulai);
        const tanggalSelesai = toDateOnlyString(
            cuti.tanggal_selesai || cuti.tanggal_mulai,
        );

        return today >= tanggalMulai && today <= tanggalSelesai;
    });
};

// ================= GRAFIK STACKED BAR + DISTRIBUSI =================
const chartData = computed(() => {
    const months = [
        "Jan",
        "Feb",
        "Mar",
        "Apr",
        "Mei",
        "Jun",
        "Jul",
        "Ags",
        "Sep",
        "Okt",
        "Nov",
        "Des",
    ];

    const data = months.map((month) => ({
        month,
        totalDays: 0,
        breakdown: { tahunan: 0, melahirkan: 0, alasan_penting: 0, besar: 0 },
    }));

    const isPribadiMode =
        !isAdminHR.value &&
        (user.value.role_id === 1 || activeTab.value === "pribadi");

    // Sumber data utama
    let sourceCuti = [];
    let sourceChart = [];

    if (isPribadiMode) {
        sourceCuti = props.cutiDisetujuiPribadi || [];
        sourceChart = props.chartDataPribadi || [];
    } else {
        sourceCuti = props.cutiDisetujuiData || [];
        sourceChart = props.chartDataBackend || [];
    }

    // Hitung breakdown dari sourceCuti
    if (sourceCuti.length > 0) {
        sourceCuti.forEach((cuti) => {
            if (cuti.status !== "disetujui") return;

            const monthIndex = new Date(cuti.tanggal_mulai).getMonth();
            if (monthIndex < 0 || monthIndex > 11) return;

            const jenis = (cuti.jenis_cuti || "cuti tahunan").toLowerCase();
            const hari = cuti.jumlah_hari || 0;

            data[monthIndex].totalDays += hari;

            if (jenis.includes("tahunan"))
                data[monthIndex].breakdown.tahunan += hari;
            else if (jenis.includes("melahirkan"))
                data[monthIndex].breakdown.melahirkan += hari;
            else if (jenis.includes("penting"))
                data[monthIndex].breakdown.alasan_penting += hari;
            else if (jenis.includes("besar"))
                data[monthIndex].breakdown.besar += hari;
        });
    }

    // Override totalDays dari backend (jika ada)
    if (sourceChart.length === 12) {
        sourceChart.forEach((val, index) => {
            data[index].totalDays = val;
        });
    }

    return data;
});

const maxDays = computed(() => {
    const max = Math.max(...chartData.value.map((d) => d.totalDays));
    return max > 5 ? max : 5;
});

// Distribusi jenis cuti untuk donut chart (aggregate dari chartData)
const distribusiCuti = computed(() => {
    const totals = {
        tahunan: 0,
        melahirkan: 0,
        alasan_penting: 0,
        besar: 0,
    };

    chartData.value.forEach((d) => {
        totals.tahunan += d.breakdown.tahunan;
        totals.melahirkan += d.breakdown.melahirkan;
        totals.alasan_penting += d.breakdown.alasan_penting;
        totals.besar += d.breakdown.besar;
    });

    const grandTotal =
        totals.tahunan +
        totals.melahirkan +
        totals.alasan_penting +
        totals.besar;

    if (grandTotal === 0) {
        return {
            tahunan: 0,
            melahirkan: 0,
            alasan_penting: 0,
            besar: 0,
            total: 0,
            percentTahunan: 0,
            percentMelahirkan: 0,
            percentPenting: 0,
            percentBesar: 0,
            percentDominant: 0,
            labelDominant: "-",
        };
    }

    const percentTahunan = Math.round((totals.tahunan / grandTotal) * 100);
    const percentMelahirkan = Math.round(
        (totals.melahirkan / grandTotal) * 100,
    );
    const percentPenting = Math.round(
        (totals.alasan_penting / grandTotal) * 100,
    );
    const percentBesar = Math.round((totals.besar / grandTotal) * 100);

    // Ambil jenis dengan proporsi terbesar untuk ditampilkan di pusat donut
    const ranking = [
        { label: "Tahunan", value: percentTahunan },
        { label: "Melahirkan", value: percentMelahirkan },
        { label: "Penting", value: percentPenting },
        { label: "Besar", value: percentBesar },
    ].sort((a, b) => b.value - a.value);

    return {
        ...totals,
        total: grandTotal,
        percentTahunan,
        percentMelahirkan,
        percentPenting,
        percentBesar,
        percentDominant: ranking[0].value,
        labelDominant: ranking[0].label,
    };
});

// CSS conic-gradient untuk donut
const donutGradient = computed(() => {
    const d = distribusiCuti.value;
    if (d.total === 0) {
        return "conic-gradient(#e2e8f0 0% 100%)";
    }
    let start = 0;
    const parts = [];
    if (d.percentTahunan > 0) {
        parts.push(`#34d399 ${start}% ${start + d.percentTahunan}%`);
        start += d.percentTahunan;
    }
    if (d.percentMelahirkan > 0) {
        parts.push(`#f472b6 ${start}% ${start + d.percentMelahirkan}%`);
        start += d.percentMelahirkan;
    }
    if (d.percentPenting > 0) {
        parts.push(`#fb923c ${start}% ${start + d.percentPenting}%`);
        start += d.percentPenting;
    }
    if (d.percentBesar > 0) {
        parts.push(`#60a5fa ${start}% ${start + d.percentBesar}%`);
        start += d.percentBesar;
    }
    if (parts.length === 0) return "conic-gradient(#e2e8f0 0% 100%)";
    return `conic-gradient(${parts.join(", ")})`;
});
// ================= END GRAFIK =================

const upcomingHolidays = computed(() => {
    const todayStr = new Date().toISOString().split("T")[0];
    if (!props.hariLiburs) return [];
    return props.hariLiburs
        .filter((item) => item.tanggal >= todayStr)
        .slice(0, 3);
});

const cutiDisetujuiBulanIniList = computed(() => {
    if (!props.cutiDisetujuiData) return [];
    const currentMonth = new Date().getMonth();
    const currentYr = new Date().getFullYear();
    return props.cutiDisetujuiData.filter((cuti) => {
        const d = new Date(cuti.tanggal_mulai);
        return d.getMonth() === currentMonth && d.getFullYear() === currentYr;
    });
});

const baseDate = ref(new Date());

const startOfWeek = computed(() => {
    const date = new Date(baseDate.value);
    const day = date.getDay();
    const diff = date.getDate() - day + (day === 0 ? -6 : 1);
    return new Date(date.setDate(diff));
});

const currentMonthYear = computed(() => {
    return new Intl.DateTimeFormat("id-ID", {
        month: "long",
        year: "numeric",
    }).format(baseDate.value);
});

const weekRangeLabel = computed(() => {
    const start = startOfWeek.value;
    const end = new Date(start);
    end.setDate(end.getDate() + 6);
    const fmt = (d) =>
        `${d.getDate()} ${new Intl.DateTimeFormat("id-ID", { month: "long" }).format(d)} ${d.getFullYear()}`;
    return `${start.getDate()} - ${end.getDate()} ${new Intl.DateTimeFormat("id-ID", { month: "long" }).format(end)} ${end.getFullYear()}`;
});

const weekDays = computed(() => {
    const daysName = ["SEN", "SEL", "RAB", "KAM", "JUM", "SAB", "MIN"];
    const result = [];
    let current = new Date(startOfWeek.value);
    const todayObj = new Date();
    const todayStr = `${todayObj.getFullYear()}-${String(todayObj.getMonth() + 1).padStart(2, "0")}-${String(todayObj.getDate()).padStart(2, "0")}`;

    for (let i = 0; i < 7; i++) {
        const dateObj = new Date(current);
        const dateStr = `${dateObj.getFullYear()}-${String(dateObj.getMonth() + 1).padStart(2, "0")}-${String(dateObj.getDate()).padStart(2, "0")}`;

        const onLeave = props.cutiDisetujuiData
            ? props.cutiDisetujuiData.filter(
                  (c) =>
                      c.tanggal_mulai <= dateStr &&
                      c.tanggal_selesai >= dateStr,
              )
            : [];

        result.push({
            name: daysName[i],
            dateNumber: dateObj.getDate(),
            fullDate: dateStr,
            isToday: dateStr === todayStr,
            isWeekend: i >= 5,
            onLeave: onLeave,
        });
        current.setDate(current.getDate() + 1);
    }
    return result;
});

const prevWeek = () => {
    const newDate = new Date(baseDate.value);
    newDate.setDate(newDate.getDate() - 7);
    baseDate.value = newDate;
};

const nextWeek = () => {
    const newDate = new Date(baseDate.value);
    newDate.setDate(newDate.getDate() + 7);
    baseDate.value = newDate;
};

const detailModal = ref({ show: false, data: null });
const showTeamModal = ref(false);
const approvedModal = ref({ show: false });

// ================= FITUR REVISI TANGGAL =================
const modeRevisi = ref(false);
const formRevisi = ref({
    tanggal_mulai: "",
    tanggal_selesai: "",
});

const isStatusBisaDirevisi = (item) => {
    return (
        item?.status === "ditangguhkan" ||
        item?.status === "dibatalkan_ditangguhkan"
    );
};

const canRevisiCurrentItem = computed(() =>
    isStatusBisaDirevisi(detailModal.value.data),
);

const openDetailModal = (item) => {
    detailModal.value.data = item;
    detailModal.value.show = true;
    modeRevisi.value = false;
    formRevisi.value.tanggal_mulai = "";
    formRevisi.value.tanggal_selesai = "";
};

const openRevisiModal = (item) => {
    detailModal.value.data = item;
    detailModal.value.show = true;
    modeRevisi.value = true;
    formRevisi.value.tanggal_mulai = "";
    formRevisi.value.tanggal_selesai = "";
};

const closeDetailModal = () => {
    detailModal.value.show = false;
    detailModal.value.data = null;
    modeRevisi.value = false;
    formRevisi.value.tanggal_mulai = "";
    formRevisi.value.tanggal_selesai = "";
};

const activateRevisiMode = () => {
    modeRevisi.value = true;
};

const cancelRevisiMode = () => {
    modeRevisi.value = false;
    formRevisi.value.tanggal_mulai = "";
    formRevisi.value.tanggal_selesai = "";
};

const minDate = computed(() => {
    const today = new Date();
    return today.toISOString().split("T")[0];
});

const jumlahHariKerja = computed(() => {
    if (!formRevisi.value.tanggal_mulai || !formRevisi.value.tanggal_selesai)
        return 0;

    let start = new Date(formRevisi.value.tanggal_mulai);
    let end = new Date(formRevisi.value.tanggal_selesai);
    if (start > end) return 0;

    let count = 0;
    let current = new Date(start);

    while (current <= end) {
        let day = current.getDay();
        if (day !== 0 && day !== 6) count++;
        current.setDate(current.getDate() + 1);
    }
    return count;
});

const isInvalidWeekendOnly = computed(() => {
    if (!formRevisi.value.tanggal_mulai || !formRevisi.value.tanggal_selesai)
        return false;

    let start = new Date(formRevisi.value.tanggal_mulai);
    let end = new Date(formRevisi.value.tanggal_selesai);
    if (start > end) return false;

    let hasWeekday = false;
    let current = new Date(start);

    while (current <= end) {
        let day = current.getDay();
        if (day !== 0 && day !== 6) {
            hasWeekday = true;
            break;
        }
        current.setDate(current.getDate() + 1);
    }
    return !hasWeekday;
});

const submitRevisi = () => {
    if (isInvalidWeekendOnly.value || jumlahHariKerja.value === 0) return;
    if (!detailModal.value.data?.id) return;

    router.post(
        route("karyawan.cuti.revisi", detailModal.value.data.id),
        formRevisi.value,
        {
            preserveScroll: true,
            onSuccess: () => {
                closeDetailModal();
            },
        },
    );
};
// ================= END FITUR REVISI TANGGAL =================

const suspendData = ref({ show: false, id: null, alasan: "" });
const openSuspendModal = (id) => {
    suspendData.value.id = id;
    suspendData.value.alasan = "";
    suspendData.value.show = true;
};
const closeSuspendModal = () => {
    suspendData.value.show = false;
};
const submitSuspend = () => {
    if (!suspendData.value.alasan.trim()) {
        alert("Mohon isi alasan penangguhan terlebih dahulu.");
        return;
    }
    router.post(
        route("admin.cuti.tangguhkan", suspendData.value.id),
        { alasan: suspendData.value.alasan },
        { preserveScroll: true, onSuccess: () => closeSuspendModal() },
    );
};

const showOnLeaveModal = ref(false);
const selectedDateForModal = ref(null);
const openOnLeaveModal = (day) => {
    selectedDateForModal.value = day;
    showOnLeaveModal.value = true;
};
const onLeaveTeamMembers = computed(() => {
    if (!selectedDateForModal.value) return [];
    return selectedDateForModal.value.onLeave;
});

const approveCuti = (id) => {
    if (confirm("Anda yakin ingin MENGESAHKAN pengajuan cuti ini?")) {
        router.post(
            route("atasan.approval.approve", id),
            {},
            { preserveScroll: true },
        );
    }
};
const rejectCuti = (id) => {
    const alasan = prompt("Masukkan alasan penolakan (opsional):");
    if (alasan !== null) {
        router.post(
            route("atasan.approval.reject", id),
            { catatan: alasan },
            { preserveScroll: true },
        );
    }
};

// ================= PERBAIKAN NAMA ATASAN PEMROSES =================
const getNamaAtasanPemroses = (item) => {
    if (!item) return "Kepala Biro Perencanaan";

    if (
        item.status === "ditangguhkan" ||
        item.status === "dibatalkan_ditangguhkan"
    ) {
        return "Kepala Biro Perencanaan";
    }

    return (
        item.dibatalkan_oleh?.nama ||
        item.dibatalkanOleh?.nama ||
        item.ditangguhkan_oleh?.nama ||
        item.ditangguhkanOleh?.nama ||
        item.atasan_l4?.nama ||
        item.atasanL4?.nama ||
        item.atasan_l3?.nama ||
        item.atasanL3?.nama ||
        item.atasan_l2?.nama ||
        item.atasanL2?.nama ||
        item.atasan_l1?.nama ||
        item.atasanL1?.nama ||
        {
            1: "Ketua Tim Kerja",
            2: "Ketua Kelompok Substansi",
            3: "Kasubag TU",
            4: "Kepala Biro Perencanaan",
            6: "Kepala Biro Perencanaan",
        }[item.level_saat_ini] ||
        "Kepala Biro Perencanaan"
    );
};

const getCatatanAtasan = (item) => {
    if (item?.keterangan && item.keterangan.includes("|")) {
        return item.keterangan
            .split("|")[1]
            .replace(/\[.*?:\s*/g, "")
            .replace(/\]/g, "")
            .trim();
    }

    if (item?.alasan_penangguhan) {
        return item.alasan_penangguhan;
    }
    if (item?.alasanPenangguhan) {
        return item.alasanPenangguhan;
    }
    if (item?.catatan_atasan) {
        return item.catatan_atasan;
    }

    const responses = {
        disetujui: "Disetujui dan diteruskan sesuai alur birokrasi.",
        ditolak: "Pengajuan ditolak oleh atasan.",
        ditangguhkan: "Cuti ditangguhkan oleh atasan.",
        dibatalkan_ditangguhkan: "Cuti ditangguhkan oleh atasan.",
    };

    return responses[item?.status] || "Diproses tanpa catatan tambahan.";
};

const isStatusDitangguhkan = (item) =>
    item?.status === "ditangguhkan" ||
    item?.status === "dibatalkan_ditangguhkan";

const getApprovalLogs = (item) => {
    const logs = item?.approval_logs || item?.approvalLogs || [];
    if (logs.length) return logs;

    const legacyLogs = [];
    if (item?.atasanL1?.nama || item?.atasan_l1?.nama) {
        legacyLogs.push({
            id: `legacy-l1-${item.id}`,
            level_approval: 1,
            approver: item.atasanL1 || item.atasan_l1,
            keputusan: "setuju",
        });
    }
    if (item?.atasanL3?.nama || item?.atasan_l3?.nama) {
        legacyLogs.push({
            id: `legacy-l3-${item.id}`,
            level_approval: 3,
            approver: item.atasanL3 || item.atasan_l3,
            keputusan: "setuju",
        });
    }
    if (item?.atasanL4?.nama || item?.atasan_l4?.nama) {
        legacyLogs.push({
            id: `legacy-l4-${item.id}`,
            level_approval: 4,
            approver: item.atasanL4 || item.atasan_l4,
            keputusan: "setuju",
        });
    }
    return legacyLogs;
};

const getApprovalLevelLabel = (level) =>
    ({
        1: "L1 - Ketua Tim Kerja",
        2: "L2 - Ketua Kelompok Substansi",
        3: "L3 - Kasubag TU",
        4: "L4 - Kepala Biro Perencanaan",
    })[level] || `Level ${level}`;
</script>

<template>
    <Head title="Dashboard" />

    <MainLayout>
        <div class="relative space-y-5 max-w-7xl mx-auto pb-10 pt-1 px-1">
            <!-- Visual dashboard dari aset publik tanpa mengubah data atau navigasi. -->
            <div
                class="relative overflow-hidden rounded-2xl border border-emerald-100 bg-emerald-950 shadow-sm min-h-[230px] md:min-h-[260px]"
            >
                <img
                    src="/images/gedung.png"
                    alt="Ilustrasi pertanian digital"
                    class="absolute inset-0 h-full w-full object-cover object-center opacity-75"
                />
                <div
                    class="absolute inset-0 bg-gradient-to-r from-emerald-950/90 via-emerald-900/55 to-transparent"
                ></div>
                <div class="relative max-w-2xl px-5 py-10 pr-32 md:px-8 md:py-14 md:pr-8">
                    <p
                        class="text-[10px] font-extrabold uppercase tracking-[0.2em] text-emerald-200"
                    >
                        AgriLeave Digital
                    </p>
                    <h1
                        class="mt-3 text-2xl font-black leading-tight text-white md:text-3xl"
                    >
                        Selamat datang, {{ user.nama }}
                    </h1>
                    <p class="mt-2 text-lg font-black leading-tight text-white md:text-xl">
                        Kelola cuti dengan lebih teratur
                    </p>
                    <p class="mt-2 max-w-xl text-sm font-medium leading-relaxed text-emerald-50/85 md:text-base">
                        {{ headerSubtitle }}
                    </p>
                    <p class="mt-1 max-w-xl text-xs font-medium leading-relaxed text-emerald-50/75 md:text-sm">
                        Pantau ketersediaan, pengajuan, dan aktivitas tim dari satu dashboard.
                    </p>
                </div>
                <div
                    v-if="[1, 2, 3, 4, 6].includes(user.role_id)"
                    class="absolute right-5 top-1/2 -translate-y-1/2 md:right-8"
                >
                    <Link
                        :href="route('karyawan.ajukan')"
                        class="inline-flex items-center gap-2 rounded-xl bg-white/95 px-3.5 py-2.5 text-[11px] font-extrabold uppercase tracking-wider text-emerald-800 shadow-lg shadow-emerald-950/20 transition hover:bg-white hover:text-emerald-600"
                    >
                        <svg
                            class="h-4 w-4"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M12 4v16m8-8H4"
                            ></path>
                        </svg>
                        Ajukan Cuti
                    </Link>
                </div>
            </div>

            <!-- TAB SWITCHER (hanya atasan role 2,3,4,6) -->
            <div
                v-if="[2, 3, 4, 6].includes(user.role_id)"
                class="flex items-center justify-between bg-white border border-slate-100 rounded-2xl px-4 py-3 shadow-sm"
            >
                <div>
                    <h2 class="text-sm font-extrabold text-slate-800">
                        {{
                            activeTab === "pribadi"
                                ? "Ringkasan Cuti Pribadi"
                                : "Ringkasan Cuti Tim"
                        }}
                    </h2>
                    <p class="text-[11px] font-medium text-slate-500 mt-0.5">
                        Kelola informasi cuti dan pantau aktivitas bawahan.
                    </p>
                </div>
                <div
                    class="bg-slate-50 p-1 rounded-xl inline-flex border border-slate-100"
                >
                    <button
                        @click="activeTab = 'pribadi'"
                        :class="
                            activeTab === 'pribadi'
                                ? 'bg-white shadow-sm text-brand-700'
                                : 'text-slate-500 hover:text-slate-700'
                        "
                        class="px-4 py-1.5 rounded-lg text-xs font-bold transition-all flex items-center gap-1.5 cursor-pointer"
                    >
                        <img
                            src="/images/icon-pribadi.png"
                            alt=""
                            aria-hidden="true"
                            class="w-4 h-4 object-contain"
                        />
                        Pribadi
                    </button>
                    <button
                        @click="activeTab = 'tim'"
                        :class="
                            activeTab === 'tim'
                                ? 'bg-white shadow-sm text-blue-700'
                                : 'text-slate-500 hover:text-slate-700'
                        "
                        class="px-4 py-1.5 rounded-lg text-xs font-bold transition-all flex items-center gap-1.5 cursor-pointer"
                    >
                        <img
                            src="/images/icon-tim.png"
                            alt=""
                            aria-hidden="true"
                            class="w-4 h-4 object-contain"
                        />
                        Tim / Bawahan
                    </button>
                </div>
            </div>

            <!-- 1. WIDGET STATISTIK RINGKASAN PRIBADI (role 1 atau tab pribadi) -->
            <!-- Icon KPI diperbesar (w-20/24) dan digeser ke pojok kanan-bawah agar
                 sedikit "bleed" keluar kartu (overflow-hidden di parent menjaga
                 sudutnya tetap rapi mengikuti rounded-2xl). Teks diberi z-10 supaya
                 tetap berada di atas icon. -->
            <div
                v-show="
                    !isAdminHR &&
                    (user.role_id === 1 || activeTab === 'pribadi')
                "
                class="grid grid-cols-2 md:grid-cols-4 gap-3"
            >
                <div
                    class="relative bg-white border border-slate-100 rounded-2xl p-4 shadow-sm flex flex-col justify-between group hover:shadow-md transition-all overflow-hidden"
                >
                    <img
                        src="/images/kalender-3d.png"
                        alt=""
                        aria-hidden="true"
                        class="absolute -right-3 -bottom-3 w-20 h-20 md:w-24 md:h-24 object-contain opacity-90 pointer-events-none select-none transition-transform duration-300 group-hover:scale-105"
                    />
                    <p
                        class="relative z-10 text-[10px] font-extrabold text-brand-600/70 uppercase tracking-widest mb-2"
                    >
                        Jatah Cuti {{ currentYear }}
                    </p>
                    <div class="relative z-10 flex items-baseline gap-1">
                        <p class="text-3xl font-black text-slate-800">
                            {{ stats.kuota_tahunan || 12 }}
                        </p>
                        <p class="text-xs font-bold text-slate-400">Hari</p>
                    </div>
                    <p class="relative z-10 text-[11px] text-slate-400 mt-1 font-medium">
                        Maksimum {{ stats.kuota_tahunan || 12 }} hari
                    </p>
                </div>

                <div
                    class="relative bg-white border border-slate-100 rounded-2xl p-4 shadow-sm flex flex-col justify-between group hover:shadow-md transition-all overflow-hidden"
                >
                    <img
                        src="/images/jam-3d.png"
                        alt=""
                        aria-hidden="true"
                        class="absolute -right-3 -bottom-3 w-20 h-20 md:w-24 md:h-24 object-contain opacity-90 pointer-events-none select-none transition-transform duration-300 group-hover:scale-105"
                    />
                    <p
                        class="relative z-10 text-[10px] font-extrabold text-orange-600/70 uppercase tracking-widest mb-2"
                    >
                        Sisa Tahun Kemarin
                    </p>
                    <div class="relative z-10 flex items-baseline gap-1">
                        <p class="text-3xl font-black text-slate-800">
                            {{ stats.sisa_cuti_tahun_lalu || 0 }}
                        </p>
                        <p class="text-xs font-bold text-slate-400">Hari</p>
                    </div>
                    <p class="relative z-10 text-[11px] text-slate-400 mt-1 font-medium">
                        Sisa Tahun N-1
                    </p>
                </div>

                <div
                    class="relative bg-white border border-slate-100 rounded-2xl p-4 shadow-sm flex flex-col justify-between group hover:shadow-md transition-all overflow-hidden"
                >
                    <img
                        src="/images/ceklis-3d.png"
                        alt=""
                        aria-hidden="true"
                        class="absolute -right-3 -bottom-3 w-20 h-20 md:w-24 md:h-24 object-contain opacity-90 pointer-events-none select-none transition-transform duration-300 group-hover:scale-105"
                    />
                    <p
                        class="relative z-10 text-[10px] font-extrabold text-rose-600/70 uppercase tracking-widest mb-2"
                    >
                        Cuti Terpakai
                    </p>
                    <div class="relative z-10 flex items-baseline gap-1">
                        <p class="text-3xl font-black text-slate-800">
                            {{ cutiTahunanTerpakai }}
                        </p>
                        <p class="text-xs font-bold text-slate-400">Hari</p>
                    </div>
                    <p class="relative z-10 text-[11px] text-slate-400 mt-1 font-medium">
                        Khusus Cuti Tahunan
                    </p>
                </div>

                <div
                    class="relative bg-white border border-slate-100 rounded-2xl p-4 shadow-sm flex flex-col justify-between group hover:shadow-md transition-all overflow-hidden"
                >
                    <img
                        src="/images/koin-3d.png"
                        alt=""
                        aria-hidden="true"
                        class="absolute -right-3 -bottom-3 w-20 h-20 md:w-24 md:h-24 object-contain opacity-90 pointer-events-none select-none transition-transform duration-300 group-hover:scale-105"
                    />
                    <p
                        class="relative z-10 text-[10px] font-extrabold text-emerald-600/70 uppercase tracking-widest mb-2"
                    >
                        Total Cuti Tersedia
                    </p>
                    <div class="relative z-10 flex items-baseline gap-1">
                        <p class="text-3xl font-black text-slate-800">
                            {{ sisaCutiTersedia }}
                        </p>
                        <p class="text-xs font-bold text-slate-400">Hari</p>
                    </div>
                    <p class="relative z-10 text-[11px] text-slate-400 mt-1 font-medium">
                        Siap Dipakai Kapan Saja
                    </p>
                </div>
            </div>

            <!-- 2. WIDGET STATISTIK RINGKASAN TIM (compact seperti screenshot) -->
            <div
                v-show="
                    activeTab === 'tim' &&
                    [2, 3, 4, 5, 6].includes(user.role_id)
                "
                class="grid grid-cols-1 md:grid-cols-3 gap-4"
            >
                <!-- Perlu Persetujuan -->
                <Link
                    :href="route('atasan.approval')"
                    class="relative overflow-hidden bg-white border border-slate-200/80 rounded-2xl px-5 py-5 shadow-[0_2px_12px_rgba(15,23,42,0.04)] ring-1 ring-slate-100 flex flex-col justify-between hover:shadow-[0_8px_24px_rgba(15,23,42,0.08)] hover:-translate-y-0.5 transition-all duration-200 cursor-pointer group min-h-[110px]"
                >
                    <div
                        class="absolute left-0 top-0 bottom-0 w-1 bg-gradient-to-b from-amber-400 to-orange-400 rounded-l-2xl"
                    ></div>
                    <img
                        src="/images/icon-dokumen.png"
                        alt=""
                        aria-hidden="true"
                        class="absolute -right-3 -bottom-3 w-20 h-20 md:w-24 md:h-24 object-contain opacity-90 pointer-events-none select-none transition-transform duration-300 group-hover:scale-105"
                    />
                    <div class="relative z-10 min-w-0 flex-1">
                        <p
                            class="text-[11px] font-bold text-slate-400 uppercase tracking-wider"
                        >
                            Perlu Persetujuan
                        </p>
                        <p
                            class="text-3xl font-black text-slate-800 leading-none mt-1.5 tracking-tight"
                        >
                            {{
                                stats.total_antrean ??
                                stats.pengajuan_menunggu ??
                                0
                            }}
                        </p>
                        <p
                            class="text-[11px] font-medium text-amber-600/80 mt-1"
                        >
                            Antrean menunggu
                        </p>
                    </div>
                </Link>

                <!-- Disetujui Bulan Ini -->
                <div
                    @click="approvedModal.show = true"
                    class="relative overflow-hidden bg-white border border-slate-200/80 rounded-2xl px-5 py-5 shadow-[0_2px_12px_rgba(15,23,42,0.04)] ring-1 ring-slate-100 flex flex-col justify-between hover:shadow-[0_8px_24px_rgba(15,23,42,0.08)] hover:-translate-y-0.5 transition-all duration-200 cursor-pointer group min-h-[110px]"
                >
                    <div
                        class="absolute left-0 top-0 bottom-0 w-1 bg-gradient-to-b from-emerald-400 to-teal-400 rounded-l-2xl"
                    ></div>
                    <img
                        src="/images/ceklis-3d.png"
                        alt=""
                        aria-hidden="true"
                        class="absolute -right-3 -bottom-3 w-20 h-20 md:w-24 md:h-24 object-contain opacity-90 pointer-events-none select-none transition-transform duration-300 group-hover:scale-105"
                    />
                    <div class="relative z-10 min-w-0 flex-1">
                        <p
                            class="text-[11px] font-bold text-slate-400 uppercase tracking-wider"
                        >
                            Disetujui Bulan Ini
                        </p>
                        <p
                            class="text-3xl font-black text-slate-800 leading-none mt-1.5 tracking-tight"
                        >
                            {{
                                stats.cuti_tim_bulan_ini ??
                                stats.pengajuan_disetujui ??
                                0
                            }}
                        </p>
                        <p
                            class="text-[11px] font-medium text-emerald-600/80 mt-1"
                        >
                            Pengajuan disetujui
                        </p>
                    </div>
                </div>

                <!-- Anggota Tim Hadir -->
                <div
                    @click="showTeamModal = true"
                    class="relative overflow-hidden bg-white border border-slate-200/80 rounded-2xl px-5 py-5 shadow-[0_2px_12px_rgba(15,23,42,0.04)] ring-1 ring-slate-100 flex flex-col justify-between hover:shadow-[0_8px_24px_rgba(15,23,42,0.08)] hover:-translate-y-0.5 transition-all duration-200 cursor-pointer group min-h-[110px]"
                >
                    <div
                        class="absolute left-0 top-0 bottom-0 w-1 bg-gradient-to-b from-sky-400 to-blue-500 rounded-l-2xl"
                    ></div>
                    <img
                        src="/images/icon-tim-bawahan.png"
                        alt=""
                        aria-hidden="true"
                        class="absolute -right-3 -bottom-3 w-20 h-20 md:w-24 md:h-24 object-contain opacity-90 pointer-events-none select-none transition-transform duration-300 group-hover:scale-105"
                    />
                    <div class="relative z-10 min-w-0 flex-1">
                        <p
                            class="text-[11px] font-bold text-slate-400 uppercase tracking-wider"
                        >
                            Anggota Tim Hadir
                        </p>
                        <p
                            class="text-3xl font-black text-slate-800 leading-none mt-1.5 tracking-tight"
                        >
                            {{ hadirHariIniCount
                            }}<span class="text-xl font-bold text-slate-400"
                                >/{{ totalAnggota }}</span
                            >
                        </p>
                        <p class="text-[11px] font-medium text-sky-600/80 mt-1">
                            {{ cutiHariIniCount }} orang cuti hari ini
                        </p>
                    </div>
                </div>
            </div>

            <!-- TABEL RIWAYAT / AKTIVITAS TERBARU -->
            <div
                class="bg-white border border-slate-100 rounded-2xl shadow-sm overflow-hidden"
            >
                <div
                    class="px-5 py-3.5 border-b border-slate-100 flex justify-between items-center"
                >
                    <h3 class="text-sm font-extrabold text-slate-800">
                        {{
                            !isAdminHR &&
                            (user.role_id === 1 || activeTab === "pribadi")
                                ? "Riwayat Pengajuan Terbaru"
                                : "Aktivitas Cuti Tim Terbaru"
                        }}
                    </h3>
                    <Link
                        :href="
                            user.role_id === 1
                                ? route('karyawan.riwayat')
                                : user.role_id === 5
                                  ? route('admin.rekap')
                                  : route('atasan.approval')
                        "
                        class="text-[11px] font-extrabold text-brand-600 hover:text-brand-700 transition uppercase tracking-wider"
                    >
                        Lihat Semua
                    </Link>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full">
                        <thead>
                            <tr class="border-b border-slate-50 bg-slate-50/50">
                                <th
                                    v-if="user.role_id !== 1"
                                    class="px-5 py-3 text-left text-[10px] font-extrabold text-slate-400 uppercase tracking-widest"
                                >
                                    Nama Pegawai
                                </th>
                                <th
                                    class="px-5 py-3 text-left text-[10px] font-extrabold text-slate-400 uppercase tracking-widest"
                                >
                                    Jenis Cuti
                                </th>
                                <th
                                    class="px-5 py-3 text-left text-[10px] font-extrabold text-slate-400 uppercase tracking-widest"
                                >
                                    Tanggal Cuti
                                </th>
                                <th
                                    class="px-5 py-3 text-left text-[10px] font-extrabold text-slate-400 uppercase tracking-widest"
                                >
                                    Durasi
                                </th>
                                <th
                                    class="px-5 py-3 text-left text-[10px] font-extrabold text-slate-400 uppercase tracking-widest"
                                >
                                    Status
                                </th>
                                <th
                                    class="px-5 py-3 text-left text-[10px] font-extrabold text-slate-400 uppercase tracking-widest"
                                >
                                    Keterangan
                                </th>
                                <th
                                    class="px-5 py-3 text-center text-[10px] font-extrabold text-slate-400 uppercase tracking-widest"
                                >
                                    Aksi
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <tr
                                v-for="item in displayedRecentCuti"
                                :key="item.id"
                                class="hover:bg-slate-50/60 transition-colors cursor-pointer"
                                @click="openDetailModal(item)"
                            >
                                <td
                                    v-if="user.role_id !== 1"
                                    class="px-5 py-3.5 whitespace-nowrap text-sm font-bold text-slate-800"
                                >
                                    {{ item.pegawai?.nama ?? "-" }}
                                </td>
                                <td class="px-5 py-3.5 whitespace-nowrap">
                                    <span
                                        class="px-2.5 py-1 bg-indigo-50 text-indigo-600 border border-indigo-100 rounded-lg text-[11px] font-bold"
                                    >
                                        {{ item.jenis_cuti ?? "Cuti Tahunan" }}
                                    </span>
                                </td>
                                <td
                                    class="px-5 py-3.5 whitespace-nowrap text-xs text-slate-600 font-semibold"
                                >
                                    {{ formatDate(item.tanggal_mulai) }} -
                                    {{ formatDate(item.tanggal_selesai) }}
                                </td>
                                <td
                                    class="px-5 py-3.5 whitespace-nowrap text-xs font-semibold text-slate-600"
                                >
                                    {{ item.jumlah_hari }} Hari
                                </td>
                                <td class="px-5 py-3.5 whitespace-nowrap">
                                    <span
                                        class="px-3 py-1 inline-flex text-[10px] font-extrabold rounded-lg uppercase tracking-wider"
                                        :class="formatStatus(item.status).class"
                                    >
                                        {{ formatStatus(item.status).text }}
                                    </span>
                                </td>
                                <td
                                    class="px-5 py-3.5 text-xs font-medium text-slate-500 max-w-[140px] truncate"
                                    :title="item.keterangan"
                                >
                                    {{
                                        item.keterangan
                                            ? item.keterangan
                                                  .split("|")[0]
                                                  .trim()
                                            : "-"
                                    }}
                                </td>
                                <td
                                    class="px-5 py-3.5 whitespace-nowrap text-center"
                                    @click.stop
                                >
                                    <button
                                        type="button"
                                        @click.prevent="openDetailModal(item)"
                                        class="px-3.5 py-1.5 bg-slate-800 hover:bg-slate-900 text-white rounded-lg text-[10px] font-extrabold uppercase tracking-widest transition shadow-sm"
                                        title="Lihat Detail & Catatan"
                                    >
                                        DETAIL
                                    </button>
                                </td>
                            </tr>
                            <!--
                                FIX: sebelumnya mengecek `recentCuti` mentah (props),
                                padahal yang dirender di v-for adalah `displayedRecentCuti`
                                (computed). Untuk role Karyawan yang sedang di tab "pribadi",
                                keduanya bisa berbeda sumber datanya, jadi pesan
                                "belum ada aktivitas" bisa salah muncul/tidak muncul.
                                Sekarang dicek dari sumber data yang sama dengan yang dirender.
                            -->
                            <tr
                                v-if="
                                    !displayedRecentCuti ||
                                    displayedRecentCuti.length === 0
                                "
                            >
                                <td
                                    :colspan="user.role_id !== 1 ? 7 : 6"
                                    class="px-5 py-12 text-center text-slate-400"
                                >
                                    <p class="text-sm font-semibold">
                                        Belum ada aktivitas pengajuan cuti.
                                    </p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- BAGIAN BAWAH: GRAFIK + DONUT + HARI LIBUR + KETERSEDIAAN -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <!-- GRAFIK STACKED BAR + DONUT (span 2) -->
                <div
                    class="bg-white border border-slate-100 rounded-2xl p-5 shadow-sm lg:col-span-2"
                >
                    <div class="flex justify-between items-start mb-5">
                        <div>
                            <h3 class="text-sm font-extrabold text-slate-800">
                                {{
                                    !isAdminHR &&
                                    (user.role_id === 1 ||
                                        activeTab === "pribadi")
                                        ? "Total Ketidakhadiran"
                                        : "Total Absensi Tim"
                                }}
                            </h3>
                            <p class="text-[11px] text-slate-500 font-medium">
                                Jumlah hari cuti per bulan, per jenis.
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <!--
                                TAMBAHAN: "Filter Divisi"
                                - Kolom DB yang dipakai: `pegawais.departemen`
                                  (label form: "Divisi/Departemen"), BUKAN
                                  `pegawais.divisi` (label form: "Tim Kerja").
                                - Hanya tampil untuk role yang punya konteks
                                  tim: Atasan L1-L4 & Admin HR. Karyawan biasa
                                  (role_id 1) tidak melihat dropdown ini karena
                                  dashboardnya hanya menampilkan data pribadi.
                                - Berfungsi sebagai drill-down: untuk Atasan,
                                  hasil query sudah otomatis dibatasi ke
                                  departemen mereka sendiri; filter ini hanya
                                  mempersempit lebih lanjut jika diperlukan.
                                  Untuk Admin HR yang defaultnya melihat semua
                                  departemen, filter ini yang paling terasa
                                  manfaatnya.
                            -->
                            <select
                                v-if="[2, 3, 4, 5, 6].includes(user.role_id)"
                                v-model="selectedDivisi"
                                @change="applyDivisiFilter"
                                class="text-[11px] font-bold border border-slate-200 rounded-lg text-slate-600 focus:ring-brand-500 focus:border-brand-500 py-1.5 pl-3 pr-8 bg-white cursor-pointer"
                            >
                                <option :value="null">Semua Divisi</option>
                                <option
                                    v-for="d in listDivisi"
                                    :key="d"
                                    :value="d"
                                >
                                    {{ d }}
                                </option>
                            </select>
                            <select
                                class="text-[11px] font-bold border border-slate-200 rounded-lg text-slate-600 focus:ring-brand-500 focus:border-brand-500 py-1.5 pl-3 pr-8 bg-white cursor-pointer"
                            >
                                <option :value="currentYear">
                                    Tahun {{ currentYear }}
                                </option>
                                <option :value="currentYear - 1">
                                    Tahun {{ currentYear - 1 }}
                                </option>
                            </select>
                        </div>
                    </div>

                    <div class="flex flex-col md:flex-row gap-4">
                        <!-- Kotak Grafik Batang (Stacked Bar) -->
                        <div
                            class="flex-1 min-w-0 rounded-2xl border border-slate-200/80 bg-white p-4 shadow-[0_2px_12px_rgba(15,23,42,0.04)] ring-1 ring-slate-100"
                        >
                            <p
                                class="text-[10px] font-extrabold text-slate-500 uppercase tracking-widest mb-3"
                            >
                                Absensi per Bulan
                            </p>
                            <div
                                class="relative h-44 flex items-end justify-between gap-1 sm:gap-1.5"
                            >
                                <div
                                    class="absolute inset-0 flex flex-col justify-between pb-6 opacity-15 pointer-events-none"
                                >
                                    <div
                                        class="border-t border-dashed border-slate-300 w-full"
                                    ></div>
                                    <div
                                        class="border-t border-dashed border-slate-300 w-full"
                                    ></div>
                                    <div
                                        class="border-t border-dashed border-slate-300 w-full"
                                    ></div>
                                    <div
                                        class="border-t border-dashed border-slate-300 w-full"
                                    ></div>
                                </div>

                                <div
                                    v-for="(data, index) in chartData"
                                    :key="index"
                                    class="relative flex flex-col items-center flex-1 h-full justify-end cursor-pointer z-10"
                                    @click="toggleChartTooltip(index)"
                                >
                                    <div
                                        class="w-full max-w-[28px] sm:max-w-[32px] flex flex-col-reverse rounded-t-md overflow-hidden transition-all duration-300 relative"
                                        :class="
                                            data.totalDays === 0
                                                ? 'bg-transparent'
                                                : 'bg-slate-100'
                                        "
                                        :style="`height: ${(data.totalDays / maxDays) * 100}%`"
                                    >
                                        <div
                                            v-if="data.breakdown.tahunan > 0"
                                            class="w-full bg-emerald-400"
                                            :style="`height: ${(data.breakdown.tahunan / data.totalDays) * 100}%`"
                                        ></div>
                                        <div
                                            v-if="data.breakdown.melahirkan > 0"
                                            class="w-full bg-pink-400"
                                            :style="`height: ${(data.breakdown.melahirkan / data.totalDays) * 100}%`"
                                        ></div>
                                        <div
                                            v-if="
                                                data.breakdown.alasan_penting >
                                                0
                                            "
                                            class="w-full bg-orange-400"
                                            :style="`height: ${(data.breakdown.alasan_penting / data.totalDays) * 100}%`"
                                        ></div>
                                        <div
                                            v-if="data.breakdown.besar > 0"
                                            class="w-full bg-blue-400"
                                            :style="`height: ${(data.breakdown.besar / data.totalDays) * 100}%`"
                                        ></div>
                                    </div>

                                    <!-- Tooltip klik -->
                                    <div
                                        v-if="data.totalDays > 0"
                                        class="absolute bottom-full mb-2 left-1/2 -translate-x-1/2 bg-slate-800 text-white py-1.5 px-2.5 rounded-lg shadow-xl pointer-events-none flex flex-col gap-0.5 items-center min-w-[110px] transition-all duration-150 text-[10px]"
                                        :class="
                                            activeChartIndex === index
                                                ? 'opacity-100 visible z-50'
                                                : 'opacity-0 invisible -z-10'
                                        "
                                    >
                                        <span
                                            class="font-black text-center border-b border-slate-600 pb-1 mb-0.5 w-full"
                                            >Total:
                                            {{ data.totalDays }} Hari</span
                                        >
                                        <span
                                            v-if="data.breakdown.tahunan > 0"
                                            class="text-emerald-300 w-full text-left font-bold"
                                            >Tahunan:
                                            {{ data.breakdown.tahunan }}</span
                                        >
                                        <span
                                            v-if="data.breakdown.melahirkan > 0"
                                            class="text-pink-300 w-full text-left font-bold"
                                            >Melahirkan:
                                            {{
                                                data.breakdown.melahirkan
                                            }}</span
                                        >
                                        <span
                                            v-if="
                                                data.breakdown.alasan_penting >
                                                0
                                            "
                                            class="text-orange-300 w-full text-left font-bold"
                                            >Penting:
                                            {{
                                                data.breakdown.alasan_penting
                                            }}</span
                                        >
                                        <span
                                            v-if="data.breakdown.besar > 0"
                                            class="text-blue-300 w-full text-left font-bold"
                                            >Besar:
                                            {{ data.breakdown.besar }}</span
                                        >
                                    </div>

                                    <span
                                        class="text-[10px] font-bold text-slate-400 mt-2 absolute bottom-0 translate-y-full"
                                        >{{ data.month }}</span
                                    >
                                </div>
                            </div>
                            <div class="h-6"></div>

                            <!-- Legend -->
                            <div
                                class="flex flex-wrap justify-center gap-x-4 gap-y-1 mt-1"
                            >
                                <div class="flex items-center gap-1.5">
                                    <div
                                        class="w-2.5 h-2.5 rounded-sm bg-emerald-400"
                                    ></div>
                                    <span
                                        class="text-[10px] font-bold text-slate-500"
                                        >Tahunan</span
                                    >
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <div
                                        class="w-2.5 h-2.5 rounded-sm bg-pink-400"
                                    ></div>
                                    <span
                                        class="text-[10px] font-bold text-slate-500"
                                        >Melahirkan</span
                                    >
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <div
                                        class="w-2.5 h-2.5 rounded-sm bg-orange-400"
                                    ></div>
                                    <span
                                        class="text-[10px] font-bold text-slate-500"
                                        >Penting</span
                                    >
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <div
                                        class="w-2.5 h-2.5 rounded-sm bg-blue-400"
                                    ></div>
                                    <span
                                        class="text-[10px] font-bold text-slate-500"
                                        >Besar</span
                                    >
                                </div>
                            </div>
                        </div>

                        <!-- Kotak Donut Distribusi Jenis Cuti -->
                        <div
                            class="w-full md:w-[200px] shrink-0 flex flex-col items-center justify-center rounded-2xl border border-slate-200/80 bg-white p-4 shadow-[0_2px_12px_rgba(15,23,42,0.04)] ring-1 ring-slate-100"
                        >
                            <p
                                class="text-[10px] font-extrabold text-slate-500 uppercase tracking-widest mb-3 self-start"
                            >
                                Distribusi
                            </p>
                            <div
                                class="relative w-28 h-28 rounded-full flex items-center justify-center"
                                :style="{ background: donutGradient }"
                            >
                                <div
                                    class="absolute w-[68px] h-[68px] rounded-full bg-white flex flex-col items-center justify-center shadow-sm ring-1 ring-slate-100"
                                >
                                    <span
                                        class="text-lg font-black text-slate-800 leading-none"
                                        >{{
                                            distribusiCuti.percentDominant || 0
                                        }}%</span
                                    >
                                    <span
                                        class="text-[8px] font-bold text-slate-400 mt-0.5 uppercase tracking-wide"
                                        >{{
                                            distribusiCuti.labelDominant || ""
                                        }}</span
                                    >
                                </div>
                            </div>
                            <p
                                class="text-[11px] font-extrabold text-slate-700 mt-3"
                            >
                                Distribusi Jenis Cuti
                            </p>
                            <div class="mt-2 space-y-1 w-full">
                                <div
                                    class="flex items-center justify-between text-[10px] px-1 py-0.5"
                                >
                                    <span
                                        class="flex items-center gap-1.5 font-semibold text-slate-500"
                                    >
                                        <span
                                            class="w-2 h-2 rounded-full bg-emerald-400"
                                        ></span>
                                        Tahunan
                                    </span>
                                    <span class="font-bold text-slate-700"
                                        >{{
                                            distribusiCuti.percentTahunan
                                        }}%</span
                                    >
                                </div>
                                <div
                                    class="flex items-center justify-between text-[10px] px-1 py-0.5"
                                >
                                    <span
                                        class="flex items-center gap-1.5 font-semibold text-slate-500"
                                    >
                                        <span
                                            class="w-2 h-2 rounded-full bg-pink-400"
                                        ></span>
                                        Melahirkan
                                    </span>
                                    <span class="font-bold text-slate-700"
                                        >{{
                                            distribusiCuti.percentMelahirkan
                                        }}%</span
                                    >
                                </div>
                                <div
                                    class="flex items-center justify-between text-[10px] px-1 py-0.5"
                                >
                                    <span
                                        class="flex items-center gap-1.5 font-semibold text-slate-500"
                                    >
                                        <span
                                            class="w-2 h-2 rounded-full bg-orange-400"
                                        ></span>
                                        Penting
                                    </span>
                                    <span class="font-bold text-slate-700"
                                        >{{
                                            distribusiCuti.percentPenting
                                        }}%</span
                                    >
                                </div>
                                <div
                                    class="flex items-center justify-between text-[10px] px-1 py-0.5"
                                >
                                    <span
                                        class="flex items-center gap-1.5 font-semibold text-slate-500"
                                    >
                                        <span
                                            class="w-2 h-2 rounded-full bg-blue-400"
                                        ></span>
                                        Besar
                                    </span>
                                    <span class="font-bold text-slate-700"
                                        >{{
                                            distribusiCuti.percentBesar
                                        }}%</span
                                    >
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- KANAN: Hari Libur + Ketersediaan Tim -->
                <div class="flex flex-col gap-4">
                    <!-- Hari Libur Terdekat -->
                    <div
                        class="bg-white border border-slate-100 rounded-2xl p-5 shadow-sm flex-1"
                    >
                        <h3
                            class="text-sm font-extrabold text-slate-800 mb-3 flex items-center gap-2"
                        >
                            <img
                                src="/images/kalender-3d.png"
                                alt=""
                                aria-hidden="true"
                                class="w-7 h-7 object-contain"
                            />
                            Hari Libur Terdekat
                        </h3>
                        <div class="space-y-2.5">
                            <div
                                v-for="libur in upcomingHolidays"
                                :key="libur.id"
                                class="flex items-center gap-3 p-2.5 rounded-xl bg-slate-50/80 border border-slate-100 hover:bg-slate-50 transition"
                            >
                                <div
                                    class="w-9 h-9 rounded-lg bg-blue-50 flex items-center justify-center shrink-0 overflow-hidden"
                                >
                                    <img
                                        src="/images/kalender-2d.png"
                                        alt=""
                                        aria-hidden="true"
                                        class="w-6 h-6 object-contain"
                                    />
                                </div>
                                <div class="min-w-0">
                                    <h4
                                        class="text-xs font-bold text-slate-800 truncate"
                                    >
                                        {{ libur.keterangan }}
                                    </h4>
                                    <p
                                        class="text-[11px] text-slate-400 mt-0.5 font-medium"
                                    >
                                        {{ formatDate(libur.tanggal) }}
                                    </p>
                                </div>
                            </div>
                            <div
                                v-if="upcomingHolidays.length === 0"
                                class="text-center py-4 text-xs font-medium text-slate-400"
                            >
                                Belum ada jadwal libur terdekat.
                            </div>
                        </div>
                    </div>

                    <!-- Ketersediaan Tim Minggu Ini (compact) -->
                    <div
                        v-if="[2, 3, 4, 5, 6].includes(user.role_id)"
                        class="bg-white border border-slate-100 rounded-2xl p-5 shadow-sm"
                    >
                        <div
                            class="flex items-center justify-between mb-3 gap-2"
                        >
                            <div>
                                <h3
                                    class="text-sm font-extrabold text-slate-800 cursor-pointer hover:text-blue-600 transition"
                                    @click="showTeamModal = true"
                                >
                                    Ketersediaan Tim
                                </h3>
                                <p
                                    class="text-[11px] font-medium text-slate-400 mt-0.5"
                                >
                                    {{ weekRangeLabel }}
                                </p>
                            </div>
                            <div class="flex gap-1">
                                <button
                                    @click="prevWeek"
                                    class="p-1.5 bg-slate-50 hover:bg-slate-100 border border-slate-100 rounded-lg text-slate-500 transition cursor-pointer"
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
                                            stroke-width="2.5"
                                            d="M15 19l-7-7 7-7"
                                        ></path>
                                    </svg>
                                </button>
                                <button
                                    @click="nextWeek"
                                    class="p-1.5 bg-slate-50 hover:bg-slate-100 border border-slate-100 rounded-lg text-slate-500 transition cursor-pointer"
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
                                            stroke-width="2.5"
                                            d="M9 5l7 7-7 7"
                                        ></path>
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <div class="grid grid-cols-7 gap-1">
                            <div
                                v-for="day in weekDays"
                                :key="day.fullDate"
                                class="flex flex-col items-center"
                            >
                                <div
                                    class="text-[9px] font-extrabold mb-1 uppercase tracking-wider"
                                    :class="
                                        day.isToday
                                            ? 'text-brand-600'
                                            : 'text-slate-400'
                                    "
                                >
                                    {{ day.name }}
                                </div>
                                <div
                                    class="w-full flex flex-col items-center pt-1.5 pb-1 rounded-xl cursor-pointer transition-all border"
                                    @click="openOnLeaveModal(day)"
                                    :class="[
                                        day.isToday
                                            ? 'bg-brand-50 border-brand-200 shadow-sm'
                                            : 'bg-slate-50/60 border-slate-100 hover:border-slate-200',
                                        day.isWeekend && !day.isToday
                                            ? 'opacity-50'
                                            : '',
                                    ]"
                                    title="Klik untuk melihat Tim yang Cuti"
                                >
                                    <span
                                        class="text-sm font-black"
                                        :class="
                                            day.isToday
                                                ? 'text-brand-600'
                                                : 'text-slate-700'
                                        "
                                    >
                                        {{ day.dateNumber }}
                                    </span>
                                    <div
                                        v-if="
                                            day.onLeave.length > 0 &&
                                            !day.isWeekend
                                        "
                                        class="mt-0.5 flex -space-x-1"
                                    >
                                        <template
                                            v-for="cuti in day.onLeave.slice(
                                                0,
                                                2,
                                            )"
                                            :key="cuti.id"
                                        >
                                            <div
                                                class="w-4 h-4 rounded-full border border-white bg-red-100 text-red-500 flex items-center justify-center text-[7px] font-bold overflow-hidden"
                                                :title="
                                                    cuti.pegawai?.nama ||
                                                    'Pegawai'
                                                "
                                            >
                                                <img
                                                    v-if="
                                                        cuti.pegawai
                                                            ?.foto_profil
                                                    "
                                                    :src="`/storage/${cuti.pegawai.foto_profil}`"
                                                    class="w-full h-full object-cover"
                                                />
                                                <span v-else>{{
                                                    cuti.pegawai?.nama?.charAt(
                                                        0,
                                                    ) || "?"
                                                }}</span>
                                            </div>
                                        </template>
                                        <div
                                            v-if="day.onLeave.length > 2"
                                            class="w-4 h-4 rounded-full border border-white bg-slate-200 text-slate-500 flex items-center justify-center text-[7px] font-bold"
                                        >
                                            +{{ day.onLeave.length - 2 }}
                                        </div>
                                    </div>
                                    <div
                                        v-else-if="!day.isWeekend"
                                        class="mt-0.5 w-1.5 h-1.5 rounded-full bg-emerald-400"
                                    ></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </MainLayout>

    <!-- Modal Detail Cuti + Revisi Tanggal -->
    <Teleport to="body">
        <div
            v-if="detailModal.show"
            class="fixed inset-0 z-[9999] flex items-center justify-center bg-slate-900/40 backdrop-blur-sm p-4"
            @click.self="closeDetailModal"
        >
            <div
                class="bg-white rounded-2xl max-w-lg w-full shadow-2xl border border-slate-100 overflow-hidden transform animate-in zoom-in duration-200"
            >
                <div
                    class="p-5 max-h-[70vh] overflow-y-auto custom-scrollbar"
                    v-if="detailModal.data"
                >
                    <div class="flex justify-between items-start mb-5">
                        <div class="flex items-center gap-3">
                            <div
                                class="bg-brand-50 text-brand-600 p-2 rounded-xl shrink-0"
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
                                        d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"
                                    ></path>
                                </svg>
                            </div>
                            <div>
                                <h3
                                    class="text-base font-extrabold text-slate-800"
                                >
                                    {{
                                        modeRevisi
                                            ? "Revisi Tanggal Cuti"
                                            : "Detail Pengajuan Cuti"
                                    }}
                                </h3>
                                <p
                                    class="text-[11px] font-medium text-slate-500 mt-0.5"
                                >
                                    {{
                                        modeRevisi
                                            ? "Ajukan ulang tanggal cuti Anda."
                                            : "Informasi lengkap status dan permohonan."
                                    }}
                                </p>
                            </div>
                        </div>
                        <div
                            class="px-2.5 py-1 rounded-lg text-[10px] font-bold uppercase tracking-wider whitespace-nowrap"
                            :class="{
                                'bg-amber-50 text-amber-600 border border-amber-200':
                                    detailModal.data.status?.includes(
                                        'menunggu',
                                    ),
                                'bg-emerald-50 text-emerald-600 border border-emerald-200':
                                    detailModal.data.status === 'disetujui',
                                'bg-red-50 text-red-600 border border-red-200':
                                    detailModal.data.status === 'ditolak',
                                'bg-orange-50 text-orange-600 border border-orange-200':
                                    detailModal.data.status ===
                                        'ditangguhkan' ||
                                    detailModal.data.status ===
                                        'dibatalkan_ditangguhkan',
                                'bg-slate-50 text-slate-600 border border-slate-200':
                                    detailModal.data.status?.includes(
                                        'dibatalkan',
                                    ) &&
                                    detailModal.data.status !==
                                        'dibatalkan_ditangguhkan',
                            }"
                        >
                            {{
                                detailModal.data.status?.replace(/_/g, " ") ??
                                "Status"
                            }}
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3 mb-4">
                        <div
                            class="p-3 bg-slate-50 border border-slate-100 rounded-xl"
                        >
                            <p
                                class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-0.5"
                            >
                                Tanggal Mulai
                            </p>
                            <p class="text-sm font-bold text-slate-700">
                                {{ formatDate(detailModal.data.tanggal_mulai) }}
                            </p>
                        </div>
                        <div
                            class="p-3 bg-slate-50 border border-slate-100 rounded-xl"
                        >
                            <p
                                class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-0.5"
                            >
                                Tanggal Selesai
                            </p>
                            <p class="text-sm font-bold text-slate-700">
                                {{
                                    formatDate(detailModal.data.tanggal_selesai)
                                }}
                            </p>
                        </div>
                        <div
                            class="p-3 bg-slate-50 border border-slate-100 rounded-xl"
                        >
                            <p
                                class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-0.5"
                            >
                                Jenis Cuti
                            </p>
                            <p class="text-sm font-bold text-indigo-600">
                                {{
                                    detailModal.data.jenis_cuti ??
                                    "Cuti Tahunan"
                                }}
                            </p>
                        </div>
                        <div
                            class="p-3 bg-slate-50 border border-slate-100 rounded-xl"
                        >
                            <p
                                class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-0.5"
                            >
                                Durasi
                            </p>
                            <p class="text-sm font-bold text-slate-700">
                                {{ detailModal.data.jumlah_hari }} Hari
                            </p>
                        </div>
                    </div>

                    <div class="mb-4">
                        <p
                            class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1 ml-0.5"
                        >
                            Keterangan / Alasan Cuti
                        </p>
                        <div
                            class="p-3 bg-slate-50 border border-slate-100 rounded-xl text-sm text-slate-600"
                        >
                            {{
                                detailModal.data.keterangan
                                    ? detailModal.data.keterangan
                                          .split("|")[0]
                                          .trim()
                                    : "-"
                            }}
                        </div>
                    </div>

                    <div
                        v-if="
                            isStatusDitangguhkan(detailModal.data) ||
                            (detailModal.data.status &&
                                (!detailModal.data.status.includes(
                                    'menunggu',
                                ) ||
                                    getApprovalLogs(detailModal.data).length >
                                        0))
                        "
                        class="p-3.5 rounded-xl border mb-4"
                        :class="
                            isStatusDitangguhkan(detailModal.data)
                                ? 'bg-orange-50 border-orange-100'
                                : detailModal.data.status === 'disetujui'
                                  ? 'bg-emerald-50 border-emerald-100'
                                  : 'bg-orange-50 border-orange-100'
                        "
                    >
                        <div class="flex items-center gap-2 mb-1.5">
                            <svg
                                class="w-4 h-4"
                                :class="
                                    isStatusDitangguhkan(detailModal.data)
                                        ? 'text-orange-500'
                                        : detailModal.data.status ===
                                            'disetujui'
                                          ? 'text-emerald-600'
                                          : 'text-orange-500'
                                "
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
                            <p
                                class="text-[10px] font-bold uppercase tracking-wider"
                                :class="
                                    isStatusDitangguhkan(detailModal.data)
                                        ? 'text-orange-600'
                                        : detailModal.data.status ===
                                            'disetujui'
                                          ? 'text-emerald-700'
                                          : 'text-orange-600'
                                "
                            >
                                {{
                                    isStatusDitangguhkan(detailModal.data)
                                        ? "Alasan Penangguhan / Pembatalan Atasan"
                                        : "Catatan / Respon Atasan"
                                }}
                            </p>
                        </div>

                        <div v-if="isStatusDitangguhkan(detailModal.data)">
                            <p class="text-sm text-slate-700 mb-1">
                                Diproses oleh:
                                <span class="font-bold">{{
                                    getNamaAtasanPemroses(detailModal.data)
                                }}</span>
                            </p>
                            <p
                                class="text-sm font-medium italic text-orange-700"
                            >
                                "{{ getCatatanAtasan(detailModal.data) }}"
                            </p>
                        </div>

                        <div
                            v-else-if="getApprovalLogs(detailModal.data).length"
                            class="space-y-2"
                        >
                            <div
                                v-for="log in getApprovalLogs(detailModal.data)"
                                :key="log.id"
                                class="rounded-lg border border-white/80 bg-white/70 p-2.5"
                            >
                                <p class="text-sm text-slate-700">
                                    {{
                                        getApprovalLevelLabel(
                                            log.level_approval,
                                        )
                                    }}:
                                    <span class="font-bold">{{
                                        log.approver?.nama || "Atasan"
                                    }}</span>
                                </p>
                                <p
                                    class="mt-0.5 text-xs font-medium"
                                    :class="
                                        log.keputusan === 'setuju'
                                            ? 'text-emerald-600'
                                            : 'text-orange-600'
                                    "
                                >
                                    {{
                                        log.catatan ||
                                        (log.keputusan === "setuju"
                                            ? "Disetujui."
                                            : "Ditolak oleh atasan.")
                                    }}
                                </p>
                            </div>
                        </div>

                        <div v-else>
                            <p class="text-sm text-slate-700 mb-1">
                                Diproses oleh:
                                <span class="font-bold">{{
                                    getNamaAtasanPemroses(detailModal.data)
                                }}</span>
                            </p>
                            <p
                                class="text-sm font-medium italic"
                                :class="
                                    detailModal.data.status === 'disetujui'
                                        ? 'text-emerald-600'
                                        : 'text-orange-700'
                                "
                            >
                                "{{ getCatatanAtasan(detailModal.data) }}"
                            </p>
                        </div>
                    </div>

                    <!-- Form Revisi Tanggal -->
                    <div
                        v-if="modeRevisi && canRevisiCurrentItem"
                        class="p-3.5 bg-amber-50 border border-amber-200 rounded-xl space-y-3"
                    >
                        <h4
                            class="text-xs font-extrabold text-amber-800 uppercase tracking-wider"
                        >
                            Form Pengajuan Ulang Tanggal Cuti
                        </h4>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label
                                    class="block text-[10px] font-bold text-slate-600 mb-1"
                                    >Tanggal Mulai Baru *</label
                                >
                                <input
                                    type="date"
                                    v-model="formRevisi.tanggal_mulai"
                                    :min="minDate"
                                    class="w-full px-2.5 py-1.5 border border-slate-200 rounded-lg text-xs focus:ring-amber-500 focus:border-amber-500"
                                />
                            </div>
                            <div>
                                <label
                                    class="block text-[10px] font-bold text-slate-600 mb-1"
                                    >Tanggal Selesai Baru *</label
                                >
                                <input
                                    type="date"
                                    v-model="formRevisi.tanggal_selesai"
                                    :min="formRevisi.tanggal_mulai || minDate"
                                    class="w-full px-2.5 py-1.5 border border-slate-200 rounded-lg text-xs focus:ring-amber-500 focus:border-amber-500"
                                />
                            </div>
                        </div>
                        <div class="flex justify-between items-center text-xs">
                            <span class="text-emerald-700 font-semibold"
                                >Estimasi Hari Kerja:
                                <strong
                                    >{{ jumlahHariKerja }} Hari</strong
                                ></span
                            >
                        </div>
                        <p
                            v-if="isInvalidWeekendOnly"
                            class="text-[11px] font-bold text-red-500"
                        >
                            *Tanggal yang dipilih tidak valid karena hanya
                            mencakup hari libur (Sabtu/Minggu).
                        </p>
                    </div>
                </div>

                <div
                    class="p-4 bg-slate-50 border-t border-slate-100 flex justify-between items-center gap-3 flex-wrap"
                    v-if="detailModal.data"
                >
                    <div>
                        <button
                            v-if="canRevisiCurrentItem && !modeRevisi"
                            type="button"
                            @click="activateRevisiMode"
                            class="px-3.5 py-2 bg-amber-500 hover:bg-amber-600 text-white rounded-xl text-xs font-bold uppercase tracking-wider transition shadow-sm"
                        >
                            Revisi Tanggal
                        </button>
                        <button
                            v-else-if="modeRevisi"
                            type="button"
                            @click="cancelRevisiMode"
                            class="px-3.5 py-2 bg-slate-200 hover:bg-slate-300 text-slate-700 rounded-xl text-xs font-bold transition"
                        >
                            Batal Revisi
                        </button>
                    </div>

                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            @click="closeDetailModal"
                            class="px-5 py-2 bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 rounded-xl text-sm font-bold transition shadow-sm"
                        >
                            Tutup
                        </button>

                        <button
                            v-if="modeRevisi"
                            type="button"
                            @click="submitRevisi"
                            :disabled="
                                isInvalidWeekendOnly || jumlahHariKerja === 0
                            "
                            class="px-5 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-sm font-bold transition shadow-md disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            Kirim Revisi
                        </button>

                        <template
                            v-else-if="
                                (user.role_id === 2 &&
                                    detailModal.data.status ===
                                        'menunggu_l1') ||
                                (user.role_id === 3 &&
                                    detailModal.data.status ===
                                        'menunggu_l2') ||
                                (user.role_id === 4 &&
                                    detailModal.data.status ===
                                        'menunggu_l3') ||
                                (user.role_id === 6 &&
                                    detailModal.data.status === 'menunggu_l4')
                            "
                        >
                            <button
                                type="button"
                                @click="rejectCuti(detailModal.data.id)"
                                class="px-5 py-2 bg-red-100 hover:bg-red-200 text-red-700 rounded-xl text-sm font-bold transition shadow-sm"
                            >
                                Tolak
                            </button>
                            <button
                                type="button"
                                @click="approveCuti(detailModal.data.id)"
                                class="px-5 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-xl text-sm font-bold transition shadow-md"
                            >
                                Setuju
                            </button>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>

    <!-- Modal Daftar Anggota Tim -->
    <Teleport to="body">
        <div
            v-if="showTeamModal"
            class="fixed inset-0 z-[9999] flex items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4"
            @click.self="showTeamModal = false"
        >
            <div
                class="bg-white rounded-2xl max-w-md w-full shadow-2xl border border-slate-100 overflow-hidden flex flex-col max-h-[90vh]"
            >
                <div
                    class="p-4 border-b border-slate-100 flex items-start gap-3 shrink-0 bg-slate-50/60"
                >
                    <div class="bg-blue-50 text-blue-600 p-2 rounded-xl">
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
                                d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"
                            ></path>
                        </svg>
                    </div>
                    <div class="flex-1">
                        <h3 class="text-base font-bold text-slate-800">
                            Daftar Anggota Tim
                        </h3>
                        <p class="text-xs font-semibold text-slate-500 mt-0.5">
                            Total terdaftar: {{ anggotaTim.length }} Orang
                        </p>
                    </div>
                    <button
                        type="button"
                        @click="showTeamModal = false"
                        class="text-slate-400 hover:text-slate-600 hover:bg-slate-100 p-1.5 rounded-full transition cursor-pointer"
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
                <div class="p-4 overflow-y-auto space-y-2.5 flex-1">
                    <div
                        v-for="anggota in anggotaTim"
                        :key="anggota.id"
                        class="flex items-center gap-3 p-2.5 border border-slate-100 rounded-xl bg-white hover:border-blue-100 hover:shadow-sm transition-all"
                    >
                        <div
                            class="w-9 h-9 rounded-full bg-blue-50 text-blue-600 flex items-center justify-center font-bold text-sm shrink-0 overflow-hidden border border-blue-100"
                        >
                            <img
                                v-if="anggota.foto_profil"
                                :src="`/storage/${anggota.foto_profil}`"
                                class="w-full h-full object-cover"
                            />
                            <span v-else>{{
                                anggota.nama?.charAt(0) || "?"
                            }}</span>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p
                                class="text-sm font-bold text-slate-800 truncate"
                            >
                                {{ anggota.nama }}
                            </p>
                            <p class="text-xs text-slate-500 mt-0.5 truncate">
                                NIP: {{ anggota.nip || "-" }}
                            </p>
                        </div>
                        <span
                            v-if="isPegawaiCutiHariIni(anggota.id)"
                            class="shrink-0 px-2.5 py-0.5 bg-red-50 text-red-600 border border-red-200 rounded-full text-[10px] font-bold"
                            >Sedang Cuti</span
                        >
                        <span
                            v-else
                            class="shrink-0 px-2.5 py-0.5 bg-emerald-50 text-emerald-600 border border-emerald-200 rounded-full text-[10px] font-bold"
                            >Aktif</span
                        >
                    </div>
                    <div
                        v-if="anggotaTim.length === 0"
                        class="text-center py-6"
                    >
                        <p class="text-sm text-slate-500 font-medium">
                            Belum ada data anggota tim.
                        </p>
                    </div>
                </div>
                <div
                    class="p-3.5 border-t border-slate-100 bg-slate-50 flex justify-between items-center shrink-0"
                >
                    <Link
                        :href="route('karyawan.kalender')"
                        class="text-xs font-bold text-blue-600 hover:text-blue-700 transition flex items-center gap-1"
                        >Buka Kalender Tim Lengkap
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
                                d="M17 8l4 4m0 0l-4 4m4-4H3"
                            ></path></svg
                    ></Link>
                    <button
                        type="button"
                        @click="showTeamModal = false"
                        class="px-4 py-1.5 bg-slate-800 hover:bg-slate-900 text-white rounded-xl text-sm font-bold transition cursor-pointer shadow-sm"
                    >
                        Tutup
                    </button>
                </div>
            </div>
        </div>
    </Teleport>

    <!-- Modal Cuti Disetujui Bulan Ini -->
    <Teleport to="body">
        <div
            v-if="approvedModal.show"
            class="fixed inset-0 z-[9999] flex items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4"
            @click.self="approvedModal.show = false"
        >
            <div
                class="bg-white rounded-2xl max-w-2xl w-full shadow-2xl border border-slate-100 overflow-hidden flex flex-col max-h-[90vh]"
            >
                <div
                    class="p-4 border-b border-slate-100 bg-emerald-50/50 flex items-center justify-between shrink-0"
                >
                    <div class="flex items-center gap-3">
                        <div
                            class="bg-emerald-50 text-emerald-600 p-2 rounded-xl"
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
                                    d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"
                                ></path>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-base font-extrabold text-slate-800">
                                Daftar Cuti Disetujui (Bulan Ini)
                            </h3>
                            <p
                                class="text-xs text-slate-500 mt-0.5 font-semibold"
                            >
                                Total pengajuan disetujui:
                                {{ cutiDisetujuiBulanIniList.length }} Pengajuan
                            </p>
                        </div>
                    </div>
                    <button
                        @click="approvedModal.show = false"
                        class="text-slate-400 hover:text-slate-600 p-1.5 rounded-lg hover:bg-slate-200/50 transition"
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
                <div class="p-4 overflow-y-auto space-y-2.5 flex-1">
                    <template v-if="cutiDisetujuiBulanIniList.length > 0">
                        <div
                            v-for="item in cutiDisetujuiBulanIniList"
                            :key="item.id"
                            class="p-3.5 bg-white border border-slate-100 rounded-xl flex items-center justify-between gap-3 hover:shadow-sm transition-all"
                        >
                            <div>
                                <h4 class="text-sm font-bold text-slate-800">
                                    {{ item.pegawai?.nama || "Pegawai" }}
                                </h4>
                                <p class="text-xs text-slate-500 mt-0.5">
                                    <span class="font-bold text-slate-700">{{
                                        formatDate(item.tanggal_mulai)
                                    }}</span>
                                    s.d
                                    <span class="font-bold text-slate-700">{{
                                        formatDate(item.tanggal_selesai)
                                    }}</span>
                                    ({{ item.jumlah_hari }} Hari)
                                </p>
                                <p
                                    class="text-[10px] text-slate-400 mt-0.5 italic font-medium"
                                >
                                    Keterangan: {{ item.keterangan || "-" }}
                                </p>
                            </div>
                            <span
                                class="px-2.5 py-0.5 bg-emerald-50 text-emerald-600 border border-emerald-200 rounded-full text-[10px] font-bold shrink-0"
                                >Disetujui</span
                            >
                        </div>
                    </template>
                    <div
                        v-else
                        class="text-center py-6 text-slate-400 text-xs font-semibold"
                    >
                        Tidak ada catatan cuti yang disetujui pada bulan ini.
                    </div>
                </div>
                <div
                    class="p-4 bg-slate-50 border-t border-slate-100 flex justify-end shrink-0"
                >
                    <button
                        @click="approvedModal.show = false"
                        class="px-5 py-2 bg-slate-800 hover:bg-slate-900 text-white rounded-xl text-sm font-bold transition shadow-md"
                    >
                        Tutup
                    </button>
                </div>
            </div>
        </div>
    </Teleport>

    <!-- Modal Penangguhan -->
    <Teleport to="body">
        <div
            v-if="suspendData.show"
            class="fixed inset-0 z-[9999] flex items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4"
            @click.self="closeSuspendModal"
        >
            <div
                class="bg-white rounded-2xl max-w-md w-full shadow-2xl border border-slate-100 overflow-hidden"
            >
                <div
                    class="p-4 border-b border-slate-100 bg-orange-50/50 flex items-center gap-3"
                >
                    <div class="bg-orange-50 text-orange-600 p-2 rounded-xl">
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
                                d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
                            ></path>
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-base font-extrabold text-slate-800">
                            Tangguhkan Cuti
                        </h3>
                        <p class="text-xs text-slate-500 mt-0.5 font-semibold">
                            Membatalkan paksa cuti yang telah disetujui.
                        </p>
                    </div>
                </div>
                <div class="p-5">
                    <label
                        class="block text-xs font-bold text-slate-700 mb-2 uppercase tracking-wide"
                        >Alasan Penangguhan
                        <span class="text-red-500">*</span></label
                    >
                    <textarea
                        v-model="suspendData.alasan"
                        rows="4"
                        class="w-full text-sm font-medium border-slate-200 rounded-xl focus:ring-orange-500 focus:border-orange-500"
                        placeholder="Contoh: Rapat mendadak dengan Kementan pada hari H."
                    ></textarea>
                </div>
                <div
                    class="p-4 bg-slate-50 border-t border-slate-100 flex justify-end gap-2"
                >
                    <button
                        type="button"
                        @click.prevent="closeSuspendModal"
                        class="px-4 py-2 bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 rounded-xl text-sm font-bold transition cursor-pointer"
                    >
                        Batal
                    </button>
                    <button
                        type="button"
                        @click.prevent="submitSuspend"
                        class="px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white rounded-xl text-sm font-bold transition cursor-pointer shadow-md"
                    >
                        Proses Penangguhan
                    </button>
                </div>
            </div>
        </div>
    </Teleport>

    <!-- Modal Tim Sedang Cuti pada Tanggal Tertentu -->
    <Teleport to="body">
        <div
            v-if="showOnLeaveModal"
            class="fixed inset-0 z-[9999] flex items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4"
            @click.self="showOnLeaveModal = false"
        >
            <div
                class="bg-white rounded-2xl max-w-md w-full shadow-2xl border border-slate-100 overflow-hidden flex flex-col max-h-[90vh]"
            >
                <div
                    class="p-4 border-b border-slate-100 flex items-start gap-3 shrink-0 bg-slate-50/60"
                >
                    <div class="bg-red-50 text-red-600 p-2 rounded-xl">
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
                                d="M13 7a4 4 0 11-8 0 4 4 0 018 0zM9 14a6 6 0 00-6 6v1h12v-1a6 6 0 00-6-6zM21 12h-6"
                            ></path>
                        </svg>
                    </div>
                    <div class="flex-1">
                        <h3 class="text-base font-extrabold text-slate-800">
                            Tim Sedang Cuti
                        </h3>
                        <p class="text-xs font-semibold text-slate-500 mt-0.5">
                            {{ formatDate(selectedDateForModal?.fullDate) }}
                            &bull; {{ onLeaveTeamMembers.length }} Orang
                        </p>
                    </div>
                    <button
                        type="button"
                        @click="showOnLeaveModal = false"
                        class="text-slate-400 hover:text-slate-600 hover:bg-slate-100 p-1.5 rounded-full transition cursor-pointer"
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
                <div class="p-4 overflow-y-auto space-y-2.5 flex-1">
                    <div
                        v-for="cuti in onLeaveTeamMembers"
                        :key="cuti.id"
                        class="flex items-center gap-3 p-2.5 border border-slate-100 rounded-xl bg-white hover:border-red-100 hover:shadow-sm transition-all"
                    >
                        <div
                            class="w-9 h-9 rounded-full bg-red-50 text-red-600 flex items-center justify-center font-bold text-sm shrink-0 overflow-hidden border border-red-100"
                        >
                            <img
                                v-if="cuti.pegawai?.foto_profil"
                                :src="`/storage/${cuti.pegawai.foto_profil}`"
                                class="w-full h-full object-cover"
                            />
                            <span v-else>{{
                                cuti.pegawai?.nama?.charAt(0) || "?"
                            }}</span>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p
                                class="text-sm font-bold text-slate-800 truncate"
                            >
                                {{ cuti.pegawai?.nama || "Tanpa Nama" }}
                            </p>
                            <p class="text-xs text-slate-500 mt-0.5 truncate">
                                NIP: {{ cuti.pegawai?.nip || "-" }}
                            </p>
                        </div>
                        <span
                            class="shrink-0 px-2.5 py-0.5 bg-red-50 text-red-600 border border-red-200 rounded-full text-[10px] font-bold"
                            >Sedang Cuti</span
                        >
                    </div>
                    <div
                        v-if="onLeaveTeamMembers.length === 0"
                        class="text-center py-6"
                    >
                        <p class="text-sm text-slate-500 font-medium">
                            Tidak ada anggota tim yang sedang cuti pada tanggal
                            ini.
                        </p>
                    </div>
                </div>
                <div
                    class="p-4 bg-slate-50 border-t border-slate-100 flex justify-end shrink-0"
                >
                    <button
                        type="button"
                        @click="showOnLeaveModal = false"
                        class="px-5 py-2 bg-slate-800 hover:bg-slate-900 text-white rounded-xl text-sm font-bold transition cursor-pointer shadow-md"
                    >
                        Tutup
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>