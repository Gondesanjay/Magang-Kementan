<script setup>
import MainLayout from '@/Layouts/MainLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    cutiTim: Array,
    departemen: String,
    hariLiburs: Array // Menerima data dari Controller (Database Admin HR)
});

const currentDate = ref(new Date());

const namaBulan = [
    'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 
    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
];

const bulanAktif = computed(() => namaBulan[currentDate.value.getMonth()]);
const tahunAktif = computed(() => currentDate.value.getFullYear());

// DAFTAR HARI LIBUR DINAMIS DIAMBIL DARI DATABASE ADMIN HR
const holidayList = computed(() => {
    let holidays = {};
    if (props.hariLiburs && props.hariLiburs.length > 0) {
        props.hariLiburs.forEach(item => {
            holidays[item.tanggal] = item.keterangan;
        });
    }
    return holidays;
});

const prevMonth = () => {
    currentDate.value = new Date(currentDate.value.getFullYear(), currentDate.value.getMonth() - 1, 1);
};

const nextMonth = () => {
    currentDate.value = new Date(currentDate.value.getFullYear(), currentDate.value.getMonth() + 1, 1);
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
        const formattedMonth = String(month + 1).padStart(2, '0');
        const formattedDay = String(i).padStart(2, '0');
        const dateStr = `${year}-${formattedMonth}-${formattedDay}`;

        days.push({
            dateString: dateStr,
            dayNum: i,
            isCurrentMonth: true
        });
    }

    return days;
});

const getHolidayName = (dateStr) => {
    return holidayList.value[dateStr] || null;
};

const getCutiOnDate = (dateStr) => {
    if (!dateStr) return [];
    return props.cutiTim.filter(item => {
        return dateStr >= item.tanggal_mulai && dateStr <= item.tanggal_selesai;
    });
};

// MODAL DATA
const selectedDateData = ref(null);

const openModal = (day) => {
    if (!day.isCurrentMonth) return;
    
    const listCuti = getCutiOnDate(day.dateString);
    const holidayName = getHolidayName(day.dateString);
    
    if (listCuti.length > 0 || holidayName) {
        selectedDateData.value = {
            date: day.dateString,
            holiday: holidayName,
            list: listCuti
        };
    }
};

const closeModal = () => {
    selectedDateData.value = null;
};
</script>

<template>
    <Head title="Jadwal Cuti Tim" />

    <MainLayout>
        <div class="max-w-7xl mx-auto space-y-6 pb-12">
            
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div>
                    <h1 class="text-2xl md:text-3xl font-bold text-slate-800 tracking-tight">Jadwal Cuti Tim</h1>
                    <p class="text-slate-500 mt-1 text-sm">Departemen: <span class="font-semibold text-slate-700">{{ props.departemen || 'Biro Perencanaan' }}</span></p>
                </div>

                <div class="flex items-center gap-3 bg-white px-4 py-2 rounded-xl shadow-sm border border-slate-200">
                    <button type="button" @click.prevent="prevMonth" class="p-1.5 hover:bg-slate-100 rounded-lg text-slate-600 transition cursor-pointer">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                    </button>
                    <span class="font-bold text-slate-800 text-sm w-32 text-center">{{ bulanAktif }} {{ tahunAktif }}</span>
                    <button type="button" @click.prevent="nextMonth" class="p-1.5 hover:bg-slate-100 rounded-lg text-slate-600 transition cursor-pointer">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                    </button>
                </div>
            </div>

            <!-- GRID KALENDER -->
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
                <div class="grid grid-cols-7 bg-slate-50 border-b border-slate-200 text-center text-xs font-bold text-slate-600 py-3 uppercase tracking-wider">
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
                            'bg-slate-50/50 text-slate-300': !day.isCurrentMonth,
                            'cursor-pointer hover:bg-slate-50': day.isCurrentMonth && (getCutiOnDate(day.dateString).length > 0 || getHolidayName(day.dateString))
                        }"
                        @click="openModal(day)"
                    >
                        <div class="flex flex-col gap-1">
                            <div class="flex justify-between items-center" v-if="day.dayNum">
                                <span 
                                    class="text-xs font-semibold px-2 py-0.5 rounded-md"
                                    :class="{
                                        'text-slate-700': day.isCurrentMonth && !getHolidayName(day.dateString),
                                        'bg-red-500 text-white font-bold shadow-sm': getHolidayName(day.dateString)
                                    }"
                                >
                                    {{ day.dayNum }}
                                </span>
                                
                                <span v-if="day.isCurrentMonth && getCutiOnDate(day.dateString).length > 0" class="text-[10px] bg-green-100 text-green-700 font-bold px-1.5 py-0.5 rounded-full">
                                    {{ getCutiOnDate(day.dateString).length }} cuti
                                </span>
                            </div>

                            <div v-if="getHolidayName(day.dateString)" class="text-[10px] bg-red-50 text-red-600 border border-red-100 font-semibold px-1.5 py-0.5 rounded mt-1 truncate" :title="getHolidayName(day.dateString)">
                                🎉 {{ getHolidayName(day.dateString) }}
                            </div>
                        </div>

                        <div class="space-y-1 mt-1 overflow-y-auto max-h-[60px]" v-if="day.isCurrentMonth">
                            <template v-for="cuti in getCutiOnDate(day.dateString)" :key="cuti.id">
                                <div class="bg-green-50 border border-green-200 text-green-800 text-[11px] font-medium px-2 py-1 rounded-md truncate shadow-sm flex items-center gap-1">
                                    <span class="w-1.5 h-1.5 rounded-full bg-green-500 shrink-0"></span>
                                    <span class="truncate">{{ cuti.pegawai?.nama || 'Pegawai' }}</span>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </MainLayout>

    <!-- MODAL DETAIL TANGGAL MENGGUNAKAN TELEPORT -->
    <Teleport to="body">
        <div v-if="selectedDateData" class="fixed inset-0 z-[9999] flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4">
            <div class="bg-white w-full max-w-md rounded-2xl shadow-xl overflow-hidden animate-in zoom-in duration-200">
                
                <div class="bg-slate-900 text-white px-6 py-4 flex justify-between items-center">
                    <div>
                        <h3 class="font-bold text-base">Informasi Jadwal</h3>
                        <p class="text-xs text-slate-400 mt-0.5">Tanggal: {{ selectedDateData.date }}</p>
                    </div>
                    <button type="button" @click.prevent="closeModal" class="text-slate-400 hover:text-white bg-slate-800 p-1.5 rounded-full cursor-pointer transition">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </button>
                </div>

                <div class="p-6 space-y-4 max-h-[60vh] overflow-y-auto">
                    <!-- Alert Hari Libur -->
                    <div v-if="selectedDateData.holiday" class="p-4 rounded-xl bg-red-50 border border-red-200 text-red-700 flex items-start gap-3">
                        <svg class="w-5 h-5 text-red-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                        <div>
                            <p class="font-bold text-sm">Hari Libur Nasional</p>
                            <p class="text-xs mt-0.5">{{ selectedDateData.holiday }}</p>
                        </div>
                    </div>

                    <!-- List Pegawai Cuti -->
                    <div v-if="selectedDateData.list.length > 0">
                        <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-3">Pegawai yang Sedang Cuti:</p>
                        <div class="space-y-3">
                            <div v-for="item in selectedDateData.list" :key="item.id" class="p-4 rounded-xl bg-slate-50 border border-slate-200 space-y-2">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <span class="font-bold text-slate-800 text-sm block">{{ item.pegawai?.nama }}</span>
                                        <span class="text-[10px] font-semibold bg-green-100 text-green-700 px-2 py-0.5 rounded-full mt-1 inline-block">Disetujui</span>
                                    </div>
                                </div>
                                <div class="text-xs text-slate-600 space-y-1 bg-white p-2.5 rounded-lg border border-slate-100">
                                    <p><span class="font-medium text-slate-400 block mb-0.5">Durasi Cuti:</span> {{ item.tanggal_mulai }} s/d {{ item.tanggal_selesai }} ({{ item.jumlah_hari }} Hari)</p>
                                    <p><span class="font-medium text-slate-400 block mb-0.5">Keterangan:</span> {{ item.keterangan || '-' }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-slate-50 px-6 py-3 border-t border-slate-100 flex justify-end">
                    <button type="button" @click.prevent="closeModal" class="px-5 py-2 bg-slate-800 text-white rounded-xl text-sm font-semibold hover:bg-slate-900 transition cursor-pointer shadow-sm">
                        Tutup
                    </button>
                </div>

            </div>
        </div>
    </Teleport>
</template>