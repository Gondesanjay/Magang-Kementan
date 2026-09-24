<script setup>
import { ref, watch, computed } from "vue";
import { router, Head } from "@inertiajs/vue3";
import MainLayout from "@/Layouts/MainLayout.vue";

// Halaman ini adalah versi ATASAN dari "Rekap Kuota Detail" milik HR Admin
// (lihat Pages/Admin/RekapKuotaDetail.vue). Data & tampilan tabel/modal
// SENGAJA dibuat identik supaya konsisten, tapi TANPA fitur "Impor Kuota"
// sama sekali — atasan hanya boleh melihat rekap saldo/kuota & riwayat cuti
// bawahannya, tidak boleh mengubah data secara massal.
const props = defineProps({
    dataPegawai: Object,
    filters: Object,
    userRoleId: Number, // 1=Staff, 2=L1, 3=L2, 4=L3, 6=L4
    userTimKerja: String, // dipakai untuk badge info L1
    listTimKerja: {
        type: Array,
        default: () => [],
    },
    listKelompok: {
        type: Array,
        default: () => [],
    },
});

const search = ref(props.filters?.search || "");

// State untuk Modal Detail Riwayat Cuti
const showModal = ref(false);
const selectedPegawai = ref(null);
const expandedRiwayatId = ref(null);

const openDetailModal = (pegawai) => {
    selectedPegawai.value = pegawai;
    expandedRiwayatId.value = null;
    showModal.value = true;
};

const closeModal = () => {
    showModal.value = false;
    selectedPegawai.value = null;
    expandedRiwayatId.value = null;
};

const toggleExpand = (id) => {
    expandedRiwayatId.value = expandedRiwayatId.value === id ? null : id;
};

// Label status generik — nama pejabat tampil di approval_chain (dari backend),
// bukan hardcode di sini.
const statusLabels = {
    menunggu_l1: "Menunggu Ketua Tim Kerja (L1)",
    menunggu_l2: "Menunggu Ketua Kelompok Substansi (L2)",
    menunggu_l3: "Menunggu Kasubag TU (L3)",
    menunggu_l4: "Menunggu Kepala Biro Perencanaan (L4)",
    disetujui: "Disetujui",
    ditolak: "Ditolak",
    dibatalkan_ditangguhkan: "Dibatalkan/Ditangguhkan",
    dibatalkan_reguler: "Dibatalkan",
};

const getStatusLabel = (st) => {
    return statusLabels[st] || st?.replace(/_/g, " ").toUpperCase();
};

const approvalStepLabel = (status) => {
    switch (status) {
        case "setuju":
            return "Setuju";
        case "tolak":
            return "Tolak";
        case "menunggu":
            return "Menunggu";
        case "belum_giliran":
            return "Belum Giliran";
        case "tangguh":
            return "Ditangguhkan";
        default:
            return status;
    }
};

const approvalStepClass = (status) => {
    switch (status) {
        case "setuju":
            return "text-emerald-600";
        case "tolak":
            return "text-rose-600";
        case "menunggu":
            return "text-amber-600";
        case "belum_giliran":
            return "text-gray-400";
        case "tangguh":
            return "text-orange-600";
        default:
            return "text-gray-500";
    }
};

// ================= APPROVAL CHAIN (MURNI DARI BACKEND) =================
// Tidak ada hardcode NIP/nama. Backend (RekapKuotaDetailController::
// buildApprovalChain + getRequiredLevels) sudah menentukan level mana yang
// relevan per role pemohon dan mengisi nama approver dari DB.
//
// Frontend hanya:
//   1) mengurutkan langkah berlevel L1 → L4
//   2) (opsional) menyembunyikan status "belum_giliran" agar tampilan ringkas
//
// Jika ingin menampilkan SEMUA langkah termasuk "Belum Giliran", ubah
// HIDE_BELUM_GILIRAN menjadi false.
const HIDE_BELUM_GILIRAN = true;

const urutkanChain = (chain) => {
    const berlevel = chain
        .filter((step) => step?.level)
        .sort((a, b) => Number(a.level) - Number(b.level));
    let i = 0;
    return chain.map((step) => (step?.level ? berlevel[i++] : step));
};

const getVisibleApprovalChain = (chainAsli) => {
    if (!Array.isArray(chainAsli)) return [];
    const chain = urutkanChain(chainAsli);
    if (!HIDE_BELUM_GILIRAN) return chain;
    return chain.filter((step) => step?.status !== "belum_giliran");
};
// ================= END APPROVAL CHAIN =================

// ================= FILTER BERJENJANG SESUAI LEVEL ATASAN =================
// Diselaraskan dengan pola yang sama di KalenderTim.vue:
//   L1 (role_id 2)       : TANPA dropdown, badge "Menampilkan tim Anda: ..."
//   L2 (role_id 3)       : dropdown Tim Kerja (dalam kelompoknya sendiri)
//   L3 & L4 (role_id 4/6): dropdown Kelompok/Subbagian (lintas kelompok)
const filterMode = computed(() => {
    if (props.userRoleId === 2) return "l1";
    if (props.userRoleId === 3) return "l2";
    if (props.userRoleId === 4 || props.userRoleId === 6) return "l3l4";
    return "none";
});

const showFilterDropdown = computed(
    () => filterMode.value === "l2" || filterMode.value === "l3l4",
);

const showBadgeTimL1 = computed(
    () => filterMode.value === "l1" && !!props.userTimKerja,
);

const filterQueryParam = computed(() =>
    filterMode.value === "l2" ? "tim_kerja" : "kelompok_substansi",
);

const filterAllLabel = computed(() =>
    filterMode.value === "l2" ? "Semua Tim Kerja" : "Semua Kelompok",
);

const filterOptions = computed(() => {
    if (filterMode.value === "l2") return props.listTimKerja || [];
    if (filterMode.value === "l3l4") return props.listKelompok || [];
    return [];
});

const kelompok = ref(props.filters?.[filterQueryParam.value] || "");
// ================= END FILTER BERJENJANG =================

const daftarPegawai = computed(() => props.dataPegawai?.data ?? []);

const hitungDurasi = (mulai, selesai) => {
    if (!mulai || !selesai) return 1;
    const tglMulai = new Date(mulai);
    const tglSelesai = new Date(selesai);
    const selisihWaktu = tglSelesai - tglMulai;
    const selisihHari = Math.ceil(selisihWaktu / (1000 * 60 * 60 * 24)) + 1;
    return selisihHari > 0 ? selisihHari : 1;
};

const getDurasi = (item) => {
    if (item?.jumlah_hari && item.jumlah_hari > 0) {
        return item.jumlah_hari;
    }
    return hitungDurasi(item?.tanggal_mulai, item?.tanggal_selesai);
};

const formatKeteranganRapi = (teksAsli) => {
    if (!teksAsli || teksAsli === "-") return "-";

    const text = teksAsli
        .replace(/\s*[—–-]\s*Pengajuan Cuti Ulang\b/gi, "")
        .trim();
    if (!text) return "-";

    if (text.includes("|") || text.includes("[DITANGGUHKAN")) {
        let bagian = text.split("|").map((item) => item.trim());
        let alasanAwal =
            bagian[0] && bagian[0] !== "-" ? bagian[0] : "Ada keperluan";

        let regex = /\[DITANGGUHKAN\/DIBATALKAN ATASAN:\s*(.*?)\]/i;
        let match = text.match(regex);

        if (match && match[1]) {
            let catatanAtasan = match[1].trim();
            return `${alasanAwal} (Ditangguhkan: ${catatanAtasan})`;
        }

        return alasanAwal;
    }

    return text;
};

function debounce(fn, delay = 300) {
    let timeoutId;
    return (...args) => {
        clearTimeout(timeoutId);
        timeoutId = setTimeout(() => fn(...args), delay);
    };
}

const buildParams = () => {
    const params = {};
    if (search.value) params.search = search.value;
    if (kelompok.value) params[filterQueryParam.value] = kelompok.value;
    return params;
};

const terapkanFilter = debounce(() => {
    router.get(route("atasan.kuota"), buildParams(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}, 300);

watch([search, kelompok], terapkanFilter);

const goToPage = (url) => {
    if (!url) return;
    router.get(url, buildParams(), {
        preserveState: true,
        preserveScroll: true,
    });
};

// ================= PERHITUNGAN KUOTA =================
const currentYear = new Date().getFullYear();

const toNum = (nilai) => {
    const angka = Number(nilai);
    return Number.isFinite(angka) ? angka : 0;
};

const getTerpakai = (pegawai) => {
    return toNum(pegawai?.cuti_terpakai);
};

const getSisaTahunIni = (pegawai) => {
    return toNum(pegawai?.sisa_cuti_tahun_ini) + getTerpakai(pegawai);
};

const getSaldoBawaanEligible = (pegawai) => {
    return toNum(pegawai?.carry_forward_normal);
};

const getTotalTersedia = (pegawai) => {
    return getSisaTahunIni(pegawai) + getSaldoBawaanEligible(pegawai);
};

const getSisaTahunBerjalan = (pegawai) => {
    const sisa = getSisaTahunIni(pegawai) - getTerpakai(pegawai);
    return sisa > 0 ? sisa : 0;
};

const getSaldoAkhir = (pegawai) => {
    return getTotalTersedia(pegawai) - getTerpakai(pegawai);
};
// ================= END PERHITUNGAN KUOTA =================
</script>

<template>
    <Head title="Rekap Kuota Cuti Pegawai" />

    <MainLayout>
        <div class="relative w-full h-full">
            <div class="p-6 bg-white rounded-xl shadow-sm">
                <div
                    class="flex flex-col md:flex-row justify-between items-center mb-6 gap-4"
                >
                    <div>
                        <h2 class="text-xl font-bold">
                            Rincian Kuota & Riwayat Pegawai
                        </h2>
                        <div
                            v-if="showBadgeTimL1"
                            class="inline-flex items-center gap-1.5 mt-2 px-2.5 py-1 rounded-full bg-green-50 border border-green-200 text-green-700 text-xs font-semibold"
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
                                    d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 100-8 4 4 0 000 8zm6 3c0-2.21-3.582-4-8-4s-8 1.79-8 4"
                                ></path>
                            </svg>
                            Menampilkan tim Anda: {{ userTimKerja }}
                        </div>
                    </div>

                    <div
                        class="flex flex-col sm:flex-row gap-3 items-center w-full md:w-auto"
                    >
                        <!-- FILTER BERJENJANG — ukuran lebar seperti versi lama -->
                        <div
                            v-if="showFilterDropdown"
                            class="relative w-full sm:w-[600px] shrink-0"
                        >
                            <svg
                                class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"
                                />
                            </svg>
                            <select
                                v-model="kelompok"
                                class="border border-gray-300 rounded-lg pl-9 pr-3 py-2 w-full text-sm bg-white truncate focus:ring-green-500 focus:border-green-500"
                            >
                                <option value="">{{ filterAllLabel }}</option>
                                <option
                                    v-for="opsi in filterOptions"
                                    :key="opsi"
                                    :value="opsi"
                                    :title="opsi"
                                >
                                    {{ opsi }}
                                </option>
                            </select>
                        </div>

                        <div class="relative w-full sm:w-auto">
                            <input
                                v-model="search"
                                type="text"
                                placeholder="Cari nama/NIP..."
                                class="border border-gray-300 rounded-lg pl-10 pr-4 py-2 w-full sm:w-[400px] text-sm focus:ring-green-500 focus:border-green-500"
                            />
                            <svg
                                class="w-4 h-4 text-gray-400 absolute left-3 top-3"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
                                />
                            </svg>
                        </div>
                    </div>
                </div>

                <div
                    v-if="kelompok && showFilterDropdown"
                    class="flex items-center gap-2 mb-4 text-xs"
                >
                    <span
                        class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-green-50 text-green-700 border border-green-200 font-semibold"
                    >
                        {{ kelompok }}
                        <button
                            type="button"
                            @click="kelompok = ''"
                            class="hover:text-green-900 cursor-pointer"
                            title="Hapus filter"
                        >
                            &times;
                        </button>
                    </span>
                </div>

                <div class="overflow-x-auto">
                    <table
                        class="w-full text-left border-collapse whitespace-nowrap"
                    >
                        <thead>
                            <tr
                                class="bg-slate-50 border-b border-slate-200 text-[11px] text-slate-500 uppercase tracking-wider font-bold"
                            >
                                <th class="p-4">Nama Pegawai</th>
                                <th class="p-4 text-center">
                                    Hak {{ currentYear }}
                                </th>
                                <th class="p-4 text-center">
                                    Terpakai {{ currentYear }}
                                </th>
                                <th class="p-4 text-center">
                                    Sisa {{ currentYear }}
                                </th>
                                <th class="p-4 text-center text-slate-800">
                                    Saldo Akhir {{ currentYear }}
                                </th>
                                <th class="p-4 text-center">Aksi</th>
                            </tr>
                        </thead>

                        <tbody class="text-sm align-middle">
                            <tr
                                v-for="pegawai in daftarPegawai"
                                :key="pegawai.id"
                                class="border-b border-slate-100 hover:bg-slate-50 transition-colors"
                            >
                                <td class="p-4">
                                    <div
                                        class="font-bold text-slate-800 text-sm"
                                    >
                                        {{ pegawai.nama }}
                                    </div>
                                    <div
                                        class="text-[11px] text-slate-500 mt-0.5"
                                    >
                                        {{
                                            pegawai.jabatan ||
                                            pegawai.kelompok_substansi ||
                                            pegawai.departemen ||
                                            "Staf"
                                        }}
                                    </div>
                                </td>

                                <td class="p-4 text-center">
                                    <span
                                        class="inline-block min-w-[32px] px-2 py-1 bg-slate-100 text-slate-700 rounded-md font-bold text-xs border border-slate-300/80"
                                    >
                                        {{ getSisaTahunIni(pegawai) }}
                                    </span>
                                </td>

                                <td class="p-4 text-center">
                                    <span
                                        class="inline-block min-w-[32px] px-2 py-1 bg-rose-100 text-rose-800 rounded-md font-bold text-xs border border-rose-300/80"
                                    >
                                        {{ pegawai.cuti_terpakai || 0 }}
                                    </span>
                                </td>

                                <td class="p-4 text-center">
                                    <span
                                        class="inline-block min-w-[32px] px-2 py-1 bg-blue-100 text-blue-800 rounded-md font-bold text-xs border border-blue-300/80"
                                    >
                                        {{ getSisaTahunBerjalan(pegawai) }}
                                    </span>
                                </td>

                                <td class="p-4 text-center">
                                    <span
                                        class="inline-block min-w-[36px] px-2.5 py-1 bg-slate-800 text-white rounded-md font-extrabold text-xs shadow-sm border border-slate-900"
                                    >
                                        {{ getSaldoAkhir(pegawai) }}
                                    </span>
                                </td>

                                <td class="p-4 text-center">
                                    <button
                                        @click="openDetailModal(pegawai)"
                                        type="button"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 border border-slate-300 rounded-lg text-xs font-semibold text-slate-700 bg-white hover:bg-slate-100 hover:text-slate-900 transition shadow-sm cursor-pointer"
                                        title="Lihat Riwayat Cuti"
                                    >
                                        Detail
                                    </button>
                                </td>
                            </tr>

                            <tr v-if="daftarPegawai.length === 0">
                                <td
                                    colspan="6"
                                    class="p-8 text-center text-slate-400"
                                >
                                    Tidak ada data pegawai ditemukan.
                                    <button
                                        v-if="kelompok || search"
                                        type="button"
                                        @click="
                                            search = '';
                                            kelompok = '';
                                        "
                                        class="ml-1 text-green-700 font-semibold hover:underline cursor-pointer"
                                    >
                                        Reset filter
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div
                    v-if="dataPegawai?.links && dataPegawai.links.length > 3"
                    class="flex flex-wrap items-center justify-between gap-2 mt-6"
                >
                    <p class="text-sm text-gray-500">
                        Menampilkan {{ dataPegawai.from ?? 0 }}–{{
                            dataPegawai.to ?? 0
                        }}
                        dari {{ dataPegawai.total ?? 0 }} data
                    </p>
                    <div class="flex flex-wrap gap-1">
                        <button
                            v-for="(link, index) in dataPegawai.links"
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

            <!-- MODAL DETAIL -->
            <div
                v-if="showModal"
                class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/60 p-4 backdrop-blur-xs overflow-y-auto"
            >
                <div
                    class="bg-white rounded-2xl max-w-2xl w-full shadow-2xl relative animate-in fade-in zoom-in duration-200 flex flex-col max-h-[85vh] my-auto"
                >
                    <div
                        class="border-b px-6 py-4 flex justify-between items-center bg-gray-50 rounded-t-2xl shrink-0"
                    >
                        <div>
                            <h3 class="text-xl font-bold text-gray-900">
                                Rincian Kuota & Riwayat Pegawai
                            </h3>
                            <p class="text-xs text-gray-500 mt-0.5">
                                Klik salah satu riwayat cuti untuk melihat
                                rincian lengkap
                            </p>
                        </div>
                        <button
                            @click="closeModal"
                            type="button"
                            class="text-gray-400 hover:text-red-500 transition cursor-pointer p-1.5 rounded-lg hover:bg-gray-200/50"
                        >
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="w-6 h-6"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
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

                    <div
                        v-if="selectedPegawai"
                        class="p-6 overflow-y-auto space-y-6"
                    >
                        <div
                            class="flex items-center gap-4 bg-gray-50/80 p-4 rounded-xl border border-gray-100"
                        >
                            <div
                                class="w-14 h-14 rounded-full bg-green-600 flex items-center justify-center text-white font-bold text-2xl uppercase shadow-sm shrink-0"
                            >
                                {{ selectedPegawai.nama?.charAt(0) || "?" }}
                            </div>
                            <div>
                                <h4 class="font-bold text-gray-900 text-lg">
                                    {{ selectedPegawai.nama }}
                                </h4>
                                <p class="text-xs text-gray-500 mt-0.5">
                                    NIP: {{ selectedPegawai.nip || "-" }}
                                    &middot;
                                    {{
                                        selectedPegawai.jabatan ||
                                        selectedPegawai.kelompok_substansi ||
                                        "Staf / Pegawai"
                                    }}
                                </p>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                            <div
                                class="bg-white p-3 rounded-xl border border-gray-200 shadow-sm text-center flex flex-col justify-center"
                            >
                                <p
                                    class="text-[10px] font-bold text-gray-400 uppercase tracking-wider"
                                >
                                    Hak {{ currentYear }}
                                </p>
                                <p
                                    class="text-2xl font-black text-gray-800 mt-1"
                                >
                                    {{ getSisaTahunIni(selectedPegawai) }}
                                    <span class="text-xs font-normal"
                                        >hari</span
                                    >
                                </p>
                            </div>

                            <div
                                class="bg-rose-50 p-3 rounded-xl border border-rose-100 shadow-sm text-center flex flex-col justify-center"
                            >
                                <p
                                    class="text-[10px] font-bold text-rose-500 uppercase tracking-wider"
                                >
                                    Terpakai {{ currentYear }}
                                </p>
                                <p
                                    class="text-2xl font-black text-rose-600 mt-1"
                                >
                                    {{ selectedPegawai.cuti_terpakai || 0 }}
                                    <span class="text-xs font-normal"
                                        >hari</span
                                    >
                                </p>
                            </div>

                            <div
                                class="bg-blue-50 p-3 rounded-xl border border-blue-100 shadow-sm text-center flex flex-col justify-center"
                            >
                                <p
                                    class="text-[10px] font-bold text-blue-500 uppercase tracking-wider"
                                >
                                    Sisa {{ currentYear }}
                                </p>
                                <p
                                    class="text-2xl font-black text-blue-600 mt-1"
                                >
                                    {{ getSisaTahunBerjalan(selectedPegawai) }}
                                    <span class="text-xs font-normal"
                                        >hari</span
                                    >
                                </p>
                            </div>

                            <div
                                class="bg-slate-800 border border-slate-900 text-white p-3 rounded-xl shadow-md text-center flex flex-col justify-center"
                            >
                                <p
                                    class="text-[10px] font-bold text-slate-400 uppercase tracking-wider"
                                >
                                    Saldo Akhir {{ currentYear }}
                                </p>
                                <p class="text-2xl font-black text-white mt-1">
                                    {{ getSaldoAkhir(selectedPegawai) }}
                                    <span class="text-xs font-normal"
                                        >hari</span
                                    >
                                </p>
                            </div>
                        </div>

                        <p
                            class="bg-gray-50 px-3 py-2 rounded-lg text-xs text-gray-600 border border-gray-100"
                        >
                            Saldo Akhir {{ currentYear }}:
                            <span class="font-semibold text-gray-800">
                                Hak {{ getSisaTahunIni(selectedPegawai) }} +
                                Bawaan
                                {{ getSaldoBawaanEligible(selectedPegawai) }} -
                                Terpakai {{ getTerpakai(selectedPegawai) }} =
                            </span>
                            <span class="font-bold text-green-700">
                                {{ getSaldoAkhir(selectedPegawai) }} hari
                            </span>
                        </p>

                        <div>
                            <h5
                                class="font-bold text-gray-900 text-base mb-3.5 flex items-center gap-2"
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
                                        d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"
                                    ></path>
                                </svg>
                                Riwayat Pengajuan Cuti
                            </h5>

                            <div
                                v-if="
                                    !selectedPegawai.pengajuan_cuti ||
                                    selectedPegawai.pengajuan_cuti.length === 0
                                "
                                class="text-center py-6 bg-gray-50 rounded-xl border border-dashed border-gray-200 text-xs text-gray-400"
                            >
                                Belum ada catatan riwayat pengajuan cuti.
                            </div>

                            <div v-else class="space-y-2.5">
                                <div
                                    v-for="riwayat in selectedPegawai.pengajuan_cuti"
                                    :key="riwayat.id"
                                    @click="toggleExpand(riwayat.id)"
                                    class="bg-white p-4 rounded-xl border border-gray-200/80 text-sm flex flex-col gap-2.5 transition-colors duration-150 hover:shadow-sm cursor-pointer relative overflow-hidden group"
                                    :class="{
                                        'border-l-4 border-l-emerald-500 hover:bg-emerald-100':
                                            riwayat.status === 'disetujui',
                                        'border-l-4 border-l-amber-500 hover:bg-amber-100':
                                            riwayat.status?.includes(
                                                'menunggu',
                                            ),
                                        'border-l-4 border-l-rose-500 hover:bg-rose-100':
                                            riwayat.status === 'ditolak',
                                        'border-l-4 border-l-gray-400 hover:bg-gray-200':
                                            riwayat.status ===
                                            'dibatalkan_ditangguhkan',
                                        'ring-2 ring-green-500/20':
                                            expandedRiwayatId === riwayat.id,
                                    }"
                                >
                                    <div
                                        class="flex justify-between items-center"
                                    >
                                        <span
                                            class="font-bold text-gray-900 text-base group-hover:text-green-700 transition"
                                            >{{ riwayat.jenis_cuti }}</span
                                        >
                                        <div class="flex items-center gap-2">
                                            <span
                                                class="px-2.5 py-1 rounded-md font-bold text-xs tracking-wide uppercase"
                                                :class="{
                                                    'bg-emerald-50 text-emerald-700 border border-emerald-200':
                                                        riwayat.status ===
                                                        'disetujui',
                                                    'bg-amber-50 text-amber-700 border border-amber-200':
                                                        riwayat.status?.includes(
                                                            'menunggu',
                                                        ),
                                                    'bg-rose-50 text-rose-700 border border-rose-200':
                                                        riwayat.status ===
                                                        'ditolak',
                                                    'bg-gray-100 text-gray-700 border border-gray-200':
                                                        riwayat.status ===
                                                        'dibatalkan_ditangguhkan',
                                                }"
                                            >
                                                {{
                                                    getStatusLabel(
                                                        riwayat.status,
                                                    )
                                                }}
                                            </span>
                                            <svg
                                                class="w-4 h-4 text-gray-400 transition-transform duration-200 shrink-0"
                                                :class="{
                                                    'rotate-180 text-green-600':
                                                        expandedRiwayatId ===
                                                        riwayat.id,
                                                }"
                                                fill="none"
                                                stroke="currentColor"
                                                viewBox="0 0 24 24"
                                            >
                                                <path
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                    stroke-width="2"
                                                    d="M19 9l-7 7-7-7"
                                                />
                                            </svg>
                                        </div>
                                    </div>

                                    <div
                                        class="text-gray-600 flex items-center gap-2 font-medium"
                                    >
                                        <span
                                            >{{ riwayat.tanggal_mulai }} s/d
                                            {{ riwayat.tanggal_selesai }}</span
                                        >
                                        <span>&middot;</span>
                                        <span class="font-bold text-gray-900"
                                            >{{ getDurasi(riwayat) }} Hari</span
                                        >
                                    </div>

                                    <div
                                        v-if="riwayat.keterangan"
                                        class="text-gray-800 bg-gray-50/80 border border-gray-200/60 p-3 rounded-xl italic text-sm font-medium leading-relaxed"
                                    >
                                        "{{
                                            formatKeteranganRapi(
                                                riwayat.keterangan,
                                            )
                                        }}"
                                    </div>

                                    <div
                                        v-if="expandedRiwayatId === riwayat.id"
                                        @click.stop
                                        class="mt-1 pt-3 border-t-2 border-dashed border-green-200 bg-green-50/40 -mx-4 -mb-4 p-4 rounded-b-xl space-y-3 text-xs"
                                    >
                                        <div
                                            class="font-bold text-green-900 uppercase tracking-wider text-[11px] flex items-center gap-1.5"
                                        >
                                            <svg
                                                class="w-4 h-4 text-green-600"
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
                                            Rincian Lengkap Pengajuan
                                        </div>

                                        <div
                                            class="grid grid-cols-1 md:grid-cols-2 gap-3 bg-white p-3 rounded-lg border border-green-100"
                                        >
                                            <div>
                                                <span
                                                    class="block text-gray-400 text-[10px] uppercase font-semibold"
                                                    >Alamat Selama Cuti</span
                                                >
                                                <p
                                                    class="font-medium text-gray-800 mt-0.5"
                                                >
                                                    {{
                                                        riwayat.alamat_cuti ||
                                                        "-"
                                                    }}
                                                </p>
                                            </div>
                                            <div>
                                                <span
                                                    class="block text-gray-400 text-[10px] uppercase font-semibold"
                                                    >Nomor Kontak /
                                                    Telepon</span
                                                >
                                                <p
                                                    class="font-medium text-gray-800 mt-0.5"
                                                >
                                                    {{ riwayat.telepon || "-" }}
                                                </p>
                                            </div>
                                        </div>

                                        <div
                                            class="flex items-center justify-between bg-white p-3 rounded-lg border border-green-100 gap-3"
                                        >
                                            <div
                                                class="flex items-center gap-2"
                                            >
                                                <svg
                                                    class="w-5 h-5 text-red-500 shrink-0"
                                                    fill="none"
                                                    stroke="currentColor"
                                                    viewBox="0 0 24 24"
                                                >
                                                    <path
                                                        stroke-linecap="round"
                                                        stroke-linejoin="round"
                                                        stroke-width="2"
                                                        d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"
                                                    />
                                                </svg>
                                                <div>
                                                    <span
                                                        class="block text-gray-800 font-semibold"
                                                        >Lampiran
                                                        Pendukung</span
                                                    >
                                                    <span
                                                        class="text-gray-400 text-[10px]"
                                                        >{{
                                                            riwayat.lampiran
                                                                ? "File tersedia di sistem"
                                                                : "Tidak ada lampiran diunggah"
                                                        }}</span
                                                    >
                                                </div>
                                            </div>
                                            <a
                                                v-if="riwayat.lampiran"
                                                :href="riwayat.lampiran"
                                                target="_blank"
                                                class="px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white font-semibold rounded-md shadow-xs transition shrink-0"
                                            >
                                                Unduh / Lihat
                                            </a>
                                            <span
                                                v-else
                                                class="text-gray-400 italic text-[11px] shrink-0"
                                                >Tanpa Lampiran</span
                                            >
                                        </div>

                                        <!-- Approval chain: hanya dari backend -->
                                        <div
                                            v-if="
                                                getVisibleApprovalChain(
                                                    riwayat.approval_chain,
                                                ).length > 0
                                            "
                                            class="bg-white p-3 rounded-lg border border-green-100 space-y-1"
                                        >
                                            <div
                                                v-for="(
                                                    step, idx
                                                ) in getVisibleApprovalChain(
                                                    riwayat.approval_chain,
                                                )"
                                                :key="
                                                    step.level ??
                                                    `tangguh-${idx}`
                                                "
                                                class="flex items-center gap-1.5 text-gray-600"
                                            >
                                                <svg
                                                    v-if="
                                                        step.status ===
                                                        'tangguh'
                                                    "
                                                    class="w-3.5 h-3.5 text-orange-400 shrink-0"
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
                                                <svg
                                                    v-else
                                                    class="w-3.5 h-3.5 text-gray-400 shrink-0"
                                                    fill="none"
                                                    stroke="currentColor"
                                                    viewBox="0 0 24 24"
                                                >
                                                    <path
                                                        stroke-linecap="round"
                                                        stroke-linejoin="round"
                                                        stroke-width="2"
                                                        d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"
                                                    />
                                                </svg>
                                                <span class="flex-1">
                                                    <template v-if="step.level">
                                                        L{{ step.level }}
                                                        &middot;
                                                    </template>
                                                    <strong
                                                        class="text-gray-800"
                                                        >{{ step.nama }}</strong
                                                    >
                                                    <span
                                                        class="text-gray-400 text-[10px]"
                                                    >
                                                        ({{ step.label }})
                                                    </span>
                                                </span>
                                                <span
                                                    class="font-bold uppercase text-[11px]"
                                                    :class="
                                                        approvalStepClass(
                                                            step.status,
                                                        )
                                                    "
                                                >
                                                    {{
                                                        approvalStepLabel(
                                                            step.status,
                                                        )
                                                    }}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div
                        class="border-t px-6 py-4 bg-gray-50 rounded-b-2xl flex justify-end shrink-0"
                    >
                        <button
                            @click="closeModal"
                            type="button"
                            class="px-6 py-2.5 bg-gray-800 hover:bg-gray-700 text-white text-sm font-semibold rounded-xl transition shadow-sm cursor-pointer"
                        >
                            Tutup Rincian
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </MainLayout>
</template>