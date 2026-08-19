<script setup>
import MainLayout from "@/Layouts/MainLayout.vue";
import { Head, useForm } from "@inertiajs/vue3";
import { computed } from "vue";
import Swal from "sweetalert2";

const props = defineProps({
    sisa_cuti: Number,
});

// Setup Form Pengajuan Cuti (Menambahkan field anak_ke)
const form = useForm({
    jenis_cuti: "Cuti Tahunan",
    anak_ke: "", 
    tanggal_mulai: "",
    tanggal_selesai: "",
    keterangan: "",
    alamat_cuti: "",
    no_telp: "",
});

// LOGIKA BARU: Mendapatkan tanggal hari ini (Format YYYY-MM-DD)
const todayStr = computed(() => {
    const today = new Date();
    const yyyy = today.getFullYear();
    const mm = String(today.getMonth() + 1).padStart(2, '0');
    const dd = String(today.getDate()).padStart(2, '0');
    return `${yyyy}-${mm}-${dd}`;
});

// LOGIKA BARU: Cek apakah tanggal mulai yang diketik manual ada di masa lalu
const isPastDate = computed(() => {
    if (!form.tanggal_mulai) return false;
    return form.tanggal_mulai < todayStr.value;
});

// Kalkulasi estimasi hari secara real-time TANPA hari Sabtu dan Minggu
const estimasiHari = computed(() => {
    if (!form.tanggal_mulai || !form.tanggal_selesai) return 0;
    
    let start = new Date(form.tanggal_mulai);
    let end = new Date(form.tanggal_selesai);
    
    // Pastikan start tidak lebih besar dari end
    if (start > end) return 0;

    let count = 0;
    let current = new Date(start);

    // Looping setiap hari dari start sampai end
    while (current <= end) {
        const dayOfWeek = current.getDay();
        // 0 = Minggu, 6 = Sabtu. Jika bukan 0 atau 6, tambah hitungan.
        if (dayOfWeek !== 0 && dayOfWeek !== 6) {
            count++;
        }
        current.setDate(current.getDate() + 1);
    }
    
    return count;
});

// Hanya Cuti Tahunan yang memotong Saldo Cuti
const sisaSetelahPengajuan = computed(() => {
    if (form.jenis_cuti === "Cuti Tahunan") {
        return props.sisa_cuti - estimasiHari.value;
    }
    return props.sisa_cuti; // Jenis cuti lain tidak memotong saldo
});

const submit = () => {
    if (!form.jenis_cuti) {
        Swal.fire({
            icon: 'error',
            title: 'Jenis cuti belum dipilih',
            text: 'Silakan pilih jenis cuti terlebih dahulu.',
            confirmButtonColor: '#ef4444',
        });
        return;
    }

    // Kosongkan form.anak_ke jika jenis cuti bukan Melahirkan
    if (form.jenis_cuti !== "Cuti Melahirkan") {
        form.anak_ke = "";
    }

    form.post(route("karyawan.ajukan.store"), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            form.jenis_cuti = "Cuti Tahunan";
            Swal.fire({
                icon: 'success',
                title: 'Pengajuan Berhasil!',
                text: 'Permohonan cuti Anda berhasil dikirim dan sedang menunggu persetujuan Atasan.',
                confirmButtonColor: '#10b981',
                confirmButtonText: 'OK',
            });
        },
    });
};
</script>

<template>
    <Head title="Ajukan Cuti" />

    <MainLayout>
        <div class="max-w-6xl mx-auto space-y-6 pb-12">
            <div>
                <h1
                    class="text-2xl md:text-3xl font-bold text-slate-800 tracking-tight"
                >
                    Formulir Pengajuan Cuti
                </h1>
                <p class="text-slate-500 mt-1 text-sm">
                    Silakan lengkapi data di bawah ini untuk mengajukan
                    permohonan cuti baru.
                </p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-stretch">
                <div
                    class="lg:col-span-2 bg-white p-6 md:p-8 rounded-2xl shadow-sm border border-slate-200 h-full flex flex-col justify-between"
                >
                    <div>
                        <div
                            v-if="
                                form.errors.tanggal_mulai &&
                                form.errors.tanggal_mulai.includes('Saldo')
                            "
                            class="mb-6 p-4 bg-red-50 text-red-700 rounded-xl border border-red-200 flex items-start gap-3"
                        >
                            <svg
                                class="w-5 h-5 text-red-500 mt-0.5"
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
                            <span class="text-sm font-semibold">{{
                                form.errors.tanggal_mulai
                            }}</span>
                        </div>

                        <form @submit.prevent="submit" class="space-y-6">
                            <div>
                                <label
                                    class="block text-sm font-semibold text-slate-700 mb-2"
                                    >Jenis Cuti <span class="text-red-500">*</span></label
                                >
                                <select
                                    v-model="form.jenis_cuti"
                                    class="w-full text-sm border-slate-200 rounded-xl focus:ring-green-500 focus:border-green-500 py-2.5 px-3 bg-white"
                                >
                                    <option value="Cuti Tahunan">Cuti Tahunan</option>
                                    <option value="Cuti Melahirkan">Cuti Melahirkan</option>
                                    <option value="Cuti Besar">Cuti Besar</option>
                                    <option value="Cuti Alasan Penting">Cuti Alasan Penting</option>
                                </select>
                                <span v-if="form.errors.jenis_cuti" class="text-xs text-red-500 mt-1 block">
                                    {{ form.errors.jenis_cuti }}
                                </span>
                            </div>

                            <div v-if="form.jenis_cuti === 'Cuti Melahirkan'" class="animate-in fade-in duration-300">
                                <label class="block text-sm font-semibold text-slate-700 mb-2">
                                    Anak ke-berapa <span class="text-red-500">*</span>
                                </label>
                                <input
                                    v-model="form.anak_ke"
                                    type="number"
                                    min="1"
                                    required
                                    placeholder="Contoh: 1, 2, atau 3"
                                    class="w-full md:w-1/2 text-sm border-slate-200 rounded-xl focus:ring-green-500 focus:border-green-500 py-2.5 px-3"
                                />
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label
                                        class="block text-sm font-semibold text-slate-700 mb-2"
                                        >Tanggal Mulai <span class="text-red-500">*</span></label
                                    >
                                    <input
                                        v-model="form.tanggal_mulai"
                                        type="date"
                                        :min="todayStr"
                                        required
                                        class="w-full text-sm border-slate-200 rounded-xl focus:ring-green-500 focus:border-green-500 py-2.5 px-3"
                                    />
                                    <span
                                        v-if="
                                            form.errors.tanggal_mulai &&
                                            !form.errors.tanggal_mulai.includes(
                                                'Saldo',
                                            )
                                        "
                                        class="text-xs text-red-500 mt-1 block"
                                    >
                                        {{ form.errors.tanggal_mulai }}
                                    </span>
                                </div>
                                <div>
                                    <label
                                        class="block text-sm font-semibold text-slate-700 mb-2"
                                        >Tanggal Selesai <span class="text-red-500">*</span></label
                                    >
                                    <input
                                        v-model="form.tanggal_selesai"
                                        type="date"
                                        :min="form.tanggal_mulai || todayStr"
                                        required
                                        class="w-full text-sm border-slate-200 rounded-xl focus:ring-green-500 focus:border-green-500 py-2.5 px-3"
                                    />
                                    <span
                                        v-if="form.errors.tanggal_selesai"
                                        class="text-xs text-red-500 mt-1 block"
                                    >
                                        {{ form.errors.tanggal_selesai }}
                                    </span>
                                </div>
                            </div>

                            <div class="space-y-2">
                                <div class="flex items-center justify-between bg-slate-50 border border-slate-200 p-4 rounded-xl">
                                    <div class="flex items-center gap-2 text-slate-600">
                                        <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                        <span class="text-sm font-medium">Estimasi Jumlah Hari Kerja</span>
                                    </div>
                                    <span class="text-base font-bold text-green-700">{{ estimasiHari }} Hari</span>
                                </div>
                                
                                <p v-if="estimasiHari === 0 && form.tanggal_mulai && form.tanggal_selesai && !isPastDate" class="text-xs font-bold text-red-500 animate-pulse">
                                    *Tanggal yang dipilih tidak valid karena hanya mencakup hari libur (Sabtu/Minggu).
                                </p>

                                <p v-if="isPastDate" class="text-xs font-bold text-red-500 animate-pulse">
                                    *Peringatan: Anda tidak dapat mengajukan cuti untuk tanggal yang sudah lewat.
                                </p>
                            </div>

                            <div>
                                <label
                                    class="block text-sm font-semibold text-slate-700 mb-2"
                                    >Keterangan / Alasan Cuti <span class="text-red-500">*</span></label
                                >
                                <textarea
                                    v-model="form.keterangan"
                                    rows="3"
                                    required
                                    placeholder="Jelaskan alasan atau keperluan cuti Anda secara detail..."
                                    class="w-full text-sm border-slate-200 rounded-xl focus:ring-green-500 focus:border-green-500 py-2.5 px-3 resize-none"
                                ></textarea>
                                <span
                                    v-if="form.errors.keterangan"
                                    class="text-xs text-red-500 mt-1 block"
                                    >{{ form.errors.keterangan }}</span
                                >
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label
                                        class="block text-sm font-semibold text-slate-700 mb-2"
                                        >Alamat Selama Cuti <span class="text-red-500">*</span></label
                                    >
                                    <textarea
                                        v-model="form.alamat_cuti"
                                        rows="2"
                                        required
                                        placeholder="Contoh: Jl. Merdeka No. 10..."
                                        class="w-full text-sm border-slate-200 rounded-xl focus:ring-green-500 focus:border-green-500 py-2.5 px-3 resize-none"
                                    ></textarea>
                                    <span
                                        v-if="form.errors.alamat_cuti"
                                        class="text-xs text-red-500 mt-1 block"
                                        >{{ form.errors.alamat_cuti }}</span
                                    >
                                </div>
                                <div>
                                    <label
                                        class="block text-sm font-semibold text-slate-700 mb-2"
                                        >No. Telepon / HP <span class="text-red-500">*</span></label
                                    >
                                    <input
                                        v-model="form.no_telp"
                                        type="tel"
                                        required
                                        placeholder="Contoh: 081234567890"
                                        class="w-full text-sm border-slate-200 rounded-xl focus:ring-green-500 focus:border-green-500 py-2.5 px-3"
                                    />
                                    <span
                                        v-if="form.errors.no_telp"
                                        class="text-xs text-red-500 mt-1 block"
                                        >{{ form.errors.no_telp }}</span
                                    >
                                </div>
                            </div>
                        </form>
                    </div>

                    <div
                        class="flex items-center justify-end gap-3 pt-6 mt-6 border-t border-slate-100"
                    >
                        <button
                            type="button"
                            @click="form.reset()"
                            class="px-5 py-2.5 border border-slate-300 text-slate-600 rounded-xl text-sm font-semibold hover:bg-slate-50 transition"
                        >
                            Batal
                        </button>
                        <button
                            @click="submit"
                            :disabled="form.processing || estimasiHari === 0 || isPastDate || (form.jenis_cuti === 'Cuti Tahunan' && sisa_cuti <= 0)"
                            class="px-6 py-2.5 bg-green-600 hover:bg-green-700 text-white rounded-xl text-sm font-semibold shadow-md shadow-green-600/20 transition disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            Ajukan Cuti
                        </button>
                    </div>
                </div>

                <div class="lg:col-span-1 flex flex-col gap-6">
                    <div
                        class="bg-white p-5 rounded-2xl shadow-sm border border-slate-200"
                    >
                        <div class="flex items-center gap-2 mb-4">
                            <svg
                                class="w-5 h-5 text-green-600"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"
                                ></path>
                            </svg>
                            <h3 class="text-base font-bold text-slate-800">
                                Ringkasan Saldo
                            </h3>
                        </div>

                        <div class="space-y-3">
                            <div
                                class="flex justify-between items-center text-sm"
                            >
                                <span class="text-slate-600"
                                    >Sisa Saldo Saat Ini</span
                                >
                                <span class="font-bold text-slate-800"
                                    >{{ props.sisa_cuti }} Hari</span
                                >
                            </div>
                            <div
                                class="flex justify-between items-center text-sm border-b border-slate-100 pb-3"
                            >
                                <span class="text-slate-600"
                                    >Estimasi Pengajuan</span
                                >
                                <span class="font-bold" :class="form.jenis_cuti === 'Cuti Tahunan' ? 'text-red-500' : 'text-slate-500'"
                                    >{{ form.jenis_cuti === 'Cuti Tahunan' ? estimasiHari : 0 }} Hari</span
                                >
                            </div>
                            <div
                                class="flex justify-between items-center text-sm bg-green-50 p-3 rounded-lg border border-green-100"
                            >
                                <span class="font-semibold text-green-800"
                                    >Sisa Setelah Pengajuan</span
                                >
                                <span
                                    class="font-bold text-green-700 text-base"
                                    :class="{
                                        'text-red-600':
                                            sisaSetelahPengajuan < 0,
                                    }"
                                >
                                    {{ sisaSetelahPengajuan }} Hari
                                </span>
                            </div>
                            <div v-if="form.jenis_cuti !== 'Cuti Tahunan'" class="text-[10px] text-center text-slate-400 font-semibold italic mt-2">
                                *Jenis cuti ini tidak memotong saldo cuti tahunan.
                            </div>
                        </div>
                    </div>

                    <div
                        class="bg-slate-900 p-5 rounded-2xl shadow-sm border border-slate-800"
                    >
                        <div class="flex items-center gap-2 mb-3">
                            <svg
                                class="w-5 h-5 text-blue-400"
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
                            <h3 class="text-sm font-bold text-white">
                                Ketentuan Pengajuan
                            </h3>
                        </div>
                        <ul
                            class="text-xs text-slate-300 space-y-2 list-disc pl-4 marker:text-slate-500"
                        >
                            <li>
                                Tidak ada batasan waktu minimum pengajuan,
                                pegawai dapat mengajukan cuti darurat kapan
                                saja.
                            </li>
                            <li>
                                Pengajuan cuti yang jatuh pada akhir pekan atau
                                hari libur nasional tidak akan dihitung sebagai
                                hari cuti.
                            </li>
                            <li>
                                Sisa cuti tahunan yang tidak terpakai dapat
                                diakumulasikan ke tahun berikutnya (maksimal 6
                                hari).
                            </li>
                        </ul>
                    </div>

                    <div
                        class="bg-blue-50 p-5 rounded-2xl shadow-sm border border-blue-100 flex-1 flex flex-col justify-center"
                    >
                        <div class="flex items-center gap-2 mb-6">
                            <svg
                                class="w-5 h-5 text-blue-600"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"
                                ></path>
                            </svg>
                            <h3 class="text-sm font-bold text-blue-900">
                                Alur Persetujuan Staf
                            </h3>
                        </div>

                        <div class="ml-2 border-l-2 border-blue-200 space-y-6">
                            <div class="relative pl-4">
                                <div
                                    class="absolute -left-[7px] top-1 w-3 h-3 bg-blue-500 rounded-full border-2 border-white shadow-sm ring-1 ring-blue-200"
                                ></div>
                                <p
                                    class="text-xs font-bold text-blue-900 leading-none"
                                >
                                    Tahap 1
                                </p>
                                <p
                                    class="text-[11px] font-medium text-blue-700 mt-1"
                                >
                                    Ketua Tim Kerja / Atasan Langsung (L1)
                                </p>
                            </div>
                            <div class="relative pl-4">
                                <div
                                    class="absolute -left-[7px] top-1 w-3 h-3 bg-blue-400 rounded-full border-2 border-white shadow-sm ring-1 ring-blue-200"
                                ></div>
                                <p
                                    class="text-xs font-bold text-blue-900 leading-none"
                                >
                                    Tahap 2
                                </p>
                                <p
                                    class="text-[11px] font-medium text-blue-700 mt-1"
                                >
                                    Kasubag TU (L3)
                                </p>
                            </div>
                            <div class="relative pl-4">
                                <div
                                    class="absolute -left-[7px] top-1 w-3 h-3 bg-blue-300 rounded-full border-2 border-white shadow-sm ring-1 ring-blue-200"
                                ></div>
                                <p
                                    class="text-xs font-bold text-blue-900 leading-none"
                                >
                                    Tahap Final
                                </p>
                                <p
                                    class="text-[11px] font-medium text-blue-700 mt-1"
                                >
                                    Kepala Biro Perencanaan (L4)
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </MainLayout>
</template>