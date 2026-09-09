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
// Tombol Setuju/Tolak hanya aktif jika status sesuai level atasan yang login
const canApprove = (item) => {
    // Admin HR (role 5) read-only
    if (isReadOnly.value) return false;

    const roleId = page.props.auth.user.role_id;

    const allowedStatus = {
        2: "menunggu_l1", // Ketua Tim Kerja
        3: "menunggu_l2", // Ketua Kelompok Substansi
        4: "menunggu_l3", // Kasubag TU
        6: "menunggu_l4", // Kepala Biro
    };

    return item.status === allowedStatus[roleId];
};

const search = ref(props.filters?.search || "");
const jenisCuti = ref(props.filters?.jenis_cuti || "Semua Jenis Cuti");
// Filter Status: melengkapi pencarian & jenis cuti agar seluruh transaksi
// (menunggu L1-L4, disetujui, ditolak, ditangguhkan) bisa disaring juga.
const status = ref(props.filters?.status || "");

// Auto-search saat mengetik atau mengganti filter jenis cuti/status
watch(
    [search, jenisCuti, status],
    debounce(function ([newSearch, newJenis, newStatus]) {
        router.get(
            route("atasan.approval"),
            {
                search: newSearch,
                jenis_cuti: newJenis,
                status: newStatus,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    }, 300),
);

// Kamus label status lengkap (L1-L4 + status akhir disetujui/ditolak/
// ditangguhkan/dibatalkan) supaya badge tabel maupun footer Modal Detail
// tidak pernah menampilkan nilai mentah dari database (mis. "disetujui"
// tanpa format). "dibatalkan_ditangguhkan" sengaja dipetakan ke label bersih
// "Ditangguhkan" (bukan ditampilkan mentah) sesuai kesepakatan penamaan.
const statusLabels = {
    menunggu_l1: "Menunggu Bapak Ketua Tim Kerja (L1)",
    menunggu_l2: "Menunggu Bapak Ketua Kelompok Substansi (L2)",
    menunggu_l3: "Menunggu Ignatius Agus Hendarto (L3)",
    menunggu_l4: "Menunggu Seta Rukmalasari Agustina (L4)",
    disetujui: "Disetujui",
    ditolak: "Ditolak",
    ditangguhkan: "Ditangguhkan",
    dibatalkan_reguler: "Dibatalkan (Reguler)",
    dibatalkan_ditangguhkan: "Ditangguhkan",
};

// Fallback: kalau status belum terdaftar di kamus (mis. status baru dari
// backend), tetap tampil rapi ("dibatalkan_l2" -> "DIBATALKAN L2") daripada
// mentah apa adanya.
const getStatusLabel = (st) => {
    return statusLabels[st] || st?.replace(/_/g, " ").toUpperCase() || "-";
};

// ================= STATUS EFEKTIF DITOLAK vs DITANGGUHKAN =================
// Root cause (LAMA): di database, status penangguhan disimpan dengan nilai
// mentah yang SAMA dengan penolakan murni, yaitu 'ditolak'. Satu-satunya
// penanda pembeda ada di dalam teks kolom 'keterangan' ("Ditangguhkan oleh
// ..."). Helper ini dipakai bersama di tabel (badge) maupun Modal Detail
// (footer) supaya keduanya konsisten menampilkan "Ditangguhkan" (bukan
// "Ditolak") untuk kasus penangguhan, sejalan dengan filter status yang
// sudah dipisah di MonitoringCutiController (opsi 'ditolak' vs
// 'ditangguhkan' pada dropdown).
//
// PEMBARUAN (setelah perbaikan backend MonitoringCutiController::process()):
// Backend SEKARANG menulis kata "Ditolak" (bukan lagi "Ditangguhkan") ke
// kolom keterangan saat Atasan menekan tombol Tolak. Efeknya:
//   - Pengajuan yang ditolak SETELAH perbaikan ini otomatis TIDAK memicu
//     isKeteranganDitangguhkan() lagi (kata "ditangguhkan" tidak ada di
//     teksnya), sehingga getEffectiveStatus() mengembalikan 'ditolak' apa
//     adanya -> badge tampil "Ditolak" merah, sesuai status aslinya.
//   - Data LAMA (sebelum perbaikan ini) yang keterangannya masih menyebut
//     "Ditangguhkan" TETAP ditampilkan sebagai "Ditangguhkan" — ini
//     disengaja (bukan bug) supaya histori lama tidak berubah makna begitu
//     saja. Helper ini TETAP DIPERTAHANKAN (tidak dihapus) khusus untuk
//     menjaga kompatibilitas data lama tersebut.
const isKeteranganDitangguhkan = (keterangan) => {
    return /ditangguhkan/i.test(keterangan || "");
};

const getEffectiveStatus = (item) => {
    if (!item) return null;
    if (
        item.status === "ditolak" &&
        isKeteranganDitangguhkan(item.keterangan)
    ) {
        return "ditangguhkan";
    }
    return item.status;
};

// Label status efektif untuk baris tabel & footer modal (memakai
// getEffectiveStatus supaya penangguhan tidak lagi salah tertulis "Ditolak").
const getEffectiveStatusLabel = (item) => {
    return getStatusLabel(getEffectiveStatus(item));
};
// ================= END STATUS EFEKTIF =================

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

// Durasi cuti: utamakan 'jumlah_hari' dari database (kolom asli tabel
// pengajuan_cuti). Kalau kosong/0, hitung manual dari tanggal sebagai
// cadangan supaya durasi tidak pernah tampil kosong atau salah.
const hitungDurasi = (mulai, selesai) => {
    if (!mulai || !selesai) return 1;
    const tglMulai = new Date(mulai);
    const tglSelesai = new Date(selesai);
    const selisih =
        Math.ceil((tglSelesai - tglMulai) / (1000 * 60 * 60 * 24)) + 1;
    return selisih > 0 ? selisih : 1;
};

const getDurasi = (item) => {
    if (item?.jumlah_hari && item.jumlah_hari > 0) {
        return item.jumlah_hari;
    }
    return hitungDurasi(item?.tanggal_mulai, item?.tanggal_selesai);
};

// Fungsi memproses aksi Setujui / Tolak
//
// PERBAIKAN TERBARU: tombol Tolak sekarang membuka MODAL KUSTOM
// (rejectModal, lihat di bawah) alih-alih prompt() bawaan browser yang
// tampilannya polos dan tidak serasi dengan desain portal. Validasi
// "alasan wajib diisi" dipindahkan ke dalam submitReject() (dipanggil dari
// tombol "Kirim Penolakan" di modal).
//
// CATATAN PENTING (field name): payload TETAP dikirim dengan key `alasan`
// karena MonitoringCutiController::process() membaca alasan penolakan
// lewat `$request->input('alasan', 'Tidak disetujui')`. Kedua sisi
// (frontend & backend) HARUS memakai key yang sama.
const processApproval = (id, action) => {
    if (action === "approve") {
        // Panggil modal kustom alih-alih confirm() browser
        openApproveModal(id);
    } else if (action === "reject") {
        // Panggil modal kustom alih-alih prompt() browser
        openRejectModal(id);
    }
};

// ================= MODAL KONFIRMASI SETUJUI CUTI (KUSTOM) =================
const approveModal = ref({
    show: false,
    id: null,
    item: null,
});

const openApproveModal = (id) => {
    const item = props.antrean?.data?.find((i) => i.id === id) || null;
    approveModal.value.id = id;
    approveModal.value.item = item;
    approveModal.value.show = true;
};

const closeApproveModal = () => {
    approveModal.value.show = false;
    approveModal.value.id = null;
    approveModal.value.item = null;
};

const submitApprove = () => {
    if (!approveModal.value.id) return;

    router.post(
        route("atasan.approval.process", approveModal.value.id),
        { action: "approve" },
        {
            preserveScroll: true,
            onSuccess: () => {
                closeApproveModal();
            },
        },
    );
};
// ================= END MODAL KONFIRMASI SETUJUI =================

// ================= MODAL INPUT ALASAN PENOLAKAN (KUSTOM) =================
const rejectModal = ref({
    show: false,
    id: null,
    item: null,
    alasan: "",
});

const openRejectModal = (id) => {
    const item = props.antrean?.data?.find((i) => i.id === id) || null;
    rejectModal.value.id = id;
    rejectModal.value.item = item;
    rejectModal.value.alasan = "";
    rejectModal.value.show = true;
};

const closeRejectModal = () => {
    rejectModal.value.show = false;
    rejectModal.value.id = null;
    rejectModal.value.item = null;
    rejectModal.value.alasan = "";
};

const submitReject = () => {
    if (!rejectModal.value.alasan || rejectModal.value.alasan.trim() === "") {
        alert("Alasan penolakan wajib diisi!");
        return;
    }

    router.post(
        route("atasan.approval.process", rejectModal.value.id),
        {
            action: "reject",
            alasan: rejectModal.value.alasan, // key 'alasan' sinkron dengan backend
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                closeRejectModal();
            },
        },
    );
};
// ================= END MODAL ALASAN PENOLAKAN =================

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
// Data di database dijumpai dalam beberapa format mentah yang berbeda:
//   - "alasan asli pegawai | [DITANGGUHKAN/DIBATALKAN ATASAN: catatan]"
//   - "alasan asli pegawai (Ditangguhkan oleh <Nama>: catatan)"
//   - "alasan asli pegawai (Ditolak oleh <Nama>: catatan)" (format baru
//     setelah perbaikan backend)
// Dipecah jadi beberapa helper kecil supaya:
//   - Alasan murni pegawai tampil bersih di kolom Keterangan/baris Alasan
//     (tanpa tercampur catatan maupun nama atasan)
//   - Catatan dan nama atasan tampil terpisah, ditempel pada kartu level
//     yang bersangkutan di dalam timeline "Catatan / Respon Atasan"

// Alasan murni: memotong bagian sebelum '|', '[DITANGGUHKAN...]', atau
// tanda kurung berisi catatan atasan (format "(Ditolak/Ditangguhkan oleh
// ...)"), supaya alasan pegawai tidak pernah tercampur catatan/nama atasan.
const getAlasanBersih = (text) => {
    if (!text || text === "-") return "-";

    if (text.includes("|") || text.includes("[DITANGGUHKAN")) {
        let bagian = text.split("|").map((item) => item.trim());
        let alasanAwal =
            bagian[0] && bagian[0] !== "-" ? bagian[0] : "Ada keperluan";
        return alasanAwal;
    }

    // Format lain: "Ada keperluan (Ditolak/Ditangguhkan oleh <Nama>: ...)"
    // — hanya dipotong kalau tanda kurungnya memang berisi catatan atasan,
    // supaya alasan pegawai yang kebetulan memakai tanda kurung biasa tidak
    // ikut terpotong.
    if (
        text.includes("(") &&
        /oleh|ditolak|ditangguhkan|dibatalkan/i.test(text)
    ) {
        let alasanAwal = text.split("(")[0].trim();
        return alasanAwal !== "" ? alasanAwal : text;
    }

    return text;
};

// Mengambil isi mentah di dalam catatan atasan, dari pola bracket baku
// "[DITANGGUHKAN/DIBATALKAN ATASAN: ...]" (diutamakan), atau fallback ke
// teks di dalam tanda kurung biasa "(...)" supaya catatan tetap terbaca
// walau datanya tidak persis mengikuti format bracket.
const getIsiCatatanMentah = (text) => {
    if (!text) return "";

    const bracketRegex = /\[DITANGGUHKAN\/DIBATALKAN ATASAN:\s*(.*?)\]/i;
    const bracketMatch = text.match(bracketRegex);
    if (bracketMatch && bracketMatch[1]) {
        return bracketMatch[1].trim();
    }

    const parenRegex = /\(([^)]+)\)/;
    const parenMatch = text.match(parenRegex);
    if (parenMatch && parenMatch[1]) {
        return parenMatch[1].trim();
    }

    return "";
};

// Catatan atasan yang siap ditampilkan: kalau isi mentahnya memakai pola
// "Ditolak/Ditangguhkan oleh <Nama>: <catatan>", hanya bagian setelah ':'
// pertama yang diambil (nama pemberi catatan ditangani terpisah lewat
// getNamaPemberiCatatan supaya tidak dobel disebut).
const getCatatanAtasan = (text) => {
    const isi = getIsiCatatanMentah(text);
    if (!isi) return "";

    if (/oleh/i.test(isi) && isi.includes(":")) {
        const parts = isi.split(":");
        return parts.slice(1).join(":").trim();
    }

    return isi;
};

// Nama atasan yang menolak/menangguhkan/membatalkan, diekstrak dari pola
// "Ditolak/Ditangguhkan oleh <Nama>: ...". Mengembalikan string kosong
// kalau pola "oleh" tidak ditemukan (mis. format lama tanpa nama eksplisit).
//
// CATATAN PERBAIKAN: fungsi ini TETAP DIPERTAHANKAN (tidak dihapus) karena
// masih berguna kalau suatu saat dibutuhkan untuk keperluan lain (mis. audit
// log / debugging teks keterangan). TAPI sejak fix terbaru, hasil fungsi ini
// SENGAJA TIDAK LAGI dipakai untuk menimpa nama pejabat di approvalTimeline
// (lihat computed approvalTimeline di bawah) — sebelumnya baris
// `nama = namaPemberiCatatan` menyebabkan nama pejabat asli (mis. "Seta
// Rukmalasari Agustina, S.P., M.M.A., M.Sc." di L4) berubah/tertimpa jadi
// potongan teks generik seperti "Bapak Ketua Tim Kerja" yang diekstrak dari
// kalimat keterangan pengajuan lain, sehingga nama yang tampil jadi tidak
// konsisten dan salah level. Sekarang nama pejabat SELALU diambil dari
// levelsDefinition (level.nama) supaya konsisten dengan tampilan status
// "Disetujui".
const getNamaPemberiCatatan = (text) => {
    const isi = getIsiCatatanMentah(text);
    if (!isi || !/oleh/i.test(isi)) return "";

    const sebelumTitikDua = isi.split(":")[0];
    const bagianSetelahOleh = sebelumTitikDua.split(/oleh/i);
    return bagianSetelahOleh.length > 1 ? bagianSetelahOleh[1].trim() : "";
};
// ================= END HELPER KETERANGAN =================

// ================= TIMELINE PERSETUJUAN (Catatan / Respon Atasan) =================
// Definisi 4 level persetujuan sesuai statusLabels di atas (L1-L4).
// L1/L2 memakai jabatan generik karena atasan langsungnya berbeda-beda per
// pegawai; L3/L4 memakai nama tetap sesuai kesepakatan penamaan di atas.
const levelsDefinition = [
    {
        key: 1,
        jabatan: "L1 - Ketua Tim Kerja",
        nama: "Ketua Tim Kerja Pegawai",
    },
    {
        key: 2,
        jabatan: "L2 - Ketua Kelompok Substansi",
        nama: "Ketua Kelompok Substansi",
    },
    {
        key: 3,
        jabatan: "L3 - Kasubag TU",
        nama: "Ignatius Agus Hendarto, S.E., M.M.",
    },
    {
        key: 4,
        jabatan: "L4 - Kepala Biro Perencanaan",
        nama: "Seta Rukmalasari Agustina, S.P., M.M.A., M.Sc.",
    },
];

// Rute persetujuan berjenjang bergantung pada jabatan pemohon sendiri:
// pemohon tidak perlu diapprove oleh level yang setara dengan jabatannya
// sendiri, jadi level itu dilewati dari timeline.
//   - Staf biasa (role_id 1 / tidak diketahui) -> L1, L3, L4 (L2 dilewati)
//   - Atasan L1 (role_id 2)                    -> L2, L3, L4
//   - Atasan L2 (role_id 3)                    -> L3, L4
//   - Atasan L3 (role_id 4)                    -> L4 saja
const getApprovalRoute = (roleId) => {
    switch (roleId) {
        case 4:
            return [levelsDefinition[3]];
        case 3:
            return [levelsDefinition[2], levelsDefinition[3]];
        case 2:
            return [
                levelsDefinition[1],
                levelsDefinition[2],
                levelsDefinition[3],
            ];
        default:
            return [
                levelsDefinition[0],
                levelsDefinition[2],
                levelsDefinition[3],
            ];
    }
};

// Menyusun status tiap level pada rute yang berlaku (Disetujui / Menunggu
// Persetujuan / Ditolak / Ditangguhkan / "-") berdasarkan status pengajuan
// yang sedang dibuka di Modal Detail.
//
// ================= RIWAYAT PERBAIKAN approvalTimeline =================
// PERBAIKAN #1 (root cause bug "penolakan L1 tampil di kotak L4"):
// Awalnya `stoppedLevel` SELALU diambil dari step TERAKHIR pada rute
// pemohon (steps[steps.length - 1].key), padahal penolakan bisa terjadi di
// level manapun. Sempat diperbaiki sementara dengan memindai teks
// `keterangan` mencari pola "L1/L2/L3/L4".
//
// PERBAIKAN #2 (fix final, menggantikan pemindaian teks di atas):
// Backend (MonitoringCutiController::process()) SEKARANG SELALU menyimpan
// angka level yang menolak secara eksplisit & tervalidasi ke kolom
// `level_saat_ini` setiap kali status berubah jadi 'ditolak'. Kolom ini
// dijadikan SUMBER KEBENARAN UTAMA untuk `stoppedLevel` di sini — jauh
// lebih akurat daripada menebak dari teks keterangan (yang bisa meleset
// kalau format kalimat berubah, atau salah tangkap huruf "L" yang tidak
// relevan). Urutan prioritas fallback:
//   1. data.level_saat_ini dari database (PALING DIUTAMAKAN)
//   2. Pemindaian teks keterangan (untuk data lama yang belum sempat
//      menyimpan level_saat_ini secara eksplisit)
//   3. Step terakhir pada rute pemohon (fallback paling akhir, perilaku
//      paling lama, supaya data sangat lama tetap tampil sebagaimana
//      sebelumnya / tidak tiba-tiba kosong)
//
// PERBAIKAN #3 (nama pejabat tidak lagi berubah-ubah):
// Sebelumnya baris `nama = namaPemberiCatatan` menimpa nama asli pejabat
// (level.nama) dengan potongan teks yang diekstrak dari kolom keterangan.
// Ini menyebabkan nama seperti "Seta Rukmalasari Agustina, S.P., M.M.A.,
// M.Sc." di L4 bisa berubah jadi teks generik/salah level (mis. "Bapak
// Ketua Tim Kerja"). Sekarang baris itu DIHAPUS — nama pejabat di setiap
// level SELALU memakai level.nama dari kamus levelsDefinition, persis sama
// seperti saat statusnya "Disetujui", sehingga konsisten di semua kondisi.
// ================= END RIWAYAT PERBAIKAN =================
const approvalTimeline = computed(() => {
    const data = detailModal.value.data;
    if (!data) return [];

    const st = data.status;
    const roleId = data.pegawai?.role_id ?? 1;
    const steps = getApprovalRoute(roleId);

    const pendingMatch = st?.match(/^menunggu_l(\d)$/);
    const pendingLevel = pendingMatch ? parseInt(pendingMatch[1], 10) : null;
    const isStoppedStatus =
        st === "ditolak" ||
        st === "ditangguhkan" ||
        st === "dibatalkan_ditangguhkan";

    // Deteksi level tempat penolakan/penangguhan terjadi secara akurat.
    // Prioritas #1: kolom level_saat_ini dari database (sumber kebenaran
    // utama, sudah divalidasi di backend). Prioritas #2 & #3: fallback lama
    // untuk kompatibilitas data historis (lihat penjelasan di atas).
    let stoppedLevel = null;
    if (isStoppedStatus && steps.length > 0) {
        if (data.level_saat_ini) {
            stoppedLevel = parseInt(data.level_saat_ini, 10);
        } else {
            const ket = data.keterangan || "";
            const matchLevel = ket.match(/l([1-4])/i);
            if (matchLevel) {
                stoppedLevel = parseInt(matchLevel[1], 10);
            } else {
                stoppedLevel = steps[steps.length - 1].key;
            }
        }
    }

    // Catatan atasan yang menolak/menangguhkan, diambil dari format mentah
    // field keterangan lewat getCatatanAtasan. (getNamaPemberiCatatan
    // sengaja TIDAK dipanggil di sini lagi — lihat PERBAIKAN #3 di atas.)
    const catatanAtasan =
        stoppedLevel !== null ? getCatatanAtasan(data.keterangan) : "";
    // Catatan: backend versi LAMA kadang menyimpan penangguhan dengan nilai
    // status mentah 'ditolak', padahal catatannya berbunyi "Ditangguhkan
    // oleh ...". Setelah perbaikan backend, penolakan baru konsisten
    // menulis kata "Ditolak", jadi baris ini hanya akan aktif untuk DATA
    // LAMA yang masih memakai kata "ditangguhkan" di keterangannya —
    // sengaja dipertahankan untuk kompatibilitas histori.
    const catatanMenyebutDitangguhkan = isKeteranganDitangguhkan(
        data.keterangan,
    );

    return steps.map((level) => {
        let label = "-";
        // Warna disamakan dengan skema emerald/amber/rose yang dipakai di
        // fitur Rekap Kuota Detail (bukan lagi green/yellow/red bawaan
        // Tailwind), supaya kedua fitur konsisten secara visual.
        let color = "text-gray-400";
        let catatan = "";
        // KUNCI PERBAIKAN #3: nama level SELALU memakai data asli dari
        // kamus levelsDefinition (level.nama), TIDAK PERNAH ditimpa oleh
        // hasil ekstraksi teks keterangan. Nama pejabat jadi konsisten di
        // semua status (disetujui, menunggu, ditolak, ditangguhkan).
        let nama = level.nama;

        if (st === "disetujui") {
            label = "Disetujui";
            color = "text-emerald-700";
        } else if (pendingLevel !== null) {
            if (level.key < pendingLevel) {
                label = "Disetujui";
                color = "text-emerald-700";
            } else if (level.key === pendingLevel) {
                label = "Menunggu Persetujuan";
                color = "text-amber-700";
            }
        } else if (stoppedLevel !== null) {
            if (level.key < stoppedLevel) {
                // Level SEBELUM level penolakan otomatis berstatus Disetujui
                label = "Disetujui";
                color = "text-emerald-700";
            } else if (level.key === stoppedLevel) {
                // TEPAT DI LEVEL INI penolakan/penangguhan terjadi
                label =
                    st === "ditolak" && !catatanMenyebutDitangguhkan
                        ? "Ditolak"
                        : "Ditangguhkan";
                color = "text-rose-700";
                if (catatanAtasan) {
                    catatan = catatanAtasan;
                }
            } else if (level.key > stoppedLevel) {
                // Level SETELAH penolakan tidak sempat diproses -> tetap "-"
                label = "-";
                color = "text-gray-400";
            }
        }

        return { ...level, nama, status: label, color, catatan };
    });
});
// ================= END TIMELINE PERSETUJUAN =================
</script>

<template>
    <Head title="Monitoring Cuti" />

    <MainLayout>
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
            <div
                class="flex flex-col md:flex-row justify-between items-center mb-6 gap-4"
            >
                <h2 class="text-2xl font-bold text-gray-800">
                    Monitoring Transaksi Cuti
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
                        <option value="Cuti Melahirkan">Cuti Melahirkan</option>
                        <option value="Cuti Besar">Cuti Besar</option>
                        <option value="Cuti Alasan Penting">
                            Cuti Alasan Penting
                        </option>
                    </select>

                    <!-- Dropdown Status Pengajuan -->
                    <select
                        v-model="status"
                        class="border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-lg text-sm text-gray-600"
                    >
                        <option value="">Semua Status</option>
                        <option value="menunggu_l1">Menunggu L1</option>
                        <option value="menunggu_l2">Menunggu L2</option>
                        <option value="menunggu_l3">Menunggu L3</option>
                        <option value="menunggu_l4">Menunggu L4</option>
                        <option value="disetujui">Disetujui</option>
                        <option value="ditolak">Ditolak</option>
                        <option value="ditangguhkan">Ditangguhkan</option>
                        <option value="dibatalkan_reguler">
                            Dibatalkan (Reguler)
                        </option>
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
                                <!-- PERBAIKAN WARNA: sebelumnya solid bg-blue-600 dengan
                                     teks putih, sekarang disamakan dengan warna biru soft
                                     milik card "Tahun Ini" di Rekap Kuota Detail
                                     (bg-blue-100/border-blue-200/text-blue-700). -->
                                <span
                                    class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-200 text-blue-800 border border-blue-300"
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
                                {{ getDurasi(item) }} Hari
                            </td>
                            <!-- PERBAIKAN WARNA & BENTUK: badge Tahap sekarang disamakan
                                 PERSIS dengan badge status riwayat di Rekap Kuota Detail —
                                 bukan cuma warnanya, tapi juga bentuknya (rounded-md, bukan
                                 rounded-full) dan tipografinya (uppercase, tracking-wide). -->
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <span
                                    class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-bold tracking-wide uppercase border"
                                    :class="{
                                        'bg-amber-100 text-amber-800 border-amber-300':
                                            item.status?.includes('menunggu'),
                                        'bg-emerald-100 text-emerald-800 border-emerald-300':
                                            item.status === 'disetujui',
                                        'bg-rose-100 text-rose-800 border-rose-300':
                                            getEffectiveStatus(item) ===
                                            'ditolak',
                                        'bg-gray-200 text-gray-800 border-gray-300':
                                            getEffectiveStatus(item) ===
                                                'ditangguhkan' ||
                                            item.status?.includes(
                                                'ditangguhkan',
                                            ) ||
                                            item.status?.includes('dibatalkan'),
                                        'bg-gray-200 text-gray-600 border-gray-300':
                                            !item.status,
                                    }"
                                >
                                    {{ getEffectiveStatusLabel(item) }}
                                </span>
                            </td>
                            <!-- Kolom Keterangan: pakai helper getAlasanBersih agar hanya
                                 alasan murni pegawai yang tampil (catatan atasan dipindah
                                 ke kartu timeline di Modal Detail) -->
                            <td
                                class="px-6 py-4 text-sm text-gray-500 max-w-xs truncate"
                                :title="getAlasanBersih(item.keterangan)"
                            >
                                {{ getAlasanBersih(item.keterangan) }}
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
                                            :disabled="!canApprove(item)"
                                            :class="[
                                                'px-3 py-1.5 rounded-lg text-xs font-bold transition shadow-sm',
                                                canApprove(item)
                                                    ? 'bg-emerald-600 hover:bg-emerald-700 text-white'
                                                    : 'bg-slate-200 text-slate-400 cursor-not-allowed',
                                            ]"
                                        >
                                            SETUJUI
                                        </button>

                                        <button
                                            @click="
                                                processApproval(
                                                    item.id,
                                                    'reject',
                                                )
                                            "
                                            :disabled="!canApprove(item)"
                                            :class="[
                                                'px-3 py-1.5 rounded-lg text-xs font-bold transition shadow-sm',
                                                canApprove(item)
                                                    ? 'bg-red-600 hover:bg-red-700 text-white'
                                                    : 'bg-slate-200 text-slate-400 cursor-not-allowed',
                                            ]"
                                        >
                                            TOLAK
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
                class="bg-white rounded-2xl shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto mx-4"
            >
                <!-- Header Modal -->
                <div
                    class="sticky top-0 bg-white/95 backdrop-blur-sm flex justify-between items-center px-6 py-5 border-b border-gray-100 z-10"
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
                            ({{ getDurasi(detailModal.data) }} Hari)
                        </div>
                    </div>

                    <!-- Alasan: pakai helper getAlasanBersih supaya hanya
                         menampilkan alasan pengajuan cuti murni dari pegawai,
                         tanpa tercampur catatan atasan (catatan atasan tampil
                         terpisah di kartu timeline "Catatan / Respon Atasan"
                         di bawah) -->
                    <div class="grid grid-cols-12 gap-2">
                        <div class="col-span-4 text-gray-500 font-medium">
                            Alasan
                        </div>
                        <div
                            class="col-span-8 text-slate-800 font-semibold leading-relaxed"
                        >
                            {{ getAlasanBersih(detailModal.data.keterangan) }}
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

                    <!-- Lampiran: tampilkan tautan asli kalau file tersedia,
                         jatuh kembali ke badge "Tidak ada lampiran" kalau kosong -->
                    <div class="grid grid-cols-12 gap-2 items-center pt-1">
                        <div class="col-span-4 text-gray-500 font-medium">
                            Lampiran
                        </div>
                        <div class="col-span-8">
                            <a
                                v-if="detailModal.data.lampiran"
                                :href="detailModal.data.lampiran"
                                target="_blank"
                                rel="noopener"
                                class="inline-flex items-center gap-2 px-3 py-1.5 bg-blue-50 border border-blue-200 text-blue-600 hover:bg-blue-100 rounded-xl text-xs font-medium transition-colors"
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

                    <!-- Catatan / Respon Atasan: timeline persetujuan (rute dinamis)
                         dibungkus desain Nested Cards. WARNA DISAMAKAN dengan skema
                         emerald/amber/rose/gray milik fitur Rekap Kuota Detail (bukan
                         lagi green/orange bawaan yang terlalu soft), dan sekarang
                         membedakan 4 kondisi (disetujui / menunggu / ditolak /
                         ditangguhkan) alih-alih cuma 2 (disetujui vs selain itu). -->
                    <div class="pt-4 border-t border-gray-100">
                        <div
                            :class="[
                                'rounded-xl p-4 border',
                                getEffectiveStatus(detailModal.data) ===
                                'disetujui'
                                    ? 'bg-emerald-100 border-emerald-300'
                                    : getEffectiveStatus(
                                            detailModal.data,
                                        )?.includes('menunggu')
                                      ? 'bg-amber-100 border-amber-300'
                                      : getEffectiveStatus(detailModal.data) ===
                                          'ditolak'
                                        ? 'bg-rose-100 border-rose-300'
                                        : 'bg-gray-200 border-gray-300',
                            ]"
                        >
                            <h4
                                :class="[
                                    'text-xs font-bold uppercase tracking-wider mb-3 flex items-center gap-1.5',
                                    getEffectiveStatus(detailModal.data) ===
                                    'disetujui'
                                        ? 'text-emerald-800'
                                        : getEffectiveStatus(
                                                detailModal.data,
                                            )?.includes('menunggu')
                                          ? 'text-amber-800'
                                          : getEffectiveStatus(
                                                  detailModal.data,
                                              ) === 'ditolak'
                                            ? 'text-rose-800'
                                            : 'text-gray-800',
                                ]"
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
                                Catatan / Respon Atasan
                            </h4>

                            <div class="space-y-2.5">
                                <div
                                    v-for="level in approvalTimeline"
                                    :key="level.key"
                                    class="bg-white rounded-lg p-3 shadow-sm border border-gray-100 flex flex-col"
                                >
                                    <span class="text-xs text-gray-500 mb-1">
                                        {{ level.jabatan }}:
                                        <span
                                            class="font-semibold text-gray-800"
                                            >{{ level.nama }}</span
                                        >
                                    </span>
                                    <span
                                        class="text-xs font-bold"
                                        :class="level.color"
                                    >
                                        {{ level.status }}
                                    </span>
                                    <!-- Catatan spesifik dari atasan yang menolak/menangguhkan
                                         di level ini, diekstrak lewat getCatatanAtasan.
                                         Warna disamakan ke rose (bukan red) agar konsisten. -->
                                    <span
                                        v-if="level.catatan"
                                        class="text-xs text-rose-700 mt-1 italic bg-rose-100 p-1.5 rounded border border-rose-300"
                                    >
                                        "{{ level.catatan }}"
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer Modal: area status abu-abu pucat -->
                <!-- PERBAIKAN: memakai getEffectiveStatusLabel supaya footer juga
                     menampilkan "Ditangguhkan" (bukan "Ditolak") saat status DB
                     'ditolak' namun keterangannya menyebut "Ditangguhkan oleh ..."
                     (khusus data lama — untuk data baru status 'ditolak' sudah
                     konsisten tampil "Ditolak" berkat perbaikan backend). -->
                <div
                    class="bg-slate-50/70 border-t border-gray-100 px-6 py-4 text-center"
                >
                    <p
                        class="text-sm font-medium text-gray-500"
                        v-if="detailModal.data"
                    >
                        {{ getEffectiveStatusLabel(detailModal.data) }}
                    </p>
                </div>
            </div>
        </div>
    </Teleport>

    <!-- ================= MODAL INPUT ALASAN PENOLAKAN (KUSTOM) ================= -->
    <!-- Menggantikan prompt() bawaan browser dengan modal bergaya sama seperti
         Modal Detail Cuti di atas (rounded-2xl, backdrop-blur, skema warna
         rose untuk aksi penolakan), supaya tampilan portal konsisten. -->
    <Teleport to="body">
        <div
            v-if="rejectModal.show"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm p-4"
            @click.self="closeRejectModal"
        >
            <div
                class="bg-white rounded-2xl shadow-xl w-full max-w-md overflow-hidden transform transition-all"
            >
                <!-- Header Modal -->
                <div
                    class="flex justify-between items-center px-6 py-4 border-b border-gray-100 bg-rose-50/50"
                >
                    <div
                        class="flex items-center gap-2 text-rose-700 font-semibold"
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
                                d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
                            />
                        </svg>
                        <h3>Alasan Penolakan Cuti</h3>
                    </div>
                    <button
                        type="button"
                        @click="closeRejectModal"
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

                <!-- Body Modal -->
                <div class="p-6 space-y-4">
                    <p class="text-sm text-slate-600 leading-relaxed">
                        Silakan berikan alasan atau catatan mengapa pengajuan
                        cuti ini ditolak. Alasan ini akan dibaca oleh pegawai
                        yang bersangkutan.
                    </p>

                    <!-- Detail ringkas (disamakan dengan Modal Setujui) -->
                    <div
                        v-if="rejectModal.item"
                        class="p-3.5 bg-slate-50 border border-slate-100 rounded-xl text-left"
                    >
                        <div class="flex justify-between items-center mb-1.5">
                            <span
                                class="text-xs font-bold text-slate-400 uppercase tracking-wider"
                                >Karyawan</span
                            >
                            <span class="text-xs font-semibold text-slate-700">
                                {{ rejectModal.item.pegawai?.nama || "-" }}
                            </span>
                        </div>
                        <div class="flex justify-between items-center mb-1.5">
                            <span
                                class="text-xs font-bold text-slate-400 uppercase tracking-wider"
                                >Jenis Cuti</span
                            >
                            <span class="text-xs font-semibold text-rose-600">
                                {{ rejectModal.item.jenis_cuti || "-" }}
                            </span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span
                                class="text-xs font-bold text-slate-400 uppercase tracking-wider"
                                >Tanggal</span
                            >
                            <span class="text-xs font-semibold text-slate-700">
                                {{ formatDate(rejectModal.item.tanggal_mulai) }}
                                s/d
                                {{
                                    formatDate(rejectModal.item.tanggal_selesai)
                                }}
                                ({{ getDurasi(rejectModal.item) }} Hari)
                            </span>
                        </div>
                    </div>

                    <div>
                        <textarea
                            v-model="rejectModal.alasan"
                            rows="3"
                            placeholder="Contoh: Masih ada tugas proyek mendesak yang harus diselesaikan..."
                            class="w-full border-gray-300 focus:border-rose-500 focus:ring-rose-500 rounded-xl text-sm shadow-sm"
                            autofocus
                        ></textarea>
                    </div>
                </div>

                <!-- Footer Modal -->
                <div
                    class="bg-gray-50 px-6 py-3.5 flex justify-end gap-3 border-t border-gray-100"
                >
                    <button
                        type="button"
                        @click="closeRejectModal"
                        class="px-4 py-2 bg-white border border-gray-300 rounded-xl text-xs font-semibold text-gray-700 uppercase tracking-wider hover:bg-gray-50 transition-colors shadow-sm"
                    >
                        Batal
                    </button>
                    <button
                        type="button"
                        @click="submitReject"
                        class="px-4 py-2 bg-red-600 border border-transparent rounded-xl text-xs font-semibold text-white uppercase tracking-wider hover:bg-red-700 transition-colors shadow-sm"
                    >
                        Kirim Penolakan
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
    <!-- ================= MODAL KONFIRMASI SETUJUI CUTI (KUSTOM) ================= -->
    <Teleport to="body">
        <div
            v-if="approveModal.show"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm p-4"
            @click.self="closeApproveModal"
        >
            <div
                class="bg-white rounded-2xl shadow-xl w-full max-w-md overflow-hidden transform transition-all"
            >
                <!-- Header Modal -->
                <div
                    class="flex justify-between items-center px-6 py-4 border-b border-gray-100 bg-emerald-50/50"
                >
                    <div
                        class="flex items-center gap-2 text-emerald-700 font-semibold"
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
                                d="M5 13l4 4L19 7"
                            />
                        </svg>
                        <h3>Setujui Pengajuan Cuti</h3>
                    </div>
                    <button
                        type="button"
                        @click="closeApproveModal"
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

                <!-- Body Modal -->
                <div class="p-6 space-y-4 text-center">
                    <p class="text-sm text-gray-600">
                        Apakah Anda yakin ingin menyetujui pengajuan cuti ini?
                    </p>

                    <!-- Detail ringkas -->
                    <div
                        v-if="approveModal.item"
                        class="p-3.5 bg-slate-50 border border-slate-100 rounded-xl text-left"
                    >
                        <div class="flex justify-between items-center mb-1.5">
                            <span
                                class="text-xs font-bold text-slate-400 uppercase tracking-wider"
                                >Karyawan</span
                            >
                            <span class="text-xs font-semibold text-slate-700">
                                {{ approveModal.item.pegawai?.nama || "-" }}
                            </span>
                        </div>
                        <div class="flex justify-between items-center mb-1.5">
                            <span
                                class="text-xs font-bold text-slate-400 uppercase tracking-wider"
                                >Jenis Cuti</span
                            >
                            <span
                                class="text-xs font-semibold text-emerald-600"
                            >
                                {{ approveModal.item.jenis_cuti || "-" }}
                            </span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span
                                class="text-xs font-bold text-slate-400 uppercase tracking-wider"
                                >Tanggal</span
                            >
                            <span class="text-xs font-semibold text-slate-700">
                                {{
                                    formatDate(approveModal.item.tanggal_mulai)
                                }}
                                s/d
                                {{
                                    formatDate(
                                        approveModal.item.tanggal_selesai,
                                    )
                                }}
                                ({{ getDurasi(approveModal.item) }} Hari)
                            </span>
                        </div>
                    </div>

                    <p class="text-sm text-slate-500 leading-relaxed">
                        <template
                            v-if="
                                approveModal.item?.jenis_cuti
                                    ?.toLowerCase()
                                    .includes('tahunan')
                            "
                        >
                            Saldo Cuti Tahunan karyawan akan dipotong otomatis
                            setelah persetujuan ini dikonfirmasi.
                        </template>
                        <template v-else>
                            Jenis cuti ini tidak akan memotong saldo Cuti
                            Tahunan karyawan setelah persetujuan dikonfirmasi.
                        </template>
                    </p>
                </div>

                <!-- Footer Modal -->
                <div
                    class="bg-gray-50 px-6 py-3.5 flex justify-end gap-3 border-t border-gray-100"
                >
                    <button
                        type="button"
                        @click="closeApproveModal"
                        class="px-4 py-2 bg-white border border-gray-300 rounded-xl text-xs font-semibold text-gray-700 uppercase tracking-wider hover:bg-gray-50 transition-colors shadow-sm"
                    >
                        Batal
                    </button>
                    <button
                        type="button"
                        @click="submitApprove"
                        class="px-4 py-2 bg-emerald-600 border border-transparent rounded-xl text-xs font-semibold text-white uppercase tracking-wider hover:bg-emerald-700 transition-colors shadow-sm"
                    >
                        Ya, Setujui
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>


