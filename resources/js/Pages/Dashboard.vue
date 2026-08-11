<script setup>
import MainLayout from "@/Layouts/MainLayout.vue";
import { Head, usePage, Link, router } from "@inertiajs/vue3";
import { computed, ref } from "vue";

const props = defineProps({
    stats: Object,
    recentCuti: Array,
    chartDataBackend: {
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
});

const page = usePage();
const user = computed(() => page.props.auth.user);

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
        case "menunggu_l2":
        case "menunggu_l3":
            return {
                text: "Menunggu",
                class: "bg-amber-100/70 text-amber-700 border border-amber-200/50 backdrop-blur-sm",
            };
        case "disetujui":
            return {
                text: "Disetujui",
                class: "bg-green-100/70 text-green-700 border border-green-200/50 backdrop-blur-sm",
            };
        case "ditolak":
            return {
                text: "Ditolak",
                class: "bg-red-100/70 text-red-700 border border-red-200/50 backdrop-blur-sm",
            };
        case "dibatalkan_reguler":
            return {
                text: "Dibatalkan",
                class: "bg-slate-100/70 text-slate-600 border border-slate-200/50 backdrop-blur-sm",
            };
        case "dibatalkan_ditangguhkan":
            return {
                text: "Ditangguhkan",
                class: "bg-orange-100/70 text-orange-700 border border-orange-200/50 backdrop-blur-sm",
            };
        default:
            return {
                text: status,
                class: "bg-slate-100/70 text-slate-600 border border-slate-200/50 backdrop-blur-sm",
            };
    }
};

const currentYear = new Date().getFullYear();

const chartData = computed(() => {
    const months = ["Jan", "Feb", "Mar", "Apr", "Mei", "Jun", "Jul", "Ags", "Sep", "Okt", "Nov", "Des"];
    if (props.chartDataBackend && props.chartDataBackend.length === 12) {
        return months.map((month, index) => ({ month: month, days: props.chartDataBackend[index] }));
    }
    const data = months.map((month) => ({ month, days: 0 }));
    if (props.recentCuti && props.recentCuti.length > 0) {
        props.recentCuti.forEach((cuti) => {
            if (cuti.status === "disetujui") {
                const date = new Date(cuti.tanggal_mulai);
                const monthIndex = date.getMonth();
                if (monthIndex >= 0 && monthIndex <= 11) {
                    data[monthIndex].days += cuti.jumlah_hari;
                }
            }
        });
    }
    return data;
});

const maxDays = computed(() => {
    const max = Math.max(...chartData.value.map((d) => d.days));
    return max > 5 ? max : 5;
});

const upcomingHolidays = computed(() => {
    const todayStr = new Date().toISOString().split("T")[0];
    if (!props.hariLiburs) return [];
    return props.hariLiburs.filter((item) => item.tanggal >= todayStr).slice(0, 3);
});

const pegawaiCutiHariIni = computed(() => {
    const todayStr = new Date().toISOString().split("T")[0];
    if (!props.recentCuti) return [];
    return props.recentCuti.filter((cuti) => {
        return cuti.status === "disetujui" && cuti.tanggal_mulai <= todayStr && cuti.tanggal_selesai >= todayStr;
    });
});

const cutiDisetujuiBulanIniList = computed(() => {
    if (!props.recentCuti) return [];
    const currentMonth = new Date().getMonth();
    const currentYr = new Date().getFullYear();
    return props.recentCuti.filter((cuti) => {
        if (cuti.status !== "disetujui") return false;
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
    return new Intl.DateTimeFormat("id-ID", { month: "long", year: "numeric" }).format(baseDate.value);
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
        const onLeave = props.recentCuti ? props.recentCuti.filter((c) => c.status === "disetujui" && c.tanggal_mulai <= dateStr && c.tanggal_selesai >= dateStr) : [];
        result.push({
            name: daysName[i], dateNumber: dateObj.getDate(), fullDate: dateStr, isToday: dateStr === todayStr, isWeekend: i >= 5, onLeave: onLeave,
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

const openDetailModal = (item) => { detailModal.value.data = item; detailModal.value.show = true; };
const closeDetailModal = () => { detailModal.value.show = false; detailModal.value.data = null; };

const suspendData = ref({ show: false, id: null, alasan: "" });
const openSuspendModal = (id) => { suspendData.value.id = id; suspendData.value.alasan = ""; suspendData.value.show = true; };
const closeSuspendModal = () => { suspendData.value.show = false; };
const submitSuspend = () => {
    if (!suspendData.value.alasan.trim()) { alert("Mohon isi alasan penangguhan terlebih dahulu."); return; }
    router.post(route("admin.cuti.tangguhkan", suspendData.value.id), { alasan: suspendData.value.alasan }, { preserveScroll: true, onSuccess: () => closeSuspendModal() });
};

const showOnLeaveModal = ref(false);
const selectedDateForModal = ref(null);
const openOnLeaveModal = (day) => { selectedDateForModal.value = day; showOnLeaveModal.value = true; };
const onLeaveTeamMembers = computed(() => { if (!selectedDateForModal.value) return []; return selectedDateForModal.value.onLeave; });

const approveCuti = (id) => { if (confirm("Anda yakin ingin MENGESAHKAN pengajuan cuti ini?")) { router.post(route("atasan.approval.approve", id), {}, { preserveScroll: true }); } };
const rejectCuti = (id) => { const alasan = prompt("Masukkan alasan penolakan (opsional):"); if (alasan !== null) { router.post(route("atasan.approval.reject", id), { catatan: alasan }, { preserveScroll: true }); } };
</script>

<template>
    <Head title="Dashboard" />

    <MainLayout>
        <div class="relative space-y-6 max-w-7xl mx-auto pb-12 pt-2">
            
            <svg class="absolute -top-10 -right-10 opacity-[0.08] pointer-events-none w-[32rem] h-[32rem] text-green-800 z-0 transform rotate-12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="0.3">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 22c0-4.5 1-8 4-11s7-4 7-4-4 1-7 4-4 6.5-4 11zm0 0c0-4.5-1-8-4-11s-7-4-7-4 4 1 7 4 4 6.5 4 11z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 16c2.5-1.5 4.5-4 5-7M12 16c-2.5-1.5-4.5-4-5-7" />
            </svg>
            
            <svg class="absolute top-[24rem] -left-16 opacity-[0.08] pointer-events-none w-[28rem] h-[28rem] text-green-800 z-0 transform -rotate-45" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="0.3">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 22c0-4.5 1-8 4-11s7-4 7-4-4 1-7 4-4 6.5-4 11zm0 0c0-4.5-1-8-4-11s-7-4-7-4 4 1 7 4 4 6.5 4 11z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 16c2.5-1.5 4.5-4 5-7M12 16c-2.5-1.5-4.5-4-5-7" />
            </svg>

            <div class="relative z-10 flex flex-col md:flex-row md:items-end justify-between gap-4 mb-6">
                <div>
                    <h1 class="text-3xl md:text-[32px] font-bold text-slate-800 tracking-tight leading-tight">
                        Selamat datang, {{ user.nama }}
                    </h1>
                    <p class="text-slate-500 mt-1 text-sm font-medium">
                        Pantau sisa cuti dan kelola permohonan Anda dengan mudah hari ini.
                    </p>
                </div>
                <div v-if="[1, 2, 3, 4].includes(user.role_id)">
                    <Link
                        :href="route('karyawan.ajukan')"
                        class="inline-flex items-center justify-center gap-2 px-6 py-3 bg-gradient-to-r from-green-500 to-green-600 text-white rounded-xl text-sm font-bold shadow-[0_8px_20px_rgba(34,197,94,0.3)] hover:scale-[1.02] hover:shadow-[0_10px_25px_rgba(34,197,94,0.4)] transition-all duration-300"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"></path></svg>
                        Ajukan Cuti Baru
                    </Link>
                </div>
            </div>

            <!-- ========================================== -->
            <!-- 1. WIDGET STATISTIK KHUSUS PEGAWAI (ROLE 1)-->
            <!-- ========================================== -->
            <div v-if="user.role_id === 1" class="relative z-10 grid grid-cols-1 md:grid-cols-4 gap-5">
                <div class="relative overflow-hidden bg-gradient-to-br from-blue-50/80 to-white/40 backdrop-blur-2xl border border-white rounded-[2rem] p-6 shadow-[0_8px_30px_rgb(0,0,0,0.04)] flex flex-col justify-between group">
                    <div class="relative z-10">
                        <p class="text-[10px] font-extrabold text-blue-900/60 uppercase tracking-widest mb-3">Jatah Cuti {{ currentYear }}</p>
                        <div class="flex items-baseline gap-1">
                            <p class="text-4xl font-black text-slate-800">{{ stats.kuota_tahunan || 12 }}</p>
                            <p class="text-sm font-bold text-slate-500">Hari</p>
                        </div>
                        <p class="text-[11px] text-slate-500 mt-2 font-semibold">Maksimum {{ stats.kuota_tahunan || 12 }} hari</p>
                    </div>
                    <img src="/images/kalender-3d.png" class="absolute -bottom-2 -right-2 w-24 h-24 object-contain drop-shadow-2xl group-hover:scale-110 transition-transform duration-500" alt="" />
                </div>

                <div class="relative overflow-hidden bg-gradient-to-br from-orange-50/80 to-white/40 backdrop-blur-2xl border border-white rounded-[2rem] p-6 shadow-[0_8px_30px_rgb(0,0,0,0.04)] flex flex-col justify-between group">
                    <div class="relative z-10">
                        <p class="text-[10px] font-extrabold text-orange-900/60 uppercase tracking-widest mb-3">Sisa Tahun Kemarin</p>
                        <div class="flex items-baseline gap-1">
                            <p class="text-4xl font-black text-slate-800">{{ stats.sisa_cuti_tahun_lalu || 0 }}</p>
                            <p class="text-sm font-bold text-slate-500">Hari</p>
                        </div>
                        <p class="text-[11px] text-slate-500 mt-2 font-semibold">Sisa Tahun N-1</p>
                    </div>
                    <img src="/images/jam-3d.png" class="absolute -bottom-2 -right-2 w-24 h-24 object-contain drop-shadow-2xl group-hover:scale-110 transition-transform duration-500" alt="" />
                </div>

                <div class="relative overflow-hidden bg-gradient-to-br from-rose-50/80 to-white/40 backdrop-blur-2xl border border-white rounded-[2rem] p-6 shadow-[0_8px_30px_rgb(0,0,0,0.04)] flex flex-col justify-between group">
                    <div class="relative z-10">
                        <p class="text-[10px] font-extrabold text-rose-900/60 uppercase tracking-widest mb-3">Cuti Terpakai</p>
                        <div class="flex items-baseline gap-1">
                            <p class="text-4xl font-black text-slate-800">{{ ((stats.kuota_tahunan || 12) + (stats.sisa_cuti_tahun_lalu || 0)) - (stats.sisa_cuti || 0) }}</p>
                            <p class="text-sm font-bold text-slate-500">Hari</p>
                        </div>
                        <p class="text-[11px] text-slate-500 mt-2 font-semibold">Sudah Terpakai Tahun Ini</p>
                    </div>
                    <img src="/images/ceklis-3d.png" class="absolute -bottom-2 -right-2 w-24 h-24 object-contain drop-shadow-2xl group-hover:scale-110 transition-transform duration-500" alt="" />
                </div>

                <div class="relative overflow-hidden bg-gradient-to-br from-emerald-50/80 to-white/40 backdrop-blur-2xl border border-white rounded-[2rem] p-6 shadow-[0_8px_30px_rgb(0,0,0,0.04)] flex flex-col justify-between group">
                    <div class="relative z-10">
                        <p class="text-[10px] font-extrabold text-emerald-900/60 uppercase tracking-widest mb-3">Total Cuti Tersedia</p>
                        <div class="flex items-baseline gap-1">
                            <p class="text-4xl font-black text-slate-800">{{ stats.sisa_cuti }}</p>
                            <p class="text-sm font-bold text-slate-500">Hari</p>
                        </div>
                        <p class="text-[11px] text-slate-500 mt-2 font-semibold">Siap Dipakai Kapan Saja</p>
                    </div>
                    <img src="/images/koin-3d.png" class="absolute -bottom-2 -right-2 w-24 h-24 object-contain drop-shadow-2xl group-hover:scale-110 transition-transform duration-500" alt="" />
                </div>
            </div>

            <!-- ============================================================== -->
            <!-- 2. WIDGET STATISTIK KHUSUS ATASAN & ADMIN (ROLE 2, 3, 4, 5) -->
            <!-- ============================================================== -->
            <div v-if="[2, 3, 4, 5].includes(user.role_id)" class="relative z-10 grid grid-cols-1 md:grid-cols-3 gap-5">
                
                <!-- KARTU 1: PERLU PERSETUJUAN -->
                <Link
                    :href="route('atasan.approval')"
                    class="relative bg-gradient-to-br from-amber-50/80 to-white/50 backdrop-blur-2xl border border-white rounded-[2rem] p-6 shadow-[0_8px_30px_rgb(0,0,0,0.04)] flex flex-col justify-between transition-all duration-300 hover:shadow-lg hover:-translate-y-1 cursor-pointer overflow-hidden group"
                >
                    <div class="flex justify-between items-start relative z-10">
                        <p class="text-[10px] font-extrabold text-amber-600/80 uppercase tracking-widest">Perlu Persetujuan</p>
                        <div class="text-amber-500 bg-amber-100/50 p-2.5 rounded-xl shadow-sm border border-amber-100">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path></svg>
                        </div>
                    </div>
                    <div class="mt-4 flex items-baseline gap-1.5 relative z-10">
                        <p class="text-4xl font-black text-slate-800">{{ stats.total_antrean ?? stats.pengajuan_menunggu ?? 0 }}</p>
                        <p class="text-sm font-bold text-slate-500">Antrean</p>
                    </div>
                    <!-- Ornamen background opsional -->
                    <div class="absolute -bottom-6 -right-6 text-amber-500/5 group-hover:text-amber-500/10 transition-colors">
                         <svg class="w-32 h-32" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm-1-13h2v6h-2zm0 8h2v2h-2z"/></svg>
                    </div>
                </Link>

                <!-- KARTU 2: TOTAL CUTI DISETUJUI -->
                <div
                    @click="approvedModal.show = true"
                    class="relative bg-gradient-to-br from-green-50/80 to-white/50 backdrop-blur-2xl border border-white rounded-[2rem] p-6 shadow-[0_8px_30px_rgb(0,0,0,0.04)] flex flex-col justify-between transition-all duration-300 hover:shadow-lg hover:-translate-y-1 cursor-pointer overflow-hidden group"
                >
                    <div class="flex justify-between items-start relative z-10">
                        <p class="text-[10px] font-extrabold text-green-600/80 uppercase tracking-widest">Total Cuti Disetujui</p>
                        <div class="text-green-500 bg-green-100/50 p-2.5 rounded-xl shadow-sm border border-green-100">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        </div>
                    </div>
                    <div class="mt-4 flex items-baseline gap-1.5 relative z-10">
                        <p class="text-4xl font-black text-slate-800">{{ stats.cuti_tim_bulan_ini ?? stats.pengajuan_disetujui ?? 0 }}</p>
                        <p class="text-sm font-bold text-slate-500">Bulan Ini</p>
                    </div>
                    <div class="absolute -bottom-6 -right-6 text-green-500/5 group-hover:text-green-500/10 transition-colors">
                         <svg class="w-32 h-32" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
                    </div>
                </div>

                <!-- KARTU 3: TOTAL ANGGOTA TIM / PEGAWAI -->
                <div
                    @click="showTeamModal = true"
                    class="relative bg-gradient-to-br from-blue-50/80 to-white/50 backdrop-blur-2xl border border-white rounded-[2rem] p-6 shadow-[0_8px_30px_rgb(0,0,0,0.04)] flex flex-col justify-between transition-all duration-300 hover:shadow-lg hover:-translate-y-1 cursor-pointer overflow-hidden group"
                >
                    <div class="flex justify-between items-start relative z-10">
                        <p class="text-[10px] font-extrabold text-blue-600/80 uppercase tracking-widest">
                            {{ user.role_id === 5 ? 'Total Pegawai' : 'Total Anggota Tim' }}
                        </p>
                        <div class="text-blue-500 bg-blue-100/50 p-2.5 rounded-xl shadow-sm border border-blue-100">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                        </div>
                    </div>
                    <div class="mt-4 flex items-baseline gap-1.5 relative z-10">
                        <p class="text-4xl font-black text-slate-800">{{ stats.total_pegawai ?? stats.total_anggota_tim ?? anggotaTim.length }}</p>
                        <p class="text-sm font-bold text-slate-500">{{ user.role_id === 5 ? 'Terdaftar' : 'Orang' }}</p>
                    </div>
                    <div class="absolute -bottom-6 -right-6 text-blue-500/5 group-hover:text-blue-500/10 transition-colors">
                         <svg class="w-32 h-32" fill="currentColor" viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
                    </div>
                </div>
            </div>

            <!-- BAGIAN TENGAH: GRAFIK & WIDGET HARI LIBUR -->
            <div class="relative z-10 grid grid-cols-1 lg:grid-cols-3 gap-5">
                <div class="bg-white/60 backdrop-blur-2xl border border-white rounded-[2rem] p-7 shadow-[0_8px_30px_rgb(0,0,0,0.04)] lg:col-span-2">
                    <div class="flex justify-between items-center mb-8">
                        <h3 class="text-base font-extrabold text-slate-800 tracking-tight">
                            {{ user.role_id === 1 ? "Penggunaan Cuti Pribadi" : "Tren Cuti Tim" }}
                        </h3>
                        <select class="text-xs font-bold border-white/60 rounded-xl text-slate-600 focus:ring-green-500 focus:border-green-500 py-2 pl-4 pr-10 bg-white/50 backdrop-blur-md cursor-pointer shadow-sm">
                            <option :value="currentYear">Tahun {{ currentYear }}</option>
                            <option :value="currentYear - 1">Tahun {{ currentYear - 1 }}</option>
                        </select>
                    </div>

                    <div class="relative h-56 flex items-end justify-between gap-1 sm:gap-2">
                        <div class="absolute inset-0 flex flex-col justify-between pb-7 opacity-20 pointer-events-none">
                            <div class="border-t-2 border-slate-300 border-dashed w-full"></div>
                            <div class="border-t-2 border-slate-300 border-dashed w-full"></div>
                            <div class="border-t-2 border-slate-300 border-dashed w-full"></div>
                            <div class="border-t-2 border-slate-300 border-dashed w-full"></div>
                        </div>

                        <div v-for="(data, index) in chartData" :key="index" class="relative flex flex-col items-center flex-1 h-full justify-end group z-10">
                            <div
                                class="w-full sm:w-8 md:w-11 rounded-xl transition-all duration-700 relative"
                                :class="data.days > 0 ? 'bg-gradient-to-t from-green-500 to-green-300 shadow-[0_0_20px_rgba(74,222,128,0.5)]' : 'bg-transparent'"
                                :style="`height: ${(data.days / maxDays) * 100}%`"
                            >
                                <div v-if="data.days > 0" class="opacity-0 group-hover:opacity-100 absolute -top-9 left-1/2 -translate-x-1/2 bg-slate-800 text-white text-xs font-bold py-1 px-2.5 rounded-lg transition-opacity whitespace-nowrap shadow-xl pointer-events-none z-20">
                                    {{ data.days }} Hari
                                </div>
                            </div>
                            <span class="text-[11px] font-bold text-slate-500 mt-4 absolute bottom-0 translate-y-full">{{ data.month }}</span>
                        </div>
                    </div>
                    <div class="h-8"></div>
                </div>

                <div class="flex flex-col gap-5">
                    <div class="bg-white/60 backdrop-blur-2xl border border-white rounded-[2rem] p-7 shadow-[0_8px_30px_rgb(0,0,0,0.04)] flex-1 flex flex-col relative overflow-hidden">
                        <h3 class="text-sm font-extrabold text-slate-800 mb-5 flex items-center gap-2 relative z-10">
                            <svg class="w-5 h-5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                            Hari Libur Terdekat
                        </h3>

                        <div class="space-y-3 flex-1 overflow-y-auto pr-1 relative z-10 custom-scrollbar">
                            <div v-for="libur in upcomingHolidays" :key="libur.id" class="p-4 rounded-2xl bg-white/50 backdrop-blur-md border border-white shadow-sm flex items-center gap-4 hover:shadow-md transition-shadow group">
                                <div class="shrink-0 group-hover:scale-110 transition-transform">
                                    <img src="/images/kalender-3d.png" class="w-10 h-10 object-contain drop-shadow-md" alt="Ikon Kalender" />
                                </div>
                                <div>
                                    <h4 class="text-xs font-bold text-slate-800 leading-tight">{{ libur.keterangan }}</h4>
                                    <p class="text-[11px] text-slate-500 mt-1 font-semibold">{{ formatDate(libur.tanggal) }}</p>
                                </div>
                            </div>
                            <div v-if="upcomingHolidays.length === 0" class="text-center py-6 text-xs font-medium text-slate-400">
                                Belum ada jadwal libur terdekat.
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TABEL RIWAYAT TERBARU / ANTREAN -->
            <div class="relative z-10 bg-white/60 backdrop-blur-2xl border border-white rounded-[2rem] shadow-[0_8px_30px_rgb(0,0,0,0.04)] overflow-hidden mt-6">
                <div class="px-7 py-5 border-b border-white/50 flex justify-between items-center bg-white/40">
                    <h3 class="text-base font-extrabold text-slate-800 tracking-tight">
                        {{ user.role_id === 1 ? "Riwayat Pengajuan Terbaru" : "Aktivitas Cuti Tim Terbaru" }}
                    </h3>
                    <Link
                        :href="user.role_id === 1 ? route('karyawan.riwayat') : (user.role_id === 5 ? route('admin.rekap') : route('atasan.approval'))"
                        class="text-xs font-extrabold text-green-600 hover:text-green-700 transition uppercase tracking-wider"
                    >
                        Lihat Semua
                    </Link>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full">
                        <thead>
                            <tr class="border-b border-white/50 bg-white/30">
                                <th v-if="user.role_id !== 1" class="px-7 py-4 text-left text-[10px] font-extrabold text-slate-400 uppercase tracking-widest">Nama Pegawai</th>
                                <th class="px-7 py-4 text-left text-[10px] font-extrabold text-slate-400 uppercase tracking-widest">Tanggal Cuti</th>
                                <th class="px-7 py-4 text-left text-[10px] font-extrabold text-slate-400 uppercase tracking-widest">Durasi</th>
                                <th class="px-7 py-4 text-left text-[10px] font-extrabold text-slate-400 uppercase tracking-widest">Keterangan</th>
                                <th class="px-7 py-4 text-left text-[10px] font-extrabold text-slate-400 uppercase tracking-widest">Status</th>
                                <th class="px-7 py-4 text-center text-[10px] font-extrabold text-slate-400 uppercase tracking-widest">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/50">
                            <tr v-for="item in recentCuti" :key="item.id" class="hover:bg-white/50 transition-colors cursor-pointer" @click="openDetailModal(item)">
                                <td v-if="user.role_id !== 1" class="px-7 py-5 whitespace-nowrap text-sm font-bold text-slate-800">
                                    {{ item.pegawai?.nama ?? "-" }}
                                </td>
                                <td class="px-7 py-5 whitespace-nowrap text-xs text-slate-600 font-semibold">
                                    {{ formatDate(item.tanggal_mulai) }} - {{ formatDate(item.tanggal_selesai) }}
                                </td>
                                <td class="px-7 py-5 whitespace-nowrap text-xs font-semibold text-slate-600">
                                    {{ item.jumlah_hari }} Hari
                                </td>
                                <td class="px-7 py-5 text-xs font-medium text-slate-600 max-w-[150px] truncate" :title="item.keterangan">
                                    {{ item.keterangan ? item.keterangan.split("|")[0].trim() : "-" }}
                                </td>
                                <td class="px-7 py-5 whitespace-nowrap">
                                    <span class="px-4 py-1.5 inline-flex text-[10px] font-extrabold rounded-xl uppercase tracking-wider shadow-sm" :class="formatStatus(item.status).class">
                                        {{ formatStatus(item.status).text }}
                                    </span>
                                </td>
                                <td class="px-7 py-5 whitespace-nowrap text-center" @click.stop>
                                    <div class="flex items-center justify-center gap-2">
                                        <button type="button" @click="openDetailModal(item)" class="px-4 py-1.5 bg-slate-800 hover:bg-slate-900 text-white rounded-xl text-[10px] font-extrabold uppercase tracking-widest transition shadow-sm">
                                            DETAIL
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="recentCuti.length === 0">
                                <td :colspan="user.role_id !== 1 ? 6 : 5" class="px-7 py-16 text-center text-slate-500">
                                    <p class="text-sm font-semibold">Belum ada aktivitas pengajuan cuti.</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ========================================================================= -->
            <!-- 3. WIDGET KETERSEDIAAN TIM MINGGU INI KHUSUS ATASAN & ADMIN (ROLE 2,3,4,5)-->
            <!-- ========================================================================= -->
            <div v-if="[2, 3, 4, 5].includes(user.role_id)" class="relative z-10 bg-white/60 backdrop-blur-2xl border border-white rounded-[2rem] shadow-[0_8px_30px_rgb(0,0,0,0.04)] mt-6 p-7">
                <div class="flex flex-col md:flex-row md:justify-between md:items-center mb-6 gap-4">
                    <h3
                        class="text-base font-extrabold text-slate-800 cursor-pointer hover:text-blue-600 transition flex items-center gap-2"
                        @click="showTeamModal = true"
                        title="Lihat Daftar Anggota Tim"
                    >
                        Ketersediaan Tim Minggu Ini
                        <span class="text-xs font-bold text-slate-500 bg-white/80 border border-white px-3 py-1 rounded-xl shadow-sm">{{ currentMonthYear }}</span>
                        <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    </h3>
                    <div class="flex gap-2">
                        <button @click="prevWeek" class="p-2 bg-white/50 hover:bg-white border border-white rounded-xl text-slate-500 shadow-sm transition-all hover:scale-105 cursor-pointer">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"></path></svg>
                        </button>
                        <button @click="nextWeek" class="p-2 bg-white/50 hover:bg-white border border-white rounded-xl text-slate-500 shadow-sm transition-all hover:scale-105 cursor-pointer">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"></path></svg>
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-7 gap-2 md:gap-4">
                    <div v-for="day in weekDays" :key="day.fullDate" class="flex flex-col">
                        <div
                            class="text-center text-[10px] font-extrabold mb-2 uppercase tracking-widest"
                            :class="day.isToday ? 'text-green-600' : 'text-slate-400'"
                        >
                            {{ day.name }}
                        </div>
                        <div
                            class="relative flex flex-col items-center pt-3 pb-2 px-1 rounded-2xl h-20 md:h-24 transition-all cursor-pointer hover:border-red-300 hover:shadow-md border border-white/50"
                            @click="openOnLeaveModal(day)"
                            :class="[
                                day.isToday ? 'bg-gradient-to-b from-green-50 to-white shadow-sm border-green-200' : 'bg-white/40',
                                day.isWeekend && !day.isToday ? 'opacity-50' : '',
                            ]"
                            title="Klik untuk melihat Tim yang Cuti"
                        >
                            <span
                                class="text-base font-black"
                                :class="day.isToday ? 'text-green-600' : 'text-slate-700'"
                            >
                                {{ day.dateNumber }}
                            </span>

                            <div v-if="day.onLeave.length > 0 && !day.isWeekend" class="mt-auto flex -space-x-1.5 md:-space-x-2">
                                <template v-for="cuti in day.onLeave.slice(0, 3)" :key="cuti.id">
                                    <div
                                        class="w-6 h-6 md:w-7 md:h-7 rounded-full border-2 border-white bg-red-100 text-red-600 flex items-center justify-center text-[9px] font-bold z-10 overflow-hidden shadow-sm"
                                        :title="cuti.pegawai?.nama || 'Pegawai'"
                                    >
                                        <img v-if="cuti.pegawai?.foto_profil" :src="`/storage/${cuti.pegawai.foto_profil}`" class="w-full h-full object-cover" />
                                        <span v-else>{{ cuti.pegawai?.nama?.charAt(0) || "?" }}</span>
                                    </div>
                                </template>
                                <div v-if="day.onLeave.length > 3" class="w-6 h-6 md:w-7 md:h-7 rounded-full border-2 border-white bg-slate-200 text-slate-600 flex items-center justify-center text-[9px] font-bold z-0 shadow-sm">
                                    +{{ day.onLeave.length - 3 }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </MainLayout>

    <!-- Modal Detail Cuti (Tetap Menggunakan Code Asli) -->
    <Teleport to="body">
        <div v-if="detailModal.show" class="fixed inset-0 z-[9999] flex items-center justify-center bg-slate-900/40 backdrop-blur-sm p-4">
            <div class="bg-white/90 backdrop-blur-xl rounded-[2rem] max-w-lg w-full shadow-2xl border border-white overflow-hidden transform animate-in zoom-in duration-200">
                <div class="p-6 border-b border-slate-100 bg-white/50 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="bg-blue-100 text-blue-600 p-2.5 rounded-xl"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path></svg></div>
                        <div><h3 class="text-lg font-extrabold text-slate-800">Detail Pengajuan Cuti</h3><p class="text-xs font-semibold text-slate-500 mt-0.5">Informasi lengkap status dan permohonan.</p></div>
                    </div>
                    <button @click="closeDetailModal" class="text-slate-400 hover:text-slate-600 p-2 rounded-xl hover:bg-slate-200/50 transition"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
                </div>
                <div class="p-6 space-y-5 max-h-[70vh] overflow-y-auto text-sm custom-scrollbar" v-if="detailModal.data">
                    <div class="grid grid-cols-2 gap-4 pb-4 border-b border-slate-100">
                        <div><span class="block text-[10px] text-slate-400 font-extrabold uppercase tracking-wider mb-1">Nama Pegawai</span><span class="font-bold text-slate-800">{{ detailModal.data.pegawai?.nama ?? "-" }}</span></div>
                        <div><span class="block text-[10px] text-slate-400 font-extrabold uppercase tracking-wider mb-1">Status Saat Ini</span><span class="inline-flex px-3 py-1 text-[11px] font-bold rounded-xl" :class="formatStatus(detailModal.data.status).class">{{ formatStatus(detailModal.data.status).text }}</span></div>
                    </div>
                    <div class="grid grid-cols-2 gap-4 pb-4 border-b border-slate-100">
                        <div><span class="block text-[10px] text-slate-400 font-extrabold uppercase tracking-wider mb-1">Tanggal Mulai</span><span class="font-semibold text-slate-700">{{ formatDate(detailModal.data.tanggal_mulai) }}</span></div>
                        <div><span class="block text-[10px] text-slate-400 font-extrabold uppercase tracking-wider mb-1">Tanggal Selesai</span><span class="font-semibold text-slate-700">{{ formatDate(detailModal.data.tanggal_selesai) }}</span></div>
                    </div>
                    <div class="pb-4 border-b border-slate-100"><span class="block text-[10px] text-slate-400 font-extrabold uppercase tracking-wider mb-1">Durasi</span><span class="font-bold text-slate-700">{{ detailModal.data.jumlah_hari }} Hari Kalender</span></div>
                    <div class="pb-4 border-b border-slate-100"><span class="block text-[10px] text-slate-400 font-extrabold uppercase tracking-wider mb-1">Keterangan / Alasan</span><p class="text-slate-600 mt-1 bg-slate-50/50 p-4 rounded-2xl border border-slate-100 whitespace-pre-line font-medium">{{ detailModal.data.keterangan || "-" }}</p></div>
                    <div v-if="detailModal.data.catatan"><span class="block text-[10px] text-slate-400 font-extrabold uppercase tracking-wider mb-1">Catatan / Alasan Penolakan</span><p class="text-red-600 mt-1 bg-red-50/50 p-4 rounded-2xl border border-red-100 whitespace-pre-line text-xs font-bold">{{ detailModal.data.catatan }}</p></div>
                </div>
                <div class="p-5 bg-slate-50/50 border-t border-slate-100 flex justify-end">
                    <button type="button" @click="closeDetailModal" class="px-6 py-2.5 bg-slate-800 hover:bg-slate-900 text-white rounded-xl text-sm font-bold transition shadow-md">Tutup</button>
                </div>
            </div>
        </div>
    </Teleport>
    
    <!-- 2. POP-UP MODAL DAFTAR ANGGOTA TIM -->
    <Teleport to="body">
        <div v-if="showTeamModal" class="fixed inset-0 z-[9999] flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4" @click.self="showTeamModal = false">
            <div class="bg-white/90 backdrop-blur-xl rounded-[2rem] max-w-md w-full shadow-2xl border border-white overflow-hidden transform animate-in zoom-in duration-200 flex flex-col max-h-[90vh]">
                <div class="p-5 border-b border-slate-100 flex items-start gap-4 shrink-0 bg-slate-50/50">
                    <div class="bg-blue-100 text-blue-600 p-2.5 rounded-xl"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg></div>
                    <div class="flex-1"><h3 class="text-lg font-bold text-slate-800 leading-tight">Daftar Anggota Tim</h3><p class="text-xs font-semibold text-slate-500 mt-1">Total terdaftar: {{ anggotaTim.length }} Orang</p></div>
                    <button type="button" @click="showTeamModal = false" class="text-slate-400 hover:text-slate-600 hover:bg-slate-100 p-1.5 rounded-full transition cursor-pointer"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
                </div>
                <div class="p-5 overflow-y-auto space-y-3 flex-1 custom-scrollbar">
                    <div v-for="anggota in anggotaTim" :key="anggota.id" class="flex items-center gap-4 p-3 border border-slate-100 rounded-xl bg-white hover:border-blue-100 hover:shadow-sm transition-all">
                        <div class="w-10 h-10 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center font-bold text-sm shrink-0 overflow-hidden border border-blue-200">
                            <img v-if="anggota.foto_profil" :src="`/storage/${anggota.foto_profil}`" class="w-full h-full object-cover" />
                            <span v-else>{{ anggota.nama?.charAt(0) || "?" }}</span>
                        </div>
                        <div class="flex-1 min-w-0"><p class="text-sm font-bold text-slate-800 truncate">{{ anggota.nama }}</p><p class="text-xs text-slate-500 mt-0.5 truncate">NIP: {{ anggota.nip || "-" }}</p></div>
                        <span class="shrink-0 px-3 py-1 bg-green-50 text-green-600 border border-green-200 rounded-full text-[10px] font-bold">Aktif</span>
                    </div>
                    <div v-if="anggotaTim.length === 0" class="text-center py-8"><p class="text-sm text-slate-500 font-medium">Belum ada data anggota tim.</p></div>
                </div>
                <div class="p-4 border-t border-slate-100 bg-slate-50 flex justify-between items-center shrink-0">
                    <Link :href="route('karyawan.kalender')" class="text-xs font-bold text-blue-600 hover:text-blue-700 transition flex items-center gap-1">Buka Kalender Tim Lengkap <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"></path></svg></Link>
                    <button type="button" @click="showTeamModal = false" class="px-5 py-2 bg-slate-800 hover:bg-slate-900 text-white rounded-xl text-sm font-bold transition cursor-pointer shadow-sm">Tutup</button>
                </div>
            </div>
        </div>
    </Teleport>

    <!-- 3. POP-UP MODAL CUTI DISETUJUI BULAN INI -->
    <Teleport to="body">
        <div v-if="approvedModal.show" class="fixed inset-0 z-[9999] flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4">
            <div class="bg-white/90 backdrop-blur-xl rounded-[2rem] max-w-2xl w-full shadow-2xl border border-slate-100 overflow-hidden transform animate-in zoom-in duration-200 flex flex-col max-h-[90vh]">
                <div class="p-5 border-b border-slate-100 bg-green-50/50 flex items-center justify-between shrink-0">
                    <div class="flex items-center gap-3">
                        <div class="bg-green-100 text-green-600 p-2.5 rounded-xl"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg></div>
                        <div><h3 class="text-lg font-extrabold text-slate-800">Daftar Cuti Disetujui (Bulan Ini)</h3><p class="text-xs text-slate-500 mt-0.5 font-semibold">Total pengajuan disetujui: {{ cutiDisetujuiBulanIniList.length }} Pengajuan</p></div>
                    </div>
                    <button @click="approvedModal.show = false" class="text-slate-400 hover:text-slate-600 p-1.5 rounded-lg hover:bg-slate-200/50 transition"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
                </div>
                <div class="p-6 overflow-y-auto space-y-3 flex-1 custom-scrollbar">
                    <template v-if="cutiDisetujuiBulanIniList.length > 0">
                        <div v-for="item in cutiDisetujuiBulanIniList" :key="item.id" class="p-4 bg-white border border-slate-100 rounded-xl flex items-center justify-between gap-4 hover:shadow-sm transition-all">
                            <div>
                                <h4 class="text-sm font-bold text-slate-800">{{ item.pegawai?.nama || "Pegawai" }}</h4>
                                <p class="text-xs text-slate-500 mt-1"><span class="font-bold text-slate-700">{{ formatDate(item.tanggal_mulai) }}</span> s.d <span class="font-bold text-slate-700">{{ formatDate(item.tanggal_selesai) }}</span> ({{ item.jumlah_hari }} Hari)</p>
                                <p class="text-[10px] text-slate-400 mt-1 italic font-medium">Keterangan: {{ item.keterangan || "-" }}</p>
                            </div>
                            <span class="px-3 py-1 bg-green-50 text-green-600 border border-green-200 rounded-full text-[10px] font-bold shrink-0">Disetujui</span>
                        </div>
                    </template>
                    <div v-else class="text-center py-8 text-slate-400 text-xs font-semibold">Tidak ada catatan cuti yang disetujui pada bulan ini.</div>
                </div>
                <div class="p-5 bg-slate-50/50 border-t border-slate-100 flex justify-end shrink-0">
                    <button @click="approvedModal.show = false" class="px-6 py-2.5 bg-slate-800 hover:bg-slate-900 text-white rounded-xl text-sm font-bold transition shadow-md">Tutup</button>
                </div>
            </div>
        </div>
    </Teleport>

    <!-- 4. POP-UP MODAL PENANGGUHAN -->
    <Teleport to="body">
        <div v-if="suspendData.show" class="fixed inset-0 z-[9999] flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4">
            <div class="bg-white/90 backdrop-blur-xl rounded-[2rem] max-w-md w-full shadow-2xl border border-white overflow-hidden transform animate-in zoom-in duration-200">
                <div class="p-5 border-b border-slate-100 bg-orange-50/50 flex items-center gap-3">
                    <div class="bg-orange-100 text-orange-600 p-2.5 rounded-xl"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg></div>
                    <div><h3 class="text-lg font-extrabold text-slate-800">Tangguhkan Cuti</h3><p class="text-xs text-slate-500 mt-0.5 font-semibold">Membatalkan paksa cuti yang telah disetujui.</p></div>
                </div>
                <div class="p-6">
                    <label class="block text-xs font-bold text-slate-700 mb-2 uppercase tracking-wide">Alasan Penangguhan <span class="text-red-500">*</span></label>
                    <textarea v-model="suspendData.alasan" rows="4" class="w-full text-sm font-medium border-slate-200 rounded-xl focus:ring-orange-500 focus:border-orange-500" placeholder="Contoh: Rapat mendadak dengan Kementan pada hari H."></textarea>
                </div>
                <div class="p-5 bg-slate-50/50 border-t border-slate-100 flex justify-end gap-3">
                    <button type="button" @click.prevent="closeSuspendModal" class="px-5 py-2.5 bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 rounded-xl text-sm font-bold transition cursor-pointer">Batal</button>
                    <button type="button" @click.prevent="submitSuspend" class="px-5 py-2.5 bg-orange-600 hover:bg-orange-700 text-white rounded-xl text-sm font-bold transition cursor-pointer shadow-md">Proses Penangguhan</button>
                </div>
            </div>
        </div>
    </Teleport>

    <!-- 5. POP-UP MODAL TIM SEDANG CUTI PADA TANGGAL TERTENTU -->
    <Teleport to="body">
        <div v-if="showOnLeaveModal" class="fixed inset-0 z-[9999] flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4" @click.self="showOnLeaveModal = false">
            <div class="bg-white/90 backdrop-blur-xl rounded-[2rem] max-w-md w-full shadow-2xl border border-white overflow-hidden transform animate-in zoom-in duration-200 flex flex-col max-h-[90vh]">
                <div class="p-5 border-b border-slate-100 flex items-start gap-4 shrink-0 bg-slate-50/50">
                    <div class="bg-red-100 text-red-600 p-2.5 rounded-xl"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 7a4 4 0 11-8 0 4 4 0 018 0zM9 14a6 6 0 00-6 6v1h12v-1a6 6 0 00-6-6zM21 12h-6"></path></svg></div>
                    <div class="flex-1"><h3 class="text-lg font-extrabold text-slate-800 leading-tight">Tim Sedang Cuti</h3><p class="text-xs font-semibold text-slate-500 mt-1">{{ formatDate(selectedDateForModal?.fullDate) }} &bull; {{ onLeaveTeamMembers.length }} Orang</p></div>
                    <button type="button" @click="showOnLeaveModal = false" class="text-slate-400 hover:text-slate-600 hover:bg-slate-100 p-1.5 rounded-full transition cursor-pointer"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
                </div>
                <div class="p-5 overflow-y-auto space-y-3 flex-1 custom-scrollbar">
                    <div v-for="cuti in onLeaveTeamMembers" :key="cuti.id" class="flex items-center gap-4 p-3 border border-slate-100 rounded-xl bg-white hover:border-red-100 hover:shadow-sm transition-all">
                        <div class="w-10 h-10 rounded-full bg-red-100 text-red-600 flex items-center justify-center font-bold text-sm shrink-0 overflow-hidden border border-red-200">
                            <img v-if="cuti.pegawai?.foto_profil" :src="`/storage/${cuti.pegawai.foto_profil}`" class="w-full h-full object-cover" />
                            <span v-else>{{ cuti.pegawai?.nama?.charAt(0) || "?" }}</span>
                        </div>
                        <div class="flex-1 min-w-0"><p class="text-sm font-bold text-slate-800 truncate">{{ cuti.pegawai?.nama || "Tanpa Nama" }}</p><p class="text-xs text-slate-500 mt-0.5 truncate">NIP: {{ cuti.pegawai?.nip || "-" }}</p></div>
                        <span class="shrink-0 px-3 py-1 bg-red-50 text-red-600 border border-red-200 rounded-full text-[10px] font-bold">Sedang Cuti</span>
                    </div>
                    <div v-if="onLeaveTeamMembers.length === 0" class="text-center py-8"><p class="text-sm text-slate-500 font-medium">Tidak ada anggota tim yang sedang cuti pada tanggal ini.</p></div>
                </div>
                <div class="p-5 bg-slate-50/50 border-t border-slate-100 flex justify-end shrink-0">
                    <button type="button" @click="showOnLeaveModal = false" class="px-6 py-2.5 bg-slate-800 hover:bg-slate-900 text-white rounded-xl text-sm font-bold transition cursor-pointer shadow-md">Tutup</button>
                </div>
            </div>
        </div>
    </Teleport>
</template>