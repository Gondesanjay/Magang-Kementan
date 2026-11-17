<script setup>
import MainLayout from "@/Layouts/MainLayout.vue";
import { Head, router } from "@inertiajs/vue3";
import { ref, watch, computed, onMounted, onBeforeUnmount } from "vue";

// Debounce lokal (tidak butuh lodash)
function debounce(fn, delay) {
    let timeoutId;
    return (...args) => {
        clearTimeout(timeoutId);
        timeoutId = setTimeout(() => fn(...args), delay);
    };
}

const props = defineProps({
    // Paginator dari server: { data, total, current_page, last_page, from, to, ... }
    daftarCuti: {
        type: Object,
        default: () => ({
            data: [],
            total: 0,
            current_page: 1,
            last_page: 1,
            from: null,
            to: null,
        }),
    },
    // Daftar nama kelompok substansi dari controller (sama seperti Monitoring Cuti).
    daftarKelompokSubstansi: {
        type: Array,
        default: () => [],
    },
    // Daftar tahun yang boleh dipilih (2 tahun mundur, 1 tahun maju) dari controller.
    daftarTahun: {
        type: Array,
        default: () => [],
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
});

// ================= FORMAT TANGGAL =================
// "YYYY-MM-DD" di-parse sebagai tanggal LOKAL (bukan UTC) agar tidak bergeser sehari.
const parseTanggal = (dateString) => {
    if (!dateString) return null;
    const [y, m, d] = String(dateString).slice(0, 10).split("-").map(Number);
    if (y && m && d) return new Date(y, m - 1, d);
    const fallback = new Date(dateString);
    return Number.isNaN(fallback.getTime()) ? null : fallback;
};

const formatDate = (dateString) => {
    const date = parseTanggal(dateString);
    if (!date) return "-";
    return new Intl.DateTimeFormat("id-ID", {
        day: "2-digit",
        month: "short",
        year: "numeric",
    }).format(date);
};
// ================= END FORMAT TANGGAL =================

// Kamus label status lengkap, dipakai untuk teks deskriptif.
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

// Label singkat untuk badge.
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

const bisaDitangguhkan = (item) => item?.bisa_ditangguhkan === true;
const alasanTidakBisaDitangguhkan = (item) => item?.alasan_tidak_bisa || null;
const PESAN_TIDAK_BISA = "Cuti ini tidak dapat ditangguhkan.";

// Kelas warna badge status (emerald/amber/rose/gray).
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
    const mulai = parseTanggal(item?.tanggal_mulai);
    const selesai = parseTanggal(item?.tanggal_selesai);
    if (!mulai || !selesai) return 1;
    const selisih = Math.round((selesai - mulai) / (1000 * 60 * 60 * 24)) + 1;
    return selisih > 0 ? selisih : 1;
};

const getNomorTelepon = (item) => {
    if (!item) return "-";
    return (
        item.no_telp ??
        item.no_telepon ??
        item.telepon ??
        item.phone ??
        item.pegawai?.no_telepon ??
        item.pegawai?.telepon ??
        item.pegawai?.no_hp ??
        item.pegawai?.phone ??
        "-"
    );
};

// ================= HELPER MODAL DETAIL (SAMA DENGAN MonitoringCuti.vue) =================
const LABEL_TANPA_KELOMPOK = "Tanpa Kelompok";

const kosongKelompok = (nilai) =>
    ["", "-", "–", "—"].includes(String(nilai ?? "").trim());

const kelompokPegawai = (pegawai) => {
    const ks = pegawai?.kelompok_substansi;
    const dep = pegawai?.departemen;
    if (!kosongKelompok(ks)) return String(ks).trim();
    if (!kosongKelompok(dep)) return String(dep).trim();
    return LABEL_TANPA_KELOMPOK;
};

const getLampiranUrl = (path) => {
    if (!path) return "";
    if (/^(https?:)?\/\//.test(path) || path.startsWith("/")) return path;
    return `/storage/${path}`;
};

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

const isKeteranganDitangguhkan = (keterangan) =>
    /ditangguhkan/i.test(keterangan || "");

const getIsiCatatanMentah = (text) => {
    if (!text) return "";
    const bracketRegex = /\[DITANGGUHKAN\/DIBATALKAN ATASAN:\s*(.*?)\]/i;
    const bracketMatch = text.match(bracketRegex);
    if (bracketMatch && bracketMatch[1]) return bracketMatch[1].trim();
    const parenRegex = /([)]+)/;
    const parenMatch = text.match(parenRegex);
    if (parenMatch && parenMatch[1]) return parenMatch[1].trim();
    return "";
};

const getCatatanAtasan = (text) => {
    const isi = getIsiCatatanMentah(text);
    if (!isi) return "";
    let hasil = isi;
    if (/oleh/i.test(isi) && isi.includes(":")) {
        const parts = isi.split(":");
        hasil = parts.slice(1).join(":").trim();
    }
    return hasil.replace(/\s*[—–-]\s*Pengajuan Cuti Ulang\s*$/i, "").trim();
};

// Nama L1/L2 dinamis + rute approval
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
    { key: 3, jabatan: "L3 - Kasubag TU", nama: "Kasubag TU" },
    {
        key: 4,
        jabatan: "L4 - Kepala Biro Perencanaan",
        nama: "Kepala Biro Perencanaan",
    },
];

const getLevelDisplayName = (levelKey, data) =>
    data?.[`atasan_l${levelKey}_nama`] ||
    levelsDefinition.find((l) => l.key === levelKey)?.nama ||
    "-";

const getApprovalRoute = (pegawai) => {
    const roleId = pegawai?.role_id ?? 1;
    switch (roleId) {
        case 6:
            return [];
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
        default: {
            const kelompokPeg = (
                pegawai?.kelompok_substansi ??
                pegawai?.departemen ??
                ""
            )
                .toString()
                .trim()
                .toLowerCase();
            const isSubbagianTataUsaha =
                kelompokPeg.includes("subbagian tata usaha") ||
                kelompokPeg.includes("tata usaha") ||
                kelompokPeg === "tata_usaha";
            if (isSubbagianTataUsaha) {
                return [levelsDefinition[2], levelsDefinition[3]];
            }
            const timKerjaPeg = (pegawai?.tim_kerja ?? "").toString().trim();
            const tanpaTimKerja = timKerjaPeg === "" || timKerjaPeg === "-";
            if (tanpaTimKerja) {
                return [
                    levelsDefinition[1],
                    levelsDefinition[2],
                    levelsDefinition[3],
                ];
            }
            return [
                levelsDefinition[0],
                levelsDefinition[2],
                levelsDefinition[3],
            ];
        }
    }
};
// ================= END HELPER MODAL DETAIL =================

// ===================== PAGINATION =====================
const paginatedData = computed(() => props.daftarCuti?.data ?? []);
const totalData = computed(() => props.daftarCuti?.total ?? 0);
const currentPage = computed(() => props.daftarCuti?.current_page ?? 1);
const totalPages = computed(() => props.daftarCuti?.last_page ?? 1);
const displayedFrom = computed(() => props.daftarCuti?.from ?? 0);
const displayedTo = computed(() => props.daftarCuti?.to ?? 0);

// Pindah halaman = minta halaman itu ke server, filter tetap terbawa.
const bukaHalaman = (page) => {
    if (page === "..." || page === currentPage.value) return;
    if (page < 1 || page > totalPages.value) return;
    router.get(
        route("atasan.penangguhan"),
        { ...buildParams(), page },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};
const prevPage = () => bukaHalaman(currentPage.value - 1);
const nextPage = () => bukaHalaman(currentPage.value + 1);
const setPage = (page) => bukaHalaman(page);
const pageNumbers = computed(() => {
    const total = totalPages.value;
    const current = currentPage.value;
    const maxVisible = 7;
    const pages = [];
    if (total <= maxVisible) {
        for (let i = 1; i <= total; i++) pages.push(i);
    } else {
        if (current <= 4) {
            pages.push(1, 2, 3, 4, 5, "...", total);
        } else if (current >= total - 3) {
            pages.push(
                1,
                "...",
                total - 4,
                total - 3,
                total - 2,
                total - 1,
                total,
            );
        } else {
            pages.push(
                1,
                "...",
                current - 1,
                current,
                current + 1,
                "...",
                total,
            );
        }
    }
    return pages;
});

// ===================== END PAGINATION =====================

// ===================== SEARCH & FILTER =====================
const search = ref(props.filters?.search || "");
const kelompok = ref(props.filters?.kelompok_substansi || "");
// Tahun yang ditampilkan. Bawaan dari controller (tahun berjalan).
const tahun = ref(Number(props.filters?.tahun) || new Date().getFullYear());

const buildParams = () => {
    const params = { search: search.value, tahun: tahun.value };
    if (kelompok.value) params.kelompok_substansi = kelompok.value;
    return params;
};

watch(
    [search, kelompok, tahun],
    debounce(() => {
        router.get(route("atasan.penangguhan"), buildParams(), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, 300),
);

// Tahun TIDAK dihitung sebagai "filter aktif" karena selalu terisi.
const adaFilterAktif = computed(() => !!search.value || !!kelompok.value);
const resetFilter = () => {
    search.value = "";
    kelompok.value = "";
};

// ----- Pegawai tanpa kelompok bernilai "-" di database. Nilai tetap "-" ke
// backend, tetapi ditampilkan sebagai "Tanpa Kelompok". -----
const NILAI_KOSONG = "-";
const tampilKelompok = (k) =>
    String(k ?? "").trim() === NILAI_KOSONG ? "Tanpa Kelompok" : k;

// Opsi "-" diletakkan paling bawah agar kelompok nyata tampil dulu.
const opsiKelompok = computed(() => {
    const daftar = (props.daftarKelompokSubstansi || []).filter(
        (k) => k !== null && k !== undefined && k !== "",
    );
    const biasa = daftar.filter((k) => String(k).trim() !== NILAI_KOSONG);
    const kosong = daftar.filter((k) => String(k).trim() === NILAI_KOSONG);
    return [...biasa, ...kosong];
});

// ================================================================
// DROPDOWN FILTER KUSTOM (SAMA DENGAN HALAMAN APPROVAL / MonitoringCuti.vue)
// Mobile  : Search full → Kelompok full → Tahun full
// Desktop : satu baris horizontal
// Menutup saat klik di luar dropdown (atribut data-dropdown) atau tekan Esc.
// ================================================================
const dropdownTerbuka = ref(null);

const toggleDropdown = (nama) => {
    dropdownTerbuka.value = dropdownTerbuka.value === nama ? null : nama;
};

/** Kelompok + Tahun */
const daftarFilter = computed(() => [
    {
        key: "kelompok",
        id: "filter-kelompok-pembatalan",
        ariaLabel: "Filter kelompok substansi",
        selected: kelompok.value,
        lebar: "lg:w-52",
        options: [
            { value: "", label: "Semua Kelompok" },
            ...opsiKelompok.value.map((k) => ({
                value: k,
                label: tampilKelompok(k),
            })),
        ],
    },
    {
        key: "tahun",
        id: "filter-tahun-penangguhan",
        ariaLabel: "Filter tahun cuti",
        selected: tahun.value,
        lebar: "lg:w-44",
        options: props.daftarTahun.map((t) => ({
            value: t,
            label: `Tahun ${t}`,
        })),
    },
]);

const labelSelected = (f) => {
    const cocok = f.options.find((o) => o.value === f.selected);
    return cocok ? cocok.label : f.selected || f.options[0]?.label || "";
};

const pilihFilter = (key, nilai) => {
    if (key === "kelompok") kelompok.value = nilai;
    else if (key === "tahun") tahun.value = nilai;
    dropdownTerbuka.value = null;
};

const tutupDropdownSaatKlikLuar = (event) => {
    if (!event.target.closest?.("[data-dropdown]")) {
        dropdownTerbuka.value = null;
    }
};
// ================= END DROPDOWN FILTER KUSTOM =================

// Chip "Filter aktif" untuk pencarian & kelompok
const activeFilterChips = computed(() => {
    const chips = [];
    if (search.value) {
        chips.push({
            key: "search",
            label: `Pencarian: "${search.value}"`,
            clear: () => {
                search.value = "";
            },
        });
    }
    if (kelompok.value) {
        chips.push({
            key: "kelompok",
            label: tampilKelompok(kelompok.value),
            clear: () => {
                kelompok.value = "";
            },
        });
    }
    return chips;
});
// ===================== END SEARCH & FILTER =====================

// ===================== TOAST =====================
const toast = ref({
    show: false,
    message: "",
});
let toastTimer = null;
const showToast = (message) => {
    toast.value.message = message;
    toast.value.show = true;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
        toast.value.show = false;
    }, 4000);
};
// ===================== END TOAST =====================

// ===================== MODAL PENANGGUHAN =====================
const suspendData = ref({
    show: false,
    id: null,
    namaPegawai: "",
    alasan: "",
});
const suspendError = ref("");
// true selama request berjalan: mencegah klik ganda.
const sedangMemproses = ref(false);

const openSuspendModal = (id, nama) => {
    suspendData.value.id = id;
    suspendData.value.namaPegawai = nama;
    suspendData.value.alasan = "";
    suspendError.value = "";
    sedangMemproses.value = false;
    suspendData.value.show = true;
};

const closeSuspendModal = () => {
    if (sedangMemproses.value) return;
    suspendData.value.show = false;
    suspendError.value = "";
};

const submitSuspend = () => {
    if (sedangMemproses.value) return;

    if (!suspendData.value.alasan.trim()) {
        suspendError.value = "Mohon isi alasan penangguhan terlebih dahulu.";
        return;
    }

    suspendError.value = "";
    sedangMemproses.value = true;
    const namaPegawaiDisuspend = suspendData.value.namaPegawai;

    router.post(
        route("atasan.penangguhan.process", suspendData.value.id),
        {
            alasan: suspendData.value.alasan,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                sedangMemproses.value = false;
                closeSuspendModal();
                closeDetailModal();
                showToast(
                    `Berhasil: Cuti atas nama ${namaPegawaiDisuspend} telah ditangguhkan.`,
                );
            },
            onError: (errors) => {
                // Termasuk pesan "Hanya Cuti Tahunan yang dapat ditangguhkan." dari server.
                const message =
                    errors?.status?.[0] ||
                    errors?.alasan?.[0] ||
                    "Penangguhan cuti gagal diproses.";
                suspendError.value = message;
            },
            onFinish: () => {
                sedangMemproses.value = false;
            },
        },
    );
};
// ===================== END MODAL PENANGGUHAN =====================

// ===================== MODAL DETAIL =====================
const detailModal = ref({ show: false, data: null });

const bukaDetailModal = (item) => {
    detailModal.value.data = item;
    detailModal.value.show = true;
};

const closeDetailModal = () => {
    detailModal.value.show = false;
    detailModal.value.data = null;
};

const detailModalTangguhkan = () => {
    if (!detailModal.value.data) return;
    const { id, pegawai } = detailModal.value.data;
    openSuspendModal(id, pegawai?.nama);
};

const bisaDitangguhkanDetail = computed(() =>
    bisaDitangguhkan(detailModal.value.data),
);
const alasanTidakBisaDetail = computed(() =>
    alasanTidakBisaDitangguhkan(detailModal.value.data),
);

// Timeline "Catatan / Respon Atasan" (sama dengan MonitoringCuti.vue)
const approvalTimeline = computed(() => {
    const data = detailModal.value.data;
    if (!data) return [];
    const st = data.status;
    const steps = getApprovalRoute(data.pegawai);
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
            stoppedLevel = matchLevel
                ? parseInt(matchLevel[1], 10)
                : steps[steps.length - 1].key;
        }
    }
    const catatanAtasan =
        stoppedLevel !== null ? getCatatanAtasan(data.keterangan) : "";
    const catatanMenyebutDitangguhkan = isKeteranganDitangguhkan(
        data.keterangan,
    );
    return steps.map((level) => {
        let label = "-";
        let color = "text-gray-400";
        let catatan = "";
        let nama = getLevelDisplayName(level.key, data);
        if (st === "disetujui") {
            label = "Disetujui";
            color = "text-emerald-700";
        } else if (pendingLevel !== null) {
            if (level.key < pendingLevel) {
                label = "Disetujui";
                color = "text-emerald-700";
            } else {
                label = "Menunggu Persetujuan";
                color = "text-orange-500";
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
                color = "text-rose-600";
                if (catatanAtasan) catatan = catatanAtasan;
            } else if (level.key > stoppedLevel) {
                label = "Menunggu Persetujuan";
                color = "text-orange-500";
            }
        }
        return { ...level, nama, status: label, color, catatan };
    });
});

const isPemohonKabiro = computed(
    () => Number(detailModal.value.data?.pegawai?.role_id) === 6,
);
// ===================== END MODAL DETAIL =====================

// ===================== ESC & KUNCI SCROLL =====================
// Esc menutup lapisan paling atas dulu: modal penangguhan -> modal detail -> dropdown.
const tanganiTombolEsc = (event) => {
    if (event.key !== "Escape") return;
    if (suspendData.value.show) {
        closeSuspendModal();
    } else if (detailModal.value.show) {
        closeDetailModal();
    } else {
        dropdownTerbuka.value = null;
    }
};

// Kunci scroll halaman di belakang saat ada modal terbuka.
watch(
    () => detailModal.value.show || suspendData.value.show,
    (adaModal) => {
        document.body.style.overflow = adaModal ? "hidden" : "";
    },
);

onMounted(() => {
    document.addEventListener("click", tutupDropdownSaatKlikLuar);
    window.addEventListener("keydown", tanganiTombolEsc);
});
onBeforeUnmount(() => {
    document.removeEventListener("click", tutupDropdownSaatKlikLuar);
    window.removeEventListener("keydown", tanganiTombolEsc);
    clearTimeout(toastTimer);
    document.body.style.overflow = "";
});
// ===================== END ESC & KUNCI SCROLL =====================
</script>

<template>
    <Head title="Penangguhan Cuti" />
    <MainLayout>
        <div
            class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6 w-full max-w-full"
        >
            <!-- Header -->
            <div class="mb-5">
                <h2 class="text-xl sm:text-2xl font-bold text-gray-800">
                    Manajemen Penangguhan Cuti
                </h2>
                <p class="text-sm text-gray-500 mt-1 leading-relaxed">
                    Hanya Cuti Tahunan berstatus Menunggu L1-L4 atau Disetujui
                    yang dapat ditangguhkan. Hari cuti yang ditangguhkan akan
                    ditambahkan ke saldo cuti pegawai pada tahun berikutnya.
                </p>
            </div>

            <!-- ============================================================
                 TOOLBAR FILTER (gaya SAMA dengan halaman Approval)
                 Mobile  : Search full → Kelompok full → Tahun full
                 Desktop : satu baris horizontal
                 ============================================================ -->
            <div
                @keydown.esc="dropdownTerbuka = null"
                class="mb-3 flex flex-col gap-2 lg:flex-row lg:flex-wrap lg:items-center lg:gap-2.5"
            >
                <!-- 1. Search -->
                <div
                    class="relative w-full min-w-0 lg:flex-1 lg:min-w-[12rem] lg:max-w-xs"
                >
                    <div
                        class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"
                    >
                        <svg
                            class="h-4 w-4 text-gray-400"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            aria-hidden="true"
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
                        id="cari-pengajuan-pembatalan"
                        name="cari_pengajuan"
                        v-model="search"
                        type="text"
                        aria-label="Cari nama atau NIP pegawai"
                        placeholder="Cari nama, NIP..."
                        class="min-h-[40px] pl-10 border border-gray-300 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 rounded-lg text-sm w-full bg-white"
                    />
                </div>

                <!-- 2. Kelompok + Tahun (dropdown kustom) -->
                <div
                    v-for="f in daftarFilter"
                    :key="f.key"
                    data-dropdown
                    :class="['relative w-full min-w-0 lg:shrink-0', f.lebar]"
                >
                    <div
                        class="absolute inset-y-0 left-0 pl-2.5 flex items-center pointer-events-none"
                    >
                        <svg
                            class="w-3.5 h-3.5 text-gray-400"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                            aria-hidden="true"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"
                            />
                        </svg>
                    </div>
                    <button
                        :id="f.id"
                        type="button"
                        :aria-label="f.ariaLabel"
                        aria-haspopup="listbox"
                        :aria-expanded="dropdownTerbuka === f.key"
                        :title="labelSelected(f)"
                        @click="toggleDropdown(f.key)"
                        class="w-full min-h-[40px] text-left text-sm border border-gray-300 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 focus:outline-none rounded-lg shadow-sm text-gray-700 py-1.5 pl-8 pr-7 bg-white cursor-pointer"
                    >
                        <span class="block truncate">{{
                            labelSelected(f)
                        }}</span>
                    </button>
                    <span
                        class="absolute inset-y-0 right-0 flex items-center pr-2 pointer-events-none"
                    >
                        <svg
                            class="w-3.5 h-3.5 text-gray-500 transition-transform"
                            :class="{
                                'rotate-180': dropdownTerbuka === f.key,
                            }"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                            aria-hidden="true"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M19 9l-7 7-7-7"
                            />
                        </svg>
                    </span>
                    <ul
                        v-if="dropdownTerbuka === f.key"
                        role="listbox"
                        class="absolute left-0 right-0 top-full z-30 mt-1 max-h-60 overflow-y-auto rounded-lg border border-gray-200 bg-white py-1 shadow-lg min-w-[12rem]"
                    >
                        <li v-for="opt in f.options" :key="opt.value">
                            <button
                                type="button"
                                role="option"
                                :title="opt.label"
                                :aria-selected="f.selected === opt.value"
                                @click="pilihFilter(f.key, opt.value)"
                                :class="
                                    f.selected === opt.value
                                        ? 'bg-emerald-50 text-emerald-700 font-semibold'
                                        : 'text-gray-700 hover:bg-gray-50'
                                "
                                class="block w-full whitespace-normal break-words leading-tight px-3 py-2 text-left text-sm cursor-pointer"
                            >
                                {{ opt.label }}
                            </button>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Catatan filter tahun -->
            <p class="mb-4 text-xs text-gray-500">
                Filter tahun menampilkan cuti yang tanggalnya beririsan dengan
                tahun terpilih. Cuti lintas tahun muncul di kedua tahunnya.
            </p>

            <!-- Chip Filter Aktif -->
            <div
                v-if="activeFilterChips.length > 0"
                class="flex flex-wrap items-center gap-2 mb-4 text-xs text-gray-500"
            >
                <span>Filter aktif:</span>
                <span
                    v-for="chip in activeFilterChips"
                    :key="chip.key"
                    class="inline-flex items-center gap-1.5 bg-emerald-50 text-emerald-700 font-semibold pl-3 pr-2 py-1 rounded-full"
                >
                    {{ chip.label }}
                    <button
                        type="button"
                        @click="chip.clear()"
                        class="text-emerald-600/70 hover:text-emerald-700"
                        aria-label="Hapus filter"
                    >
                        ✕
                    </button>
                </span>
                <button
                    type="button"
                    @click="resetFilter"
                    class="text-gray-400 underline hover:text-gray-600"
                >
                    Hapus semua
                </button>
                <span class="text-gray-500">{{ totalData }} pengajuan</span>
            </div>

            <!-- ===================== TABEL DESKTOP ===================== -->
            <div class="hidden md:block overflow-x-auto">
                <table class="w-full min-w-[900px] divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th
                                scope="col"
                                class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider whitespace-nowrap"
                            >
                                Nama Karyawan
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider whitespace-nowrap"
                            >
                                Jenis Cuti
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider whitespace-nowrap"
                            >
                                Tanggal Cuti
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider whitespace-nowrap"
                            >
                                Durasi
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider whitespace-nowrap"
                            >
                                Status Saat Ini
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider whitespace-nowrap"
                            >
                                Aksi
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <tr
                            v-for="item in paginatedData"
                            :key="item.id"
                            class="hover:bg-gray-50"
                        >
                            <!-- Nama -->
                            <td class="px-4 py-3">
                                <div
                                    class="text-sm font-medium text-gray-900 leading-snug"
                                >
                                    {{ item.pegawai?.nama || "-" }}
                                </div>
                                <div
                                    v-if="item.pegawai?.jabatan"
                                    class="text-xs text-gray-500 mt-0.5"
                                >
                                    {{ item.pegawai.jabatan }}
                                </div>
                            </td>

                            <!-- Jenis Cuti -->
                            <td class="px-4 py-3">
                                <span
                                    class="inline-flex px-2.5 py-0.5 text-xs font-semibold rounded-full bg-blue-100 text-blue-800 border border-blue-200"
                                >
                                    {{ item.jenis_cuti || "-" }}
                                </span>
                            </td>

                            <!-- Tanggal Cuti -->
                            <td
                                class="px-4 py-3 text-sm text-gray-900 whitespace-nowrap"
                            >
                                {{ formatDate(item.tanggal_mulai) }}
                                <span class="text-gray-400">s/d</span>
                                {{ formatDate(item.tanggal_selesai) }}
                            </td>

                            <!-- Durasi -->
                            <td
                                class="px-4 py-3 text-sm text-gray-900 whitespace-nowrap"
                            >
                                {{ getDurasi(item) }} Hari
                            </td>

                            <!-- Status -->
                            <td class="px-4 py-3">
                                <span
                                    class="inline-flex items-center px-2.5 py-0.5 rounded-md text-xs font-bold uppercase border"
                                    :class="statusBadgeClass(item.status)"
                                >
                                    {{ formatStatusBadge(item.status) }}
                                </span>
                            </td>

                            <!-- Aksi -->
                            <td class="px-4 py-3">
                                <div
                                    class="flex items-center justify-center gap-2"
                                >
                                    <!-- Tombol Lihat Detail -->
                                    <button
                                        type="button"
                                        @click="bukaDetailModal(item)"
                                        title="Lihat Detail"
                                        aria-label="Lihat detail pengajuan"
                                        class="inline-flex items-center justify-center p-1.5 bg-white border border-gray-300 rounded-lg text-gray-500 hover:bg-gray-50 hover:text-blue-600 transition shadow-sm cursor-pointer"
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
                                            />
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                stroke-width="2"
                                                d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"
                                            />
                                        </svg>
                                    </button>

                                    <!-- Tombol Tangguhkan -->
                                    <button
                                        v-if="bisaDitangguhkan(item)"
                                        type="button"
                                        @click.prevent="
                                            openSuspendModal(
                                                item.id,
                                                item.pegawai?.nama,
                                            )
                                        "
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-orange-50 border border-orange-200 text-orange-600 hover:bg-orange-100 font-semibold rounded-lg text-xs transition shadow-sm cursor-pointer"
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
                                            />
                                        </svg>
                                        Tangguhkan
                                    </button>

                                    <span
                                        v-else
                                        class="inline-flex items-center px-3 py-1.5 bg-slate-50 border border-slate-200 text-slate-400 font-semibold rounded-lg text-xs cursor-not-allowed"
                                        :title="
                                            alasanTidakBisaDitangguhkan(item) ||
                                            PESAN_TIDAK_BISA
                                        "
                                    >
                                        Tidak Bisa
                                    </span>
                                </div>
                            </td>
                        </tr>

                        <!-- Empty State -->
                        <tr v-if="paginatedData.length === 0">
                            <td
                                colspan="6"
                                class="px-6 py-10 text-center text-gray-500 text-sm"
                            >
                                Tidak ada pengajuan Cuti Tahunan yang bisa
                                ditangguhkan pada tahun {{ tahun }}.
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

            <!-- ===================== CARD MOBILE ===================== -->
            <div class="space-y-3 md:hidden">
                <article
                    v-for="item in paginatedData"
                    :key="item.id"
                    class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h3
                                class="break-words text-sm font-semibold text-gray-900"
                            >
                                {{ item.pegawai?.nama || "-" }}
                            </h3>
                            <p
                                v-if="item.pegawai?.jabatan"
                                class="mt-0.5 break-words text-xs text-gray-500"
                            >
                                {{ item.pegawai.jabatan }}
                            </p>
                        </div>
                        <span
                            class="shrink-0 rounded-md border px-2 py-0.5 text-xs font-bold uppercase"
                            :class="statusBadgeClass(item.status)"
                        >
                            {{ formatStatusBadge(item.status) }}
                        </span>
                    </div>

                    <dl class="mt-3 space-y-1.5 text-sm">
                        <div class="flex justify-between gap-3">
                            <dt class="text-gray-500">Jenis cuti</dt>
                            <dd class="text-right font-medium text-gray-800">
                                {{ item.jenis_cuti || "-" }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-gray-500">Tanggal</dt>
                            <dd class="text-gray-800 text-right">
                                {{ formatDate(item.tanggal_mulai) }} s/d
                                {{ formatDate(item.tanggal_selesai) }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-gray-500">Durasi</dt>
                            <dd class="font-medium text-gray-800">
                                {{ getDurasi(item) }} hari
                            </dd>
                        </div>
                    </dl>

                    <div class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <button
                            type="button"
                            @click="bukaDetailModal(item)"
                            class="min-h-[44px] rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 cursor-pointer"
                        >
                            Lihat detail
                        </button>
                        <button
                            v-if="bisaDitangguhkan(item)"
                            type="button"
                            @click.prevent="
                                openSuspendModal(item.id, item.pegawai?.nama)
                            "
                            class="min-h-[44px] rounded-lg border border-orange-200 bg-orange-50 px-3 py-2 text-sm font-semibold text-orange-700 cursor-pointer"
                        >
                            Tangguhkan
                        </button>
                        <span
                            v-else
                            class="flex min-h-[44px] items-center justify-center rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-400"
                            :title="
                                alasanTidakBisaDitangguhkan(item) ||
                                PESAN_TIDAK_BISA
                            "
                        >
                            Tidak bisa ditangguhkan
                        </span>
                    </div>

                    <!-- Alasan ditampilkan langsung (tooltip tidak terlihat di layar sentuh) -->
                    <p
                        v-if="
                            !bisaDitangguhkan(item) &&
                            alasanTidakBisaDitangguhkan(item)
                        "
                        class="mt-2 text-xs text-slate-500 leading-relaxed"
                    >
                        {{ alasanTidakBisaDitangguhkan(item) }}
                    </p>
                </article>

                <div
                    v-if="paginatedData.length === 0"
                    class="rounded-xl border border-gray-200 bg-white px-4 py-8 text-center text-sm text-gray-500"
                >
                    Tidak ada pengajuan Cuti Tahunan yang bisa ditangguhkan pada
                    tahun {{ tahun }}.
                    <button
                        v-if="adaFilterAktif"
                        type="button"
                        @click="resetFilter"
                        class="ml-1 text-orange-700 font-semibold hover:underline cursor-pointer"
                    >
                        Reset filter
                    </button>
                </div>
            </div>

            <!-- Pagination -->
            <div
                v-if="totalData > 0"
                class="flex flex-col sm:flex-row items-center justify-between mt-4 gap-3"
            >
                <div class="text-sm text-gray-500">
                    Menampilkan
                    <span class="font-medium text-gray-700">{{
                        displayedFrom
                    }}</span
                    >–<span class="font-medium text-gray-700">{{
                        displayedTo
                    }}</span>
                    dari
                    <span class="font-medium text-gray-700">{{
                        totalData
                    }}</span>
                    data
                </div>

                <nav
                    class="isolate inline-flex -space-x-px rounded-md shadow-sm"
                    aria-label="Pagination"
                >
                    <button
                        type="button"
                        @click="prevPage"
                        :disabled="currentPage === 1"
                        class="relative inline-flex items-center rounded-l-md px-3 py-2 text-sm font-medium text-gray-500 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed focus:z-20 focus:outline-offset-0 transition-colors"
                    >
                        &laquo; Previous
                    </button>
                    <button
                        v-for="(page, index) in pageNumbers"
                        :key="index"
                        type="button"
                        @click="page !== '...' ? setPage(page) : null"
                        :class="[
                            page === currentPage
                                ? 'z-10 bg-blue-600 text-white font-bold'
                                : 'text-gray-700 bg-white hover:bg-gray-50',
                            page === '...'
                                ? 'cursor-default'
                                : 'cursor-pointer',
                            'relative inline-flex items-center px-3.5 py-2 text-sm font-medium ring-1 ring-inset ring-gray-300 focus:z-20 focus:outline-offset-0 transition-colors',
                        ]"
                    >
                        {{ page }}
                    </button>
                    <button
                        type="button"
                        @click="nextPage"
                        :disabled="currentPage === totalPages"
                        class="relative inline-flex items-center rounded-r-md px-3 py-2 text-sm font-medium text-gray-500 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed focus:z-20 focus:outline-offset-0 transition-colors"
                    >
                        Next &raquo;
                    </button>
                </nav>
            </div>
        </div>
    </MainLayout>

    <!-- ===================== MODAL DETAIL =====================
         z-[9998]: di atas header/menu bawah, tetapi DI BAWAH modal penangguhan (z-[9999]). -->
    <Teleport to="body">
        <div
            v-if="detailModal.show"
            class="fixed inset-0 z-[9998] flex items-end justify-center bg-black/40 backdrop-blur-sm p-0 sm:items-center sm:p-4"
            @click.self="closeDetailModal"
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="judul-detail-cuti"
                class="bg-white rounded-t-2xl shadow-xl w-full max-w-lg max-h-[90dvh] flex flex-col overflow-hidden sm:rounded-2xl"
            >
                <div
                    class="shrink-0 flex justify-between items-start gap-3 px-5 sm:px-6 py-4 border-b border-gray-100"
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
                                />
                            </svg>
                        </div>
                        <div class="min-w-0">
                            <h3
                                id="judul-detail-cuti"
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
                        class="shrink-0 px-3 py-1 rounded-full border text-[10px] font-extrabold uppercase tracking-wider whitespace-nowrap"
                        :class="statusBadgeClass(detailModal.data.status)"
                    >
                        {{ formatStatusBadge(detailModal.data.status) }}
                    </span>
                </div>

                <div
                    class="flex-1 overflow-y-auto px-5 sm:px-6 py-4 space-y-3 text-sm"
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
                            Kelompok Substansi
                        </div>
                        <div class="col-span-8 text-slate-800 font-semibold">
                            {{ kelompokPegawai(detailModal.data.pegawai) }}
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

                    <!-- Anak ke berapa: hanya Cuti Melahirkan -->
                    <div
                        v-if="
                            detailModal.data.jenis_cuti
                                ?.toLowerCase()
                                .includes('melahirkan') &&
                            detailModal.data.anak_ke
                        "
                        class="grid grid-cols-12 gap-2"
                    >
                        <div class="col-span-4 text-gray-500 font-medium">
                            Anak ke berapa
                        </div>
                        <div class="col-span-8 text-slate-800 font-semibold">
                            {{ detailModal.data.anak_ke }}
                        </div>
                    </div>

                    <!-- Rincian Sisa Cuti (hanya Cuti Tahunan) -->
                    <div
                        v-if="
                            detailModal.data.jenis_cuti
                                ?.toLowerCase()
                                .includes('tahunan')
                        "
                        class="grid grid-cols-12 gap-2 items-start bg-amber-50 border border-amber-200 border-l-4 border-l-amber-400 rounded-lg px-3 py-2.5 -mx-1"
                    >
                        <div class="col-span-4 text-amber-700 font-semibold">
                            Rincian Sisa Cuti
                        </div>
                        <div class="col-span-8">
                            <span class="font-extrabold text-amber-800 block">
                                {{
                                    detailModal.data.pegawai
                                        ?.total_cuti_tersedia ??
                                    detailModal.data.pegawai
                                        ?.sisa_cuti_tersedia ??
                                    0
                                }}
                                Hari Total
                            </span>
                            <span
                                class="font-normal text-amber-600/80 text-xs block mt-0.5"
                            >
                                {{
                                    detailModal.data.pegawai
                                        ?.sisa_hak_tahun_berjalan ?? 0
                                }}
                                sisa tahun ini +
                                {{
                                    detailModal.data.pegawai
                                        ?.saldo_bawaan_eligible ?? 0
                                }}
                                bawaan +
                                {{
                                    detailModal.data.pegawai
                                        ?.cuti_dikembalikan ?? 0
                                }}
                                dikembalikan
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
                    <div class="grid grid-cols-12 gap-2">
                        <div class="col-span-4 text-gray-500 font-medium">
                            Nomor Telepon
                        </div>
                        <div class="col-span-8 text-slate-800 font-semibold">
                            {{ getNomorTelepon(detailModal.data) }}
                        </div>
                    </div>
                    <div class="grid grid-cols-12 gap-2 items-center">
                        <div class="col-span-4 text-gray-500 font-medium">
                            Lampiran
                        </div>
                        <div class="col-span-8">
                            <a
                                v-if="detailModal.data.lampiran"
                                :href="
                                    getLampiranUrl(detailModal.data.lampiran)
                                "
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

                    <!-- Catatan / Respon Atasan (sama dengan halaman Approval) -->
                    <div class="pt-1">
                        <div
                            :class="[
                                'rounded-xl p-4 border',
                                detailModal.data.status === 'disetujui'
                                    ? 'bg-emerald-50 border-emerald-200'
                                    : 'bg-amber-50 border-amber-200',
                            ]"
                        >
                            <h4
                                :class="[
                                    'text-xs font-bold uppercase tracking-wider mb-3',
                                    detailModal.data.status === 'disetujui'
                                        ? 'text-emerald-700'
                                        : 'text-orange-600',
                                ]"
                            >
                                Catatan / Respon Atasan
                            </h4>
                            <div
                                v-if="isPemohonKabiro"
                                class="bg-white rounded-lg border border-slate-100 px-3 py-2.5 flex flex-col shadow-sm"
                            >
                                <span class="text-xs text-gray-500 mb-1">
                                    L4 - Kepala Biro Perencanaan:
                                    <span class="font-semibold text-gray-800">{{
                                        detailModal.data.pegawai?.nama
                                    }}</span>
                                </span>
                                <span
                                    class="text-xs font-bold text-emerald-700"
                                >
                                    Disetujui otomatis (pemohon adalah Kepala
                                    Biro)
                                </span>
                            </div>
                            <div v-else class="space-y-2">
                                <div
                                    v-for="level in approvalTimeline"
                                    :key="level.key"
                                    class="bg-white rounded-lg border border-slate-100 px-3 py-2.5 flex flex-col shadow-sm"
                                >
                                    <span class="text-xs text-gray-500 mb-1">
                                        {{ level.jabatan }}:
                                        <span
                                            class="font-semibold text-gray-800"
                                            >{{ level.nama }}</span
                                        >
                                    </span>
                                    <span
                                        class="text-xs"
                                        :class="[
                                            level.color,
                                            level.catatan
                                                ? 'font-medium'
                                                : 'font-bold',
                                        ]"
                                    >
                                        {{ level.catatan || level.status }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div
                    class="shrink-0 border-t border-gray-100 px-5 sm:px-6 py-4 flex items-center justify-end gap-3"
                >
                    <button
                        type="button"
                        @click="closeDetailModal"
                        class="px-5 py-2 bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 rounded-xl text-sm font-bold transition shadow-sm cursor-pointer"
                    >
                        Tutup
                    </button>
                    <button
                        v-if="bisaDitangguhkanDetail"
                        type="button"
                        @click="detailModalTangguhkan"
                        class="px-5 py-2 bg-orange-600 hover:bg-orange-700 text-white rounded-xl text-sm font-bold transition shadow-sm cursor-pointer"
                    >
                        Tangguhkan
                    </button>
                </div>
            </div>
        </div>
    </Teleport>

    <!-- ===================== MODAL PENANGGUHAN ===================== -->
    <Teleport to="body">
        <div
            v-if="suspendData.show"
            class="fixed inset-0 z-[9999] flex items-end justify-center bg-slate-900/60 backdrop-blur-sm p-0 sm:items-center sm:p-4"
            @click.self="closeSuspendModal"
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="judul-tangguhkan-cuti"
                class="bg-white rounded-t-2xl sm:rounded-2xl max-w-md w-full max-h-[90dvh] overflow-y-auto shadow-2xl border border-slate-100"
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
                            />
                        </svg>
                    </div>
                    <div>
                        <h3
                            id="judul-tangguhkan-cuti"
                            class="text-base font-bold text-slate-800"
                        >
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

                <div class="p-5 sm:p-6">
                    <label
                        for="alasan-penangguhan"
                        class="block text-sm font-semibold text-slate-700 mb-2"
                    >
                        Alasan Penangguhan
                        <span class="text-red-500">*</span>
                    </label>
                    <textarea
                        id="alasan-penangguhan"
                        name="alasan"
                        v-model="suspendData.alasan"
                        rows="3"
                        :disabled="sedangMemproses"
                        class="w-full min-h-[88px] text-base sm:text-sm border rounded-xl focus:ring-orange-500 focus:border-orange-500 disabled:bg-slate-50 disabled:text-slate-400"
                        :class="
                            suspendError
                                ? 'border-red-400 focus:ring-red-500 focus:border-red-500'
                                : 'border-slate-200'
                        "
                        placeholder="Contoh: Perintah tugas mendadak untuk persiapan dinas luar kota."
                        @input="suspendError = ''"
                    ></textarea>
                    <p
                        v-if="suspendError"
                        class="mt-2 text-xs font-semibold text-red-600 flex items-center gap-1.5"
                        role="alert"
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
                        {{ suspendError }}
                    </p>
                </div>

                <div
                    class="p-4 bg-slate-50 border-t border-slate-100 flex justify-end gap-2"
                >
                    <button
                        type="button"
                        @click.prevent="closeSuspendModal"
                        :disabled="sedangMemproses"
                        class="px-4 py-2 bg-white border border-slate-300 text-slate-700 hover:bg-slate-50 rounded-xl text-sm font-semibold transition cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        Batal
                    </button>
                    <button
                        type="button"
                        @click.prevent="submitSuspend"
                        :disabled="sedangMemproses"
                        class="px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white rounded-xl text-sm font-semibold transition cursor-pointer disabled:opacity-60 disabled:cursor-not-allowed"
                    >
                        {{
                            sedangMemproses
                                ? "Memproses..."
                                : "Proses Penangguhan"
                        }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>

    <!-- ===================== TOAST ===================== -->
    <Teleport to="body">
        <Transition
            enter-active-class="transform ease-out duration-300 transition"
            enter-from-class="-translate-y-10 opacity-0"
            enter-to-class="translate-y-0 opacity-100"
            leave-active-class="transition ease-in duration-200"
            leave-from-class="opacity-100 translate-y-0"
            leave-to-class="opacity-0 -translate-y-10"
        >
            <div
                v-if="toast.show"
                role="status"
                aria-live="polite"
                class="fixed top-6 left-1/2 -translate-x-1/2 z-[10000] max-w-sm w-[calc(100%-2rem)] bg-white shadow-2xl rounded-2xl pointer-events-auto border border-gray-100 overflow-hidden flex items-start p-4"
            >
                <div class="shrink-0 pt-0.5">
                    <div
                        class="h-8 w-8 rounded-full bg-emerald-100 flex items-center justify-center"
                    >
                        <svg
                            class="h-5 w-5 text-emerald-600"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke-width="2.5"
                            stroke="currentColor"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M4.5 12.75l6 6 9-13.5"
                            />
                        </svg>
                    </div>
                </div>
                <div class="ml-3 w-0 flex-1">
                    <p class="text-sm font-bold text-gray-900">Aksi Berhasil</p>
                    <p class="mt-1 text-sm text-gray-600">
                        {{ toast.message }}
                    </p>
                </div>
                <div class="ml-4 flex shrink-0">
                    <button
                        type="button"
                        @click="toast.show = false"
                        class="inline-flex rounded-md bg-white text-gray-400 hover:text-gray-600 focus:outline-none transition-colors cursor-pointer"
                    >
                        <span class="sr-only">Tutup</span>
                        <svg
                            class="h-5 w-5"
                            viewBox="0 0 20 20"
                            fill="currentColor"
                        >
                            <path
                                fill-rule="evenodd"
                                d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
                                clip-rule="evenodd"
                            />
                        </svg>
                    </button>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>