<script setup>
import MainLayout from "@/Layouts/MainLayout.vue";
import { Head, useForm } from "@inertiajs/vue3";
import { computed } from "vue";

const props = defineProps({
    sisa_cuti: Number,
});

// Setup Form Pengajuan Cuti
const form = useForm({
    jenis_cuti: "Cuti Tahunan",
    tanggal_mulai: "",
    tanggal_selesai: "",
    keterangan: "",
    alamat_cuti: "",
    no_telp: "",
});

// Kalkulasi estimasi hari secara real-time di sisi frontend
const estimasiHari = computed(() => {
    if (!form.tanggal_mulai || !form.tanggal_selesai) return 0;
    const start = new Date(form.tanggal_mulai);
    const end = new Date(form.tanggal_selesai);
    const diffTime = Math.abs(end - start);
    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;
    return diffDays > 0 ? diffDays : 0;
});

const sisaSetelahPengajuan = computed(() => {
    return props.sisa_cuti - estimasiHari.value;
});

const submit = () => {
    form.post(route("karyawan.ajukan.store"), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
};
</script>

<template>
    <Head title="Ajukan Cuti" />

    <MainLayout>
        <div class="max-w-6xl mx-auto space-y-6 pb-12">
            <!-- Header Halaman -->
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

            <!-- LAYOUT UTAMA: GRID 2 KOLOM -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-stretch">
                <!-- KOLOM KIRI: FORMULIR UTAMA -->
                <div
                    class="lg:col-span-2 bg-white p-6 md:p-8 rounded-2xl shadow-sm border border-slate-200 h-full flex flex-col justify-between"
                >
                    <div>
                        <!-- Pesan Error Saldo -->
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
                            <!-- Jenis Cuti -->
                            <div>
                                <label
                                    class="block text-sm font-semibold text-slate-700 mb-2"
                                    >Jenis Cuti <span class="text-red-500">*</span></label
                                >
                                <select
                                    v-model="form.jenis_cuti"
                                    class="w-full text-sm border-slate-200 rounded-xl focus:ring-green-500 focus:border-green-500 py-2.5 px-3 bg-white"
                                >
                                    <option value="Cuti Tahunan">
                                        Cuti Tahunan
                                    </option>
                                </select>
                            </div>

                            <!-- Tanggal Mulai & Selesai -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label
                                        class="block text-sm font-semibold text-slate-700 mb-2"
                                        >Tanggal Mulai <span class="text-red-500">*</span></label
                                    >
                                    <input
                                        v-model="form.tanggal_mulai"
                                        type="date"
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

                            <!-- Info Estimasi Hari -->
                            <div
                                class="flex items-center justify-between bg-slate-50 border border-slate-200 p-4 rounded-xl"
                            >
                                <div
                                    class="flex items-center gap-2 text-slate-600"
                                >
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
                                            d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"
                                        ></path>
                                    </svg>
                                    <span class="text-sm font-medium"
                                        >Estimasi Jumlah Hari Kerja</span
                                    >
                                </div>
                                <span class="text-base font-bold text-green-700"
                                    >{{ estimasiHari }} Hari</span
                                >
                            </div>

                            <!-- Alasan / Keterangan -->
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

                            <!-- Alamat & Telepon -->
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

                    <!-- Tombol Aksi -->
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
                            :disabled="form.processing || sisa_cuti <= 0"
                            class="px-6 py-2.5 bg-green-600 hover:bg-green-700 text-white rounded-xl text-sm font-semibold shadow-md shadow-green-600/20 transition disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            Ajukan Cuti
                        </button>
                    </div>
                </div>

                <!-- KOLOM KANAN: SIDEBAR WIDGETS -->
                <div class="lg:col-span-1 flex flex-col gap-6">
                    <!-- Widget 1: Ringkasan Saldo -->
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
                                <span class="font-bold text-red-500"
                                    >{{ estimasiHari }} Hari</span
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
                        </div>
                    </div>

                    <!-- Widget 2: Ketentuan Pengajuan -->
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

                    <!-- Widget 3: Alur Persetujuan -->
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
                                Alur Persetujuan
                            </h3>
                        </div>

                        <!-- Timeline Vertical -->
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
                                    Atasan Langsung (L1)
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
                                    Kasubag TU (L2)
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
                                    Kepala Biro Perencanaan (L3)
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </MainLayout>
</template>