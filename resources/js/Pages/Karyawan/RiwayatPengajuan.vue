<script setup>
import MainLayout from "@/Layouts/MainLayout.vue";
import { Head, Link, router, useForm, usePage } from "@inertiajs/vue3";
import { computed, ref, watch } from "vue";

const props = defineProps({
    riwayat: Object,
    filters: Object,
    // Data saldo cuti tahunan milik pegawai yang login, dipakai untuk
    // menampilkan kartu "Rincian Sisa Cuti" di Modal Detail — hanya muncul
    // kalau jenis_cuti pengajuan yang dibuka adalah "Cuti Tahunan". Optional:
    // kalau controller belum mengirim prop ini, kartu tetap tampil dengan
    // angka 0 (tidak error), sampai backend menambahkan datanya.
    stats: {
        type: Object,
        default: () => ({}),
    },
    hariLiburs: {
        type: Array,
        default: () => [],
    },
    // ================= UNIT TANPA L1 (mis. Subbagian TU) =================
    // Dikirim dari CutiController@history: true kalau departemen/kelompok
    // substansi user PUNYA pegawai berlevel L1 (Ketua Tim Kerja). Kalau
    // false, step "L1 - Ketua Tim Kerja" tidak boleh dimasukkan ke timeline
    // approval, karena memang tidak ada jenjang tersebut di unit ybs (staf
    // langsung lapor ke L3/Kasubag TU). Default true supaya tidak mengubah
    // tampilan unit-unit yang memang punya L1.
    ada_atasan_l1: {
        type: Boolean,
        default: true,
    },
});

// Pengguna yang sedang login (dipakai untuk menentukan rute persetujuan
// L1-L4 pada timeline "Catatan / Respon Atasan", disamakan dengan logika
// di MonitoringCuti.vue milik Atasan).
const page = usePage();
const currentUser = computed(() => page.props.auth.user);

// ================= SISA CUTI TAHUNAN (untuk kartu "Rincian Sisa Cuti" di Modal Detail) =================
const isJenisCutiTahunan = (jenisCuti) =>
    (jenisCuti || "Cuti Tahunan").toLowerCase().includes("tahunan");

// Frontend-only, tanpa ubah controller. Dihitung ulang meniru logika Dashboard.vue:
//   - "Tahun Ini" = kuota_tahunan dikurangi cuti_terpakai.
//   - "Saldo Bawaan" = carry-forward tahun lalu, DIBATASI maksimal 6 hari.
// CATATAN: kasus "akumulasi penuh" (carry-forward sampai 12 hari) TIDAK bisa
// dideteksi di halaman ini karena data "sisa 2 tahun lalu" tidak dikirim oleh
// CutiController@history. Batas 6 hari selalu diterapkan sebagai pendekatan aman.
const sisaCutiTahunanInfo = computed(() => {
    const s = props.stats || {};
    const kuotaTahunan = s.kuota_tahunan ?? 12;
    const cutiTerpakai = s.cuti_terpakai ?? 0;
    const sisaTahunLaluMentah = s.carry_forward_normal ?? 0;

    const tahunIni = Math.max(0, kuotaTahunan - cutiTerpakai);
    const tahunLalu = Math.min(6, sisaTahunLaluMentah);
    const total = tahunIni + tahunLalu;

    return { total, tahunIni, tahunLalu };
});

// BARU: rincian yang ditampilkan di Modal Detail. Memakai data dari relasi
// pegawai (sisa_cuti_tersedia, sisa_kuota_tahun_ini, saldo_bawaan_eligible)
// bila backend mengirimnya (seperti di Dashboard), kalau tidak ada otomatis
// jatuh ke perhitungan sisaCutiTahunanInfo di atas.
const rincianSisaCuti = (item) => ({
    total: item?.pegawai?.sisa_cuti_tersedia ?? sisaCutiTahunanInfo.value.total,
    tahunIni:
        item?.pegawai?.sisa_kuota_tahun_ini ??
        sisaCutiTahunanInfo.value.tahunIni,
    saldoBawaan:
        item?.pegawai?.saldo_bawaan_eligible ??
        sisaCutiTahunanInfo.value.tahunLalu,
});
// ================= END SISA CUTI TAHUNAN =================

// ================= FILTER =================
// PEMBARUAN: fitur pencarian ("search berdasarkan keterangan") DIHAPUS.
// Filter yang tersisa hanya Jenis Cuti dan Status.
const status = ref(props.filters?.status || "");
const jenisCutiFilter = ref(props.filters?.jenis_cuti || "");

let timeout = null;
watch(
    [status, jenisCutiFilter],
    ([newStatus, newJenisCuti]) => {
        clearTimeout(timeout);
        timeout = setTimeout(() => {
            router.get(
                route("karyawan.riwayat"),
                {
                    status: newStatus,
                    jenis_cuti: newJenisCuti,
                },
                { preserveState: true, replace: true },
            );
        }, 300);
    },
);

// ==========================================================
// PEMBATALAN CUTI — MASIH MENUNGGU APPROVAL (menunggu_l1/l2/l3/l4)
// Memakai modal konfirmasi custom (bukan window.confirm bawaan browser)
// agar UX konsisten dengan modal Detail & Batalkan Mandiri. Tidak wajib
// isi alasan — cukup konfirmasi.
// ==========================================================
const modalKonfirmasiBatal = ref({
    show: false,
    item: null,
});

const cancelCuti = (item) => {
    modalKonfirmasiBatal.value.item = item;
    modalKonfirmasiBatal.value.show = true;
};

const closeModalKonfirmasiBatal = () => {
    modalKonfirmasiBatal.value.show = false;
    modalKonfirmasiBatal.value.item = null;
};

const eksekusiBatalCuti = () => {
    const item = modalKonfirmasiBatal.value.item;
    if (!item?.id) return;

    router.post(
        route("karyawan.cuti.batal", item.id),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => closeModalKonfirmasiBatal(),
        },
    );
};

// ==========================================================
// PEMBATALAN MANDIRI — CUTI SUDAH "DISETUJUI"
// Wajib isi alasan pembatalan. Saldo cuti otomatis dikembalikan
// tanpa perlu approval ulang dari atasan.
//
// PEMBARUAN: validasi "alasan wajib diisi" sebelumnya memakai alert()
// bawaan browser (tampilannya "sistem", tidak bisa di-styling, dan
// mengganggu alur modal custom). Sekarang diganti validasi inline:
// textarea diberi border merah + pesan error di bawahnya, mengikuti
// pola yang sama seperti modal-modal lain di aplikasi ini (mis. modal
// penolakan pada AntreanApproval.vue).
// ==========================================================
const batalModal = ref({
    show: false,
    id: null,
    alasan: "",
    error: "",
});

const openBatalModal = (id) => {
    batalModal.value.id = id;
    batalModal.value.alasan = "";
    batalModal.value.error = "";
    batalModal.value.show = true;
};

const closeBatalModal = () => {
    batalModal.value.show = false;
    batalModal.value.error = "";
};

const submitBatal = () => {
    if (!batalModal.value.alasan.trim()) {
        batalModal.value.error = "Alasan pembatalan wajib diisi.";
        return;
    }
    batalModal.value.error = "";

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

// ==========================================================
// MODAL DETAIL
// ==========================================================
const selectedDetail = ref(null);

const openDetailModal = (item) => {
    selectedDetail.value = item;
};

const closeDetailModal = () => {
    selectedDetail.value = null;
};

// Status pengajuan yang boleh direvisi (dipakai untuk kondisi tombol
// "Revisi" di tabel, tombol "Revisi Cuti" di footer Modal Detail, maupun
// validasi sebelum membuka Modal Revisi).
const isStatusBisaDirevisi = (item) =>
    item?.status === "ditangguhkan" ||
    item?.status === "dibatalkan_ditangguhkan";

// BARU: dari footer Modal Detail -> tutup detail lalu buka Modal Revisi
// (dedicated) yang sudah ada, jadi tidak ada logika revisi ganda.
const revisiDariDetail = () => {
    const item = selectedDetail.value;
    if (!item) return;
    closeDetailModal();
    konfirmasiRevisi(item);
};

// Nama Status Atasan & Hierarki L4 — disamakan dengan statusLabels di
// MonitoringCuti.vue Admin (nama atasan lengkap per level).
const formatStatus = (statusCode) => {
    switch (statusCode) {
        // ------------------------------------------------------------
        // PEMBARUAN: label "menunggu" sekarang memakai JABATAN + kode
        // level (mis. "Menunggu Kasubag TU (L3)"), bukan nama pejabat.
        // Alasannya: jabatan lebih stabil terhadap pergantian orang, dan
        // lebih ringkas di badge/tabel. Nama lengkap pejabat tetap
        // ditampilkan di Modal Detail (lihat rutePersetujuan).
        // ------------------------------------------------------------
        case "menunggu_l1":
            return {
                text: "Menunggu Ketua Tim Kerja (L1)",
                class: "bg-amber-100 text-amber-700 border border-amber-200",
            };
        case "menunggu_l2":
            return {
                text: "Menunggu Ketua Kelompok Substansi (L2)",
                class: "bg-amber-100 text-amber-700 border border-amber-200",
            };
        case "menunggu_l3":
            return {
                text: "Menunggu Kasubag TU (L3)",
                class: "bg-amber-100 text-amber-700 border border-amber-200",
            };
        case "menunggu_l4":
            return {
                text: "Menunggu Kepala Biro Perencanaan (L4)",
                class: "bg-amber-100 text-amber-700 border border-amber-200",
            };
        case "disetujui":
            return {
                text: "Disetujui",
                class: "bg-green-100 text-green-700 border border-green-200",
            };
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

// Helper: nama atasan yang memproses pengajuan.
// PEMBARUAN: sekarang lebih dulu mencari log 'tangguh'/'tolak' terakhir di
// approval_logs (pemroses sebenarnya), baru jatuh ke fallback lama
// (relasi atasanL1-L4 / level_saat_ini) — fallback lama tetap dipertahankan.
const getNamaAtasanPemroses = (item) => {
    if (!item) return "Bapak Atasan L1";

    const logs = item.approval_logs || item.approvalLogs || [];
    const logPemroses = [...logs]
        .reverse()
        .find((l) => l.keputusan === "tangguh" || l.keputusan === "tolak");
    if (logPemroses?.approver?.nama) return logPemroses.approver.nama;

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
        }[item.level_saat_ini] ||
        "Atasan"
    );
};

// Membersihkan teks catatan atasan: ambil bagian setelah "|", buang prefix
// "[Label: ...]" beserta sisa kurung siku. DIPERTAHANKAN sebagai fallback.
const formatCatatanAtasan = (keterangan, status) => {
    if (!keterangan || !keterangan.includes("|")) {
        return (
            {
                disetujui: "Disetujui dan diteruskan sesuai alur birokrasi.",
                ditolak: "Pengajuan ditolak oleh atasan.",
            }[status] || "Diproses tanpa catatan tambahan."
        );
    }

    return keterangan
        .split("|")[1]
        .replace(/\[.*?:\s*/g, "")
        .replace(/\]/g, "")
        .trim();
};

// ================= CATATAN / RESPON ATASAN (SUMBER DATA ASLI) =================
// getApprovalLogs() membaca LANGSUNG dari relasi approvalLogs (tabel
// approval_logs, sudah di-eager-load di CutiController@history):
//  - MonitoringCutiController mencatat log 'setuju'/'tolak' dengan
//    level_approval 1-4 setiap kali atasan memproses approval.
//  - PembatalanController mencatat log 'tangguh' dengan level_approval = 5
//    (penanda khusus penangguhan oleh L4) beserta `catatan` = alasannya.
const getApprovalLogs = (item) => {
    const logs = item?.approval_logs || item?.approvalLogs || [];
    if (logs.length) return logs;

    // Fallback HANYA untuk data lama (sebelum tabel approval_logs dipakai).
    const legacyLogs = [];
    if (item?.atasanL1?.nama || item?.atasan_l1?.nama) {
        legacyLogs.push({
            id: `legacy-l1-${item.id}`,
            level_approval: 1,
            approver: item.atasanL1 || item.atasan_l1,
            keputusan: "setuju",
        });
    }
    if (item?.atasanL3?.nama || item?.atasan_l3?.nama) {
        legacyLogs.push({
            id: `legacy-l3-${item.id}`,
            level_approval: 3,
            approver: item.atasanL3 || item.atasan_l3,
            keputusan: "setuju",
        });
    }
    if (item?.atasanL4?.nama || item?.atasan_l4?.nama) {
        legacyLogs.push({
            id: `legacy-l4-${item.id}`,
            level_approval: 4,
            approver: item.atasanL4 || item.atasan_l4,
            keputusan: "setuju",
        });
    }
    return legacyLogs;
};

// Level 5 = penanda khusus penangguhan oleh L4 (Kepala Biro), sesuai
// ApprovalLog::create() di PembatalanController@process.
const getApprovalLevelLabel = (level) =>
    ({
        1: "L1 - Ketua Tim Kerja",
        2: "L2 - Ketua Kelompok Substansi",
        3: "L3 - Kasubag TU",
        4: "L4 - Kepala Biro Perencanaan",
        5: "Penangguhan oleh Kepala Biro",
    })[level] || `Level ${level}`;

// Label & warna badge per nilai kolom `keputusan` di approval_logs
// ('setuju' | 'tolak' | 'tangguh'). 'tangguh' SENGAJA memakai warna
// silver/abu (bukan merah) supaya tidak dikesankan sama seperti penolakan.
const keputusanLabel = (k) =>
    ({ setuju: "Disetujui", tolak: "Ditolak", tangguh: "Ditangguhkan" })[k] ||
    k;

const keputusanClass = (k) =>
    ({
        setuju: "bg-emerald-50 text-emerald-700 border border-emerald-200",
        tolak: "bg-red-50 text-red-700 border border-red-200",
        tangguh: "bg-slate-200 text-slate-700 border border-slate-300",
    })[k] || "bg-slate-100 text-slate-600 border border-slate-200";

// BARU: warna teks status per entri (dipakai di daftar riwayat persetujuan).
const keputusanTextClass = (k) =>
    ({
        setuju: "text-emerald-600",
        tolak: "text-red-600",
        tangguh: "text-orange-600",
    })[k] || "text-slate-600";
// ================= END CATATAN / RESPON ATASAN =================

// ================= JEJAK APPROVAL LENGKAP (disamakan dengan RekapKuotaDetail.vue Admin) =================
const approvalStepLabel = (statusStep) => {
    switch (statusStep) {
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
            return statusStep;
    }
};

const approvalStepClass = (statusStep) => {
    switch (statusStep) {
        case "setuju":
            return "text-emerald-600";
        case "tolak":
            return "text-rose-600";
        case "menunggu":
            return "text-amber-600";
        case "belum_giliran":
            return "text-gray-400";
        case "tangguh":
            return "text-slate-600 font-medium";
        default:
            return "text-gray-500";
    }
};

// Pembersih kalimat keterangan (konsisten dengan RekapKuotaDetail.vue Admin
// & AntreanApproval.vue). PembatalanController@process menyimpan bracket
// dengan kata "ATASAN" — regex menerima ALASAN maupun ATASAN.
const formatKeteranganRapi = (text) => {
    if (!text || text === "-") return "-";

    if (text.includes("|") || text.includes("[DITANGGUHKAN")) {
        let bagian = text.split("|").map((item) => item.trim());
        let alasanAwal =
            bagian[0] && bagian[0] !== "-" ? bagian[0] : "Ada keperluan";

        let regex = /\[DITANGGUHKAN\/DIBATALKAN (?:ALASAN|ATASAN):\s*(.*?)\]/i;
        let match = text.match(regex);

        if (match && match[1]) {
            let catatanAtasan = match[1].trim();
            return `${alasanAwal} (Ditangguhkan: ${catatanAtasan})`;
        }

        return alasanAwal;
    }

    return text;
};
// ================= END JEJAK APPROVAL LENGKAP =================

// ================= TIMELINE PERSETUJUAN (FALLBACK, dari MonitoringCuti Atasan) =================
// ----------------------------------------------------------------
// PEMBARUAN: label "menunggu" di badge tabel Riwayat (dipakai lewat
// getEffectiveStatusLabel) sekarang memakai JABATAN + kode level saja
// (mis. "Menunggu Kasubag TU (L3)"), tanpa nama pejabat — supaya badge
// tetap ringkas dan tidak berubah kalau pejabatnya diganti orang. Nama
// lengkap tetap tampil di Modal Detail lewat rutePersetujuan.
// ----------------------------------------------------------------
const statusLabels = {
    menunggu_l1: "Menunggu Ketua Tim Kerja (L1)",
    menunggu_l2: "Menunggu Ketua Kelompok Substansi (L2)",
    menunggu_l3: "Menunggu Kasubag TU (L3)",
    menunggu_l4: "Menunggu Kepala Biro Perencanaan (L4)",
    disetujui: "Disetujui",
    ditolak: "Ditolak",
    ditangguhkan: "Ditangguhkan",
    dibatalkan_reguler: "Dibatalkan (Reguler)",
    dibatalkan_ditangguhkan: "Ditangguhkan",
};

const getStatusLabel = (st) => {
    return statusLabels[st] || st?.replace(/_/g, " ").toUpperCase() || "-";
};

// Status penangguhan lama disimpan dengan nilai mentah 'ditolak' di
// database, dibedakan lewat teks kolom 'keterangan'.
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

const getEffectiveStatusLabel = (item) => {
    return getStatusLabel(getEffectiveStatus(item));
};

// ================= LABEL SINGKAT UNTUK BADGE HEADER MODAL DETAIL =================
// Dipakai KHUSUS untuk badge status pada header Modal Detail (mis.
// "MENUNGGU L3"). TIDAK menggantikan getEffectiveStatusLabel() yang tetap
// dipakai di kolom Status tabel riwayat.
const getStatusBadgeLabelSingkat = (item) => {
    const st = getEffectiveStatus(item);
    if (!st) return "STATUS";

    const levelMap = {
        menunggu_l1: "MENUNGGU L1",
        menunggu_l2: "MENUNGGU L2",
        menunggu_l3: "MENUNGGU L3",
        menunggu_l4: "MENUNGGU L4",
        disetujui: "DISETUJUI",
        ditolak: "DITOLAK",
        ditangguhkan: "DITANGGUHKAN",
        dibatalkan_ditangguhkan: "DITANGGUHKAN",
        dibatalkan_reguler: "DIBATALKAN",
    };

    return levelMap[st] || st.replace(/_/g, " ").toUpperCase();
};
// ================= END LABEL SINGKAT BADGE =================

// ================= BARU: HELPER BLOK "RESPON ATASAN / PEMBATALAN" (gaya Dashboard) =================
const isStatusDibatalkanReguler = (item) =>
    item?.status === "dibatalkan_reguler" || item?.status === "dibatalkan";

const isStatusDitangguhkan = (item) => {
    const st = getEffectiveStatus(item);
    return st === "ditangguhkan" || st === "dibatalkan_ditangguhkan";
};

const isStatusDibatalkanAtauDitangguhkan = (item) =>
    isStatusDitangguhkan(item) || isStatusDibatalkanReguler(item);

const getNamaPembatal = (item) => {
    return (
        item?.dibatalkan_oleh?.nama || item?.dibatalkanOleh?.nama || "Pemohon"
    );
};

const getCatatanPembatalanReguler = (item) => {
    if (item?.alasan_pembatalan) return item.alasan_pembatalan;
    if (item?.alasanPembatalan) return item.alasanPembatalan;
    if (item?.catatan_pembatalan) return item.catatan_pembatalan;
    if (item?.keterangan && item.keterangan.includes("|")) {
        const isi = item.keterangan
            .split("|")[1]
            .replace(/\[.*?:\s*/g, "")
            .replace(/\]/g, "")
            .trim();
        if (isi) return isi;
    }
    return "Pengajuan cuti telah dibatalkan secara mandiri oleh pemohon sebelum proses persetujuan selesai.";
};

// Daftar histori persetujuan L1-L4 (tanpa log penangguhan level 5) dalam
// bentuk yang siap dirender di template.
const jabatanPerLevel = {
    1: "Ketua Tim Kerja",
    2: "Ketua Kelompok Substansi",
    3: "Kasubag TU",
    4: "Kepala Biro Perencanaan",
};

const getCatatanAtasanList = (item) => {
    return getApprovalLogs(item)
        .filter((log) => Number(log.level_approval) <= 4)
        .map((log) => ({
            key: log.id,
            level: log.level_approval,
            jabatan: jabatanPerLevel[log.level_approval] || "Atasan",
            nama: log.approver?.nama || "-",
            catatan: log.catatan || "",
            statusText: keputusanLabel(log.keputusan),
            statusColorClass: keputusanTextClass(log.keputusan),
        }));
};

// Alasan utama dari atasan: prioritas log 'tangguh'/'tolak' terakhir,
// lalu log terakhir yang punya catatan, lalu teks di kolom keterangan.
const getCatatanAtasan = (item) => {
    const logs = getApprovalLogs(item);

    const logUtama = [...logs]
        .reverse()
        .find(
            (l) =>
                (l.keputusan === "tangguh" || l.keputusan === "tolak") &&
                l.catatan,
        );
    if (logUtama) return logUtama.catatan;

    const logBerCatatan = [...logs].reverse().find((l) => l.catatan);
    if (logBerCatatan) return logBerCatatan.catatan;

    const isi = getIsiCatatanMentah(item?.keterangan);
    if (isi) return isi;

    return (
        {
            disetujui: "Disetujui dan diteruskan sesuai alur birokrasi.",
            ditolak: "Pengajuan ditolak oleh atasan.",
        }[item?.status] || "Diproses tanpa catatan tambahan."
    );
};

// Jabatan atasan yang memproses penangguhan/penolakan (mis. "Kepala Biro
// Perencanaan"), seperti tampilan Dashboard. Fallback ke nama pemroses lama.
const getJabatanPemroses = (item) => {
    const logs = getApprovalLogs(item);
    const log = [...logs]
        .reverse()
        .find((l) => l.keputusan === "tangguh" || l.keputusan === "tolak");
    if (log) {
        const level = Number(log.level_approval);
        // level 5 = penangguhan oleh L4 (Kepala Biro)
        const jabatan = jabatanPerLevel[level === 5 ? 4 : level];
        if (jabatan) return jabatan;
    }
    return getNamaAtasanPemroses(item);
};

// Class kotak pembungkus & ikon/judul, berdasarkan status.
const blokResponClass = (item) => {
    if (getEffectiveStatus(item) === "ditolak")
        return "bg-red-50 border-red-200";
    if (isStatusDibatalkanReguler(item)) return "bg-slate-50 border-slate-200";
    if (isStatusDitangguhkan(item)) return "bg-orange-50 border-orange-100";
    if (item?.status === "disetujui") return "bg-emerald-50 border-emerald-100";
    return "bg-orange-50 border-orange-100";
};

const blokIkonClass = (item) => {
    if (getEffectiveStatus(item) === "ditolak") return "text-red-600";
    if (isStatusDibatalkanReguler(item)) return "text-slate-500";
    if (isStatusDitangguhkan(item)) return "text-orange-500";
    if (item?.status === "disetujui") return "text-emerald-600";
    return "text-orange-500";
};

const blokJudulClass = (item) => {
    if (getEffectiveStatus(item) === "ditolak") return "text-red-700";
    if (isStatusDibatalkanReguler(item)) return "text-slate-600";
    if (isStatusDitangguhkan(item)) return "text-orange-600";
    if (item?.status === "disetujui") return "text-emerald-700";
    return "text-orange-600";
};

const blokJudulText = (item) => {
    if (isStatusDibatalkanReguler(item)) return "Informasi Pembatalan";
    if (isStatusDitangguhkan(item))
        return "Alasan Penangguhan / Pembatalan Atasan";
    return "Catatan / Respon Atasan";
};
// ================= END HELPER BLOK RESPON =================

// Alasan murni pegawai (tanpa catatan/nama atasan tercampur).
const getAlasanBersih = (text) => {
    if (!text || text === "-") return "-";

    if (text.includes("|") || text.includes("[DITANGGUHKAN")) {
        let bagian = text.split("|").map((item) => item.trim());
        let alasanAwal =
            bagian[0] && bagian[0] !== "-" ? bagian[0] : "Ada keperluan";
        return alasanAwal;
    }

    if (
        text.includes("(") &&
        /oleh|ditolak|ditangguhkan|dibatalkan/i.test(text)
    ) {
        let alasanAwal = text.split("(")[0].trim();
        return alasanAwal !== "" ? alasanAwal : text;
    }

    return text;
};

const getIsiCatatanMentah = (text) => {
    if (!text) return "";

    // Bracket disimpan dengan kata "ATASAN" (bukan "ALASAN") oleh
    // PembatalanController@process; regex menerima keduanya supaya data lama
    // ikut tampil tanpa migrasi.
    const bracketRegex =
        /\[DITANGGUHKAN\/DIBATALKAN (?:ALASAN|ATASAN):\s*(.*?)\]/i;
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

const getCatatanAtasanLevel = (text) => {
    const isi = getIsiCatatanMentah(text);
    if (!isi) return "";

    if (/oleh/i.test(isi) && isi.includes(":")) {
        const parts = isi.split(":");
        return parts.slice(1).join(":").trim();
    }

    return isi;
};

// ================= [PERBAIKAN] DEFINISI 4 LEVEL PERSETUJUAN =================
// levelsDefinition adalah FUNGSI yang menerima `item` (data pengajuan),
// sehingga nama L1/L2 diambil dari `item.pegawai.nama_l1` /
// `item.pegawai.nama_l2` — data yang sudah dihitung dan ditempelkan oleh
// CutiController@history (lihat accessor ketua_tim_kerja / ketua_kelompok
// di model Pegawai). Kalau data belum tersedia, fallback ke label jabatan
// generik.
// ================= END PERBAIKAN =================
const getLevelsDefinition = (item) => [
    {
        key: 1,
        jabatan: "L1 - Ketua Tim Kerja",
        nama: item?.pegawai?.nama_l1 || "Ketua Tim Kerja",
    },
    {
        key: 2,
        jabatan: "L2 - Ketua Kelompok Substansi",
        nama: item?.pegawai?.nama_l2 || "Ketua Kelompok Substansi",
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

// ================================================================
// [PERBAIKAN] RUTE PERSETUJUAN — DINAMIS PER-ITEM, BUKAN TEBAKAN GLOBAL
// BERDASARKAN roleId + flag biner ada_atasan_l1.
//
// Levelnya dideteksi LANGSUNG dari data pengajuan itu sendiri
// (approval_logs & status), bukan dari tebakan berbasis role/flag. Ini
// otomatis benar untuk semua skenario: staf dgn L1 ([1,3,4]), staf tanpa
// L1 tapi ada L2 ([2,3,4]), staf tanpa L1 & L2 ([3,4]), maupun Atasan yang
// mengajukan cuti sendiri (L1 sendiri -> [2,3,4], L2 sendiri -> [3,4], L3
// sendiri -> [4]).
//
// getLevelAwalUntukItem(): level PERTAMA yang dilalui pengajuan ini,
// dengan prioritas: (1) log approval_logs di level 1/2 kalau sudah ada —
// ini sumber kebenaran paling akurat karena mencatat apa yang BENAR-
// BENAR terjadi; (2) kalau belum ada log, baca dari status saat ini
// ('menunggu_lX'); (3) kalau status sudah lewat L1/L2 tanpa log di sana,
// berarti pengajuan ini memang tidak pernah melalui L1/L2.
// ================================================================
const getLevelAwalUntukItem = (item) => {
    if (!item) return null;

    const logs = getApprovalLogs(item);
    const levelLogValid = logs
        .map((l) => Number(l.level_approval))
        .filter((l) => l >= 1 && l <= 4);

    if (levelLogValid.length) {
        return Math.min(...levelLogValid);
    }

    const pendingMatch = item?.status?.match(/^menunggu_l(\d)$/);
    if (pendingMatch) return parseInt(pendingMatch[1], 10);

    return null;
};

// Rute persetujuan berjenjang, ditentukan dari data pengajuan itu
// sendiri (lihat catatan di atas), bukan lagi dari roleId + flag biner
// ada_atasan_l1. Parameter menerima `item` supaya setiap pengajuan
// dievaluasi berdasarkan datanya masing-masing.
const getApprovalRoute = (item) => {
    const levelAwal = getLevelAwalUntukItem(item);

    let levels;
    if (levelAwal === null) {
        levels = [3, 4]; // fallback aman
    } else if (levelAwal <= 2) {
        levels = [levelAwal, 3, 4];
    } else if (levelAwal === 3) {
        levels = [3, 4];
    } else {
        levels = [4];
    }

    const definisi = getLevelsDefinition(item);
    return levels.map((lvl) => definisi[lvl - 1]);
};

// Menyusun status tiap level pada rute yang berlaku, berdasarkan
// selectedDetail. DIPERTAHANKAN sebagai fallback cadangan — tampilan
// utama memakai getApprovalLogs().
const approvalTimeline = computed(() => {
    const data = selectedDetail.value;
    if (!data) return [];

    const st = data.status;
    const steps = getApprovalRoute(data);

    const pendingMatch = st?.match(/^menunggu_l(\d)$/);
    const pendingLevel = pendingMatch ? parseInt(pendingMatch[1], 10) : null;
    const isStoppedStatus =
        st === "ditolak" ||
        st === "ditangguhkan" ||
        st === "dibatalkan_ditangguhkan";

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

    const catatanAtasan =
        stoppedLevel !== null ? getCatatanAtasanLevel(data.keterangan) : "";
    const catatanMenyebutDitangguhkan = isKeteranganDitangguhkan(
        data.keterangan,
    );

    return steps.map((level) => {
        let label = "-";
        let color = "text-gray-400";
        let catatan = "";
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
                label = "Disetujui";
                color = "text-emerald-700";
            } else if (level.key === stoppedLevel) {
                label =
                    st === "ditolak" && !catatanMenyebutDitangguhkan
                        ? "Ditolak"
                        : "Ditangguhkan";
                color = "text-slate-700";
                if (catatanAtasan) {
                    catatan = catatanAtasan;
                }
            } else if (level.key > stoppedLevel) {
                label = "-";
                color = "text-gray-400";
            }
        }

        return { ...level, nama, status: label, color, catatan };
    });
});
// BARU: rute persetujuan per level (L1/L3/L4 dst.) untuk Modal Detail, format
// sama seperti Dashboard: "L3 - Kasubag TU: Nama" + status di bawahnya.
// Nama & keputusan diambil dari approval_logs bila ada; kalau belum ada
// (masih menunggu) jatuh ke approvalTimeline.
const rutePersetujuan = computed(() => {
    const item = selectedDetail.value;
    if (!item) return [];
    const logs = getApprovalLogs(item);

    // Sama seperti Dashboard: selama status masih "menunggu_lX", level X
    // DAN semua level sesudahnya tampil "Menunggu Persetujuan" (oranye).
    const pendingMatch = item.status?.match(/^menunggu_l(\d)$/);
    const pendingLevel = pendingMatch ? parseInt(pendingMatch[1], 10) : null;
    // Pengajuan dibatalkan pemohon: level yang belum sempat diproses
    // (belum ada log) ditampilkan sebagai "Menunggu Persetujuan".
    const dibatalkanReguler = isStatusDibatalkanReguler(item);
    const statusDitolak = getEffectiveStatus(item) === "ditolak";

    return approvalTimeline.value.map((step) => {
        const log = logs.find((l) => Number(l.level_approval) === step.key);
        const masihMenunggu =
            !log &&
            (dibatalkanReguler ||
                isStatusDitangguhkan(item) ||
                statusDitolak ||
                (pendingLevel !== null && step.key >= pendingLevel));

        return {
            key: step.key,
            jabatan: step.jabatan,
            nama: log?.approver?.nama || step.nama,
            status: log
                ? log.catatan ||
                  {
                      setuju: "Disetujui.",
                      tolak: "Ditolak oleh atasan.",
                      tangguh: "Ditangguhkan.",
                  }[log.keputusan] ||
                  keputusanLabel(log.keputusan)
                : masihMenunggu
                  ? "Menunggu Persetujuan"
                  : step.status,
            color: log
                ? keputusanTextClass(log.keputusan)
                : masihMenunggu
                  ? "text-orange-600"
                  : step.color,
        };
    });
});
// ================= END TIMELINE PERSETUJUAN =================

// ================= PENGURUTAN DATA (SORTING TIGA PRIORITAS) =================
// BARU: menggantikan urutan mentah dari backend. Status diurutkan supaya
// yang paling butuh perhatian tampil paling atas:
//   1) Ditangguhkan / Dibatalkan (Ditangguhkan) — perlu direvisi
//   2) Menunggu Persetujuan Atasan (L1-L4)
//   3) Selesai (Disetujui, Ditolak, Dibatalkan Reguler)
// Dalam satu grup yang sama, diurutkan berdasarkan tanggal aksi terbaru
// (grup 1 & 3) atau tanggal pengajuan terbaru (grup 2).
const sortedRiwayatData = computed(() => {
    if (!props.riwayat?.data) return [];

    return [...props.riwayat.data].sort((a, b) => {
        const getGroup = (item) => {
            const effStatus = getEffectiveStatus(item);

            // Prioritas Pertama: Ditangguhkan (Revisi)
            if (
                effStatus === "ditangguhkan" ||
                effStatus === "dibatalkan_ditangguhkan"
            )
                return 1;

            // Prioritas Kedua: Menunggu Persetujuan Atasan
            if (effStatus?.includes("menunggu")) return 2;

            // Prioritas Ketiga: Selesai (Disetujui, Ditolak, Dibatalkan Reguler)
            return 3;
        };

        const groupA = getGroup(a);
        const groupB = getGroup(b);

        if (groupA !== groupB) {
            return groupA - groupB;
        }

        const dateAUpdate = new Date(a.updated_at || a.created_at || 0).getTime();
        const dateBUpdate = new Date(b.updated_at || b.created_at || 0).getTime();
        const dateACreate = new Date(a.created_at || a.updated_at || 0).getTime();
        const dateBCreate = new Date(b.created_at || b.updated_at || 0).getTime();

        if (groupA === 1) {
            // Grup 1 (Ditangguhkan): Berdasarkan Updated At (Aksi Terbaru)
            return dateBUpdate - dateAUpdate;
        } else if (groupA === 2) {
            // Grup 2 (Menunggu): Berdasarkan Created At (Tanggal Submit)
            return dateBCreate - dateACreate;
        } else {
            // Grup 3 (Selesai): Berdasarkan Updated At (Aksi Terbaru)
            return dateBUpdate - dateAUpdate;
        }
    });
});
// ================= END PENGURUTAN DATA =================

// ==========================================================
// MODAL REVISI (DEDICATED)
// Begitu tombol "Revisi" (di tabel maupun di footer Modal Detail) ditekan,
// modal ringkas ini terbuka: instruksi/catatan atasan di atas, form tanggal
// baru di bawahnya. Validasi hari kerja & larangan tanggal hanya
// Sabtu/Minggu dipertahankan.
// ==========================================================
const showModalRevisi = ref(false);
const cutiYangDirevisi = ref(null);

const formRevisi = useForm({
    tanggal_mulai: "",
    tanggal_selesai: "",
});

const konfirmasiRevisi = (item) => {
    cutiYangDirevisi.value = item;
    formRevisi.reset();
    formRevisi.clearErrors();
    showModalRevisi.value = true;
};

const tutupModalRevisi = () => {
    showModalRevisi.value = false;
    cutiYangDirevisi.value = null;
    formRevisi.reset();
    formRevisi.clearErrors();
};

const minDate = computed(() => new Date().toISOString().split("T")[0]);

const toDateStr = (date) => {
    const yyyy = date.getFullYear();
    const mm = String(date.getMonth() + 1).padStart(2, "0");
    const dd = String(date.getDate()).padStart(2, "0");
    return `${yyyy}-${mm}-${dd}`;
};

const hariLiburSet = computed(
    () => new Set(props.hariLiburs.map((hari) => hari.tanggal)),
);

const liburBertepatan = computed(() => {
    if (!formRevisi.tanggal_mulai || !formRevisi.tanggal_selesai) return [];

    const start = new Date(formRevisi.tanggal_mulai);
    const end = new Date(formRevisi.tanggal_selesai);
    if (start > end) return [];

    const hasil = [];
    const current = new Date(start);
    while (current <= end) {
        const tanggal = toDateStr(current);
        const libur = props.hariLiburs.find((hari) => hari.tanggal === tanggal);
        if (libur) hasil.push(libur);
        current.setDate(current.getDate() + 1);
    }

    return hasil;
});

const jumlahHariKerja = computed(() => {
    if (!formRevisi.tanggal_mulai || !formRevisi.tanggal_selesai) return 0;

    const start = new Date(formRevisi.tanggal_mulai);
    const end = new Date(formRevisi.tanggal_selesai);
    if (start > end) return 0;

    let count = 0;
    const current = new Date(start);
    while (current <= end) {
        const tanggal = toDateStr(current);
        if (
            current.getDay() !== 0 &&
            current.getDay() !== 6 &&
            !hariLiburSet.value.has(tanggal)
        ) {
            count++;
        }
        current.setDate(current.getDate() + 1);
    }
    return count;
});

const isInvalidWeekendOnly = computed(() => {
    if (!formRevisi.tanggal_mulai || !formRevisi.tanggal_selesai) return false;

    const start = new Date(formRevisi.tanggal_mulai);
    const end = new Date(formRevisi.tanggal_selesai);
    if (start > end) return false;

    const current = new Date(start);
    while (current <= end) {
        const tanggal = toDateStr(current);
        if (
            current.getDay() !== 0 &&
            current.getDay() !== 6 &&
            !hariLiburSet.value.has(tanggal)
        ) {
            return false;
        }
        current.setDate(current.getDate() + 1);
    }
    return true;
});

// Catatan/instruksi atasan yang ditampilkan di Modal Revisi — sekarang
// diambil dari log 'tangguh' di approval_logs (sumber asli), lalu approval_chain
// (jika suatu saat tersedia), lalu fallback ke kolom keterangan lama.
const catatanAtasanUntukRevisi = computed(() => {
    const item = cutiYangDirevisi.value;
    if (!item) return "";

    const logTangguh = [...getApprovalLogs(item)]
        .reverse()
        .find((l) => l.keputusan === "tangguh" && l.catatan);
    if (logTangguh) return logTangguh.catatan;

    const stepTangguh = (item.approval_chain || []).find(
        (s) => s.status === "tangguh",
    );
    if (stepTangguh?.catatan) return stepTangguh.catatan;

    const isi = getIsiCatatanMentah(item.keterangan);
    if (isi) return isi;

    return "Silakan sesuaikan kembali tanggal pengajuan cuti Anda.";
});

const eksekusiRevisi = () => {
    if (!cutiYangDirevisi.value?.id) return;
    if (isInvalidWeekendOnly.value || jumlahHariKerja.value === 0) return;

    formRevisi.post(route("karyawan.cuti.revisi", cutiYangDirevisi.value.id), {
        preserveScroll: true,
        onSuccess: () => tutupModalRevisi(),
    });
};
</script>

<template>
    <Head title="Riwayat Pengajuan Cuti" />

    <MainLayout>
        <div class="max-w-7xl mx-auto space-y-6 pb-12">
            <div
                class="flex flex-col md:flex-row md:items-center md:justify-between gap-4"
            >
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

                <!-- Fitur pencarian berdasarkan keterangan DIHAPUS. Filter yang
                     tersisa: Jenis Cuti & Status. -->
                <div
                    class="flex flex-col sm:flex-row items-center gap-3 w-full md:w-auto"
                >
                    <select
                        v-model="jenisCutiFilter"
                        class="w-full sm:w-48 py-2 px-3 text-xs border border-slate-200 rounded-xl focus:ring-green-500 focus:border-green-500 bg-white text-slate-600"
                    >
                        <option value="">Semua Jenis Cuti</option>
                        <option value="Cuti Tahunan">Cuti Tahunan</option>
                        <option value="Cuti Besar">Cuti Besar</option>
                        <option value="Cuti Melahirkan">
                            Cuti Melahirkan
                        </option>
                        <option value="Cuti Alasan Penting">
                            Cuti Alasan Penting
                        </option>
                    </select>

                    <!-- Dropdown Filter Status (teks singkat gaya Admin, tanpa L2) -->
                    <select
                        v-model="status"
                        class="w-full sm:w-44 py-2 px-3 text-xs border border-slate-200 rounded-xl focus:ring-green-500 focus:border-green-500 bg-white text-slate-600"
                    >
                        <option value="">Semua Status</option>
                        <option value="menunggu_l1">Menunggu L1</option>
                        <!-- Opsi "Menunggu L2" sengaja dilewati: alur L2
                             tidak dipakai di halaman Riwayat Pegawai ini. -->
                        <option value="menunggu_l3">Menunggu L3</option>
                        <option value="menunggu_l4">Menunggu L4</option>
                        <option value="disetujui">Disetujui</option>
                        <option value="ditolak">Ditolak</option>
                        <option value="dibatalkan_ditangguhkan">
                            Ditangguhkan
                        </option>
                        <option value="dibatalkan_reguler">
                            Dibatalkan (Reguler)
                        </option>
                    </select>
                </div>
            </div>

            <div
                class="bg-white shadow-sm border border-slate-200 rounded-2xl overflow-hidden"
            >
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-100">
                        <thead class="bg-slate-50/50">
                            <tr>
                                <th
                                    scope="col"
                                    class="px-6 py-4 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wider"
                                >
                                    Jenis Cuti
                                </th>
                                <th
                                    scope="col"
                                    class="px-6 py-4 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wider"
                                >
                                    Tanggal Cuti
                                </th>
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
                                    Status
                                </th>
                                <!-- Kolom "Keterangan" DIHAPUS dari tabel — informasi
                                     alasan/keterangan cuti sudah tersedia lengkap di
                                     Modal Detail (klik tombol ikon mata), jadi tidak
                                     perlu diduplikasi di sini. -->
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
                                v-for="item in sortedRiwayatData"
                                :key="item.id"
                                class="hover:bg-slate-50/70 transition-colors"
                            >
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span
                                        class="px-2.5 py-1 inline-flex text-xs font-semibold rounded-lg bg-blue-100 text-blue-800 border border-blue-200"
                                    >
                                        {{ item.jenis_cuti ?? "Cuti Tahunan" }}
                                    </span>
                                </td>

                                <td
                                    class="px-6 py-4 whitespace-nowrap text-sm text-slate-600 font-medium"
                                >
                                    {{ formatDate(item.tanggal_mulai) }} -
                                    {{ formatDate(item.tanggal_selesai) }}
                                </td>

                                <td
                                    class="px-6 py-4 whitespace-nowrap text-sm text-slate-600 font-semibold"
                                >
                                    {{ item.jumlah_hari }} Hari
                                </td>

                                <!-- BADGE STATUS TABEL: "ditangguhkan" /
                                     "dibatalkan_ditangguhkan" memakai warna silver/abu
                                     (bg-slate-200), merah HANYA untuk "ditolak" murni. -->
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span
                                        class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-bold tracking-wide uppercase border"
                                        :class="{
                                            'bg-amber-100 text-amber-800 border-amber-300':
                                                item.status?.includes(
                                                    'menunggu',
                                                ),
                                            'bg-emerald-100 text-emerald-800 border-emerald-300':
                                                item.status === 'disetujui',
                                            'bg-rose-100 text-rose-800 border-rose-300':
                                                getEffectiveStatus(item) ===
                                                'ditolak',
                                            'bg-slate-200 text-slate-700 border-slate-300':
                                                getEffectiveStatus(item) ===
                                                    'ditangguhkan' ||
                                                item.status ===
                                                    'dibatalkan_ditangguhkan',
                                            'bg-gray-200 text-gray-800 border-gray-300':
                                                item.status ===
                                                'dibatalkan_reguler',
                                            'bg-gray-200 text-gray-600 border-gray-300':
                                                !item.status,
                                        }"
                                    >
                                        {{ getEffectiveStatusLabel(item) }}
                                    </span>
                                </td>

                                <!-- ================= KOLOM AKSI ================= -->
                                <td
                                    colspan="3"
                                    class="px-4 py-4 whitespace-nowrap"
                                >
                                    <div
                                        class="grid grid-cols-[36px_112px_112px] gap-2.5 items-center justify-center mx-auto w-fit"
                                    >
                                        <!-- A. Tombol Detail (Ikon Mata) -->
                                        <button
                                            type="button"
                                            @click.prevent="
                                                openDetailModal(item)
                                            "
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

                                        <!-- B. Tombol Cetak PDF (Hanya jika disetujui) -->
                                        <a
                                            v-if="item.status === 'disetujui'"
                                            :href="
                                                route(
                                                    'karyawan.cuti.pdf',
                                                    item.id,
                                                )
                                            "
                                            target="_blank"
                                            class="inline-flex items-center justify-center gap-1 px-2.5 py-1.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-600 border border-indigo-100 rounded-xl text-xs font-semibold transition shadow-sm w-full"
                                            title="Cetak PDF"
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
                                                    d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                                                ></path>
                                            </svg>
                                            Cetak PDF
                                        </a>
                                        <span
                                            v-else
                                            class="inline-flex items-center justify-center gap-1 px-2.5 py-1.5 bg-slate-100 text-slate-400 border border-slate-200 rounded-xl text-xs font-semibold cursor-not-allowed select-none w-full"
                                        >
                                            Cetak PDF
                                        </span>

                                        <!-- C. KONTROL TOMBOL: REVISI / BATALKAN -->

                                        <!-- Ditangguhkan -> buka Modal Revisi tersendiri -->
                                        <button
                                            v-if="isStatusBisaDirevisi(item)"
                                            type="button"
                                            @click="konfirmasiRevisi(item)"
                                            class="inline-flex items-center justify-center gap-1 px-3 py-1.5 bg-amber-500 hover:bg-amber-600 text-white rounded-xl text-xs font-bold transition shadow-sm cursor-pointer w-full"
                                            title="Revisi Pengajuan Cuti"
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
                                                    d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"
                                                ></path>
                                            </svg>
                                            Revisi
                                        </button>

                                        <!-- Status MASIH MENUNGGU (antre approval L1-L4) -->
                                        <button
                                            v-else-if="
                                                [
                                                    'menunggu_l1',
                                                    'menunggu_l2',
                                                    'menunggu_l3',
                                                    'menunggu_l4',
                                                ].includes(item.status)
                                            "
                                            type="button"
                                            @click="cancelCuti(item)"
                                            class="inline-flex items-center justify-center gap-1 px-2.5 py-1.5 bg-red-50 hover:bg-red-100 text-red-600 border border-red-200 rounded-xl text-xs font-semibold transition shadow-sm cursor-pointer w-full"
                                            title="Batalkan Pengajuan"
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
                                                    d="M6 18L18 6M6 6l12 12"
                                                ></path>
                                            </svg>
                                            Batalkan
                                        </button>

                                        <!-- Status SUDAH DISETUJUI -->
                                        <button
                                            v-else-if="
                                                item.status === 'disetujui'
                                            "
                                            type="button"
                                            @click="openBatalModal(item.id)"
                                            class="inline-flex items-center justify-center gap-1 px-2.5 py-1.5 bg-red-50 hover:bg-red-100 text-red-600 border border-red-200 rounded-xl text-xs font-semibold transition shadow-sm cursor-pointer w-full"
                                            title="Batalkan Pengajuan"
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
                                                    d="M6 18L18 6M6 6l12 12"
                                                ></path>
                                            </svg>
                                            Batalkan
                                        </button>

                                        <!-- Status lain (ditolak, dibatalkan_reguler, dll.) -->
                                        <span
                                            v-else
                                            class="inline-flex items-center justify-center gap-1 px-2.5 py-1.5 bg-slate-100 text-slate-400 border border-slate-200 rounded-xl text-xs font-semibold cursor-not-allowed select-none w-full"
                                        >
                                            Batalkan
                                        </span>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="sortedRiwayatData.length === 0">
                                <td
                                    colspan="5"
                                    class="px-6 py-12 text-center text-slate-500 text-sm font-medium"
                                >
                                    Tidak ada riwayat cuti yang cocok dengan
                                    filter yang dipilih.
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

    <!-- ================= MODAL DETAIL (tampilan disamakan dengan Dashboard) ================= -->
    <Teleport to="body">
        <div
            v-if="selectedDetail"
            class="fixed inset-0 z-[9999] flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4"
            @click.self="closeDetailModal"
        >
            <div
                class="bg-white rounded-2xl max-w-lg w-full shadow-2xl border border-slate-100 overflow-hidden transform animate-in zoom-in duration-200 max-h-[90vh] flex flex-col"
            >
                <!-- HEADER MODAL: ikon clipboard-check + judul/subjudul di kiri,
                     badge status pil ringkas + tombol tutup di kanan. -->
                <div
                    class="p-5 flex justify-between items-start gap-3 shrink-0"
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
                                class="text-base font-bold text-slate-900 leading-tight"
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
                    <div class="flex items-center gap-2 shrink-0">
                        <span
                            class="px-3 py-1 rounded-full text-[10px] font-extrabold uppercase tracking-wider whitespace-nowrap"
                            :class="{
                                'bg-amber-100 text-amber-700':
                                    selectedDetail.status?.includes('menunggu'),
                                'bg-emerald-100 text-emerald-700':
                                    selectedDetail.status === 'disetujui',
                                'bg-red-100 text-red-700':
                                    getEffectiveStatus(selectedDetail) ===
                                    'ditolak',
                                'bg-orange-100 text-orange-700':
                                    isStatusDitangguhkan(selectedDetail),
                                'bg-slate-100 text-slate-600':
                                    isStatusDibatalkanReguler(selectedDetail),
                            }"
                        >
                            {{ getStatusBadgeLabelSingkat(selectedDetail) }}
                        </span>
                    </div>
                </div>

                <div class="p-6 space-y-3 overflow-y-auto custom-scrollbar">
                    <!-- DETAIL UMUM PEGAWAI (hanya untuk role selain staf) -->
                    <template v-if="currentUser?.role_id !== 1">
                        <div class="grid grid-cols-12 gap-2 text-sm">
                            <div class="col-span-4 text-slate-500 font-medium">
                                Nama Pegawai
                            </div>
                            <div
                                class="col-span-8 font-semibold text-slate-800 leading-snug"
                            >
                                {{
                                    selectedDetail.pegawai?.nama ??
                                    currentUser?.nama ??
                                    "-"
                                }}
                            </div>
                        </div>
                        <div class="grid grid-cols-12 gap-2 text-sm">
                            <div class="col-span-4 text-slate-500 font-medium">
                                NIP
                            </div>
                            <div class="col-span-8 text-slate-700 font-medium">
                                {{
                                    selectedDetail.pegawai?.nip ??
                                    currentUser?.nip ??
                                    "-"
                                }}
                            </div>
                        </div>
                        <div class="grid grid-cols-12 gap-2 text-sm">
                            <div class="col-span-4 text-slate-500 font-medium">
                                Jabatan
                            </div>
                            <div
                                class="col-span-8 text-slate-800 font-semibold uppercase leading-snug"
                            >
                                {{
                                    selectedDetail.pegawai?.jabatan ??
                                    currentUser?.jabatan ??
                                    "-"
                                }}
                            </div>
                        </div>
                        <div class="grid grid-cols-12 gap-2 text-sm">
                            <div class="col-span-4 text-slate-500 font-medium">
                                Kelompok Substansi
                            </div>
                            <div
                                class="col-span-8 text-slate-800 font-semibold"
                            >
                                {{
                                    selectedDetail.pegawai
                                        ?.kelompok_substansi ??
                                    currentUser?.kelompok_substansi ??
                                    "-"
                                }}
                            </div>
                        </div>
                    </template>

                    <!-- DETAIL PENGAJUAN -->
                    <div class="grid grid-cols-12 gap-2 text-sm">
                        <div class="col-span-4 text-slate-500 font-medium">
                            Jenis Cuti
                        </div>
                        <div class="col-span-8 font-semibold text-indigo-600">
                            {{ selectedDetail.jenis_cuti ?? "Cuti Tahunan" }}
                        </div>
                    </div>

                    <!-- RINCIAN SISA CUTI TAHUNAN (AMBER BOX, sama seperti Dashboard) -->
                    <div
                        v-if="isJenisCutiTahunan(selectedDetail.jenis_cuti)"
                        class="grid grid-cols-12 gap-2 items-start bg-amber-50 border border-amber-200 border-l-4 border-l-amber-400 rounded-lg px-3 py-2.5 -mx-1 text-sm"
                    >
                        <div class="col-span-4 text-amber-700 font-semibold">
                            Rincian Sisa Cuti
                        </div>
                        <div class="col-span-8">
                            <span class="font-extrabold text-amber-800 block">
                                {{ rincianSisaCuti(selectedDetail).total }} Hari
                                Total
                            </span>
                            <span
                                class="font-normal text-amber-600/80 text-xs block mt-0.5"
                            >
                                (Tahun Ini:
                                {{ rincianSisaCuti(selectedDetail).tahunIni }}
                                hari | Saldo Bawaan:
                                {{
                                    rincianSisaCuti(selectedDetail).saldoBawaan
                                }}
                                hari)
                            </span>
                        </div>
                    </div>

                    <div class="grid grid-cols-12 gap-2 text-sm">
                        <div class="col-span-4 text-slate-500 font-medium">
                            Waktu Cuti
                        </div>
                        <div class="col-span-8 text-slate-800 font-semibold">
                            {{ formatDate(selectedDetail.tanggal_mulai) }} s/d
                            {{ formatDate(selectedDetail.tanggal_selesai) }}
                            ({{ selectedDetail.jumlah_hari }} Hari)
                        </div>
                    </div>

                    <div class="grid grid-cols-12 gap-2 text-sm">
                        <div class="col-span-4 text-slate-500 font-medium">
                            Alasan
                        </div>
                        <div
                            class="col-span-8 text-slate-800 font-semibold leading-relaxed"
                        >
                            {{ getAlasanBersih(selectedDetail.keterangan) }}
                        </div>
                    </div>

                    <div class="grid grid-cols-12 gap-2 text-sm">
                        <div class="col-span-4 text-slate-500 font-medium">
                            Alamat
                        </div>
                        <div
                            class="col-span-8 text-slate-800 font-semibold uppercase leading-normal"
                        >
                            {{
                                selectedDetail.alamat_selama_cuti ||
                                selectedDetail.alamat_cuti ||
                                "-"
                            }}
                        </div>
                    </div>

                    <div class="grid grid-cols-12 gap-2 text-sm">
                        <div class="col-span-4 text-slate-500 font-medium">
                            Nomor Telepon
                        </div>
                        <div class="col-span-8 text-slate-800 font-semibold">
                            {{
                                selectedDetail.no_telp ||
                                selectedDetail.telepon ||
                                selectedDetail.nomor_telepon ||
                                selectedDetail.pegawai?.no_telepon ||
                                selectedDetail.pegawai?.telepon ||
                                selectedDetail.pegawai?.no_hp ||
                                selectedDetail.pegawai?.phone ||
                                currentUser?.no_telepon ||
                                currentUser?.telepon ||
                                currentUser?.no_hp ||
                                currentUser?.phone ||
                                "-"
                            }}
                        </div>
                    </div>

                    <!-- LAMPIRAN -->
                    <div
                        class="grid grid-cols-12 gap-2 items-center text-sm pt-1"
                    >
                        <div class="col-span-4 text-slate-500 font-medium">
                            Lampiran
                        </div>
                        <div class="col-span-8">
                            <a
                                v-if="selectedDetail.lampiran"
                                :href="selectedDetail.lampiran"
                                target="_blank"
                                rel="noopener"
                                class="inline-flex items-center gap-2 px-3 py-1.5 bg-green-50 border border-green-200 text-green-700 hover:bg-green-100 rounded-xl text-xs font-medium transition-colors"
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

                    <div class="pt-2"></div>

                    <!-- ================= BLOK RESPON ATASAN / PEMBATALAN =================
                         Warna & judul mengikuti status (ditolak / dibatalkan / ditangguhkan /
                         disetujui). Data dari approval_logs (getApprovalLogs). -->
                    <div
                        class="p-4 rounded-2xl border"
                        :class="blokResponClass(selectedDetail)"
                    >
                        <div class="flex items-center gap-2 mb-1.5">
                            <svg
                                class="w-4 h-4"
                                :class="blokIkonClass(selectedDetail)"
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
                                :class="blokJudulClass(selectedDetail)"
                            >
                                {{ blokJudulText(selectedDetail) }}
                            </p>
                        </div>

                        <!-- 1) Dibatalkan reguler (oleh pemohon) -->
                        <div v-if="isStatusDibatalkanReguler(selectedDetail)">
                            <p class="text-sm text-slate-700 mb-1">
                                Dibatalkan oleh:
                                <span class="font-bold">{{
                                    getNamaPembatal(selectedDetail)
                                }}</span>
                            </p>
                            <p
                                class="text-sm font-medium italic text-slate-600"
                            >
                                "{{
                                    getCatatanPembatalanReguler(selectedDetail)
                                }}"
                            </p>

                            <div
                                v-if="rutePersetujuan.length"
                                class="space-y-2 mt-3 pt-3 border-t border-slate-200"
                            >
                                <p
                                    class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1"
                                >
                                    Progres Persetujuan Sebelum Dibatalkan
                                </p>
                                <div
                                    v-for="step in rutePersetujuan"
                                    :key="step.key"
                                    class="rounded-lg border border-slate-100 bg-slate-50/70 p-2.5"
                                >
                                    <p class="text-sm text-slate-700">
                                        {{ step.jabatan }}:
                                        <span class="font-bold">{{
                                            step.nama
                                        }}</span>
                                    </p>
                                    <p
                                        class="mt-0.5 text-xs font-medium"
                                        :class="step.color"
                                    >
                                        {{ step.status }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- 2) Ditangguhkan / dibatalkan atasan -->
                        <div v-else-if="isStatusDitangguhkan(selectedDetail)">
                            <p class="text-sm text-slate-700 mb-1">
                                Diproses oleh:
                                <span class="font-bold">{{
                                    getJabatanPemroses(selectedDetail)
                                }}</span>
                            </p>
                            <p
                                class="text-sm font-medium italic text-orange-700"
                            >
                                "{{ getCatatanAtasan(selectedDetail) }}"
                            </p>

                            <div
                                v-if="rutePersetujuan.length"
                                class="space-y-2 mt-3 pt-3 border-t border-orange-100"
                            >
                                <p
                                    class="text-[10px] font-bold text-orange-500 uppercase tracking-wider mb-1"
                                >
                                    Riwayat Persetujuan Atasan
                                </p>
                                <div
                                    v-for="step in rutePersetujuan"
                                    :key="step.key"
                                    class="rounded-lg border border-white/80 bg-white/70 p-2.5"
                                >
                                    <p class="text-sm text-slate-700">
                                        {{ step.jabatan }}:
                                        <span class="font-bold">{{
                                            step.nama
                                        }}</span>
                                    </p>
                                    <p
                                        class="mt-0.5 text-xs font-medium"
                                        :class="step.color"
                                    >
                                        {{ step.status }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- 3) Status lain (menunggu / disetujui / ditolak): rute persetujuan
                             per level, gaya Dashboard -->
                        <div
                            v-else-if="rutePersetujuan.length"
                            class="space-y-3"
                        >
                            <div
                                v-for="step in rutePersetujuan"
                                :key="step.key"
                                class="rounded-xl bg-white p-3 shadow-sm"
                            >
                                <p class="text-sm text-slate-700">
                                    {{ step.jabatan }}:
                                    <span class="font-bold text-slate-800">{{
                                        step.nama
                                    }}</span>
                                </p>
                                <p
                                    class="mt-1 text-xs font-medium"
                                    :class="step.color"
                                >
                                    {{ step.status }}
                                </p>
                            </div>
                        </div>

                        <!-- 4) Belum ada catatan sama sekali -->
                        <p v-else class="text-xs text-slate-500 italic">
                            Belum ada catatan dari atasan.
                        </p>
                    </div>
                    <!-- ================= END BLOK RESPON ATASAN / PEMBATALAN ================= -->
                </div>

                <!-- FOOTER MODAL: "REVISI TANGGAL" (jika eligible) di kiri, Tutup di kanan -->
                <div
                    class="p-4 bg-slate-50 border-t border-slate-100 flex items-center justify-between gap-2 shrink-0"
                >
                    <button
                        v-if="isStatusBisaDirevisi(selectedDetail)"
                        type="button"
                        @click.prevent="revisiDariDetail"
                        class="px-4 py-2 bg-amber-500 hover:bg-amber-600 text-white rounded-full text-[11px] font-extrabold uppercase tracking-wider transition cursor-pointer shadow-sm"
                    >
                        Revisi Tanggal
                    </button>
                    <span v-else></span>
                    <button
                        type="button"
                        @click.prevent="closeDetailModal"
                        class="px-5 py-2 bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 rounded-xl text-xs font-semibold transition cursor-pointer shadow-sm"
                    >
                        Tutup
                    </button>
                </div>
            </div>
        </div>
    </Teleport>

    <!-- MODAL PEMBATALAN MANDIRI (khusus status "Disetujui") -->
    <Teleport to="body">
        <div v-if="batalModal.show" class="relative z-[9999]">
            <div
                class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity"
                @click="closeBatalModal"
            ></div>

            <div
                class="fixed inset-0 z-10 w-screen overflow-y-auto pointer-events-none"
            >
                <div
                    class="flex min-h-full items-center justify-center p-4 text-center sm:p-0"
                >
                    <div
                        class="relative transform overflow-hidden rounded-[2rem] bg-white text-left shadow-2xl transition-all w-full max-w-md p-6 pointer-events-auto text-slate-800"
                    >
                        <h3 class="text-lg font-extrabold text-slate-800 mb-1">
                            Batalkan Cuti
                        </h3>
                        <p class="text-xs text-slate-500 mb-4 font-medium">
                            Saldo cuti Anda akan otomatis dikembalikan. Tindakan
                            ini tidak dapat diubah.
                        </p>

                        <label
                            class="block text-xs font-bold text-slate-700 mb-2 uppercase tracking-wide"
                        >
                            Alasan Pembatalan
                            <span class="text-red-500">*</span>
                        </label>
                        <textarea
                            v-model="batalModal.alasan"
                            @input="batalModal.error = ''"
                            rows="3"
                            class="w-full text-sm font-medium rounded-xl mb-1 p-3 shadow-sm bg-white transition-colors"
                            :class="
                                batalModal.error
                                    ? 'border-2 border-red-400 focus:ring-red-500 focus:border-red-500'
                                    : 'border border-slate-200 focus:ring-red-500 focus:border-red-500'
                            "
                            placeholder="Contoh: Agenda liburan keluarga batal karena urusan mendadak."
                        ></textarea>

                        <!-- Pesan validasi INLINE — menggantikan alert() bawaan
                             browser agar tampilan tetap konsisten dengan modal
                             custom, bukan popup "sistem". -->
                        <p
                            v-if="batalModal.error"
                            class="flex items-center gap-1.5 text-xs font-semibold text-red-600 mb-4"
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
                                />
                            </svg>
                            {{ batalModal.error }}
                        </p>
                        <div v-else class="mb-4"></div>

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
            </div>
        </div>
    </Teleport>

    <!-- MODAL KONFIRMASI PEMBATALAN (khusus status MASIH MENUNGGU L1-L4) -->
    <Teleport to="body">
        <div v-if="modalKonfirmasiBatal.show" class="relative z-[9999]">
            <div
                class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity"
                @click="closeModalKonfirmasiBatal"
            ></div>

            <div
                class="fixed inset-0 z-10 w-screen overflow-y-auto pointer-events-none"
            >
                <div
                    class="flex min-h-full items-center justify-center p-4 text-center sm:p-0"
                >
                    <div
                        class="relative transform overflow-hidden rounded-[2rem] bg-white text-center shadow-2xl transition-all w-full max-w-md p-6 pointer-events-auto text-slate-800"
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
                                    d="M6 18L18 6M6 6l12 12"
                                />
                            </svg>
                        </div>

                        <h3 class="text-lg font-extrabold text-slate-800 mb-1">
                            Batalkan Pengajuan
                        </h3>
                        <p class="text-xs text-slate-500 mb-4 font-medium">
                            Apakah Anda yakin ingin membatalkan pengajuan cuti
                            ini? Pengajuan yang masih menunggu persetujuan akan
                            dihentikan dan tidak diteruskan ke atasan
                            berikutnya.
                        </p>

                        <div
                            v-if="modalKonfirmasiBatal.item"
                            class="mb-5 p-3.5 bg-slate-50 border border-slate-100 rounded-2xl text-left"
                        >
                            <p class="text-sm font-extrabold text-slate-700">
                                {{
                                    modalKonfirmasiBatal.item.jenis_cuti ??
                                    "Cuti Tahunan"
                                }}
                            </p>
                            <p class="text-xs text-slate-500 mt-1">
                                {{
                                    formatDate(
                                        modalKonfirmasiBatal.item.tanggal_mulai,
                                    )
                                }}
                                -
                                {{
                                    formatDate(
                                        modalKonfirmasiBatal.item
                                            .tanggal_selesai,
                                    )
                                }}
                                ({{ modalKonfirmasiBatal.item.jumlah_hari }}
                                Hari)
                            </p>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <button
                                @click="closeModalKonfirmasiBatal"
                                type="button"
                                class="px-4 py-2.5 text-sm font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 rounded-xl transition-colors"
                            >
                                Tutup
                            </button>
                            <button
                                @click="eksekusiBatalCuti"
                                type="button"
                                class="px-4 py-2.5 text-sm font-semibold text-white bg-red-600 hover:bg-red-700 rounded-xl shadow-sm hover:shadow-md transition-all"
                            >
                                Ya, Batalkan
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>

    <!-- MODAL REVISI (DEDICATED) — khusus status "ditangguhkan" /
         "dibatalkan_ditangguhkan". Dibuka dari tombol "Revisi" di tabel
         maupun tombol "Revisi Cuti" di footer Modal Detail. -->
    <Teleport to="body">
        <div v-if="showModalRevisi" class="relative z-[9999]">
            <div
                class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity"
                @click="tutupModalRevisi"
            ></div>

            <div
                class="fixed inset-0 z-10 w-screen overflow-y-auto pointer-events-none"
            >
                <div
                    class="flex min-h-full items-center justify-center p-4 text-center sm:p-0"
                >
                    <div
                        class="relative transform overflow-hidden rounded-[2rem] bg-white text-left shadow-2xl transition-all w-full max-w-md p-6 pointer-events-auto text-slate-800"
                    >
                        <!-- Header -->
                        <div
                            class="flex items-center gap-3 mb-5 border-b border-slate-100 pb-4"
                        >
                            <div
                                class="flex items-center justify-center h-10 w-10 rounded-full bg-orange-100 shrink-0"
                            >
                                <svg
                                    class="h-5 w-5 text-orange-600"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke-width="2"
                                    stroke="currentColor"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10"
                                    />
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-lg font-bold text-slate-800">
                                    Revisi Pengajuan
                                </h3>
                                <p class="text-xs text-slate-500">
                                    Sesuaikan tanggal cuti berdasarkan instruksi
                                    atasan.
                                </p>
                            </div>
                        </div>

                        <!-- Ringkasan pengajuan yang direvisi -->
                        <div
                            v-if="cutiYangDirevisi"
                            class="mb-5 p-3.5 bg-slate-50 border border-slate-100 rounded-2xl"
                        >
                            <p class="text-sm font-extrabold text-slate-700">
                                {{
                                    cutiYangDirevisi.jenis_cuti ??
                                    "Cuti Tahunan"
                                }}
                            </p>
                            <p class="text-xs text-slate-500 mt-1">
                                Tanggal sebelumnya:
                                {{ formatDate(cutiYangDirevisi.tanggal_mulai) }}
                                -
                                {{
                                    formatDate(cutiYangDirevisi.tanggal_selesai)
                                }}
                            </p>
                        </div>

                        <!-- Instruksi Atasan -->
                        <div class="mb-5">
                            <label
                                class="block text-[11px] font-extrabold text-slate-500 uppercase tracking-wider mb-2"
                                >Instruksi Atasan</label
                            >
                            <div
                                class="p-3 bg-red-50 border border-red-100 rounded-lg"
                            >
                                <p
                                    class="text-sm text-red-700 font-medium leading-relaxed"
                                >
                                    "{{ catatanAtasanUntukRevisi }}"
                                </p>
                            </div>
                        </div>

                        <!-- Form Revisi -->
                        <div
                            class="mb-4 p-4 bg-orange-50/50 border border-orange-200 rounded-xl"
                        >
                            <label
                                class="block text-[11px] font-extrabold text-slate-500 uppercase tracking-wider mb-3"
                                >Form Pengajuan Ulang</label
                            >

                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label
                                        class="block text-xs font-semibold text-slate-700 mb-1.5"
                                        >Tanggal Mulai Baru</label
                                    >
                                    <input
                                        type="date"
                                        v-model="formRevisi.tanggal_mulai"
                                        :min="minDate"
                                        class="w-full text-sm border-slate-300 rounded-lg focus:ring-orange-500 focus:border-orange-500 bg-white"
                                    />
                                </div>
                                <div>
                                    <label
                                        class="block text-xs font-semibold text-slate-700 mb-1.5"
                                        >Tanggal Selesai Baru</label
                                    >
                                    <input
                                        type="date"
                                        v-model="formRevisi.tanggal_selesai"
                                        :min="
                                            formRevisi.tanggal_mulai || minDate
                                        "
                                        class="w-full text-sm border-slate-300 rounded-lg focus:ring-orange-500 focus:border-orange-500 bg-white"
                                    />
                                </div>
                            </div>

                            <div
                                class="flex justify-between items-center text-xs mt-3"
                            >
                                <span class="text-emerald-700 font-semibold">
                                    Estimasi Hari Kerja:
                                    <strong>{{ jumlahHariKerja }} Hari</strong>
                                </span>
                            </div>

                            <div
                                v-if="liburBertepatan.length > 0"
                                class="flex items-start gap-2 bg-blue-50 border border-blue-100 rounded-xl px-3.5 py-2.5 mt-3"
                            >
                                <svg
                                    class="w-4 h-4 text-blue-500 mt-0.5 shrink-0"
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
                                    class="text-xs text-blue-700 leading-relaxed"
                                >
                                    <span class="font-semibold"
                                        >{{ liburBertepatan.length }} tanggal
                                        tidak dihitung</span
                                    >
                                    karena bertepatan dengan hari libur:
                                    <span
                                        v-for="(libur, idx) in liburBertepatan"
                                        :key="libur.tanggal"
                                    >
                                        <span class="font-semibold">{{
                                            libur.keterangan
                                        }}</span>
                                        ({{
                                            new Date(
                                                libur.tanggal,
                                            ).toLocaleDateString("id-ID", {
                                                day: "2-digit",
                                                month: "short",
                                            })
                                        }})<span
                                            v-if="
                                                idx < liburBertepatan.length - 1
                                            "
                                            >,
                                        </span>
                                    </span>
                                </p>
                            </div>

                            <p
                                v-if="isInvalidWeekendOnly"
                                class="text-xs text-red-600 font-semibold mt-2"
                            >
                                Tanggal yang dipilih hanya berisi Sabtu/Minggu.
                            </p>
                            <p
                                v-if="formRevisi.errors.tanggal_mulai"
                                class="text-xs text-red-600 font-semibold mt-2"
                            >
                                {{ formRevisi.errors.tanggal_mulai }}
                            </p>
                            <p
                                v-if="formRevisi.errors.tanggal_selesai"
                                class="text-xs text-red-600 font-semibold mt-2"
                            >
                                {{ formRevisi.errors.tanggal_selesai }}
                            </p>
                        </div>

                        <!-- Tombol Aksi -->
                        <div class="grid grid-cols-2 gap-3">
                            <button
                                @click="tutupModalRevisi"
                                type="button"
                                class="px-4 py-2.5 text-sm font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 rounded-xl transition-colors"
                            >
                                Batal
                            </button>
                            <button
                                @click="eksekusiRevisi"
                                :disabled="
                                    formRevisi.processing ||
                                    !formRevisi.tanggal_mulai ||
                                    !formRevisi.tanggal_selesai ||
                                    isInvalidWeekendOnly ||
                                    jumlahHariKerja === 0
                                "
                                type="button"
                                class="px-4 py-2.5 text-sm font-semibold text-white bg-orange-500 hover:bg-orange-600 disabled:opacity-50 disabled:cursor-not-allowed rounded-xl shadow-sm transition-all"
                            >
                                <span v-if="formRevisi.processing"
                                    >Menyimpan...</span
                                >
                                <span v-else>Ajukan Ulang</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>