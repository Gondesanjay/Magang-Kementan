<script setup>
import MainLayout from '@/Layouts/MainLayout.vue';
import { Head, useForm, router, Link } from '@inertiajs/vue3';

const props = defineProps({
    libur: Object // Diubah menjadi Object karena data dari paginate()
});

// Form Inertia untuk input data
const form = useForm({
    tanggal: '',
    keterangan: '',
    is_cuti_bersama: false, // Default: Libur Nasional (false)
});

const csvForm = useForm({
    file: null,
});

const submit = () => {
    form.post(route('admin.libur.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
};

const submitCsv = () => {
    if (!csvForm.file) {
        alert('Pilih file CSV terlebih dahulu.');
        return;
    }

    csvForm.post(route('admin.libur.import'), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            csvForm.reset();
            const input = document.getElementById('csv-file-input');
            if (input) input.value = '';
        },
    });
};

const hapusLibur = (id, keterangan) => {
    if (confirm(`Hapus tanggal merah: ${keterangan}?`)) {
        router.delete(route('admin.libur.destroy', id), {
            preserveScroll: true
        });
    }
};

const formatDate = (dateString) => {
    if (!dateString) return '-';
    const date = new Date(dateString);
    return new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'long', year: 'numeric' }).format(date);
};
</script>

<template>
    <Head title="Kelola Hari Libur" />

    <MainLayout>
        <div class="max-w-7xl mx-auto space-y-6 pb-12">
            
            <div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
                <div>
                    <h1 class="text-2xl md:text-3xl font-bold text-slate-800 tracking-tight">Kelola Hari Libur</h1>
                    <p class="text-slate-500 mt-1 text-sm">Atur tanggal merah dan cuti bersama instansi secara manual.</p>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- FORM INPUT -->
                <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200 self-start space-y-6">
                    <div>
                        <h3 class="text-base font-bold text-slate-800 mb-4 border-b border-slate-100 pb-3">Tambah Hari Libur</h3>
                        
                        <form @submit.prevent="submit" class="space-y-4">
                            <div>
                                <label class="block text-xs font-semibold text-slate-700 mb-1.5">Tanggal Libur <span class="text-red-500">*</span></label>
                                <input v-model="form.tanggal" type="date" required class="w-full text-sm border-slate-200 rounded-xl focus:ring-green-500 focus:border-green-500 bg-slate-50">
                                <span v-if="form.errors.tanggal" class="text-xs text-red-500 mt-1">{{ form.errors.tanggal }}</span>
                            </div>

                            <div>
                                <label class="block text-xs font-semibold text-slate-700 mb-1.5">Nama Peringatan / Keterangan <span class="text-red-500">*</span></label>
                                <input v-model="form.keterangan" type="text" placeholder="Contoh: Hari Kemerdekaan RI" required class="w-full text-sm border-slate-200 rounded-xl focus:ring-green-500 focus:border-green-500 bg-slate-50">
                            </div>

                            <div>
                                <label class="block text-xs font-semibold text-slate-700 mb-1.5">Jenis Libur <span class="text-red-500">*</span></label>
                                <select v-model="form.is_cuti_bersama" class="w-full text-sm border-slate-200 rounded-xl focus:ring-green-500 focus:border-green-500 bg-slate-50">
                                    <option :value="false">Libur Nasional (Tanggal Merah)</option>
                                    <option :value="true">Cuti Bersama</option>
                                </select>
                            </div>

                            <button type="submit" :disabled="form.processing" class="w-full px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-xl text-sm font-semibold transition disabled:opacity-50 mt-2">
                                Simpan Tanggal
                            </button>
                        </form>
                    </div>

                    <div class="border-t border-slate-100 pt-5">
                        <h3 class="text-base font-bold text-slate-800 mb-4">Impor Hari Libur dari CSV</h3>
                        <form @submit.prevent="submitCsv" class="space-y-4">
                            <div>
                                <label class="block text-xs font-semibold text-slate-700 mb-1.5">File CSV <span class="text-red-500">*</span></label>
                                <input
                                    id="csv-file-input"
                                    type="file"
                                    accept=".csv,text/csv"
                                    @change="csvForm.file = $event.target.files[0]"
                                    class="w-full text-sm text-slate-600 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:bg-slate-100 file:text-slate-700 file:font-semibold hover:file:bg-slate-200"
                                >
                                <p class="text-[11px] text-slate-500 mt-2">
                                    Format kolom: tanggal, keterangan, is_cuti_bersama
                                </p>
                            </div>

                            <button type="submit" :disabled="csvForm.processing" class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-sm font-semibold transition disabled:opacity-50">
                                Impor CSV
                            </button>
                        </form>
                    </div>
                </div>

                <!-- TABEL DAFTAR LIBUR -->
                <div class="md:col-span-2 bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50">
                        <h3 class="text-base font-bold text-slate-800">Daftar Hari Libur Terdaftar</h3>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-100">
                            <thead class="bg-slate-50/50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wider">Tanggal</th>
                                    <th class="px-6 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wider">Keterangan</th>
                                    <th class="px-6 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wider">Jenis</th>
                                    <th class="px-6 py-3 text-center text-[11px] font-bold text-slate-400 uppercase tracking-wider">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-slate-50">
                                <!-- Menggunakan libur.data karena format dari pagination -->
                                <tr v-for="item in libur.data" :key="item.id" class="hover:bg-slate-50/70 transition-colors">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-700 font-semibold">
                                        {{ formatDate(item.tanggal) }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-600">
                                        {{ item.keterangan }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span v-if="!item.is_cuti_bersama" class="px-2.5 py-1 inline-flex text-[10px] font-bold bg-red-50 text-red-600 border border-red-100 rounded-md">
                                            Libur Nasional
                                        </span>
                                        <span v-else class="px-2.5 py-1 inline-flex text-[10px] font-bold bg-blue-50 text-blue-600 border border-blue-100 rounded-md">
                                            Cuti Bersama
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center">
                                        <button @click="hapusLibur(item.id, item.keterangan)" class="text-red-500 hover:text-red-700 p-1.5 hover:bg-red-50 rounded-lg transition">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="!libur.data || libur.data.length === 0">
                                    <td colspan="4" class="px-6 py-8 text-center text-slate-500 text-sm">
                                        Belum ada hari libur yang didaftarkan.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- PAGINATION -->
                    <div v-if="libur.data && libur.data.length > 0" class="px-6 py-4 border-t border-slate-100 flex flex-col md:flex-row justify-between items-center gap-4 bg-slate-50/50">
                        <span class="text-sm text-slate-500">
                            Menampilkan {{ libur.from || 0 }} - {{ libur.to || 0 }} dari {{ libur.total }} data
                        </span>
                        
                        <div class="flex space-x-1">
                            <template v-for="(link, index) in libur.links" :key="index">
                                <div v-if="link.url === null" 
                                    class="px-3 py-1 text-sm text-slate-400 bg-slate-50 border border-slate-200 rounded cursor-not-allowed" 
                                    v-html="link.label">
                                </div>
                                <Link v-else 
                                    :href="link.url" 
                                    class="px-3 py-1 text-sm border border-slate-200 rounded hover:bg-green-50 transition-colors"
                                    :class="link.active ? 'bg-green-600 text-white border-green-600 hover:bg-green-700' : 'text-slate-600 bg-white'"
                                    v-html="link.label">
                                </Link>
                            </template>
                        </div>
                    </div>

                </div>
            </div>

        </div>
    </MainLayout>
</template>