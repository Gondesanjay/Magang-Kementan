<script setup>
import MainLayout from '@/Layouts/MainLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps({
    daftarCuti: Array
});

const formatDate = (dateString) => {
    if (!dateString) return '-';
    const date = new Date(dateString);
    return new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }).format(date);
};

// Fungsi status dengan format yang diminta
const formatStatus = (status) => {
    if (status === 'menunggu_l1' || status === 'menunggu_l2' || status === 'menunggu_l3') return 'Menunggu Atasan Langsung';
    if (status === 'disetujui') return 'Disetujui';
    if (status === 'ditolak') return 'Ditolak';
    return status.replace('_', ' ').toUpperCase();
};

// --- STATE UNTUK MODAL PENANGGUHAN CUTI ---
const suspendData = ref({
    show: false,
    id: null,
    namaPegawai: '',
    alasan: ''
});

const openSuspendModal = (id, nama) => {
    suspendData.value.id = id;
    suspendData.value.namaPegawai = nama;
    suspendData.value.alasan = '';
    suspendData.value.show = true;
};

const closeSuspendModal = () => {
    suspendData.value.show = false;
};

const submitSuspend = () => {
    if (!suspendData.value.alasan.trim()) {
        alert('Mohon isi alasan pembatalan/penangguhan terlebih dahulu.');
        return;
    }

    router.post(route('atasan.pembatalan.process', suspendData.value.id), {
        alasan: suspendData.value.alasan
    }, {
        preserveScroll: true,
        onSuccess: () => {
            closeSuspendModal();
        }
    });
};
</script>

<template>
    <Head title="Pembatalan Cuti" />

    <MainLayout>
        <div class="max-w-7xl mx-auto space-y-6 pb-12">
            
            <div class="bg-white overflow-hidden shadow-sm border border-slate-200 sm:rounded-2xl p-6 md:p-8">
                <div class="mb-6">
                    <h2 class="text-2xl font-bold text-slate-800 tracking-tight">Manajemen Penangguhan Cuti</h2>
                    <p class="text-sm text-slate-500 mt-1">
                        Membatalkan cuti yang sudah <strong class="text-green-600 font-semibold">Disetujui</strong> akan secara otomatis mengubah statusnya menjadi ditangguhkan dan mengembalikan jumlah saldo sisa cuti pegawai yang bersangkutan.
                    </p>
                </div>

                <div class="overflow-x-auto rounded-xl border border-slate-100">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50/50">
                            <tr>
                                <th class="px-6 py-4 text-left text-xs font-bold text-slate-400 uppercase tracking-wider">Nama Pegawai</th>
                                <th class="px-6 py-4 text-left text-xs font-bold text-slate-400 uppercase tracking-wider">Jadwal Cuti</th>
                                <th class="px-6 py-4 text-left text-xs font-bold text-slate-400 uppercase tracking-wider">Status Saat Ini</th>
                                <th class="px-6 py-4 text-center text-xs font-bold text-slate-400 uppercase tracking-wider">Aksi Eksekusi</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-slate-50">
                            <tr v-for="item in daftarCuti" :key="item.id" class="hover:bg-slate-50/70 transition-colors">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-slate-800">
                                    {{ item.pegawai?.nama }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-600">
                                    <span class="font-medium">{{ formatDate(item.tanggal_mulai) }} - {{ formatDate(item.tanggal_selesai) }}</span> <br>
                                    <span class="text-xs text-slate-400">({{ item.jumlah_hari }} Hari)</span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-3 py-1 inline-flex text-[11px] font-bold rounded-full" 
                                          :class="item.status === 'disetujui' ? 'bg-green-50 text-green-600 border border-green-200' : 'bg-amber-50 text-amber-600 border border-amber-200'">
                                        {{ formatStatus(item.status) }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <button 
                                        type="button" 
                                        @click.prevent="openSuspendModal(item.id, item.pegawai?.nama)" 
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-orange-50 border border-orange-200 text-orange-600 hover:bg-orange-100 font-bold rounded-lg text-xs transition cursor-pointer shadow-sm"
                                    >
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                                        Tangguhkan
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="daftarCuti.length === 0">
                                <td colspan="4" class="px-6 py-12 text-center text-slate-500">
                                    <div class="w-16 h-16 mx-auto bg-slate-50 rounded-full flex items-center justify-center mb-3">
                                        <svg class="w-8 h-8 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path></svg>
                                    </div>
                                    <p class="text-sm font-medium">Tidak ada pengajuan cuti aktif yang bisa ditangguhkan.</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </MainLayout>

    <!-- POP-UP MODAL PENANGGUHAN DENGAN TELEPORT -->
    <Teleport to="body">
        <div v-if="suspendData.show" class="fixed inset-0 z-[9999] flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4">
            <div class="bg-white rounded-2xl max-w-md w-full shadow-2xl border border-slate-100 overflow-hidden transform animate-in zoom-in duration-200">
                <div class="p-5 border-b border-slate-100 bg-orange-50 flex items-center gap-3">
                    <div class="bg-orange-100 text-orange-600 p-2 rounded-full">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-slate-800">Tangguhkan Cuti</h3>
                        <p class="text-xs text-slate-500 mt-0.5">Atas nama: <strong class="text-slate-700">{{ suspendData.namaPegawai }}</strong></p>
                    </div>
                </div>
                <div class="p-6">
                    <label class="block text-sm font-semibold text-slate-700 mb-2">Alasan Penangguhan / Pembatalan <span class="text-red-500">*</span></label>
                    <textarea 
                        v-model="suspendData.alasan" 
                        rows="3" 
                        class="w-full text-sm border-slate-200 rounded-xl focus:ring-orange-500 focus:border-orange-500"
                        placeholder="Contoh: Perintah tugas mendadak untuk persiapan dinas luar kota."
                    ></textarea>
                </div>
                <div class="p-4 bg-slate-50 border-t border-slate-100 flex justify-end gap-2">
                    <button type="button" @click.prevent="closeSuspendModal" class="px-4 py-2 bg-white border border-slate-300 text-slate-700 hover:bg-slate-50 rounded-xl text-sm font-semibold transition cursor-pointer">Batal</button>
                    <button type="button" @click.prevent="submitSuspend" class="px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white rounded-xl text-sm font-semibold transition cursor-pointer">Proses Penangguhan</button>
                </div>
            </div>
        </div>
    </Teleport>
</template>