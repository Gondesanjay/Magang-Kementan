<script setup>
import { computed, ref, watch, onErrorCaptured } from "vue";
import { useForm, Head, router } from "@inertiajs/vue3";
import MainLayout from "@/Layouts/MainLayout.vue";


// Definisikan props dengan nilai default agar tidak error jika data dari backend kosong
const props = defineProps({
    pegawai: {
        type: Array,
        default: () => [],
    },
    // Tahun berjalan (dikirim dari AdminController@kelolaPegawai), dipakai
    // hanya untuk label "Saldo Cuti Tahun {{ tahunBerjalan }}" di modal.
    tahunBerjalan: {
        type: [Number, String],
        default: () => new Date().getFullYear(),
    },
});


// ---> SISTEM PENDETEKSI ERROR OTOMATIS <---
const vueError = ref(null);
onErrorCaptured((err) => {
    vueError.value = err.toString();
    console.error("VUE ERROR TERTANGKAP:", err);
    return false; // Mencegah error merusak seluruh halaman
});


const formatRole = (roleId) => {
    const roles = {
        1: "Karyawan",
        2: "Atasan L1",
        3: "Atasan L2",
        4: "Atasan L3",
        5: "Admin HR",
        6: "Atasan L4",
    };
    return roles[roleId] || "Tidak Diketahui";
};


// ===================== FITUR SEARCH + FILTER + PAGINATION =====================
const searchNama = ref("");
const selectedDepartemen = ref("");
const currentPage = ref(1);
const itemsPerPage = 10;


const filteredPegawai = computed(() => {
    const keyword = searchNama.value.trim().toLowerCase();


    return props.pegawai.filter((item) => {
        const nama = (item?.nama || "").toLowerCase();
        const nip = String(item?.nip || "").toLowerCase();
        const departemen = item?.departemen || "";


        return (
            (!keyword || nama.includes(keyword) || nip.includes(keyword)) &&
            (!selectedDepartemen.value ||
                departemen === selectedDepartemen.value)
        );
    });
});


const daftarDepartemen = computed(() =>
    [
        ...new Set(
            props.pegawai.map((item) => item?.departemen).filter(Boolean),
        ),
    ].sort((a, b) => a.localeCompare(b)),
);


const totalPages = computed(() =>
    Math.max(1, Math.ceil(filteredPegawai.value.length / itemsPerPage)),
);


const paginatedPegawai = computed(() => {
    const start = (currentPage.value - 1) * itemsPerPage;
    return filteredPegawai.value.slice(start, start + itemsPerPage);
});


watch([searchNama, selectedDepartemen], () => {
    currentPage.value = 1;
});


const goToPage = (page) => {
    currentPage.value = Math.min(Math.max(page, 1), totalPages.value);
};
// ===================== END SEARCH + FILTER + PAGINATION =====================


// State reaktivitas modal
const isModalOpen = ref(false);
const isEditMode = ref(false);
const currentEditId = ref(null);


// Default saldo cuti untuk pegawai baru (dipakai saat openAddModal)
const DEFAULT_KUOTA_TAHUNAN = 12;


const form = useForm({
    nip: "",
    nama: "",
    departemen: "", // Divisi/Departemen
    divisi: "", // Tim Kerja
    jabatan: "",
    kelompok_substansi: "", // Field baru ditambahkan
    role_id: 1,
    no_telp: "",
    alamat: "",
    kuota_tahunan: DEFAULT_KUOTA_TAHUNAN,
    sisa: DEFAULT_KUOTA_TAHUNAN,
    carry_forward_normal: 0,
});


// Fungsi buka modal Tambah
const openAddModal = () => {
    isEditMode.value = false;
    currentEditId.value = null;
    form.reset();
    isModalOpen.value = true;
};


// Fungsi buka modal Edit
const openEditModal = (item) => {
    isEditMode.value = true;
    currentEditId.value = item.id;
    form.nip = item.nip || "";
    form.nama = item.nama || "";
    form.departemen = item.departemen || "";
    form.divisi = item.divisi || "";
    form.jabatan = item.jabatan || "";
    form.kelompok_substansi = item.kelompok_substansi || "";
    form.role_id = item.role_id || 1;
    form.no_telp = item.no_telp || "";
    form.alamat = item.alamat || "";


    // Ambil saldo cuti tahun berjalan pegawai ini
    const saldoTahunIni =
        item.saldo_cuti && item.saldo_cuti.length > 0
            ? item.saldo_cuti[0]
            : null;


    form.kuota_tahunan = saldoTahunIni?.kuota_tahunan ?? DEFAULT_KUOTA_TAHUNAN;
    form.sisa = saldoTahunIni?.sisa ?? DEFAULT_KUOTA_TAHUNAN;
    form.carry_forward_normal = saldoTahunIni?.carry_forward_normal ?? 0;


    isModalOpen.value = true;
};


// Fungsi simpan (Store / Update)
const submitForm = () => {
    if (isEditMode.value) {
        form.put(route("admin.pegawai.update", currentEditId.value), {
            onSuccess: () => {
                isModalOpen.value = false;
                form.reset();
            },
        });
    } else {
        form.post(route("admin.pegawai.store"), {
            onSuccess: () => {
                isModalOpen.value = false;
                form.reset();
            },
        });
    }
};


// ---> STATE UNTUK TOAST NOTIFIKASI SUKSES RESET PASSWORD <---
const showResetToast = ref(false);
const resetToastNama = ref("");
let resetToastTimer = null;


// ========================================== //
// ---> FITUR: RESET PASSWORD PEGAWAI <---
// ========================================== //
const showModalReset = ref(false);
const pegawaiYangAkanDireset = ref(null);


const konfirmasiReset = (item) => {
    pegawaiYangAkanDireset.value = item;
    showModalReset.value = true;
};


const batalReset = () => {
    showModalReset.value = false;
    pegawaiYangAkanDireset.value = null;
};


const eksekusiReset = () => {
    if (!pegawaiYangAkanDireset.value) return;


    const item = pegawaiYangAkanDireset.value;


    router.post(
        route("admin.pegawai.reset-password", item.id),
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                showModalReset.value = false;
                pegawaiYangAkanDireset.value = null;


                resetToastNama.value = item.nama;
                showResetToast.value = true;
                clearTimeout(resetToastTimer);
                resetToastTimer = setTimeout(() => {
                    showResetToast.value = false;
                }, 3500);
            },
        },
    );
};


// ========================================== //
// ---> FITUR: HAPUS DATA PEGAWAI (SOFT DELETE) <---
// ========================================== //
const showModalHapus = ref(false);
const pegawaiYangAkanDihapus = ref(null);


const konfirmasiHapus = (item) => {
    pegawaiYangAkanDihapus.value = item;
    showModalHapus.value = true;
};


const batalHapus = () => {
    showModalHapus.value = false;
    pegawaiYangAkanDihapus.value = null;
};


const eksekusiHapus = () => {
    if (!pegawaiYangAkanDihapus.value) return;


    router.delete(
        route("admin.pegawai.destroy", pegawaiYangAkanDihapus.value.id),
        {
            preserveScroll: true,
            onSuccess: () => {
                showModalHapus.value = false;
                pegawaiYangAkanDihapus.value = null;
            },
        },
    );
};


// ========================================== //
// ---> FITUR: IMPOR DATA PEGAWAI <---
// ========================================== //
const isImportModalOpen = ref(false);


const importForm = useForm({
    file: null,
});


const importFileInput = ref(null);


const openImportModal = () => {
    importForm.reset();
    importForm.clearErrors();
    isImportModalOpen.value = true;
};


const closeImportModal = () => {
    isImportModalOpen.value = false;
    importForm.reset();
    importForm.clearErrors();
    if (importFileInput.value) {
        importFileInput.value.value = null;
    }
};


const handleImportFileChange = (event) => {
    importForm.file = event.target.files[0] ?? null;
};


const submitImportForm = () => {
    if (!importForm.file) return;


    importForm.post(route("admin.pegawai.import"), {
        forceFormData: true,
        onSuccess: () => {
            closeImportModal();
        },
    });
};
</script>


<template>
    <Head title="Kelola Pegawai" />


    <MainLayout>
        <div>
            <!-- KOTAK MERAH PENDETEKSI ERROR -->
            <div
                v-if="vueError"
                class="bg-red-600 text-white p-6 rounded-lg mb-6 shadow-xl font-mono text-sm border-4 border-red-800"
            >
                <h2 class="text-xl font-black mb-2">
                    🚨 VUE RENDER ERROR TERTANGKAP!
                </h2>
                <p>
                    Pesan Error: <strong>{{ vueError }}</strong>
                </p>
                <p class="mt-2 text-xs opacity-80">
                    Silakan kirimkan tulisan error di atas kepada saya agar kita
                    perbaiki akarnya!
                </p>
            </div>


            <!-- KONTEN UTAMA -->
            <div
                v-else
                class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 relative z-10"
            >
                <div
                    class="flex justify-between items-center mb-6 border-b pb-4"
                >
                    <h2 class="text-2xl font-bold text-gray-800">
                        Master Data Pegawai
                    </h2>


                    <!-- Kontainer tombol aksi: Impor Data + Tambah Pegawai -->
                    <div class="flex flex-wrap items-center justify-end gap-3">
                        <button
                            type="button"
                            @click="openImportModal"
                            class="inline-flex items-center gap-2 px-4 py-2 bg-emerald-600 border border-transparent rounded-lg font-semibold text-sm text-white hover:bg-emerald-700 transition ease-in-out duration-150 shadow-sm cursor-pointer"
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
                                    d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"
                                />
                            </svg>
                            Impor Data
                        </button>


                        <button
                            type="button"
                            @click="openAddModal"
                            class="px-5 py-2.5 bg-indigo-600 text-white rounded-md text-sm font-extrabold hover:bg-indigo-700 cursor-pointer shadow-lg hover:scale-105 transition-all"
                        >
                            + Tambah Pegawai
                        </button>
                    </div>
                </div>


                <!-- FILTER + SEARCH + TOTAL -->
                <div class="mb-4 flex flex-wrap items-center gap-3">
                    <div class="relative w-full max-w-md">
                        <input
                            v-model="searchNama"
                            type="text"
                            placeholder="Cari nama atau NIP..."
                            aria-label="Cari nama atau NIP"
                            class="w-full rounded-md border-gray-300 pr-10 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        />
                        <button
                            v-if="searchNama"
                            type="button"
                            @click="searchNama = ''"
                            aria-label="Hapus pencarian"
                            class="absolute inset-y-0 right-0 px-3 text-gray-400 hover:text-gray-700 cursor-pointer"
                        >
                            &times;
                        </button>
                    </div>


                    <select
                        v-model="selectedDepartemen"
                        aria-label="Filter departemen"
                        class="w-full max-w-xs rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    >
                        <option value="">Semua Departemen</option>
                        <option
                            v-for="departemen in daftarDepartemen"
                            :key="departemen"
                            :value="departemen"
                        >
                            {{ departemen }}
                        </option>
                    </select>


                    <div
                        class="inline-flex h-12 min-w-[170px] items-center justify-center gap-3 rounded-lg border border-indigo-100 bg-indigo-50 px-4 text-indigo-900 shadow-sm"
                    >
                        <span
                            class="flex h-8 w-8 items-center justify-center rounded-full bg-indigo-600 text-sm font-bold text-white"
                        >
                            {{ pegawai.length }}
                        </span>
                        <span class="text-sm font-semibold whitespace-nowrap">
                            Total Pegawai
                        </span>
                    </div>
                </div>


                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"
                                >
                                    NIP
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"
                                >
                                    Nama Pegawai
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"
                                >
                                    Divisi/Dept.
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"
                                >
                                    Tim Kerja
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"
                                >
                                    Jabatan
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"
                                >
                                    Hak Akses
                                </th>
                                <th
                                    class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"
                                >
                                    Aksi
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <tr v-if="!pegawai || pegawai.length === 0">
                                <td
                                    colspan="7"
                                    class="px-6 py-12 text-center text-gray-500 font-bold bg-gray-50/50"
                                >
                                    <span class="block text-xl mb-2">📭</span>
                                    Data Pegawai Kosong atau Belum Dimasukkan
                                </td>
                            </tr>
                            <tr v-else-if="filteredPegawai.length === 0">
                                <td
                                    colspan="7"
                                    class="px-6 py-12 text-center text-gray-500 font-bold bg-gray-50/50"
                                >
                                    Tidak ada pegawai yang sesuai dengan filter
                                    saat ini
                                </td>
                            </tr>
                            <tr
                                v-else
                                v-for="item in paginatedPegawai"
                                :key="item.id"
                                class="hover:bg-gray-50"
                            >
                                <td
                                    class="px-4 py-3 whitespace-nowrap text-sm text-gray-900"
                                >
                                    {{ item?.nip || "-" }}
                                </td>
                                <td
                                    class="px-4 py-3 whitespace-nowrap text-sm text-gray-900 font-medium"
                                >
                                    <div
                                        class="max-w-[220px] truncate"
                                        :title="item?.nama || '-'"
                                    >
                                        {{ item?.nama || "-" }}
                                    </div>
                                </td>
                                <td
                                    class="px-4 py-3 whitespace-nowrap text-sm text-gray-500"
                                >
                                    {{ item?.departemen || "-" }}
                                </td>
                                <td
                                    class="px-4 py-3 whitespace-nowrap text-sm text-gray-500"
                                >
                                    {{ item?.divisi || "-" }}
                                </td>
                                <td
                                    class="px-4 py-3 whitespace-nowrap text-sm text-gray-500"
                                >
                                    <div
                                        class="max-w-[220px] truncate"
                                        :title="item?.jabatan || '-'"
                                    >
                                        {{ item?.jabatan || "-" }}
                                    </div>
                                </td>
                                <td
                                    class="px-4 py-3 whitespace-nowrap text-sm text-gray-500"
                                >
                                    {{ formatRole(item?.role_id) }}
                                </td>
                                <td
                                    class="px-4 py-3 whitespace-nowrap text-sm font-medium"
                                >
                                    <div
                                        class="flex items-center justify-center gap-2"
                                    >
                                        <!-- Edit -->
                                        <button
                                            type="button"
                                            @click="openEditModal(item)"
                                            title="Edit Pegawai"
                                            class="p-2 bg-blue-50 text-blue-600 rounded-lg hover:bg-blue-100 hover:text-blue-700 transition-colors shadow-sm cursor-pointer"
                                        >
                                            <svg
                                                xmlns="http://www.w3.org/2000/svg"
                                                fill="none"
                                                viewBox="0 0 24 24"
                                                stroke-width="1.5"
                                                stroke="currentColor"
                                                class="w-4 h-4"
                                            >
                                                <path
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                    d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L6.832 19.82a4.5 4.5 0 01-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 011.13-1.897L16.863 4.487zm0 0L19.5 7.125"
                                                />
                                            </svg>
                                        </button>


                                        <!-- Reset Password -->
                                        <button
                                            type="button"
                                            @click="konfirmasiReset(item)"
                                            title="Reset Password"
                                            class="p-2 bg-orange-50 text-orange-600 rounded-lg hover:bg-orange-100 hover:text-orange-700 transition-colors shadow-sm cursor-pointer"
                                        >
                                            <svg
                                                xmlns="http://www.w3.org/2000/svg"
                                                fill="none"
                                                viewBox="0 0 24 24"
                                                stroke-width="1.5"
                                                stroke="currentColor"
                                                class="w-4 h-4"
                                            >
                                                <path
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                    d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z"
                                                />
                                            </svg>
                                        </button>


                                        <!-- Hapus -->
                                        <button
                                            type="button"
                                            @click="konfirmasiHapus(item)"
                                            title="Hapus Pegawai"
                                            class="p-2 bg-red-50 text-red-600 rounded-lg hover:bg-red-100 hover:text-red-700 transition-colors shadow-sm cursor-pointer"
                                        >
                                            <svg
                                                xmlns="http://www.w3.org/2000/svg"
                                                fill="none"
                                                viewBox="0 0 24 24"
                                                stroke-width="1.5"
                                                stroke="currentColor"
                                                class="w-4 h-4"
                                            >
                                                <path
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                    d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"
                                                />
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>


                <!-- PAGINATION -->
                <div
                    v-if="filteredPegawai.length > 0"
                    class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 pt-4"
                >
                    <span class="text-sm text-gray-500">
                        Halaman {{ currentPage }} dari {{ totalPages }} ({{
                            filteredPegawai.length
                        }}
                        data)
                    </span>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            @click="goToPage(currentPage - 1)"
                            :disabled="currentPage === 1"
                            class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            Sebelumnya
                        </button>
                        <button
                            type="button"
                            @click="goToPage(currentPage + 1)"
                            :disabled="currentPage === totalPages"
                            class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            Berikutnya
                        </button>
                    </div>
                </div>
            </div>


            <!-- ========================================== -->
            <!-- MODAL FORM EDIT / TAMBAH PEGAWAI           -->
            <!-- ========================================== -->
            <Teleport to="body">
                <div
                    v-if="isModalOpen"
                    class="fixed inset-0 flex items-center justify-center p-4"
                    style="z-index: 999999"
                >
                    <div
                        class="absolute inset-0 bg-black/60 backdrop-blur-sm"
                        @click="isModalOpen = false"
                    ></div>


                    <div
                        class="relative bg-white p-6 rounded-xl w-full max-w-2xl shadow-2xl max-h-[90vh] overflow-y-auto z-10"
                    >
                        <div
                            class="flex justify-between items-center mb-5 border-b pb-3"
                        >
                            <h3 class="text-lg font-bold text-gray-800">
                                {{
                                    isEditMode
                                        ? "Edit Data Pegawai"
                                        : "Tambah Data Pegawai Baru"
                                }}
                            </h3>
                            <button
                                type="button"
                                @click="isModalOpen = false"
                                class="text-gray-400 hover:text-red-500 font-bold text-2xl cursor-pointer outline-none"
                            >
                                &times;
                            </button>
                        </div>


                        <form @submit.prevent="submitForm">
                            <p
                                class="text-xs font-bold uppercase tracking-wide text-gray-400 mb-3"
                            >
                                Identitas & Kontak
                            </p>


                            <div class="grid grid-cols-2 gap-x-4 mb-3">
                                <div>
                                    <label
                                        class="block text-sm font-medium text-gray-700"
                                        >NIP</label
                                    >
                                    <input
                                        type="text"
                                        v-model="form.nip"
                                        required
                                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    />
                                </div>
                                <div>
                                    <label
                                        class="block text-sm font-medium text-gray-700"
                                        >Nama Lengkap & Gelar</label
                                    >
                                    <input
                                        type="text"
                                        v-model="form.nama"
                                        required
                                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    />
                                </div>
                            </div>


                            <div class="grid grid-cols-2 gap-x-4 mb-3">
                                <div>
                                    <label
                                        class="block text-sm font-medium text-gray-700"
                                        >No Telepon</label
                                    >
                                    <input
                                        type="text"
                                        v-model="form.no_telp"
                                        placeholder="08xxxxxxxxxx"
                                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    />
                                </div>
                                <div>
                                    <label
                                        class="block text-sm font-medium text-gray-700"
                                        >Hak Akses (Role)</label
                                    >
                                    <select
                                        v-model="form.role_id"
                                        required
                                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    >
                                        <option value="1">
                                            Karyawan / Staf
                                        </option>
                                        <option value="2">
                                            Atasan L1 (Ketua Tim Kerja)
                                        </option>
                                        <option value="3">
                                            Atasan L2 (Ketua Kelompok)
                                        </option>
                                        <option value="4">
                                            Atasan L3 (Kasubag TU)
                                        </option>
                                        <option value="5">Admin HR</option>
                                        <option value="6">
                                            Atasan L4 (Kepala Biro)
                                        </option>
                                    </select>
                                </div>
                            </div>


                            <div class="mb-5">
                                <label
                                    class="block text-sm font-medium text-gray-700"
                                    >Alamat</label
                                >
                                <textarea
                                    v-model="form.alamat"
                                    rows="2"
                                    class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                ></textarea>
                            </div>


                            <div class="border-t border-gray-100 pt-4 mb-5">
                                <p
                                    class="text-xs font-bold uppercase tracking-wide text-gray-400 mb-3"
                                >
                                    Posisi & Jabatan
                                </p>


                                <div class="grid grid-cols-2 gap-x-4 mb-3">
                                    <div>
                                        <label
                                            class="block text-sm font-medium text-gray-700"
                                            >Divisi/Departemen</label
                                        >
                                        <input
                                            type="text"
                                            v-model="form.departemen"
                                            required
                                            class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        />
                                    </div>
                                    <div>
                                        <label
                                            class="block text-sm font-medium text-gray-700"
                                            >Kelompok Substansi</label
                                        >
                                        <input
                                            type="text"
                                            v-model="form.kelompok_substansi"
                                            class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        />
                                    </div>
                                </div>


                                <div class="grid grid-cols-2 gap-x-4">
                                    <div>
                                        <label
                                            class="block text-sm font-medium text-gray-700"
                                            >Jabatan</label
                                        >
                                        <input
                                            type="text"
                                            v-model="form.jabatan"
                                            required
                                            class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        />
                                    </div>
                                    <div>
                                        <label
                                            class="block text-sm font-medium text-gray-700"
                                            >Tim Kerja</label
                                        >
                                        <input
                                            type="text"
                                            v-model="form.divisi"
                                            class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        />
                                    </div>
                                </div>
                            </div>


                            <div class="border-t border-gray-100 pt-4 mb-4">
                                <p
                                    class="text-xs font-bold uppercase tracking-wide text-gray-400 mb-3"
                                >
                                    Saldo Cuti Tahun {{ tahunBerjalan }}
                                </p>


                                <div class="grid grid-cols-3 gap-x-3">
                                    <div>
                                        <label
                                            class="block text-sm font-medium text-gray-700"
                                            >Jatah Cuti</label
                                        >
                                        <input
                                            type="number"
                                            min="0"
                                            v-model="form.kuota_tahunan"
                                            class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        />
                                    </div>
                                    <div>
                                        <label
                                            class="block text-sm font-medium text-gray-700"
                                            >Sisa Tahun Ini</label
                                        >
                                        <input
                                            type="number"
                                            min="0"
                                            v-model="form.sisa"
                                            class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        />
                                    </div>
                                    <div>
                                        <label
                                            class="block text-sm font-medium text-gray-700"
                                            >Sisa Tahun Kemarin</label
                                        >
                                        <input
                                            type="number"
                                            min="0"
                                            v-model="form.carry_forward_normal"
                                            class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        />
                                    </div>
                                </div>
                            </div>


                            <div
                                class="flex justify-end space-x-2 mt-6 pt-4 border-t border-gray-100"
                            >
                                <button
                                    type="button"
                                    @click="isModalOpen = false"
                                    class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 cursor-pointer transition"
                                >
                                    Batal
                                </button>
                                <button
                                    type="submit"
                                    class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 cursor-pointer transition"
                                    :disabled="form.processing"
                                >
                                    {{
                                        isEditMode
                                            ? "Simpan Perubahan"
                                            : "Simpan Data"
                                    }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </Teleport>


            <!-- ========================================== -->
            <!-- MODAL IMPOR DATA PEGAWAI                   -->
            <!-- ========================================== -->
            <Teleport to="body">
                <div
                    v-if="isImportModalOpen"
                    class="fixed inset-0 flex items-center justify-center p-4"
                    style="z-index: 999999"
                >
                    <div
                        class="absolute inset-0 bg-black/60 backdrop-blur-sm"
                        @click="closeImportModal"
                    ></div>


                    <div
                        class="relative bg-white p-6 rounded-xl w-full max-w-md shadow-2xl max-h-[90vh] overflow-y-auto z-10"
                    >
                        <div
                            class="flex justify-between items-center mb-5 border-b pb-3"
                        >
                            <h3 class="text-lg font-bold text-gray-800">
                                Impor Data Pegawai
                            </h3>
                            <button
                                type="button"
                                @click="closeImportModal"
                                class="text-gray-400 hover:text-red-500 font-bold text-2xl cursor-pointer outline-none"
                            >
                                &times;
                            </button>
                        </div>


                        <form @submit.prevent="submitImportForm">
                            <p class="text-sm text-gray-500 mb-4">
                                Unggah file Excel (.xlsx) atau CSV berisi data
                                pegawai untuk ditambahkan secara massal.
                            </p>


                            <div class="mb-2">
                                <label
                                    class="block text-sm font-medium text-gray-700 mb-1"
                                    >File Excel / CSV</label
                                >
                                <input
                                    ref="importFileInput"
                                    type="file"
                                    accept=".xlsx,.xls,.csv"
                                    @change="handleImportFileChange"
                                    class="block w-full text-sm text-gray-600 border border-gray-300 rounded-md cursor-pointer focus:outline-none file:mr-3 file:py-2 file:px-4 file:border-0 file:bg-emerald-50 file:text-emerald-700 file:font-semibold hover:file:bg-emerald-100"
                                />
                            </div>


                            <p
                                v-if="importForm.errors.file"
                                class="text-sm text-red-600 mb-2"
                            >
                                {{ importForm.errors.file }}
                            </p>


                            <p class="text-xs text-gray-400 mb-5">
                                Pastikan kolom pada file mengikuti format
                                template yang sudah ditentukan (NIP, Nama,
                                Divisi/Departemen, Jabatan, Role, dsb).
                            </p>


                            <div
                                class="flex justify-end space-x-2 pt-4 border-t border-gray-100"
                            >
                                <button
                                    type="button"
                                    @click="closeImportModal"
                                    class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 cursor-pointer transition"
                                >
                                    Batal
                                </button>
                                <button
                                    type="submit"
                                    class="px-4 py-2 bg-emerald-600 text-white rounded-md hover:bg-emerald-700 cursor-pointer transition disabled:opacity-50 disabled:cursor-not-allowed"
                                    :disabled="
                                        importForm.processing ||
                                        !importForm.file
                                    "
                                >
                                    {{
                                        importForm.processing
                                            ? "Mengunggah..."
                                            : "Impor Sekarang"
                                    }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </Teleport>


            <!-- TOAST NOTIFIKASI RESET PASSWORD -->
            <Teleport to="body">
                <Transition
                    enter-active-class="transition ease-out duration-200"
                    enter-from-class="opacity-0 translate-y-2"
                    enter-to-class="opacity-100 translate-y-0"
                    leave-active-class="transition ease-in duration-150"
                    leave-from-class="opacity-100 translate-y-0"
                    leave-to-class="opacity-0 translate-y-2"
                >
                    <div
                        v-if="showResetToast"
                        class="fixed bottom-6 right-6 flex items-center gap-3 bg-white border border-green-200 shadow-2xl rounded-lg px-4 py-3 max-w-sm"
                        style="z-index: 999999"
                    >
                        <span
                            class="flex-shrink-0 flex items-center justify-center w-8 h-8 rounded-full bg-green-100"
                        >
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-5 w-5 text-green-600"
                                viewBox="0 0 20 20"
                                fill="currentColor"
                            >
                                <path
                                    fill-rule="evenodd"
                                    d="M16.704 5.29a1 1 0 010 1.415l-7.5 7.5a1 1 0 01-1.415 0l-3.5-3.5a1 1 0 111.415-1.415L8.5 12.086l6.793-6.793a1 1 0 011.415 0z"
                                    clip-rule="evenodd"
                                />
                            </svg>
                        </span>
                        <div class="text-sm">
                            <p class="font-semibold text-gray-800">
                                Password berhasil direset
                            </p>
                            <p class="text-gray-500">
                                Akun
                                <span class="font-medium">{{
                                    resetToastNama
                                }}</span>
                                sekarang menggunakan password default.
                            </p>
                        </div>
                        <button
                            type="button"
                            @click="showResetToast = false"
                            class="ml-2 text-gray-400 hover:text-gray-600 cursor-pointer"
                        >
                            &times;
                        </button>
                    </div>
                </Transition>
            </Teleport>


            <!-- ========================================== -->
            <!-- CUSTOM MODAL KONFIRMASI HAPUS              -->
            <!-- ========================================== -->
            <Teleport to="body">
                <Transition
                    enter-active-class="transition ease-out duration-200"
                    enter-from-class="opacity-0"
                    enter-to-class="opacity-100"
                    leave-active-class="transition ease-in duration-150"
                    leave-from-class="opacity-100"
                    leave-to-class="opacity-0"
                >
                    <div
                        v-if="showModalHapus"
                        class="fixed inset-0 z-[999999] flex items-center justify-center overflow-y-auto overflow-x-hidden bg-slate-900/50 backdrop-blur-sm"
                    >
                        <div class="absolute inset-0" @click="batalHapus"></div>


                        <div class="relative w-full max-w-md p-4">
                            <div
                                class="relative bg-white rounded-2xl shadow-2xl p-6 text-center"
                            >
                                <div
                                    class="mx-auto flex items-center justify-center h-14 w-14 rounded-full bg-red-50 mb-5"
                                >
                                    <svg
                                        class="h-7 w-7 text-red-600"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke-width="2"
                                        stroke="currentColor"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
                                        />
                                    </svg>
                                </div>


                                <h3
                                    class="mb-2 text-lg font-bold text-slate-800"
                                >
                                    Konfirmasi Hapus
                                </h3>


                                <p class="mb-3 text-sm text-slate-500">
                                    Apakah Anda yakin ingin menghapus pegawai
                                    ini?
                                </p>


                                <div
                                    class="mb-5 p-3 bg-slate-50 border border-slate-100 rounded-lg"
                                >
                                    <p
                                        class="text-sm font-extrabold text-slate-700"
                                    >
                                        {{ pegawaiYangAkanDihapus?.nama }}
                                    </p>
                                </div>


                                <p
                                    class="mb-6 text-[13px] text-slate-400 leading-relaxed"
                                >
                                    Akses login akan dicabut, namun riwayat cuti
                                    tetap aman sebagai arsip.
                                </p>


                                <div class="grid grid-cols-2 gap-3">
                                    <button
                                        @click="batalHapus"
                                        type="button"
                                        class="px-4 py-2.5 text-sm font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 rounded-xl transition-colors cursor-pointer"
                                    >
                                        Batal
                                    </button>
                                    <button
                                        @click="eksekusiHapus"
                                        type="button"
                                        class="px-4 py-2.5 text-sm font-semibold text-white bg-red-600 hover:bg-red-700 rounded-xl shadow-sm hover:shadow-md transition-all cursor-pointer"
                                    >
                                        Ya, Hapus
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </Transition>
            </Teleport>


            <!-- ========================================== -->
            <!-- CUSTOM MODAL KONFIRMASI RESET PASSWORD     -->
            <!-- ========================================== -->
            <Teleport to="body">
                <Transition
                    enter-active-class="transition ease-out duration-200"
                    enter-from-class="opacity-0"
                    enter-to-class="opacity-100"
                    leave-active-class="transition ease-in duration-150"
                    leave-from-class="opacity-100"
                    leave-to-class="opacity-0"
                >
                    <div
                        v-if="showModalReset"
                        class="fixed inset-0 z-[999999] flex items-center justify-center overflow-y-auto overflow-x-hidden bg-slate-900/50 backdrop-blur-sm"
                    >
                        <div class="absolute inset-0" @click="batalReset"></div>


                        <div class="relative w-full max-w-md p-4">
                            <div
                                class="relative bg-white rounded-2xl shadow-2xl p-6 text-center"
                            >
                                <div
                                    class="mx-auto flex items-center justify-center h-14 w-14 rounded-full bg-orange-50 mb-5"
                                >
                                    <svg
                                        class="h-7 w-7 text-orange-600"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke-width="2"
                                        stroke="currentColor"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"
                                        />
                                    </svg>
                                </div>


                                <h3
                                    class="mb-2 text-lg font-bold text-slate-800"
                                >
                                    Reset Password
                                </h3>


                                <p class="mb-3 text-sm text-slate-500">
                                    Apakah Anda yakin ingin mereset password
                                    pegawai ini?
                                </p>


                                <div
                                    class="mb-5 p-3 bg-slate-50 border border-slate-100 rounded-lg"
                                >
                                    <p
                                        class="text-sm font-extrabold text-slate-700"
                                    >
                                        {{ pegawaiYangAkanDireset?.nama }}
                                    </p>
                                </div>


                                <p
                                    class="mb-6 text-[13px] text-slate-400 leading-relaxed"
                                >
                                    Password akan diubah menjadi default
                                    <span class="font-bold text-slate-600"
                                        >password123</span
                                    >. Pegawai wajib menggantinya saat login
                                    berikutnya.
                                </p>


                                <div class="grid grid-cols-2 gap-3">
                                    <button
                                        @click="batalReset"
                                        type="button"
                                        class="px-4 py-2.5 text-sm font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 rounded-xl transition-colors cursor-pointer"
                                    >
                                        Batal
                                    </button>
                                    <button
                                        @click="eksekusiReset"
                                        type="button"
                                        class="px-4 py-2.5 text-sm font-semibold text-white bg-orange-500 hover:bg-orange-600 rounded-xl shadow-sm hover:shadow-md transition-all cursor-pointer"
                                    >
                                        Ya, Reset
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </Transition>
            </Teleport>
        </div>
    </MainLayout>
</template>



