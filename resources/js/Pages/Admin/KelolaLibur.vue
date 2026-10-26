<script setup>
import MainLayout from "@/Layouts/MainLayout.vue";
import { Head, useForm, router, Link } from "@inertiajs/vue3";
import { ref, onMounted, onBeforeUnmount } from "vue";

const props = defineProps({
    libur: Object, // Object karena data dari paginate()
});

// ================= FORM TAMBAH LIBUR =================
const form = useForm({
    tanggal: "",
    keterangan: "",
    is_cuti_bersama: false, // Default: Libur Nasional (false)
});

const submit = () => {
    form.post(route("admin.libur.store"), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
};

// ================= IMPOR CSV =================
const csvForm = useForm({
    file: null,
});

const csvInputRef = ref(null);

// Tombol "Impor CSV" disabled selama belum ada file, jadi tidak perlu modal
// peringatan. Guard di bawah mencegah submit paksa (mis. lewat tombol Enter).
const submitCsv = () => {
    if (!csvForm.file || csvForm.processing) return;

    csvForm.post(route("admin.libur.import"), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            csvForm.reset();
            if (csvInputRef.value) csvInputRef.value.value = "";
        },
    });
};

// ================= MODAL KONFIRMASI HAPUS =================
// Menggantikan confirm() bawaan browser. Ditutup lewat tombol Batal, klik di
// luar kartu, atau tombol Esc. "deleting" mencegah klik ganda mengirim dua
// request; error dari server ditampilkan di dalam modal.
const showConfirmModal = ref(false);
const itemToDelete = ref(null);
const deleting = ref(false);
const deleteError = ref("");

const confirmHapus = (id, keterangan) => {
    itemToDelete.value = { id, keterangan };
    deleteError.value = "";
    showConfirmModal.value = true;
};

const closeConfirmModal = () => {
    if (deleting.value) return; // jangan tutup saat request berjalan
    showConfirmModal.value = false;
    itemToDelete.value = null;
    deleteError.value = "";
};

const executeHapus = () => {
    if (deleting.value || !itemToDelete.value) return;

    deleting.value = true;
    deleteError.value = "";

    router.delete(route("admin.libur.destroy", itemToDelete.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            showConfirmModal.value = false;
            itemToDelete.value = null;
        },
        onError: (errors) => {
            deleteError.value =
                Object.values(errors || {})[0] ||
                "Hari libur gagal dihapus. Silakan coba lagi.";
        },
        onFinish: () => {
            deleting.value = false;
        },
    });
};

const tutupDenganEsc = (event) => {
    if (event.key === "Escape" && showConfirmModal.value) closeConfirmModal();
};
onMounted(() => window.addEventListener("keydown", tutupDenganEsc));
onBeforeUnmount(() => window.removeEventListener("keydown", tutupDenganEsc));

// ================= HELPER =================
// Ambil 10 karakter pertama ("YYYY-MM-DD") lalu pakai jam lokal, supaya
// tanggal tidak bergeser karena zona waktu (Date("YYYY-MM-DD") dibaca UTC).
const formatDate = (dateString) => {
    if (!dateString) return "-";
    const date = new Date(`${String(dateString).slice(0, 10)}T00:00:00`);
    if (Number.isNaN(date.getTime())) return "-";
    return new Intl.DateTimeFormat("id-ID", {
        day: "2-digit",
        month: "long",
        year: "numeric",
    }).format(date);
};

// Terjemahkan label bawaan pagination Laravel (Inggris) ke Indonesia.
// Label angka halaman dibiarkan apa adanya.
const translateLabel = (label) => {
    if (!label) return "";
    return label
        .replace(/Previous/i, "Sebelumnya")
        .replace(/Next/i, "Berikutnya");
};
</script>

<template>
    <Head title="Kelola Hari Libur" />

    <MainLayout>
        <div class="max-w-7xl mx-auto space-y-6 pb-12">
            <div
                class="flex flex-col md:flex-row md:items-end justify-between gap-4"
            >
                <div>
                    <h1
                        class="text-2xl md:text-3xl font-bold text-slate-800 tracking-tight"
                    >
                        Kelola Hari Libur
                    </h1>
                    <p class="text-slate-500 mt-1 text-sm">
                        Atur tanggal merah dan cuti bersama instansi secara
                        manual.
                    </p>
                </div>
            </div>

            <!-- Grid 2 kolom baru aktif di layar lg (>=1024px) agar tabel
                 punya ruang cukup di zoom 100%. Di bawah itu tersusun vertikal. -->
            <div
                class="grid grid-cols-1 lg:grid-cols-[minmax(300px,340px)_minmax(0,1fr)] gap-6"
            >
                <!-- FORM INPUT -->
                <div
                    class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200 self-start space-y-6"
                >
                    <div>
                        <h3
                            class="text-base font-bold text-slate-800 mb-4 border-b border-slate-100 pb-3"
                        >
                            Tambah Hari Libur
                        </h3>

                        <form @submit.prevent="submit" class="space-y-4">
                            <div>
                                <label
                                    for="tanggal-libur"
                                    class="block text-sm font-semibold text-slate-700 mb-1.5"
                                    >Tanggal Libur
                                    <span class="text-red-500">*</span></label
                                >
                                <input
                                    id="tanggal-libur"
                                    name="tanggal"
                                    v-model="form.tanggal"
                                    type="date"
                                    required
                                    class="w-full min-h-[44px] text-base sm:text-sm border-slate-200 rounded-xl focus:ring-green-500 focus:border-green-500 bg-slate-50"
                                />
                                <p
                                    v-if="form.errors.tanggal"
                                    class="text-xs text-red-500 mt-1"
                                >
                                    {{ form.errors.tanggal }}
                                </p>
                            </div>

                            <div>
                                <label
                                    for="keterangan-libur"
                                    class="block text-sm font-semibold text-slate-700 mb-1.5"
                                    >Nama Peringatan / Keterangan
                                    <span class="text-red-500">*</span></label
                                >
                                <input
                                    id="keterangan-libur"
                                    name="keterangan"
                                    v-model="form.keterangan"
                                    type="text"
                                    placeholder="Contoh: Hari Kemerdekaan RI"
                                    required
                                    class="w-full min-h-[44px] text-base sm:text-sm border-slate-200 rounded-xl focus:ring-green-500 focus:border-green-500 bg-slate-50"
                                />
                                <p
                                    v-if="form.errors.keterangan"
                                    class="text-xs text-red-500 mt-1"
                                >
                                    {{ form.errors.keterangan }}
                                </p>
                            </div>

                            <div>
                                <label
                                    for="jenis-libur"
                                    class="block text-sm font-semibold text-slate-700 mb-1.5"
                                    >Jenis Libur
                                    <span class="text-red-500">*</span></label
                                >
                                <select
                                    id="jenis-libur"
                                    name="is_cuti_bersama"
                                    v-model="form.is_cuti_bersama"
                                    class="w-full min-h-[44px] text-base sm:text-sm border-slate-200 rounded-xl focus:ring-green-500 focus:border-green-500 bg-slate-50"
                                >
                                    <option :value="false">
                                        Libur Nasional (Tanggal Merah)
                                    </option>
                                    <option :value="true">Cuti Bersama</option>
                                </select>
                                <p
                                    v-if="form.errors.is_cuti_bersama"
                                    class="text-xs text-red-500 mt-1"
                                >
                                    {{ form.errors.is_cuti_bersama }}
                                </p>
                            </div>

                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="w-full min-h-[44px] px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-xl text-sm font-semibold transition disabled:opacity-50 mt-2"
                            >
                                {{
                                    form.processing
                                        ? "Menyimpan..."
                                        : "Simpan Tanggal"
                                }}
                            </button>
                        </form>
                    </div>

                    <div class="border-t border-slate-100 pt-5">
                        <h3 class="text-base font-bold text-slate-800 mb-4">
                            Impor Hari Libur dari CSV
                        </h3>
                        <form @submit.prevent="submitCsv" class="space-y-4">
                            <div>
                                <label
                                    for="csv-file-input"
                                    class="block text-xs font-semibold text-slate-700 mb-1.5"
                                    >File CSV
                                    <span class="text-red-500">*</span></label
                                >
                                <input
                                    id="csv-file-input"
                                    ref="csvInputRef"
                                    name="file"
                                    type="file"
                                    accept=".csv,text/csv"
                                    @change="
                                        csvForm.file =
                                            $event.target.files[0] ?? null
                                    "
                                    class="w-full min-h-[44px] text-base sm:text-sm text-slate-600 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:bg-slate-100 file:text-slate-700 file:font-semibold hover:file:bg-slate-200"
                                />
                                <p class="text-[11px] text-slate-500 mt-2">
                                    Format kolom: tanggal, keterangan,
                                    is_cuti_bersama
                                </p>
                                <p
                                    v-if="csvForm.errors.file"
                                    class="text-xs text-red-500 mt-1"
                                >
                                    {{ csvForm.errors.file }}
                                </p>
                            </div>

                            <button
                                type="submit"
                                :disabled="!csvForm.file || csvForm.processing"
                                class="w-full min-h-[44px] px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-sm font-semibold transition disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-blue-600"
                            >
                                {{
                                    csvForm.processing
                                        ? "Mengimpor..."
                                        : "Impor CSV"
                                }}
                            </button>
                        </form>
                    </div>
                </div>

                <!-- TABEL DAFTAR LIBUR
                     min-w-0 penting agar kolom grid boleh menyusut dan tidak
                     mendorong isi tabel keluar layar -->
                <div
                    class="min-w-0 bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden"
                >
                    <div
                        class="px-6 py-4 border-b border-slate-100 bg-slate-50/50"
                    >
                        <h3 class="text-base font-bold text-slate-800">
                            Daftar Hari Libur Terdaftar
                        </h3>
                    </div>

                    <!-- TAMPILAN TABEL (md ke atas) -->
                    <div class="hidden overflow-x-auto md:block">
                        <!-- table-fixed + lebar kolom tetap: Tanggal, Jenis, Aksi
                             selalu terlihat, Keterangan mengambil sisa ruang -->
                        <table
                            class="w-full table-fixed divide-y divide-slate-100"
                        >
                            <colgroup>
                                <col class="w-[170px]" />
                                <col />
                                <col class="w-[130px]" />
                                <col class="w-[80px]" />
                            </colgroup>
                            <thead class="bg-slate-50/50">
                                <tr>
                                    <th
                                        class="px-6 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wider"
                                    >
                                        Tanggal
                                    </th>
                                    <th
                                        class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wider"
                                    >
                                        Keterangan
                                    </th>
                                    <th
                                        class="px-4 py-3 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wider"
                                    >
                                        Jenis
                                    </th>
                                    <th
                                        class="px-4 py-3 text-center text-[11px] font-bold text-slate-400 uppercase tracking-wider"
                                    >
                                        Aksi
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-slate-50">
                                <!-- libur.data karena format dari pagination -->
                                <tr
                                    v-for="item in libur.data"
                                    :key="item.id"
                                    class="hover:bg-slate-50/70 transition-colors"
                                >
                                    <td
                                        class="px-6 py-4 align-top text-sm text-slate-700 font-semibold"
                                    >
                                        {{ formatDate(item.tanggal) }}
                                    </td>
                                    <td
                                        class="px-4 py-4 align-top text-sm text-slate-600 break-words"
                                    >
                                        {{ item.keterangan }}
                                    </td>
                                    <td class="px-4 py-4 align-top">
                                        <span
                                            v-if="!item.is_cuti_bersama"
                                            class="px-2.5 py-1 inline-flex whitespace-nowrap text-[11px] font-bold bg-red-50 text-red-600 border border-red-100 rounded-md"
                                        >
                                            Libur Nasional
                                        </span>
                                        <span
                                            v-else
                                            class="px-2.5 py-1 inline-flex whitespace-nowrap text-[11px] font-bold bg-blue-50 text-blue-600 border border-blue-100 rounded-md"
                                        >
                                            Cuti Bersama
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 align-top text-center">
                                        <button
                                            type="button"
                                            @click="
                                                confirmHapus(
                                                    item.id,
                                                    item.keterangan,
                                                )
                                            "
                                            :aria-label="`Hapus hari libur ${item.keterangan}`"
                                            class="text-red-500 hover:text-red-700 p-1.5 hover:bg-red-50 rounded-lg transition"
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
                                                    d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
                                                ></path>
                                            </svg>
                                        </button>
                                    </td>
                                </tr>
                                <tr
                                    v-if="
                                        !libur.data || libur.data.length === 0
                                    "
                                >
                                    <td
                                        colspan="4"
                                        class="px-6 py-8 text-center text-slate-500 text-sm"
                                    >
                                        Belum ada hari libur yang didaftarkan.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- TAMPILAN KARTU (mobile) -->
                    <div class="space-y-3 p-4 md:hidden">
                        <article
                            v-for="item in libur.data"
                            :key="item.id"
                            class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0 flex-1">
                                    <!-- Tanggal + badge dalam satu baris
                                         (flex-wrap supaya aman di layar sempit) -->
                                    <div
                                        class="flex flex-wrap items-center gap-x-2 gap-y-1.5"
                                    >
                                        <p
                                            class="text-sm font-semibold text-slate-800"
                                        >
                                            {{ formatDate(item.tanggal) }}
                                        </p>
                                        <span
                                            class="inline-flex rounded-md border px-2 py-0.5 text-[11px] font-bold"
                                            :class="
                                                !item.is_cuti_bersama
                                                    ? 'border-red-100 bg-red-50 text-red-600'
                                                    : 'border-blue-100 bg-blue-50 text-blue-600'
                                            "
                                        >
                                            {{
                                                !item.is_cuti_bersama
                                                    ? "Libur Nasional"
                                                    : "Cuti Bersama"
                                            }}
                                        </span>
                                    </div>
                                    <p
                                        class="mt-1.5 break-words text-sm text-slate-600"
                                    >
                                        {{ item.keterangan }}
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    @click="
                                        confirmHapus(item.id, item.keterangan)
                                    "
                                    :aria-label="`Hapus hari libur ${item.keterangan}`"
                                    class="inline-flex min-h-[40px] min-w-[40px] shrink-0 items-center justify-center rounded-lg p-2 text-red-500 transition hover:bg-red-50 hover:text-red-700"
                                >
                                    <svg
                                        class="h-4 w-4"
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
                                        ></path>
                                    </svg>
                                </button>
                            </div>
                        </article>
                        <p
                            v-if="!libur.data || libur.data.length === 0"
                            class="py-6 text-center text-sm text-slate-500"
                        >
                            Belum ada hari libur yang didaftarkan.
                        </p>
                    </div>

                    <!-- PAGINATION -->
                    <div
                        v-if="libur.data && libur.data.length > 0"
                        class="px-6 py-4 border-t border-slate-100 flex flex-col md:flex-row justify-between items-center gap-4 bg-slate-50/50"
                    >
                        <span class="text-sm text-slate-500 text-center">
                            Menampilkan {{ libur.from || 0 }} -
                            {{ libur.to || 0 }} dari {{ libur.total }} data
                        </span>

                        <!-- flex-wrap + justify-center: aman saat halaman banyak -->
                        <div class="flex flex-wrap justify-center gap-1">
                            <template
                                v-for="(link, index) in libur.links"
                                :key="index"
                            >
                                <div
                                    v-if="link.url === null"
                                    class="px-3 py-1 text-sm text-slate-400 bg-slate-50 border border-slate-200 rounded cursor-not-allowed"
                                    v-html="translateLabel(link.label)"
                                ></div>
                                <Link
                                    v-else
                                    :href="link.url"
                                    preserve-scroll
                                    class="px-3 py-1 text-sm border border-slate-200 rounded hover:bg-green-50 transition-colors"
                                    :class="
                                        link.active
                                            ? 'bg-green-600 text-white border-green-600 hover:bg-green-700'
                                            : 'text-slate-600 bg-white'
                                    "
                                    v-html="translateLabel(link.label)"
                                >
                                </Link>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </MainLayout>

    <!-- MODAL KONFIRMASI HAPUS
         Di-Teleport ke <body> supaya tidak terpotong stacking context layout. -->
    <Teleport to="body">
        <div
            v-if="showConfirmModal"
            class="fixed inset-0 z-[9999] flex items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4"
            @click.self="closeConfirmModal"
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="judul-hapus-libur"
                class="bg-white rounded-2xl p-6 w-full max-w-sm shadow-xl text-center"
            >
                <div
                    class="w-12 h-12 bg-red-100 text-red-600 rounded-full flex items-center justify-center mx-auto mb-4"
                >
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
                <h3
                    id="judul-hapus-libur"
                    class="text-lg font-bold text-slate-800 mb-2"
                >
                    Hapus Hari Libur?
                </h3>
                <p class="text-sm text-slate-500 mb-4">
                    Apakah Anda yakin ingin menghapus
                    <span class="font-bold text-slate-700 break-words">{{
                        itemToDelete?.keterangan
                    }}</span
                    >? Tindakan ini tidak dapat dibatalkan.
                </p>
                <p
                    v-if="deleteError"
                    class="mb-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs font-semibold text-red-600"
                >
                    {{ deleteError }}
                </p>
                <div class="flex gap-3 w-full">
                    <button
                        type="button"
                        @click="closeConfirmModal"
                        :disabled="deleting"
                        class="flex-1 px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-sm font-semibold transition disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        Batal
                    </button>
                    <button
                        type="button"
                        @click="executeHapus"
                        :disabled="deleting"
                        class="flex-1 px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-semibold transition shadow-sm disabled:opacity-60 disabled:cursor-not-allowed"
                    >
                        {{ deleting ? "Menghapus..." : "Ya, Hapus" }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>