<script setup>
import { ref, computed, watch } from "vue";
import { router, useForm, Head } from "@inertiajs/vue3";
import MainLayout from "@/Layouts/MainLayout.vue";

const currentYear = new Date().getFullYear();

/**
 * Hitung saldo bawaan eligible — selaras dengan Dashboard karyawan & Excel:
 * IF (sisa N-2 == 12 AND sisa N-1 == 12) → 12
 * else → MIN(6, sisa N-1)
 * Jika backend sudah kirim saldo_bawaan_eligible, pakai itu.
 */
const getSaldoBawaanEligible = (pegawai) => {
    if (pegawai?.saldo_bawaan_eligible != null) {
        return Number(pegawai.saldo_bawaan_eligible) || 0;
    }
    const sisaN2 = Number(pegawai?.sisa_cuti_dua_tahun_lalu ?? 0);
    const sisaN1 = Number(pegawai?.carry_forward_normal ?? 0);
    if (sisaN2 === 12 && sisaN1 === 12) return 12;
    return Math.min(6, sisaN1);
};

const getSisaDuaTahunLalu = (pegawai) =>
    Number(pegawai?.sisa_cuti_dua_tahun_lalu ?? 0);

// Hak tahun berjalan adalah kuota awal dari database, bukan sisa setelah
// dikurangi cuti yang sudah disetujui.
const getSisaTahunIni = (pegawai) =>
    Number(
        pegawai?.kuota_tahunan ??
            pegawai?.hak_tahun_ini ??
            0,
    );

const getSisaTahunLalu = (pegawai) =>
    Number(pegawai?.carry_forward_normal ?? 0);

const getTotalTersedia = (pegawai) => {
    if (pegawai?.total_cuti_tersedia != null) {
        return Number(pegawai.total_cuti_tersedia) || 0;
    }
    // Excel: Total Hak Tersedia = Saldo Bawaan Eligible + Hak tahun berjalan
    return getSisaTahunIni(pegawai) + getSaldoBawaanEligible(pegawai);
};

const getSaldoAkhir = (pegawai) => {
    if (pegawai?.saldo_akhir != null) {
        return Number(pegawai.saldo_akhir) || 0;
    }
    // Excel: Saldo Akhir = Total Hak Tersedia − Terpakai
    const terpakai = Number(pegawai?.cuti_terpakai ?? 0);
    return Math.max(0, getTotalTersedia(pegawai) - terpakai);
};

// Sisa jatah tahun berjalan untuk KPI modal. Ini berbeda dari Total
// Tersedia karena tidak memasukkan Saldo Bawaan Eligible.
const getSisaTahunBerjalan = (pegawai) => {
    const sisa = getSisaTahunIni(pegawai) - Number(pegawai?.cuti_terpakai ?? 0);
    return Math.max(0, sisa);
};

// ================================================================
// Sisa ditangguhkan — HANYA untuk modal Detail, TIDAK ditampilkan
// di tabel utama. Sesuai kesepakatan: kolom ini di Excel hampir
// selalu 0 (hanya terisi saat ada penangguhan resmi), jadi kalau
// dipaksakan tampil di tabel utama hanya bikin tabel lebih lebar
// tanpa menambah informasi yang sering dicek admin.
// ================================================================
const getSisaDitangguhkanN2 = (pegawai) =>
    Number(
        pegawai?.sisa_ditangguhkan_dua_tahun_lalu ??
            pegawai?.sisa_ditangguhkan_2024 ??
            0,
    );

const getSisaDitangguhkanN1 = (pegawai) =>
    Number(
        pegawai?.sisa_ditangguhkan_tahun_lalu ??
            pegawai?.sisa_ditangguhkan_2025 ??
            0,
    );

// Dipakai untuk v-if di modal: blok "Sisa Ditangguhkan" hanya
// dirender kalau salah satu nilainya > 0, supaya modal tidak ramai
// saat kasusnya (mayoritas) tidak ada penangguhan.
const punyaSisaDitangguhkan = (pegawai) =>
    getSisaDitangguhkanN2(pegawai) > 0 || getSisaDitangguhkanN1(pegawai) > 0;

// Sekarang menerima 'dataPegawai' (satu baris per pegawai), bukan 'pengajuan'
const props = defineProps({
    dataPegawai: Object,
    filters: Object,
    daftarKelompokSubstansi: {
        type: Array,
        default: () => [],
    },
    // ================================================================
    // [BARU] KPI ringkasan (Total Pegawai, Rata-rata Terpakai, Rata-rata
    // Saldo Akhir, Akumulasi Penuh) — SEKARANG DIKIRIM LANGSUNG dari
    // backend (RekapKuotaDetailController::hitungKpi()), dihitung dari
    // SELURUH data hasil filter (search/kelompok substansi/tim kerja),
    // bukan hanya dari 10 baris yang sedang tampil di halaman aktif.
    //
    // SEBELUMNYA KPI dihitung di sisi Vue dari 'dataPegawai.data' (data
    // satu halaman paginasi), sehingga kalau suatu kelompok substansi
    // punya lebih dari 10 pegawai, KPI hanya mencerminkan 10 baris
    // pertama, bukan keseluruhan pegawai yang cocok dengan filter.
    // ================================================================
    kpi: {
        type: Object,
        default: () => ({}),
    },
});

const search = ref(props.filters?.search || "");
const filterKelompok = ref(props.filters?.kelompok_substansi || "");

// ================================================================
// [DIPERBARUI] KPI Cards ringkasan (versi putih, senada latar belakang).
// SEKARANG DIAMBIL LANGSUNG dari prop 'kpi' yang dikirim backend (lihat
// catatan di defineProps di atas), supaya nilainya selalu mencerminkan
// SELURUH data hasil filter — bukan cuma satu halaman paginasi.
//
// 'daftarPegawaiHalamanIni' masih dipertahankan karena dipakai di
// tempat lain (v-for tabel), TIDAK lagi dipakai untuk menghitung KPI.
// ================================================================
const daftarPegawaiHalamanIni = computed(() => props.dataPegawai?.data || []);

const kpiTotalPegawai = computed(() => props.kpi?.total_pegawai ?? 0);

const kpiRataRataTerpakai = computed(() => props.kpi?.rata_rata_terpakai ?? 0);

const kpiRataRataSaldoAkhir = computed(
    () => props.kpi?.rata_rata_saldo_akhir ?? 0,
);

const kpiAkumulasiPenuh = computed(() => props.kpi?.akumulasi_penuh ?? 0);
// ================= END TAMBAHAN KPI CARDS =================

// State untuk Modal Detail Riwayat Cuti
const showModal = ref(false);
const selectedPegawai = ref(null);

// Menyimpan ID riwayat cuti yang sedang di-expand (dibuka rinciannya)
const expandedRiwayatId = ref(null);

// ========================================== //
// ---> FITUR: EDIT SALDO CUTI PERORANGAN <---
// ========================================== //
// Satu-satunya tempat mengubah kuota/saldo cuti pegawai (single source
// of truth). Field "Kelola Pegawai" khusus identitas & posisi, TIDAK
// lagi menangani saldo cuti — lihat catatan di KelolaPegawai.vue.
//
// saldoForm sekarang memuat 5 isian yang sejajar dengan kolom sumber di
// sheet "Saldo Tahunan" Excel, supaya input manual & Impor Kuota
// menghasilkan data yang sama:
//   - hak_tahun_ini                     -> Hak {tahun} (jatah tahunan)
//   - carry_forward_normal              -> Sisa {tahun-1}
//   - sisa_ditangguhkan_tahun_lalu      -> Ditangguhkan {tahun-1}
//   - sisa_cuti_dua_tahun_lalu          -> Sisa {tahun-2}
//   - sisa_ditangguhkan_dua_tahun_lalu  -> Ditangguhkan {tahun-2}
// Saldo Bawaan Eligible, Total Tersedia & Saldo Akhir TIDAK diisi manual,
// melainkan dihitung otomatis dan langsung tampil di kartu KPI & kotak
// "Saldo Akhir" di atas form setelah data disimpan.
const saldoForm = useForm({
    hak_tahun_ini: 0,
    carry_forward_normal: 0,
    sisa_ditangguhkan_tahun_lalu: 0,
    sisa_cuti_dua_tahun_lalu: 0,
    sisa_ditangguhkan_dua_tahun_lalu: 0,
});

const isSavingSaldo = computed(() => saldoForm.processing);

// Nilai dari data pegawai dijadikan "defaults" form, sehingga
// saldoForm.isDirty hanya true kalau admin benar-benar mengubah isian.
// Tombol Simpan & Reset memakai isDirty ini.
const isiSaldoFormDariPegawai = (pegawai) => {
    saldoForm.defaults({
        hak_tahun_ini: getSisaTahunIni(pegawai),
        carry_forward_normal: getSisaTahunLalu(pegawai),
        sisa_ditangguhkan_tahun_lalu: getSisaDitangguhkanN1(pegawai),
        sisa_cuti_dua_tahun_lalu: getSisaDuaTahunLalu(pegawai),
        sisa_ditangguhkan_dua_tahun_lalu: getSisaDitangguhkanN2(pegawai),
    });
    saldoForm.reset();
};

// Kembalikan isian ke data tersimpan tanpa menutup modal.
const resetSaldoForm = () => {
    saldoForm.reset();
    saldoForm.clearErrors();
};

const simpanSaldo = () => {
    if (!selectedPegawai.value || isSavingSaldo.value) return;

    saldoForm.put(
        route("admin.rekap-kuota.update-saldo", selectedPegawai.value.id),
        {
            preserveScroll: true,
            onSuccess: () => {
                closeModal();
            },
        },
    );
};
// ================= END FITUR EDIT SALDO CUTI PERORANGAN =================

// Tab aktif di dalam popup: "saldo" (Kelola Saldo) atau "riwayat".
// Selalu mulai dari tab Kelola Saldo saat popup dibuka.
const modalTab = ref("saldo");

const openDetailModal = (pegawai) => {
    selectedPegawai.value = pegawai;
    modalTab.value = "saldo";
    expandedRiwayatId.value = null; // Reset saat buka pegawai baru
    isiSaldoFormDariPegawai(pegawai);
    saldoForm.clearErrors();
    showModal.value = true;
};

const closeModal = () => {
    showModal.value = false;
    selectedPegawai.value = null;
    modalTab.value = "saldo";
    expandedRiwayatId.value = null;
    saldoForm.clearErrors();
};

// Toggle buka/tutup rincian kartu riwayat
const toggleExpand = (id) => {
    expandedRiwayatId.value = expandedRiwayatId.value === id ? null : id;
};

// Kamus label status, konsisten dengan halaman Approval
const statusLabels = {
    menunggu_l1: "Menunggu Bapak Ketua Tim Kerja (L1)",
    menunggu_l2: "Menunggu Bapak Ketua Kelompok Substansi (L2)",
    menunggu_l3: "Menunggu Ignatius Agus Hendarto (L3)",
    menunggu_l4: "Menunggu Seta Rukmalasari Agustina (L4)",
    disetujui: "Disetujui",
    ditolak: "Ditolak",
    dibatalkan_ditangguhkan: "Dibatalkan/Ditangguhkan",
};

const getStatusLabel = (st) => {
    return statusLabels[st] || st?.replace(/_/g, " ").toUpperCase();
};

// Label & warna untuk tiap langkah approval_chain (BARU) — dipakai
// menampilkan status per level L1-L4 secara lengkap: Setuju / Tolak /
// Menunggu / Belum Giliran, konsisten di semua status pengajuan.
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

// Fungsi menghitung durasi hari otomatis dari tanggal (fallback), dipakai
// kalau field 'jumlah_hari' kosong/tidak terisi di suatu pengajuan.
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

// Helper pembersih kalimat keterangan (konsisten dengan AntreanApproval.vue)
const formatKeteranganRapi = (text) => {
    if (!text || text === "-") return "-";

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

// Auto-search saat mengetik (hanya 'search', karena filter status/jenis
// cuti tidak relevan lagi di tampilan ringkasan per-pegawai ini)
watch(
    [search, filterKelompok],
    debounce(function () {
        router.get(
            route("admin.monitoring"),
            {
                search: search.value,
                kelompok_substansi: filterKelompok.value,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }, 300),
);

// Navigasi pagination (Laravel paginator links)
const goToPage = (url) => {
    if (!url) return;
    router.get(url, {}, { preserveState: true, preserveScroll: true });
};

// ========================================== //
// ---> FITUR: IMPOR KUOTA CUTI <---
// ========================================== //
// Modal terpisah dari modal Detail Riwayat di atas, supaya tidak
// mengganggu alur/data yang sudah ada. Menggunakan useForm terpisah
// karena payload-nya berupa file upload, bukan data pegawai satu per satu.
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

// NOTE: Route "admin.monitoring.import" perlu didaftarkan di backend
// (mis. lewat controller terkait + package maatwebsite/excel) sebelum
// tombol ini benar-benar memproses file. Form ini sudah siap dipakai
// begitu endpoint-nya tersedia.
const submitImportForm = () => {
    if (!importForm.file) return;

    importForm.post(route("admin.monitoring.import"), {
        forceFormData: true,
        onSuccess: () => {
            closeImportModal();
        },
    });
};

// ========================================== //
// ---> FITUR (DIREKONSTRUKSI): GENERATE SALDO TAHUN BARU (MANUAL) <---
// ========================================== //
// Sebelumnya tombol ini ada di halaman Admin/KelolaSaldo.vue (terpisah),
// tapi halaman itu sudah digabung ke RekapKuotaDetail.vue ini. Backend
// TIDAK BERUBAH sama sekali — tetap memanggil route 'admin.saldo.generate'
// (AdminController::generateSaldoManual), yang di baliknya menjalankan
// Artisan Command 'saldo-cuti:generate-tahun-baru'. Command ini SAMA
// PERSIS dengan yang dijalankan otomatis oleh Laravel Scheduler setiap
// 1 Januari (lihat GenerateSaldoCutiTahunBaru.php & routes/console.php),
// sehingga hasil generate manual & otomatis selalu identik.
//
// Dipakai modal konfirmasi terpisah (bukan langsung jalan saat tombol
// diklik) karena ini aksi massal yang menyentuh SEMUA pegawai sekaligus.
const isGenerateSaldoModalOpen = ref(false);

const generateSaldoForm = useForm({
    tahun: currentYear + 1,
});

const openGenerateSaldoModal = () => {
    generateSaldoForm.tahun = currentYear + 1;
    generateSaldoForm.clearErrors();
    isGenerateSaldoModalOpen.value = true;
};

const closeGenerateSaldoModal = () => {
    isGenerateSaldoModalOpen.value = false;
};

const submitGenerateSaldo = () => {
    generateSaldoForm.post(route("admin.saldo.generate"), {
        preserveScroll: true,
        onSuccess: () => {
            closeGenerateSaldoModal();
        },
    });
};
// ================= END FITUR GENERATE SALDO TAHUN BARU =================
</script>

<template>
    <Head title="Rekap Kuota Detail Cuti" />

    <MainLayout>
        <!-- Div pembungkus tunggal: MainLayout hanya boleh punya SATU elemen
             anak langsung agar transisi halaman Inertia/Vue tidak error.
             Tabel dan Modal sekarang sama-sama ada di dalam div ini. -->
        <div class="relative w-full h-full">
            <div class="p-6 bg-white rounded-xl shadow-sm">
                <!-- Baris judul: judul + subjudul di kiri, tombol aksi
                 (Impor, Generate Saldo, Export) selalu di kanan atas —
                 sesuai aturan layout prototype.

                 KONSISTENSI WARNA TOMBOL (skema disepakati per jenis aksi,
                 berlaku di seluruh halaman AgriLeave):
                   - Impor           -> Blue (konsisten dengan tombol Impor
                                        Data di halaman Kelola Pegawai)
                   - Generate Saldo  -> Emerald (aksi "generate"/otomatisasi,
                                        beda warna dari Impor & Export supaya
                                        admin tidak salah pencet)
                   - Export          -> Hijau tua (green-700), dipertahankan
                                        sebagai aksi utama -->
                <div
                    class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-5 gap-4"
                >
                    <div>
                        <h2 class="text-xl font-bold">
                            Rekap Kuota Detail Cuti
                        </h2>
                        <p class="text-sm text-gray-500 mt-0.5">
                            Lihat sisa, kelola saldo, dan riwayat pemakaian
                            kuota cuti tahunan tiap pegawai.
                        </p>
                    </div>

                    <div
                        class="flex flex-col sm:flex-row gap-3 items-center w-full sm:w-auto shrink-0"
                    >
                        <!-- Tombol Impor Kuota — bentuk pill (rounded-full),
                         warna biru pastel, konsisten dengan tombol Impor
                         Data di halaman Kelola Pegawai. -->
                        <button
                            type="button"
                            @click="openImportModal"
                            class="inline-flex items-center justify-center w-full sm:w-auto gap-2 px-5 py-2.5 bg-blue-600 border border-transparent rounded-full font-semibold text-sm text-white hover:bg-blue-700 transition ease-in-out duration-150 shadow-sm cursor-pointer"
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
                            Impor Kuota
                        </button>

                        <!-- Tombol Generate Saldo Tahun Baru (Manual) — DIREKONSTRUKSI.
                         Memanggil route 'admin.saldo.generate' yang sudah ada
                         sejak sebelumnya (AdminController::generateSaldoManual),
                         backend TIDAK berubah sama sekali. -->
                        <button
                            type="button"
                            @click="openGenerateSaldoModal"
                            class="inline-flex items-center justify-center w-full sm:w-auto gap-2 px-5 py-2.5 bg-emerald-600 border border-transparent rounded-full font-semibold text-sm text-white hover:bg-emerald-700 transition ease-in-out duration-150 shadow-sm cursor-pointer"
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
                                    d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
                                />
                            </svg>
                            Generate Saldo Tahun Baru (Manual)
                        </button>

                        <!-- Tombol Export — bentuk pill (rounded-full), warna
                         solid hijau dipertahankan sebagai aksi utama. -->
                        <a
                            :href="route('admin.monitoring.export')"
                            class="w-full sm:w-auto bg-green-700 hover:bg-green-800 text-white px-5 py-2.5 rounded-full text-sm font-semibold transition inline-flex items-center justify-center gap-2"
                        >
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="w-4 h-4"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"
                                />
                            </svg>
                            Export Excel
                        </a>
                    </div>
                </div>

                <!--
          ================================================================
          TAMBAHAN: KPI Cards (versi putih, senada latar belakang halaman).
          Sengaja TIDAK pakai warna latar berwarna-warni supaya tidak
          bersaing dengan tabel di bawahnya — border tipis + shadow lembut
          saja. Angka SEKARANG diambil dari prop 'kpi' yang dikirim
          backend (lihat computed kpiTotalPegawai dkk di atas), dihitung
          dari SELURUH data hasil filter — bukan cuma halaman aktif.
          ================================================================
        -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                    <div
                        class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm flex flex-col justify-between"
                    >
                        <h4
                            class="text-slate-500 text-[10px] font-bold uppercase tracking-wider mb-2"
                        >
                            Total Pegawai
                        </h4>
                        <div class="flex items-baseline gap-1">
                            <span
                                class="text-2xl font-extrabold text-slate-800"
                                >{{ kpiTotalPegawai }}</span
                            >
                        </div>
                    </div>

                    <div
                        class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm flex flex-col justify-between"
                    >
                        <h4
                            class="text-slate-500 text-[10px] font-bold uppercase tracking-wider mb-2"
                        >
                            Rata-Rata Terpakai
                        </h4>
                        <div class="flex items-baseline gap-1.5">
                            <span
                                class="text-2xl font-extrabold text-rose-500"
                                >{{ kpiRataRataTerpakai }}</span
                            >
                            <span class="text-sm font-medium text-slate-400"
                                >hari</span
                            >
                        </div>
                    </div>

                    <div
                        class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm flex flex-col justify-between"
                    >
                        <h4
                            class="text-slate-500 text-[10px] font-bold uppercase tracking-wider mb-2"
                        >
                            Rata-Rata Saldo Akhir
                        </h4>
                        <div class="flex items-baseline gap-1.5">
                            <span
                                class="text-2xl font-extrabold text-emerald-500"
                                >{{ kpiRataRataSaldoAkhir }}</span
                            >
                            <span class="text-sm font-medium text-slate-400"
                                >hari</span
                            >
                        </div>
                    </div>

                    <div
                        class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm flex flex-col justify-between"
                    >
                        <h4
                            class="text-slate-500 text-[10px] font-bold uppercase tracking-wider mb-2"
                        >
                            Akumulasi Penuh (12/12)
                        </h4>
                        <div class="flex items-baseline gap-1.5">
                            <span
                                class="text-2xl font-extrabold text-blue-600"
                                >{{ kpiAkumulasiPenuh }}</span
                            >
                            <span class="text-sm font-medium text-slate-400"
                                >orang</span
                            >
                        </div>
                    </div>
                </div>
                <!-- ================= END TAMBAHAN KPI CARDS ================= -->

                <!-- Baris tersendiri di bawah judul: search + filter,
                 sejajar satu sama lain — sesuai aturan layout prototype. -->
                <div
                    class="flex flex-col sm:flex-row gap-3 items-stretch sm:items-center mb-6"
                >
                    <div class="relative flex-1">
                        <input
                            v-model="search"
                            type="text"
                            placeholder="Cari nama/NIP..."
                            class="border border-gray-300 rounded-lg pl-10 pr-4 py-2 w-full text-sm focus:ring-green-500 focus:border-green-500"
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

                    <select
                        v-model="filterKelompok"
                        class="border border-gray-300 rounded-lg px-3 py-2 text-sm w-full sm:w-64 shrink-0 focus:ring-green-500 focus:border-green-500"
                        aria-label="Filter kelompok substansi"
                    >
                        <option value="">Semua Kelompok Substansi</option>
                        <option
                            v-for="kelompok in daftarKelompokSubstansi"
                            :key="kelompok"
                            :value="kelompok"
                        >
                            {{ kelompok }}
                        </option>
                    </select>
                </div>

                <!--
          ================================================================
          TABEL MONITORING: SATU BARIS PER PEGAWAI (bukan per pengajuan)

          Kolom mengikuti kesepakatan hasil diskusi (selaras sheet Saldo
          Tahunan Excel, konsisten dengan KPI Dashboard karyawan):
                        Nama Pegawai | Sisa N-2 | Sisa N-1 | Saldo Bawaan Eligible |
                        Hak N | Total Tersedia | Terpakai | Saldo Akhir | Aksi

                    Kolom "Sisa {{ currentYear }}" dihilangkan karena redundan.
          kolom rinci yang sudah ada sebelumnya (Sisa N-2/N-1, Bawaan
          Eligible, Total Tersedia) — kolom itu tetap dipertahankan karena
          memang berguna khusus buat Admin memverifikasi data mentah.

          SENGAJA TIDAK menampilkan kolom "Sisa Ditangguhkan N-2/N-1",
          "Status Saldo", atau "Catatan" di tabel utama karena:
            - Di Excel nilainya hampir selalu 0 (hanya terisi saat ada
              penangguhan resmi), jadi jarang relevan buat dicek cepat.
            - Menambah kolom tsb membuat tabel terlalu lebar & sulit
              discroll di layar laptop.
          Info tersebut TETAP ADA, dipindah ke Modal Detail (lihat blok
          "Sisa Ditangguhkan" di bawah, hanya tampil jika nilai > 0).
          ================================================================
        -->
                <!--
          PEMBARUAN GAYA (badge/pill): setiap angka di kolom data sekarang
          dibungkus "kapsul" warna (bg + border + rounded-md) memakai
          min-w tetap supaya kotaknya sejajar rapi walau angkanya 1 atau
          2 digit — persis konsep Boss. Kolom Saldo Akhir sengaja diberi
          background gelap (bg-slate-800/text-white) agar jadi fokus mata
          paling akhir di baris, karena itu adalah angka paling penting.
          whitespace-nowrap tetap dipertahankan supaya header panjang
          tidak patah ke bawah.
        -->
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
                                    Sisa {{ currentYear - 2 }}
                                </th>
                                <th class="p-4 text-center text-orange-600">
                                    Tgh '{{
                                        (currentYear - 2).toString().slice(-2)
                                    }}
                                </th>
                                <th class="p-4 text-center">
                                    Sisa {{ currentYear - 1 }}
                                </th>
                                <th class="p-4 text-center text-orange-600">
                                    Tgh '{{
                                        (currentYear - 1).toString().slice(-2)
                                    }}
                                </th>
                                <th class="p-4 text-center">
                                    Bawaan Eligible {{ currentYear }}
                                </th>
                                <th class="p-4 text-center">
                                    Hak {{ currentYear }}
                                </th>
                                <th class="p-4 text-center">
                                    Total Tersedia {{ currentYear }}
                                </th>
                                <th class="p-4 text-center">
                                    Terpakai {{ currentYear }}
                                </th>
                                <th class="p-4 text-center text-slate-800">
                                    Saldo Akhir {{ currentYear }}
                                </th>
                                <th class="p-4 text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="text-sm align-middle">
                            <tr
                                v-for="pegawai in dataPegawai?.data"
                                :key="pegawai.id"
                                class="border-b border-slate-100 hover:bg-slate-50 transition-colors"
                            >
                                <!-- Nama & Kelompok Substansi (tetap rata kiri) -->
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
                                            pegawai.kelompok_substansi ||
                                            "Biro Perencanaan"
                                        }}
                                    </div>
                                </td>

                                <!-- Sisa N-2 (Badge Abu-abu lebih tegas) -->
                                <td class="p-4 text-center">
                                    <span
                                        class="inline-block min-w-[32px] px-2 py-1 bg-slate-200 text-slate-700 rounded-md font-semibold text-xs border border-slate-300/80"
                                    >
                                        {{ getSisaDuaTahunLalu(pegawai) }}
                                    </span>
                                </td>

                                <!-- Ditangguhkan N-2 (Badge Oranye) — ditambahkan
                                 supaya admin bisa lihat cuti ditangguhkan
                                 tanpa perlu buka modal Detail satu-satu -->
                                <td class="p-4 text-center">
                                    <span
                                        class="inline-block min-w-[32px] px-2 py-1 bg-orange-100 text-orange-800 rounded-md font-bold text-xs border border-orange-300/80"
                                    >
                                        {{ getSisaDitangguhkanN2(pegawai) }}
                                    </span>
                                </td>

                                <!-- Sisa N-1 (Badge Abu-abu lebih tegas) -->
                                <td class="p-4 text-center">
                                    <span
                                        class="inline-block min-w-[32px] px-2 py-1 bg-slate-200 text-slate-700 rounded-md font-semibold text-xs border border-slate-300/80"
                                    >
                                        {{ getSisaTahunLalu(pegawai) }}
                                    </span>
                                </td>

                                <!-- Ditangguhkan N-1 (Badge Oranye) -->
                                <td class="p-4 text-center">
                                    <span
                                        class="inline-block min-w-[32px] px-2 py-1 bg-orange-100 text-orange-800 rounded-md font-bold text-xs border border-orange-300/80"
                                    >
                                        {{ getSisaDitangguhkanN1(pegawai) }}
                                    </span>
                                </td>

                                <!-- Saldo Bawaan Eligible (Badge Hijau lebih tegas) -->
                                <td class="p-4 text-center">
                                    <span
                                        class="inline-block min-w-[32px] px-2 py-1 bg-emerald-100 text-emerald-800 rounded-md font-bold text-xs border border-emerald-300/80"
                                    >
                                        {{ getSaldoBawaanEligible(pegawai) }}
                                    </span>
                                </td>

                                <!-- Hak tahun berjalan (Badge Biru lebih tegas) -->
                                <td class="p-4 text-center">
                                    <span
                                        class="inline-block min-w-[32px] px-2 py-1 bg-blue-100 text-blue-800 rounded-md font-bold text-xs border border-blue-300/80"
                                    >
                                        {{ getSisaTahunIni(pegawai) }}
                                    </span>
                                </td>

                                <!-- Total Hak Tersedia (Badge Ungu/Indigo lebih tegas) -->
                                <td class="p-4 text-center">
                                    <span
                                        class="inline-block min-w-[32px] px-2 py-1 bg-indigo-100 text-indigo-800 rounded-md font-bold text-xs border border-indigo-300/80"
                                    >
                                        {{ getTotalTersedia(pegawai) }}
                                    </span>
                                </td>

                                <!-- Terpakai (Badge Merah lebih tegas) -->
                                <td class="p-4 text-center">
                                    <span
                                        class="inline-block min-w-[32px] px-2 py-1 bg-rose-100 text-rose-800 rounded-md font-bold text-xs border border-rose-300/80"
                                    >
                                        {{ pegawai.cuti_terpakai || 0 }}
                                    </span>
                                </td>

                                <!-- Saldo Akhir (Badge gelap/hitam agar paling menonjol) -->
                                <td class="p-4 text-center">
                                    <span
                                        class="inline-block min-w-[36px] px-2.5 py-1 bg-slate-800 text-white rounded-md font-extrabold text-xs shadow-sm border border-slate-900"
                                    >
                                        {{ getSaldoAkhir(pegawai) }}
                                    </span>
                                </td>

                                <!-- Aksi (Tombol Kelola Kuota) — bentuk pill
                                 (rounded-full) mengikuti prototype. Label
                                 diubah dari "Detail" menjadi "Kelola Kuota"
                                 agar sejak awal jelas kalau di dalamnya bisa
                                 melihat riwayat SEKALIGUS mengedit saldo. -->
                                <td class="p-4 text-center">
                                    <button
                                        @click="openDetailModal(pegawai)"
                                        type="button"
                                        class="inline-flex items-center gap-1.5 px-4 py-1.5 border border-gray-300 rounded-full text-xs font-medium text-gray-700 bg-white hover:bg-gray-50 hover:text-gray-900 transition shadow-sm cursor-pointer"
                                        title="Lihat Riwayat & Kelola Kuota"
                                    >
                                        <svg
                                            xmlns="http://www.w3.org/2000/svg"
                                            class="w-4 h-4 text-gray-500"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                            stroke="currentColor"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                stroke-width="2"
                                                d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"
                                            />
                                        </svg>
                                        Kelola Kuota
                                    </button>
                                </td>
                            </tr>

                            <tr
                                v-if="
                                    !dataPegawai?.data ||
                                    dataPegawai.data.length === 0
                                "
                            >
                                <td
                                    colspan="12"
                                    class="p-8 text-center text-gray-400"
                                >
                                    Tidak ada data pegawai ditemukan.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
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

            <!-- MODAL DETAIL KUOTA + EDIT SALDO + RIWAYAT CUTI PEGAWAI
             (POSISI DIPERBAIKI AGAR SELALU DI TENGAH LAYAR, TIDAK PEDULI
             TINGGI KONTEN HALAMAN DI BELAKANGNYA MAUPUN LEVEL ZOOM
             BROWSER) — tetap memakai data asli 'pengajuan_cuti' &
             'approval_chain' dari controller, bukan field placeholder. -->
            <div
                v-if="showModal"
                class="fixed inset-0 z-[9999] flex items-stretch sm:items-center justify-center bg-black/60 p-0 sm:p-4 backdrop-blur-xs sm:overflow-y-auto"
            >
                <div
                    class="bg-white sm:rounded-2xl max-w-2xl w-full shadow-2xl relative animate-in fade-in zoom-in duration-200 flex flex-col h-[100dvh] max-h-[100dvh] sm:h-auto sm:max-h-[85vh] sm:my-auto"
                >
                    <!-- Header Modal -->
                    <div
                        class="border-b px-4 sm:px-6 py-3 sm:py-4 flex justify-between items-center gap-3 bg-gray-50 sm:rounded-t-2xl shrink-0"
                    >
                        <div>
                            <h3 class="text-lg sm:text-xl font-bold text-gray-900">
                                Rincian Kuota & Riwayat Pegawai
                            </h3>
                            <p class="hidden sm:block text-xs text-gray-500 mt-0.5">
                                Kelola saldo cuti pegawai dan lihat riwayat
                                pengajuannya di satu tempat
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

                    <!-- Body Modal (scroll rapi) -->
                    <!-- Struktur body: [profil + KPI] -> [tab sticky] -> [isi tab].
                         Seluruhnya satu area scroll (flex-1 min-h-0), sedangkan
                         header & footer tetap di tempat. Tab menempel di atas
                         (sticky) saat isi di-scroll. -->
                    <div
                        v-if="selectedPegawai"
                        class="flex-1 min-h-0 overflow-y-auto"
                    >
                        <div class="p-4 sm:p-6 pb-3 sm:pb-4 space-y-3 sm:space-y-4">
                        <!-- Profil Pegawai ringkas -->
                        <div
                            class="flex items-center gap-3 sm:gap-4 bg-gray-50/80 p-3 sm:p-4 rounded-xl border border-gray-100"
                        >
                            <div
                                class="w-11 h-11 sm:w-14 sm:h-14 rounded-full bg-green-600 flex items-center justify-center text-white font-bold text-xl sm:text-2xl uppercase shadow-sm shrink-0"
                            >
                                {{ selectedPegawai.nama?.charAt(0) || "?" }}
                            </div>
                            <div class="min-w-0">
                                <h4
                                    class="font-bold text-gray-900 text-base sm:text-lg leading-snug line-clamp-2"
                                >
                                    {{ selectedPegawai.nama }}
                                </h4>
                                <p
                                    class="text-xs text-gray-500 mt-0.5 truncate"
                                >
                                    NIP: {{ selectedPegawai.nip || "-" }}
                                    &middot;
                                    {{
                                        selectedPegawai.jabatan ||
                                        "Staf / Pegawai"
                                    }}
                                </p>
                            </div>
                        </div>

                        <!--
              Kartu Ringkasan Modal — DISERAGAMKAN menjadi 4 KPI (Hak →
              Terpakai → Sisa → Saldo Akhir {{ currentYear }}), persis
              struktur & urutan yang sudah dipakai di panel Atasan &
              Karyawan, supaya tidak ada lagi beda istilah antar level.
              Rincian Sisa N-2/N-1/Bawaan Eligible tetap bisa dilihat admin
              langsung di tabel utama di belakang modal ini (tidak
              dihapus), dan breakdown Hak + Saldo Bawaan Eligible tetap
              ditampilkan lewat kotak perhitungan Saldo Akhir di bawah
              kartu.
            -->
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 sm:gap-3">
                            <!-- KPI 1: Hak {{ currentYear }} -->
                            <div
                                class="bg-white p-2.5 sm:p-3 rounded-xl border border-gray-200 shadow-sm text-center flex flex-col justify-center"
                            >
                                <p
                                    class="text-[10px] font-bold text-gray-400 uppercase tracking-wider"
                                >
                                    Hak {{ currentYear }}
                                </p>
                                <p
                                    class="text-xl sm:text-2xl font-black text-gray-800 mt-1"
                                >
                                    {{ getSisaTahunIni(selectedPegawai) }}
                                    <span class="text-xs font-normal"
                                        >hari</span
                                    >
                                </p>
                            </div>

                            <!-- KPI 2: Terpakai {{ currentYear }} (Rose) -->
                            <div
                                class="bg-rose-50 p-2.5 sm:p-3 rounded-xl border border-rose-100 shadow-sm text-center flex flex-col justify-center"
                            >
                                <p
                                    class="text-[10px] font-bold text-rose-500 uppercase tracking-wider"
                                >
                                    Terpakai {{ currentYear }}
                                </p>
                                <p
                                    class="text-xl sm:text-2xl font-black text-rose-600 mt-1"
                                >
                                    {{ selectedPegawai.cuti_terpakai || 0 }}
                                    <span class="text-xs font-normal"
                                        >hari</span
                                    >
                                </p>
                            </div>

                            <!-- KPI 3: Sisa {{ currentYear }} (Biru) -->
                            <div
                                class="bg-blue-50 p-2.5 sm:p-3 rounded-xl border border-blue-100 shadow-sm text-center flex flex-col justify-center"
                            >
                                <p
                                    class="text-[10px] font-bold text-blue-500 uppercase tracking-wider"
                                >
                                    Sisa {{ currentYear }}
                                </p>
                                <p
                                    class="text-xl sm:text-2xl font-black text-blue-600 mt-1"
                                >
                                    {{ getSisaTahunBerjalan(selectedPegawai) }}
                                    <span class="text-xs font-normal"
                                        >hari</span
                                    >
                                </p>
                            </div>

                            <!-- KPI 4: Saldo Akhir {{ currentYear }} (Hitam Solid —
                                 konsisten dengan badge Saldo Akhir di tabel utama
                                 & dengan panel Atasan/Karyawan) -->
                            <div
                                class="bg-slate-800 border border-slate-900 text-white p-2.5 sm:p-3 rounded-xl shadow-md text-center flex flex-col justify-center"
                            >
                                <p
                                    class="text-[10px] font-bold text-slate-400 uppercase tracking-wider"
                                >
                                    Saldo Akhir {{ currentYear }}
                                </p>
                                <p class="text-xl sm:text-2xl font-black text-white mt-1">
                                    {{ getSaldoAkhir(selectedPegawai) }}
                                    <span class="text-xs font-normal"
                                        >hari</span
                                    >
                                </p>
                            </div>
                        </div>

                        </div>
                        <!-- ================= END PROFIL + KPI ================= -->

                        <!-- TAB: Kelola Saldo | Riwayat.
                             Label dipendekkan di HP ("Saldo"), lengkap di layar
                             lebar. Angka pada tab Riwayat = jumlah seluruh
                             riwayat (tidak dipotong). -->
                        <div
                            class="sticky top-0 z-10 bg-white border-b border-gray-200 px-4 sm:px-6"
                            role="tablist"
                        >
                            <div class="flex gap-1">
                                <button
                                    type="button"
                                    role="tab"
                                    :aria-selected="modalTab === 'saldo'"
                                    @click="modalTab = 'saldo'"
                                    class="flex-1 sm:flex-none px-4 py-3 text-sm font-semibold border-b-2 -mb-px transition cursor-pointer"
                                    :class="
                                        modalTab === 'saldo'
                                            ? 'border-green-600 text-green-700'
                                            : 'border-transparent text-gray-500 hover:text-gray-700'
                                    "
                                >
                                    <span class="sm:hidden">Saldo</span>
                                    <span class="hidden sm:inline"
                                        >Kelola Saldo</span
                                    >
                                </button>
                                <button
                                    type="button"
                                    role="tab"
                                    :aria-selected="modalTab === 'riwayat'"
                                    @click="modalTab = 'riwayat'"
                                    class="flex-1 sm:flex-none px-4 py-3 text-sm font-semibold border-b-2 -mb-px transition cursor-pointer"
                                    :class="
                                        modalTab === 'riwayat'
                                            ? 'border-green-600 text-green-700'
                                            : 'border-transparent text-gray-500 hover:text-gray-700'
                                    "
                                >
                                    Riwayat
                                    <span
                                        class="ml-1 inline-block min-w-[20px] px-1.5 py-0.5 rounded-full bg-gray-100 text-gray-600 text-[11px] font-bold"
                                        >{{
                                            selectedPegawai.pengajuan_cuti
                                                ?.length || 0
                                        }}</span
                                    >
                                </button>
                            </div>
                        </div>

                        <!-- ISI TAB -->
                        <div class="p-4 sm:p-6 space-y-4 sm:space-y-6">
                        <template v-if="modalTab === 'saldo'">

                        <!-- Kotak Cara Perhitungan Saldo Akhir — DISERAGAMKAN
                         dengan panel Atasan: satu baris ringkas
                         "Saldo Akhir {tahun}: Hak X + Bawaan Y - Terpakai Z = N hari".
                         Nilai Hak, Bawaan (Saldo Bawaan Eligible), Terpakai, dan
                         hasil akhir semuanya memakai fungsi yang sama dengan
                         kartu KPI & tabel utama, jadi angkanya selalu konsisten.
                         Breakdown Hak + Bawaan TETAP ADA, hanya format tampilannya
                         yang diringkas. -->
                        <div
                            class="bg-gray-50 px-4 py-3 rounded-lg text-xs text-gray-600 border border-gray-100"
                        >
                            Saldo Akhir {{ currentYear }}:
                            <span class="font-semibold text-gray-800"
                                >Hak {{ getSisaTahunIni(selectedPegawai) }}</span
                            >
                            +
                            <span class="font-semibold text-gray-800"
                                >Bawaan
                                {{ getSaldoBawaanEligible(selectedPegawai) }}</span
                            >
                            -
                            <span class="font-semibold text-gray-800"
                                >Terpakai
                                {{ selectedPegawai.cuti_terpakai || 0 }}</span
                            >
                            =
                            <span class="font-bold text-green-700"
                                >{{ getSaldoAkhir(selectedPegawai) }} hari</span
                            >
                        </div>
                        <!-- ================= END KARTU RINGKASAN MODAL ================= -->

                        <!-- ========================================== -->
                        <!-- FITUR BARU: EDIT SALDO PERORANGAN          -->
                        <!-- Satu-satunya tempat mengubah kuota/saldo    -->
                        <!-- cuti pegawai (dipindah dari Kelola Pegawai  -->
                        <!-- supaya tidak ada dua pintu edit untuk data  -->
                        <!-- yang sama).                                 -->
                        <!-- ========================================== -->
                        <div
                            class="rounded-xl border border-emerald-200 bg-emerald-50/60 p-3 sm:p-4"
                        >
                            <div
                                class="flex items-center justify-between mb-1"
                            >
                                <p
                                    class="text-sm font-bold text-emerald-900"
                                >
                                    Edit Saldo Perorangan
                                </p>
                                <span
                                    class="text-[10px] font-bold text-emerald-700 bg-emerald-100 border border-emerald-300 rounded-full px-2 py-0.5 uppercase tracking-wide"
                                >
                                    Admin HR
                                </span>
                            </div>
                            <p class="text-xs text-emerald-700/80 mb-3">
                                Perubahan ini berlaku untuk tahun
                                {{ currentYear }}.
                            </p>

                            <form
                                @submit.prevent="simpanSaldo"
                                class="space-y-3"
                            >
                                <!-- Hak tahun berjalan (jatah tahunan) -->
                                <div
                                    class="rounded-lg border border-blue-200 bg-blue-50/70 p-2.5"
                                >
                                    <label
                                        class="block text-xs font-semibold text-blue-800 mb-1"
                                    >
                                        Hak Cuti {{ currentYear }} (Jatah
                                        Tahunan)
                                    </label>
                                    <input
                                        type="number"
                                        inputmode="numeric"
                                        v-model="saldoForm.hak_tahun_ini"
                                        min="0"
                                        class="w-full text-base sm:text-sm border border-blue-200 rounded-md px-3 py-2.5 sm:py-2 bg-white focus:ring-blue-500 focus:border-blue-500"
                                    />
                                    <p
                                        v-if="saldoForm.errors.hak_tahun_ini"
                                        class="text-[11px] text-red-600 mt-1"
                                    >
                                        {{ saldoForm.errors.hak_tahun_ini }}
                                    </p>
                                </div>

                                <div
                                    class="grid grid-cols-2 gap-3"
                                >
                                    <!-- Sisa N-1 (carry forward) -->
                                    <div>
                                        <label
                                            class="block text-xs font-semibold text-emerald-800 mb-1"
                                        >
                                            Sisa Cuti {{ currentYear - 1 }}
                                        </label>
                                        <input
                                            type="number"
                                        inputmode="numeric"
                                            v-model="
                                                saldoForm.carry_forward_normal
                                            "
                                            min="0"
                                            max="12"
                                            class="w-full text-base sm:text-sm border border-emerald-200 rounded-md px-3 py-2.5 sm:py-2 bg-white focus:ring-emerald-500 focus:border-emerald-500"
                                        />
                                        <p
                                            v-if="
                                                saldoForm.errors
                                                    .carry_forward_normal
                                            "
                                            class="text-[11px] text-red-600 mt-1"
                                        >
                                            {{
                                                saldoForm.errors
                                                    .carry_forward_normal
                                            }}
                                        </p>
                                    </div>

                                    <!-- Ditangguhkan N-1 -->
                                    <div>
                                        <label
                                            class="block text-xs font-semibold text-orange-700 mb-1"
                                        >
                                            Ditangguhkan {{ currentYear - 1 }}
                                        </label>
                                        <input
                                            type="number"
                                        inputmode="numeric"
                                            v-model="
                                                saldoForm.sisa_ditangguhkan_tahun_lalu
                                            "
                                            min="0"
                                            max="12"
                                            class="w-full text-base sm:text-sm border border-orange-200 rounded-md px-3 py-2.5 sm:py-2 bg-orange-50/60 focus:ring-orange-500 focus:border-orange-500"
                                        />
                                        <p
                                            v-if="
                                                saldoForm.errors
                                                    .sisa_ditangguhkan_tahun_lalu
                                            "
                                            class="text-[11px] text-red-600 mt-1"
                                        >
                                            {{
                                                saldoForm.errors
                                                    .sisa_ditangguhkan_tahun_lalu
                                            }}
                                        </p>
                                    </div>

                                    <!-- Sisa N-2 -->
                                    <div>
                                        <label
                                            class="block text-xs font-semibold text-emerald-800 mb-1"
                                        >
                                            Sisa Cuti {{ currentYear - 2 }}
                                        </label>
                                        <input
                                            type="number"
                                        inputmode="numeric"
                                            v-model="
                                                saldoForm.sisa_cuti_dua_tahun_lalu
                                            "
                                            min="0"
                                            max="12"
                                            class="w-full text-base sm:text-sm border border-emerald-200 rounded-md px-3 py-2.5 sm:py-2 bg-white focus:ring-emerald-500 focus:border-emerald-500"
                                        />
                                        <p
                                            v-if="
                                                saldoForm.errors
                                                    .sisa_cuti_dua_tahun_lalu
                                            "
                                            class="text-[11px] text-red-600 mt-1"
                                        >
                                            {{
                                                saldoForm.errors
                                                    .sisa_cuti_dua_tahun_lalu
                                            }}
                                        </p>
                                    </div>

                                    <!-- Ditangguhkan N-2 -->
                                    <div>
                                        <label
                                            class="block text-xs font-semibold text-orange-700 mb-1"
                                        >
                                            Ditangguhkan {{ currentYear - 2 }}
                                        </label>
                                        <input
                                            type="number"
                                        inputmode="numeric"
                                            v-model="
                                                saldoForm.sisa_ditangguhkan_dua_tahun_lalu
                                            "
                                            min="0"
                                            max="12"
                                            class="w-full text-base sm:text-sm border border-orange-200 rounded-md px-3 py-2.5 sm:py-2 bg-orange-50/60 focus:ring-orange-500 focus:border-orange-500"
                                        />
                                        <p
                                            v-if="
                                                saldoForm.errors
                                                    .sisa_ditangguhkan_dua_tahun_lalu
                                            "
                                            class="text-[11px] text-red-600 mt-1"
                                        >
                                            {{
                                                saldoForm.errors
                                                    .sisa_ditangguhkan_dua_tahun_lalu
                                            }}
                                        </p>
                                    </div>
                                </div>

                                <!-- Petunjuk singkat (menggantikan kotak pratinjau) -->
                                <p class="text-[11px] text-gray-500">
                                    *Ditangguhkan hanya diisi jika ada
                                    penangguhan resmi. Saldo Bawaan Eligible,
                                    Total Tersedia, dan Saldo Akhir dihitung
                                    otomatis dari isian di atas.
                                </p>

                                <div class="flex gap-2 sm:justify-end">
                                    <button
                                        type="button"
                                        @click="resetSaldoForm"
                                        :disabled="
                                            isSavingSaldo || !saldoForm.isDirty
                                        "
                                        class="flex-1 sm:flex-none px-5 py-2.5 sm:py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 text-sm font-semibold rounded-full transition disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer"
                                    >
                                        Reset
                                    </button>
                                    <button
                                        type="submit"
                                        :disabled="
                                            isSavingSaldo || !saldoForm.isDirty
                                        "
                                        class="flex-1 sm:flex-none justify-center inline-flex items-center gap-2 px-5 py-2.5 sm:py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-full shadow-sm transition disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer"
                                    >
                                        <svg
                                            xmlns="http://www.w3.org/2000/svg"
                                            class="w-4 h-4"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                            stroke="currentColor"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                stroke-width="2"
                                                d="M5 13l4 4L19 7"
                                            />
                                        </svg>
                                        {{
                                            isSavingSaldo
                                                ? "Menyimpan..."
                                                : "Simpan Saldo"
                                        }}
                                    </button>
                                </div>
                            </form>
                        </div>
                        <!-- ================= END FITUR EDIT SALDO PERORANGAN ================= -->

                        <!--
              Sisa Ditangguhkan: HANYA tampil jika ada nilai > 0.
              Ini realisasi kesepakatan diskusi — info penangguhan resmi
              dipindah ke sini (bukan kolom tabel utama) karena jarang
              terisi dan supaya tabel tetap ringkas. Nilainya kini bisa
              diubah lewat isian "Ditangguhkan" di form "Edit Saldo
              Perorangan" di atas; blok ini tetap dipertahankan sebagai
              ringkasan + catatan_saldo untuk data yang sudah tersimpan.
            -->
                        <div
                            v-if="punyaSisaDitangguhkan(selectedPegawai)"
                            class="rounded-xl border border-orange-200 bg-orange-50 p-4"
                        >
                            <p
                                class="text-[11px] font-bold uppercase tracking-wider text-orange-700 mb-2"
                            >
                                Sisa Ditangguhkan (penangguhan resmi)
                            </p>
                            <div class="grid grid-cols-2 gap-3 text-sm">
                                <div>
                                    <span class="text-xs text-orange-600/80"
                                        >Ditangguhkan
                                        {{ currentYear - 2 }}</span
                                    >
                                    <p
                                        class="font-bold text-orange-900 text-lg"
                                    >
                                        {{
                                            getSisaDitangguhkanN2(
                                                selectedPegawai,
                                            )
                                        }}
                                        Hari
                                    </p>
                                </div>
                                <div>
                                    <span class="text-xs text-orange-600/80"
                                        >Ditangguhkan
                                        {{ currentYear - 1 }}</span
                                    >
                                    <p
                                        class="font-bold text-orange-900 text-lg"
                                    >
                                        {{
                                            getSisaDitangguhkanN1(
                                                selectedPegawai,
                                            )
                                        }}
                                        Hari
                                    </p>
                                </div>
                            </div>
                            <p
                                v-if="selectedPegawai.catatan_saldo"
                                class="mt-2 text-xs text-orange-800/80 italic"
                            >
                                {{ selectedPegawai.catatan_saldo }}
                            </p>
                        </div>

                        </template>

                        <!-- TAB RIWAYAT: menampilkan SEMUA riwayat (tidak dipotong) -->
                        <template v-if="modalTab === 'riwayat'">
                        <!-- Bagian Riwayat Pengajuan -->
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

                            <!-- Kosong -->
                            <div
                                v-if="
                                    !selectedPegawai.pengajuan_cuti ||
                                    selectedPegawai.pengajuan_cuti.length === 0
                                "
                                class="text-center py-6 bg-gray-50 rounded-xl border border-dashed border-gray-200 text-xs text-gray-400"
                            >
                                Belum ada catatan riwayat pengajuan cuti.
                            </div>

                            <!-- List riwayat, garis warna kiri sesuai status asli
                             (disetujui / menunggu_lX / ditolak /
                             dibatalkan_ditangguhkan) -->
                            <div v-else class="space-y-2.5">
                                <div
                                    v-for="riwayat in selectedPegawai.pengajuan_cuti"
                                    :key="riwayat.id"
                                    @click="toggleExpand(riwayat.id)"
                                    class="bg-white p-3 sm:p-4 rounded-xl border border-gray-200/80 text-sm flex flex-col gap-2.5 transition-colors duration-150 hover:shadow-sm cursor-pointer relative overflow-hidden group"
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
                                    <!-- Baris atas: Jenis Cuti & Badge Status -->
                                    <div
                                        class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-1.5"
                                    >
                                        <span
                                            class="font-bold text-gray-900 text-base group-hover:text-green-700 transition"
                                            >{{ riwayat.jenis_cuti }}</span
                                        >
                                        <div class="flex items-start sm:items-center justify-between sm:justify-start gap-2">
                                            <span
                                                class="px-2.5 py-1 rounded-md font-bold text-xs tracking-wide uppercase min-w-0 break-words"
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

                                    <!-- Tanggal & Durasi -->
                                    <div
                                        class="text-gray-600 flex flex-wrap items-center gap-x-2 gap-y-0.5 font-medium"
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

                                    <!-- Keterangan / Alasan -->
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

                                    <!-- ========================================== -->
                                    <!-- AREA RINCIAN YANG MUNCUL SAAT KARTU DIKLIK -->
                                    <!-- ========================================== -->
                                    <div
                                        v-if="expandedRiwayatId === riwayat.id"
                                        @click.stop
                                        class="mt-1 pt-3 border-t-2 border-dashed border-green-200 bg-green-50/40 -mx-3 sm:-mx-4 -mb-3 sm:-mb-4 p-3 sm:p-4 rounded-b-xl space-y-3 text-xs"
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

                                        <!-- Alamat & kontak selama cuti — hanya tampil kalau field-nya
                                         memang dikirim dari controller; kalau belum ada di data,
                                         tampil '-' agar tidak error. -->
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

                                        <!-- Lampiran file / surat -->
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

                                        <!-- Jejak Approval LENGKAP: selalu tampilkan seluruh
                                         jenjang L1-L4 sesuai rantai role pemohon, dengan status
                                         masing-masing (Setuju / Tolak / Menunggu / Belum Giliran),
                                         konsisten di SEMUA status pengajuan (disetujui, menunggu_lX,
                                         ditolak, dibatalkan/ditangguhkan, dibatalkan reguler). -->
                                        <div
                                            v-if="
                                                riwayat.approval_chain &&
                                                riwayat.approval_chain.length >
                                                    0
                                            "
                                            class="bg-white p-3 rounded-lg border border-green-100 space-y-1"
                                        >
                                            <div
                                                v-for="(
                                                    step, idx
                                                ) in riwayat.approval_chain"
                                                :key="
                                                    step.level ??
                                                    `tangguh-${idx}`
                                                "
                                                class="flex items-start sm:items-center gap-1.5 text-gray-600"
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
                                                <span class="flex-1 min-w-0 break-words">
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
                                                    class="font-bold uppercase text-[11px] shrink-0"
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
                        </template>
                        </div>
                        <!-- ================= END ISI TAB ================= -->
                    </div>

                    <!-- Footer Modal -->
                    <div
                        class="border-t px-4 sm:px-6 pt-3 sm:pt-4 pb-[calc(0.75rem+env(safe-area-inset-bottom))] sm:pb-4 bg-gray-50 sm:rounded-b-2xl flex justify-end shrink-0"
                    >
                        <button
                            @click="closeModal"
                            type="button"
                            class="w-full sm:w-auto px-6 py-2.5 bg-gray-800 hover:bg-gray-700 text-white text-sm font-semibold rounded-xl transition shadow-sm cursor-pointer"
                        >
                            Tutup Rincian
                        </button>
                    </div>
                </div>
            </div>

            <!-- ========================================== -->
            <!-- MODAL IMPOR KUOTA CUTI (FITUR BARU)        -->
            <!-- ========================================== -->
            <div
                v-if="isImportModalOpen"
                class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/60 p-4 backdrop-blur-xs overflow-y-auto"
            >
                <!-- Background klik-untuk-tutup -->
                <div class="absolute inset-0" @click="closeImportModal"></div>

                <div
                    class="relative bg-white p-6 rounded-2xl w-full max-w-md shadow-2xl max-h-[85vh] overflow-y-auto my-auto"
                >
                    <div
                        class="flex justify-between items-center mb-5 border-b pb-3"
                    >
                        <h3 class="text-lg font-bold text-gray-800">
                            Impor Kuota Cuti
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
                            Unggah file Excel (.xlsx) atau CSV berisi data kuota
                            cuti pegawai untuk diperbarui secara massal.
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
                                class="block w-full text-sm text-gray-600 border border-gray-300 rounded-md cursor-pointer focus:outline-none file:mr-3 file:py-2 file:px-4 file:border-0 file:bg-blue-50 file:text-blue-700 file:font-semibold hover:file:bg-blue-100"
                            />
                        </div>

                        <p
                            v-if="importForm.errors.file"
                            class="text-sm text-red-600 mb-2"
                        >
                            {{ importForm.errors.file }}
                        </p>

                        <p class="text-xs text-gray-400 mb-5">
                            Pastikan kolom mengikuti template Excel (NIP, Sisa
                            {{ currentYear - 1 }}, Saldo Bawaan Eligible, Hak
                            {{ currentYear }}, Terpakai, Total Tersedia, dsb).
                        </p>

                        <!-- TOMBOL AKSI -->
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
                                class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 cursor-pointer transition disabled:opacity-50 disabled:cursor-not-allowed"
                                :disabled="
                                    importForm.processing || !importForm.file
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

            <!-- ========================================== -->
            <!-- MODAL GENERATE SALDO TAHUN BARU (MANUAL) — DIREKONSTRUKSI -->
            <!-- Memanggil route 'admin.saldo.generate' yang sudah ada    -->
            <!-- sebelumnya di AdminController::generateSaldoManual(),    -->
            <!-- yang di baliknya menjalankan Artisan Command             -->
            <!-- 'saldo-cuti:generate-tahun-baru' (sama persis dengan     -->
            <!-- scheduler otomatis tiap 1 Januari).                      -->
            <!-- ========================================== -->
            <div
                v-if="isGenerateSaldoModalOpen"
                class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/60 p-4 backdrop-blur-xs overflow-y-auto"
            >
                <!-- Background klik-untuk-tutup -->
                <div
                    class="absolute inset-0"
                    @click="closeGenerateSaldoModal"
                ></div>

                <div
                    class="relative bg-white p-6 rounded-2xl w-full max-w-md shadow-2xl max-h-[85vh] overflow-y-auto my-auto"
                >
                    <div
                        class="flex justify-between items-center mb-4 border-b pb-3"
                    >
                        <h3 class="text-lg font-bold text-gray-800">
                            Generate Saldo Tahun Baru
                        </h3>
                        <button
                            type="button"
                            @click="closeGenerateSaldoModal"
                            class="text-gray-400 hover:text-red-500 font-bold text-2xl cursor-pointer outline-none"
                        >
                            &times;
                        </button>
                    </div>

                    <form @submit.prevent="submitGenerateSaldo">
                        <p class="text-sm text-gray-600 mb-4">
                            Ini akan menghitung ulang carry-over saldo cuti
                            untuk
                            <strong>semua pegawai</strong>
                            pada tahun di bawah, memakai logika yang sama
                            persis dengan generate otomatis tiap 1 Januari
                            (carry-over dari sisa saldo biasa maks 6 hari,
                            ditambah hari dari penangguhan resmi yang belum
                            dipakai, dibatasi total maks 24 hari). Tindakan
                            ini aman diulang — pegawai yang datanya sudah
                            benar tidak akan rusak.
                        </p>

                        <label
                            class="block text-sm font-medium text-gray-700 mb-1"
                        >
                            Tahun yang digenerate
                        </label>
                        <input
                            type="number"
                            v-model="generateSaldoForm.tahun"
                            class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm mb-2 focus:ring-emerald-500 focus:border-emerald-500"
                        />
                        <p
                            v-if="generateSaldoForm.errors.tahun"
                            class="text-sm text-red-600 mb-2"
                        >
                            {{ generateSaldoForm.errors.tahun }}
                        </p>

                        <div
                            class="flex justify-end space-x-2 pt-4 border-t border-gray-100 mt-3"
                        >
                            <button
                                type="button"
                                @click="closeGenerateSaldoModal"
                                class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 cursor-pointer transition"
                            >
                                Batal
                            </button>
                            <button
                                type="submit"
                                :disabled="generateSaldoForm.processing"
                                class="px-4 py-2 bg-emerald-600 text-white rounded-md hover:bg-emerald-700 cursor-pointer transition disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                {{
                                    generateSaldoForm.processing
                                        ? "Memproses..."
                                        : "Generate Sekarang"
                                }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <!-- end div pembungkus tunggal -->
        </div>  
    </MainLayout>
</template>