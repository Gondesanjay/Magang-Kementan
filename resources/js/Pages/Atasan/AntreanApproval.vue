<script setup>
import MainLayout from "@/Layouts/MainLayout.vue";
import { Head, router, usePage } from "@inertiajs/vue3";
import { ref, watch, computed } from "vue";


// Debounce lokal (tidak butuh package lodash)
function debounce(fn, delay) {
    let timeoutId;
    return (...args) => {
        clearTimeout(timeoutId);
        timeoutId = setTimeout(() => fn(...args), delay);
    };
}


const props = defineProps({
    antrean: Object, // paginated: { data: [], links: [], ... }
    filters: Object,
});


// Admin HR (role 5) hanya memonitor, tidak bisa approve/reject
const page = usePage();
const isReadOnly = computed(() => page.props.auth.user.role_id === 5);


const search = ref(props.filters?.search || "");
const jenisCuti = ref(props.filters?.jenis_cuti || "Semua Jenis Cuti");


// Auto-search saat mengetik atau mengganti filter jenis cuti
watch(
    [search, jenisCuti],
    debounce(function ([newSearch, newJenis]) {
        router.get(
            route("atasan.approval"),
            {
                search: newSearch,
                jenis_cuti: newJenis,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    }, 300),
);


const statusLabels = {
    menunggu_l1: "Menunggu Bapak Ketua Tim Kerja (L1)",
    menunggu_l2: "Menunggu Bapak Ketua Kelompok Substansi (L2)",
    menunggu_l3: "Menunggu Ignatius Agus Hendarto (L3)",
    menunggu_l4: "Menunggu Seta Rukmalasari Agustina (L4)",
};


// Fungsi memformat tanggal (YYYY-MM-DD ke DD-MM-YYYY)
const formatDate = (dateString) => {
    if (!dateString) return "-";
    const date = new Date(dateString);
    return new Intl.DateTimeFormat("id-ID", {
        day: "2-digit",
        month: "short",
        year: "numeric",
    }).format(date);
};


// Fungsi memproses aksi Setujui / Tolak
const processApproval = (id, action) => {
    const actionText = action === "approve" ? "MENYETUJUI" : "MENOLAK";
    if (confirm(`Apakah Anda yakin ingin ${actionText} pengajuan cuti ini?`)) {
        router.post(
            route("atasan.approval.process", id),
            { action: action },
            {
                preserveScroll: true,
            },
        );
    }
};


// Navigasi pagination (Laravel paginator links)
const goToPage = (url) => {
    if (!url) return;
    router.get(
        url,
        {},
        {
            preserveState: true,
            preserveScroll: true,
        },
    );
};


// ================= MODAL DETAIL CUTI (Semua Role) =================
const detailModal = ref({ show: false, data: null });


const bukaDetailModal = (item) => {
    detailModal.value.data = item;
    detailModal.value.show = true;
};


const closeDetailModal = () => {
    detailModal.value.show = false;
    detailModal.value.data = null;
};
// ================= END MODAL DETAIL CUTI =================


// ================= HELPER: PEMBERSIH KALIMAT KETERANGAN =================
// Merapikan data teks lama (format mentah "... | [DITANGGUHKAN/DIBATALKAN ATASAN: ...]")
// maupun data baru, supaya tampil sebagai kalimat manusiawi yang rapi
// baik di tabel antrean maupun di Modal Detail Cuti.
const formatKeteranganRapi = (text) => {
    if (!text || text === "-") return "-";


    // Cek apakah teks mengandung format bawaan sistem yang pakai '|' atau '['
    if (text.includes("|") || text.includes("[DITANGGUHKAN")) {
        let bagian = text.split("|").map((item) => item.trim());
        let alasanAwal =
            bagian[0] && bagian[0] !== "-" ? bagian[0] : "Ada keperluan";


        // Ekstrak catatan atasan dari format mentah database
        let regex = /\[DITANGGUHKAN\/DIBATALKAN ATASAN:\s*(.*?)\]/i;
        let match = text.match(regex);


        if (match && match[1]) {
            let catatanAtasan = match[1].trim();
            // Menggunakan nama lengkap tanpa gelar sesuai kesepakatan
            return `${alasanAwal} (Ditangguhkan oleh Seta Rukmalasari Agustina: ${catatanAtasan})`;
        }


        return alasanAwal;
    }


    return text;
};
// ================= END HELPER KETERANGAN =================
</script>


<template>
    <Head title="Antrean Approval" />


    <MainLayout>
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
            <div
                class="flex flex-col md:flex-row justify-between items-center mb-6 gap-4"
            >
                <h2 class="text-2xl font-bold text-gray-800">
                    Antrean Persetujuan Cuti
                </h2>


                <div class="flex flex-col sm:flex-row gap-3 w-full md:w-auto">
                    <!-- Dropdown Jenis Cuti -->
                    <select
                        v-model="jenisCuti"
                        class="border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-lg text-sm text-gray-600"
                    >
                        <option value="Semua Jenis Cuti">
                            Semua Jenis Cuti
                        </option>
                        <option value="Cuti Tahunan">Cuti Tahunan</option>
                        <option value="Cuti Besar">Cuti Besar</option>
                        <option value="Cuti Alasan Penting">
                            Cuti Alasan Penting
                        </option>
                        <option value="Cuti Melahirkan">Cuti Melahirkan</option>
                    </select>


                    <!-- Input Pencarian -->
                    <div class="relative">
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
                            v-model="search"
                            type="text"
                            placeholder="Cari nama, NIP..."
                            class="pl-10 border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-lg text-sm w-full md:w-64"
                        />
                    </div>
                </div>
            </div>


            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
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
                                Tahap
                            </th>
                            <th
                                scope="col"
                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                            >
                                Keterangan
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
                            v-for="item in antrean.data"
                            :key="item.id"
                            class="hover:bg-gray-50"
                        >
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900">
                                    {{ item.pegawai.nama }}
                                </div>
                                <div class="text-xs text-gray-500">
                                    {{ item.pegawai.departemen }}
                                </div>
                            </td>


                            <td
                                class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"
                            >
                                <span
                                    class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800"
                                >
                                    {{ item.jenis_cuti }}
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
                                {{ item.jumlah_hari }} Hari
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <span
                                    class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium"
                                    :class="{
                                        'bg-yellow-100 text-yellow-800':
                                            item.status?.includes('menunggu'),
                                        'bg-green-100 text-green-800':
                                            item.status === 'disetujui',
                                        'bg-red-100 text-red-800':
                                            item.status === 'ditolak' ||
                                            item.status?.includes('dibatalkan'),
                                        'bg-orange-100 text-orange-800':
                                            item.status === 'ditangguhkan',
                                        'bg-gray-100 text-gray-800':
                                            !item.status,
                                    }"
                                >
                                    {{
                                        statusLabels[item.status] || item.status
                                    }}
                                </span>
                            </td>
                            <!-- Kolom Keterangan: pakai helper formatKeteranganRapi agar
                                 kalimat lama/format mentah tetap tampil rapi -->
                            <td
                                class="px-6 py-4 text-sm text-gray-500 max-w-xs truncate"
                                :title="formatKeteranganRapi(item.keterangan)"
                            >
                                {{ formatKeteranganRapi(item.keterangan) }}
                            </td>
                            <!-- Kolom Aksi (Urutan: Detail -> Setujui -> Tolak) -->
                            <td
                                class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium"
                            >
                                <div
                                    class="flex items-center justify-center gap-2"
                                >
                                    <!-- 1. TOMBOL DETAIL (IKON MATA) DI PALING KIRI —
                                         tersedia untuk SEMUA role, baik yang bisa
                                         approve/reject maupun Admin HR read-only, agar
                                         semua bisa melihat rincian pengajuan lewat
                                         Modal Detail Cuti. -->
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


                                    <!-- 2. TOMBOL PERSETUJUAN DI SEBELAH KANAN -->
                                    <template v-if="!isReadOnly">
                                        <button
                                            @click="
                                                processApproval(
                                                    item.id,
                                                    'approve',
                                                )
                                            "
                                            class="inline-flex items-center px-3 py-1.5 bg-green-600 border border-transparent rounded-lg font-semibold text-xs text-white uppercase tracking-wider hover:bg-green-700 transition ease-in-out duration-150 shadow-sm"
                                        >
                                            Setujui
                                        </button>
                                        <button
                                            @click="
                                                processApproval(
                                                    item.id,
                                                    'reject',
                                                )
                                            "
                                            class="inline-flex items-center px-3 py-1.5 bg-red-600 border border-transparent rounded-lg font-semibold text-xs text-white uppercase tracking-wider hover:bg-red-700 transition ease-in-out duration-150 shadow-sm"
                                        >
                                            Tolak
                                        </button>
                                    </template>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="antrean.data.length === 0">
                            <td
                                colspan="7"
                                class="px-6 py-8 text-center text-gray-500"
                            >
                                Saat ini tidak ada antrean pengajuan cuti yang
                                memerlukan persetujuan Anda.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>


            <!-- Pagination -->
            <div
                v-if="antrean.links && antrean.links.length > 3"
                class="flex flex-wrap items-center justify-between gap-2 mt-6"
            >
                <p class="text-sm text-gray-500">
                    Menampilkan {{ antrean.from ?? 0 }}–{{
                        antrean.to ?? 0
                    }}
                    dari {{ antrean.total ?? 0 }} data
                </p>
                <div class="flex flex-wrap gap-1">
                    <button
                        v-for="(link, index) in antrean.links"
                        :key="index"
                        v-html="link.label"
                        :disabled="!link.url"
                        @click="goToPage(link.url)"
                        class="px-3 py-1 text-sm rounded-md border"
                        :class="[
                            link.active
                                ? 'bg-blue-600 border-blue-600 text-white'
                                : 'bg-white border-gray-300 text-gray-600 hover:bg-gray-50',
                            !link.url
                                ? 'opacity-50 cursor-not-allowed'
                                : 'cursor-pointer',
                        ]"
                    ></button>
                </div>
            </div>
        </div>
    </MainLayout>


    <!-- ================= MODAL DETAIL CUTI (Semua Role) ================= -->
    <Teleport to="body">
        <div
            v-if="detailModal.show"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm p-4"
            @click.self="closeDetailModal"
        >
            <div
                class="bg-white rounded-2xl shadow-xl w-full max-w-lg overflow-hidden mx-4"
            >
                <!-- Header Modal -->
                <div
                    class="flex justify-between items-center px-6 py-5 border-b border-gray-100"
                >
                    <h3 class="text-lg font-semibold text-slate-800">
                        Detail Cuti
                    </h3>
                    <button
                        type="button"
                        @click="closeDetailModal"
                        class="text-gray-400 hover:text-gray-600 transition-colors"
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
                            />
                        </svg>
                    </button>
                </div>


                <!-- Body Modal: layout list, label kiri - nilai kanan -->
                <div
                    class="px-6 py-5 space-y-3.5 text-sm"
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
                            Departemen
                        </div>
                        <div class="col-span-8 text-slate-800 font-semibold">
                            {{ detailModal.data.pegawai?.departemen || "-" }}
                        </div>
                    </div>


                    <div class="grid grid-cols-12 gap-2">
                        <div class="col-span-4 text-gray-500 font-medium">
                            Jenis Cuti
                        </div>
                        <div class="col-span-8 font-semibold text-slate-800">
                            {{ detailModal.data.jenis_cuti || "-" }}
                        </div>
                    </div>


                    <!-- Sisa Cuti Tahunan (mengikuti logika Dashboard) -->
                    <div
                        v-if="
                            detailModal.data.jenis_cuti
                                ?.toLowerCase()
                                .includes('tahunan')
                        "
                        class="grid grid-cols-12 gap-2 items-center bg-blue-50/50 border border-blue-100 rounded-lg px-2 py-2 -mx-1"
                    >
                        <div class="col-span-4 text-gray-600 font-medium">
                            Sisa Cuti Tahunan
                        </div>
                        <div class="col-span-8 font-bold text-blue-600">
                            {{
                                detailModal.data.pegawai?.sisa_cuti_tersedia ??
                                0
                            }}
                            Hari
                            <span
                                class="text-xs font-normal text-gray-500 ml-1"
                            >
                                (Tahun ini:
                                {{
                                    (detailModal.data.pegawai
                                        ?.sisa_cuti_tersedia ?? 0) -
                                    (detailModal.data.pegawai
                                        ?.sisa_tahun_lalu ?? 0)
                                }}
                                hari, Tahun lalu:
                                {{
                                    detailModal.data.pegawai?.sisa_tahun_lalu ??
                                    0
                                }}
                                hari)
                            </span>
                        </div>
                    </div>


                    <div class="grid grid-cols-12 gap-2">
                        <div class="col-span-4 text-gray-500 font-medium">
                            Waktu Cuti
                        </div>
                        <div class="col-span-8 text-slate-800 font-semibold">
                            {{ formatDate(detailModal.data.tanggal_mulai) }} s/d
                            {{ formatDate(detailModal.data.tanggal_selesai) }}
                            ({{ detailModal.data.jumlah_hari }} Hari)
                        </div>
                    </div>


                    <!-- Alasan: pakai helper formatKeteranganRapi agar kalimat lama/
                         format mentah tetap tampil sebagai kalimat manusiawi -->
                    <div class="grid grid-cols-12 gap-2">
                        <div class="col-span-4 text-gray-500 font-medium">
                            Alasan
                        </div>
                        <div
                            class="col-span-8 text-slate-800 font-semibold leading-relaxed"
                        >
                            {{
                                formatKeteranganRapi(
                                    detailModal.data.keterangan,
                                )
                            }}
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


                    <div class="grid grid-cols-12 gap-2 items-center pt-1">
                        <div class="col-span-4 text-gray-500 font-medium">
                            Lampiran
                        </div>
                        <div class="col-span-8">
                            <div
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
                </div>


                <!-- Footer Modal: area status abu-abu pucat -->
                <div
                    class="bg-slate-50/70 border-t border-gray-100 px-6 py-4 text-center"
                >
                    <p
                        class="text-sm font-medium text-gray-500"
                        v-if="detailModal.data"
                    >
                        {{
                            statusLabels[detailModal.data.status] ||
                            detailModal.data.status ||
                            "Menunggu Persetujuan"
                        }}
                    </p>
                </div>
            </div>
        </div>
    </Teleport>
</template>



