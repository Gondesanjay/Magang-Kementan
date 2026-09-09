<script setup>
import MainLayout from "@/Layouts/MainLayout.vue";
import { Head, router } from "@inertiajs/vue3";
import { ref, watch } from "vue";


// Debounce lokal (tidak butuh lodash)
function debounce(fn, delay) {
    let timeoutId;
    return (...args) => {
        clearTimeout(timeoutId);
        timeoutId = setTimeout(() => fn(...args), delay);
    };
}


const props = defineProps({
    daftarCuti: {
        type: Array,
        default: () => [],
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
});


const formatDate = (dateString) => {
    if (!dateString) return "-";
    const date = new Date(dateString);
    return new Intl.DateTimeFormat("id-ID", {
        day: "2-digit",
        month: "short",
        year: "numeric",
    }).format(date);
};


// Fungsi status dengan format yang diminta
const formatStatus = (status) => {
    const labels = {
        menunggu_l1: "Menunggu Ketua Tim Kerja",
        menunggu_l2: "Menunggu Ketua Kelompok Substansi",
        menunggu_l3: "Menunggu Kasubag TU",
        menunggu_l4: "Menunggu Kepala Biro Perencanaan",
        disetujui: "Disetujui",
        ditolak: "Ditolak",
        dibatalkan_reguler: "Dibatalkan",
        dibatalkan_ditangguhkan: "Ditangguhkan",
    };
    if (labels[status]) return labels[status];
    return status.replace("_", " ").toUpperCase();
};


// ---> PERBAIKAN <---
// Aturan terbaru: L4 boleh menangguhkan cuti di SEMUA status proses
// approval (menunggu_l1/l2/l3/l4) maupun yang sudah disetujui penuh.
// Penangguhan HANYA tidak diperbolehkan untuk status berikut. Daftar ini
// harus konsisten dengan STATUS_TIDAK_BISA_DITANGGUHKAN di
// PembatalanController.php (backend tetap jadi sumber kebenaran utama;
// daftar ini di frontend murni untuk UX, bukan satu-satunya lapisan
// keamanan).
const STATUS_TIDAK_BISA_DITANGGUHKAN = [
    "ditolak",
    "dibatalkan_reguler",
    "dibatalkan_ditangguhkan",
];


const bisaDitangguhkan = (status) =>
    !STATUS_TIDAK_BISA_DITANGGUHKAN.includes(status);


// Kelas warna badge status, disesuaikan per kelompok status agar
// informatif (bukan cuma hijau/kuning generik).
const statusBadgeClass = (status) => {
    if (status === "disetujui") {
        return "bg-green-50 text-green-600 border border-green-200";
    }
    if (status === "dibatalkan_ditangguhkan") {
        return "bg-slate-100 text-slate-500 border border-slate-200";
    }
    if (status === "ditolak" || status === "dibatalkan_reguler") {
        return "bg-red-50 text-red-600 border border-red-200";
    }
    // menunggu_l1 / menunggu_l2 / menunggu_l3 / menunggu_l4
    return "bg-amber-50 text-amber-600 border border-amber-200";
};


// ===================== FITUR SEARCH =====================
const search = ref(props.filters?.search || "");


watch(
    search,
    debounce((value) => {
        router.get(
            route("atasan.penangguhan"), // sesuaikan nama route jika berbeda
            { search: value },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    }, 300),
);
// ===================== END FITUR SEARCH =====================


// --- STATE UNTUK MODAL PENANGGUHAN CUTI ---
const suspendData = ref({
    show: false,
    id: null,
    namaPegawai: "",
    alasan: "",
});


const openSuspendModal = (id, nama) => {
    suspendData.value.id = id;
    suspendData.value.namaPegawai = nama;
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
        route("atasan.penangguhan.process", suspendData.value.id),
        {
            alasan: suspendData.value.alasan,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                closeSuspendModal();
            },
        },
    );
};
</script>


<template>
    <Head title="Penangguhan Cuti" />


    <MainLayout>
        <div class="max-w-7xl mx-auto space-y-6 pb-12">
            <div
                class="bg-white overflow-hidden shadow-sm border border-slate-200 sm:rounded-2xl p-6 md:p-8"
            >
                <!-- Header + Search (sejajar rapi, judul & search bar align) -->
                <div
                    class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6"
                >
                    <div class="flex-1">
                        <h2
                            class="text-2xl font-bold text-slate-800 tracking-tight"
                        >
                            Manajemen Penangguhan Cuti
                        </h2>
                        <!-- Deskripsi dipersingkat agar tidak terlalu panjang barisnya -->
                        <p
                            class="text-xs md:text-sm text-slate-600 font-normal mt-1.5 leading-relaxed max-w-xl"
                        >
                            Cuti berstatus
                            <strong class="text-amber-600 font-semibold"
                                >Menunggu L1-L4</strong
                            >
                            atau
                            <strong class="text-green-600 font-semibold"
                                >Disetujui</strong
                            >
                            dapat ditangguhkan. Saldo cuti yang sebelumnya
                            disetujui akan dikembalikan secara otomatis.
                        </p>
                    </div>


                    <!-- Input Search -->
                    <div class="relative w-full sm:w-72 shrink-0">
                        <span
                            class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-slate-400"
                        >
                            <svg
                                class="w-4 h-4"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke-width="2"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"
                                />
                            </svg>
                        </span>
                        <input
                            type="text"
                            v-model="search"
                            placeholder="Cari nama / NIP..."
                            class="w-full pl-9 pr-4 py-2.5 text-sm bg-white border border-slate-400 text-slate-800 rounded-xl focus:ring-2 focus:ring-orange-500 focus:border-orange-500 placeholder-slate-500 shadow-sm transition-all"
                        />
                    </div>
                </div>


                <div class="overflow-x-auto rounded-xl border border-slate-100">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50/50">
                            <tr>
                                <th
                                    class="px-6 py-4 text-left text-xs font-bold text-slate-600 uppercase tracking-wider"
                                >
                                    Nama Pegawai
                                </th>
                                <th
                                    class="px-6 py-4 text-left text-xs font-bold text-slate-600 uppercase tracking-wider"
                                >
                                    Jadwal Cuti
                                </th>
                                <th
                                    class="px-6 py-4 text-left text-xs font-bold text-slate-600 uppercase tracking-wider"
                                >
                                    Status Saat Ini
                                </th>
                                <th
                                    class="px-6 py-4 text-center text-xs font-bold text-slate-600 uppercase tracking-wider"
                                >
                                    Aksi Eksekusi
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-slate-50">
                            <tr
                                v-for="item in daftarCuti"
                                :key="item.id"
                                class="hover:bg-slate-50/70 transition-colors"
                            >
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div
                                        class="flex items-center space-x-2 text-sm"
                                    >
                                        <!-- Nama Pegawai -->
                                        <span class="font-bold text-slate-800">
                                            {{ item.pegawai?.nama }}
                                        </span>


                                        <!-- Pemisah Titik, hanya muncul jika jabatan ada -->
                                        <span
                                            v-if="item.pegawai?.jabatan"
                                            class="text-slate-300"
                                            >•</span
                                        >


                                        <!-- Jabatan -->
                                        <span
                                            v-if="item.pegawai?.jabatan"
                                            class="text-slate-600 font-medium"
                                        >
                                            {{ item.pegawai?.jabatan }}
                                        </span>


                                        <!-- Pemisah Titik + Badge Biro/Unit (Jika ada) -->
                                        <template
                                            v-if="
                                                item.pegawai?.biro || item.unit
                                            "
                                        >
                                            <span class="text-slate-300"
                                                >•</span
                                            >
                                            <span
                                                class="text-xs text-slate-500 bg-slate-100 px-2 py-0.5 rounded-md"
                                            >
                                                {{
                                                    item.pegawai?.biro ||
                                                    item.unit
                                                }}
                                            </span>
                                        </template>
                                    </div>
                                </td>
                                <td
                                    class="px-6 py-4 whitespace-nowrap text-sm text-slate-600"
                                >
                                    <span class="font-medium"
                                        >{{ formatDate(item.tanggal_mulai) }} -
                                        {{
                                            formatDate(item.tanggal_selesai)
                                        }}</span
                                    >
                                    <br />
                                    <span class="text-xs text-slate-400"
                                        >({{ item.jumlah_hari }} Hari)</span
                                    >
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span
                                        class="px-3 py-1 inline-flex text-[11px] font-bold rounded-full"
                                        :class="statusBadgeClass(item.status)"
                                    >
                                        {{ formatStatus(item.status) }}
                                    </span>
                                </td>
                                <td
                                    class="px-6 py-4 whitespace-nowrap text-center"
                                >
                                    <button
                                        v-if="bisaDitangguhkan(item.status)"
                                        type="button"
                                        @click.prevent="
                                            openSuspendModal(
                                                item.id,
                                                item.pegawai?.nama,
                                            )
                                        "
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-orange-50 border border-orange-200 text-orange-600 hover:bg-orange-100 font-bold rounded-lg text-xs transition cursor-pointer shadow-sm"
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
                                                d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
                                            ></path>
                                        </svg>
                                        Tangguhkan
                                    </button>
                                    <span
                                        v-else
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-50 border border-slate-200 text-slate-400 font-bold rounded-lg text-xs cursor-not-allowed"
                                        title="Cuti ini sudah ditolak atau sudah dibatalkan/ditangguhkan sebelumnya, tidak dapat ditangguhkan"
                                    >
                                        Tidak Dapat Ditangguhkan
                                    </span>
                                </td>
                            </tr>
                            <tr v-if="daftarCuti.length === 0">
                                <td
                                    colspan="4"
                                    class="px-6 py-12 text-center text-slate-500"
                                >
                                    <div
                                        class="w-16 h-16 mx-auto bg-slate-50 rounded-full flex items-center justify-center mb-3"
                                    >
                                        <svg
                                            class="w-8 h-8 text-slate-300"
                                            fill="none"
                                            stroke="currentColor"
                                            viewBox="0 0 24 24"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                stroke-width="1.5"
                                                d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"
                                            ></path>
                                        </svg>
                                    </div>
                                    <p class="text-sm font-medium">
                                        Tidak ada pengajuan cuti yang bisa
                                        ditangguhkan saat ini.
                                    </p>
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
        <div
            v-if="suspendData.show"
            class="fixed inset-0 z-[9999] flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4"
        >
            <div
                class="bg-white rounded-2xl max-w-md w-full shadow-2xl border border-slate-100 overflow-hidden transform animate-in zoom-in duration-200"
            >
                <div
                    class="p-5 border-b border-slate-100 bg-orange-50 flex items-center gap-3"
                >
                    <div class="bg-orange-100 text-orange-600 p-2 rounded-full">
                        <svg
                            class="w-6 h-6"
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
                        <h3 class="text-base font-bold text-slate-800">
                            Tangguhkan Cuti
                        </h3>
                        <p class="text-xs text-slate-500 mt-0.5">
                            Atas nama:
                            <strong class="text-slate-700">{{
                                suspendData.namaPegawai
                            }}</strong>
                        </p>
                    </div>
                </div>
                <div class="p-6">
                    <label
                        class="block text-sm font-semibold text-slate-700 mb-2"
                        >Alasan Penangguhan
                        <span class="text-red-500">*</span></label
                    >
                    <textarea
                        v-model="suspendData.alasan"
                        rows="3"
                        class="w-full text-sm border-slate-200 rounded-xl focus:ring-orange-500 focus:border-orange-500"
                        placeholder="Contoh: Perintah tugas mendadak untuk persiapan dinas luar kota."
                    ></textarea>
                </div>
                <div
                    class="p-4 bg-slate-50 border-t border-slate-100 flex justify-end gap-2"
                >
                    <button
                        type="button"
                        @click.prevent="closeSuspendModal"
                        class="px-4 py-2 bg-white border border-slate-300 text-slate-700 hover:bg-slate-50 rounded-xl text-sm font-semibold transition cursor-pointer"
                    >
                        Batal
                    </button>
                    <button
                        type="button"
                        @click.prevent="submitSuspend"
                        class="px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white rounded-xl text-sm font-semibold transition cursor-pointer"
                    >
                        Proses Penangguhan
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
