<script setup>
import MainLayout from "@/Layouts/MainLayout.vue";
import { Head, router } from "@inertiajs/vue3";
import { ref, watch, computed } from "vue";


// ================= PETA KELOMPOK PEGAWAI =================
// Sama seperti di halaman Approval Cuti: peta NIP → kelompok/subbagian.
// Kepala Biro tidak masuk kelompok mana pun; ia hanya tampil di "Semua
// Kelompok". Pegawai baru / pindah kelompok: cukup tambah atau pindahkan
// NIP di sini. `label` = nama lengkap (untuk chip), `singkat` = isi dropdown.
const DAFTAR_KELOMPOK = [
    { value: "tata_usaha", label: "Subbagian Tata Usaha", singkat: "Subbagian Tata Usaha" },
    { value: "kebijakan", label: "Kebijakan Pembangunan Pertanian", singkat: "Kebijakan Pertanian" },
    { value: "program_anggaran", label: "Program dan Anggaran Pembangunan Pertanian", singkat: "Program dan Anggaran" },
    { value: "kawasan", label: "Pengembangan Kawasan Pertanian", singkat: "Pengembangan Kawasan" },
    { value: "pemantauan", label: "Pemantauan, Evaluasi dan Pelaporan", singkat: "Pemantauan & Evaluasi" },
];


const NIP_PER_KELOMPOK = {
    tata_usaha: [
        "197909182006041018", "198906012019022002", "197207191995031002",
        "197303112002122002", "199906262025052004", "198001292003121001",
        "198710102015031001", "200101082025052004", "198510292025211032",
        "197612062006041021", "198111132025211037", "198502142025211043",
        "199412182018012001", "196907201998031001", "198611112019022001",
        "196907042007011001", "198302072002122002", "198806042011012020",
        "198804092025212047", "197902132009011004", "198510112025212037",
    ],
    kebijakan: [
        "196109291986031003", "197810152009121003", "198109292009011006",
        "198606252011012014", "199203212025051001", "196912181996021001",
        "197804152002122002", "199108082018012001", "199204022025051004",
        "197206082025212011", "196904051999031001", "197011181998032001",
        "198306182009122004", "198306302009122004", "198701062009121006",
        "198302102009121003",
    ],
    program_anggaran: [
        "198405122011011008", "198904132018011001", "198302282009121003",
        "198307032018012001", "197807072025211042", "198301282018012001",
        "198608082011011023", "198408222009012007", "197901032025212018",
        "197401101997031002", "199909302022081001", "199210122015031004",
        "198010202005012001", "198810222025211050", "199502202025052002",
        "199401312020121004",
    ],
    kawasan: [
        "198103262011011001", "198606152011012023", "198111072011011007",
        "198312012015031001", "197112071994032005", "198106202025212032",
        "197705202009121001", "196901121989032001", "199309152025051002",
        "198904282020122003", "199010102025051001", "198603282005012002",
        "196707151994032002",
    ],
    pemantauan: [
        "198404292018012001", "197602022009121003", "198208022011011014",
        "197807072025211043", "198012222002121001", "197712012005012001",
        "199111122025212058", "198302012011012013", "198411242009121002",
        "196809101994031003", "198607132011012019", "197205152025212019",
        "197207011998032001", "196907111997032001", "198402192011012015",
        "198005062011011009", "199804172025052002", "198602182009011005",
    ],
};


const normalisasiNip = (nip) => String(nip ?? "").replace(/\D/g, "");


const kelompokPerNip = new Map();
Object.entries(NIP_PER_KELOMPOK).forEach(([kunci, daftarNip]) => {
    daftarNip.forEach((nip) => kelompokPerNip.set(nip, kunci));
});


const kelompokDariNip = (nip) =>
    kelompokPerNip.get(normalisasiNip(nip)) ?? null;
// ================= END PETA KELOMPOK PEGAWAI =================


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


// Kamus label status lengkap, dipakai untuk teks deskriptif di bawah badge
// (sama filosofinya dengan statusLabels di halaman Approval Cuti).
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


// Label singkat untuk badge (gaya sama dengan getStatusBadgeLabelSingkat di
// halaman Approval Cuti).
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


// Kelas warna badge status. Palet diselaraskan dengan halaman Approval Cuti
// (emerald/amber/rose/gray) supaya kedua halaman terasa satu sistem visual.
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


// ===================== FITUR SEARCH & FILTER =====================
const search = ref(props.filters?.search || "");
// Filter Kelompok: menyaring pengajuan berdasarkan kelompok/subbagian
// pegawai (peta NIP → kelompok ada di blok PETA KELOMPOK PEGAWAI di atas).
const kelompok = ref(props.filters?.kelompok || "");


const buildParams = () => {
    const params = { search: search.value };
    if (kelompok.value) params.kelompok = kelompok.value;
    return params;
};


watch(
    [search, kelompok],
    debounce(() => {
        router.get(
            route("atasan.penangguhan"), // sesuaikan nama route jika berbeda
            buildParams(),
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    }, 300),
);


// Cadangan di sisi tampilan: baris pada halaman yang sedang dimuat disaring
// lagi berdasarkan NIP. Kalau backend sudah menyaring lewat ?kelompok=,
// hasilnya sama saja (aman ditumpuk) — sama seperti di Approval Cuti.
const daftarCutiTampil = computed(() => {
    const semua = props.daftarCuti ?? [];
    if (!kelompok.value) return semua;
    return semua.filter(
        (item) => kelompokDariNip(item.pegawai?.nip) === kelompok.value,
    );
});


const labelKelompokAktif = computed(
    () => DAFTAR_KELOMPOK.find((k) => k.value === kelompok.value)?.label ?? "",
);


const adaFilterAktif = computed(() => !!search.value || !!kelompok.value);


const resetFilter = () => {
    search.value = "";
    kelompok.value = "";
};
// ===================== END FITUR SEARCH & FILTER =====================


// --- STATE UNTUK MODAL PENANGGUHAN CUTI (fitur asli, dipertahankan) ---
const suspendData = ref({
    show: false,
    id: null,
    namaPegawai: "",
    alasan: "",
});


// Error validasi inline (menggantikan alert() browser)
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
    // Validasi tanpa alert() browser — tampilkan error di dalam modal
    if (!suspendData.value.alasan.trim()) {
        suspendError.value = "Mohon isi alasan penangguhan terlebih dahulu.";
        return;
    }


    suspendError.value = "";


    router.post(
        route("atasan.penangguhan.process", suspendData.value.id),
        {
            alasan: suspendData.value.alasan,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                // Auto-close popup setelah aksi sukses
                closeSuspendModal();
                closeDetailModal();
            },
        },
    );
};


// --- MODAL DETAIL (baru, mengikuti gaya Modal Detail di Approval Cuti) ---
const detailModal = ref({ show: false, data: null });


const bukaDetailModal = (item) => {
    detailModal.value.data = item;
    detailModal.value.show = true;
};


const closeDetailModal = () => {
    detailModal.value.show = false;
    detailModal.value.data = null;
};


// Membuka modal tangguhkan langsung dari dalam modal detail
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
                        v-model="search"
                        type="text"
                        placeholder="Cari nama, NIP..."
                        class="pl-10 border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-lg text-sm w-full"
                    />
                </div>


                <select
                    v-model="kelompok"
                    class="border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-lg text-sm text-gray-600 w-full sm:w-auto shrink-0"
                >
                    <option value="">Semua Kelompok</option>
                    <option
                        v-for="k in DAFTAR_KELOMPOK"
                        :key="k.value"
                        :value="k.value"
                        :title="k.label"
                    >
                        {{ k.singkat }}
                    </option>
                </select>
            </div>


            <!-- Penanda filter kelompok yang sedang aktif -->
            <div v-if="kelompok" class="flex items-center gap-2 mb-4 text-xs">
                <span
                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-green-50 text-green-700 border border-green-200 font-semibold"
                >
                    {{ labelKelompokAktif }}
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
                    >{{ daftarCutiTampil.length }} pengajuan pada halaman
                    ini</span
                >
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
                                Status Saat Ini
                            </th>
                            <th
                                scope="col"
                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                            >
                                Alasan Cuti
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
                            v-for="item in daftarCutiTampil"
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
                                    <template
                                        v-if="item.pegawai?.biro || item.unit"
                                    >
                                        <span
                                            v-if="item.pegawai?.jabatan"
                                            class="text-gray-300"
                                            >•</span
                                        >
                                        <span
                                            class="text-[11px] text-gray-500 bg-gray-100 px-2 py-0.5 rounded-md"
                                        >
                                            {{
                                                item.pegawai?.biro || item.unit
                                            }}
                                        </span>
                                    </template>
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
                                class="px-6 py-4 text-sm text-gray-500 max-w-xs truncate"
                                :title="item.keterangan || '-'"
                            >
                                {{ item.keterangan || "-" }}
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
                        <tr v-if="daftarCutiTampil.length === 0">
                            <td
                                colspan="7"
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
        </div>
    </MainLayout>


    <!-- MODAL DETAIL: mengikuti struktur & gaya Modal Detail di halaman
         Approval Cuti, disesuaikan dengan data yang tersedia di halaman
         Penangguhan Cuti. Field yang datanya belum dikirim backend akan
         tampil "-" alih-alih error. -->
    <Teleport to="body">
        <div
            v-if="detailModal.show"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm p-4"
            @click.self="closeDetailModal"
        >
            <div
                class="bg-white rounded-2xl shadow-xl w-full max-w-lg max-h-[90vh] flex flex-col overflow-hidden mx-4"
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
                            Biro / Unit
                        </div>
                        <div class="col-span-8 text-slate-800 font-semibold">
                            {{
                                detailModal.data.pegawai?.biro ||
                                detailModal.data.unit ||
                                "-"
                            }}
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
                            {{
                                detailModal.data.pegawai?.no_telepon ??
                                detailModal.data.pegawai?.telepon ??
                                detailModal.data.pegawai?.no_hp ??
                                detailModal.data.pegawai?.phone ??
                                "-"
                            }}
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


                    <div
                        class="rounded-xl p-4 border bg-amber-50 border-amber-200"
                    >
                        <h4
                            class="text-xs font-bold uppercase tracking-wider mb-1.5 flex items-center gap-1.5 text-amber-700"
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
                                    d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                                />
                            </svg>
                            Catatan Penangguhan
                        </h4>
                        <p class="text-sm text-amber-800/90 leading-relaxed">
                            Menangguhkan cuti ini akan mengembalikan saldo
                            cuti yang sebelumnya sudah dipotong (jika
                            statusnya sudah Disetujui), dan menghentikan
                            proses persetujuan bila masih berjalan (L1-L4).
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


    <!-- POP-UP MODAL PENANGGUHAN DENGAN TELEPORT (fitur asli, dipertahankan
         utuh — hanya dipanggil juga dari tombol di dalam Modal Detail). -->
    <Teleport to="body">
        <div
            v-if="suspendData.show"
            class="fixed inset-0 z-[9999] flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4"
            @click.self="closeSuspendModal"
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
                        class="w-full text-sm border rounded-xl focus:ring-orange-500 focus:border-orange-500"
                        :class="
                            suspendError
                                ? 'border-red-400 focus:ring-red-500 focus:border-red-500'
                                : 'border-slate-200'
                        "
                        placeholder="Contoh: Perintah tugas mendadak untuk persiapan dinas luar kota."
                        @input="suspendError = ''"
                    ></textarea>
                    <!-- Pesan error validasi inline (menggantikan alert browser) -->
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
</template>

