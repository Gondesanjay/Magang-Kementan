<script setup>
import MainLayout from "@/Layouts/MainLayout.vue";
import { Head, router } from "@inertiajs/vue3";
import { ref, watch, computed } from "vue";
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
    // Daftar nama kelompok substansi (array string) dari controller,
    // sama seperti halaman Monitoring Cuti.
    daftarKelompokSubstansi: {
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
// Kamus label status lengkap, dipakai untuk teks deskriptif.
const statusLabels = {
    menunggu_l1: "Menunggu Ketua Tim Kerja",
    menunggu_l2: "Menunggu Ketua Kelompok Substansi",
    menunggu_l3: "Menunggu Kasubag TU",
    menunggu_l4: "Menunggu Kepala Biro Perencanaan",
    disetujui: "Disetujui",
    ditolak: "Ditolak",
    dibatalkan_reguler: "Dibatalkan",
    dibatalkan_ditangguhkan: "Ditangguhkan",
};
const formatStatus = (status) => {
    if (!status) return "-";
    if (statusLabels[status]) return statusLabels[status];
    return status.replace(/_/g, " ").toUpperCase();
};
// Label singkat untuk badge.
const STATUS_BADGE_SINGKAT = {
    menunggu_l1: "MENUNGGU L1",
    menunggu_l2: "MENUNGGU L2",
    menunggu_l3: "MENUNGGU L3",
    menunggu_l4: "MENUNGGU L4",
    disetujui: "DISETUJUI",
    ditolak: "DITOLAK",
    dibatalkan_reguler: "DIBATALKAN",
    dibatalkan_ditangguhkan: "DITANGGUHKAN",
};
const formatStatusBadge = (status) => {
    if (!status) return "-";
    return (
        STATUS_BADGE_SINGKAT[status] || status.replace(/_/g, " ").toUpperCase()
    );
};
// Penangguhan HANYA tidak diperbolehkan untuk status berikut. Harus
// konsisten dengan STATUS_TIDAK_BISA_DITANGGUHKAN di PembatalanController.php
// (backend tetap sumber kebenaran utama; ini murni untuk UX).
const STATUS_TIDAK_BISA_DITANGGUHKAN = [
    "ditolak",
    "dibatalkan_reguler",
    "dibatalkan_ditangguhkan",
];
const bisaDitangguhkan = (status) =>
    !STATUS_TIDAK_BISA_DITANGGUHKAN.includes(status);
// Kelas warna badge status (emerald/amber/rose/gray).
const statusBadgeClass = (status) => {
    if (status === "disetujui") {
        return "bg-emerald-100 text-emerald-800 border-emerald-300";
    }
    if (status === "dibatalkan_ditangguhkan") {
        return "bg-gray-200 text-gray-800 border-gray-300";
    }
    if (status === "ditolak" || status === "dibatalkan_reguler") {
        return "bg-rose-100 text-rose-800 border-rose-300";
    }
    // menunggu_l1 / menunggu_l2 / menunggu_l3 / menunggu_l4
    return "bg-amber-100 text-amber-800 border-amber-300";
};
const getDurasi = (item) => {
    if (item?.jumlah_hari && item.jumlah_hari > 0) return item.jumlah_hari;
    if (!item?.tanggal_mulai || !item?.tanggal_selesai) return 1;
    const mulai = new Date(item.tanggal_mulai);
    const selesai = new Date(item.tanggal_selesai);
    const selisih = Math.ceil((selesai - mulai) / (1000 * 60 * 60 * 24)) + 1;
    return selisih > 0 ? selisih : 1;
};
// ================= NOMOR TELEPON (PERBAIKAN) =================
const getNomorTelepon = (item) => {
    if (!item) return "-";
    return (
        item.no_telp ??
        item.no_telepon ??
        item.telepon ??
        item.phone ??
        item.pegawai?.no_telepon ??
        item.pegawai?.telepon ??
        item.pegawai?.no_hp ??
        item.pegawai?.phone ??
        "-"
    );
};
// ================= END NOMOR TELEPON =================


// ===================== FITUR PAGINATION =====================
const currentPage = ref(1);
const itemsPerPage = 10;
const totalData = computed(() => props.daftarCuti.length);
const totalPages = computed(
    () => Math.ceil(totalData.value / itemsPerPage) || 1,
);
const paginatedData = computed(() => {
    const start = (currentPage.value - 1) * itemsPerPage;
    const end = start + itemsPerPage;
    return props.daftarCuti.slice(start, end);
});
const displayedFrom = computed(() =>
    totalData.value === 0 ? 0 : (currentPage.value - 1) * itemsPerPage + 1,
);
const displayedTo = computed(() =>
    Math.min(currentPage.value * itemsPerPage, totalData.value),
);
const prevPage = () => {
    if (currentPage.value > 1) currentPage.value--;
};
const nextPage = () => {
    if (currentPage.value < totalPages.value) currentPage.value++;
};
const setPage = (page) => {
    currentPage.value = page;
};
const pageNumbers = computed(() => {
    const total = totalPages.value;
    const current = currentPage.value;
    const maxVisible = 7;
    const pages = [];
    if (total <= maxVisible) {
        for (let i = 1; i <= total; i++) pages.push(i);
    } else {
        if (current <= 4) {
            pages.push(1, 2, 3, 4, 5, "...", total);
        } else if (current >= total - 3) {
            pages.push(
                1,
                "...",
                total - 4,
                total - 3,
                total - 2,
                total - 1,
                total,
            );
        } else {
            pages.push(
                1,
                "...",
                current - 1,
                current,
                current + 1,
                "...",
                total,
            );
        }
    }
    return pages;
});
// ===================== END FITUR PAGINATION =====================
// ===================== FITUR SEARCH & FILTER =====================
const search = ref(props.filters?.search || "");
const kelompok = ref(props.filters?.kelompok_substansi || "");
const buildParams = () => {
    const params = { search: search.value };
    if (kelompok.value) params.kelompok_substansi = kelompok.value;
    return params;
};
watch(
    [search, kelompok],
    debounce(() => {
        currentPage.value = 1;
        router.get(route("atasan.penangguhan"), buildParams(), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, 300),
);
const adaFilterAktif = computed(() => !!search.value || !!kelompok.value);
const resetFilter = () => {
    search.value = "";
    kelompok.value = "";
};
// ===================== END FITUR SEARCH & FILTER =====================
// ===================== FITUR NOTIFIKASI TOAST =====================
const toast = ref({
    show: false,
    message: "",
});
const showToast = (message) => {
    toast.value.message = message;
    toast.value.show = true;
    setTimeout(() => {
        toast.value.show = false;
    }, 4000);
};
// ===================== END FITUR NOTIFIKASI TOAST =====================
const suspendData = ref({
    show: false,
    id: null,
    namaPegawai: "",
    alasan: "",
});
const suspendError = ref("");
const openSuspendModal = (id, nama) => {
    suspendData.value.id = id;
    suspendData.value.namaPegawai = nama;
    suspendData.value.alasan = "";
    suspendError.value = "";
    suspendData.value.show = true;
};
const closeSuspendModal = () => {
    suspendData.value.show = false;
    suspendError.value = "";
};
const submitSuspend = () => {
    if (!suspendData.value.alasan.trim()) {
        suspendError.value = "Mohon isi alasan penangguhan terlebih dahulu.";
        return;
    }
    suspendError.value = "";
    const namaPegawaiDisuspend = suspendData.value.namaPegawai;
    router.post(
        route("atasan.penangguhan.process", suspendData.value.id),
        {
            alasan: suspendData.value.alasan,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                closeSuspendModal();
                closeDetailModal();
                showToast(
                    `Berhasil: Cuti atas nama ${namaPegawaiDisuspend} telah ditangguhkan.`,
                );
            },
        },
    );
};
const detailModal = ref({ show: false, data: null });
const bukaDetailModal = (item) => {
    detailModal.value.data = item;
    detailModal.value.show = true;
};
const closeDetailModal = () => {
    detailModal.value.show = false;
    detailModal.value.data = null;
};
const detailModalTangguhkan = () => {
    if (!detailModal.value.data) return;
    const { id, pegawai } = detailModal.value.data;
    openSuspendModal(id, pegawai?.nama);
};
const bisaDitangguhkanDetail = computed(() =>
    bisaDitangguhkan(detailModal.value.data?.status),
);
</script>


<template>
    <Head title="Penangguhan Cuti" />
    <MainLayout>
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
            <div class="mb-6">
                <h2 class="text-2xl font-bold text-gray-800">
                    Manajemen Penangguhan Cuti
                </h2>
                <p class="text-sm text-gray-500 mt-1">
                    Cuti berstatus
                    <strong class="text-amber-600 font-semibold"
                        >Menunggu L1-L4</strong
                    >
                    atau
                    <strong class="text-emerald-600 font-semibold"
                        >Disetujui</strong
                    >
                    dapat ditangguhkan. Saldo cuti yang sebelumnya disetujui
                    akan dikembalikan secara otomatis.
                </p>
            </div>
            <div
                class="flex flex-col sm:flex-row sm:flex-wrap items-center gap-3 mb-6"
            >
                <div class="relative min-w-0 flex-1 w-full">
                    <div
                        class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"
                    >
                        <svg
                            class="h-4 w-4 text-gray-400"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
                            />
                        </svg>
                    </div>
                    <input
                        id="cari-pengajuan-pembatalan"
                        name="cari_pengajuan"
                        v-model="search"
                        type="text"
                        aria-label="Cari nama atau NIP pegawai"
                        placeholder="Cari nama, NIP..."
                        class="min-h-[44px] pl-10 border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-lg text-base sm:text-sm w-full"
                    />
                </div>
                <div class="relative w-full sm:w-auto">
                    <div
                        class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"
                    >
                        <svg
                            class="h-4 w-4 text-gray-400"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M3 4a1 1 0 011-1h16a1 1 0 01.8 1.6l-6.3 8.4v5.5a1 1 0 01-.45.83l-4 2.67A1 1 0 019 21v-8l-6.3-8.4A1 1 0 013 4z"
                            />
                        </svg>
                    </div>
                    <select
                        id="filter-kelompok-pembatalan"
                        name="kelompok"
                        v-model="kelompok"
                        aria-label="Filter kelompok substansi"
                        class="min-h-[44px] pl-9 pr-8 border-gray-300 focus:border-green-500 focus:ring-green-500 rounded-lg text-base sm:text-sm text-gray-600 w-full sm:w-auto"
                    >
                        <option value="">Semua Kelompok</option>
                        <option
                            v-for="k in daftarKelompokSubstansi"
                            :key="k"
                            :value="k"
                        >
                            {{ k }}
                        </option>
                    </select>
                </div>
            </div>
            <div v-if="kelompok" class="flex items-center gap-2 mb-4 text-xs">
                <span
                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-green-50 text-green-700 border border-green-200 font-semibold"
                >
                    {{ kelompok }}
                    <button
                        type="button"
                        @click="kelompok = ''"
                        class="hover:text-green-900 cursor-pointer"
                        title="Hapus filter kelompok"
                    >
                        &times;
                    </button>
                </span>
                <span class="text-gray-500"
                    >{{ daftarCuti.length }} pengajuan</span
                >
            </div>
            <div class="hidden overflow-x-auto md:block">
                <table
                    class="min-w-full divide-y divide-gray-200 border-b border-gray-200"
                >
                    <thead class="bg-gray-50">
                        <tr>
                            <th
                                scope="col"
                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                            >
                                Nama Karyawan
                            </th>
                            <th
                                scope="col"
                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                            >
                                Jenis Cuti
                            </th>
                            <th
                                scope="col"
                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                            >
                                Tanggal Cuti
                            </th>
                            <th
                                scope="col"
                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                            >
                                Durasi
                            </th>
                            <th
                                scope="col"
                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                            >
                                Status Saat Ini
                            </th>
                            <th
                                scope="col"
                                class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider"
                            >
                                Aksi
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <tr
                            v-for="item in paginatedData"
                            :key="item.id"
                            class="hover:bg-gray-50"
                        >
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900">
                                    {{ item.pegawai?.nama || "-" }}
                                </div>
                                <div
                                    class="text-xs text-gray-500 flex items-center gap-1.5 flex-wrap mt-0.5"
                                >
                                    <span v-if="item.pegawai?.jabatan">
                                        {{ item.pegawai?.jabatan }}
                                    </span>
                                </div>
                            </td>
                            <td
                                class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"
                            >
                                <span
                                    class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-200 text-blue-800 border border-blue-300"
                                >
                                    {{ item.jenis_cuti || "-" }}
                                </span>
                            </td>
                            <td
                                class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"
                            >
                                {{ formatDate(item.tanggal_mulai) }} s/d
                                {{ formatDate(item.tanggal_selesai) }}
                            </td>
                            <td
                                class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"
                            >
                                {{ getDurasi(item) }} Hari
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <span
                                    class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-bold tracking-wide uppercase border"
                                    :class="statusBadgeClass(item.status)"
                                >
                                    {{ formatStatusBadge(item.status) }}
                                </span>
                            </td>
                            <td
                                class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium"
                            >
                                <div
                                    class="flex items-center justify-center gap-2"
                                >
                                    <button
                                        type="button"
                                        @click="bukaDetailModal(item)"
                                        title="Lihat Detail Pengajuan"
                                        class="inline-flex items-center justify-center p-1.5 bg-white border border-gray-300 rounded-lg text-gray-500 hover:bg-gray-50 hover:text-blue-600 transition-colors shadow-sm"
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
                                                d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
                                            />
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                stroke-width="2"
                                                d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"
                                            />
                                        </svg>
                                    </button>
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
                                        Tidak Bisa
                                    </span>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="paginatedData.length === 0">
                            <td
                                colspan="6"
                                class="px-6 py-8 text-center text-gray-500"
                            >
                                Tidak ada pengajuan cuti yang bisa ditangguhkan
                                saat ini.
                                <button
                                    v-if="adaFilterAktif"
                                    type="button"
                                    @click="resetFilter"
                                    class="ml-1 text-orange-700 font-semibold hover:underline cursor-pointer"
                                >
                                    Reset filter
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="space-y-3 md:hidden">
                <article
                    v-for="item in paginatedData"
                    :key="item.id"
                    class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h3 class="break-words text-sm font-semibold text-gray-900">
                                {{ item.pegawai?.nama || "-" }}
                            </h3>
                            <p v-if="item.pegawai?.jabatan" class="mt-1 break-words text-xs text-gray-500">
                                {{ item.pegawai.jabatan }}
                            </p>
                        </div>
                        <span
                            class="shrink-0 rounded-md border px-2 py-1 text-xs font-bold uppercase"
                            :class="statusBadgeClass(item.status)"
                        >
                            {{ formatStatusBadge(item.status) }}
                        </span>
                    </div>
                    <dl class="mt-3 space-y-2 text-sm">
                        <div class="flex justify-between gap-3">
                            <dt class="text-gray-500">Jenis cuti</dt>
                            <dd class="text-right font-medium text-gray-800">{{ item.jenis_cuti || "-" }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-gray-500">Tanggal</dt>
                            <dd class="text-right text-gray-800">
                                {{ formatDate(item.tanggal_mulai) }} s/d {{ formatDate(item.tanggal_selesai) }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-gray-500">Durasi</dt>
                            <dd class="font-medium text-gray-800">{{ getDurasi(item) }} hari</dd>
                        </div>
                    </dl>
                    <div class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <button
                            type="button"
                            @click="bukaDetailModal(item)"
                            class="min-h-[44px] rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700"
                        >
                            Lihat detail
                        </button>
                        <button
                            v-if="bisaDitangguhkan(item.status)"
                            type="button"
                            @click.prevent="openSuspendModal(item.id, item.pegawai?.nama)"
                            class="min-h-[44px] rounded-lg border border-orange-200 bg-orange-50 px-3 py-2 text-sm font-semibold text-orange-700"
                        >
                            Tangguhkan
                        </button>
                        <span
                            v-else
                            class="flex min-h-[44px] items-center justify-center rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-400"
                            title="Cuti ini sudah ditolak atau sudah dibatalkan/ditangguhkan sebelumnya, tidak dapat ditangguhkan"
                        >
                            Tidak bisa ditangguhkan
                        </span>
                    </div>
                </article>
                <div
                    v-if="paginatedData.length === 0"
                    class="rounded-xl border border-gray-200 bg-white px-4 py-8 text-center text-sm text-gray-500"
                >
                    Tidak ada pengajuan cuti yang bisa ditangguhkan saat ini.
                </div>
            </div>
            <div
                v-if="totalData > 0"
                class="flex flex-col sm:flex-row items-center justify-between bg-white px-2 py-4 mt-2 gap-4"
            >
                <div class="text-sm text-gray-500">
                    Menampilkan
                    <span class="font-medium text-gray-700">{{
                        displayedFrom
                    }}</span
                    >–<span class="font-medium text-gray-700">{{
                        displayedTo
                    }}</span>
                    dari
                    <span class="font-medium text-gray-700">{{
                        totalData
                    }}</span>
                    data
                </div>
                <div>
                    <nav
                        class="isolate inline-flex -space-x-px rounded-md shadow-sm"
                        aria-label="Pagination"
                    >
                        <button
                            @click="prevPage"
                            :disabled="currentPage === 1"
                            class="relative inline-flex items-center rounded-l-md px-3 py-2 text-sm font-medium text-gray-500 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed focus:z-20 focus:outline-offset-0 transition-colors"
                        >
                            &laquo; Previous
                        </button>
                        <button
                            v-for="(page, index) in pageNumbers"
                            :key="index"
                            @click="page !== '...' ? setPage(page) : null"
                            :class="[
                                page === currentPage
                                    ? 'z-10 bg-blue-600 text-white focus-visible:outline-blue-600 font-bold border-blue-600'
                                    : 'text-gray-700 bg-white hover:bg-gray-50',
                                page === '...'
                                    ? 'cursor-default'
                                    : 'cursor-pointer',
                                'relative inline-flex items-center px-4 py-2 text-sm font-medium ring-1 ring-inset ring-gray-300 focus:z-20 focus:outline-offset-0 transition-colors',
                            ]"
                        >
                            {{ page }}
                        </button>
                        <button
                            @click="nextPage"
                            :disabled="currentPage === totalPages"
                            class="relative inline-flex items-center rounded-r-md px-3 py-2 text-sm font-medium text-gray-500 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed focus:z-20 focus:outline-offset-0 transition-colors"
                        >
                            Next &raquo;
                        </button>
                    </nav>
                </div>
            </div>
        </div>
    </MainLayout>

    <!-- MODAL DETAIL — disamakan dengan Dashboard / MonitoringCuti -->
    <Teleport to="body">
        <div
            v-if="detailModal.show"
            class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 backdrop-blur-sm p-0 sm:items-center sm:p-4"
            @click.self="closeDetailModal"
        >
            <div
                class="bg-white rounded-t-2xl shadow-xl w-full max-w-lg max-h-[90dvh] flex flex-col overflow-hidden sm:rounded-2xl"
            >
                <div
                    class="shrink-0 bg-white flex justify-between items-start gap-3 px-6 py-4 border-b border-gray-100"
                >
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
                                class="text-lg font-semibold text-slate-800 leading-tight"
                            >
                                Detail Cuti
                            </h3>
                            <p
                                class="text-[11px] font-medium text-slate-500 mt-0.5"
                            >
                                Informasi lengkap status dan permohonan.
                            </p>
                        </div>
                    </div>
                    <span
                        v-if="detailModal.data"
                        class="shrink-0 px-3 py-1 rounded-full text-[10px] font-extrabold uppercase tracking-wider whitespace-nowrap"
                        :class="statusBadgeClass(detailModal.data.status)"
                    >
                        {{ formatStatusBadge(detailModal.data.status) }}
                    </span>
                </div>
                <div
                    class="flex-1 overflow-y-auto px-6 py-4 space-y-3 text-sm"
                    v-if="detailModal.data"
                >
                    <div class="grid grid-cols-12 gap-2">
                        <div class="col-span-4 text-gray-500 font-medium">
                            Nama Pegawai
                        </div>
                        <div
                            class="col-span-8 font-semibold text-slate-800 leading-snug"
                        >
                            {{ detailModal.data.pegawai?.nama || "-" }}
                        </div>
                    </div>
                    <div class="grid grid-cols-12 gap-2">
                        <div class="col-span-4 text-gray-500 font-medium">
                            NIP
                        </div>
                        <div class="col-span-8 text-slate-700 font-medium">
                            {{ detailModal.data.pegawai?.nip || "-" }}
                        </div>
                    </div>
                    <div class="grid grid-cols-12 gap-2">
                        <div class="col-span-4 text-gray-500 font-medium">
                            Jabatan
                        </div>
                        <div
                            class="col-span-8 text-slate-800 font-semibold uppercase leading-snug"
                        >
                            {{ detailModal.data.pegawai?.jabatan || "-" }}
                        </div>
                    </div>
                    <div class="grid grid-cols-12 gap-2">
                        <div class="col-span-4 text-gray-500 font-medium">
                            Kelompok Substansi
                        </div>
                        <div class="col-span-8 text-slate-800 font-semibold">
                            {{
                                detailModal.data.pegawai?.kelompok_substansi ||
                                detailModal.data.pegawai?.departemen ||
                                "-"
                            }}
                        </div>
                    </div>
                    <div class="grid grid-cols-12 gap-2">
                        <div class="col-span-4 text-gray-500 font-medium">
                            Jenis Cuti
                        </div>
                        <div class="col-span-8 font-semibold text-indigo-600">
                            {{ detailModal.data.jenis_cuti || "-" }}
                        </div>
                    </div>

                    <!-- BARU: Anak ke berapa — hanya Cuti Melahirkan -->
                    <div
                        v-if="
                            detailModal.data.jenis_cuti
                                ?.toLowerCase()
                                .includes('melahirkan') &&
                            detailModal.data.anak_ke
                        "
                        class="grid grid-cols-12 gap-2"
                    >
                        <div class="col-span-4 text-gray-500 font-medium">
                            Anak ke berapa
                        </div>
                        <div class="col-span-8 text-slate-800 font-semibold">
                            {{ detailModal.data.anak_ke }}
                        </div>
                    </div>

                    <div class="grid grid-cols-12 gap-2">
                        <div class="col-span-4 text-gray-500 font-medium">
                            Waktu Cuti
                        </div>
                        <div class="col-span-8 text-slate-800 font-semibold">
                            {{ formatDate(detailModal.data.tanggal_mulai) }} s/d
                            {{ formatDate(detailModal.data.tanggal_selesai) }}
                            ({{ getDurasi(detailModal.data) }} Hari)
                        </div>
                    </div>
                    <div class="grid grid-cols-12 gap-2">
                        <div class="col-span-4 text-gray-500 font-medium">
                            Status
                        </div>
                        <div class="col-span-8 text-slate-800 font-semibold">
                            {{ formatStatus(detailModal.data.status) }}
                        </div>
                    </div>
                    <div class="grid grid-cols-12 gap-2">
                        <div class="col-span-4 text-gray-500 font-medium">
                            Alasan / Keterangan
                        </div>
                        <div
                            class="col-span-8 text-slate-800 font-semibold leading-relaxed"
                        >
                            {{ detailModal.data.keterangan || "-" }}
                        </div>
                    </div>
                    <div class="grid grid-cols-12 gap-2">
                        <div class="col-span-4 text-gray-500 font-medium">
                            Alamat
                        </div>
                        <div
                            class="col-span-8 text-slate-800 font-semibold uppercase leading-normal"
                        >
                            {{
                                detailModal.data.alamat_selama_cuti ||
                                detailModal.data.alamat_cuti ||
                                "-"
                            }}
                        </div>
                    </div>
                    <div class="grid grid-cols-12 gap-2">
                        <div class="col-span-4 text-gray-500 font-medium">
                            Nomor Telepon
                        </div>
                        <div class="col-span-8 text-slate-800 font-semibold">
                            {{ getNomorTelepon(detailModal.data) }}
                        </div>
                    </div>
                    <div class="grid grid-cols-12 gap-2 items-center">
                        <div class="col-span-4 text-gray-500 font-medium">
                            Lampiran
                        </div>
                        <div class="col-span-8">
                            <a
                                v-if="detailModal.data.lampiran"
                                :href="detailModal.data.lampiran"
                                target="_blank"
                                rel="noopener"
                                class="inline-flex items-center gap-2 px-3 py-1.5 bg-emerald-50 border border-emerald-200 text-emerald-600 hover:bg-emerald-100 rounded-xl text-xs font-medium transition-colors"
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

                    <!-- Info penangguhan (aksi halaman ini) -->
                    <div
                        class="rounded-xl p-4 border bg-orange-50 border-orange-200"
                    >
                        <h4
                            class="text-xs font-bold uppercase tracking-wider mb-1.5 flex items-center gap-1.5 text-orange-700"
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
                                    d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
                                />
                            </svg>
                            Catatan Penangguhan
                        </h4>
                        <p class="text-sm text-orange-800/90 leading-relaxed">
                            Menangguhkan cuti ini akan mengembalikan saldo cuti
                            yang sebelumnya sudah dipotong (jika statusnya sudah
                            Disetujui), dan menghentikan proses persetujuan bila
                            masih berjalan (L1-L4).
                        </p>
                    </div>
                </div>
                <div
                    class="shrink-0 bg-white border-t border-gray-100 px-6 py-4 flex items-center justify-end gap-3"
                >
                    <button
                        type="button"
                        @click="closeDetailModal"
                        class="px-5 py-2 bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 rounded-xl text-sm font-bold transition shadow-sm"
                    >
                        Tutup
                    </button>
                    <button
                        v-if="bisaDitangguhkanDetail"
                        type="button"
                        @click="detailModalTangguhkan"
                        class="px-5 py-2 bg-orange-600 hover:bg-orange-700 text-white rounded-xl text-sm font-bold transition shadow-sm"
                    >
                        Tangguhkan
                    </button>
                </div>
            </div>
        </div>
    </Teleport>

    <!-- MODAL PENANGGUHAN -->
    <Teleport to="body">
        <div
            v-if="suspendData.show"
            class="fixed inset-0 z-[9999] flex items-end justify-center bg-slate-900/60 backdrop-blur-sm p-0 sm:items-center sm:p-4"
            @click.self="closeSuspendModal"
        >
            <div
                class="bg-white rounded-t-2xl sm:rounded-2xl max-w-md w-full max-h-[90dvh] overflow-y-auto shadow-2xl border border-slate-100"
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
                        for="alasan-penangguhan"
                        class="block text-sm font-semibold text-slate-700 mb-2"
                        >Alasan Penangguhan
                        <span class="text-red-500">*</span></label
                    >
                    <textarea
                        id="alasan-penangguhan"
                        name="alasan"
                        v-model="suspendData.alasan"
                        rows="3"
                        class="w-full min-h-[88px] text-base sm:text-sm border rounded-xl focus:ring-orange-500 focus:border-orange-500"
                        :class="
                            suspendError
                                ? 'border-red-400 focus:ring-red-500 focus:border-red-500'
                                : 'border-slate-200'
                        "
                        placeholder="Contoh: Perintah tugas mendadak untuk persiapan dinas luar kota."
                        @input="suspendError = ''"
                    ></textarea>
                    <p
                        v-if="suspendError"
                        class="mt-2 text-xs font-semibold text-red-600 flex items-center gap-1.5"
                    >
                        <svg
                            class="w-3.5 h-3.5 shrink-0"
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
                        {{ suspendError }}
                    </p>
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

    <!-- TOAST -->
    <Teleport to="body">
        <Transition
            enter-active-class="transform ease-out duration-300 transition"
            enter-from-class="-translate-y-10 opacity-0"
            enter-to-class="translate-y-0 opacity-100"
            leave-active-class="transition ease-in duration-200"
            leave-from-class="opacity-100 translate-y-0"
            leave-to-class="opacity-0 -translate-y-10"
        >
            <div
                v-if="toast.show"
                class="fixed top-6 left-1/2 -translate-x-1/2 z-[10000] max-w-sm w-full bg-white shadow-2xl rounded-2xl pointer-events-auto border border-gray-100 overflow-hidden flex items-start p-4"
            >
                <div class="shrink-0 pt-0.5">
                    <div
                        class="h-8 w-8 rounded-full bg-emerald-100 flex items-center justify-center"
                    >
                        <svg
                            class="h-5 w-5 text-emerald-600"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke-width="2.5"
                            stroke="currentColor"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M4.5 12.75l6 6 9-13.5"
                            />
                        </svg>
                    </div>
                </div>
                <div class="ml-3 w-0 flex-1">
                    <p class="text-sm font-bold text-gray-900">Aksi Berhasil</p>
                    <p class="mt-1 text-sm text-gray-600">{{ toast.message }}</p>
                </div>
                <div class="ml-4 flex shrink-0">
                    <button
                        type="button"
                        @click="toast.show = false"
                        class="inline-flex rounded-md bg-white text-gray-400 hover:text-gray-600 focus:outline-none transition-colors"
                    >
                        <span class="sr-only">Tutup</span>
                        <svg
                            class="h-5 w-5"
                            viewBox="0 0 20 20"
                            fill="currentColor"
                        >
                            <path
                                fill-rule="evenodd"
                                d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
                                clip-rule="evenodd"
                            />
                        </svg>
                    </button>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
