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

// ==========================================================
// PEMBATALAN CUTI — MASIH MENUNGGU APPROVAL (menunggu_l1/l2/l3/l4)
// Cukup konfirmasi biasa, tidak wajib alasan.
// ==========================================================
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

// ==========================================================
// PEMBATALAN MANDIRI — CUTI SUDAH "DISETUJUI"
// Wajib isi alasan pembatalan. Saldo cuti otomatis dikembalikan
// tanpa perlu approval ulang dari atasan.
// ==========================================================
const batalModal = ref({
    show: false,
    id: null,
    alasan: "",
});

const openBatalModal = (id) => {
    batalModal.value.id = id;
    batalModal.value.alasan = "";
    batalModal.value.show = true;
};

const closeBatalModal = () => {
    batalModal.value.show = false;
};

const submitBatal = () => {
    if (!batalModal.value.alasan.trim()) {
        alert("Alasan pembatalan wajib diisi!");
        return;
    }

    router.post(
        route("karyawan.cuti.batalkan-mandiri", batalModal.value.id),
        {
            alasan_pembatalan: batalModal.value.alasan,
        },
        {
            preserveScroll: true,
            onSuccess: () => closeBatalModal(),
        },
    );
};

const selectedDetail = ref(null);

const openDetailModal = (item) => {
    selectedDetail.value = item;
};

const closeDetailModal = () => {
    selectedDetail.value = null;
};

// PEMBARUAN: Nama Status Atasan & Hierarki L4
const formatStatus = (statusCode) => {
    switch (statusCode) {
        case "menunggu_l1":
            return { text: "Menunggu Ketua Tim Kerja", class: "bg-amber-100 text-amber-700 border border-amber-200" };
        case "menunggu_l2":
            return { text: "Menunggu Ketua Kelompok Substansi", class: "bg-amber-100 text-amber-700 border border-amber-200" };
        case "menunggu_l3":
            return { text: "Menunggu Kasubag TU", class: "bg-amber-100 text-amber-700 border border-amber-200" };
        case "menunggu_l4":
            return { text: "Menunggu Kepala Biro Perencanaan", class: "bg-amber-100 text-amber-700 border border-amber-200" };
        case "disetujui":
            return { text: "Disetujui", class: "bg-green-100 text-green-700 border border-green-200" };
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

// Helper: nama atasan yang memproses pengajuan, mengikuti detail Dashboard.
const getNamaAtasanPemroses = (item) => {
    if (!item) return "Bapak Atasan L1";
    return (
        item.atasan_l4?.nama ||
        item.atasanL4?.nama ||
        item.atasan_l3?.nama ||
        item.atasanL3?.nama ||
        item.atasan_l2?.nama ||
        item.atasanL2?.nama ||
        item.atasan_l1?.nama ||
        item.atasanL1?.nama ||
        {
            1: "Bapak Ketua Tim Kerja",
            2: "Bapak Ketua Kelompok Substansi",
            3: "Bapak Kasubag TU",
            4: "Bapak Kasubag TU",
            6: "Bapak Kepala Biro Perencanaan",
        }[item.level_saat_ini] || "Atasan"
    );
};

// Fungsi untuk membersihkan teks catatan atasan agar tampil clean:
// mengambil bagian setelah tanda "|", lalu membuang prefix format
// "[Label: ...]" beserta sisa tanda kurung siku di akhir.
const formatCatatanAtasan = (keterangan, status) => {
    if (!keterangan || !keterangan.includes("|")) {
        return {
            disetujui: "Disetujui dan diteruskan sesuai alur birokrasi.",
            ditolak: "Pengajuan ditolak oleh atasan.",
        }[status] || "Diproses tanpa catatan tambahan.";
    }

    return keterangan
        .split("|")[1]
        .replace(/\[.*?:\s*/g, "")
        .replace(/\]/g, "")
        .trim();
};

const getApprovalLogs = (item) => {
    const logs = item?.approval_logs || item?.approvalLogs || [];
    if (logs.length) return logs;

    const legacyLogs = [];
    if (item?.atasanL1?.nama || item?.atasan_l1?.nama) {
        legacyLogs.push({
            id: `legacy-l1-${item.id}`,
            level_approval: 1,
            approver: item.atasanL1 || item.atasan_l1,
            keputusan: 'setuju',
        });
    }
    if (item?.atasanL3?.nama || item?.atasan_l3?.nama) {
        legacyLogs.push({
            id: `legacy-l3-${item.id}`,
            level_approval: 3,
            approver: item.atasanL3 || item.atasan_l3,
            keputusan: 'setuju',
        });
    }
    if (item?.atasanL4?.nama || item?.atasan_l4?.nama) {
        legacyLogs.push({
            id: `legacy-l4-${item.id}`,
            level_approval: 4,
            approver: item.atasanL4 || item.atasan_l4,
            keputusan: 'setuju',
        });
    }
    return legacyLogs;
};

const getApprovalLevelLabel = (level) => ({
    1: "L1 - Ketua Tim Kerja",
    2: "L2 - Ketua Kelompok Substansi",
    3: "L3 - Kasubag TU",
    4: "L4 - Kepala Biro Perencanaan",
}[level] || `Level ${level}`);
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

                    <div class="w-full md:w-64">
                        <select
                            v-model="status"
                            class="w-full py-2 px-3 text-xs border border-slate-200 rounded-xl focus:ring-green-500 focus:border-green-500 bg-white text-slate-600"
                        >
                            <option value="">Semua Status</option>
                            <option value="menunggu_l1">
                                Menunggu Ketua Tim Kerja (L1)
                            </option>
                            <option value="menunggu_l2">
                                Menunggu Ketua Pokja (L2)
                            </option>
                            <option value="menunggu_l3">
                                Menunggu Kasubag TU (L3)
                            </option>
                            <option value="menunggu_l4">
                                Menunggu Kabiro (L4)
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
                                <!-- 1. TANGGAL CUTI (Gabungan Mulai & Selesai) -->
                                <th
                                    scope="col"
                                    class="px-6 py-4 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wider"
                                >
                                    Tanggal Cuti
                                </th>

                                <!-- 2. TAMBAHAN: JENIS CUTI -->
                                <th
                                    scope="col"
                                    class="px-6 py-4 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wider"
                                >
                                    Jenis Cuti
                                </th>

                                <!-- 3. DURASI (Pengganti Jumlah Hari) -->
                                <th
                                    scope="col"
                                    class="px-6 py-4 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wider"
                                >
                                    Durasi
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
                                <!-- JUDUL UTAMA AKSI MEMBAWAHI 3 KOLOM -->
                                <th
                                    scope="col"
                                    colspan="3"
                                    class="px-4 py-4 text-center text-[11px] font-bold text-slate-400 uppercase tracking-wider"
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
                                <!-- 1. TANGGAL CUTI (Gabungan) -->
                                <td
                                    class="px-6 py-4 whitespace-nowrap text-sm text-slate-600 font-medium"
                                >
                                    {{ formatDate(item.tanggal_mulai) }} -
                                    {{ formatDate(item.tanggal_selesai) }}
                                </td>

                                <!-- 2. TAMBAHAN: JENIS CUTI -->
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span
                                        class="px-2.5 py-1 bg-indigo-50 text-indigo-600 border border-indigo-100 rounded-lg text-xs font-bold"
                                    >
                                        {{ item.jenis_cuti ?? "Cuti Tahunan" }}
                                    </span>
                                </td>

                                <!-- 3. DURASI -->
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
                                <!-- ================= KOLOM 1: TOMBOL DETAIL (Selalu Aktif) ================= -->
                                <td
                                    class="px-1.5 py-4 whitespace-nowrap text-center"
                                >
                                    <button
                                        type="button"
                                        @click.prevent="openDetailModal(item)"
                                        class="p-2 bg-slate-50 hover:bg-slate-200 text-slate-600 rounded-xl transition shadow-sm border border-slate-200 cursor-pointer inline-flex items-center justify-center"
                                        title="Lihat Detail & Catatan"
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
                                                d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
                                            ></path>
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                stroke-width="2"
                                                d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"
                                            ></path>
                                        </svg>
                                    </button>
                                </td>

                                <!-- ================= KOLOM 2: TOMBOL CETAK PDF (Aktif jika disetujui, Redup jika tidak) ================= -->
                                <td
                                    class="px-1.5 py-4 whitespace-nowrap text-center"
                                >
                                    <a
                                        v-if="item.status === 'disetujui'"
                                        :href="
                                            route('karyawan.cuti.pdf', item.id)
                                        "
                                        target="_blank"
                                        class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-600 border border-indigo-100 rounded-xl text-xs font-semibold transition shadow-sm"
                                        title="Cetak PDF"
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
                                    <!-- Tombol Redup (Disabled State) -->
                                    <span
                                        v-else
                                        class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-slate-100 text-slate-400 border border-slate-200 rounded-xl text-xs font-semibold cursor-not-allowed select-none"
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
                                    </span>
                                </td>

                                <!-- ================= KOLOM 3: TOMBOL BATALKAN (Aktif jika menunggu/disetujui, Redup jika tidak) ================= -->
                                <td
                                    class="px-1.5 py-4 whitespace-nowrap text-center"
                                >
                                    <button
                                        v-if="
                                            [
                                                'menunggu_l1',
                                                'menunggu_l2',
                                                'menunggu_l3',
                                                'menunggu_l4',
                                                'disetujui',
                                            ].includes(item.status)
                                        "
                                        type="button"
                                        @click="openBatalModal(item.id)"
                                        class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-red-50 hover:bg-red-100 text-red-600 border border-red-200 rounded-xl text-xs font-semibold transition shadow-sm cursor-pointer"
                                        title="Batalkan Pengajuan"
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
                                    <!-- Tombol Redup (Disabled State) -->
                                    <span
                                        v-else
                                        class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-slate-100 text-slate-400 border border-slate-200 rounded-xl text-xs font-semibold cursor-not-allowed select-none"
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
                                    </span>
                                </td>
                            </tr>
                            <tr v-if="riwayat.data.length === 0">
                                <td
                                    colspan="8"
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

    <!-- MODAL DETAIL (versi gabungan: badge status di header, grid Jenis Cuti,
         serta Catatan/Respon Atasan lengkap dengan nama pemroses & teks bersih) -->
    <Teleport to="body">
        <div
            v-if="selectedDetail"
            class="fixed inset-0 z-[9999] flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4"
            @click.self="closeDetailModal"
        >
            <div
                class="bg-white rounded-2xl max-w-lg w-full shadow-2xl border border-slate-100 overflow-hidden transform animate-in zoom-in duration-200"
            >
                <!-- HEADER & BADGE STATUS -->
                <div
                    class="p-5 border-b border-slate-100 flex justify-between items-start bg-slate-50/50"
                >
                    <div>
                        <h3 class="text-lg font-extrabold text-slate-800">
                            Detail Pengajuan Cuti
                        </h3>
                        <p class="text-xs font-medium text-slate-500 mt-1">
                            Informasi lengkap status dan permohonan.
                        </p>
                    </div>
                    <div class="flex items-center gap-3 shrink-0">
                        <!-- Badge Status -->
                        <div
                            class="px-3 py-1.5 rounded-lg text-[10px] font-bold uppercase tracking-wider whitespace-nowrap"
                            :class="{
                                'bg-amber-50 text-amber-600 border border-amber-200':
                                    selectedDetail.status?.includes('menunggu'),
                                'bg-emerald-50 text-emerald-600 border border-emerald-200':
                                    selectedDetail.status === 'disetujui',
                                'bg-red-50 text-red-600 border border-red-200':
                                    selectedDetail.status === 'ditolak',
                                'bg-slate-50 text-slate-600 border border-slate-200':
                                    selectedDetail.status?.includes(
                                        'dibatalkan',
                                    ),
                            }"
                        >
                            {{
                                selectedDetail.status?.replace(/_/g, " ") ??
                                "Status"
                            }}
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
                </div>

                <div class="p-6 space-y-5">
                    <!-- GRID 4 KOTAK: Tanggal Mulai, Tanggal Selesai, Jenis Cuti, Durasi -->
                    <div class="grid grid-cols-2 gap-4">
                        <div
                            class="p-4 bg-white border border-slate-100 rounded-2xl shadow-sm"
                        >
                            <p
                                class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1"
                            >
                                Tanggal Mulai
                            </p>
                            <p class="text-sm font-bold text-slate-700">
                                {{ formatDate(selectedDetail.tanggal_mulai) }}
                            </p>
                        </div>
                        <div
                            class="p-4 bg-white border border-slate-100 rounded-2xl shadow-sm"
                        >
                            <p
                                class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1"
                            >
                                Tanggal Selesai
                            </p>
                            <p class="text-sm font-bold text-slate-700">
                                {{ formatDate(selectedDetail.tanggal_selesai) }}
                            </p>
                        </div>
                        <div
                            class="p-4 bg-white border border-slate-100 rounded-2xl shadow-sm"
                        >
                            <p
                                class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1"
                            >
                                Jenis Cuti
                            </p>
                            <p class="text-sm font-bold text-indigo-600">
                                {{
                                    selectedDetail.jenis_cuti ?? "Cuti Tahunan"
                                }}
                            </p>
                        </div>
                        <div
                            class="p-4 bg-white border border-slate-100 rounded-2xl shadow-sm"
                        >
                            <p
                                class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1"
                            >
                                Durasi
                            </p>
                            <p class="text-sm font-bold text-slate-700">
                                {{ selectedDetail.jumlah_hari }} Hari
                            </p>
                        </div>
                    </div>

                    <!-- KETERANGAN / ALASAN PEGAWAI -->
                    <div>
                        <p
                            class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1.5 ml-1"
                        >
                            Keterangan / Alasan Cuti
                        </p>
                        <div
                            class="p-4 bg-slate-50 border border-slate-100 rounded-2xl text-sm text-slate-600 leading-relaxed whitespace-pre-wrap"
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

                    <!-- CATATAN / RESPON ATASAN -->
                    <div
                        v-if="
                            selectedDetail.status &&
                            (!selectedDetail.status.includes('menunggu') ||
                                getApprovalLogs(selectedDetail).length > 0)
                        "
                        class="p-4 rounded-2xl border"
                        :class="
                            selectedDetail.status === 'disetujui'
                                ? 'bg-emerald-50 border-emerald-100'
                                : 'bg-orange-50 border-orange-100'
                        "
                    >
                        <div class="flex items-center gap-2 mb-2">
                            <svg
                                class="w-4 h-4"
                                :class="
                                    selectedDetail.status === 'disetujui'
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
                                    selectedDetail.status === 'disetujui'
                                        ? 'text-emerald-700'
                                        : 'text-orange-600'
                                "
                            >
                                Catatan / Respon Atasan
                            </p>
                        </div>

                        <!-- Daftar seluruh atasan yang sudah memproses -->
                        <p v-if="!getApprovalLogs(selectedDetail).length" class="text-sm text-slate-700 mb-1">
                            Diproses oleh:
                            <span class="font-bold">{{
                                getNamaAtasanPemroses(selectedDetail)
                            }}</span>
                        </p>

                        <!-- Catatan Atasan, Sudah Dibersihkan dari Prefix "[Label: ...]" -->
                        <p v-if="!getApprovalLogs(selectedDetail).length"
                            class="text-sm font-medium italic"
                            :class="
                                selectedDetail.status === 'disetujui'
                                    ? 'text-emerald-600'
                                    : 'text-orange-600'
                            "
                        >
                            "{{
                                formatCatatanAtasan(
                                    selectedDetail.keterangan,
                                    selectedDetail.status,
                                )
                            }}"
                        </p>
                        <div v-else class="space-y-3">
                            <div v-for="log in getApprovalLogs(selectedDetail)" :key="log.id" class="rounded-xl border border-white/80 bg-white/70 p-3">
                                <p class="text-sm text-slate-700">
                                    {{ getApprovalLevelLabel(log.level_approval) }}:
                                    <span class="font-bold">{{ log.approver?.nama || "Atasan" }}</span>
                                </p>
                                <p class="mt-1 text-xs font-medium" :class="log.keputusan === 'setuju' ? 'text-emerald-600' : 'text-orange-600'">
                                    {{ log.catatan || (log.keputusan === 'setuju' ? 'Disetujui.' : 'Ditolak oleh atasan.') }}
                                </p>
                            </div>
                        </div>
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
                        Tutup
                    </button>
                </div>
            </div>
        </div>
    </Teleport>

    <!-- MODAL PEMBATALAN MANDIRI (khusus status "Disetujui") -->
    <Teleport to="body">
        <div
            v-if="batalModal.show"
            class="fixed inset-0 z-[9999] flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4"
        >
            <div
                class="bg-white rounded-[2rem] max-w-md w-full shadow-2xl overflow-hidden p-6"
            >
                <h3 class="text-lg font-extrabold text-slate-800 mb-1">
                    Batalkan Cuti
                </h3>
                <p class="text-xs text-slate-500 mb-4 font-medium">
                    Saldo cuti Anda akan otomatis dikembalikan. Tindakan ini
                    tidak dapat diubah.
                </p>

                <label
                    class="block text-xs font-bold text-slate-700 mb-2 uppercase tracking-wide"
                >
                    Alasan Pembatalan <span class="text-red-500">*</span>
                </label>
                <textarea
                    v-model="batalModal.alasan"
                    rows="3"
                    class="w-full text-sm font-medium border-slate-200 rounded-xl focus:ring-red-500 focus:border-red-500 mb-5 p-3 shadow-sm"
                    placeholder="Contoh: Agenda liburan keluarga batal karena urusan mendadak."
                ></textarea>

                <div class="flex justify-end gap-3">
                    <button
                        @click="closeBatalModal"
                        class="px-5 py-2.5 bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 rounded-xl text-sm font-bold transition shadow-sm"
                    >
                        Tutup
                    </button>
                    <button
                        @click="submitBatal"
                        class="px-5 py-2.5 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-bold transition shadow-md"
                    >
                        Proses Pembatalan
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>