<script setup>
import { ref, computed, watch, nextTick } from "vue";
import { router, useForm, Head } from "@inertiajs/vue3";
import MainLayout from "@/Layouts/MainLayout.vue";
import { useTahunFilter } from "@/composables/useTahunFilter";

// Tahun kalender sebenarnya (hari ini). Dipakai sebagai default filter tahun
// dan sebagai dasar tahun "berikutnya" untuk fitur Generate Saldo Tahun Baru.
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
    Number(pegawai?.kuota_tahunan ?? pegawai?.hak_tahun_ini ?? 0);

const getSisaTahunLalu = (pegawai) =>
    Number(pegawai?.carry_forward_normal ?? 0);

// ================================================================
// Koreksi Cuti Terpakai (INPUT LANGSUNG / OVERRIDE) — Admin HR mengetik
// TOTAL CUTI TERPAKAI FINAL, bukan selisih plus/minus. Nilai dikirim
// backend lewat field 'koreksi_saldo'
// (RekapKuotaDetailController::hitungKuota()).
//
// Aturan di backend:
//   - koreksi_saldo = null -> Terpakai = angka sistem (riwayat disetujui)
//   - koreksi_saldo = 0..n -> Terpakai = koreksi_saldo (angka Admin dipakai
//                             apa adanya, TERMASUK 0)
//
// PENTING: 0 adalah koreksi yang SAH ("tidak ada cuti yang benar-benar
// terpakai"). Jadi JANGAN pernah mengecek koreksi dengan `> 0`, `!k`,
// atau `k || ...`. Selalu bedakan null dan 0 lewat `=== null`.
//
// Contoh: sistem mencatat 3 hari. Admin ketik 0 -> terpakai 0. Ketik 4 ->
// terpakai 4. Centang "Gunakan data dari sistem" untuk kembali ke angka
// sistem.
// ================================================================
const getKoreksiSaldo = (pegawai) => {
    const k = pegawai?.koreksi_saldo;
    return k === null || k === undefined ? null : Math.max(0, Number(k) || 0);
};

// Ada override dari Admin HR atau tidak. Koreksi 0 juga dihitung override.
const adaKoreksi = (pegawai) => getKoreksiSaldo(pegawai) !== null;

// Angka cuti terpakai MURNI dari sistem (riwayat pengajuan disetujui),
// dipakai untuk keterangan "Cuti terpakai dari sistem: X hari".
const getTerpakaiSistem = (pegawai) =>
    Number(
        pegawai?.cuti_terpakai_sistem ?? pegawai?.cuti_terpakai_murni ?? 0,
    ) || 0;

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
    // 'cuti_terpakai' dari backend SUDAH final (angka override Admin bila
    // ada, kalau tidak angka sistem), jadi koreksi TIDAK boleh
    // ditambahkan/dikurangkan lagi di sini.
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
// Sisa ditangguhkan — tampil di tabel utama & modal Detail.
// Nilainya dihitung backend dari tabel cuti_ditangguhkans.
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
// dirender kalau salah satu nilainya > 0.
const punyaSisaDitangguhkan = (pegawai) =>
    getSisaDitangguhkanN2(pegawai) > 0 || getSisaDitangguhkanN1(pegawai) > 0;

// Menerima 'dataPegawai' (satu baris per pegawai), bukan 'pengajuan'
const props = defineProps({
    dataPegawai: Object,
    filters: Object,
    daftarKelompokSubstansi: {
        type: Array,
        default: () => [],
    },
    // Daftar tahun yang bisa dipilih di Filter Tahun (dikirim backend).
    // Kalau kosong, frontend memakai daftar standar dari composable
    // useTahunFilter (Tahun Sekarang +1 / 0 / -1 / -2).
    daftarTahun: {
        type: Array,
        default: () => [],
    },
    // KPI ringkasan (Total Pegawai, Rata-rata Terpakai, Rata-rata Saldo
    // Akhir, Akumulasi Penuh) — dikirim LANGSUNG dari backend
    // (RekapKuotaDetailController::hitungKpi()), dihitung dari SELURUH
    // data hasil filter, bukan hanya 10 baris di halaman aktif.
    kpi: {
        type: Object,
        default: () => ({}),
    },
});

const search = ref(props.filters?.search || "");
const filterKelompok = ref(props.filters?.kelompok_substansi || "");

// ================================================================
// FILTER TAHUN
// Default = tahun berjalan (atau tahun yang dikembalikan backend lewat
// props.filters.tahun, misalnya setelah reload/pagination).
//
// 'tahunAktif' adalah SATU-SATUNYA sumber tahun untuk seluruh label di
// halaman ini (header kolom, KPI modal, form edit saldo, dll).
// ================================================================
const filterTahun = ref(Number(props.filters?.tahun) || currentYear);

const tahunAktif = computed(() => Number(filterTahun.value) || currentYear);

// Daftar tahun standar dari composable (satu sumber logika untuk semua
// halaman). Default: tahun berjalan +1 sampai -2
// (di 2026: 2027, 2026, 2025, 2024).
const daftarTahunDefault = useTahunFilter();

// Daftar opsi tahun (urut terbaru -> terlama). Memakai props.daftarTahun
// bila backend mengirimnya; bila tidak, memakai daftarTahunDefault.
// Tahun yang sedang aktif selalu dijamin ada di daftar.
const daftarTahunPilihan = computed(() => {
    const dariBackend = (props.daftarTahun || [])
        .map((t) => Number(t))
        .filter((t) => Number.isFinite(t) && t > 0);

    const daftar =
        dariBackend.length > 0 ? dariBackend : daftarTahunDefault.value;

    return [...new Set([...daftar, tahunAktif.value])].sort((a, b) => b - a);
});

// URL Export Excel — ikut mengekspor data sesuai tahun (dan filter
// pencarian/kelompok substansi) yang sedang aktif.
const exportUrl = computed(() =>
    route("admin.monitoring.export", {
        tahun: tahunAktif.value,
        search: search.value || undefined,
        kelompok_substansi: filterKelompok.value || undefined,
    }),
);

// ================================================================
// KPI Cards ringkasan — DIAMBIL LANGSUNG dari prop 'kpi' backend,
// supaya nilainya mencerminkan SELURUH data hasil filter.
// ================================================================
const daftarPegawaiHalamanIni = computed(() => props.dataPegawai?.data || []);

const kpiTotalPegawai = computed(() => props.kpi?.total_pegawai ?? 0);

const kpiRataRataTerpakai = computed(() => props.kpi?.rata_rata_terpakai ?? 0);

const kpiRataRataSaldoAkhir = computed(
    () => props.kpi?.rata_rata_saldo_akhir ?? 0,
);

const kpiAkumulasiPenuh = computed(() => props.kpi?.akumulasi_penuh ?? 0);
// ================= END KPI CARDS =================

// State untuk Modal Detail Riwayat Cuti
const showModal = ref(false);
const selectedPegawai = ref(null);

// Menyimpan ID riwayat cuti yang sedang di-expand (dibuka rinciannya)
const expandedRiwayatId = ref(null);

// ========================================== //
// ---> FITUR: EDIT SALDO CUTI PERORANGAN <---
// ========================================== //
// Satu-satunya tempat mengubah kuota/saldo cuti pegawai (single source
// of truth). Isian sejajar dengan kolom sumber di sheet "Saldo Tahunan"
// Excel, supaya input manual & Impor Kuota menghasilkan data yang sama:
//   - hak_tahun_ini                     -> Hak {tahun} (jatah tahunan)
//   - pakai_sistem + koreksi_saldo      -> Koreksi Jumlah Cuti Terpakai
//                                          (angka FINAL, bukan +/-)
//   - carry_forward_normal              -> Sisa {tahun-1}
//   - sisa_cuti_dua_tahun_lalu          -> Sisa {tahun-2}
// Saldo Bawaan Eligible, Total Tersedia & Saldo Akhir dihitung otomatis.
//
// Saat disimpan, form ikut mengirim 'tahun' (= tahunAktif) lewat
// transform di simpanSaldo(). 'tahun' tidak dimasukkan ke field form
// agar isDirty hanya bereaksi pada isian yang benar-benar diubah admin.
//
// KOREKSI CUTI TERPAKAI (checkbox "Gunakan data dari sistem"):
//   - pakai_sistem = true  -> koreksi_saldo dikirim null (ikut angka sistem)
//   - pakai_sistem = false -> angka di kotak dikirim apa adanya, TERMASUK 0
// Nilai LAMA ditimpa (bukan diakumulasi): mengubah 3 -> 4 berarti
// Terpakai = 4.
//
// CATATAN: 'Ditangguhkan' TIDAK ada di form ini. Angkanya hanya
// ditampilkan (baca saja) dari tabel cuti_ditangguhkans, yang diisi
// otomatis oleh alur Penangguhan Cuti.
const saldoForm = useForm({
    hak_tahun_ini: 0,
    pakai_sistem: true,
    koreksi_saldo: "",
    carry_forward_normal: 0,
    sisa_cuti_dua_tahun_lalu: 0,
});

const isSavingSaldo = computed(() => saldoForm.processing);

// Pesan sukses singkat di dalam modal setelah Simpan Saldo (modal tidak
// ditutup otomatis).
const pesanSukses = ref("");

// Nilai dari data pegawai dijadikan "defaults" form, sehingga
// saldoForm.isDirty hanya true kalau admin benar-benar mengubah isian.
// Tanpa koreksi (null): checkbox tercentang dan kotak menampilkan angka
// sistem. Dengan koreksi (termasuk 0): checkbox mati dan kotak berisi
// angka koreksi.
const isiSaldoFormDariPegawai = (pegawai) => {
    const koreksi = getKoreksiSaldo(pegawai);
    saldoForm.defaults({
        hak_tahun_ini: getSisaTahunIni(pegawai),
        pakai_sistem: koreksi === null,
        koreksi_saldo: koreksi ?? getTerpakaiSistem(pegawai),
        carry_forward_normal: getSisaTahunLalu(pegawai),
        sisa_cuti_dua_tahun_lalu: getSisaDuaTahunLalu(pegawai),
    });
    saldoForm.reset();
};

// Saat checkbox dicentang, kotak kembali menampilkan angka sistem supaya
// tidak ada angka koreksi lama yang tertinggal di layar.
watch(
    () => saldoForm.pakai_sistem,
    (dicentang) => {
        if (dicentang && selectedPegawai.value) {
            saldoForm.koreksi_saldo = getTerpakaiSistem(selectedPegawai.value);
            saldoForm.clearErrors("koreksi_saldo");
        }
    },
);

// Kembalikan isian ke data tersimpan tanpa menutup modal.
const resetSaldoForm = () => {
    saldoForm.reset();
    saldoForm.clearErrors();
};

// Setelah simpan, modal TETAP TERBUKA. Data pegawai terbaru diambil dari
// props (yang sudah diperbarui Inertia), lalu dimasukkan lagi ke
// 'selectedPegawai' supaya kartu KPI, kotak rumus, dan form ikut
// menampilkan angka terbaru.
const simpanSaldo = () => {
    if (!selectedPegawai.value || isSavingSaldo.value) return;

    const idAktif = selectedPegawai.value.id;

    saldoForm
        .transform((data) => ({
            ...data,
            // Dicentang -> null (ikut sistem).
            // Tidak dicentang -> angka apa adanya, TERMASUK 0.
            // Kotak kosong saat tidak dicentang dikirim null, lalu ditolak
            // backend dengan pesan error (tidak disimpan diam-diam jadi 0).
            koreksi_saldo: data.pakai_sistem
                ? null
                : data.koreksi_saldo === "" || data.koreksi_saldo == null
                  ? null
                  : Number(data.koreksi_saldo),
            tahun: tahunAktif.value,
        }))
        .put(route("admin.rekap-kuota.update-saldo", idAktif), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: async () => {
                // Tunggu props terbaru masuk, lalu segarkan isi modal
                await nextTick();
                const terbaru = props.dataPegawai?.data?.find(
                    (p) => p.id === idAktif,
                );
                if (terbaru) {
                    selectedPegawai.value = terbaru;
                    isiSaldoFormDariPegawai(terbaru);
                }
                pesanSukses.value =
                    "Saldo berhasil disimpan. Angka di atas sudah diperbarui.";
                setTimeout(() => (pesanSukses.value = ""), 4000);
            },
        });
};
// ================= END FITUR EDIT SALDO CUTI PERORANGAN =================

// Tab aktif di dalam popup: "saldo" (Kelola Saldo) atau "riwayat".
// Selalu mulai dari tab Kelola Saldo saat popup dibuka.
const modalTab = ref("saldo");

const openDetailModal = (pegawai) => {
    selectedPegawai.value = pegawai;
    modalTab.value = "saldo";
    expandedRiwayatId.value = null; // Reset saat buka pegawai baru
    pesanSukses.value = "";
    isiSaldoFormDariPegawai(pegawai);
    saldoForm.clearErrors();
    showModal.value = true;
};

const closeModal = () => {
    showModal.value = false;
    selectedPegawai.value = null;
    modalTab.value = "saldo";
    expandedRiwayatId.value = null;
    pesanSukses.value = "";
    saldoForm.clearErrors();
};

// Toggle buka/tutup rincian kartu riwayat
const toggleExpand = (id) => {
    expandedRiwayatId.value = expandedRiwayatId.value === id ? null : id;
};

// Kamus label status, konsisten dengan halaman Approval
const statusLabels = {
    menunggu_l1: "Menunggu Ketua Tim Kerja (L1)",
    menunggu_l2: "Menunggu Ketua Kelompok Substansi (L2)",
    menunggu_l3: "Menunggu Kasubag TU (L3)",
    menunggu_l4: "Menunggu Kepala Biro Perencanaan (L4)",
    disetujui: "Disetujui",
    ditolak: "Ditolak",
    dibatalkan_ditangguhkan: "Dibatalkan/Ditangguhkan",
    kedaluwarsa: "Kedaluwarsa",
};

const getStatusLabel = (st) => {
    return statusLabels[st] || st?.replace(/_/g, " ").toUpperCase();
};

// Label & warna untuk tiap langkah approval_chain — status per level
// L1-L4: Setuju / Tolak / Menunggu / Belum Giliran / Ditangguhkan.
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


// ================= APPROVAL CHAIN (filter tampilan) =================
// Samakan dengan RekapKuotaPegawai / MonitoringCuti:
// Kepala Biro (role 6) tidak punya rantai L1–L3.
const urutkanChain = (chain) => {
    const berlevel = chain
        .filter((step) => step?.level)
        .sort((a, b) => Number(a.level) - Number(b.level));
    let i = 0;
    return chain.map((step) => (step?.level ? berlevel[i++] : step));
};

const getVisibleApprovalChain = (chainAsli, pegawai = null) => {
    if (!Array.isArray(chainAsli)) return [];

    // Kepala Biro (L4): disetujui otomatis, tanpa rantai L1–L3
    if (Number(pegawai?.role_id) === 6) {
        const chain = urutkanChain(chainAsli);
        const onlyL4 = chain.filter(
            (step) => !step?.level || Number(step.level) === 4,
        );
        return onlyL4.length > 0 ? onlyL4 : chain;
    }

    let chain = urutkanChain(chainAsli);
    const kelompok = (pegawai?.kelompok_substansi || "").toLowerCase().trim();
    const tim = (pegawai?.tim_kerja || "").trim();
    const isTU =
        kelompok.includes("subbagian tata usaha") ||
        kelompok.includes("tata usaha");
    const tanpaTim = !tim || tim === "-";

    if (isTU || tanpaTim) {
        chain = chain.filter((step) => Number(step?.level) !== 1);
    }

    return chain;
};
// ================= END APPROVAL CHAIN =================

// Hitung durasi hari otomatis dari tanggal (fallback), dipakai kalau
// field 'jumlah_hari' kosong/tidak terisi di suatu pengajuan.
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

// Auto-search saat mengetik / mengganti filter. Ikut memantau
// 'filterTahun' dan mengirim parameter 'tahun' ke backend, sehingga
// tabel, KPI, dan Export selalu mengacu ke tahun yang dipilih. Halaman
// paginasi otomatis kembali ke halaman 1 karena 'page' tidak dikirim.
watch(
    [search, filterKelompok, filterTahun],
    debounce(function () {
        router.get(
            route("admin.monitoring"),
            {
                search: search.value,
                kelompok_substansi: filterKelompok.value,
                tahun: tahunAktif.value,
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
// Modal terpisah dari modal Detail Riwayat, dengan useForm terpisah
// karena payload-nya berupa file upload.
const isImportModalOpen = ref(false);

const importForm = useForm({
    file: null,
});

const importFileInput = ref(null);

// Banner ringkasan hasil impor (flash 'success' dari backend). Ditampilkan
// di halaman ini karena MainLayout tidak menampilkan flash.
const pesanImpor = ref("");
let timerPesanImpor = null;

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

// Route admin.monitoring.import sudah tersedia (RekapKuotaDetailController::importKuota).
const submitImportForm = () => {
    if (!importForm.file) return;

    importForm
        .transform((data) => ({ ...data, tahun: tahunAktif.value }))
        .post(route("admin.monitoring.import"), {
            forceFormData: true,
            onSuccess: (halaman) => {
                closeImportModal();
                pesanImpor.value = halaman?.props?.flash?.success || "";
                clearTimeout(timerPesanImpor);
                if (pesanImpor.value) {
                    timerPesanImpor = setTimeout(
                        () => (pesanImpor.value = ""),
                        15000,
                    );
                }
            },
        });
};

// ========================================== //
// ---> FITUR: GENERATE SALDO TAHUN BARU (MANUAL) <---
// ========================================== //
// Memanggil route 'admin.saldo.generate' (AdminController::
// generateSaldoManual), yang menjalankan Artisan Command
// 'saldo-cuti:generate-tahun-baru' — SAMA PERSIS dengan yang dijalankan
// otomatis oleh Laravel Scheduler setiap 1 Januari.
//
// Memakai modal konfirmasi karena ini aksi massal untuk SEMUA pegawai.
// Default tahun generate = 'currentYear + 1' (tahun kalender sebenarnya),
// TIDAK mengikuti Filter Tahun.
const isGenerateSaldoModalOpen = ref(false);

const generateSaldoForm = useForm({
    tahun: currentYear + 1,
});

// Tahun yang boleh digenerate. Sengaja hanya tahun depan. Kalau suatu saat
// perlu membuka tahun berjalan, cukup tambahkan currentYear ke daftar ini
// (peringatan di modal akan muncul otomatis). Batas sebenarnya tetap
// divalidasi di backend.
// [FIX] Dropdown di template sekarang BENAR-BENAR membaca array ini lewat v-for.
const pilihanTahunGenerate = [currentYear + 1];

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
        <div class="relative w-full h-full">
            <!-- [FIX] Div pembungkus tunggal: MainLayout hanya boleh punya SATU
                 elemen anak langsung agar transisi halaman Inertia/Vue tidak
                 error. Komentar ini sengaja diletakkan DI DALAM div (bukan
                 sejajar di luarnya), supaya root slot tetap satu elemen. -->
            <div class="p-6 bg-white rounded-xl shadow-sm">
                <!-- Banner hasil impor (flash 'success'). Bisa ditutup manual
                     dan hilang sendiri setelah 15 detik. -->
                <div
                    v-if="pesanImpor"
                    role="status"
                    class="mb-4 flex items-start justify-between gap-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800"
                >
                    <p class="min-w-0 break-words">{{ pesanImpor }}</p>
                    <button
                        type="button"
                        @click="pesanImpor = ''"
                        aria-label="Tutup pesan"
                        class="shrink-0 font-bold text-emerald-700 hover:text-emerald-900 cursor-pointer"
                    >
                        &times;
                    </button>
                </div>

                <!-- Baris judul: judul + subjudul di kiri, tombol aksi
                 (Impor, Generate Saldo, Export) selalu di kanan atas.

                 Skema warna tombol per jenis aksi:
                   - Impor           -> Blue
                   - Generate Saldo  -> Emerald
                   - Export          -> Hijau tua (green-700) -->
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

                    <!-- 'sm:flex-wrap sm:justify-end' supaya tiga tombol turun
                         ke baris berikutnya di layar sempit, bukan melebarkan
                         halaman. -->
                    <div
                        class="flex flex-col sm:flex-row sm:flex-wrap sm:justify-end gap-3 items-center w-full sm:w-auto"
                    >
                        <!-- Tombol Impor Kuota -->
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

                        <!-- Tombol Generate Saldo Tahun Baru (Manual) -->
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

                        <!-- Tombol Export — href memakai 'exportUrl' sehingga file
                             yang diunduh mengikuti tahun (& filter) aktif. -->
                        <a
                            :href="exportUrl"
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
                            Export Excel {{ tahunAktif }}
                        </a>
                    </div>
                </div>

                <!-- KPI Cards (versi putih). Angka diambil dari prop 'kpi'
                 backend, dihitung dari SELURUH data hasil filter. -->
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
                <!-- ================= END KPI CARDS ================= -->

                <!-- Baris search + filter: Search, Filter Tahun, Filter
                 Kelompok Substansi. -->
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

                    <!-- Filter Tahun (ikon kalender). Default = tahun berjalan. -->
                    <div class="relative shrink-0">
                        <div
                            class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"
                        >
                            <svg
                                class="w-4 h-4 text-gray-400"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"
                                />
                            </svg>
                        </div>

                        <select
                            v-model="filterTahun"
                            class="w-full sm:w-auto min-w-[130px] text-sm border border-gray-300 rounded-lg pl-9 pr-8 py-2 appearance-none bg-none bg-white focus:ring-green-500 focus:border-green-500"
                            aria-label="Filter tahun"
                        >
                            <option
                                v-for="tahun in daftarTahunPilihan"
                                :key="tahun"
                                :value="tahun"
                            >
                                Tahun {{ tahun }}
                            </option>
                        </select>

                        <span
                            class="absolute inset-y-0 right-0 flex items-center pr-2 pointer-events-none"
                        >
                            <svg
                                class="w-3.5 h-3.5 text-gray-500"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M19 9l-7 7-7-7"
                                ></path>
                            </svg>
                        </span>
                    </div>

                    <!-- Filter Kelompok Substansi (ikon funnel) — konsisten
                     dengan filter yang sama di Rekap Laporan & Monitoring
                     Cuti. -->
                    <div class="relative shrink-0">
                        <div
                            class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"
                        >
                            <svg
                                class="w-4 h-4 text-gray-400"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"
                                />
                            </svg>
                        </div>

                        <select
                            v-model="filterKelompok"
                            class="w-auto min-w-[190px] sm:min-w-[220px] text-sm border border-gray-300 rounded-lg pl-9 pr-8 py-2 appearance-none bg-none bg-white focus:ring-green-500 focus:border-green-500"
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

                        <span
                            class="absolute inset-y-0 right-0 flex items-center pr-2 pointer-events-none"
                        >
                            <svg
                                class="w-3.5 h-3.5 text-gray-500"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M19 9l-7 7-7-7"
                                ></path>
                            </svg>
                        </span>
                    </div>
                </div>

                <!--
          TABEL MONITORING: SATU BARIS PER PEGAWAI.
          Semua label tahun di header DINAMIS mengikuti Filter Tahun
          ('tahunAktif'). Setiap angka dibungkus "kapsul" warna dengan
          min-w tetap supaya sejajar; Saldo Akhir diberi background gelap
          sebagai fokus utama. Tabel punya 11 kolom.

          Tabel dibuat RAPAT agar semua kolom muat tanpa geser:
            - tanpa 'whitespace-nowrap' (header & nama boleh membungkus)
            - padding sel 'px-2 py-3' (kolom Nama 'px-3 py-3')
            - kolom Nama diberi min-w-[200px]
        -->
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr
                                class="bg-slate-50 border-b border-slate-200 text-[10px] leading-tight text-slate-500 uppercase tracking-wide font-bold align-middle"
                            >
                                <th class="px-3 py-3 min-w-[200px]">
                                    Nama Pegawai
                                </th>
                                <th class="px-2 py-3 text-center">
                                    Sisa {{ tahunAktif - 2 }}
                                </th>
                                <th class="px-2 py-3 text-center text-orange-600">
                                    Tgh '{{
                                        (tahunAktif - 2).toString().slice(-2)
                                    }}
                                </th>
                                <th class="px-2 py-3 text-center">
                                    Sisa {{ tahunAktif - 1 }}
                                </th>
                                <th class="px-2 py-3 text-center text-orange-600">
                                    Tgh '{{
                                        (tahunAktif - 1).toString().slice(-2)
                                    }}
                                </th>
                                <th class="px-2 py-3 text-center">
                                    Bawaan Eligible {{ tahunAktif }}
                                </th>
                                <th class="px-2 py-3 text-center">
                                    Hak {{ tahunAktif }}
                                </th>
                                <th class="px-2 py-3 text-center">
                                    Total Tersedia {{ tahunAktif }}
                                </th>
                                <th class="px-2 py-3 text-center">
                                    Terpakai {{ tahunAktif }}
                                </th>
                                <th class="px-2 py-3 text-center text-slate-800">
                                    Saldo Akhir {{ tahunAktif }}
                                </th>
                                <th class="px-2 py-3 text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="text-sm align-middle">
                            <tr
                                v-for="pegawai in dataPegawai?.data"
                                :key="pegawai.id"
                                class="border-b border-slate-100 hover:bg-slate-50 transition-colors"
                            >
                                <!-- Nama & Kelompok Substansi (boleh membungkus) -->
                                <td class="px-3 py-3">
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

                                <!-- Sisa N-2 -->
                                <td class="px-2 py-3 text-center">
                                    <span
                                        class="inline-block min-w-[32px] px-2 py-1 bg-slate-200 text-slate-700 rounded-md font-semibold text-xs border border-slate-300/80"
                                    >
                                        {{ getSisaDuaTahunLalu(pegawai) }}
                                    </span>
                                </td>

                                <!-- Ditangguhkan N-2 (Oranye) -->
                                <td class="px-2 py-3 text-center">
                                    <span
                                        class="inline-block min-w-[32px] px-2 py-1 bg-orange-100 text-orange-800 rounded-md font-bold text-xs border border-orange-300/80"
                                    >
                                        {{ getSisaDitangguhkanN2(pegawai) }}
                                    </span>
                                </td>

                                <!-- Sisa N-1 -->
                                <td class="px-2 py-3 text-center">
                                    <span
                                        class="inline-block min-w-[32px] px-2 py-1 bg-slate-200 text-slate-700 rounded-md font-semibold text-xs border border-slate-300/80"
                                    >
                                        {{ getSisaTahunLalu(pegawai) }}
                                    </span>
                                </td>

                                <!-- Ditangguhkan N-1 (Oranye) -->
                                <td class="px-2 py-3 text-center">
                                    <span
                                        class="inline-block min-w-[32px] px-2 py-1 bg-orange-100 text-orange-800 rounded-md font-bold text-xs border border-orange-300/80"
                                    >
                                        {{ getSisaDitangguhkanN1(pegawai) }}
                                    </span>
                                </td>

                                <!-- Saldo Bawaan Eligible (Hijau) -->
                                <td class="px-2 py-3 text-center">
                                    <span
                                        class="inline-block min-w-[32px] px-2 py-1 bg-emerald-100 text-emerald-800 rounded-md font-bold text-xs border border-emerald-300/80"
                                    >
                                        {{ getSaldoBawaanEligible(pegawai) }}
                                    </span>
                                </td>

                                <!-- Hak tahun berjalan (Biru) -->
                                <td class="px-2 py-3 text-center">
                                    <span
                                        class="inline-block min-w-[32px] px-2 py-1 bg-blue-100 text-blue-800 rounded-md font-bold text-xs border border-blue-300/80"
                                    >
                                        {{ getSisaTahunIni(pegawai) }}
                                    </span>
                                </td>

                                <!-- Total Hak Tersedia (Indigo) -->
                                <td class="px-2 py-3 text-center">
                                    <span
                                        class="inline-block min-w-[32px] px-2 py-1 bg-indigo-100 text-indigo-800 rounded-md font-bold text-xs border border-indigo-300/80"
                                    >
                                        {{ getTotalTersedia(pegawai) }}
                                    </span>
                                </td>

                                <!-- Terpakai (Merah) — angka final (override
                                     Admin bila ada, kalau tidak angka sistem).
                                     Tanda * kecil muncul kalau angkanya hasil
                                     koreksi manual Admin HR (termasuk koreksi 0). -->
                                <td class="px-2 py-3 text-center">
                                    <span
                                        class="inline-block min-w-[32px] px-2 py-1 bg-rose-100 text-rose-800 rounded-md font-bold text-xs border border-rose-300/80"
                                        :title="
                                            adaKoreksi(pegawai)
                                                ? `Dikoreksi Admin HR (sistem mencatat ${getTerpakaiSistem(pegawai)} hari)`
                                                : ''
                                        "
                                    >
                                        {{ pegawai.cuti_terpakai || 0
                                        }}<sup
                                            v-if="adaKoreksi(pegawai)"
                                            class="text-amber-600 font-black"
                                            >*</sup
                                        >
                                    </span>
                                </td>

                                <!-- Saldo Akhir (gelap agar paling menonjol) -->
                                <td class="px-2 py-3 text-center">
                                    <span
                                        class="inline-block min-w-[36px] px-2.5 py-1 bg-slate-800 text-white rounded-md font-extrabold text-xs shadow-sm border border-slate-900"
                                    >
                                        {{ getSaldoAkhir(pegawai) }}
                                    </span>
                                </td>

                                <!-- Aksi (Tombol Kelola Kuota) -->
                                <td class="px-2 py-3 text-center">
                                    <button
                                        @click="openDetailModal(pegawai)"
                                        type="button"
                                        class="inline-flex items-center gap-1.5 px-4 py-1.5 border border-gray-300 rounded-full text-xs font-medium text-gray-700 bg-white hover:bg-gray-50 hover:text-gray-900 transition shadow-sm cursor-pointer whitespace-nowrap"
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
                                    colspan="11"
                                    class="p-8 text-center text-gray-400"
                                >
                                    Tidak ada data pegawai ditemukan untuk
                                    tahun {{ tahunAktif }}.
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

            <!-- MODAL DETAIL KUOTA + EDIT SALDO + RIWAYAT CUTI PEGAWAI.
             Selalu di tengah layar; memakai data asli 'pengajuan_cuti' &
             'approval_chain' dari controller. -->
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

                    <!-- Body Modal (satu area scroll):
                         [profil + KPI] -> [tab sticky] -> [isi tab]. -->
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

                        <!-- 4 KPI modal (Hak → Terpakai → Sisa → Saldo Akhir),
                         struktur sama dengan panel Atasan & Karyawan. -->
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 sm:gap-3">
                            <!-- KPI 1: Hak -->
                            <div
                                class="bg-white p-2.5 sm:p-3 rounded-xl border border-gray-200 shadow-sm text-center flex flex-col justify-center"
                            >
                                <p
                                    class="text-[10px] font-bold text-gray-400 uppercase tracking-wider"
                                >
                                    Hak {{ tahunAktif }}
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

                            <!-- KPI 2: Terpakai (Rose) -->
                            <div
                                class="bg-rose-50 p-2.5 sm:p-3 rounded-xl border border-rose-100 shadow-sm text-center flex flex-col justify-center"
                            >
                                <p
                                    class="text-[10px] font-bold text-rose-500 uppercase tracking-wider"
                                >
                                    Terpakai {{ tahunAktif }}
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

                            <!-- KPI 3: Sisa (Biru) -->
                            <div
                                class="bg-blue-50 p-2.5 sm:p-3 rounded-xl border border-blue-100 shadow-sm text-center flex flex-col justify-center"
                            >
                                <p
                                    class="text-[10px] font-bold text-blue-500 uppercase tracking-wider"
                                >
                                    Sisa {{ tahunAktif }}
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

                            <!-- KPI 4: Saldo Akhir (Hitam Solid) -->
                            <div
                                class="bg-slate-800 border border-slate-900 text-white p-2.5 sm:p-3 rounded-xl shadow-md text-center flex flex-col justify-center"
                            >
                                <p
                                    class="text-[10px] font-bold text-slate-400 uppercase tracking-wider"
                                >
                                    Saldo Akhir {{ tahunAktif }}
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

                        <!-- TAB: Kelola Saldo | Riwayat. Label dipendekkan di
                             HP ("Saldo"). Angka pada tab Riwayat = jumlah
                             seluruh riwayat. -->
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

                        <!-- Kotak Cara Perhitungan Saldo Akhir:
                         "Saldo Akhir {tahun}: Hak X + Bawaan Y - Terpakai Z = N hari".
                         'Terpakai' dari backend SUDAH final (angka Admin
                         bila ada, kalau tidak angka sistem). Kalau Admin
                         mengisi koreksi, catatan kecil di bawah rumus
                         menjelaskan bahwa angka sistem tidak dipakai. -->
                        <div
                            class="bg-gray-50 px-4 py-3 rounded-lg text-xs text-gray-600 border border-gray-100"
                        >
                            Saldo Akhir {{ tahunAktif }}:
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
                            <p
                                v-if="adaKoreksi(selectedPegawai)"
                                class="mt-1 text-[11px] text-amber-700"
                            >
                                Terpakai memakai angka koreksi Admin HR
                                ({{ getKoreksiSaldo(selectedPegawai) }} hari).
                                Angka dari sistem:
                                {{ getTerpakaiSistem(selectedPegawai) }} hari.
                            </p>
                        </div>
                        <!-- ================= END KOTAK PERHITUNGAN ================= -->

                        <!-- ========================================== -->
                        <!-- FITUR: EDIT SALDO PERORANGAN               -->
                        <!-- Satu-satunya tempat mengubah kuota/saldo    -->
                        <!-- cuti pegawai.                               -->
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
                                {{ tahunAktif }}.
                            </p>

                            <form
                                @submit.prevent="simpanSaldo"
                                class="space-y-3"
                            >
                                <!-- Hak tahun berjalan + Koreksi Jumlah Cuti
                                     Terpakai berdampingan -->
                                <div
                                    class="grid grid-cols-1 sm:grid-cols-2 gap-3"
                                >
                                    <div
                                        class="rounded-lg border border-blue-200 bg-blue-50/70 p-2.5"
                                    >
                                        <label
                                            class="block text-xs font-semibold text-blue-800 mb-1"
                                        >
                                            Hak Cuti {{ tahunAktif }} (Jatah
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

                                    <!-- Koreksi Jumlah Cuti Terpakai (angka FINAL,
                                         bukan +/-). Checkbox dicentang = ikut angka
                                         sistem (kotak dinonaktifkan). Tidak dicentang
                                         = angka di kotak dipakai apa adanya, termasuk
                                         0. Batas 0..365 sesuai validasi backend. -->
                                    <div
                                        class="rounded-lg border border-amber-200 bg-amber-50/70 p-2.5"
                                    >
                                        <label
                                            class="block text-xs font-semibold text-amber-800 mb-1"
                                        >
                                            Koreksi Jumlah Cuti Terpakai
                                            (Hari)
                                        </label>

                                        <label
                                            class="flex items-center gap-2 text-[11px] font-semibold text-amber-800 mb-1.5 cursor-pointer select-none"
                                        >
                                            <input
                                                type="checkbox"
                                                v-model="saldoForm.pakai_sistem"
                                                class="rounded border-amber-300 text-amber-600 focus:ring-amber-500"
                                            />
                                            Gunakan data dari sistem
                                        </label>

                                        <input
                                            type="number"
                                            inputmode="numeric"
                                            step="1"
                                            min="0"
                                            max="365"
                                            v-model="saldoForm.koreksi_saldo"
                                            :disabled="saldoForm.pakai_sistem"
                                            :required="!saldoForm.pakai_sistem"
                                            class="w-full text-base sm:text-sm border border-amber-200 rounded-md px-3 py-2.5 sm:py-2 bg-white focus:ring-amber-500 focus:border-amber-500 disabled:bg-gray-100 disabled:text-gray-500 disabled:cursor-not-allowed"
                                        />
                                        <p
                                            v-if="saldoForm.errors.koreksi_saldo"
                                            class="text-[11px] text-red-600 mt-1"
                                        >
                                            {{ saldoForm.errors.koreksi_saldo }}
                                        </p>
                                        <template v-else>
                                            <p
                                                class="text-[11px] text-amber-800 mt-1"
                                            >
                                                Cuti terpakai dari sistem:
                                                <b
                                                    >{{
                                                        getTerpakaiSistem(
                                                            selectedPegawai,
                                                        )
                                                    }}
                                                    hari</b
                                                >.
                                            </p>
                                            <p
                                                class="text-[11px] text-amber-700/80 mt-0.5"
                                            >
                                                Centang untuk memakai cuti
                                                terpakai dari sistem. Hilangkan
                                                centang untuk mengisi angka
                                                sendiri (boleh 0). Contoh:
                                                sistem mencatat 3 hari, tetapi
                                                pegawai tidak mengambil cuti,
                                                isi 0.
                                            </p>
                                        </template>
                                    </div>
                                </div>

                                <div
                                    class="grid grid-cols-2 gap-3"
                                >
                                    <!-- Sisa N-1 (carry forward) -->
                                    <div>
                                        <label
                                            class="block text-xs font-semibold text-emerald-800 mb-1"
                                        >
                                            Sisa Cuti {{ tahunAktif - 1 }}
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

                                    <!-- Ditangguhkan N-1 (hanya-baca) -->
                                    <div>
                                        <label
                                            class="block text-xs font-semibold text-orange-700 mb-1"
                                        >
                                            Ditangguhkan {{ tahunAktif - 1 }}
                                        </label>
                                        <div
                                            class="w-full text-base sm:text-sm border border-orange-200 rounded-md px-3 py-2.5 sm:py-2 bg-orange-50/60 text-orange-900 font-semibold cursor-not-allowed select-none"
                                            aria-readonly="true"
                                        >
                                            {{ getSisaDitangguhkanN1(selectedPegawai) }}
                                        </div>
                                    </div>

                                    <!-- Sisa N-2 -->
                                    <div>
                                        <label
                                            class="block text-xs font-semibold text-emerald-800 mb-1"
                                        >
                                            Sisa Cuti {{ tahunAktif - 2 }}
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

                                    <!-- Ditangguhkan N-2 (hanya-baca) -->
                                    <div>
                                        <label
                                            class="block text-xs font-semibold text-orange-700 mb-1"
                                        >
                                            Ditangguhkan {{ tahunAktif - 2 }}
                                        </label>
                                        <div
                                            class="w-full text-base sm:text-sm border border-orange-200 rounded-md px-3 py-2.5 sm:py-2 bg-orange-50/60 text-orange-900 font-semibold cursor-not-allowed select-none"
                                            aria-readonly="true"
                                        >
                                            {{ getSisaDitangguhkanN2(selectedPegawai) }}
                                        </div>
                                    </div>
                                </div>

                                <!-- Petunjuk singkat -->
                                <p class="text-[11px] text-gray-500">
                                    *Ditangguhkan diambil otomatis dari riwayat
                                    penangguhan resmi dan tidak dapat diubah di
                                    sini. Saldo Bawaan Eligible, Total Tersedia,
                                    dan Saldo Akhir dihitung otomatis dari isian
                                    di atas (termasuk Koreksi Jumlah Cuti
                                    Terpakai).
                                </p>

                                <!-- Pesan sukses setelah Simpan Saldo. Modal
                                     tetap terbuka supaya admin bisa langsung
                                     melihat perubahan pada kartu KPI. -->
                                <p
                                    v-if="pesanSukses"
                                    class="text-xs font-semibold text-emerald-700 bg-emerald-100 border border-emerald-200 rounded-lg px-3 py-2"
                                >
                                    {{ pesanSukses }}
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

                        <!-- Sisa Ditangguhkan: HANYA tampil jika ada nilai > 0.
                         Ringkasan + catatan_saldo untuk data yang sudah
                         tersimpan. -->
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
                                        {{ tahunAktif - 2 }}</span
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
                                        {{ tahunAktif - 1 }}</span
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

                            <!-- List riwayat, garis warna kiri sesuai status asli -->
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

                                        <!-- Alamat & kontak selama cuti (tampil '-'
                                         kalau field belum dikirim controller) -->
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
                                                    {{ riwayat.no_telp || "-" }}
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

                                        <!-- Jejak Approval LENGKAP: seluruh jenjang
                                         L1-L4 sesuai rantai role pemohon, dengan
                                         status masing-masing (Setuju / Tolak /
                                         Menunggu / Belum Giliran / Ditangguhkan). -->
                                        <div
                                            v-if="
                                                getVisibleApprovalChain(
                                                    riwayat.approval_chain,
                                                    selectedPegawai,
                                                ).length > 0
                                            "
                                            class="bg-white p-3 rounded-lg border border-green-100 space-y-1"
                                        >
                                            <div
                                                v-for="(
                                                    step, idx
                                                ) in getVisibleApprovalChain(
                                                    riwayat.approval_chain,
                                                    selectedPegawai,
                                                )"
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
            <!-- MODAL IMPOR KUOTA CUTI                     -->
            <!-- ========================================== -->
            <div
                v-if="isImportModalOpen"
                class="fixed inset-0 z-[9999] flex items-end justify-center bg-black/60 p-0 backdrop-blur-xs overflow-y-auto sm:items-center sm:p-4"
            >
                <!-- Background klik-untuk-tutup -->
                <div class="absolute inset-0" @click="closeImportModal"></div>

                <div
                    class="relative bg-white p-4 sm:p-6 rounded-t-2xl sm:rounded-2xl w-full max-w-md shadow-2xl max-h-[90dvh] overflow-y-auto sm:my-auto"
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
                                class="block min-h-[44px] w-full text-base sm:text-sm text-gray-600 border border-gray-300 rounded-md cursor-pointer focus:outline-none file:mr-3 file:py-2 file:px-4 file:border-0 file:bg-blue-50 file:text-blue-700 file:font-semibold hover:file:bg-blue-100"
                            />
                        </div>

                        <p
                            v-if="importForm.errors.file"
                            class="text-sm text-red-600 mb-2"
                        >
                            {{ importForm.errors.file }}
                        </p>

                        <p class="text-xs text-gray-400 mb-5">
                            Impor untuk tahun <b>{{ tahunAktif }}</b> (sesuai Filter
                            Tahun). Gunakan template Rekap Cuti, sheet "Saldo Tahunan".
                            Yang diperbarui hanya angka Sisa {{ tahunAktif - 2 }},
                            Sisa {{ tahunAktif - 1 }}, dan Hak {{ tahunAktif }}. Kolom
                            hasil hitungan diabaikan dan dihitung ulang oleh sistem.
                            Sel kosong tidak mengubah data.
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
            <!-- MODAL GENERATE SALDO TAHUN BARU (MANUAL)   -->
            <!-- Memanggil route 'admin.saldo.generate'     -->
            <!-- (AdminController::generateSaldoManual) —   -->
            <!-- Artisan Command                            -->
            <!-- 'saldo-cuti:generate-tahun-baru', sama     -->
            <!-- persis dengan scheduler tiap 1 Januari.    -->
            <!-- ========================================== -->
            <div
                v-if="isGenerateSaldoModalOpen"
                class="fixed inset-0 z-[9999] flex items-end justify-center bg-black/60 p-0 backdrop-blur-xs overflow-y-auto sm:items-center sm:p-4"
            >
                <!-- Background klik-untuk-tutup -->
                <div
                    class="absolute inset-0"
                    @click="closeGenerateSaldoModal"
                ></div>

                <div
                    class="relative bg-white p-4 sm:p-6 rounded-t-2xl sm:rounded-2xl w-full max-w-md shadow-2xl max-h-[90dvh] overflow-y-auto sm:my-auto"
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
                            dipakai, dibatasi total maks 24 hari). Aman
                            diulang: hasilnya sama untuk data yang sama, dan
                            Hak yang sudah diisi tidak berubah. Sisa tahun lalu
                            dan carry-over dihitung ulang.
                        </p>

                        <label
                            class="block text-sm font-medium text-gray-700 mb-1"
                        >
                            Tahun yang digenerate
                        </label>
                        <!-- [FIX] Opsi dirender dinamis dari 'pilihanTahunGenerate'
                             (sebelumnya di-hardcode currentYear + 1), sehingga
                             catatan di <script> berlaku: cukup tambahkan
                             currentYear ke array itu untuk membuka tahun berjalan. -->
                        <select
                            v-model="generateSaldoForm.tahun"
                            class="w-full min-h-[44px] border border-gray-300 rounded-md px-3 py-2 text-base sm:text-sm mb-2 focus:ring-emerald-500 focus:border-emerald-500"
                        >
                            <option
                                v-for="tahun in pilihanTahunGenerate"
                                :key="tahun"
                                :value="tahun"
                            >
                                {{ tahun }}
                            </option>
                        </select>
                        <p
                            v-if="generateSaldoForm.tahun === currentYear"
                            class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-md px-3 py-2 mb-2"
                        >
                            Generate tahun berjalan akan menghitung ulang Sisa
                            tahun lalu dan menimpa angka yang diisi manual atau
                            diimpor. Hak yang sudah diisi tidak berubah.
                        </p>
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


