<script setup>
import MainLayout from "@/Layouts/MainLayout.vue";
import { Head, router, usePage } from "@inertiajs/vue3";
import {
    ref,
    watch,
    computed,
    nextTick,
    onMounted,
    onBeforeUnmount,
} from "vue";

// ================= PETA KELOMPOK PEGAWAI =================
const DAFTAR_KELOMPOK = [
    {
        value: "tata_usaha",
        label: "Subbagian Tata Usaha",
        singkat: "Subbagian Tata Usaha",
    },
    {
        value: "kebijakan",
        label: "Kelompok Kebijakan Pembangunan Pertanian",
        singkat: "Kelompok Kebijakan Pembangunan Pertanian",
    },
    {
        value: "program_anggaran",
        label: "Kelompok Program Dan Anggaran Pembangunan Pertanian",
        singkat: "Kelompok Program Dan Anggaran Pembangunan Pertanian",
    },
    {
        value: "pemantauan",
        label: "Kelompok Pemantauan, Evaluasi Dan Pelaporan Program Pembangunan Pertanian",
        singkat:
            "Kelompok Pemantauan, Evaluasi Dan Pelaporan Program Pembangunan Pertanian",
    },
    {
        value: "kawasan",
        label: "Kelompok Pengembangan Kawasan Pertanian",
        singkat: "Kelompok Pengembangan Kawasan Pertanian",
    },
];
// ================= END PETA KELOMPOK PEGAWAI =================

// ================= TANPA KELOMPOK =================
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

const adaKelompokPegawai = (pegawai) =>
    !kosongKelompok(pegawai?.kelompok_substansi) ||
    !kosongKelompok(pegawai?.departemen);
// ================= END TANPA KELOMPOK =================

function debounce(fn, delay) {
    let timeoutId;
    return (...args) => {
        clearTimeout(timeoutId);
        timeoutId = setTimeout(() => fn(...args), delay);
    };
}

// ================= PROPS =================
const props = defineProps({
    antrean: Object,
    filters: Object,
    opsi_tim_kerja: {
        type: Array,
        default: () => [],
    },
    userTimKerja: {
        type: String,
        default: "",
    },
    listKelompok: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const userRole = computed(() => page.props.auth.user.role_id);
const isReadOnly = computed(() => userRole.value === 5);

// ================= SKEMA FILTER BERJENJANG =================
const filterMode = computed(() => {
    if (userRole.value === 2) return "l1";
    if (userRole.value === 3) return "l2";
    if (userRole.value === 4 || userRole.value === 6 || userRole.value === 5)
        return "l3l4";
    return "none";
});

const showFilterDropdown = computed(
    () => filterMode.value === "l2" || filterMode.value === "l3l4",
);

const showBadgeTimL1 = computed(
    () => filterMode.value === "l1" && !!props.userTimKerja,
);

const opsiKelompok = computed(() => {
    const dasar =
        props.listKelompok && props.listKelompok.length > 0
            ? props.listKelompok
            : DAFTAR_KELOMPOK.map((k) => k.value);

    const bernama = dasar.filter((k) => !kosongKelompok(k));
    const tanpa = dasar.find(
        (k) => String(k ?? "").trim() !== "" && kosongKelompok(k),
    );

    return tanpa !== undefined ? [tanpa, ...bernama] : bernama;
});

const labelKelompok = (value) => {
    if (kosongKelompok(value)) return LABEL_TANPA_KELOMPOK;
    const match = DAFTAR_KELOMPOK.find((k) => k.value === value);
    return match ? match.singkat : value;
};
// ================= END SKEMA FILTER BERJENJANG =================

const canApprove = (item) => {
    if (isReadOnly.value) return false;
    const roleId = userRole.value;
    const allowedStatus = {
        2: "menunggu_l1",
        3: "menunggu_l2",
        4: "menunggu_l3",
        6: "menunggu_l4",
    };
    return item.status === allowedStatus[roleId];
};

const search = ref(props.filters?.search || "");
const jenisCuti = ref(props.filters?.jenis_cuti || "Semua Jenis Cuti");
const status = ref(props.filters?.status || "");
const kelompok = ref(props.filters?.kelompok || "");
const timKerja = ref(props.filters?.tim_kerja || "");

// ================================================================
// DROPDOWN FILTER KUSTOM
// Mobile  : Search full → Kelompok full → Jenis | Status (2 kolom)
// Desktop : satu baris horizontal (seperti foto)
// ================================================================
const dropdownTerbuka = ref(null);

const toggleDropdown = (nama) => {
    dropdownTerbuka.value = dropdownTerbuka.value === nama ? null : nama;
};

const OPSI_JENIS_CUTI = [
    "Semua Jenis Cuti",
    "Cuti Tahunan",
    "Cuti Melahirkan",
    "Cuti Besar",
    "Cuti Alasan Penting",
].map((j) => ({ value: j, label: j }));

const OPSI_STATUS = [
    { value: "", label: "Semua Status" },
    { value: "menunggu_l1", label: "Menunggu L1" },
    { value: "menunggu_l2", label: "Menunggu L2" },
    { value: "menunggu_l3", label: "Menunggu L3" },
    { value: "menunggu_l4", label: "Menunggu L4" },
    { value: "disetujui", label: "Disetujui" },
    { value: "ditolak", label: "Ditolak" },
    { value: "ditangguhkan", label: "Ditangguhkan" },
    { value: "dibatalkan_reguler", label: "Dibatalkan (Reguler)" },
    { value: "kedaluwarsa", label: "Kedaluwarsa" },
];

/** Filter wilayah (Tim Kerja / Kelompok) */
const filterWilayah = computed(() => {
    if (filterMode.value === "l2") {
        return {
            key: "tim",
            id: "filter-tim-kerja",
            ariaLabel: "Filter tim kerja",
            selected: timKerja.value,
            options: [
                { value: "", label: "Semua Tim Kerja" },
                ...props.opsi_tim_kerja.map((t) => ({
                    value: t.value || t,
                    label: t.label || t,
                })),
            ],
        };
    }
    if (filterMode.value === "l3l4") {
        return {
            key: "kelompok",
            id: "filter-kelompok-monitoring",
            ariaLabel: "Filter kelompok substansi",
            selected: kelompok.value,
            options: [
                { value: "", label: "Semua Kelompok" },
                ...opsiKelompok.value.map((k) => ({
                    value: k,
                    label: labelKelompok(k),
                })),
            ],
        };
    }
    return null;
});

/** Jenis Cuti + Status */
const filterLain = computed(() => [
    {
        key: "jenis",
        id: "filter-jenis-cuti-monitoring",
        ariaLabel: "Filter jenis cuti",
        selected: jenisCuti.value,
        options: OPSI_JENIS_CUTI,
    },
    {
        key: "status",
        id: "filter-status-monitoring",
        ariaLabel: "Filter status pengajuan",
        selected: status.value,
        options: OPSI_STATUS,
    },
]);

const labelSelected = (f) => {
    const cocok = f.options.find((o) => o.value === f.selected);
    return cocok ? cocok.label : f.selected || f.options[0]?.label || "";
};

const pilihFilter = (key, nilai) => {
    if (key === "tim") timKerja.value = nilai;
    else if (key === "kelompok") kelompok.value = nilai;
    else if (key === "jenis") jenisCuti.value = nilai;
    else if (key === "status") status.value = nilai;
    dropdownTerbuka.value = null;
};

const tutupDropdownSaatKlikLuar = (event) => {
    if (!event.target.closest?.("[data-dropdown]")) {
        dropdownTerbuka.value = null;
    }
};
onMounted(() => document.addEventListener("click", tutupDropdownSaatKlikLuar));
onBeforeUnmount(() =>
    document.removeEventListener("click", tutupDropdownSaatKlikLuar),
);
// ================= END DROPDOWN FILTER KUSTOM =================

const buildParams = () => {
    const params = {
        search: search.value,
        jenis_cuti: jenisCuti.value,
        status: status.value,
    };
    if (filterMode.value === "l3l4" && kelompok.value)
        params.kelompok = kelompok.value;
    if (filterMode.value === "l2" && timKerja.value)
        params.tim_kerja = timKerja.value;
    return params;
};

watch(
    [search, jenisCuti, status, kelompok, timKerja],
    debounce(function () {
        router.get(route("atasan.approval"), buildParams(), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, 300),
);

const antreanTampil = computed(() => props.antrean?.data ?? []);

const labelFilterAktif = computed(() => {
    if (filterMode.value === "l3l4" && kelompok.value) {
        return labelKelompok(kelompok.value);
    }
    if (filterMode.value === "l2" && timKerja.value) {
        const obj = props.opsi_tim_kerja.find(
            (t) => (t.value || t) === timKerja.value,
        );
        return obj ? obj.label || obj : timKerja.value;
    }
    return "";
});

const adaFilterAktif = computed(
    () =>
        !!search.value ||
        !!kelompok.value ||
        !!timKerja.value ||
        !!status.value ||
        jenisCuti.value !== "Semua Jenis Cuti",
);

const resetFilter = () => {
    search.value = "";
    jenisCuti.value = "Semua Jenis Cuti";
    status.value = "";
    kelompok.value = "";
    timKerja.value = "";
};

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
    kedaluwarsa: "Kedaluwarsa",
};

const getStatusLabel = (st) =>
    statusLabels[st] || st?.replace(/_/g, " ").toUpperCase() || "-";

const isKeteranganDitangguhkan = (keterangan) =>
    /ditangguhkan/i.test(keterangan || "");

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

const getEffectiveStatusLabel = (item) =>
    getStatusLabel(getEffectiveStatus(item));

const statusLabelsSingkat = {
    menunggu_l1: "MENUNGGU L1",
    menunggu_l2: "MENUNGGU L2",
    menunggu_l3: "MENUNGGU L3",
    menunggu_l4: "MENUNGGU L4",
    disetujui: "DISETUJUI",
    ditolak: "DITOLAK",
    ditangguhkan: "DITANGGUHKAN",
    dibatalkan_reguler: "DIBATALKAN",
    dibatalkan_ditangguhkan: "DITANGGUHKAN",
    kedaluwarsa: "KEDALUWARSA",
};

const getEffectiveStatusLabelSingkatTahap = (item) => {
    const st = getEffectiveStatus(item);
    return (
        statusLabelsSingkat[st] || st?.replace(/_/g, " ").toUpperCase() || "-"
    );
};

const kelasBadgeStatus = (item) => ({
    "bg-amber-100 text-amber-800 border-amber-300":
        !!item.status?.includes("menunggu"),
    "bg-emerald-100 text-emerald-800 border-emerald-300":
        item.status === "disetujui",
    "bg-rose-100 text-rose-800 border-rose-300":
        getEffectiveStatus(item) === "ditolak",
    "bg-slate-100 text-slate-600 border-slate-300":
        getEffectiveStatus(item) === "kedaluwarsa",
    "bg-gray-200 text-gray-800 border-gray-300":
        getEffectiveStatus(item) === "ditangguhkan" ||
        !!item.status?.includes("ditangguhkan") ||
        !!item.status?.includes("dibatalkan"),
    "bg-gray-200 text-gray-600 border-gray-300": !item.status,
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

const hitungDurasi = (mulai, selesai) => {
    if (!mulai || !selesai) return 1;
    const tglMulai = new Date(mulai);
    const tglSelesai = new Date(selesai);
    const selisih =
        Math.ceil((tglSelesai - tglMulai) / (1000 * 60 * 60 * 24)) + 1;
    return selisih > 0 ? selisih : 1;
};

const getDurasi = (item) => {
    if (item?.jumlah_hari && item.jumlah_hari > 0) return item.jumlah_hari;
    return hitungDurasi(item?.tanggal_mulai, item?.tanggal_selesai);
};

const processApproval = (id, action) => {
    if (action === "approve") openApproveModal(id);
    else if (action === "reject") openRejectModal(id);
};

const approveModal = ref({ show: false, id: null, item: null });

const openApproveModal = (id) => {
    const item = props.antrean?.data?.find((i) => i.id === id) || null;
    approveModal.value = { show: true, id, item };
};

const closeApproveModal = () => {
    approveModal.value = { show: false, id: null, item: null };
};

const submitApprove = () => {
    if (!approveModal.value.id) return;
    router.post(
        route("atasan.approval.process", approveModal.value.id),
        { action: "approve" },
        { preserveScroll: true, onSuccess: () => closeApproveModal() },
    );
};

const rejectModal = ref({
    show: false,
    id: null,
    item: null,
    alasan: "",
    error: "",
    shake: false,
});
const rejectTextarea = ref(null);

const openRejectModal = (id) => {
    const item = props.antrean?.data?.find((i) => i.id === id) || null;
    rejectModal.value = {
        show: true,
        id,
        item,
        alasan: "",
        error: "",
        shake: false,
    };
    nextTick(() => rejectTextarea.value?.focus());
};

const closeRejectModal = () => {
    rejectModal.value = {
        show: false,
        id: null,
        item: null,
        alasan: "",
        error: "",
        shake: false,
    };
};

watch(
    () => rejectModal.value.alasan,
    () => {
        if (rejectModal.value.error) rejectModal.value.error = "";
    },
);

const submitReject = () => {
    if (!rejectModal.value.alasan || rejectModal.value.alasan.trim() === "") {
        rejectModal.value.error = "Alasan penolakan wajib diisi.";
        rejectModal.value.shake = false;
        nextTick(() => {
            rejectModal.value.shake = true;
            rejectTextarea.value?.focus();
        });
        return;
    }
    router.post(
        route("atasan.approval.process", rejectModal.value.id),
        { action: "reject", alasan: rejectModal.value.alasan },
        { preserveScroll: true, onSuccess: () => closeRejectModal() },
    );
};

const goToPage = (url) => {
    if (!url) return;
    router.get(url, buildParams(), {
        preserveState: true,
        preserveScroll: true,
    });
};

const detailModal = ref({ show: false, data: null });

const bukaDetailModal = (item) => {
    detailModal.value = { show: true, data: item };
};

const closeDetailModal = () => {
    detailModal.value = { show: false, data: null };
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

const getNamaPemberiCatatan = (text) => {
    const isi = getIsiCatatanMentah(text);
    if (!isi || !/oleh/i.test(isi)) return "";
    const sebelumTitikDua = isi.split(":")[0];
    const bagianSetelahOleh = sebelumTitikDua.split(/oleh/i);
    return bagianSetelahOleh.length > 1 ? bagianSetelahOleh[1].trim() : "";
};

// ================= NAMA L1/L2 DINAMIS + RUTE APPROVAL =================
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
            const kelompok = (
                pegawai?.kelompok_substansi ??
                pegawai?.departemen ??
                ""
            )
                .toString()
                .trim()
                .toLowerCase();
            const isSubbagianTataUsaha =
                kelompok.includes("subbagian tata usaha") ||
                kelompok.includes("tata usaha") ||
                kelompok === "tata_usaha";
            if (isSubbagianTataUsaha) {
                return [levelsDefinition[2], levelsDefinition[3]];
            }
            const timKerja = (pegawai?.tim_kerja ?? "").toString().trim();
            const tanpaTimKerja = timKerja === "" || timKerja === "-";
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
// ================= END =================

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
        kedaluwarsa: "KEDALUWARSA",
    };
    return levelMap[st] || st.replace(/_/g, " ").toUpperCase();
};

const filteredApprovalTimeline = computed(() => approvalTimeline.value);

const isPemohonKabiro = computed(
    () => Number(detailModal.value.data?.pegawai?.role_id) === 6,
);

const detailModalApprove = () => {
    if (!detailModal.value.data) return;
    const id = detailModal.value.data.id;
    closeDetailModal();
    processApproval(id, "approve");
};

const detailModalReject = () => {
    if (!detailModal.value.data) return;
    const id = detailModal.value.data.id;
    closeDetailModal();
    processApproval(id, "reject");
};

const canApproveDetail = computed(() => {
    const data = detailModal.value.data;
    return !!data && canApprove(data);
});

const isDitangguhkanDetail = computed(() => {
    const st = getEffectiveStatus(detailModal.value.data);
    return st === "ditangguhkan" || st === "dibatalkan_ditangguhkan";
});

const getStoppedLevel = (data) => {
    if (!data) return null;
    if (data.level_saat_ini) {
        const n = parseInt(data.level_saat_ini, 10);
        if (!isNaN(n)) return Math.min(n, 4);
    }
    const matchLevel = (data.keterangan || "").match(/l([1-4])/i);
    if (matchLevel) return parseInt(matchLevel[1], 10);
    const steps = getApprovalRoute(data.pegawai);
    return steps.length > 0 ? steps[steps.length - 1].key : null;
};

const pemrosesDitangguhkan = computed(() => {
    if (!isDitangguhkanDetail.value) return null;
    const data = detailModal.value.data;
    const lv = levelsDefinition.find((l) => l.key === getStoppedLevel(data));
    return {
        jabatanBersih: lv
            ? lv.jabatan.replace(/^L\d\s*-\s*/, "")
            : getNamaPemberiCatatan(data.keterangan),
        catatan:
            getCatatanAtasan(data.keterangan) ||
            data.catatan_penangguhan ||
            data.alasan_penangguhan ||
            data.catatan_atasan ||
            "",
    };
});

const getApprovalLogs = (item) =>
    item?.approval_logs || item?.approvalLogs || [];

const buildRiwayatPersetujuan = (item) => {
    const approvals = Array.isArray(item?.approvals) ? item.approvals : [];
    const logs = getApprovalLogs(item);
    const steps = getApprovalRoute(item?.pegawai);
    return steps.map((level) => {
        let status = "Menunggu Persetujuan";
        let color = "text-orange-500";
        const nama = getLevelDisplayName(level.key, item);
        const ap = approvals.find((a) => Number(a.level) === level.key);
        if (ap) {
            const s = (ap.status || "").toString().toUpperCase();
            if (s === "DISETUJUI") {
                status = "Disetujui";
                color = "text-emerald-700";
            } else if (s === "DITOLAK") {
                status = "Ditolak";
                color = "text-rose-600";
            }
        } else {
            const log = logs
                .filter((l) => Number(l.level_approval) === level.key)
                .reduce(
                    (best, cur) =>
                        !best || Number(cur.id) > Number(best.id) ? cur : best,
                    null,
                );
            if (log?.keputusan === "setuju") {
                status = "Disetujui";
                color = "text-emerald-700";
            } else if (log?.keputusan === "tolak") {
                status = "Ditolak";
                color = "text-rose-600";
            }
        }
        return { ...level, nama, status, color, catatan: "" };
    });
};

const riwayatDitangguhkan = computed(() =>
    buildRiwayatPersetujuan(detailModal.value.data),
);

const isDibatalkanDetail = computed(() => {
    const st = getEffectiveStatus(detailModal.value.data);
    return (
        !!st && st.startsWith("dibatalkan") && st !== "dibatalkan_ditangguhkan"
    );
});

const riwayatDibatalkan = computed(() =>
    buildRiwayatPersetujuan(detailModal.value.data),
);
</script>

<template>
    <Head
        :title="
            isReadOnly
                ? 'Monitoring Persetujuan Cuti'
                : 'Daftar Persetujuan Cuti'
        "
    />

    <MainLayout>
        <div class="bg-white shadow-sm sm:rounded-lg p-4 sm:p-6">
            <div class="mb-5">
                <h2 class="text-2xl font-bold text-gray-800">
                    {{
                        isReadOnly
                            ? "Monitoring Persetujuan Cuti"
                            : "Daftar Persetujuan Cuti"
                    }}
                </h2>
                <p class="text-sm text-gray-500 mt-1">
                    Pantau status pengajuan cuti seluruh pegawai secara
                    real-time.
                </p>
            </div>

            <!-- ============================================================
                 FILTER RESPONSIVE
                 Mobile  : Search full → Kelompok full → Jenis | Status
                 Desktop : satu baris horizontal (seperti foto)
                 ============================================================ -->
            <div
                @keydown.esc="dropdownTerbuka = null"
                class="mb-5 flex flex-col gap-2 lg:flex-row lg:flex-wrap lg:items-center lg:gap-2.5"
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
                        id="cari-antrean-cuti"
                        name="cari"
                        v-model="search"
                        type="text"
                        aria-label="Cari nama atau NIP pegawai"
                        placeholder="Cari nama, NIP..."
                        class="min-h-[40px] pl-10 border border-gray-300 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 rounded-lg text-sm w-full bg-white"
                    />
                </div>

                <!-- 2. Kelompok / Tim Kerja -->
                <div
                    v-if="filterWilayah"
                    data-dropdown
                    class="relative w-full min-w-0 lg:w-52 lg:shrink-0"
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
                        :id="filterWilayah.id"
                        type="button"
                        :aria-label="filterWilayah.ariaLabel"
                        aria-haspopup="listbox"
                        :aria-expanded="dropdownTerbuka === filterWilayah.key"
                        :title="labelSelected(filterWilayah)"
                        @click="toggleDropdown(filterWilayah.key)"
                        class="w-full min-h-[40px] text-left text-sm border border-gray-300 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 focus:outline-none rounded-lg shadow-sm text-gray-700 py-1.5 pl-8 pr-7 bg-white cursor-pointer"
                    >
                        <span class="block truncate">{{
                            labelSelected(filterWilayah)
                        }}</span>
                    </button>
                    <span
                        class="absolute inset-y-0 right-0 flex items-center pr-2 pointer-events-none"
                    >
                        <svg
                            class="w-3.5 h-3.5 text-gray-500 transition-transform"
                            :class="{
                                'rotate-180':
                                    dropdownTerbuka === filterWilayah.key,
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
                        v-if="dropdownTerbuka === filterWilayah.key"
                        role="listbox"
                        class="absolute left-0 right-0 top-full z-30 mt-1 max-h-60 overflow-y-auto rounded-lg border border-gray-200 bg-white py-1 shadow-lg"
                    >
                        <li
                            v-for="opt in filterWilayah.options"
                            :key="opt.value"
                        >
                            <button
                                type="button"
                                role="option"
                                :title="opt.label"
                                :aria-selected="
                                    filterWilayah.selected === opt.value
                                "
                                @click="
                                    pilihFilter(filterWilayah.key, opt.value)
                                "
                                :class="
                                    filterWilayah.selected === opt.value
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

                <!-- 3. Jenis Cuti + Status
                     Mobile  : grid 2 kolom
                     Desktop : ikut baris flex -->
                <div
                    class="grid grid-cols-2 gap-2 w-full lg:w-auto lg:flex lg:gap-2.5 lg:shrink-0"
                >
                    <div
                        v-for="f in filterLain"
                        :key="f.key"
                        data-dropdown
                        class="relative w-full min-w-0 lg:w-44"
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

                <!-- Badge Tim Kerja L1 -->
                <div
                    v-if="showBadgeTimL1"
                    class="inline-flex items-center justify-center gap-1.5 px-3 py-1.5 rounded-lg bg-green-50 border border-green-200 text-green-700 text-xs font-semibold w-full lg:w-auto lg:ml-auto"
                >
                    <svg
                        class="w-3.5 h-3.5 shrink-0"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                        aria-hidden="true"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 100-8 4 4 0 000 8zm6 3c0-2.21-3.582-4-8-4s-8 1.79-8 4"
                        />
                    </svg>
                    <span class="min-w-0 break-words"
                        >Menampilkan tim Anda: {{ userTimKerja }}</span
                    >
                </div>
            </div>

            <!-- Penanda filter aktif -->
            <div
                v-if="kelompok || timKerja"
                class="flex flex-wrap items-center gap-2 mb-4 text-xs"
            >
                <span
                    class="inline-flex max-w-full items-center gap-1.5 px-2.5 py-1 rounded-full bg-green-50 text-green-700 border border-green-200 font-semibold"
                >
                    <span class="min-w-0 break-words">{{
                        labelFilterAktif
                    }}</span>
                    <button
                        type="button"
                        @click="
                            kelompok = '';
                            timKerja = '';
                        "
                        class="shrink-0 hover:text-green-900 cursor-pointer"
                        title="Hapus filter"
                        aria-label="Hapus filter"
                    >
                        &times;
                    </button>
                </span>
                <span class="text-gray-500"
                    >{{ antreanTampil.length }} pengajuan pada halaman ini</span
                >
            </div>

            <!-- ================= TABEL (lg+) ================= -->
            <div class="w-full min-w-0">
                <div
                    class="hidden overflow-hidden rounded-lg border border-gray-200 lg:block"
                >
                    <table class="w-full table-fixed divide-y divide-gray-200">
                        <colgroup>
                            <col class="w-[26%]" />
                            <col class="w-[14%]" />
                            <col class="w-[17%]" />
                            <col class="w-[8%]" />
                            <col />
                            <col
                                :class="isReadOnly ? 'w-[76px]' : 'w-[184px]'"
                            />
                        </colgroup>
                        <thead class="bg-gray-50">
                            <tr>
                                <th
                                    scope="col"
                                    class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                                >
                                    Nama Karyawan
                                </th>
                                <th
                                    scope="col"
                                    class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                                >
                                    Jenis Cuti
                                </th>
                                <th
                                    scope="col"
                                    class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                                >
                                    Tanggal Cuti
                                </th>
                                <th
                                    scope="col"
                                    class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                                >
                                    Durasi
                                </th>
                                <th
                                    scope="col"
                                    class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                                >
                                    Tahap
                                </th>
                                <th
                                    scope="col"
                                    class="px-3 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider"
                                >
                                    Aksi
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <tr
                                v-for="item in antreanTampil"
                                :key="item.id"
                                class="hover:bg-gray-50"
                            >
                                <td class="px-3 py-3.5 align-middle">
                                    <div
                                        class="break-words text-sm font-medium leading-snug text-gray-900"
                                        :title="item.pegawai.nama"
                                    >
                                        {{ item.pegawai.nama }}
                                    </div>
                                    <div
                                        class="mt-0.5 break-words text-xs leading-snug"
                                        :class="
                                            adaKelompokPegawai(item.pegawai)
                                                ? 'text-gray-500'
                                                : 'italic text-gray-400'
                                        "
                                        :title="kelompokPegawai(item.pegawai)"
                                    >
                                        {{ kelompokPegawai(item.pegawai) }}
                                    </div>
                                </td>
                                <td class="px-3 py-3.5 align-middle">
                                    <span
                                        class="inline-flex max-w-full px-2.5 py-1 text-xs leading-tight font-semibold rounded-full bg-blue-200 text-blue-800 border border-blue-300"
                                    >
                                        {{ item.jenis_cuti }}
                                    </span>
                                </td>
                                <td class="px-3 py-3.5 align-middle text-sm">
                                    <div class="text-gray-900">
                                        {{ formatDate(item.tanggal_mulai) }}
                                    </div>
                                    <div class="text-xs text-gray-500">
                                        s/d
                                        {{ formatDate(item.tanggal_selesai) }}
                                    </div>
                                </td>
                                <td
                                    class="px-3 py-3.5 align-middle text-sm text-gray-900 whitespace-nowrap"
                                >
                                    {{ getDurasi(item) }} Hari
                                </td>
                                <td class="px-3 py-3.5 align-middle text-sm">
                                    <span
                                        :title="getEffectiveStatusLabel(item)"
                                        class="inline-flex max-w-full items-center px-2.5 py-1 rounded-md text-xs leading-tight font-bold tracking-wide uppercase border cursor-default"
                                        :class="kelasBadgeStatus(item)"
                                    >
                                        {{
                                            getEffectiveStatusLabelSingkatTahap(
                                                item,
                                            )
                                        }}
                                    </span>
                                </td>
                                <td
                                    class="px-3 py-3.5 align-middle text-center text-sm font-medium"
                                >
                                    <div
                                        class="flex flex-nowrap items-center justify-center gap-1.5"
                                    >
                                        <button
                                            type="button"
                                            @click="bukaDetailModal(item)"
                                            title="Lihat Detail Pengajuan"
                                            aria-label="Lihat detail pengajuan"
                                            class="shrink-0 inline-flex items-center justify-center p-1.5 bg-white border border-gray-300 rounded-lg text-gray-500 hover:bg-gray-50 hover:text-blue-600 transition-colors shadow-sm cursor-pointer"
                                        >
                                            <svg
                                                class="w-5 h-5"
                                                fill="none"
                                                stroke="currentColor"
                                                viewBox="0 0 24 24"
                                                aria-hidden="true"
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
                                        <template v-if="!isReadOnly">
                                            <button
                                                type="button"
                                                @click="
                                                    processApproval(
                                                        item.id,
                                                        'approve',
                                                    )
                                                "
                                                :disabled="!canApprove(item)"
                                                :class="[
                                                    'shrink-0 px-2.5 py-1.5 rounded-lg text-xs font-bold transition shadow-sm',
                                                    canApprove(item)
                                                        ? 'bg-emerald-600 hover:bg-emerald-700 text-white cursor-pointer'
                                                        : 'bg-slate-200 text-slate-400 cursor-not-allowed',
                                                ]"
                                            >
                                                SETUJUI
                                            </button>
                                            <button
                                                type="button"
                                                @click="
                                                    processApproval(
                                                        item.id,
                                                        'reject',
                                                    )
                                                "
                                                :disabled="!canApprove(item)"
                                                :class="[
                                                    'shrink-0 px-2.5 py-1.5 rounded-lg text-xs font-bold transition shadow-sm',
                                                    canApprove(item)
                                                        ? 'bg-red-600 hover:bg-red-700 text-white cursor-pointer'
                                                        : 'bg-slate-200 text-slate-400 cursor-not-allowed',
                                                ]"
                                            >
                                                TOLAK
                                            </button>
                                        </template>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="antreanTampil.length === 0">
                                <td
                                    colspan="6"
                                    class="px-4 py-8 text-center text-gray-500"
                                >
                                    Saat ini tidak ada antrean pengajuan cuti
                                    yang memerlukan persetujuan Anda.
                                    <button
                                        v-if="adaFilterAktif"
                                        type="button"
                                        @click="resetFilter"
                                        class="ml-1 text-green-700 font-semibold hover:underline cursor-pointer"
                                    >
                                        Reset filter
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- ================= KARTU (di bawah lg) ================= -->
                <div class="lg:hidden">
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                        <article
                            v-for="item in antreanTampil"
                            :key="item.id"
                            class="flex min-w-0 flex-col rounded-xl border border-gray-200 bg-white p-4 shadow-sm"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <h3
                                        class="break-words text-sm font-semibold text-gray-900"
                                    >
                                        {{ item.pegawai.nama }}
                                    </h3>
                                    <p
                                        class="mt-1 break-words text-xs"
                                        :class="
                                            adaKelompokPegawai(item.pegawai)
                                                ? 'text-gray-500'
                                                : 'italic text-gray-400'
                                        "
                                    >
                                        {{ kelompokPegawai(item.pegawai) }}
                                    </p>
                                </div>
                                <span
                                    class="shrink-0 rounded-md border px-2 py-1 text-xs font-bold"
                                    :class="kelasBadgeStatus(item)"
                                >
                                    {{
                                        getEffectiveStatusLabelSingkatTahap(
                                            item,
                                        )
                                    }}
                                </span>
                            </div>
                            <dl class="mt-3 space-y-2 text-sm">
                                <div class="flex justify-between gap-3">
                                    <dt class="shrink-0 text-gray-500">
                                        Jenis cuti
                                    </dt>
                                    <dd
                                        class="min-w-0 break-words text-right font-medium text-gray-800"
                                    >
                                        {{ item.jenis_cuti }}
                                    </dd>
                                </div>
                                <div class="flex justify-between gap-3">
                                    <dt class="shrink-0 text-gray-500">
                                        Tanggal
                                    </dt>
                                    <dd
                                        class="min-w-0 break-words text-right text-gray-800"
                                    >
                                        {{ formatDate(item.tanggal_mulai) }} s/d
                                        {{ formatDate(item.tanggal_selesai) }}
                                    </dd>
                                </div>
                                <div class="flex justify-between gap-3">
                                    <dt class="shrink-0 text-gray-500">
                                        Durasi
                                    </dt>
                                    <dd class="font-medium text-gray-800">
                                        {{ getDurasi(item) }} hari
                                    </dd>
                                </div>
                            </dl>
                            <div
                                class="mt-auto grid gap-2 pt-4"
                                :class="
                                    isReadOnly ? 'grid-cols-1' : 'grid-cols-3'
                                "
                            >
                                <button
                                    type="button"
                                    @click="bukaDetailModal(item)"
                                    class="min-h-[44px] rounded-lg border border-gray-300 bg-white px-2 py-2 text-sm font-semibold text-gray-700 cursor-pointer"
                                >
                                    Detail
                                </button>
                                <template v-if="!isReadOnly">
                                    <button
                                        type="button"
                                        @click="
                                            processApproval(item.id, 'approve')
                                        "
                                        :disabled="!canApprove(item)"
                                        class="min-h-[44px] rounded-lg bg-emerald-600 px-2 py-2 text-sm font-bold text-white cursor-pointer disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        Setujui
                                    </button>
                                    <button
                                        type="button"
                                        @click="
                                            processApproval(item.id, 'reject')
                                        "
                                        :disabled="!canApprove(item)"
                                        class="min-h-[44px] rounded-lg bg-red-600 px-2 py-2 text-sm font-bold text-white cursor-pointer disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        Tolak
                                    </button>
                                </template>
                            </div>
                        </article>
                    </div>
                    <div
                        v-if="antreanTampil.length === 0"
                        class="rounded-xl border border-gray-200 bg-white px-4 py-8 text-center text-sm text-gray-500"
                    >
                        Saat ini tidak ada antrean pengajuan cuti yang
                        memerlukan persetujuan Anda.
                        <button
                            v-if="adaFilterAktif"
                            type="button"
                            @click="resetFilter"
                            class="ml-1 font-semibold text-green-700 underline cursor-pointer"
                        >
                            Atur ulang filter
                        </button>
                    </div>
                </div>
            </div>

            <!-- PAGINATION -->
            <div
                v-if="antrean.links && antrean.links.length > 3"
                class="mt-6 flex flex-col items-center gap-3 sm:flex-row sm:flex-wrap sm:justify-between"
            >
                <p class="text-center text-sm text-gray-500">
                    Menampilkan {{ antrean.from ?? 0 }}–{{ antrean.to ?? 0 }}
                    dari {{ antrean.total ?? 0 }} data
                </p>
                <div class="flex flex-wrap justify-center gap-1">
                    <button
                        v-for="(link, index) in antrean.links"
                        :key="index"
                        v-html="link.label"
                        :disabled="!link.url"
                        @click="goToPage(link.url)"
                        class="min-h-[40px] px-3 py-1 text-sm rounded-md border"
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

    <!-- Modal Detail -->
    <Teleport to="body">
        <div
            v-if="detailModal.show"
            class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 backdrop-blur-sm p-0 sm:items-center sm:p-4"
            @click.self="closeDetailModal"
        >
            <div
                class="bg-white rounded-t-2xl shadow-xl w-full max-w-lg max-h-[90dvh] flex flex-col overflow-hidden sm:rounded-2xl"
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
                                />
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
                        :class="{
                            'bg-amber-100 text-amber-700': getEffectiveStatus(
                                detailModal.data,
                            )?.includes('menunggu'),
                            'bg-emerald-100 text-emerald-700':
                                getEffectiveStatus(detailModal.data) ===
                                'disetujui',
                            'bg-red-100 text-red-700':
                                getEffectiveStatus(detailModal.data) ===
                                'ditolak',
                            'bg-slate-100 text-slate-600':
                                getEffectiveStatus(detailModal.data) ===
                                    'kedaluwarsa' ||
                                getEffectiveStatus(detailModal.data) ===
                                    'ditangguhkan' ||
                                getEffectiveStatus(detailModal.data)?.includes(
                                    'dibatalkan',
                                ),
                        }"
                    >
                        {{ getStatusBadgeLabelSingkat(detailModal.data) }}
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
                                (Tahun Ini:
                                {{
                                    detailModal.data.pegawai
                                        ?.sisa_hak_tahun_berjalan ?? 0
                                }}
                                hari | Saldo Bawaan:
                                {{
                                    detailModal.data.pegawai
                                        ?.saldo_bawaan_eligible ?? 0
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
                            {{ detailModal.data.no_telp || "-" }}
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
                                Tidak ada lampiran
                            </div>
                        </div>
                    </div>
                    <div v-if="isDitangguhkanDetail" class="pt-1">
                        <div
                            class="rounded-xl p-4 border bg-slate-50 border-slate-200"
                        >
                            <h4
                                class="text-xs font-bold uppercase tracking-wider mb-2 text-slate-500"
                            >
                                Alasan Penangguhan / Pembatalan Atasan
                            </h4>
                            <p
                                v-if="pemrosesDitangguhkan?.jabatanBersih"
                                class="text-sm text-slate-700"
                            >
                                Diproses oleh:
                                <span class="font-semibold text-slate-800">{{
                                    pemrosesDitangguhkan.jabatanBersih
                                }}</span>
                            </p>
                            <p
                                v-if="pemrosesDitangguhkan?.catatan"
                                class="text-sm italic text-slate-600 mt-1"
                            >
                                "{{ pemrosesDitangguhkan.catatan }}"
                            </p>
                            <h5
                                v-if="riwayatDitangguhkan.length"
                                class="text-[10px] font-bold uppercase tracking-wider text-slate-500 mt-3 pt-3 mb-2 border-t border-slate-200"
                            >
                                Riwayat Persetujuan Atasan
                            </h5>
                            <div
                                v-if="riwayatDitangguhkan.length"
                                class="space-y-2"
                            >
                                <div
                                    v-for="level in riwayatDitangguhkan"
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
                                        class="text-xs font-bold"
                                        :class="level.color"
                                        >{{ level.status }}</span
                                    >
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-else-if="isDibatalkanDetail" class="pt-1">
                        <div
                            class="rounded-xl p-4 border bg-slate-50 border-slate-200"
                        >
                            <h4
                                class="text-xs font-bold uppercase tracking-wider mb-2 text-slate-500"
                            >
                                Informasi Pembatalan
                            </h4>
                            <p class="text-sm text-slate-600">
                                Dibatalkan oleh:
                                <span class="font-semibold text-slate-800"
                                    >Pemohon</span
                                >
                            </p>
                            <p class="text-sm italic text-slate-600 mt-1">
                                "Pengajuan cuti telah dibatalkan secara mandiri
                                oleh pemohon sebelum proses persetujuan
                                selesai."
                            </p>
                            <h5
                                v-if="riwayatDibatalkan.length"
                                class="text-[10px] font-bold uppercase tracking-wider text-slate-500 mt-4 mb-2"
                            >
                                Progres Persetujuan Sebelum Dibatalkan
                            </h5>
                            <div
                                v-if="riwayatDibatalkan.length"
                                class="space-y-2"
                            >
                                <div
                                    v-for="level in riwayatDibatalkan"
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
                                        class="text-xs font-bold"
                                        :class="level.color"
                                        >{{ level.status }}</span
                                    >
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-else class="pt-1">
                        <div
                            :class="[
                                'rounded-xl p-4 border',
                                getEffectiveStatus(detailModal.data) ===
                                'disetujui'
                                    ? 'bg-emerald-50 border-emerald-200'
                                    : getEffectiveStatus(
                                            detailModal.data,
                                        )?.includes('menunggu')
                                      ? 'bg-amber-50 border-amber-200'
                                      : getEffectiveStatus(detailModal.data) ===
                                          'ditolak'
                                        ? 'bg-rose-50 border-rose-200'
                                        : 'bg-gray-50 border-gray-200',
                            ]"
                        >
                            <h4
                                :class="[
                                    'text-xs font-bold uppercase tracking-wider mb-3',
                                    getEffectiveStatus(detailModal.data) ===
                                    'disetujui'
                                        ? 'text-emerald-700'
                                        : getEffectiveStatus(
                                                detailModal.data,
                                            )?.includes('menunggu')
                                          ? 'text-orange-600'
                                          : getEffectiveStatus(
                                                  detailModal.data,
                                              ) === 'ditolak'
                                            ? 'text-rose-700'
                                            : 'text-gray-700',
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
                                    v-for="level in filteredApprovalTimeline"
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
                    class="shrink-0 bg-white border-t border-gray-100 px-6 py-4 flex items-center justify-end gap-3"
                >
                    <button
                        type="button"
                        @click="closeDetailModal"
                        class="px-5 py-2 bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 rounded-xl text-sm font-bold transition shadow-sm"
                    >
                        Tutup
                    </button>
                    <template v-if="canApproveDetail">
                        <button
                            type="button"
                            @click="detailModalReject"
                            class="px-5 py-2 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-bold transition shadow-sm"
                        >
                            Tolak
                        </button>
                        <button
                            type="button"
                            @click="detailModalApprove"
                            class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-sm font-bold transition shadow-sm"
                        >
                            Setuju
                        </button>
                    </template>
                </div>
            </div>
        </div>
    </Teleport>

    <!-- Modal Reject -->
    <Teleport to="body">
        <div
            v-if="rejectModal.show"
            class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 backdrop-blur-sm p-0 sm:items-center sm:p-4"
            @click.self="closeRejectModal"
        >
            <div
                class="bg-white rounded-t-2xl sm:rounded-2xl shadow-xl w-full max-w-md max-h-[90dvh] overflow-y-auto"
            >
                <div
                    class="flex justify-between items-center px-6 py-4 border-b border-gray-100 bg-rose-50/50"
                >
                    <div
                        class="flex items-center gap-2 text-rose-700 font-semibold"
                    >
                        <h3>Alasan Penolakan Cuti</h3>
                    </div>
                    <button
                        type="button"
                        @click="closeRejectModal"
                        class="text-gray-400 hover:text-gray-600"
                    >
                        ✕
                    </button>
                </div>
                <div class="p-6 space-y-4">
                    <p class="text-sm text-slate-600">
                        Silakan berikan alasan atau catatan mengapa pengajuan
                        cuti ini ditolak.
                    </p>
                    <div
                        v-if="rejectModal.item"
                        class="p-3.5 bg-slate-50 border border-slate-100 rounded-xl text-left text-xs"
                    >
                        <div class="flex justify-between mb-1">
                            <span class="text-slate-400 font-bold uppercase"
                                >Karyawan</span
                            >
                            <span class="font-semibold text-slate-700">{{
                                rejectModal.item.pegawai?.nama || "-"
                            }}</span>
                        </div>
                        <div class="flex justify-between mb-1">
                            <span class="text-slate-400 font-bold uppercase"
                                >Jenis Cuti</span
                            >
                            <span class="font-semibold text-rose-600">{{
                                rejectModal.item.jenis_cuti || "-"
                            }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-400 font-bold uppercase"
                                >Tanggal</span
                            >
                            <span class="font-semibold text-slate-700">
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
                        <label
                            for="alasan-penolakan"
                            class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-1.5"
                        >
                            Alasan Penolakan
                            <span class="text-rose-500">*</span>
                        </label>
                        <textarea
                            id="alasan-penolakan"
                            ref="rejectTextarea"
                            v-model="rejectModal.alasan"
                            rows="3"
                            placeholder="Contoh: Masih ada tugas proyek mendesak..."
                            :aria-invalid="!!rejectModal.error"
                            @animationend="rejectModal.shake = false"
                            :class="[
                                'w-full rounded-xl text-sm shadow-sm',
                                rejectModal.error
                                    ? 'border-red-400 bg-red-50/40'
                                    : 'border-gray-300',
                                rejectModal.shake ? 'animate-shake' : '',
                            ]"
                        ></textarea>
                        <p
                            v-if="rejectModal.error"
                            class="mt-1.5 text-xs font-medium text-red-600"
                        >
                            {{ rejectModal.error }}
                        </p>
                    </div>
                </div>
                <div
                    class="bg-gray-50 px-6 py-3.5 flex justify-end gap-3 border-t border-gray-100"
                >
                    <button
                        type="button"
                        @click="closeRejectModal"
                        class="px-4 py-2 bg-white border border-gray-300 rounded-xl text-xs font-semibold text-gray-700 uppercase"
                    >
                        Batal
                    </button>
                    <button
                        type="button"
                        @click="submitReject"
                        class="px-4 py-2 bg-red-600 rounded-xl text-xs font-semibold text-white uppercase"
                    >
                        Kirim Penolakan
                    </button>
                </div>
            </div>
        </div>
    </Teleport>

    <!-- Modal Approve -->
    <Teleport to="body">
        <div
            v-if="approveModal.show"
            class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 backdrop-blur-sm p-0 sm:items-center sm:p-4"
            @click.self="closeApproveModal"
        >
            <div
                class="bg-white rounded-t-2xl sm:rounded-2xl shadow-xl w-full max-w-md max-h-[90dvh] overflow-y-auto"
            >
                <div
                    class="flex justify-between items-center px-6 py-4 border-b border-gray-100 bg-emerald-50/50"
                >
                    <div
                        class="flex items-center gap-2 text-emerald-700 font-semibold"
                    >
                        <h3>Setujui Pengajuan Cuti</h3>
                    </div>
                    <button
                        type="button"
                        @click="closeApproveModal"
                        class="text-gray-400 hover:text-gray-600"
                    >
                        ✕
                    </button>
                </div>
                <div class="p-6 space-y-4 text-center">
                    <p class="text-sm text-gray-600">
                        Apakah Anda yakin ingin menyetujui pengajuan cuti ini?
                    </p>
                    <div
                        v-if="approveModal.item"
                        class="p-3.5 bg-slate-50 border border-slate-100 rounded-xl text-left text-xs"
                    >
                        <div class="flex justify-between mb-1">
                            <span class="text-slate-400 font-bold uppercase"
                                >Karyawan</span
                            >
                            <span class="font-semibold text-slate-700">{{
                                approveModal.item.pegawai?.nama || "-"
                            }}</span>
                        </div>
                        <div class="flex justify-between mb-1">
                            <span class="text-slate-400 font-bold uppercase"
                                >Jenis Cuti</span
                            >
                            <span class="font-semibold text-emerald-600">{{
                                approveModal.item.jenis_cuti || "-"
                            }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-400 font-bold uppercase"
                                >Tanggal</span
                            >
                            <span class="font-semibold text-slate-700">
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
                    <p class="text-sm text-slate-500">
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
                            Tahunan karyawan.
                        </template>
                    </p>
                </div>
                <div
                    class="bg-gray-50 px-6 py-3.5 flex justify-end gap-3 border-t border-gray-100"
                >
                    <button
                        type="button"
                        @click="closeApproveModal"
                        class="px-4 py-2 bg-white border border-gray-300 rounded-xl text-xs font-semibold text-gray-700 uppercase"
                    >
                        Batal
                    </button>
                    <button
                        type="button"
                        @click="submitApprove"
                        class="px-4 py-2 bg-emerald-600 rounded-xl text-xs font-semibold text-white uppercase"
                    >
                        Ya, Setujui
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
@keyframes shake {
    0%,
    100% {
        transform: translateX(0);
    }
    20% {
        transform: translateX(-6px);
    }
    40% {
        transform: translateX(6px);
    }
    60% {
        transform: translateX(-4px);
    }
    80% {
        transform: translateX(4px);
    }
}
.animate-shake {
    animation: shake 0.4s ease-in-out;
}
</style>

