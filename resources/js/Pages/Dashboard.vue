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
    cutiDisetujuiPribadi: {
        type: Array,
        default: () => [],
    },
    chartDataBackend: {
        type: Array,
        default: () => [],
    },
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
    listKelompokSubstansi: {
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

    if (activeTab.value === "pribadi") {
        return route("karyawan.riwayat");
    }

    return route("atasan.approval");
};

const page = usePage();
const user = computed(() => page.props.auth.user);

const isAdminHR = computed(() => user.value.role_id === 5);

// Default tampilan: "tim" (Tim / Bawahan) — bukan "pribadi"
const activeTab = ref("tim");

const selectedKelompokSubstansi = ref(props.filter?.kelompok_substansi ?? null);

const applyKelompokSubstansiFilter = () => {
    router.get(
        route("dashboard"),
        { kelompok_substansi: selectedKelompokSubstansi.value },
        {
            preserveState: true,
            preserveScroll: true,
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

const displayedRecentCuti = computed(() => {
    const isPribadi =
        !isAdminHR.value &&
        (user.value.role_id === 1 || activeTab.value === "pribadi");

    if (isPribadi) {
        return props.recentCutiPribadi && props.recentCutiPribadi.length
            ? props.recentCutiPribadi
            : user.value.role_id === 1
              ? props.recentCuti
              : [];
    }

    return props.recentCuti;
});

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

// ================================================================
// LABEL STATUS "MENUNGGU": setiap status menunggu approval menampilkan
// kode level persetujuannya — (L1), (L2), (L3), (L4) — di belakang
// nama jabatan. Teks label TIDAK diubah.
//
// PEMBARUAN WARNA (Boss request): class warna badge disamakan PERSIS
// dengan badge status di halaman Riwayat Pengajuan (RiwayatPengajuan.vue):
//   - menunggu (L1-L4)          : bg-amber-100   text-amber-800   border-amber-300
//   - disetujui                 : bg-emerald-100 text-emerald-800 border-emerald-300
//   - ditolak                   : bg-rose-100    text-rose-800    border-rose-300
//   - ditangguhkan (dan yang    : bg-slate-200   text-slate-700   border-slate-300
//     dibatalkan_ditangguhkan)    (silver/abu, BUKAN merah/oranye)
//   - dibatalkan_reguler        : bg-gray-200    text-gray-800    border-gray-300
//   - lainnya / tanpa status    : bg-gray-200    text-gray-600    border-gray-300
// Hanya class warna yang berubah; teks & struktur fungsi tetap sama.
// ================================================================
const formatStatus = (status) => {
    switch (status) {
        case "menunggu_l1":
            return {
                text: "Menunggu Ketua Tim Kerja (L1)",
                class: "bg-amber-100 text-amber-800 border border-amber-300",
            };
        case "menunggu_l2":
            return {
                text: "Menunggu Ketua Kelompok Substansi (L2)",
                class: "bg-amber-100 text-amber-800 border border-amber-300",
            };
        case "menunggu_l3":
            return {
                text: "Menunggu Kasubag TU (L3)",
                class: "bg-amber-100 text-amber-800 border border-amber-300",
            };
        case "menunggu_l4":
            return {
                text: "Menunggu Kepala Biro Perencanaan (L4)",
                class: "bg-amber-100 text-amber-800 border border-amber-300",
            };
        case "disetujui":
            return {
                text: "Disetujui",
                class: "bg-emerald-100 text-emerald-800 border border-emerald-300",
            };
        case "ditolak":
            return {
                text: "Ditolak",
                class: "bg-rose-100 text-rose-800 border border-rose-300",
            };
        case "dibatalkan_reguler":
            return {
                text: "Dibatalkan",
                class: "bg-gray-200 text-gray-800 border border-gray-300",
            };
        case "dibatalkan_ditangguhkan":
            return {
                text: "Ditangguhkan",
                class: "bg-slate-200 text-slate-700 border border-slate-300",
            };
        case "ditangguhkan":
            return {
                text: "Ditangguhkan",
                class: "bg-slate-200 text-slate-700 border border-slate-300",
            };
        default:
            return {
                text: status ? status.replace(/_/g, " ").toUpperCase() : "-",
                class: "bg-gray-200 text-gray-600 border border-gray-300",
            };
    }
};

const currentYear = new Date().getFullYear();

const cutiTahunanTerpakai = computed(() => {
    return props.stats?.cuti_terpakai || 0;
});

const kuotaTahunanUtuh = computed(
    () => Number(props.stats?.kuota_tahunan ?? 12) || 0,
);

const sisaHakBerjalan = computed(
    () => props.stats?.sisa_kuota_tahun_ini ?? props.stats?.kuota_tahunan ?? 12,
);

const sisaDuaTahunLalu = computed(
    () => props.stats?.sisa_cuti_dua_tahun_lalu ?? 0,
);
const sisaSatuTahunLalu = computed(
    () => props.stats?.carry_forward_normal ?? 0,
);
const jatahTahunIniUntukSaldo = computed(
    () => props.stats?.sisa_kuota_tahun_ini ?? props.stats?.kuota_tahunan ?? 12,
);
const akumulasiPenuh = computed(
    () => sisaDuaTahunLalu.value === 12 && sisaSatuTahunLalu.value === 12,
);

const saldoBawaanEligible = computed(() => {
    if (props.stats?.saldo_bawaan_eligible != null) {
        return Number(props.stats.saldo_bawaan_eligible) || 0;
    }
    return akumulasiPenuh.value ? 12 : Math.min(6, sisaSatuTahunLalu.value);
});

const sisaCutiTersedia = computed(() => {
    if (props.stats?.total_cuti_tersedia != null) {
        return Number(props.stats.total_cuti_tersedia) || 0;
    }
    return sisaHakBerjalan.value + saldoBawaanEligible.value;
});

// ================================================================
// ALIAS: nama variabel alternatif yang mengacu pada computed yang
// SAMA persis — tidak ada logika baru, tidak ada duplikasi kalkulasi.
// ================================================================
const jatahCutiBerjalan = sisaHakBerjalan;
const totalCutiTersedia = sisaCutiTersedia;

const pctOf12 = (nilai) => {
    const angka = Number(nilai) || 0;
    return Math.max(0, Math.min(100, Math.round((angka / 12) * 100)));
};

const barisSaldoTahunan = computed(() => {
    const saldoTahunan = props.stats?.saldo_tahunan;
    if (Array.isArray(saldoTahunan) && saldoTahunan.length > 0) {
        return saldoTahunan.map((baris, index) => ({
            tahun: baris.tahun,
            nilai: Number(baris.nilai) || 0,
            barClass:
                index === saldoTahunan.length - 1
                    ? "bg-emerald-400"
                    : index === saldoTahunan.length - 2
                      ? "bg-orange-400"
                      : "bg-orange-400",
        }));
    }

    return [
        { tahun: currentYear - 2, nilai: sisaDuaTahunLalu.value, barClass: "bg-orange-400" },
        { tahun: currentYear - 1, nilai: sisaSatuTahunLalu.value, barClass: "bg-orange-400" },
        { tahun: currentYear, nilai: jatahTahunIniUntukSaldo.value, barClass: "bg-emerald-400" },
    ];
});

const showSaldoCutiModal = ref(false);
const openSaldoCutiModal = () => {
    showSaldoCutiModal.value = true;
};

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

const searchAnggota = ref("");

const filteredAnggotaTim = computed(() => {
    const keyword = searchAnggota.value.trim().toLowerCase();

    if (!keyword) return props.anggotaTim;

    return props.anggotaTim.filter((anggota) =>
        String(anggota.nama || "")
            .toLowerCase()
            .includes(keyword),
    );
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

    let sourceCuti = [];
    let sourceChart = [];

    if (isPribadiMode) {
        sourceCuti = props.cutiDisetujuiPribadi || [];
        sourceChart = props.chartDataPribadi || [];
    } else {
        sourceCuti = props.cutiDisetujuiData || [];
        sourceChart = props.chartDataBackend || [];
    }

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

const getAlasanBersih = (text) => {
    if (!text || text === "-") return "-";

    if (text.includes("|") || text.includes("[DITANGGUHKAN")) {
        let bagian = text.split("|").map((item) => item.trim());
        let alasanAwal =
            bagian[0] && bagian[0] !== "-" ? bagian[0] : "Ada keperluan";
        return alasanAwal;
    }

    if (
        text.includes("(") &&
        /oleh|ditolak|ditangguhkan|dibatalkan/i.test(text)
    ) {
        let alasanAwal = text.split("(")[0].trim();
        return alasanAwal !== "" ? alasanAwal : text;
    }

    return text;
};

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

const canRevisiCurrentItem = computed(() => {
    const item = detailModal.value.data;
    const currentUserId = user.value?.id;

    return (
        isStatusBisaDirevisi(item) &&
        String(item?.pegawai_id) === String(currentUserId)
    );
});

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

const toDateStr = (date) => {
    const yyyy = date.getFullYear();
    const mm = String(date.getMonth() + 1).padStart(2, "0");
    const dd = String(date.getDate()).padStart(2, "0");
    return `${yyyy}-${mm}-${dd}`;
};

const hariLiburSet = computed(
    () => new Set((props.hariLiburs || []).map((hari) => hari.tanggal)),
);

const liburBertepatan = computed(() => {
    if (!formRevisi.value.tanggal_mulai || !formRevisi.value.tanggal_selesai) {
        return [];
    }

    const start = new Date(formRevisi.value.tanggal_mulai);
    const end = new Date(formRevisi.value.tanggal_selesai);
    if (start > end) return [];

    const hasil = [];
    const current = new Date(start);
    while (current <= end) {
        const tanggal = toDateStr(current);
        const libur = (props.hariLiburs || []).find(
            (hari) => hari.tanggal === tanggal,
        );
        if (libur) hasil.push(libur);
        current.setDate(current.getDate() + 1);
    }

    return hasil;
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
        const day = current.getDay();
        const tanggal = toDateStr(current);
        if (day !== 0 && day !== 6 && !hariLiburSet.value.has(tanggal)) {
            count++;
        }
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
        const day = current.getDay();
        const tanggal = toDateStr(current);
        if (day !== 0 && day !== 6 && !hariLiburSet.value.has(tanggal)) {
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

// ================================================================
// MODAL KONFIRMASI PERSETUJUAN & PENOLAKAN CUTI (custom, bukan
// window.confirm() / window.prompt() bawaan browser lagi).
//
// approveModal & rejectModal menyimpan item cuti yang sedang diproses
// supaya modal bisa menampilkan ringkasan (Karyawan / Jenis Cuti /
// Tanggal) sebelum pengguna benar-benar mengonfirmasi aksinya — sesuai
// referensi tampilan popup "Setujui Pengajuan Cuti" dan
// "Alasan Penolakan Cuti".
//
// Fungsi approveCuti(id) & rejectCuti(id) TETAP DIPERTAHANKAN (nama &
// signature sama) agar tidak menghapus/merusak pemanggilan yang sudah
// ada di template (mis. tombol "Setuju" / "Tolak" pada Detail Modal),
// hanya isinya diganti supaya membuka modal custom ini.
// ================================================================
const approveModal = ref({ show: false, item: null });
const openApproveModal = (item) => {
    approveModal.value = { show: true, item };
};
const closeApproveModal = () => {
    approveModal.value = { show: false, item: null };
};
const confirmApproveCuti = () => {
    const item = approveModal.value.item;
    if (!item?.id) return;

    router.post(
        route("atasan.approval.approve", item.id),
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                // Auto-close: tutup modal konfirmasi + Detail Modal di belakangnya
                closeApproveModal();
                closeDetailModal();
            },
        },
    );
};

const rejectModal = ref({ show: false, item: null, alasan: "" });
const rejectError = ref(false);
const openRejectModal = (item) => {
    rejectModal.value = { show: true, item, alasan: "" };
    rejectError.value = false;
};
const closeRejectModal = () => {
    rejectModal.value = { show: false, item: null, alasan: "" };
    rejectError.value = false;
};
const confirmRejectCuti = () => {
    const item = rejectModal.value.item;
    if (!item?.id) return;

    // Alasan penolakan WAJIB diisi — tampilkan error inline, jangan kirim request
    if (!rejectModal.value.alasan || !String(rejectModal.value.alasan).trim()) {
        rejectError.value = true;
        return;
    }
    rejectError.value = false;

    router.post(
        route("atasan.approval.reject", item.id),
        { catatan: rejectModal.value.alasan.trim() },
        {
            preserveScroll: true,
            onSuccess: () => {
                // Auto-close: tutup modal konfirmasi + Detail Modal di belakangnya
                closeRejectModal();
                closeDetailModal();
            },
        },
    );
};

// approveCuti(id) / rejectCuti(id): dipanggil dari tombol "Setuju" /
// "Tolak" pada Detail Modal dengan hanya membawa id. Di sini kita coba
// ambil data lengkap item dari detailModal (jika id-nya cocok) supaya
// modal konfirmasi bisa menampilkan ringkasan pengajuan; jika tidak
// ditemukan, tetap fallback ke objek minimal { id } agar aksi tetap
// bisa diproses tanpa error.
const approveCuti = (id) => {
    const item =
        detailModal.value.data && detailModal.value.data.id === id
            ? detailModal.value.data
            : { id };
    openApproveModal(item);
};
const rejectCuti = (id) => {
    const item =
        detailModal.value.data && detailModal.value.data.id === id
            ? detailModal.value.data
            : { id };
    openRejectModal(item);
};

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

// ------------------------------------------------------------------
// KHUSUS PEMBATALAN MANDIRI OLEH PEMOHON (status "dibatalkan_reguler"):
// helper di bawah ini KHUSUS dipakai untuk kasus dibatalkan_reguler,
// terpisah dari getNamaAtasanPemroses / getCatatanAtasan (yang TIDAK
// diubah dan tetap dipakai untuk kasus ditolak / ditangguhkan).
//
// getNamaPembatal: nama pihak yang membatalkan (relasi dibatalkan_oleh),
// fallback ke nama pegawai pemilik pengajuan, lalu teks generik "Pemohon".
// ------------------------------------------------------------------
const getNamaPembatal = (item) => {
    return (
        item?.dibatalkan_oleh?.nama ||
        item?.dibatalkanOleh?.nama ||
        item?.pegawai?.nama ||
        "Pemohon"
    );
};

// getCatatanPembatalanReguler: catatan/alasan pembatalan mandiri. Dicoba
// dulu dari keterangan (format "alasan awal | catatan"), lalu field
// alasan_pembatalan / catatan_pembatalan, dan fallback terakhir kalimat
// default yang menegaskan ini pembatalan mandiri oleh pemohon.
const getCatatanPembatalanReguler = (item) => {
    if (item?.keterangan && item.keterangan.includes("|")) {
        return item.keterangan
            .split("|")[1]
            .replace(/\[.*?:\s*/g, "")
            .replace(/\]/g, "")
            .trim();
    }
    if (item?.alasan_pembatalan) {
        return item.alasan_pembatalan;
    }
    if (item?.alasanPembatalan) {
        return item.alasanPembatalan;
    }
    if (item?.catatan_pembatalan) {
        return item.catatan_pembatalan;
    }

    return "Pengajuan cuti telah dibatalkan secara mandiri oleh pemohon sebelum proses persetujuan selesai.";
};

const isStatusDitangguhkan = (item) =>
    item?.status === "ditangguhkan" ||
    item?.status === "dibatalkan_ditangguhkan";

// ================================================================
// Status "dibatalkan_reguler" berarti pengajuan DIBATALKAN SENDIRI oleh
// pemohon/pegawai, BUKAN karena ditolak/ditangguhkan oleh atasan.
// Dipisah lewat helper isStatusDibatalkanReguler supaya template bisa
// menampilkan label & narasi yang sesuai. isStatusDibatalkanAtauDitangguhkan
// TETAP DIPERTAHANKAN sebagai penanda umum "box catatan ini perlu tampil".
// ================================================================
const isStatusDibatalkanReguler = (item) =>
    item?.status === "dibatalkan_reguler" || item?.status === "dibatalkan";

const isStatusDibatalkanAtauDitangguhkan = (item) =>
    isStatusDitangguhkan(item) || isStatusDibatalkanReguler(item);

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

// ================================================================
// LABEL LEVEL SINGKAT UNTUK BADGE MODAL DETAIL CUTI
// (mis. "MENUNGGU L3") — dipakai khusus untuk badge status pada
// header modal Detail Cuti. Tidak mengubah fungsi status lain
// (formatStatus tetap dipakai di tabel Riwayat, dsb).
// ================================================================
const getStatusBadgeLabelSingkat = (status) => {
    if (!status) return "STATUS";

    const levelMap = {
        menunggu_l1: "MENUNGGU L1",
        menunggu_l2: "MENUNGGU L2",
        menunggu_l3: "MENUNGGU L3",
        menunggu_l4: "MENUNGGU L4",
        disetujui: "DISETUJUI",
        ditolak: "DITOLAK",
        ditangguhkan: "DITANGGUHKAN",
        dibatalkan_ditangguhkan: "DITANGGUHKAN",
        dibatalkan_reguler: "DIBATALKAN",
    };

    return levelMap[status] || status.replace(/_/g, " ").toUpperCase();
};

// ================================================================
// [PERBAIKAN] CATATAN / RESPON ATASAN — SEKARANG DINAMIS (L1/L2/L3/L4)
// SEBELUMNYA: blok ini HARDCODE hanya menampilkan L3 & L4 (`for (const
// level of [3, 4])` di getCatatanAtasanList), dengan asumsi lama bahwa
// staf SELALU lewat L1 lalu skip ke L3. Begitu logic backend
// (CutiController::tentukanLevelAwalCuti()) diperbaiki untuk kadang
// mulai dari L2 (staf tanpa tim_kerja tapi kelompoknya punya L2) atau
// bahkan langsung L3 (unit tanpa L1 & L2 sama sekali), tampilan ini jadi
// tidak sinkron: status bisa bilang "MENUNGGU L2" tapi baris L2 tidak
// pernah dirender sama sekali (lihat screenshot kasus TATI KOMARAWATI).
//
// PERBAIKAN: getLevelAwalUntukItem() menentukan level PERTAMA yang
// dilalui pengajuan ini secara dinamis per-item (bukan tebakan global),
// dengan urutan prioritas:
//   1. Kalau sudah ada approval_log di level 1 atau 2 -> itu levelnya
//      (sumber kebenaran paling akurat, karena mencatat apa yang BENAR-
//      BENAR terjadi pada pengajuan ini).
//   2. Kalau belum ada log sama sekali (masih menunggu di level
//      pertamanya), baca dari status saat ini ('menunggu_lX').
//   3. Kalau status sudah lewat L1/L2 tanpa log di level itu (mis.
//      'menunggu_l3' dst tanpa log level 1/2) -> pengajuan ini memang
//      tidak pernah melalui L1 maupun L2.
//
// Dengan begitu daftar level yang dirender (levelsToShow) otomatis
// menyesuaikan skenario staf mana pun: [1,3,4], [2,3,4], [3,4], atau
// bahkan [4] saja (kalau yang mengajukan sendiri adalah L3/Kasubag TU).
// Blok "Catatan / Respon Atasan" menampilkan baris-baris ini dengan
// nama/jabatan pejabat dan status ("Disetujui" / "Ditolak" / "Menunggu
// Persetujuan"), SELALU ditampilkan sejak awal pengajuan (selama status
// bukan ditolak):
//   - Level SUDAH diproses (ada approval_log): tampilkan nama pejabat
//     yang memutuskan + status sesuai keputusan.
//   - Level BELUM diproses: tetap tampilkan nama pejabat (dari data
//     yang tersedia / nama pejabat tetap) dengan status
//     "Menunggu Persetujuan".
// ================================================================
const LEVEL_JABATAN_MAP = {
    1: "Ketua Tim Kerja",
    2: "Ketua Kelompok Substansi",
    3: "Kasubag TU",
    4: "Kepala Biro Perencanaan",
};

// ------------------------------------------------------------------
// NAMA PEJABAT TETAP: jabatan di level tertinggi hanya diisi SATU orang
// untuk seluruh instansi. Dipakai sebagai fallback terakhir supaya baris
// "Menunggu Persetujuan" tetap menampilkan nama pejabatnya, bukan "-".
// Kalau jabatan berganti orang, cukup ubah nilai di map ini.
// ------------------------------------------------------------------
const NAMA_PEJABAT_TETAP = {
    3: "Ignatius Agus Hendarto, S.E., M.M.",
    4: "Seta Rukmalasari Agustina, S.P., M.M.A., M.Sc.",
};

// Nama pejabat yang DITUGASKAN di level tertentu (untuk baris yang masih
// "Menunggu Persetujuan"). Mencoba beberapa pola nama field yang umum.
const getNamaPejabatLevel = (item, level) => {
    return (
        item?.[`atasan_l${level}`]?.nama ||
        item?.[`atasanL${level}`]?.nama ||
        item?.[`calon_atasan_l${level}`]?.nama ||
        item?.[`calonAtasanL${level}`]?.nama ||
        item?.[`pejabat_l${level}`]?.nama ||
        item?.[`pejabatL${level}`]?.nama ||
        item?.[`penerima_l${level}`]?.nama ||
        item?.[`nama_atasan_l${level}`] ||
        item?.pegawai?.[`atasan_l${level}`]?.nama ||
        item?.pegawai?.[`atasanL${level}`]?.nama ||
        (level === 1 ? item?.pegawai?.nama_l1 : null) ||
        (level === 2 ? item?.pegawai?.nama_l2 : null) ||
        NAMA_PEJABAT_TETAP[level] ||
        "-"
    );
};

// Level PERTAMA yang dilalui pengajuan ini: 1, 2, 3, 4, atau null kalau
// tidak terdeteksi (fallback aman ke [3,4] di getLevelsToShowUntukItem).
const getLevelAwalUntukItem = (item) => {
    if (!item) return null;

    const logs = getApprovalLogs(item);
    const levelLogValid = logs
        .map((l) => Number(l.level_approval))
        .filter((l) => l >= 1 && l <= 4);

    if (levelLogValid.length) {
        return Math.min(...levelLogValid);
    }

    // Belum ada log sama sekali -> pengajuan masih di level pertamanya,
    // jadi level awal = level yang sedang ditunggu sekarang.
    const pendingMatch = item?.status?.match(/^menunggu_l(\d)$/);
    if (pendingMatch) return parseInt(pendingMatch[1], 10);

    return null;
};

// Daftar level yang harus dirender di blok "Catatan / Respon Atasan",
// mengikuti rantai baku: L1/L2 (salah satu saja, tergantung level awal)
// -> L3 -> L4. Kalau level awalnya sudah L3 atau L4, level sebelumnya
// tidak pernah dilalui sehingga tidak ikut ditampilkan.
const getLevelsToShowUntukItem = (item) => {
    const levelAwal = getLevelAwalUntukItem(item);
    if (levelAwal === null) return [3, 4]; // fallback aman
    if (levelAwal <= 2) return [levelAwal, 3, 4];
    if (levelAwal === 3) return [3, 4];
    return [4];
};

const getCatatanAtasanList = (item) => {
    if (!item) return [];

    // ----------------------------------------------------------------
    // PEMBARUAN: status "ditolak" TIDAK LAGI di-skip di sini. Sebelumnya
    // fungsi ini langsung return [] untuk status ditolak, sehingga modal
    // hanya menampilkan satu baris catatan ringkas (fallback getCatatanAtasan)
    // dan kehilangan konteks "sampai level mana pengajuan ini diproses".
    // Sekarang status ditolak tetap dihitung melalui alur yang sama
    // dengan status lain (approvals / approval_logs), supaya modal
    // menampilkan daftar level lengkap — persis seperti pada kasus
    // ditangguhkan / dibatalkan_ditangguhkan — sesuai referensi tampilan
    // yang diminta. Tidak ada logika lain di bawah ini yang diubah.
    // ----------------------------------------------------------------
    const levelsToShow = getLevelsToShowUntukItem(item);

    // 1) Jika backend sudah mengirim struktur approvals siap pakai.
    if (Array.isArray(item.approvals) && item.approvals.length) {
        return item.approvals
            .filter((a) => levelsToShow.includes(Number(a.level)))
            .sort((a, b) => Number(a.level) - Number(b.level))
            .map((a) => {
                const statusUpper = (a.status || "MENUNGGU")
                    .toString()
                    .toUpperCase();
                return {
                    key: a.level,
                    level: a.level,
                    jabatan: a.jabatan || LEVEL_JABATAN_MAP[a.level] || "",
                    nama: a.nama || a.approver?.nama || "-",
                    statusText:
                        statusUpper === "DISETUJUI"
                            ? "Disetujui"
                            : statusUpper === "DITOLAK"
                              ? "Ditolak"
                              : "Menunggu Persetujuan",
                    statusColorClass:
                        statusUpper === "DISETUJUI"
                            ? "text-emerald-600"
                            : statusUpper === "DITOLAK"
                              ? "text-red-600"
                              : "text-orange-500",
                    catatan: a.catatan || null,
                };
            });
    }

    // 2) Fallback: rakit dari approval_logs (levelsToShow yang dinamis).
    const logs = getApprovalLogs(item);

    const hasil = [];
    for (const level of levelsToShow) {
        const log = logs.find((l) => Number(l.level_approval) === level);

        if (log) {
            const statusText =
                log.keputusan === "tolak"
                    ? "Ditolak"
                    : log.keputusan === "setuju"
                      ? "Disetujui"
                      : "Menunggu Persetujuan";
            hasil.push({
                key: level,
                level,
                jabatan: LEVEL_JABATAN_MAP[level],
                nama: log.approver?.nama || "-",
                statusText,
                statusColorClass:
                    log.keputusan === "tolak"
                        ? "text-red-600"
                        : log.keputusan === "setuju"
                          ? "text-emerald-600"
                          : "text-orange-500",
                catatan: log.catatan || null,
            });
        } else {
            // Belum ada log untuk level ini — baik karena pengajuan baru
            // diajukan (masih di L1/L2) maupun levelnya sedang berjalan
            // tapi belum diputuskan, ATAU karena pengajuan sudah berhenti
            // di level sebelumnya (misal ditolak di L3, sehingga L4 belum
            // pernah diproses): tetap tampilkan barisnya dengan status
            // "Menunggu Persetujuan" beserta nama pejabatnya, supaya user
            // bisa melihat pejabat yang seharusnya memproses di level itu.
            hasil.push({
                key: level,
                level,
                jabatan: LEVEL_JABATAN_MAP[level],
                nama: getNamaPejabatLevel(item, level),
                statusText: "Menunggu Persetujuan",
                statusColorClass: "text-orange-500",
                catatan: null,
            });
        }
    }

    // Daftar level di levelsToShow (bisa [1,3,4], [2,3,4], [3,4], atau [4])
    // selalu dikembalikan untuk semua status (termasuk ditolak,
    // ditangguhkan, dibatalkan_ditangguhkan, dibatalkan_reguler,
    // disetujui, dan status menunggu).
    return hasil;
};
</script>

<template>
    <Head title="Dashboard" />

    <MainLayout>
        <div class="relative space-y-5 max-w-7xl mx-auto pb-10 pt-1 px-1">
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
                <div
                    class="relative max-w-2xl px-5 py-10 pr-32 md:px-8 md:py-14 md:pr-8"
                >
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
                    <p
                        class="mt-2 text-lg font-black leading-tight text-white md:text-xl"
                    >
                        Kelola cuti dengan lebih teratur
                    </p>
                    <p
                        class="mt-2 max-w-xl text-sm font-medium leading-relaxed text-emerald-50/85 md:text-base"
                    >
                        {{ headerSubtitle }}
                    </p>
                    <p
                        class="mt-1 max-w-xl text-xs font-medium leading-relaxed text-emerald-50/75 md:text-sm"
                    >
                        Pantau ketersediaan, pengajuan, dan aktivitas tim dari
                        satu dashboard.
                    </p>
                </div>
                <div
                    v-if="[1, 2, 3, 4, 6].includes(user.role_id)"
                    class="absolute right-5 top-1/2 -translate-y-1/2 md:right-16"
                >
                    <!-- TOMBOL "AJUKAN CUTI" — desain TIMBUL (3D) modern:
                         - bentuk pill (rounded-full) dengan gradasi putih -> hijau muda
                         - kilau glossy di bagian atas (span "shine")
                         - "tebal" tombol di bawah lewat shadow 0_6px_0 (efek timbul)
                         - ikon plus di dalam lingkaran hijau yang juga timbul
                         - hover: naik sedikit + glow; active: menekan ke bawah
                         Hanya tampilan yang berubah, link & aksi tetap sama. -->
                    <Link
                        :href="route('karyawan.ajukan')"
                        class="group relative inline-flex items-center gap-3 overflow-hidden rounded-full border-2 border-white bg-gradient-to-b from-white via-emerald-50 to-emerald-200 py-3 pl-3 pr-7 text-[13px] font-black uppercase tracking-wider text-emerald-950 shadow-[inset_0_2px_2px_rgba(255,255,255,1),inset_0_-4px_8px_rgba(16,185,129,0.35),0_0_0_4px_rgba(255,255,255,0.22),0_7px_0_#10b981,0_16px_28px_rgba(2,44,34,0.55),0_0_36px_rgba(110,231,183,0.55)] transition-all duration-150 hover:-translate-y-0.5 hover:shadow-[inset_0_2px_2px_rgba(255,255,255,1),inset_0_-4px_8px_rgba(16,185,129,0.35),0_0_0_5px_rgba(255,255,255,0.3),0_9px_0_#10b981,0_20px_34px_rgba(2,44,34,0.55),0_0_48px_rgba(110,231,183,0.8)] active:translate-y-[5px] active:shadow-[inset_0_2px_3px_rgba(6,78,59,0.25),0_0_0_4px_rgba(255,255,255,0.22),0_2px_0_#10b981,0_6px_12px_rgba(2,44,34,0.4),0_0_28px_rgba(110,231,183,0.5)] focus:outline-none focus-visible:ring-2 focus-visible:ring-white/80"
                    >
                        <!-- kilau glossy di sisi atas tombol -->
                        <span
                            aria-hidden="true"
                            class="pointer-events-none absolute inset-x-5 top-1 h-1/2 rounded-full bg-gradient-to-b from-white to-transparent"
                        ></span>

                        <!-- lingkaran ikon plus yang ikut timbul -->
                        <span
                            class="relative flex h-9 w-9 items-center justify-center rounded-full bg-gradient-to-b from-emerald-400 to-emerald-600 text-white shadow-[inset_0_1px_1px_rgba(255,255,255,0.6),0_3px_6px_rgba(5,150,105,0.6)] transition-transform duration-150 group-hover:rotate-90"
                        >
                            <svg
                                class="h-5 w-5"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2.5"
                                    d="M12 4v16m8-8H4"
                                ></path>
                            </svg>
                        </span>

                        <span class="relative">Ajukan Cuti</span>
                    </Link>
                </div>
            </div>

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
                    <!-- Urutan tombol dibalik: Tim / Bawahan di kiri, Pribadi di kanan -->
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
                </div>
            </div>

            <div
                v-show="
                    !isAdminHR &&
                    (user.role_id === 1 || activeTab === 'pribadi')
                "
                class="w-full"
            >
                <div class="grid grid-cols-1 md:grid-cols-4 gap-5">
                    <div
                        class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 flex flex-col h-[180px] hover:shadow-md transition-all"
                    >
                        <h3
                            class="text-[11px] font-bold text-slate-800 tracking-wider uppercase"
                        >
                            Jatah Cuti {{ currentYear }}
                        </h3>
                        <div
                            class="flex-1 flex flex-row items-center justify-between gap-3 mt-1"
                        >
                            <div class="text-left">
                                <div class="flex items-baseline gap-1">
                                    <span
                                        class="text-5xl font-extrabold text-slate-800 tracking-tight"
                                    >
                                        {{ kuotaTahunanUtuh }}
                                    </span>
                                    <span
                                        class="text-sm font-semibold text-slate-400"
                                        >hari</span
                                    >
                                </div>
                                <p
                                    class="text-[11px] text-slate-400 mt-2 font-medium"
                                >
                                    Maksimum
                                    {{ kuotaTahunanUtuh }} hari/tahun
                                </p>
                            </div>
                            <div class="shrink-0">
                                <img
                                    src="/images/kalender-3d.png"
                                    alt="Jatah Cuti"
                                    class="w-16 h-16 object-contain"
                                />
                            </div>
                        </div>
                    </div>

                    <div
                        class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 flex flex-col h-[180px] hover:shadow-md transition-all"
                    >
                        <h3
                            class="text-[11px] font-bold text-slate-800 tracking-wider uppercase"
                        >
                            Cuti Terpakai
                        </h3>
                        <div
                            class="flex-1 flex flex-row items-center justify-between gap-3 mt-1"
                        >
                            <div class="text-left">
                                <div class="flex items-baseline gap-1">
                                    <span
                                        class="text-5xl font-extrabold text-slate-800 tracking-tight"
                                        >{{ cutiTahunanTerpakai }}</span
                                    >
                                    <span
                                        class="text-sm font-semibold text-slate-400"
                                        >hari</span
                                    >
                                </div>
                                <p
                                    class="text-[11px] text-slate-400 mt-2 font-medium"
                                >
                                    Khusus cuti tahunan
                                </p>
                            </div>
                            <div class="shrink-0">
                                <img
                                    src="/images/ceklis-3d.png"
                                    alt="Cuti Terpakai"
                                    class="w-16 h-16 object-contain"
                                />
                            </div>
                        </div>
                    </div>

                    <div
                        @click="openSaldoCutiModal"
                        role="button"
                        tabindex="0"
                        aria-haspopup="dialog"
                        aria-label="Buka rincian saldo cuti bawaan"
                        @keydown.enter="openSaldoCutiModal"
                        @keydown.space.prevent="openSaldoCutiModal"
                        class="bg-white rounded-2xl border border-slate-200 shadow-sm flex flex-col overflow-hidden h-[180px] cursor-pointer hover:shadow-md transition-all focus:outline-none focus:ring-2 focus:ring-orange-300"
                    >
                        <div class="p-4 pb-2 flex-1 flex flex-col">
                            <div class="flex justify-between items-center mb-3">
                                <h3
                                    class="text-[11px] font-bold text-orange-500 tracking-wider uppercase"
                                >
                                    Sisa Cuti
                                </h3>
                                <button
                                    type="button"
                                    class="text-[10px] text-slate-400 hover:text-slate-600 flex items-center gap-0.5 transition-colors font-medium"
                                >
                                    Rincian
                                    <svg
                                        class="w-3 h-3"
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

                            <div class="space-y-1.5 w-full">
                                <div
                                    v-for="baris in barisSaldoTahunan"
                                    :key="baris.tahun"
                                    class="flex items-center text-[10px] font-medium"
                                >
                                    <span class="text-slate-400 w-7">{{
                                        baris.tahun
                                    }}</span>
                                    <div
                                        class="flex-1 mx-2 h-1 bg-slate-100 rounded-full"
                                    >
                                        <div
                                            class="h-full rounded-full"
                                            :class="baris.barClass"
                                            :style="`width: ${pctOf12(baris.nilai)}%`"
                                        ></div>
                                    </div>
                                    <span
                                        class="font-bold text-slate-700 w-8 text-right"
                                    >
                                        {{ baris.nilai }}
                                        <span
                                            class="font-normal text-[9px] text-slate-500"
                                            >hari</span
                                        >
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div
                            class="bg-emerald-50/70 border-t border-emerald-100 px-4 py-2.5 flex justify-between items-center shrink-0"
                        >
                            <span
                                class="text-[10px] font-bold text-emerald-700 uppercase tracking-wide leading-tight"
                                >Saldo<br class="hidden sm:block" />
                                bawaan eligible</span
                            >
                            <span class="flex items-baseline gap-1">
                                <span
                                    class="text-3xl font-extrabold text-emerald-800 leading-none tracking-tight"
                                    >{{ saldoBawaanEligible }}</span
                                >
                                <span class="text-xs font-bold text-emerald-600"
                                    >hari</span
                                >
                            </span>
                        </div>
                    </div>

                    <div
                        class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 flex flex-col min-h-[180px] hover:shadow-md transition-all"
                    >
                        <h3
                            class="text-[11px] font-bold text-slate-800 tracking-wider uppercase"
                        >
                            Total Cuti Tersedia
                        </h3>
                        <div
                            class="flex-1 flex flex-row items-center justify-between gap-3 mt-1"
                        >
                            <div class="text-left">
                                <div class="flex items-baseline gap-1">
                                    <span
                                        class="text-5xl font-extrabold text-slate-800 tracking-tight"
                                        >{{ totalCutiTersedia }}</span
                                    >
                                    <span
                                        class="text-sm font-semibold text-slate-400"
                                        >hari</span
                                    >
                                </div>
                                <p
                                    class="text-[11px] text-slate-400 mt-2 font-medium"
                                >
                                    Siap dipakai kapan saja
                                </p>
                            </div>
                            <div class="shrink-0">
                                <img
                                    src="/images/jam-3d.png"
                                    alt="Total Cuti Tersedia"
                                    class="w-16 h-16 object-contain"
                                />
                            </div>
                        </div>
                        <div class="mt-2 pt-2 border-t border-slate-100">
                            <p class="text-[10px] text-slate-400 font-medium">
                                Sisa Hak ({{ jatahCutiBerjalan }}) + Saldo
                                Bawaan ({{ saldoBawaanEligible }})
                            </p>
                        </div>
                    </div>
                </div>

                <div class="mt-4 ml-1">
                    <p class="text-[11px] text-slate-400">
                        Klik kartu "Saldo Cuti" untuk melihat cara perhitungan
                        bawaan dari {{ currentYear - 2 }} dan
                        {{ currentYear - 1 }}.
                    </p>
                </div>
            </div>

            <div
                v-show="
                    activeTab === 'tim' &&
                    [2, 3, 4, 5, 6].includes(user.role_id)
                "
                class="grid grid-cols-1 md:grid-cols-3 gap-4"
            >
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
                        :href="getLihatSemuaHref()"
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

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
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
                            <select
                                v-if="[4, 5, 6].includes(user.role_id)"
                                v-model="selectedKelompokSubstansi"
                                @change="applyKelompokSubstansiFilter"
                                class="text-[11px] font-bold border border-slate-200 rounded-lg text-slate-600 focus:ring-brand-500 focus:border-brand-500 py-1.5 pl-3 pr-8 bg-white cursor-pointer"
                            >
                                <option :value="null">Semua Divisi</option>
                                <option
                                    v-for="kelompok in listKelompokSubstansi"
                                    :key="kelompok"
                                    :value="kelompok"
                                >
                                    {{ kelompok }}
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
                        <!-- PEMBARUAN (sesuai foto kanan): kotak grafik sekarang
                             flex-col + justify-center, sehingga seluruh isi kotak
                             (judul "ABSENSI PER BULAN", chart, label bulan, dan
                             legend) berada di TENGAH secara vertikal, tidak lagi
                             menempel ke atas dengan ruang kosong besar di bawah
                             legend. Kotak ini ikut meregang setinggi kartu
                             Distribusi di sebelahnya (items-stretch bawaan flex),
                             jadi sisa ruang dibagi rata atas-bawah. -->
                        <div
                            class="flex-1 min-w-0 flex flex-col justify-center rounded-2xl border border-slate-200/80 bg-white p-4 shadow-[0_2px_12px_rgba(15,23,42,0.04)] ring-1 ring-slate-100"
                        >
                            <p
                                class="text-[10px] font-extrabold text-slate-500 uppercase tracking-widest mb-3"
                            >
                                Absensi per Bulan
                            </p>
                            <!-- PEMBARUAN: tinggi area chart dikembalikan ke h-44
                                 (sebelumnya h-32) sesuai foto kanan. Dengan tinggi
                                 ini bar terlihat proporsional dan area chart mengisi
                                 kotak lebih baik, sehingga grafik tampak seimbang di
                                 tengah kotak, bukan tenggelam di bagian bawah. -->
                            <div
                                class="relative h-44 flex items-end justify-between gap-1 sm:gap-1.5"
                            >
                                <!-- Garis putus-putus Background -->
                                <!-- absolute inset-0 memastikan garis merentang pas di tinggi h-44,
                                     sejajar dengan baseline bar di bawah ini. -->
                                <div
                                    class="absolute inset-0 flex flex-col justify-between opacity-15 pointer-events-none z-0"
                                >
                                    <div
                                        class="border-t border-dashed border-slate-400 w-full"
                                    ></div>
                                    <div
                                        class="border-t border-dashed border-slate-400 w-full"
                                    ></div>
                                    <div
                                        class="border-t border-dashed border-slate-400 w-full"
                                    ></div>
                                    <div
                                        class="border-t border-dashed border-slate-400 w-full"
                                    ></div>
                                </div>

                                <!-- Tooltip muncul otomatis saat hover (bukan klik)
                                     lewat trik Tailwind group/group-hover — class
                                     "group" ada di pembungkus tiap kolom bar, dan
                                     tooltip memakai "opacity-0 invisible
                                     group-hover:opacity-100 group-hover:visible".
                                     State activeChartIndex/fungsi toggleChartTooltip
                                     di script tetap dibiarkan ada (tidak dihapus)
                                     agar tidak mengubah bagian lain yang mungkin
                                     masih bergantung padanya. -->
                                <div
                                    v-for="(data, index) in chartData"
                                    :key="index"
                                    class="relative flex flex-col items-center flex-1 h-full justify-end cursor-pointer z-10 group"
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

                                    <!-- Tooltip Hover: opacity/visible dikendalikan oleh
                                         group-hover, tidak lagi oleh activeChartIndex. -->
                                    <div
                                        v-if="data.totalDays > 0"
                                        class="absolute mb-2 left-1/2 -translate-x-1/2 bg-slate-800 text-white py-1.5 px-2.5 rounded-lg shadow-xl pointer-events-none flex flex-col gap-0.5 items-center min-w-[110px] transition-all duration-200 text-[10px] opacity-0 invisible group-hover:opacity-100 group-hover:visible z-50"
                                        :style="`bottom: ${(data.totalDays / maxDays) * 100}%`"
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
                                </div>
                            </div>

                            <!-- Label Bulan (jarak mt-2, sejajar per kolom dengan bar) -->
                            <div
                                class="flex items-start justify-between gap-1 sm:gap-1.5 mt-2"
                            >
                                <div
                                    v-for="(data, index) in chartData"
                                    :key="'month-' + index"
                                    class="flex-1 flex justify-center"
                                >
                                    <span
                                        class="text-[10px] font-bold text-slate-400"
                                        >{{ data.month }}</span
                                    >
                                </div>
                            </div>

                            <div
                                class="flex flex-wrap justify-center gap-x-4 gap-y-1 mt-3"
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

                <div class="flex flex-col gap-4">
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
                    <!-- HEADER MODAL "DETAIL CUTI": ikon clipboard-check + judul +
                         subjudul di kiri, badge status berbentuk pil di kanan atas. -->
                    <div class="flex justify-between items-start gap-3 mb-5">
                        <div class="flex items-start gap-2.5 min-w-0">
                            <div class="mt-0.5 shrink-0 text-slate-800">
                                <svg
                                    class="w-5 h-5"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                    stroke-width="2"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"
                                    ></path>
                                </svg>
                            </div>
                            <div class="min-w-0">
                                <h3
                                    class="text-base font-bold text-slate-900 leading-tight"
                                >
                                    {{
                                        modeRevisi
                                            ? "Revisi Tanggal Cuti"
                                            : "Detail Cuti"
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
                        <span
                            class="px-3 py-1 rounded-full text-[10px] font-extrabold uppercase tracking-wider whitespace-nowrap shrink-0"
                            :class="{
                                'bg-amber-100 text-amber-700':
                                    detailModal.data.status?.includes(
                                        'menunggu',
                                    ),
                                'bg-emerald-100 text-emerald-700':
                                    detailModal.data.status === 'disetujui',
                                'bg-red-100 text-red-700':
                                    detailModal.data.status === 'ditolak',
                                'bg-orange-100 text-orange-700':
                                    detailModal.data.status ===
                                        'ditangguhkan' ||
                                    detailModal.data.status ===
                                        'dibatalkan_ditangguhkan',
                                'bg-slate-100 text-slate-600':
                                    detailModal.data.status?.includes(
                                        'dibatalkan',
                                    ) &&
                                    detailModal.data.status !==
                                        'dibatalkan_ditangguhkan',
                            }"
                        >
                            {{
                                getStatusBadgeLabelSingkat(
                                    detailModal.data.status,
                                )
                            }}
                        </span>
                    </div>

                    <div class="space-y-3 mb-4 text-sm">
                        <div
                            v-if="user.role_id !== 1"
                            class="grid grid-cols-12 gap-2"
                        >
                            <div class="col-span-4 text-slate-500 font-medium">
                                Nama Pegawai
                            </div>
                            <div
                                class="col-span-8 font-semibold text-slate-800 leading-snug"
                            >
                                {{ detailModal.data.pegawai?.nama ?? "-" }}
                            </div>
                        </div>

                        <div
                            v-if="user.role_id !== 1"
                            class="grid grid-cols-12 gap-2"
                        >
                            <div class="col-span-4 text-slate-500 font-medium">
                                NIP
                            </div>
                            <div class="col-span-8 text-slate-700 font-medium">
                                {{ detailModal.data.pegawai?.nip ?? "-" }}
                            </div>
                        </div>

                        <div
                            v-if="user.role_id !== 1"
                            class="grid grid-cols-12 gap-2"
                        >
                            <div class="col-span-4 text-slate-500 font-medium">
                                Jabatan
                            </div>
                            <div
                                class="col-span-8 text-slate-800 font-semibold uppercase leading-snug"
                            >
                                {{ detailModal.data.pegawai?.jabatan ?? "-" }}
                            </div>
                        </div>

                        <div
                            v-if="user.role_id !== 1"
                            class="grid grid-cols-12 gap-2"
                        >
                            <div class="col-span-4 text-slate-500 font-medium">
                                Kelompok Substansi
                            </div>
                            <div
                                class="col-span-8 text-slate-800 font-semibold"
                            >
                                {{
                                    detailModal.data.pegawai
                                        ?.kelompok_substansi ?? "-"
                                }}
                            </div>
                        </div>

                        <div class="grid grid-cols-12 gap-2">
                            <div class="col-span-4 text-slate-500 font-medium">
                                Jenis Cuti
                            </div>
                            <div
                                class="col-span-8 font-semibold text-indigo-600"
                            >
                                {{
                                    detailModal.data.jenis_cuti ??
                                    "Cuti Tahunan"
                                }}
                            </div>
                        </div>

                        <!-- RINCIAN SISA CUTI (kotak amber, sejajar dengan baris lain:
                             label col-span-4, nilai col-span-8 rata kiri; aksen garis
                             kiri amber). Data: totalCutiTersedia, jatahCutiBerjalan,
                             saldoBawaanEligible — TIDAK diubah. -->
                        <div
                            v-if="
                                detailModal.data.jenis_cuti
                                    ?.toLowerCase()
                                    .includes('tahunan')
                            "
                            class="grid grid-cols-12 gap-2 items-start bg-amber-50 border border-amber-200 border-l-4 border-l-amber-400 rounded-lg px-3 py-2.5 -mx-1"
                        >
                            <div
                                class="col-span-4 text-amber-700 font-semibold"
                            >
                                Rincian Sisa Cuti
                            </div>
                            <div class="col-span-8">
                                <span
                                    class="font-extrabold text-amber-800 block"
                                >
                                    {{
                                        detailModal.data.pegawai
                                            ?.total_cuti_tersedia ??
                                        0
                                    }}
                                    Hari Total
                                </span>
                                <span
                                    class="font-normal text-amber-600/80 text-xs block mt-0.5"
                                >
                                    (Tahun Ini:
                                    {{
                                        detailModal.data.pegawai
                                            ?.sisa_hak_tahun_berjalan ??
                                        0
                                    }}
                                    hari | Saldo Bawaan:
                                    {{
                                        detailModal.data.pegawai
                                            ?.saldo_bawaan_eligible ??
                                        0
                                    }}
                                    hari)
                                </span>
                            </div>
                        </div>

                        <div class="grid grid-cols-12 gap-2">
                            <div class="col-span-4 text-slate-500 font-medium">
                                Waktu Cuti
                            </div>
                            <div
                                class="col-span-8 text-slate-800 font-semibold"
                            >
                                {{ formatDate(detailModal.data.tanggal_mulai) }}
                                s/d
                                {{
                                    formatDate(detailModal.data.tanggal_selesai)
                                }}
                                ({{ detailModal.data.jumlah_hari }} Hari)
                            </div>
                        </div>

                        <div class="grid grid-cols-12 gap-2">
                            <div class="col-span-4 text-slate-500 font-medium">
                                Alasan
                            </div>
                            <div
                                class="col-span-8 text-slate-800 font-semibold leading-relaxed"
                            >
                                {{
                                    getAlasanBersih(detailModal.data.keterangan)
                                }}
                            </div>
                        </div>

                        <div class="grid grid-cols-12 gap-2">
                            <div class="col-span-4 text-slate-500 font-medium">
                                Alamat
                            </div>
                            <div
                                class="col-span-8 text-slate-800 font-semibold uppercase leading-normal"
                            >
                                {{
                                    detailModal.data.alamat_selama_cuti ??
                                    detailModal.data.alamat_cuti ??
                                    "-"
                                }}
                            </div>
                        </div>

                        <div class="grid grid-cols-12 gap-2">
                            <div class="col-span-4 text-slate-500 font-medium">
                                Nomor Telepon
                            </div>
                            <div
                                class="col-span-8 text-slate-800 font-semibold"
                            >
                                {{
                                    detailModal.data.no_telp ??
                                    detailModal.data.pegawai?.no_telepon ??
                                    detailModal.data.pegawai?.no_hp ??
                                    user.no_telepon ??
                                    user.no_hp ??
                                    "-"
                                }}
                            </div>
                        </div>

                        <div class="grid grid-cols-12 gap-2 items-center pt-1">
                            <div class="col-span-4 text-slate-500 font-medium">
                                Lampiran
                            </div>
                            <div class="col-span-8">
                                <a
                                    v-if="detailModal.data.lampiran"
                                    :href="detailModal.data.lampiran"
                                    target="_blank"
                                    rel="noopener"
                                    class="inline-flex items-center gap-2 px-3 py-1.5 bg-green-50 border border-green-200 text-green-700 hover:bg-green-100 rounded-xl text-xs font-medium transition-colors"
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
                                            d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                                        />
                                    </svg>
                                    Lihat Lampiran
                                </a>
                                <div
                                    v-else
                                    class="inline-flex items-center gap-2 px-3 py-1.5 bg-slate-50 border border-slate-200 text-slate-400 rounded-xl text-xs font-medium"
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
                                            d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                                        />
                                    </svg>
                                    Tidak ada lampiran
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- BLOK "CATATAN / RESPON ATASAN": dipecah menjadi kondisi
                         berurutan supaya narasi sesuai siapa yang memproses:
                           1) isStatusDibatalkanReguler -> "Informasi Pembatalan"
                              (dibatalkan MANDIRI oleh pemohon, bukan atasan).
                           2) isStatusDitangguhkan       -> "Alasan Penangguhan /
                              Pembatalan Atasan".
                           3) getCatatanAtasanList / approval logs / fallback.
                              (Termasuk status "ditolak" — sekarang tampil sebagai
                              daftar L3 & L4 karena getCatatanAtasanList tidak lagi
                              di-skip untuk status ini.) -->
                    <div
                        v-if="
                            isStatusDibatalkanAtauDitangguhkan(
                                detailModal.data,
                            ) ||
                            getCatatanAtasanList(detailModal.data).length > 0 ||
                            (detailModal.data.status &&
                                (!detailModal.data.status.includes(
                                    'menunggu',
                                ) ||
                                    getApprovalLogs(detailModal.data).length >
                                        0))
                        "
                        class="p-3.5 rounded-xl border mb-4"
                        :class="
                            detailModal.data.status === 'ditolak'
                                ? 'bg-red-50 border-red-200'
                                : isStatusDibatalkanReguler(detailModal.data)
                                  ? 'bg-slate-50 border-slate-200'
                                  : isStatusDitangguhkan(detailModal.data)
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
                                    detailModal.data.status === 'ditolak'
                                        ? 'text-red-600'
                                        : isStatusDibatalkanReguler(
                                                detailModal.data,
                                            )
                                          ? 'text-slate-500'
                                          : isStatusDitangguhkan(
                                                  detailModal.data,
                                              )
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
                                    detailModal.data.status === 'ditolak'
                                        ? 'text-red-700'
                                        : isStatusDibatalkanReguler(
                                                detailModal.data,
                                            )
                                          ? 'text-slate-600'
                                          : isStatusDitangguhkan(
                                                  detailModal.data,
                                              )
                                            ? 'text-orange-600'
                                            : detailModal.data.status ===
                                                'disetujui'
                                              ? 'text-emerald-700'
                                              : 'text-orange-600'
                                "
                            >
                                {{
                                    isStatusDibatalkanReguler(detailModal.data)
                                        ? "Informasi Pembatalan"
                                        : isStatusDitangguhkan(detailModal.data)
                                          ? "Alasan Penangguhan / Pembatalan Atasan"
                                          : "Catatan / Respon Atasan"
                                }}
                            </p>
                        </div>

                        <!-- (1) PEMBATALAN MANDIRI OLEH PEMOHON — status
                             "dibatalkan_reguler". Memakai getNamaPembatal /
                             getCatatanPembatalanReguler yang merujuk ke pemohon. -->
                        <div v-if="isStatusDibatalkanReguler(detailModal.data)">
                            <p class="text-sm text-slate-700 mb-1">
                                Dibatalkan oleh:
                                <span class="font-bold">{{
                                    getNamaPembatal(detailModal.data)
                                }}</span>
                            </p>
                            <p
                                class="text-sm font-medium italic text-slate-600"
                            >
                                "{{
                                    getCatatanPembatalanReguler(
                                        detailModal.data,
                                    )
                                }}"
                            </p>

                            <!-- Fitur "Riwayat Persetujuan Atasan" (L3/L4) TETAP
                                 DIPERTAHANKAN untuk pembatalan mandiri, supaya user
                                 bisa melihat sampai tahap mana pengajuan sempat
                                 diproses. Judul & warna netral (slate). -->
                            <div
                                v-if="
                                    getCatatanAtasanList(detailModal.data)
                                        .length
                                "
                                class="space-y-2 mt-3 pt-3 border-t border-slate-200"
                            >
                                <p
                                    class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1"
                                >
                                    Progres Persetujuan Sebelum Dibatalkan
                                </p>
                                <div
                                    v-for="entry in getCatatanAtasanList(
                                        detailModal.data,
                                    )"
                                    :key="entry.key"
                                    class="rounded-lg border border-slate-100 bg-slate-50/70 p-2.5"
                                >
                                    <p class="text-sm text-slate-700">
                                        L{{ entry.level }} -
                                        {{ entry.jabatan }}:
                                        <span class="font-bold">{{
                                            entry.nama
                                        }}</span>
                                    </p>
                                    <p
                                        class="mt-0.5 text-xs font-medium"
                                        :class="entry.statusColorClass"
                                    >
                                        {{ entry.catatan || entry.statusText }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- (2) DITANGGUHKAN / DIBATALKAN_DITANGGUHKAN -->
                        <div v-else-if="isStatusDitangguhkan(detailModal.data)">
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

                            <!-- Daftar L3 & L4 lengkap dengan nama pejabat dan
                                 statusnya (Disetujui / Menunggu Persetujuan) tetap
                                 ditampilkan meski pengajuan akhirnya ditangguhkan. -->
                            <div
                                v-if="
                                    getCatatanAtasanList(detailModal.data)
                                        .length
                                "
                                class="space-y-2 mt-3 pt-3 border-t border-orange-100"
                            >
                                <p
                                    class="text-[10px] font-bold text-orange-500 uppercase tracking-wider mb-1"
                                >
                                    Riwayat Persetujuan Atasan
                                </p>
                                <div
                                    v-for="entry in getCatatanAtasanList(
                                        detailModal.data,
                                    )"
                                    :key="entry.key"
                                    class="rounded-lg border border-white/80 bg-white/70 p-2.5"
                                >
                                    <p class="text-sm text-slate-700">
                                        L{{ entry.level }} -
                                        {{ entry.jabatan }}:
                                        <span class="font-bold">{{
                                            entry.nama
                                        }}</span>
                                    </p>
                                    <p
                                        class="mt-0.5 text-xs font-medium"
                                        :class="entry.statusColorClass"
                                    >
                                        {{ entry.catatan || entry.statusText }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- (3) SEMUA STATUS LAIN, TERMASUK DITOLAK & DISETUJUI:
                             render daftar L3 & L4 dari getCatatanAtasanList.
                             Sebelumnya cabang ini "kosong" khusus untuk ditolak
                             (karena fungsinya di-skip), sekarang tampil normal
                             seperti referensi tampilan yang diminta. -->
                        <div
                            v-else-if="
                                getCatatanAtasanList(detailModal.data).length
                            "
                            class="space-y-2"
                        >
                            <div
                                v-for="entry in getCatatanAtasanList(
                                    detailModal.data,
                                )"
                                :key="entry.key"
                                class="rounded-lg border border-white/80 bg-white/70 p-2.5"
                            >
                                <p class="text-sm text-slate-700">
                                    L{{ entry.level }} - {{ entry.jabatan }}:
                                    <span class="font-bold">{{
                                        entry.nama
                                    }}</span>
                                </p>
                                <p
                                    class="mt-0.5 text-xs font-medium"
                                    :class="entry.statusColorClass"
                                >
                                    {{ entry.catatan || entry.statusText }}
                                </p>
                            </div>
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
                                        log.keputusan === 'tolak'
                                            ? 'text-red-600 font-bold'
                                            : log.keputusan === 'setuju'
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
                        <div
                            v-if="liburBertepatan.length > 0"
                            class="flex items-start gap-2 bg-blue-50 border border-blue-100 rounded-xl px-3.5 py-2.5"
                        >
                            <svg
                                class="w-4 h-4 text-blue-500 mt-0.5 shrink-0"
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
                            <p class="text-xs text-blue-700 leading-relaxed">
                                <span class="font-semibold"
                                    >{{ liburBertepatan.length }} tanggal tidak
                                    dihitung</span
                                >
                                karena bertepatan dengan hari libur:
                                <span
                                    v-for="(libur, idx) in liburBertepatan"
                                    :key="libur.tanggal"
                                >
                                    <span class="font-semibold">{{
                                        libur.keterangan
                                    }}</span>
                                    ({{
                                        new Date(
                                            libur.tanggal,
                                        ).toLocaleDateString("id-ID", {
                                            day: "2-digit",
                                            month: "short",
                                        })
                                    }})<span
                                        v-if="idx < liburBertepatan.length - 1"
                                        >,
                                    </span>
                                </span>
                            </p>
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
                                class="px-5 py-2 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-bold transition shadow-md shadow-red-600/30"
                            >
                                Tolak
                            </button>
                            <button
                                type="button"
                                @click="approveCuti(detailModal.data.id)"
                                class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-sm font-bold transition shadow-md shadow-emerald-600/30"
                            >
                                Setuju
                            </button>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>

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
                <div class="px-4 pt-3 shrink-0">
                    <div class="relative">
                        <svg
                            class="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2"
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
                        <input
                            v-model="searchAnggota"
                            type="text"
                            placeholder="Cari nama anggota tim..."
                            aria-label="Cari nama anggota tim"
                            class="w-full rounded-xl border border-slate-200 bg-slate-50 pl-9 pr-3 py-2 text-sm text-slate-700 placeholder:text-slate-400 focus:border-blue-400 focus:ring-blue-400"
                        />
                    </div>
                </div>
                <div
                    class="p-4 max-h-[50vh] overflow-y-auto custom-scrollbar pr-2 space-y-2.5 flex-1"
                >
                    <div
                        v-for="anggota in filteredAnggotaTim"
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
                        v-if="filteredAnggotaTim.length === 0"
                        class="text-center py-6"
                    >
                        <p class="text-sm text-slate-500 font-medium">
                            Anggota tim tidak ditemukan...
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

    <Teleport to="body">
        <div
            v-if="showSaldoCutiModal"
            class="fixed inset-0 z-[9999] flex items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4"
            @click.self="showSaldoCutiModal = false"
        >
            <div
                class="bg-white rounded-2xl max-w-lg w-full shadow-2xl border border-slate-100 overflow-hidden flex flex-col max-h-[90vh]"
            >
                <div
                    class="p-4 border-b border-slate-100 flex items-start gap-3 shrink-0 bg-slate-50/60"
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
                                d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"
                            ></path>
                        </svg>
                    </div>
                    <div class="flex-1">
                        <h3 class="text-base font-bold text-slate-800">
                            Rincian Saldo Cuti Bawaan
                        </h3>
                        <p class="text-xs font-semibold text-slate-500 mt-0.5">
                            {{ user.nama }} &bull; posisi per {{ currentYear }}
                        </p>
                    </div>
                    <button
                        type="button"
                        @click="showSaldoCutiModal = false"
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

                <div class="p-4 overflow-y-auto flex-1 space-y-4">
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                        <div class="bg-slate-50 rounded-xl p-2.5">
                            <p class="text-[10px] font-bold text-slate-400">
                                Sisa {{ currentYear - 2 }}
                            </p>
                            <p class="text-lg font-black text-slate-800 mt-0.5">
                                {{ sisaDuaTahunLalu
                                }}<span
                                    class="text-[10px] font-semibold text-slate-400 ml-0.5"
                                    >hari</span
                                >
                            </p>
                        </div>
                        <div class="bg-slate-50 rounded-xl p-2.5">
                            <p class="text-[10px] font-bold text-slate-400">
                                Sisa {{ currentYear - 1 }}
                            </p>
                            <p class="text-lg font-black text-slate-800 mt-0.5">
                                {{ sisaSatuTahunLalu
                                }}<span
                                    class="text-[10px] font-semibold text-slate-400 ml-0.5"
                                    >hari</span
                                >
                            </p>
                        </div>
                        <div class="bg-slate-50 rounded-xl p-2.5">
                            <p class="text-[10px] font-bold text-slate-400">
                                Sisa {{ currentYear }}
                            </p>
                            <p class="text-lg font-black text-slate-800 mt-0.5">
                                {{ jatahTahunIniUntukSaldo
                                }}<span
                                    class="text-[10px] font-semibold text-slate-400 ml-0.5"
                                    >hari</span
                                >
                            </p>
                        </div>
                        <div class="bg-emerald-50 rounded-xl p-2.5">
                            <p class="text-[10px] font-bold text-emerald-600">
                                Bawaan eligible
                            </p>
                            <p
                                class="text-lg font-black text-emerald-700 mt-0.5"
                            >
                                {{ saldoBawaanEligible
                                }}<span
                                    class="text-[10px] font-semibold text-emerald-600 ml-0.5"
                                    >hari</span
                                >
                            </p>
                        </div>
                    </div>

                    <div>
                        <p
                            class="text-[11px] font-extrabold text-slate-400 uppercase tracking-wider mb-2"
                        >
                            Cara perhitungan
                        </p>

                        <div class="flex gap-2.5 mb-3">
                            <div
                                class="w-5 h-5 rounded-full flex items-center justify-center text-white text-[11px] font-bold shrink-0 mt-0.5"
                                :class="
                                    akumulasiPenuh
                                        ? 'bg-emerald-500'
                                        : 'bg-red-500'
                                "
                            >
                                {{ akumulasiPenuh ? "✓" : "✕" }}
                            </div>
                            <div>
                                <p class="text-xs font-bold text-slate-700">
                                    {{
                                        akumulasiPenuh
                                            ? "Syarat akumulasi penuh terpenuhi"
                                            : "Syarat akumulasi penuh tidak terpenuhi"
                                    }}
                                </p>
                                <p
                                    class="text-xs text-slate-500 mt-0.5 leading-relaxed"
                                >
                                    Akumulasi 12 hari hanya diberikan bila sisa
                                    {{ currentYear - 2 }} dan sisa
                                    {{ currentYear - 1 }} sama-sama utuh 12
                                    hari.
                                    <template v-if="!akumulasiPenuh">
                                        Sisa {{ currentYear - 2 }} tercatat
                                        {{ sisaDuaTahunLalu }} hari, sehingga
                                        syarat ini tidak terpenuhi.
                                    </template>
                                </p>
                            </div>
                        </div>

                        <div v-if="!akumulasiPenuh" class="flex gap-2.5">
                            <div
                                class="w-5 h-5 rounded-full bg-emerald-500 flex items-center justify-center text-white text-[11px] font-bold shrink-0 mt-0.5"
                            >
                                ✓
                            </div>
                            <div>
                                <p class="text-xs font-bold text-slate-700">
                                    Berlaku aturan alternatif
                                </p>
                                <p
                                    class="text-xs text-slate-500 mt-0.5 leading-relaxed"
                                >
                                    Sistem mengambil nilai terkecil antara batas
                                    bawaan 6 hari dan sisa
                                    {{ currentYear - 1 }} ({{
                                        sisaSatuTahunLalu
                                    }}
                                    hari). Hasilnya {{ saldoBawaanEligible }}
                                    hari.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="flex gap-2.5 p-3 bg-emerald-50 rounded-xl">
                        <svg
                            class="w-4 h-4 text-emerald-600 shrink-0 mt-0.5"
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
                        <p class="text-xs text-emerald-700 leading-relaxed">
                            Saldo {{ currentYear - 2 }} tidak hangus, tetapi
                            hanya sebagian yang bisa dibawa karena akumulasi
                            penuh membutuhkan dua tahun berturut-turut tanpa
                            pemakaian. Sisa {{ currentYear }} dihitung terpisah
                            dari aturan ini.
                        </p>
                    </div>
                </div>

                <div
                    class="p-3.5 border-t border-slate-100 bg-slate-50 flex justify-end shrink-0"
                >
                    <button
                        type="button"
                        @click="showSaldoCutiModal = false"
                        class="px-4 py-1.5 bg-slate-800 hover:bg-slate-900 text-white rounded-xl text-sm font-bold transition cursor-pointer shadow-sm"
                    >
                        Tutup
                    </button>
                </div>
            </div>
        </div>
    </Teleport>

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
                    <div class="bg-sky-50 text-sky-600 p-2 rounded-xl">
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
                                d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"
                            ></path>
                        </svg>
                    </div>
                    <div class="flex-1">
                        <h3 class="text-base font-bold text-slate-800">
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
                    <template v-if="onLeaveTeamMembers.length > 0">
                        <div
                            v-for="cuti in onLeaveTeamMembers"
                            :key="cuti.id"
                            class="flex items-center gap-3 p-2.5 border border-slate-100 rounded-xl bg-white hover:border-sky-100 hover:shadow-sm transition-all"
                        >
                            <div
                                class="w-9 h-9 rounded-full bg-slate-100 text-slate-500 flex items-center justify-center font-bold text-sm shrink-0 overflow-hidden border border-slate-200"
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
                                    {{ cuti.pegawai?.nama || "Pegawai" }}
                                </p>
                                <p
                                    class="text-xs text-slate-500 mt-0.5 truncate font-medium"
                                >
                                    <span class="text-indigo-600 font-bold">{{
                                        cuti.jenis_cuti || "Cuti"
                                    }}</span>
                                    &bull; {{ cuti.jumlah_hari }} Hari
                                </p>
                            </div>
                        </div>
                    </template>
                    <div v-else class="text-center py-6">
                        <p class="text-sm text-slate-500 font-medium">
                            Tidak ada anggota tim yang cuti pada tanggal ini.
                        </p>
                    </div>
                </div>

                <div
                    class="p-3.5 border-t border-slate-100 bg-slate-50 flex justify-end shrink-0"
                >
                    <button
                        type="button"
                        @click="showOnLeaveModal = false"
                        class="px-4 py-1.5 bg-slate-800 hover:bg-slate-900 text-white rounded-xl text-sm font-bold transition cursor-pointer shadow-sm"
                    >
                        Tutup
                    </button>
                </div>
            </div>
        </div>
    </Teleport>

    <!-- ================================================================
         MODAL KUSTOM "SETUJUI PENGAJUAN CUTI" — menggantikan window.confirm()
         bawaan browser. Muncul saat tombol "Setuju" pada Detail Modal
         diklik. Menampilkan ringkasan KARYAWAN / JENIS CUTI / TANGGAL,
         serta catatan bahwa saldo akan dipotong otomatis (khusus jenis
         Cuti Tahunan). Tombol "Ya, Setujui" memanggil confirmApproveCuti()
         yang mengirim request approve seperti semula.
    ================================================================ -->
    <Teleport to="body">
        <div
            v-if="approveModal.show"
            class="fixed inset-0 z-[9999] flex items-center justify-center bg-slate-900/40 backdrop-blur-sm p-4"
            @click.self="closeApproveModal"
        >
            <div
                class="bg-white rounded-2xl max-w-md w-full shadow-2xl border border-slate-100 overflow-hidden"
            >
                <div
                    class="p-5 border-b border-slate-100 flex items-start justify-between gap-3 bg-emerald-50/60"
                >
                    <div class="flex items-center gap-2.5">
                        <div
                            class="bg-emerald-100 text-emerald-600 p-1.5 rounded-full shrink-0"
                        >
                            <svg
                                class="w-5 h-5"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                                stroke-width="2.5"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M5 13l4 4L19 7"
                                ></path>
                            </svg>
                        </div>
                        <h3 class="text-base font-extrabold text-slate-800">
                            Setujui Pengajuan Cuti
                        </h3>
                    </div>
                    <button
                        type="button"
                        @click="closeApproveModal"
                        class="text-slate-400 hover:text-slate-600 hover:bg-white/70 p-1 rounded-full transition cursor-pointer shrink-0"
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

                <div class="p-5 space-y-4" v-if="approveModal.item">
                    <p class="text-sm font-medium text-slate-700">
                        Apakah Anda yakin ingin menyetujui pengajuan cuti ini?
                    </p>

                    <div
                        class="bg-slate-50 border border-slate-100 rounded-xl p-3.5 space-y-2"
                    >
                        <div class="flex items-center justify-between gap-3">
                            <span
                                class="text-[10px] font-extrabold text-slate-400 uppercase tracking-wider"
                                >Karyawan</span
                            >
                            <span
                                class="text-sm font-bold text-slate-800 text-right"
                                >{{
                                    approveModal.item.pegawai?.nama ?? "-"
                                }}</span
                            >
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span
                                class="text-[10px] font-extrabold text-slate-400 uppercase tracking-wider"
                                >Jenis Cuti</span
                            >
                            <span
                                class="text-sm font-bold text-emerald-600 text-right"
                                >{{
                                    approveModal.item.jenis_cuti ??
                                    "Cuti Tahunan"
                                }}</span
                            >
                        </div>
                        <div
                            class="flex items-start justify-between gap-3"
                            v-if="approveModal.item.tanggal_mulai"
                        >
                            <span
                                class="text-[10px] font-extrabold text-slate-400 uppercase tracking-wider pt-0.5"
                                >Tanggal</span
                            >
                            <span
                                class="text-sm font-bold text-slate-800 text-right"
                            >
                                {{ formatDate(approveModal.item.tanggal_mulai) }}
                                s/d
                                {{
                                    formatDate(
                                        approveModal.item.tanggal_selesai,
                                    )
                                }}
                                <template v-if="approveModal.item.jumlah_hari">
                                    ({{ approveModal.item.jumlah_hari }}
                                    Hari)
                                </template>
                            </span>
                        </div>
                    </div>

                    <p
                        v-if="
                            (approveModal.item.jenis_cuti ?? 'Cuti Tahunan')
                                .toLowerCase()
                                .includes('tahunan')
                        "
                        class="text-xs text-slate-500 leading-relaxed"
                    >
                        Saldo Cuti Tahunan karyawan akan dipotong otomatis
                        setelah persetujuan ini dikonfirmasi.
                    </p>
                </div>

                <div
                    class="p-4 bg-slate-50 border-t border-slate-100 flex justify-end gap-2"
                >
                    <button
                        type="button"
                        @click="closeApproveModal"
                        class="px-4 py-2 bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 rounded-xl text-sm font-bold transition cursor-pointer"
                    >
                        Batal
                    </button>
                    <button
                        type="button"
                        @click="confirmApproveCuti"
                        class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-sm font-bold transition shadow-md shadow-emerald-600/30 cursor-pointer"
                    >
                        Ya, Setujui
                    </button>
                </div>
            </div>
        </div>
    </Teleport>

    <!-- ================================================================
         MODAL KUSTOM "ALASAN PENOLAKAN CUTI" — menggantikan window.prompt()
         bawaan browser. Menampilkan ringkasan pengajuan yang sama seperti
         modal Setujui, ditambah textarea alasan penolakan.
         Alasan PENOLAKAN WAJIB DIISI: jika kosong, tampilkan validasi
         inline (border merah + pesan "Alasan penolakan wajib diisi.")
         tanpa mengirim request. Tombol "Kirim Penolakan" memanggil
         confirmRejectCuti().
    ================================================================ -->
    <Teleport to="body">
        <div
            v-if="rejectModal.show"
            class="fixed inset-0 z-[9999] flex items-center justify-center bg-slate-900/40 backdrop-blur-sm p-4"
            @click.self="closeRejectModal"
        >
            <div
                class="bg-white rounded-2xl max-w-md w-full shadow-2xl border border-slate-100 overflow-hidden"
            >
                <div
                    class="p-5 border-b border-slate-100 flex items-start justify-between gap-3 bg-red-50/60"
                >
                    <div class="flex items-center gap-2.5">
                        <div
                            class="bg-red-100 text-red-600 p-1.5 rounded-full shrink-0"
                        >
                            <svg
                                class="w-5 h-5"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                                stroke-width="2"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
                                ></path>
                            </svg>
                        </div>
                        <h3 class="text-base font-extrabold text-slate-800">
                            Alasan Penolakan Cuti
                        </h3>
                    </div>
                    <button
                        type="button"
                        @click="closeRejectModal"
                        class="text-slate-400 hover:text-slate-600 hover:bg-white/70 p-1 rounded-full transition cursor-pointer shrink-0"
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

                <div class="p-5 space-y-4" v-if="rejectModal.item">
                    <p class="text-sm font-medium text-slate-700 leading-relaxed">
                        Silakan berikan alasan atau catatan mengapa pengajuan
                        cuti ini ditolak. Alasan ini akan dibaca oleh pegawai
                        yang bersangkutan.
                    </p>

                    <div
                        class="bg-slate-50 border border-slate-100 rounded-xl p-3.5 space-y-2"
                    >
                        <div class="flex items-center justify-between gap-3">
                            <span
                                class="text-[10px] font-extrabold text-slate-400 uppercase tracking-wider"
                                >Karyawan</span
                            >
                            <span
                                class="text-sm font-bold text-slate-800 text-right"
                                >{{
                                    rejectModal.item.pegawai?.nama ?? "-"
                                }}</span
                            >
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span
                                class="text-[10px] font-extrabold text-slate-400 uppercase tracking-wider"
                                >Jenis Cuti</span
                            >
                            <span
                                class="text-sm font-bold text-red-600 text-right"
                                >{{
                                    rejectModal.item.jenis_cuti ??
                                    "Cuti Tahunan"
                                }}</span
                            >
                        </div>
                        <div
                            class="flex items-start justify-between gap-3"
                            v-if="rejectModal.item.tanggal_mulai"
                        >
                            <span
                                class="text-[10px] font-extrabold text-slate-400 uppercase tracking-wider pt-0.5"
                                >Tanggal</span
                            >
                            <span
                                class="text-sm font-bold text-slate-800 text-right"
                            >
                                {{ formatDate(rejectModal.item.tanggal_mulai) }}
                                s/d
                                {{
                                    formatDate(rejectModal.item.tanggal_selesai)
                                }}
                                <template v-if="rejectModal.item.jumlah_hari">
                                    ({{ rejectModal.item.jumlah_hari }}
                                    Hari)
                                </template>
                            </span>
                        </div>
                    </div>

                    <div>
                        <label
                            class="block text-[11px] font-extrabold uppercase tracking-wider mb-1.5"
                            :class="
                                rejectError
                                    ? 'text-red-600'
                                    : 'text-slate-500'
                            "
                        >
                            Alasan Penolakan
                            <span class="text-red-500">*</span>
                        </label>
                        <textarea
                            v-model="rejectModal.alasan"
                            rows="4"
                            :class="[
                                'w-full text-sm font-medium rounded-xl focus:outline-none transition',
                                rejectError
                                    ? 'border-2 border-red-500 focus:ring-red-500 focus:border-red-500 bg-red-50/30'
                                    : 'border border-slate-200 focus:ring-red-500 focus:border-red-500',
                            ]"
                            placeholder="Contoh: Masih ada tugas proyek mendesak yang harus diselesaikan..."
                            @input="rejectError = false"
                        ></textarea>
                        <p
                            v-if="rejectError"
                            class="mt-1.5 flex items-center gap-1.5 text-xs font-semibold text-red-600"
                        >
                            <svg
                                class="w-3.5 h-3.5 shrink-0"
                                fill="currentColor"
                                viewBox="0 0 20 20"
                            >
                                <path
                                    fill-rule="evenodd"
                                    d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z"
                                    clip-rule="evenodd"
                                />
                            </svg>
                            Alasan penolakan wajib diisi.
                        </p>
                    </div>
                </div>

                <div
                    class="p-4 bg-slate-50 border-t border-slate-100 flex justify-end gap-2"
                >
                    <button
                        type="button"
                        @click="closeRejectModal"
                        class="px-4 py-2 bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 rounded-xl text-sm font-bold transition cursor-pointer"
                    >
                        Batal
                    </button>
                    <button
                        type="button"
                        @click="confirmRejectCuti"
                        class="px-5 py-2 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-bold transition shadow-md shadow-red-600/30 cursor-pointer"
                    >
                        Kirim Penolakan
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>