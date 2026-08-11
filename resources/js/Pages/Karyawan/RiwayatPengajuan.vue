<script setup>
import MainLayout from "@/Layouts/MainLayout.vue";
import { Head, Link, router } from "@inertiajs/vue3";
import { ref, watch } from "vue";

const props = defineProps({
    riwayat: Object,
    filters: Object,
});

const search = ref(props.filters?.search || "");
const status = ref(props.filters?.status || "");

let timeout = null;
watch([search, status], ([newSearch, newStatus]) => {
    clearTimeout(timeout);
    timeout = setTimeout(() => {
        router.get(
            route("karyawan.riwayat"),
            { search: newSearch, status: newStatus },
            { preserveState: true, replace: true },
        );
    }, 300);
});

const cancelCuti = (id) => {
    if (confirm("Apakah Anda yakin ingin membatalkan pengajuan cuti ini?")) {
        router.post(
            route("karyawan.cuti.batal", id),
            {},
            {
                preserveScroll: true,
                preserveState: true,
            },
        );
    }
};

const selectedDetail = ref(null);

const openDetailModal = (item) => {
    selectedDetail.value = item;
};

const closeDetailModal = () => {
    selectedDetail.value = null;
};

// PEMBARUAN: Nama Status Atasan
const formatStatus = (statusCode) => {
    switch (statusCode) {
        case "menunggu_l1":
            return {
                text: "Menunggu Atasan (L1)",
                class: "bg-yellow-50 text-yellow-700 border border-yellow-200",
            };
        case "menunggu_l2":
            return {
                text: "Menunggu Kasubag TU (L2)",
                class: "bg-blue-50 text-blue-700 border border-blue-200",
            };
        case "menunggu_l3":
            return {
                text: "Menunggu Kepala Biro (L3)",
                class: "bg-purple-50 text-purple-700 border border-purple-200",
            };
        case "disetujui":
            return {
                text: "Disetujui",
                class: "bg-emerald-50 text-emerald-700 border border-emerald-100",
            };
        case "ditolak":
            return {
                text: "Ditolak",
                class: "bg-rose-50 text-rose-700 border border-rose-100",
            };
        case "dibatalkan_reguler":
            return {
                text: "Dibatalkan (Reguler)",
                class: "bg-slate-100 text-slate-600 border border-slate-200",
            };
        case "dibatalkan_ditangguhkan":
            return {
                text: "Dibatalkan (Ditangguhkan)",
                class: "bg-orange-50 text-orange-700 border border-orange-200",
            };
        default:
            return {
                text: statusCode,
                class: "bg-slate-100 text-slate-600 border border-slate-200",
            };
    }
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

// TAMBAHAN BARU: Fungsi untuk membersihkan teks catatan atasan agar tampil clean
const formatCatatanAtasan = (keterangan) => {
    if (!keterangan || !keterangan.includes("|")) return "-";

    // Ambil bagian setelah tanda "|"
    let catatan = keterangan.split("|")[1].trim();

    // Bersihkan prefix "[DITANGGUHKAN/DIBATALKAN ATASAN: " dan tanda "]" di akhir
    catatan = catatan
        .replace(/^\[DITANGGUHKAN\/DIBATALKAN ATASAN:\s*/i, "")
        .replace(/\]$/, "");

    return catatan;
};
</script>

<template>
    <Head title="Riwayat Pengajuan Cuti" />

    <MainLayout>
        <div class="max-w-7xl mx-auto space-y-6 pb-12">
            <div>
                <h1
                    class="text-2xl md:text-3xl font-bold text-slate-800 tracking-tight"
                >
                    Riwayat Pengajuan Cuti
                </h1>
                <p class="text-slate-500 mt-1 text-sm">
                    Daftar riwayat permohonan cuti yang pernah Anda ajukan.
                </p>
            </div>

            <div
                class="bg-white shadow-sm border border-slate-200 rounded-2xl overflow-hidden"
            >
                <div
                    class="p-5 border-b border-slate-100 flex flex-col md:flex-row justify-between items-center gap-4 bg-slate-50/50"
                >
                    <div class="w-full md:w-72 relative">
                        <span
                            class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-slate-400"
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
                                    d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
                                ></path>
                            </svg>
                        </span>
                        <input
                            v-model="search"
                            type="text"
                            placeholder="Cari berdasarkan keterangan..."
                            class="w-full pl-9 pr-4 py-2 text-xs border border-slate-200 rounded-xl focus:ring-green-500 focus:border-green-500 bg-white"
                        />
                    </div>

                    <div class="w-full md:w-56">
                        <select
                            v-model="status"
                            class="w-full py-2 px-3 text-xs border border-slate-200 rounded-xl focus:ring-green-500 focus:border-green-500 bg-white text-slate-600"
                        >
                            <option value="">Semua Status</option>
                            <option value="menunggu_l1">
                                Menunggu Atasan (L1)
                            </option>
                            <option value="menunggu_l2">
                                Menunggu Kasubag TU (L2)
                            </option>
                            <option value="menunggu_l3">
                                Menunggu Kepala Biro (L3)
                            </option>
                            <option value="disetujui">Disetujui</option>
                            <option value="ditolak">Ditolak</option>
                            <option value="dibatalkan_reguler">
                                Dibatalkan (Reguler)
                            </option>
                            <option value="dibatalkan_ditangguhkan">
                                Dibatalkan (Ditangguhkan)
                            </option>
                        </select>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-100">
                        <thead class="bg-slate-50/50">
                            <tr>
                                <th
                                    scope="col"
                                    class="px-6 py-4 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wider"
                                >
                                    Tanggal Mulai
                                </th>
                                <th
                                    scope="col"
                                    class="px-6 py-4 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wider"
                                >
                                    Tanggal Selesai
                                </th>
                                <th
                                    scope="col"
                                    class="px-6 py-4 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wider"
                                >
                                    Jumlah Hari
                                </th>
                                <th
                                    scope="col"
                                    class="px-6 py-4 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wider"
                                >
                                    Keterangan
                                </th>
                                <th
                                    scope="col"
                                    class="px-6 py-4 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wider"
                                >
                                    Status
                                </th>
                                <th
                                    scope="col"
                                    class="px-6 py-4 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wider"
                                >
                                    Aksi
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-slate-50">
                            <tr
                                v-for="item in riwayat.data"
                                :key="item.id"
                                class="hover:bg-slate-50/70 transition-colors"
                            >
                                <td
                                    class="px-6 py-4 whitespace-nowrap text-sm text-slate-600 font-medium"
                                >
                                    {{ formatDate(item.tanggal_mulai) }}
                                </td>
                                <td
                                    class="px-6 py-4 whitespace-nowrap text-sm text-slate-600 font-medium"
                                >
                                    {{ formatDate(item.tanggal_selesai) }}
                                </td>
                                <td
                                    class="px-6 py-4 whitespace-nowrap text-sm text-slate-600 font-semibold"
                                >
                                    {{ item.jumlah_hari }} Hari
                                </td>
                                <td
                                    class="px-6 py-4 text-sm text-slate-600 max-w-xs truncate"
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
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span
                                        class="px-3 py-1 inline-flex text-[11px] font-bold rounded-full"
                                        :class="formatStatus(item.status).class"
                                    >
                                        {{ formatStatus(item.status).text }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center gap-2">
                                        <button
                                            type="button"
                                            @click.prevent="
                                                openDetailModal(item)
                                            "
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-50 border border-slate-200 hover:bg-slate-100 text-slate-600 rounded-lg text-xs font-semibold transition shadow-sm cursor-pointer"
                                            title="Lihat Detail"
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
                                                    d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
                                                ></path>
                                                <path
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                    stroke-width="2"
                                                    d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"
                                                ></path>
                                            </svg>
                                            Detail
                                        </button>

                                        <a
                                            v-if="item.status === 'disetujui'"
                                            :href="
                                                route(
                                                    'karyawan.cuti.pdf',
                                                    item.id,
                                                )
                                            "
                                            target="_blank"
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-600 border border-indigo-100 rounded-lg text-xs font-semibold transition shadow-sm"
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
                                                    d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                                                ></path>
                                            </svg>
                                            Cetak PDF
                                        </a>

                                        <button
                                            v-if="
                                                [
                                                    'menunggu_l1',
                                                    'menunggu_l2',
                                                    'menunggu_l3',
                                                ].includes(item.status)
                                            "
                                            type="button"
                                            @click.prevent="cancelCuti(item.id)"
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white border border-red-200 hover:bg-red-50 hover:border-red-300 text-red-600 rounded-lg text-xs font-semibold transition shadow-sm cursor-pointer"
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
                                                    d="M6 18L18 6M6 6l12 12"
                                                ></path>
                                            </svg>
                                            Batalkan
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="riwayat.data.length === 0">
                                <td
                                    colspan="6"
                                    class="px-6 py-12 text-center text-slate-500 text-sm font-medium"
                                >
                                    Tidak ada riwayat cuti yang cocok dengan
                                    pencarian atau filter.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div
                    class="px-6 py-4 bg-slate-50/50 border-t border-slate-100 flex flex-col md:flex-row justify-between items-center gap-4"
                >
                    <p class="text-xs text-slate-500">
                        Menampilkan halaman {{ riwayat.current_page }} dari
                        total {{ riwayat.last_page }} halaman (maksimal 5 data
                        per halaman).
                    </p>
                    <div v-if="riwayat.links" class="flex items-center gap-1.5">
                        <template
                            v-for="(link, index) in riwayat.links"
                            :key="index"
                        >
                            <component
                                :is="link.url ? Link : 'span'"
                                :href="link.url ?? '#'"
                                v-html="link.label"
                                class="px-3 py-1.5 text-xs font-semibold rounded-lg border transition"
                                :class="{
                                    'bg-green-600 text-white border-green-600 shadow-sm':
                                        link.active,
                                    'bg-white text-slate-600 border-slate-200 hover:bg-slate-100':
                                        link.url && !link.active,
                                    'bg-slate-100 text-slate-400 border-slate-200 cursor-not-allowed':
                                        !link.url,
                                }"
                            />
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </MainLayout>

    <!-- MODAL DETAIL -->
    <Teleport to="body">
        <div
            v-if="selectedDetail"
            class="fixed inset-0 z-[9999] flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4"
            @click.self="closeDetailModal"
        >
            <div
                class="bg-white rounded-2xl max-w-lg w-full shadow-2xl border border-slate-100 overflow-hidden transform animate-in zoom-in duration-200"
            >
                <div
                    class="p-5 border-b border-slate-100 flex justify-between items-start bg-slate-50/50"
                >
                    <div>
                        <h3 class="text-lg font-bold text-slate-800">
                            Detail Pengajuan Cuti
                        </h3>
                        <p class="text-xs text-slate-500 mt-0.5">
                            Informasi lengkap terkait permohonan cuti Anda.
                        </p>
                    </div>
                    <button
                        type="button"
                        @click.prevent="closeDetailModal"
                        class="text-slate-400 hover:text-slate-600 bg-white border border-slate-200 p-1.5 rounded-full shadow-sm hover:bg-slate-50 transition cursor-pointer"
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
                <div class="p-6 space-y-5">
                    <div class="grid grid-cols-2 gap-4">
                        <div
                            class="bg-slate-50 p-3 rounded-xl border border-slate-100"
                        >
                            <p
                                class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1"
                            >
                                Tanggal Mulai
                            </p>
                            <p class="text-sm font-semibold text-slate-800">
                                {{ formatDate(selectedDetail.tanggal_mulai) }}
                            </p>
                        </div>
                        <div
                            class="bg-slate-50 p-3 rounded-xl border border-slate-100"
                        >
                            <p
                                class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1"
                            >
                                Tanggal Selesai
                            </p>
                            <p class="text-sm font-semibold text-slate-800">
                                {{ formatDate(selectedDetail.tanggal_selesai) }}
                            </p>
                        </div>
                        <div
                            class="bg-slate-50 p-3 rounded-xl border border-slate-100"
                        >
                            <p
                                class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1"
                            >
                                Durasi
                            </p>
                            <p class="text-sm font-semibold text-slate-800">
                                {{ selectedDetail.jumlah_hari }} Hari
                            </p>
                        </div>
                        <div
                            class="bg-slate-50 p-3 rounded-xl border border-slate-100"
                        >
                            <p
                                class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1"
                            >
                                Status
                            </p>
                            <span
                                class="px-2 py-0.5 inline-flex text-[11px] font-bold rounded-md mt-0.5"
                                :class="
                                    formatStatus(selectedDetail.status).class
                                "
                            >
                                {{ formatStatus(selectedDetail.status).text }}
                            </span>
                        </div>
                    </div>

                    <div>
                        <p
                            class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1.5"
                        >
                            Keterangan / Alasan Cuti
                        </p>
                        <div
                            class="p-3 bg-slate-50 border border-slate-100 rounded-xl text-sm text-slate-700 leading-relaxed whitespace-pre-wrap"
                        >
                            {{
                                selectedDetail.keterangan
                                    ? selectedDetail.keterangan
                                          .split("|")[0]
                                          .trim()
                                    : "-"
                            }}
                        </div>
                    </div>

                    <!-- KOTAK ALASAN PENOLAKAN DARI ATASAN -->
                    <div
                        v-if="
                            selectedDetail.keterangan &&
                            selectedDetail.keterangan.includes('|')
                        "
                        class="p-3.5 bg-orange-50 border border-orange-200 rounded-xl"
                    >
                        <div class="flex items-center gap-2 mb-1">
                            <svg
                                class="w-4 h-4 text-orange-600 shrink-0"
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
                                class="text-xs font-bold text-orange-800 uppercase tracking-wide"
                            >
                                Catatan / Respon Atasan
                            </p>
                        </div>

                        <!-- PEMBARUAN: Memanggil fungsi formatCatatanAtasan di sini -->
                        <p class="text-xs text-orange-700 leading-relaxed pl-6">
                            {{ formatCatatanAtasan(selectedDetail.keterangan) }}
                        </p>
                    </div>
                </div>
                <div
                    class="p-4 bg-slate-50 border-t border-slate-100 flex justify-end"
                >
                    <button
                        type="button"
                        @click.prevent="closeDetailModal"
                        class="px-6 py-2 bg-slate-800 hover:bg-slate-900 text-white rounded-xl text-sm font-semibold transition cursor-pointer shadow-sm"
                    >
                        tutup
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>