<script setup>
import MainLayout from "@/Layouts/MainLayout.vue";
import { Head, Link, router, useForm, usePage } from "@inertiajs/vue3";
import { computed, ref, watch } from "vue";

const props = defineProps({
    riwayat: Object,
    filters: Object,
    // Data saldo cuti tahunan milik pegawai yang login, dipakai untuk
    // menampilkan kartu "Sisa Cuti Tahunan" di Modal Detail — hanya muncul
    // kalau jenis_cuti pengajuan yang dibuka adalah "Cuti Tahunan". Optional:
    // kalau controller belum mengirim prop ini, kartu tetap tampil dengan
    // angka 0 (tidak error), sampai backend menambahkan datanya.
    stats: {
        type: Object,
        default: () => ({}),
    },
});

// Pengguna yang sedang login (dipakai untuk menentukan rute persetujuan
// L1-L4 pada timeline "Catatan / Respon Atasan", disamakan dengan logika
// di MonitoringCuti.vue milik Atasan).
const page = usePage();
const currentUser = computed(() => page.props.auth.user);

// ================= SISA CUTI TAHUNAN (untuk kartu di Modal Detail) =================
const isJenisCutiTahunan = (jenisCuti) =>
    (jenisCuti || "Cuti Tahunan").toLowerCase().includes("tahunan");

const sisaCutiTahunanInfo = computed(() => {
    const s = props.stats || {};
    const tahunIni = s.kuota_tahunan ?? s.sisa_cuti_tahun_ini ?? 0;
    const tahunLalu = s.sisa_cuti_tahun_lalu ?? 0;
    const total = s.total_cuti_tersedia ?? tahunIni + tahunLalu;
    return { total, tahunIni, tahunLalu };
});
// ================= END SISA CUTI TAHUNAN =================

const search = ref(props.filters?.search || "");
const status = ref(props.filters?.status || "");
// Filter jenis cuti (Semua / Tahunan / Melahirkan / Besar / Alasan Penting)
const jenisCutiFilter = ref(props.filters?.jenis_cuti || "");

let timeout = null;
watch(
    [search, status, jenisCutiFilter],
    ([newSearch, newStatus, newJenisCuti]) => {
        clearTimeout(timeout);
        timeout = setTimeout(() => {
            router.get(
                route("karyawan.riwayat"),
                {
                    search: newSearch,
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
// ==========================================================
const batalModal = ref({
    show: false,
    id: null,
    alasan: "",
});

const openBatalModal = (id) => {
    batalModal.value.id = id;
    batalModal.value.alasan = "";
    batalModal.value.show = true;
};

const closeBatalModal = () => {
    batalModal.value.show = false;
};

const submitBatal = () => {
    if (!batalModal.value.alasan.trim()) {
        alert("Alasan pembatalan wajib diisi!");
        return;
    }

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
// "Revisi" di tabel maupun untuk validasi sebelum membuka Modal Revisi).
const isStatusBisaDirevisi = (item) =>
    item?.status === "ditangguhkan" ||
    item?.status === "dibatalkan_ditangguhkan";

// PEMBARUAN: Nama Status Atasan & Hierarki L4 — disamakan dengan
// statusLabels di MonitoringCuti.vue Admin (nama atasan lengkap per
// level). Ini adalah fungsi yang SUNGGUH-SUNGGUH dipakai di template
// untuk badge kolom Status & badge header Modal Detail — beda dari
// kamus `statusLabels` di bawah yang hanya dipakai sebagai fallback
// internal (getStatusLabel/getEffectiveStatusLabel) dan tidak pernah
// dirender langsung di layar.
const formatStatus = (statusCode) => {
    switch (statusCode) {
        case "menunggu_l1":
            return {
                text: "Menunggu Bapak Ketua Tim Kerja (L1)",
                class: "bg-amber-100 text-amber-700 border border-amber-200",
            };
        case "menunggu_l2":
            return {
                text: "Menunggu Bapak Ketua Kelompok Substansi (L2)",
                class: "bg-amber-100 text-amber-700 border border-amber-200",
            };
        case "menunggu_l3":
            return {
                text: "Menunggu Ignatius Agus Hendarto (L3)",
                class: "bg-amber-100 text-amber-700 border border-amber-200",
            };
        case "menunggu_l4":
            return {
                text: "Menunggu Seta Rukmalasari Agustina (L4)",
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

// Helper: nama atasan yang memproses pengajuan, mengikuti detail Dashboard.
// DIPERTAHANKAN (tidak dihapus) sebagai fungsi cadangan meski tampilan
// utama "Catatan / Respon Atasan" sekarang memakai timeline approvalTimeline
// di bawah (disamakan dengan Monitoring Cuti Atasan).
const getNamaAtasanPemroses = (item) => {
    if (!item) return "Bapak Atasan L1";
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

// Fungsi untuk membersihkan teks catatan atasan agar tampil clean:
// mengambil bagian setelah tanda "|", lalu membuang prefix format
// "[Label: ...]" beserta sisa tanda kurung siku di akhir.
// DIPERTAHANKAN (tidak dihapus) sebagai fungsi cadangan.
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

const getApprovalLogs = (item) => {
    const logs = item?.approval_logs || item?.approvalLogs || [];
    if (logs.length) return logs;

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

const getApprovalLevelLabel = (level) =>
    ({
        1: "L1 - Ketua Tim Kerja",
        2: "L2 - Ketua Kelompok Substansi",
        3: "L3 - Kasubag TU",
        4: "L4 - Kepala Biro Perencanaan",
    })[level] || `Level ${level}`;

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
            return "text-orange-600";
        default:
            return "text-gray-500";
    }
};

// Helper pembersih kalimat keterangan (konsisten dengan RekapKuotaDetail.vue
// Admin & AntreanApproval.vue): dipakai khusus untuk kotak "Keterangan /
// Alasan Cuti" agar formatnya identik dengan tampilan admin.
const formatKeteranganRapi = (text) => {
    if (!text || text === "-") return "-";

    if (text.includes("|") || text.includes("[DITANGGUHKAN")) {
        let bagian = text.split("|").map((item) => item.trim());
        let alasanAwal =
            bagian[0] && bagian[0] !== "-" ? bagian[0] : "Ada keperluan";

        let regex = /\[DITANGGUHKAN\/DIBATALKAN ALASAN:\s*(.*?)\]/i;
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
const statusLabels = {
    menunggu_l1: "Menunggu Bapak Ketua Tim Kerja (L1)",
    menunggu_l3: "Menunggu Ignatius Agus Hendarto (L3)",
    menunggu_l4: "Menunggu Seta Rukmalasari Agustina (L4)",
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

    const bracketRegex = /\[DITANGGUHKAN\/DIBATALKAN ALASAN:\s*(.*?)\]/i;
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

// Definisi 4 level persetujuan sesuai statusLabels di atas (L1-L4).
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

// Rute persetujuan berjenjang bergantung pada jabatan pemohon.
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

// Menyusun status tiap level pada rute yang berlaku, berdasarkan
// selectedDetail (pengajuan yang sedang dibuka di Modal Detail Karyawan).
const approvalTimeline = computed(() => {
    const data = selectedDetail.value;
    if (!data) return [];

    const st = data.status;
    const roleId = data.pegawai?.role_id ?? currentUser.value?.role_id ?? 1;
    const steps = getApprovalRoute(roleId);

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
                color = "text-rose-700";
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
// ================= END TIMELINE PERSETUJUAN =================

// ==========================================================
// MODAL REVISI (DEDICATED)
// Menggantikan form revisi inline yang sebelumnya menempel di dalam
// Modal Detail (harus scroll ke bawah dulu). Sekarang begitu tombol
// "Revisi" di tabel ditekan, modal ringkas ini langsung terbuka:
// tampilkan instruksi/catatan atasan di atas, form tanggal baru di
// bawahnya. Validasi hari kerja & larangan tanggal hanya Sabtu/Minggu
// tetap dipertahankan dari implementasi sebelumnya.
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

const jumlahHariKerja = computed(() => {
    if (!formRevisi.tanggal_mulai || !formRevisi.tanggal_selesai) return 0;

    const start = new Date(formRevisi.tanggal_mulai);
    const end = new Date(formRevisi.tanggal_selesai);
    if (start > end) return 0;

    let count = 0;
    const current = new Date(start);
    while (current <= end) {
        if (current.getDay() !== 0 && current.getDay() !== 6) count++;
        current.setDate(current.getDate() + 1);
    }
    return count;
});

const isInvalidWeekendOnly = computed(() => {
    if (!formRevisi.tanggal_mulai || !formRevisi.tanggal_selesai)
        return false;

    const start = new Date(formRevisi.tanggal_mulai);
    const end = new Date(formRevisi.tanggal_selesai);
    if (start > end) return false;

    const current = new Date(start);
    while (current <= end) {
        if (current.getDay() !== 0 && current.getDay() !== 6) return false;
        current.setDate(current.getDate() + 1);
    }
    return true;
});

// Catatan/instruksi atasan yang ditampilkan di Modal Revisi — diambil
// dari approval_chain (langkah dengan status 'tangguh') bila tersedia,
// lalu fallback ke kolom keterangan lama.
const catatanAtasanUntukRevisi = computed(() => {
    const item = cutiYangDirevisi.value;
    if (!item) return "";

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

            <div
                class="bg-white shadow-sm border border-slate-200 rounded-2xl overflow-hidden"
            >
                <div
                    class="p-5 border-b border-slate-100 flex flex-col sm:flex-row justify-between items-center gap-4 bg-slate-50/50"
                >
                    <!-- SISI KIRI: Kotak Pencarian -->
                    <div class="w-full sm:w-80 relative">
                        <span
                            class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-slate-400"
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
                                    d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
                                ></path>
                            </svg>
                        </span>
                        <input
                            v-model="search"
                            type="text"
                            placeholder="Cari berdasarkan keterangan..."
                            class="w-full pl-9 pr-4 py-2 text-xs border border-slate-200 rounded-xl focus:ring-green-500 focus:border-green-500 bg-white"
                        />
                    </div>

                    <!-- SISI KANAN: Kelompok Filter (Jenis Cuti & Status berjejer, gaya Admin) -->
                    <div
                        class="flex flex-col sm:flex-row items-center gap-3 w-full sm:w-auto"
                    >
                        <!-- Dropdown Filter Jenis Cuti -->
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
                                <th
                                    scope="col"
                                    class="px-6 py-4 text-left text-[11px] font-bold text-slate-400 uppercase tracking-wider"
                                >
                                    Keterangan
                                </th>
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
                                v-for="item in riwayat.data"
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
                                            'bg-gray-200 text-gray-800 border-gray-300':
                                                getEffectiveStatus(item) ===
                                                    'ditangguhkan' ||
                                                item.status?.includes(
                                                    'ditangguhkan',
                                                ) ||
                                                item.status?.includes(
                                                    'dibatalkan',
                                                ),
                                            'bg-gray-200 text-gray-600 border-gray-300':
                                                !item.status,
                                        }"
                                    >
                                        {{ getEffectiveStatusLabel(item) }}
                                    </span>
                                </td>

                                <td
                                    class="px-6 py-4 text-sm text-slate-600 max-w-xs truncate"
                                    :title="item.keterangan"
                                >
                                    {{
                                        item.keterangan
                                            ? item.keterangan
                                                  .split("|")[0]
                                                  .trim()
                                            : "-"
                                    }}
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
                            <tr v-if="riwayat.data.length === 0">
                                <td
                                    colspan="6"
                                    class="px-6 py-12 text-center text-slate-500 text-sm font-medium"
                                >
                                    Tidak ada riwayat cuti yang cocok dengan
                                    pencarian atau filter.
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

    <!-- MODAL DETAIL -->
    <Teleport to="body">
        <div
            v-if="selectedDetail"
            class="fixed inset-0 z-[9999] flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4"
            @click.self="closeDetailModal"
        >
            <div
                class="bg-white rounded-2xl max-w-lg w-full shadow-2xl border border-slate-100 overflow-hidden transform animate-in zoom-in duration-200 max-h-[90vh] flex flex-col"
            >
                <!-- HEADER & BADGE STATUS -->
                <div
                    class="p-5 border-b border-slate-100 flex justify-between items-start bg-slate-50/50 shrink-0"
                >
                    <div>
                        <h3 class="text-lg font-extrabold text-slate-800">
                            Detail Pengajuan Cuti
                        </h3>
                        <p class="text-xs font-medium text-slate-500 mt-1">
                            Informasi lengkap status dan permohonan.
                        </p>
                    </div>
                    <div class="flex items-center gap-3 shrink-0">
                        <div
                            class="px-3 py-1.5 rounded-lg text-[10px] font-bold uppercase tracking-wider whitespace-nowrap"
                            :class="{
                                'bg-amber-50 text-amber-600 border border-amber-200':
                                    selectedDetail.status?.includes('menunggu'),
                                'bg-emerald-50 text-emerald-600 border border-emerald-200':
                                    selectedDetail.status === 'disetujui',
                                'bg-red-50 text-red-600 border border-red-200':
                                    selectedDetail.status === 'ditolak',
                                'bg-slate-50 text-slate-600 border border-slate-200':
                                    selectedDetail.status?.includes(
                                        'dibatalkan',
                                    ),
                            }"
                        >
                            {{
                                selectedDetail.status?.replace(/_/g, " ") ??
                                "Status"
                            }}
                        </div>
                        <button
                            type="button"
                            @click.prevent="closeDetailModal"
                            class="text-slate-400 hover:text-slate-600 bg-white border border-slate-200 p-1.5 rounded-full shadow-sm hover:bg-slate-50 transition cursor-pointer"
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
                                ></path>
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="p-6 space-y-5 overflow-y-auto custom-scrollbar">
                    <!-- GRID 4 KOTAK -->
                    <div class="grid grid-cols-2 gap-4">
                        <div
                            class="p-4 bg-white border border-slate-100 rounded-2xl shadow-sm"
                        >
                            <p
                                class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1"
                            >
                                Tanggal Mulai
                            </p>
                            <p class="text-sm font-bold text-slate-700">
                                {{ formatDate(selectedDetail.tanggal_mulai) }}
                            </p>
                        </div>
                        <div
                            class="p-4 bg-white border border-slate-100 rounded-2xl shadow-sm"
                        >
                            <p
                                class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1"
                            >
                                Tanggal Selesai
                            </p>
                            <p class="text-sm font-bold text-slate-700">
                                {{ formatDate(selectedDetail.tanggal_selesai) }}
                            </p>
                        </div>
                        <div
                            class="p-4 bg-white border border-slate-100 rounded-2xl shadow-sm"
                        >
                            <p
                                class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1"
                            >
                                Jenis Cuti
                            </p>
                            <p class="text-sm font-bold text-indigo-600">
                                {{
                                    selectedDetail.jenis_cuti ?? "Cuti Tahunan"
                                }}
                            </p>
                        </div>
                        <div
                            class="p-4 bg-white border border-slate-100 rounded-2xl shadow-sm"
                        >
                            <p
                                class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1"
                            >
                                Durasi
                            </p>
                            <p class="text-sm font-bold text-slate-700">
                                {{ selectedDetail.jumlah_hari }} Hari
                            </p>
                        </div>
                    </div>

                    <!-- SISA CUTI TAHUNAN -->
                    <div
                        v-if="isJenisCutiTahunan(selectedDetail.jenis_cuti)"
                        class="p-4 bg-blue-50/60 border border-blue-100 rounded-2xl shadow-sm"
                    >
                        <p
                            class="text-[10px] font-bold text-blue-600/70 uppercase tracking-wider mb-1"
                        >
                            Sisa Cuti Tahunan
                        </p>
                        <p class="text-sm font-bold text-blue-700">
                            {{ sisaCutiTahunanInfo.total }} Hari
                            <span
                                class="text-xs font-normal text-slate-500 ml-1"
                            >
                                (Tahun ini: {{ sisaCutiTahunanInfo.tahunIni }}
                                hari, Tahun lalu:
                                {{ sisaCutiTahunanInfo.tahunLalu }} hari)
                            </span>
                        </p>
                    </div>

                    <!-- KETERANGAN / ALASAN PEGAWAI -->
                    <div>
                        <p
                            class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1.5 ml-1"
                        >
                            Keterangan / Alasan Cuti
                        </p>
                        <div
                            class="p-4 bg-slate-50 border border-slate-100 rounded-2xl text-sm text-slate-600 leading-relaxed whitespace-pre-wrap"
                        >
                            "{{
                                formatKeteranganRapi(selectedDetail.keterangan)
                            }}"
                        </div>
                    </div>

                    <!-- ALAMAT SELAMA CUTI & NOMOR KONTAK -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div
                            class="p-4 bg-white border border-slate-100 rounded-2xl shadow-sm"
                        >
                            <p
                                class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1"
                            >
                                Alamat Selama Cuti
                            </p>
                            <p class="text-sm font-medium text-slate-700">
                                {{
                                    selectedDetail.alamat_cuti ||
                                    selectedDetail.alamat_selama_cuti ||
                                    "-"
                                }}
                            </p>
                        </div>
                        <div
                            class="p-4 bg-white border border-slate-100 rounded-2xl shadow-sm"
                        >
                            <p
                                class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1"
                            >
                                Nomor Kontak / Telepon
                            </p>
                            <p class="text-sm font-medium text-slate-700">
                                {{ selectedDetail.telepon || "-" }}
                            </p>
                        </div>
                    </div>

                    <!-- LAMPIRAN -->
                    <div
                        class="flex items-center justify-between bg-white p-3.5 rounded-2xl border border-slate-100 shadow-sm gap-3"
                    >
                        <div class="flex items-center gap-2">
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
                                    class="block text-slate-800 font-semibold text-sm"
                                    >Lampiran Pendukung</span
                                >
                                <span class="text-slate-400 text-[10px]">{{
                                    selectedDetail.lampiran
                                        ? "File tersedia di sistem"
                                        : "Tidak ada lampiran diunggah"
                                }}</span>
                            </div>
                        </div>
                        <a
                            v-if="selectedDetail.lampiran"
                            :href="selectedDetail.lampiran"
                            target="_blank"
                            class="px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white text-xs font-semibold rounded-lg shadow-sm transition shrink-0"
                        >
                            Unduh / Lihat
                        </a>
                        <span
                            v-else
                            class="text-slate-400 italic text-[11px] shrink-0"
                            >Tanpa Lampiran</span
                        >
                    </div>

                    <!-- CATATAN / RESPON ATASAN -->
                    <div>
                        <p
                            class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1.5 ml-1"
                        >
                            Catatan / Respon Atasan
                        </p>

                        <!-- UTAMA: pakai approval_chain -->
                        <div
                            v-if="
                                selectedDetail.approval_chain &&
                                selectedDetail.approval_chain.length > 0
                            "
                            class="bg-white p-4 rounded-2xl border border-slate-100 shadow-sm space-y-1"
                        >
                            <div
                                v-for="(
                                    step, idx
                                ) in selectedDetail.approval_chain"
                                :key="step.level ?? `tangguh-${idx}`"
                                class="flex items-center gap-1.5 text-gray-600 py-1.5"
                                :class="{
                                    'border-t border-slate-100': idx > 0,
                                }"
                            >
                                <svg
                                    v-if="step.status === 'tangguh'"
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
                                        L{{ step.level }} &middot;
                                    </template>
                                    <strong class="text-gray-800">{{
                                        step.nama
                                    }}</strong>
                                    <span class="text-gray-400 text-[10px]">
                                        ({{ step.label }})
                                    </span>
                                </span>
                                <span
                                    class="font-bold uppercase text-[11px]"
                                    :class="approvalStepClass(step.status)"
                                >
                                    {{ approvalStepLabel(step.status) }}
                                </span>
                            </div>
                        </div>

                        <!-- FALLBACK: timeline lama -->
                        <div
                            v-else
                            class="rounded-xl p-4 border"
                            :class="[
                                getEffectiveStatus(selectedDetail) ===
                                'disetujui'
                                    ? 'bg-emerald-100 border-emerald-300'
                                    : getEffectiveStatus(
                                            selectedDetail,
                                        )?.includes('menunggu')
                                      ? 'bg-amber-100 border-amber-300'
                                      : getEffectiveStatus(selectedDetail) ===
                                          'ditolak'
                                        ? 'bg-rose-100 border-rose-300'
                                        : 'bg-gray-200 border-gray-300',
                            ]"
                        >
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
                    <!-- ================= END CATATAN / RESPON ATASAN ================= -->
                </div>
                <div
                    class="p-4 bg-slate-50 border-t border-slate-100 flex justify-end shrink-0"
                >
                    <button
                        type="button"
                        @click.prevent="closeDetailModal"
                        class="px-6 py-2 bg-slate-800 hover:bg-slate-900 text-white rounded-xl text-sm font-semibold transition cursor-pointer shadow-sm"
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
                            rows="3"
                            class="w-full text-sm font-medium border-slate-200 rounded-xl focus:ring-red-500 focus:border-red-500 mb-5 p-3 shadow-sm bg-white"
                            placeholder="Contoh: Agenda liburan keluarga batal karena urusan mendadak."
                        ></textarea>

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
         "dibatalkan_ditangguhkan". Menggantikan form revisi inline di
         Modal Detail agar pengguna langsung fokus: baca instruksi
         atasan, isi tanggal baru, kirim. -->
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
                                    Sesuaikan tanggal cuti berdasarkan
                                    instruksi atasan.
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
                                {{
                                    formatDate(cutiYangDirevisi.tanggal_mulai)
                                }}
                                -
                                {{
                                    formatDate(
                                        cutiYangDirevisi.tanggal_selesai,
                                    )
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
                                        :min="formRevisi.tanggal_mulai || minDate"
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

                            <p
                                v-if="isInvalidWeekendOnly"
                                class="text-xs text-red-600 font-semibold mt-2"
                            >
                                Tanggal yang dipilih hanya berisi
                                Sabtu/Minggu.
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

