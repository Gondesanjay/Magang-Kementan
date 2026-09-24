<script setup>
import MainLayout from "@/Layouts/MainLayout.vue";
import { Head, router, usePage } from "@inertiajs/vue3";
import { ref, watch, computed, nextTick } from "vue";

// ================= PETA KELOMPOK PEGAWAI =================
// Hanya dipertahankan sebagai fallback label (title) dropdown Kelompok.
// Sumber data utama dropdown sekarang berasal dari prop `listKelompok`
// (dikirim dinamis oleh MonitoringCutiController::buildFilterPropsUntukAtasan()),
// bukan lagi hardcode statis. Array ini tetap dipakai untuk mencari label
// yang lebih rapi kalau value-nya cocok, tapi TIDAK lagi menjadi sumber
// v-for utama dropdown Kelompok (lihat template).
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

function debounce(fn, delay) {
    let timeoutId;
    return (...args) => {
        clearTimeout(timeoutId);
        timeoutId = setTimeout(() => fn(...args), delay);
    };
}

// ================= PROPS =================
// `opsi_tim_kerja`   : dikirim controller untuk L2 (isinya = listTimKerja
//                      di dalam kelompoknya sendiri). Dipertahankan nama
//                      prop-nya (bukan diganti listTimKerja) supaya tidak
//                      perlu ubah controller lebih jauh dari yang sudah ada.
// `userTimKerja`     : nama Tim Kerja milik user yang login — dipakai
//                      untuk badge "Menampilkan tim Anda: ..." khusus L1.
// `listKelompok`     : daftar Kelompok Substansi dinamis dari controller,
//                      dipakai dropdown Kelompok untuk L3/L4/HR (readonly).
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

// ================= SKEMA FILTER BERJENJANG (DIPERBAIKI) =================
// Mengikuti pola yang sama dengan Rekap Kuota (RekapKuotaDetailController):
//   Role 2 (L1)        -> TANPA dropdown, cuma badge "Menampilkan tim Anda: ..."
//   Role 3 (L2)        -> dropdown Tim Kerja (dalam kelompoknya sendiri)
//   Role 4 / 6 (L3/L4) -> dropdown Kelompok
//   Role 5 (HR/Monitoring readonly) -> dropdown Kelompok (lintas kelompok, sama seperti L3/L4)
const filterMode = computed(() => {
    if (userRole.value === 2) return "l1";
    if (userRole.value === 3) return "l2";
    if (
        userRole.value === 4 ||
        userRole.value === 6 ||
        userRole.value === 5
    )
        return "l3l4";
    return "none";
});

const showFilterDropdown = computed(
    () => filterMode.value === "l2" || filterMode.value === "l3l4",
);

const showBadgeTimL1 = computed(
    () => filterMode.value === "l1" && !!props.userTimKerja,
);

// Sumber opsi dropdown Kelompok: pakai prop dinamis dari controller kalau
// tersedia, fallback ke DAFTAR_KELOMPOK statis kalau controller belum
// mengirimnya (mis. saat pengembangan awal / belum sempat diupdate).
const opsiKelompok = computed(() =>
    props.listKelompok && props.listKelompok.length > 0
        ? props.listKelompok
        : DAFTAR_KELOMPOK.map((k) => k.value),
);

const labelKelompok = (value) => {
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

const buildParams = () => {
    const params = {
        search: search.value,
        jenis_cuti: jenisCuti.value,
        status: status.value,
    };

    // Hanya kirim parameter sesuai role & mode filter wilayahnya
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

// Sorting & Filtering JS lokal DIHAPUS. Murni pakai urutan dari ORDER BY server.
const antreanTampil = computed(() => {
    return props.antrean?.data ?? [];
});

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

const getStatusLabel = (st) => {
    return statusLabels[st] || st?.replace(/_/g, " ").toUpperCase() || "-";
};

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
};

const getEffectiveStatusLabelSingkatTahap = (item) => {
    const st = getEffectiveStatus(item);
    return (
        statusLabelsSingkat[st] || st?.replace(/_/g, " ").toUpperCase() || "-"
    );
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

const processApproval = (id, action) => {
    if (action === "approve") {
        openApproveModal(id);
    } else if (action === "reject") {
        openRejectModal(id);
    }
};

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
    rejectModal.value.id = id;
    rejectModal.value.item = item;
    rejectModal.value.alasan = "";
    rejectModal.value.error = "";
    rejectModal.value.shake = false;
    rejectModal.value.show = true;
    nextTick(() => rejectTextarea.value?.focus());
};

const closeRejectModal = () => {
    rejectModal.value.show = false;
    rejectModal.value.id = null;
    rejectModal.value.item = null;
    rejectModal.value.alasan = "";
    rejectModal.value.error = "";
    rejectModal.value.shake = false;
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
        {
            action: "reject",
            alasan: rejectModal.value.alasan,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                closeRejectModal();
            },
        },
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
    detailModal.value.data = item;
    detailModal.value.show = true;
};

const closeDetailModal = () => {
    detailModal.value.show = false;
    detailModal.value.data = null;
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

// ================= PERBAIKAN: NAMA L1/L2 DINAMIS + RUTE APPROVAL SESUAI TIM KERJA =================
// levelsDefinition dipertahankan sebagai fallback "peta jabatan" untuk L1-L4.
// KHUSUS L3 & L4, nama sudah tetap (jabatan struktural tunggal se-Biro).
// KHUSUS L1 & L2, nama di sini HANYA fallback generik — nama ASLI diambil
// dinamis lewat getLevelDisplayName() di bawah, dari data `atasan_l1_nama`
// & `atasan_l2_nama` yang dikirim Controller (MonitoringCutiController::index()),
// karena L1/L2 berbeda-beda orang tergantung Tim Kerja/Kelompok Substansi
// pegawai yang mengajukan cuti.
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

// Mengambil nama tampilan untuk satu level approval (1-4) pada satu item
// pengajuan (`data`). Untuk L1 & L2 diutamakan nama ASLI dari backend
// (`atasan_l1_nama` / `atasan_l2_nama`); kalau backend belum mengirim nilai
// itu (mis. data lama / belum di-deploy ulang), jatuh ke teks generik di
// levelsDefinition supaya tidak pernah tampil kosong.
const getLevelDisplayName = (levelKey, data) => {
    if (levelKey === 1) {
        return (
            data?.atasan_l1_nama ||
            levelsDefinition.find((l) => l.key === 1)?.nama
        );
    }
    if (levelKey === 2) {
        return (
            data?.atasan_l2_nama ||
            levelsDefinition.find((l) => l.key === 2)?.nama
        );
    }
    return levelsDefinition.find((l) => l.key === levelKey)?.nama || "-";
};

// getApprovalRoute(pegawai) menerima objek pegawai (bukan cuma role_id).
// Untuk staf biasa dicek dulu apakah dia punya Tim Kerja:
//   - Punya Tim Kerja      -> rute [L1, L3, L4]
//   - TIDAK punya Tim Kerja -> rute [L2, L3, L4]
// (staf tanpa Tim Kerja langsung berada di bawah Ketua Kelompok Substansi,
// tanpa Ketua Tim Kerja di antaranya — status pengajuannya dimulai dari
// 'menunggu_l2', bukan 'menunggu_l1', sesuai alur di
// MonitoringCutiController::process()).
const getApprovalRoute = (pegawai) => {
    const roleId = pegawai?.role_id ?? 1;
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
        default: {
            const timKerja = (pegawai?.tim_kerja ?? "").toString().trim();
            const tanpaTimKerja = timKerja === "" || timKerja === "-";

            if (tanpaTimKerja) {
                // Staf tanpa Tim Kerja: tidak ada L1, langsung L2 -> L3 -> L4.
                return [
                    levelsDefinition[1],
                    levelsDefinition[2],
                    levelsDefinition[3],
                ];
            }

            // Staf dengan Tim Kerja: L1 -> (lompat L2) -> L3 -> L4.
            return [
                levelsDefinition[0],
                levelsDefinition[2],
                levelsDefinition[3],
            ];
        }
    }
};
// ================= END PERBAIKAN =================

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
            if (matchLevel) {
                stoppedLevel = parseInt(matchLevel[1], 10);
            } else {
                stoppedLevel = steps[steps.length - 1].key;
            }
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
        // Nama diambil dinamis (nama asli L1/L2 dari backend, bukan lagi
        // selalu teks generik statis).
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
                if (catatanAtasan) {
                    catatan = catatanAtasan;
                }
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
    };

    return levelMap[st] || st.replace(/_/g, " ").toUpperCase();
};

// approvalTimeline di atas SUDAH otomatis hanya berisi level yang memang
// relevan untuk pegawai tsb (lihat getApprovalRoute), jadi tidak perlu
// filter tambahan lagi di sini — cukup teruskan apa adanya supaya
// L1/L2/L3/L4 yang memang berlaku untuk pegawai itu semuanya tampil (tidak
// lagi selalu dibuang ke hanya L3/L4).
const filteredApprovalTimeline = computed(() => approvalTimeline.value);

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
    // Rute dihitung dari objek pegawai (bukan cuma role_id) agar konsisten
    // dengan approvalTimeline & memperhitungkan kasus tanpa Tim Kerja.
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

// buildRiwayatPersetujuan memakai getApprovalRoute(item.pegawai) supaya
// level yang ditampilkan mengikuti rute approval ASLI pegawai (termasuk
// L2 untuk staf tanpa Tim Kerja), dan nama L1/L2 diambil dinamis lewat
// getLevelDisplayName supaya konsisten dengan approvalTimeline di atas.
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
                        !best || Number(cur.id) > Number(best.id)
                            ? cur
                            : best,
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
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
            <div class="mb-6">
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

                <!-- FILTER TIM KERJA (Khusus L2) -->
                <div
                    v-if="filterMode === 'l2'"
                    class="relative w-full sm:w-[300px] shrink-0"
                >
                    <svg
                        class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"
                        />
                    </svg>
                    <select
                        v-model="timKerja"
                        class="border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-lg pl-9 pr-3 py-2 text-sm text-gray-600 w-full truncate"
                    >
                        <option value="">Semua Tim Kerja</option>
                        <!-- Support Array String atau Array Object {value, label} dari Controller -->
                        <option
                            v-for="t in opsi_tim_kerja"
                            :key="t.value || t"
                            :value="t.value || t"
                        >
                            {{ t.label || t }}
                        </option>
                    </select>
                </div>

                <!-- FILTER KELOMPOK (Khusus L3, L4, Monitoring/HR) -->
                <div
                    v-if="filterMode === 'l3l4'"
                    class="relative w-full sm:w-[600px] shrink-0"
                >
                    <svg
                        class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"
                        />
                    </svg>
                    <select
                        v-model="kelompok"
                        class="border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-lg pl-9 pr-3 py-2 text-sm text-gray-600 w-full truncate"
                    >
                        <option value="">Semua Kelompok</option>
                        <option
                            v-for="k in opsiKelompok"
                            :key="k"
                            :value="k"
                            :title="labelKelompok(k)"
                        >
                            {{ labelKelompok(k) }}
                        </option>
                    </select>
                </div>

                <select
                    v-model="jenisCuti"
                    class="border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-lg text-sm text-gray-600 w-full sm:w-auto shrink-0"
                >
                    <option value="Semua Jenis Cuti">Semua Jenis Cuti</option>
                    <option value="Cuti Tahunan">Cuti Tahunan</option>
                    <option value="Cuti Melahirkan">Cuti Melahirkan</option>
                    <option value="Cuti Besar">Cuti Besar</option>
                    <option value="Cuti Alasan Penting">
                        Cuti Alasan Penting
                    </option>
                </select>

                <select
                    v-model="status"
                    class="border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-lg text-sm text-gray-600 w-full sm:w-auto shrink-0"
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

                <!-- BADGE TIM KERJA (Khusus L1 — tanpa dropdown) -->
                <!-- Ditaruh PALING KANAN (elemen terakhir baris filter).
                     ml-auto mendorongnya menempel ke ujung kanan pada
                     layar sm+ (flex-row); saat susunan jadi flex-col di
                     layar kecil, ml-auto tidak berefek horizontal sehingga
                     tetap tersusun rapi ke bawah. -->
                <div
                    v-if="showBadgeTimL1"
                    class="inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-lg bg-green-50 border border-green-200 text-green-700 text-xs font-semibold shrink-0 w-full sm:w-auto sm:ml-auto"
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
                            d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 100-8 4 4 0 000 8zm6 3c0-2.21-3.582-4-8-4s-8 1.79-8 4"
                        ></path>
                    </svg>
                    Menampilkan tim Anda: {{ userTimKerja }}
                </div>
            </div>

            <!-- Penanda filter yang sedang aktif -->
            <div
                v-if="kelompok || timKerja"
                class="flex items-center gap-2 mb-4 text-xs"
            >
                <span
                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-green-50 text-green-700 border border-green-200 font-semibold"
                >
                    {{ labelFilterAktif }}
                    <button
                        type="button"
                        @click="
                            kelompok = '';
                            timKerja = '';
                        "
                        class="hover:text-green-900 cursor-pointer"
                        title="Hapus filter"
                    >
                        &times;
                    </button>
                </span>
                <span class="text-gray-500"
                    >{{ antreanTampil.length }} pengajuan pada halaman ini</span
                >
            </div>

            <!-- TABEL -->
            <div class="w-full min-w-0">
                <div class="overflow-x-auto rounded-lg border border-gray-200">
                    <table class="min-w-full divide-y divide-gray-200">
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
                                    Tahap
                                </th>
                                <th
                                    scope="col"
                                    class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider whitespace-nowrap sticky right-0 bg-gray-50"
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
                                <td class="px-4 py-4 whitespace-nowrap">
                                    <div
                                        class="text-sm font-medium text-gray-900"
                                    >
                                        {{ item.pegawai.nama }}
                                    </div>
                                    <div class="text-xs text-gray-500">
                                        {{ item.pegawai.departemen }}
                                    </div>
                                </td>

                                <td
                                    class="px-4 py-4 whitespace-nowrap text-sm text-gray-900"
                                >
                                    <span
                                        class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-200 text-blue-800 border border-blue-300"
                                    >
                                        {{ item.jenis_cuti }}
                                    </span>
                                </td>

                                <td
                                    class="px-4 py-4 whitespace-nowrap text-sm text-gray-900"
                                >
                                    {{ formatDate(item.tanggal_mulai) }} s/d
                                    {{ formatDate(item.tanggal_selesai) }}
                                </td>
                                <td
                                    class="px-4 py-4 whitespace-nowrap text-sm text-gray-900"
                                >
                                    {{ getDurasi(item) }} Hari
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm">
                                    <span
                                        :title="getEffectiveStatusLabel(item)"
                                        class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-bold tracking-wide uppercase border cursor-default"
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
                                        {{
                                            getEffectiveStatusLabelSingkatTahap(
                                                item,
                                            )
                                        }}
                                    </span>
                                </td>
                                <td
                                    class="px-4 py-4 whitespace-nowrap text-center text-sm font-medium sticky right-0 bg-white"
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
            </div>

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

    <!-- Modal Detail -->
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
                                        ?.sisa_cuti_tersedia ?? 0
                                }}
                                Hari Total
                            </span>
                            <span
                                class="font-normal text-amber-600/80 text-xs block mt-0.5"
                            >
                                (Tahun Ini:
                                {{
                                    (detailModal.data.pegawai
                                        ?.sisa_cuti_tersedia ?? 0) -
                                    (detailModal.data.pegawai
                                        ?.sisa_tahun_lalu ?? 0)
                                }}
                                hari | Saldo Bawaan:
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

                    <div v-if="isDitangguhkanDetail" class="pt-1">
                        <div
                            class="rounded-xl p-4 border bg-slate-50 border-slate-200"
                        >
                            <h4
                                class="text-xs font-bold uppercase tracking-wider mb-2 flex items-center gap-1.5 text-slate-500"
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
                                class="text-[10px] font-bold uppercase tracking-wider text-slate-500 mt-3 pt-3 mb-2 border-t border-slate-200"
                            >
                                Riwayat Persetujuan Atasan
                            </h5>
                            <div class="space-y-2">
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
                                    >
                                        {{ level.status }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-else-if="isDibatalkanDetail" class="pt-1">
                        <div
                            class="rounded-xl p-4 border bg-slate-50 border-slate-200"
                        >
                            <h4
                                class="text-xs font-bold uppercase tracking-wider mb-2 flex items-center gap-1.5 text-slate-500"
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
                                class="text-[10px] font-bold uppercase tracking-wider text-slate-500 mt-4 mb-2"
                            >
                                Progres Persetujuan Sebelum Dibatalkan
                            </h5>
                            <div class="space-y-2">
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
                                    >
                                        {{ level.status }}
                                    </span>
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
                                    'text-xs font-bold uppercase tracking-wider mb-3 flex items-center gap-1.5',
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

                            <div class="space-y-2">
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
                    <div class="flex items-center gap-2">
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
        </div>
    </Teleport>

    <!-- Modal Reject -->
    <Teleport to="body">
        <div
            v-if="rejectModal.show"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm p-4"
            @click.self="closeRejectModal"
        >
            <div
                class="bg-white rounded-2xl shadow-xl w-full max-w-md overflow-hidden transform transition-all"
            >
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

                <div class="p-6 space-y-4">
                    <p class="text-sm text-slate-600 leading-relaxed">
                        Silakan berikan alasan atau catatan mengapa pengajuan
                        cuti ini ditolak. Alasan ini akan dibaca oleh pegawai
                        yang bersangkutan.
                    </p>

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
                            placeholder="Contoh: Masih ada tugas proyek mendesak yang harus diselesaikan..."
                            :aria-invalid="!!rejectModal.error"
                            aria-describedby="alasan-penolakan-error"
                            @animationend="rejectModal.shake = false"
                            :class="[
                                'w-full rounded-xl text-sm shadow-sm transition-colors',
                                rejectModal.error
                                    ? 'border-red-400 bg-red-50/40 focus:border-red-500 focus:ring-red-500'
                                    : 'border-gray-300 focus:border-rose-500 focus:ring-rose-500',
                                rejectModal.shake ? 'animate-shake' : '',
                            ]"
                        ></textarea>

                        <transition name="fade-error">
                            <p
                                v-if="rejectModal.error"
                                id="alasan-penolakan-error"
                                role="alert"
                                class="mt-1.5 flex items-center gap-1.5 text-xs font-medium text-red-600"
                            >
                                <svg
                                    class="w-4 h-4 shrink-0"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                                    />
                                </svg>
                                {{ rejectModal.error }}
                            </p>
                        </transition>
                    </div>
                </div>

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

    <!-- Modal Approve -->
    <Teleport to="body">
        <div
            v-if="approveModal.show"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm p-4"
            @click.self="closeApproveModal"
        >
            <div
                class="bg-white rounded-2xl shadow-xl w-full max-w-md overflow-hidden transform transition-all"
            >
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

                <div class="p-6 space-y-4 text-center">
                    <p class="text-sm text-gray-600">
                        Apakah Anda yakin ingin menyetujui pengajuan cuti ini?
                    </p>

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

.fade-error-enter-active,
.fade-error-leave-active {
    transition:
        opacity 0.2s ease,
        transform 0.2s ease;
}

.fade-error-enter-from,
.fade-error-leave-to {
    opacity: 0;
    transform: translateY(-4px);
}
</style>