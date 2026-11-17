<script setup>
import { ref, watch, computed, onMounted, onBeforeUnmount } from "vue";
import { router, Head } from "@inertiajs/vue3";
import MainLayout from "@/Layouts/MainLayout.vue";

// Halaman ini adalah versi ATASAN dari "Rekap Kuota Detail" milik HR Admin
// (lihat Pages/Admin/RekapKuotaDetail.vue). Atasan hanya boleh MELIHAT rekap
// saldo/kuota & riwayat cuti bawahannya: tanpa Impor Kuota, tanpa Generate
// Saldo, tanpa Export, dan tanpa Edit Saldo.
//
// TAMPILAN: sengaja RINGKAS.
// - Tabel memuat kolom inti (Hak, Terpakai, Sisa, Saldo Akhir). Kolom
//   "Ditangguhkan" TIDAK ditampilkan lagi supaya tidak membingungkan:
//   hari yang ditangguhkan sudah termasuk dalam Terpakai.
// - Angka Terpakai SAMA dengan halaman Admin:
//   Terpakai (tampil) = cuti disetujui + Tgh tahun aktif.
// - Popup SAMA dengan popup Admin (hanya-baca): profil -> 4 KPI -> rumus
//   Saldo Akhir -> riwayat pengajuan (kotak ringkasan hijau/kuning, chip
//   filter status dengan hitungan, checkbox "Hanya Cuti Tahunan", badge
//   "dihitung di {tahun}: n hari" untuk cuti lintas tahun).
// - Status "Ditangguhkan" (dilakukan atasan, masuk Tgh) dan "Dibatalkan"
//   (dilakukan pemohon, hari dikembalikan) DIPISAH.
const props = defineProps({
    dataPegawai: Object,
    filters: Object,
    userRoleId: Number, // 1=Staff, 2=L1, 3=L2, 4=L3, 6=L4
    userTimKerja: String, // dipakai untuk badge info L1
    listTimKerja: {
        type: Array,
        default: () => [],
    },
    listKelompok: {
        type: Array,
        default: () => [],
    },
    // Filter Tahun (dikirim backend lewat renderRekap())
    tahunTerpilih: Number,
    daftarTahun: {
        type: Array,
        default: () => [],
    },
    // OPSIONAL: KPI ringkasan dari backend (total_pegawai,
    // rata_rata_terpakai, rata_rata_saldo_akhir, akumulasi_penuh). Kalau
    // backend belum mengirim, KPI dihitung dari baris di halaman aktif.
    kpi: {
        type: Object,
        default: null,
    },
});

const search = ref(props.filters?.search || "");

// ================= FILTER TAHUN =================
const currentYear = new Date().getFullYear();

const filterTahun = ref(
    Number(props.filters?.tahun) || props.tahunTerpilih || currentYear,
);

// SATU-SATUNYA sumber tahun untuk semua label di halaman ini.
const tahunAktif = computed(() => Number(filterTahun.value) || currentYear);

const daftarTahunPilihan = computed(() => {
    const daftar = (props.daftarTahun || []).map(Number).filter(Boolean);
    return [...new Set([...daftar, tahunAktif.value])].sort((a, b) => b - a);
});
// ================= END FILTER TAHUN =================

// ================= FILTER BERJENJANG SESUAI LEVEL ATASAN =================
//   L1 (role_id 2)       : TANPA dropdown, badge "Menampilkan tim Anda: ..."
//   L2 (role_id 3)       : dropdown Tim Kerja (dalam kelompoknya sendiri)
//   L3 & L4 (role_id 4/6): dropdown Kelompok/Subbagian (lintas kelompok)
const filterMode = computed(() => {
    if (props.userRoleId === 2) return "l1";
    if (props.userRoleId === 3) return "l2";
    if (props.userRoleId === 4 || props.userRoleId === 6) return "l3l4";
    return "none";
});

const showFilterDropdown = computed(
    () => filterMode.value === "l2" || filterMode.value === "l3l4",
);

const showBadgeTimL1 = computed(
    () => filterMode.value === "l1" && !!props.userTimKerja,
);

const filterQueryParam = computed(() =>
    filterMode.value === "l2" ? "tim_kerja" : "kelompok_substansi",
);

const filterAllLabel = computed(() =>
    filterMode.value === "l2" ? "Semua Tim Kerja" : "Semua Kelompok",
);

// Pegawai tanpa kelompok/tim di database bernilai "-". Nilainya TETAP "-"
// saat dikirim ke backend, tetapi yang ditampilkan ke pengguna adalah
// "Tanpa Kelompok" (atau "Tanpa Tim Kerja" untuk L2).
const NILAI_KOSONG = "-";
const labelTanpa = computed(() =>
    filterMode.value === "l2" ? "Tanpa Tim Kerja" : "Tanpa Kelompok",
);
const labelOpsi = (opsi) =>
    String(opsi ?? "").trim() === NILAI_KOSONG ? labelTanpa.value : opsi;

// Opsi "-" diletakkan paling bawah agar daftar kelompok nyata tampil dulu.
const filterOptions = computed(() => {
    let daftar = [];
    if (filterMode.value === "l2") daftar = props.listTimKerja || [];
    if (filterMode.value === "l3l4") daftar = props.listKelompok || [];
    daftar = daftar.filter((o) => o !== null && o !== undefined && o !== "");
    const biasa = daftar.filter((o) => String(o).trim() !== NILAI_KOSONG);
    const kosong = daftar.filter((o) => String(o).trim() === NILAI_KOSONG);
    return [...biasa, ...kosong];
});

const kelompok = ref(props.filters?.[filterQueryParam.value] || "");
// ================= END FILTER BERJENJANG =================

const daftarPegawai = computed(() => props.dataPegawai?.data ?? []);

// ================= PERHITUNGAN KUOTA =================
// Semua angka DIAMBIL dari backend
// (RekapKuotaDetailController::hitungKuota()). Jangan hitung ulang di sini
// supaya selalu sama dengan Dashboard & halaman Admin. Fallback di bawah
// hanya dipakai kalau backend belum mengirim angka final.
const toNum = (nilai) => {
    const angka = Number(nilai);
    return Number.isFinite(angka) ? angka : 0;
};

// Cuti terpakai dari backend (angka Admin bila ada koreksi, kalau tidak
// angka sistem). TIDAK menyertakan Tgh tahun aktif.
const getTerpakai = (pegawai) => toNum(pegawai?.cuti_terpakai);

// Hak tahun berjalan (kuota awal), bukan sisa + terpakai.
const getSisaTahunIni = (pegawai) =>
    toNum(
        pegawai?.kuota_tahunan ?? pegawai?.hak_tahun_ini ?? pegawai?.hak_cuti,
    );

const getSisaDuaTahunLalu = (pegawai) =>
    toNum(pegawai?.sisa_cuti_dua_tahun_lalu);

const getSisaTahunLalu = (pegawai) => toNum(pegawai?.carry_forward_normal);

const getSaldoBawaanEligible = (pegawai) => {
    if (pegawai?.saldo_bawaan_eligible != null) {
        return toNum(pegawai.saldo_bawaan_eligible);
    }
    // Fallback (aturan sama dengan backend)
    const sisaN2 = getSisaDuaTahunLalu(pegawai);
    const sisaN1 = getSisaTahunLalu(pegawai);
    const kuota = getSisaTahunIni(pegawai);
    const normal = sisaN2 === 12 && sisaN1 === 12 ? 12 : Math.min(6, sisaN1);
    return Math.max(0, Math.min(normal, 24 - kuota));
};

// ================================================================
// Ditangguhkan (Tgh) — dikirim backend dari cuti_ditangguhkans.
//   - Tgh TAHUN AKTIF (N): bagian dari Terpakai N (lihat getTerpakaiTampil).
//   - Tgh TAHUN LALU (N-1): MENAMBAH Total Tersedia (aturan A, sama dengan
//     backend RekapKuotaDetailController::TGH_MASUK_TOTAL).
// ================================================================
const getSisaDitangguhkanTahunIni = (pegawai) =>
    toNum(pegawai?.sisa_ditangguhkan_tahun_ini);

const getSisaDitangguhkanTahunLalu = (pegawai) =>
    toNum(pegawai?.sisa_ditangguhkan_tahun_lalu);

// Terpakai yang DITAMPILKAN = cuti terpakai + Tgh tahun aktif.
// Angka ini dipakai di tabel, kartu HP, KPI modal, dan rumus, supaya sama
// dengan halaman Admin.
const getTerpakaiTampil = (pegawai) =>
    getTerpakai(pegawai) + getSisaDitangguhkanTahunIni(pegawai);

const getTotalTersedia = (pegawai) => {
    if (pegawai?.total_cuti_tersedia != null) {
        return toNum(pegawai.total_cuti_tersedia);
    }
    return (
        getSisaTahunIni(pegawai) +
        getSaldoBawaanEligible(pegawai) +
        getSisaDitangguhkanTahunLalu(pegawai)
    );
};

const getSisaTahunBerjalan = (pegawai) => {
    if (pegawai?.sisa_kuota_tahun_ini != null) {
        return toNum(pegawai.sisa_kuota_tahun_ini);
    }
    return Math.max(0, getSisaTahunIni(pegawai) - getTerpakaiTampil(pegawai));
};

const getSaldoAkhir = (pegawai) => {
    if (pegawai?.saldo_akhir != null) {
        return toNum(pegawai.saldo_akhir);
    }
    return Math.max(0, getTotalTersedia(pegawai) - getTerpakaiTampil(pegawai));
};

// Koreksi Cuti Terpakai oleh Admin HR (angka FINAL, bukan +/-).
// null = ikut angka sistem. 0 adalah koreksi yang SAH, jadi selalu
// bedakan lewat `=== null`, jangan `!k`.
const getKoreksiSaldo = (pegawai) => {
    const k = pegawai?.koreksi_saldo;
    return k === null || k === undefined ? null : Math.max(0, Number(k) || 0);
};
const adaKoreksi = (pegawai) => getKoreksiSaldo(pegawai) !== null;
const getTerpakaiSistem = (pegawai) =>
    toNum(pegawai?.cuti_terpakai_sistem ?? pegawai?.cuti_terpakai_murni);

// Angka sistem (tanpa koreksi Admin) dalam bentuk TAMPIL: sistem + Tgh tahun ini.
const getTerpakaiSistemTampil = (pegawai) =>
    getTerpakaiSistem(pegawai) + getSisaDitangguhkanTahunIni(pegawai);

const adaItemDikoreksi = computed(() => daftarPegawai.value.some(adaKoreksi));
// ================= END PERHITUNGAN KUOTA =================

// ================= KPI CARDS =================
// Pakai prop 'kpi' dari backend bila ada (seluruh data hasil filter).
// Kalau tidak ada, dihitung dari baris di halaman aktif.
// CATATAN: 'rata_rata_terpakai' dari backend dihitung dari 'cuti_terpakai'
// (tanpa Tgh), jadi bisa berbeda dari kolom Terpakai. Perbaikan yang bersih
// ada di backend (hitungKpi()): tambahkan 'potongan_tgh_tahun_ini'.
const kpiDariBackend = computed(() => !!props.kpi);

const bulat1 = (n) => Math.round(n * 10) / 10;

const kpiTotalPegawai = computed(
    () =>
        props.kpi?.total_pegawai ??
        props.dataPegawai?.total ??
        daftarPegawai.value.length,
);

const kpiRataRataTerpakai = computed(() => {
    if (props.kpi?.rata_rata_terpakai != null)
        return props.kpi.rata_rata_terpakai;
    const d = daftarPegawai.value;
    if (!d.length) return 0;
    return bulat1(d.reduce((s, p) => s + getTerpakaiTampil(p), 0) / d.length);
});

const kpiRataRataSaldoAkhir = computed(() => {
    if (props.kpi?.rata_rata_saldo_akhir != null)
        return props.kpi.rata_rata_saldo_akhir;
    const d = daftarPegawai.value;
    if (!d.length) return 0;
    return bulat1(d.reduce((s, p) => s + getSaldoAkhir(p), 0) / d.length);
});

const kpiAkumulasiPenuh = computed(() => {
    if (props.kpi?.akumulasi_penuh != null) return props.kpi.akumulasi_penuh;
    return daftarPegawai.value.filter(
        (p) => getSisaDuaTahunLalu(p) === 12 && getSisaTahunLalu(p) === 12,
    ).length;
});

// Definisi kartu KPI (dirender dengan v-for supaya markup tidak berulang)
const kpiCards = computed(() => [
    {
        key: "total",
        label: "Total Pegawai",
        nilai: kpiTotalPegawai.value,
        satuan: "",
        warna: "text-slate-800",
    },
    {
        key: "terpakai",
        label: "Rata² Terpakai",
        nilai: kpiRataRataTerpakai.value,
        satuan: "hari",
        warna: "text-rose-500",
    },
    {
        key: "saldo",
        label: "Rata² Saldo Akhir",
        nilai: kpiRataRataSaldoAkhir.value,
        satuan: "hari",
        warna: "text-emerald-500",
    },
    {
        key: "penuh",
        label: "Akumulasi Penuh (12/12)",
        nilai: kpiAkumulasiPenuh.value,
        satuan: "orang",
        warna: "text-blue-600",
    },
]);
// ================= END KPI CARDS =================

// ================= HELPER DURASI & KETERANGAN =================
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

const formatKeteranganRapi = (teksAsli) => {
    if (!teksAsli || teksAsli === "-") return "-";

    const text = teksAsli
        .replace(/\s*[—–-]\s*Pengajuan Cuti Ulang\b/gi, "")
        .trim();
    if (!text) return "-";

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
// ================= END HELPER DURASI & KETERANGAN =================

// ================= MODAL DETAIL =================
const showModal = ref(false);
const selectedPegawai = ref(null);
const expandedRiwayatId = ref(null);

// ================================================================
// FILTER RIWAYAT DI POPUP (SAMA dengan popup Admin)
//   - Chip status (default: Disetujui, dasar hitungan Terpakai)
//   - "Hanya Cuti Tahunan" (default aktif; jenis lain tidak mengurangi kuota)
//   - Ringkasan total hari Cuti Tahunan disetujui di tahun aktif vs angka
//     sistem. Hari lintas tahun memakai 'hari_di_tahun_ini' dari backend.
// 'Ditangguhkan' (dibatalkan_ditangguhkan) dan 'Dibatalkan'
// (dibatalkan_reguler) adalah DUA chip terpisah. Key chip = nilai status
// asli, jadi cocokStatusRiwayat cukup membandingkan langsung.
// ================================================================
const filterStatusRiwayat = ref("disetujui");
const hanyaCutiTahunan = ref(true);

const OPSI_STATUS_RIWAYAT = [
    { key: "semua", label: "Semua" },
    { key: "disetujui", label: "Disetujui" },
    { key: "menunggu", label: "Menunggu" },
    { key: "ditolak", label: "Ditolak" },
    { key: "dibatalkan_ditangguhkan", label: "Ditangguhkan" },
    { key: "dibatalkan_reguler", label: "Dibatalkan" },
];

const cocokStatusRiwayat = (riwayat, key) => {
    if (key === "semua") return true;
    if (key === "menunggu") return String(riwayat.status).includes("menunggu");
    return riwayat.status === key;
};

// Riwayat setelah filter jenis cuti (dasar untuk hitungan jumlah per chip).
const riwayatSesuaiJenis = computed(() => {
    const semua = selectedPegawai.value?.pengajuan_cuti || [];
    return hanyaCutiTahunan.value
        ? semua.filter((r) => r.jenis_cuti === "Cuti Tahunan")
        : semua;
});

const jumlahPerStatus = computed(() => {
    const hasil = {};
    OPSI_STATUS_RIWAYAT.forEach((o) => {
        hasil[o.key] = riwayatSesuaiJenis.value.filter((r) =>
            cocokStatusRiwayat(r, o.key),
        ).length;
    });
    return hasil;
});

const riwayatTersaring = computed(() =>
    riwayatSesuaiJenis.value.filter((r) =>
        cocokStatusRiwayat(r, filterStatusRiwayat.value),
    ),
);

// Total hari Cuti Tahunan disetujui yang jatuh di tahun aktif (hitungan
// manual dari riwayat), dibandingkan dengan angka sistem dari backend.
// (Angka sistem = cuti disetujui saja, belum termasuk Tgh.)
const ringkasanDisetujui = computed(() => {
    const semua = selectedPegawai.value?.pengajuan_cuti || [];
    const disetujui = semua.filter(
        (r) => r.jenis_cuti === "Cuti Tahunan" && r.status === "disetujui",
    );
    const total = disetujui.reduce(
        (jumlah, r) => jumlah + (Number(r.hari_di_tahun_ini) || 0),
        0,
    );
    const sistem = selectedPegawai.value
        ? getTerpakaiSistem(selectedPegawai.value)
        : 0;

    return { jumlahPengajuan: disetujui.length, total, sistem };
});

// Cuti lintas tahun: kapsul kecil "dihitung di {tahun}: n hari" hanya muncul
// kalau hari yang dihitung berbeda dari durasi penuh pengajuan.
const hariDihitungBeda = (riwayat) => {
    const hitung = Number(riwayat?.hari_di_tahun_ini);
    return Number.isFinite(hitung) && hitung !== Number(getDurasi(riwayat));
};

// Kelas warna kartu & badge per status (satu sumber supaya tidak berulang).
// Ditangguhkan = oranye, Dibatalkan = abu-abu.
const kelasGarisStatus = (status) => {
    if (status === "disetujui")
        return "border-l-4 border-l-emerald-500 hover:bg-emerald-100";
    if (String(status).includes("menunggu"))
        return "border-l-4 border-l-amber-500 hover:bg-amber-100";
    if (status === "ditolak")
        return "border-l-4 border-l-rose-500 hover:bg-rose-100";
    if (status === "dibatalkan_ditangguhkan")
        return "border-l-4 border-l-orange-400 hover:bg-orange-100";
    if (status === "dibatalkan_reguler")
        return "border-l-4 border-l-gray-400 hover:bg-gray-200";
    return "border-l-4 border-l-gray-300 hover:bg-gray-100";
};

const kelasBadgeStatus = (status) => {
    if (status === "disetujui")
        return "bg-emerald-50 text-emerald-700 border border-emerald-200";
    if (String(status).includes("menunggu"))
        return "bg-amber-50 text-amber-700 border border-amber-200";
    if (status === "ditolak")
        return "bg-rose-50 text-rose-700 border border-rose-200";
    if (status === "dibatalkan_ditangguhkan")
        return "bg-orange-50 text-orange-700 border border-orange-200";
    return "bg-gray-100 text-gray-700 border border-gray-200";
};

const openDetailModal = (pegawai) => {
    selectedPegawai.value = pegawai;
    expandedRiwayatId.value = null;
    filterStatusRiwayat.value = "disetujui";
    hanyaCutiTahunan.value = true;
    showModal.value = true;
};

const closeModal = () => {
    showModal.value = false;
    selectedPegawai.value = null;
    expandedRiwayatId.value = null;
};

const toggleExpand = (id) => {
    expandedRiwayatId.value = expandedRiwayatId.value === id ? null : id;
};

// Geser ke bawah pada handle/header (HP) untuk menutup bottom sheet.
let sheetStartY = null;
const mulaiGeserSheet = (event) => {
    sheetStartY = event.touches?.[0]?.clientY ?? null;
};
const selesaiGeserSheet = (event) => {
    if (sheetStartY === null) return;
    const endY = event.changedTouches?.[0]?.clientY ?? sheetStartY;
    if (endY - sheetStartY > 80) closeModal();
    sheetStartY = null;
};

// Kunci scroll halaman di belakang saat popup terbuka.
watch(showModal, (terbuka) => {
    document.body.style.overflow = terbuka ? "hidden" : "";
});

// Label status generik — nama pejabat tampil di approval_chain (dari backend),
// bukan hardcode di sini.
const statusLabels = {
    menunggu_l1: "Menunggu Ketua Tim Kerja (L1)",
    menunggu_l2: "Menunggu Ketua Kelompok Substansi (L2)",
    menunggu_l3: "Menunggu Kasubag TU (L3)",
    menunggu_l4: "Menunggu Kepala Biro Perencanaan (L4)",
    disetujui: "Disetujui",
    ditolak: "Ditolak",
    dibatalkan_ditangguhkan: "Ditangguhkan",
    dibatalkan_reguler: "Dibatalkan",
    kedaluwarsa: "Kedaluwarsa",
};

const getStatusLabel = (st) => {
    return statusLabels[st] || st?.replace(/_/g, " ").toUpperCase();
};

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

// ================= APPROVAL CHAIN (MURNI DARI BACKEND) =================
const HIDE_BELUM_GILIRAN = false;

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

    const kelompokPegawai = (pegawai?.kelompok_substansi || "")
        .toLowerCase()
        .trim();
    const timKerja = (pegawai?.tim_kerja || "").trim();

    const isSubbagianTataUsaha =
        kelompokPegawai.includes("subbagian tata usaha") ||
        kelompokPegawai.includes("tata usaha");

    const tidakPunyaTimKerja = !timKerja || timKerja === "-" || timKerja === "";

    // Sembunyikan L1 jika Subbagian Tata Usaha ATAU tidak punya tim kerja
    if (isSubbagianTataUsaha || tidakPunyaTimKerja) {
        chain = chain.filter((step) => Number(step?.level) !== 1);
    }

    if (!HIDE_BELUM_GILIRAN) return chain;
    return chain.filter((step) => step?.status !== "belum_giliran");
};
// ================= END APPROVAL CHAIN =================
// ================= END MODAL DETAIL =================

function debounce(fn, delay = 300) {
    let timeoutId;
    return (...args) => {
        clearTimeout(timeoutId);
        timeoutId = setTimeout(() => fn(...args), delay);
    };
}

// Ikut mengirim 'tahun' ke backend.
const buildParams = () => {
    const params = { tahun: tahunAktif.value };
    if (search.value) params.search = search.value;
    if (kelompok.value) params[filterQueryParam.value] = kelompok.value;
    return params;
};

const terapkanFilter = debounce(() => {
    router.get(route("atasan.kuota"), buildParams(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}, 300);

// Ikut memantau filterTahun.
watch([search, kelompok, filterTahun], terapkanFilter);

const goToPage = (url) => {
    if (!url) return;
    router.get(url, buildParams(), {
        preserveState: true,
        preserveScroll: true,
    });
};

// ================================================================
// TOOLBAR SEARCH + FILTER
// - Dropdown KUSTOM (Tim Kerja/Kelompok & Tahun) menggantikan <select>
//   bawaan browser: selalu terbuka ke bawah, tinggi dibatasi (bisa di-scroll),
//   teks panjang membungkus.
// - Hanya satu dropdown terbuka sekaligus; menutup saat klik di luar / Esc.
// - Chip "Filter aktif" + tombol "Hapus semua" untuk search & kelompok.
// ================================================================
const toolbarRef = ref(null);
const dropdownTerbuka = ref(null); // 'kelompok' | 'tahun' | null

const toggleDropdown = (nama) => {
    dropdownTerbuka.value = dropdownTerbuka.value === nama ? null : nama;
};

const pilihKelompok = (nilai) => {
    kelompok.value = nilai;
    dropdownTerbuka.value = null;
};

const pilihTahun = (nilai) => {
    filterTahun.value = Number(nilai);
    dropdownTerbuka.value = null;
};

const labelKelompok = computed(() =>
    kelompok.value ? labelOpsi(kelompok.value) : filterAllLabel.value,
);

const tutupDropdownSaatKlikLuar = (event) => {
    if (toolbarRef.value && !toolbarRef.value.contains(event.target)) {
        dropdownTerbuka.value = null;
    }
};

// Esc: tutup popup bila terbuka, selain itu tutup dropdown filter.
const tanganiTombolEsc = (event) => {
    if (event.key !== "Escape") return;
    if (showModal.value) closeModal();
    else dropdownTerbuka.value = null;
};

onMounted(() => {
    document.addEventListener("click", tutupDropdownSaatKlikLuar);
    window.addEventListener("keydown", tanganiTombolEsc);
});
onBeforeUnmount(() => {
    document.removeEventListener("click", tutupDropdownSaatKlikLuar);
    window.removeEventListener("keydown", tanganiTombolEsc);
    document.body.style.overflow = "";
});

const isFilterAktif = computed(
    () =>
        Boolean(search.value) ||
        (showFilterDropdown.value && Boolean(kelompok.value)),
);

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
    if (kelompok.value && showFilterDropdown.value) {
        chips.push({
            key: "kelompok",
            label: labelOpsi(kelompok.value),
            clear: () => {
                kelompok.value = "";
            },
        });
    }
    return chips;
});

// Reset search & kelompok (tahun dibiarkan). Pengambilan data ulang
// dilakukan otomatis oleh watch([search, kelompok, filterTahun]).
const resetFilters = () => {
    search.value = "";
    kelompok.value = "";
};
</script>

<template>
    <Head title="Rekap Kuota Cuti Pegawai" />

    <MainLayout>
        <div class="relative w-full h-full">
            <div class="p-3 sm:p-6 bg-white rounded-xl shadow-sm">
                <!-- Baris judul -->
                <div class="mb-3 sm:mb-4">
                    <h2 class="text-base sm:text-lg font-bold text-gray-800">
                        Rincian Kuota & Riwayat Pegawai
                    </h2>
                    <p class="text-xs sm:text-sm text-gray-500 mt-0.5">
                        Lihat sisa, saldo, dan riwayat pemakaian kuota cuti
                        tahunan pegawai Anda.
                    </p>
                    <div
                        v-if="showBadgeTimL1"
                        class="inline-flex items-center gap-1.5 mt-2 px-2.5 py-1 rounded-full bg-green-50 border border-green-200 text-green-700 text-xs font-semibold"
                    >
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 100-8 4 4 0 000 8zm6 3c0-2.21-3.582-4-8-4s-8 1.79-8 4"></path>
                        </svg>
                        Menampilkan tim Anda: {{ userTimKerja }}
                    </div>
                </div>

                <!-- KPI Cards: kompak. HP = 2x2 rapat, desktop = 4 kolom -->
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-2 sm:gap-3 mb-2">
                    <div
                        v-for="kartu in kpiCards"
                        :key="kartu.key"
                        class="bg-white border border-slate-200 rounded-lg px-3 py-2 sm:py-2.5 shadow-sm min-w-0"
                    >
                        <h4
                            class="text-slate-500 text-[9px] sm:text-[10px] font-bold uppercase tracking-wide leading-tight truncate"
                        >
                            {{ kartu.label }}
                        </h4>
                        <div class="flex items-baseline gap-1 mt-0.5">
                            <span
                                class="text-lg sm:text-xl font-extrabold leading-none"
                                :class="kartu.warna"
                                >{{ kartu.nilai }}</span
                            >
                            <span
                                v-if="kartu.satuan"
                                class="text-[11px] font-medium text-slate-400"
                                >{{ kartu.satuan }}</span
                            >
                        </div>
                    </div>
                </div>
                <p
                    v-if="!kpiDariBackend"
                    class="mb-3 text-[10px] sm:text-[11px] text-slate-400"
                >
                    Rata-rata dan akumulasi dihitung dari pegawai pada halaman
                    ini.
                </p>
                <div v-else class="mb-3"></div>
                <!-- ================= END KPI CARDS ================= -->

                <!-- ===================== TOOLBAR: SEARCH + FILTER =====================
                     HP : search penuh di atas; di bawahnya Kelompok (3/5, lebih lebar
                          karena teksnya panjang) dan Tahun (2/5) berdampingan.
                     Desktop: satu baris. -->
                <div
                    ref="toolbarRef"
                    @keydown.esc="dropdownTerbuka = null"
                    class="flex flex-col gap-2 mb-3 lg:flex-row lg:items-center"
                >
                    <!-- Search -->
                    <div class="relative w-full lg:flex-1 lg:min-w-[200px]">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                        </div>
                        <input
                            id="cari-pegawai-kuota"
                            name="cari_pegawai"
                            v-model="search"
                            type="text"
                            placeholder="Cari nama / NIP..."
                            aria-label="Cari nama atau NIP pegawai"
                            class="w-full min-h-[40px] pl-9 pr-3 py-1.5 text-base sm:text-sm border border-gray-300 focus:border-emerald-500 focus:ring-emerald-500 rounded-lg shadow-sm bg-white"
                        />
                    </div>

                    <div class="grid grid-cols-5 gap-2 lg:flex lg:flex-none lg:items-center">
                        <!-- Filter Tim Kerja (L2) / Kelompok (L3 & L4) : KIRI -->
                        <div
                            v-if="showFilterDropdown"
                            class="relative col-span-3 min-w-0 lg:w-64"
                        >
                            <div class="absolute inset-y-0 left-0 pl-2.5 sm:pl-3 flex items-center pointer-events-none">
                                <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
                                </svg>
                            </div>

                            <button
                                id="filter-kelompok-kuota"
                                type="button"
                                aria-label="Filter kelompok"
                                aria-haspopup="listbox"
                                :aria-expanded="dropdownTerbuka === 'kelompok'"
                                @click="toggleDropdown('kelompok')"
                                class="w-full min-h-[40px] text-left text-[13px] sm:text-sm border border-gray-300 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 focus:outline-none rounded-lg shadow-sm text-gray-600 py-1.5 pl-8 sm:pl-9 pr-6 sm:pr-7 bg-white"
                            >
                                <span class="block truncate">{{ labelKelompok }}</span>
                            </button>

                            <span class="absolute inset-y-0 right-0 flex items-center pr-2 pointer-events-none">
                                <svg
                                    class="w-3.5 h-3.5 text-gray-500 transition-transform"
                                    :class="{ 'rotate-180': dropdownTerbuka === 'kelompok' }"
                                    fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                >
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                </svg>
                            </span>

                            <!-- Daftar: di HP selebar satu baris filter (±166% dari
                                 kotaknya); di desktop menyesuaikan isi (maks. 28rem). -->
                            <ul
                                v-if="dropdownTerbuka === 'kelompok'"
                                role="listbox"
                                class="absolute left-0 top-full z-30 mt-1 max-h-60 w-[166%] lg:w-max lg:min-w-full lg:max-w-md overflow-y-auto rounded-lg border border-gray-200 bg-white py-1 shadow-lg"
                            >
                                <li>
                                    <button
                                        type="button"
                                        role="option"
                                        :aria-selected="!kelompok"
                                        @click="pilihKelompok('')"
                                        :class="
                                            !kelompok
                                                ? 'bg-emerald-50 text-emerald-700 font-semibold'
                                                : 'text-gray-700 hover:bg-gray-50'
                                        "
                                        class="block w-full break-words px-3 py-2 text-left text-sm"
                                    >
                                        {{ filterAllLabel }}
                                    </button>
                                </li>
                                <li v-for="opsi in filterOptions" :key="opsi">
                                    <button
                                        type="button"
                                        role="option"
                                        :aria-selected="kelompok === opsi"
                                        @click="pilihKelompok(opsi)"
                                        :class="
                                            kelompok === opsi
                                                ? 'bg-emerald-50 text-emerald-700 font-semibold'
                                                : 'text-gray-700 hover:bg-gray-50'
                                        "
                                        class="block w-full break-words px-3 py-2 text-left text-sm"
                                    >
                                        {{ labelOpsi(opsi) }}
                                    </button>
                                </li>
                            </ul>
                        </div>

                        <!-- Filter Tahun : KANAN. Default = tahun berjalan.
                             Tanpa dropdown kelompok (L1), tahun melebar penuh di HP. -->
                        <div
                            class="relative min-w-0 lg:w-36"
                            :class="showFilterDropdown ? 'col-span-2' : 'col-span-5'"
                        >
                            <div class="absolute inset-y-0 left-0 pl-2.5 sm:pl-3 flex items-center pointer-events-none">
                                <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                </svg>
                            </div>

                            <button
                                id="filter-tahun-kuota"
                                type="button"
                                aria-label="Filter tahun"
                                aria-haspopup="listbox"
                                :aria-expanded="dropdownTerbuka === 'tahun'"
                                @click="toggleDropdown('tahun')"
                                class="w-full min-h-[40px] text-left text-[13px] sm:text-sm border border-gray-300 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 focus:outline-none rounded-lg shadow-sm text-gray-600 py-1.5 pl-8 sm:pl-9 pr-6 sm:pr-7 bg-white"
                            >
                                <span class="block truncate">Tahun {{ tahunAktif }}</span>
                            </button>

                            <span class="absolute inset-y-0 right-0 flex items-center pr-2 pointer-events-none">
                                <svg
                                    class="w-3.5 h-3.5 text-gray-500 transition-transform"
                                    :class="{ 'rotate-180': dropdownTerbuka === 'tahun' }"
                                    fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                >
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                </svg>
                            </span>

                            <ul
                                v-if="dropdownTerbuka === 'tahun'"
                                role="listbox"
                                class="absolute left-0 right-0 top-full z-30 mt-1 max-h-60 overflow-y-auto rounded-lg border border-gray-200 bg-white py-1 shadow-lg"
                            >
                                <li v-for="tahun in daftarTahunPilihan" :key="tahun">
                                    <button
                                        type="button"
                                        role="option"
                                        :aria-selected="tahunAktif === tahun"
                                        @click="pilihTahun(tahun)"
                                        :class="
                                            tahunAktif === tahun
                                                ? 'bg-emerald-50 text-emerald-700 font-semibold'
                                                : 'text-gray-700 hover:bg-gray-50'
                                        "
                                        class="block w-full px-3 py-2 text-left text-sm"
                                    >
                                        Tahun {{ tahun }}
                                    </button>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- ===================== CHIP FILTER AKTIF ===================== -->
                <div
                    v-if="activeFilterChips.length > 0"
                    class="flex flex-wrap items-center gap-2 mb-3 text-xs text-gray-500"
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
                        @click="resetFilters"
                        class="text-gray-400 underline hover:text-gray-600"
                    >
                        Hapus semua
                    </button>
                </div>

                <!--
                  TABEL (desktop/tablet): RINGKAS, satu baris per pegawai.
                  Kolom: No | Nama | Hak | Terpakai | Sisa | Saldo Akhir | Aksi.
                  Terpakai sudah termasuk hari yang ditangguhkan (sama dengan
                  halaman Admin). Di layar kecil (< md) ditampilkan sebagai
                  kartu padat.
                -->
                <div class="hidden md:block rounded-lg border border-slate-200 bg-white overflow-hidden">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr
                                class="bg-slate-50 border-b border-slate-200 text-[10px] leading-tight text-slate-500 uppercase tracking-wide font-bold align-middle"
                            >
                                <th class="w-12 px-2 py-2.5 text-center">No</th>
                                <th class="px-3 py-2.5">Nama Pegawai</th>
                                <th class="px-2 py-2.5 text-center">Hak {{ tahunAktif }}</th>
                                <th class="px-2 py-2.5 text-center">Terpakai {{ tahunAktif }}</th>
                                <th class="px-2 py-2.5 text-center">Sisa {{ tahunAktif }}</th>
                                <th class="px-2 py-2.5 text-center text-slate-800">Saldo Akhir {{ tahunAktif }}</th>
                                <th class="px-2 py-2.5 text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody v-if="daftarPegawai.length > 0" class="text-sm align-middle">
                            <tr
                                v-for="(pegawai, index) in daftarPegawai"
                                :key="pegawai.id"
                                class="group border-b border-slate-100 hover:bg-violet-50 hover:shadow-[inset_4px_0_0_0_theme(colors.violet.400)] transition-all duration-150"
                            >
                                <td class="w-12 px-2 py-2.5 text-center text-sm font-semibold text-slate-700">
                                    {{ (dataPegawai.from ?? 1) + index }}
                                </td>

                                <td class="px-3 py-2.5">
                                    <div class="font-bold text-slate-800 text-sm">
                                        {{ pegawai.nama }}
                                    </div>
                                    <div class="text-[11px] text-slate-500 mt-0.5 line-clamp-1">
                                        {{
                                            pegawai.jabatan ||
                                            pegawai.kelompok_substansi ||
                                            pegawai.departemen ||
                                            "Staf"
                                        }}
                                    </div>
                                </td>

                                <!-- Hak tahun berjalan (Biru) -->
                                <td class="px-2 py-2.5 text-center">
                                    <span class="inline-block min-w-[32px] px-2 py-1 bg-blue-100 text-blue-800 rounded-md font-bold text-xs border border-blue-300/80">
                                        {{ getSisaTahunIni(pegawai) }}
                                    </span>
                                </td>

                                <!-- Terpakai (Merah) = cuti disetujui + Tgh tahun
                                     aktif. Tanda * = dikoreksi Admin HR -->
                                <td class="px-2 py-2.5 text-center">
                                    <span
                                        class="inline-block min-w-[32px] px-2 py-1 bg-rose-100 text-rose-800 rounded-md font-bold text-xs border border-rose-300/80"
                                        :title="
                                            adaKoreksi(pegawai)
                                                ? `Dikoreksi Admin HR (sistem mencatat ${getTerpakaiSistemTampil(pegawai)} hari)`
                                                : ''
                                        "
                                    >
                                        {{ getTerpakaiTampil(pegawai)
                                        }}<sup v-if="adaKoreksi(pegawai)" class="text-amber-600 font-black">*</sup>
                                    </span>
                                </td>

                                <!-- Sisa tahun berjalan (Biru muda) -->
                                <td class="px-2 py-2.5 text-center">
                                    <span class="inline-block min-w-[32px] px-2 py-1 bg-blue-50 text-blue-800 rounded-md font-bold text-xs border border-blue-200">
                                        {{ getSisaTahunBerjalan(pegawai) }}
                                    </span>
                                </td>

                                <!-- Saldo Akhir (gelap) -->
                                <td class="px-2 py-2.5 text-center">
                                    <span class="inline-block min-w-[36px] px-2.5 py-1 bg-slate-800 text-white rounded-md font-extrabold text-xs shadow-sm border border-slate-900">
                                        {{ getSaldoAkhir(pegawai) }}
                                    </span>
                                </td>

                                <!-- Aksi -->
                                <td class="px-2 py-2.5 text-center">
                                    <button
                                        @click="openDetailModal(pegawai)"
                                        type="button"
                                        class="inline-flex items-center gap-1.5 px-3.5 py-1 border border-gray-300 rounded-full text-xs font-medium text-gray-700 bg-white hover:bg-gray-50 hover:text-gray-900 transition shadow-sm cursor-pointer whitespace-nowrap"
                                        title="Lihat Rincian & Riwayat Cuti"
                                    >
                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                        </svg>
                                        Detail
                                    </button>
                                </td>
                            </tr>
                        </tbody>

                        <!-- State kosong (dengan tombol reset filter) -->
                        <tbody v-else class="bg-white">
                            <tr>
                                <td colspan="7" class="px-6 py-14">
                                    <div class="flex flex-col items-center justify-center text-center gap-3">
                                        <div class="w-14 h-14 rounded-2xl bg-slate-100 flex items-center justify-center">
                                            <svg class="w-7 h-7 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                            </svg>
                                        </div>
                                        <p class="text-sm font-semibold text-gray-600 max-w-sm">
                                            Tidak ada data pegawai ditemukan
                                            untuk tahun {{ tahunAktif }}.
                                        </p>
                                        <button
                                            v-if="isFilterAktif"
                                            type="button"
                                            @click="resetFilters"
                                            class="mt-1 inline-flex items-center gap-1.5 px-4 py-2 bg-white border border-gray-300 rounded-full text-xs font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-800 transition-colors"
                                        >
                                            Reset Filter
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- TAMPILAN KARTU (HP): padat. Nama + 4 angka dalam SATU baris.
                     Seluruh kartu bisa diketuk untuk membuka rincian. -->
                <div class="space-y-2 md:hidden">
                    <article
                        v-for="pegawai in daftarPegawai"
                        :key="pegawai.id"
                        role="button"
                        tabindex="0"
                        @click="openDetailModal(pegawai)"
                        @keydown.enter="openDetailModal(pegawai)"
                        class="rounded-lg border border-slate-200 bg-white p-2.5 shadow-sm active:bg-slate-50 cursor-pointer"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <div class="min-w-0">
                                <h3 class="text-sm font-bold text-slate-800 leading-snug line-clamp-1">
                                    {{ pegawai.nama }}
                                </h3>
                                <p class="text-[11px] text-slate-500 leading-snug line-clamp-1">
                                    {{
                                        pegawai.jabatan ||
                                        pegawai.kelompok_substansi ||
                                        pegawai.departemen ||
                                        "Staf"
                                    }}
                                </p>
                            </div>
                            <span class="shrink-0 inline-flex items-center gap-0.5 text-[11px] font-semibold text-emerald-700">
                                Detail
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                </svg>
                            </span>
                        </div>

                        <dl class="mt-2 grid grid-cols-4 gap-1 text-center">
                            <div class="rounded-md bg-slate-50 py-1">
                                <dt class="text-[9px] font-semibold uppercase text-slate-500">Hak</dt>
                                <dd class="text-sm font-bold text-slate-800">
                                    {{ getSisaTahunIni(pegawai) }}
                                </dd>
                            </div>
                            <div class="rounded-md bg-rose-50 py-1">
                                <dt class="text-[9px] font-semibold uppercase text-rose-600">Pakai</dt>
                                <dd class="text-sm font-bold text-rose-700">
                                    {{ getTerpakaiTampil(pegawai)
                                    }}<sup v-if="adaKoreksi(pegawai)" class="text-amber-600 font-black">*</sup>
                                </dd>
                            </div>
                            <div class="rounded-md bg-blue-50 py-1">
                                <dt class="text-[9px] font-semibold uppercase text-blue-600">Sisa</dt>
                                <dd class="text-sm font-bold text-blue-700">
                                    {{ getSisaTahunBerjalan(pegawai) }}
                                </dd>
                            </div>
                            <div class="rounded-md bg-slate-800 py-1">
                                <dt class="text-[9px] font-semibold uppercase text-slate-300">Saldo</dt>
                                <dd class="text-sm font-bold text-white">
                                    {{ getSaldoAkhir(pegawai) }}
                                </dd>
                            </div>
                        </dl>
                    </article>

                    <div
                        v-if="daftarPegawai.length === 0"
                        class="rounded-xl border border-slate-200 p-5 text-center text-sm text-slate-500"
                    >
                        Tidak ada data pegawai ditemukan untuk tahun
                        {{ tahunAktif }}.
                        <button
                            v-if="isFilterAktif"
                            type="button"
                            @click="resetFilters"
                            class="ml-1 font-semibold text-green-700 hover:underline"
                        >
                            Atur ulang filter
                        </button>
                    </div>
                </div>

                <!-- Catatan kaki koreksi Admin HR -->
                <p v-if="adaItemDikoreksi" class="mt-3 text-xs text-amber-700">
                    * Angka bertanda bintang disesuaikan manual oleh Admin HR.
                </p>

                <!-- Pagination -->
                <div
                    v-if="dataPegawai?.links && dataPegawai.links.length > 3"
                    class="flex flex-col sm:flex-row items-center justify-between gap-2 sm:gap-3 mt-3"
                >
                    <p class="text-xs text-gray-500">
                        Menampilkan
                        <span class="font-semibold text-gray-700">{{ dataPegawai.from ?? 0 }}</span>
                        -
                        <span class="font-semibold text-gray-700">{{ dataPegawai.to ?? 0 }}</span>
                        dari
                        <span class="font-semibold text-gray-700">{{ dataPegawai.total ?? 0 }}</span>
                        pegawai
                    </p>
                    <div class="flex flex-wrap items-center justify-center gap-1">
                        <button
                            v-for="(link, index) in dataPegawai.links"
                            :key="index"
                            type="button"
                            v-html="link.label"
                            :disabled="!link.url"
                            @click="goToPage(link.url)"
                            class="min-w-[30px] h-8 px-2.5 text-xs font-semibold rounded-full border transition-colors"
                            :class="[
                                link.active
                                    ? 'bg-blue-600 border-blue-600 text-white'
                                    : 'border-gray-300 text-gray-600 hover:bg-gray-50',
                                !link.url
                                    ? 'opacity-40 cursor-not-allowed'
                                    : 'cursor-pointer',
                            ]"
                        ></button>
                    </div>
                </div>
            </div>

            <!-- ================================================================
                 POPUP DETAIL (hanya-baca) — disamakan dengan popup Rekap Kuota
                 Detail Admin: header tetap (handle + profil + tombol X), body
                 SATU-SATUNYA area scroll, footer tetap (Tutup Rincian).
                 - Dibungkus <Teleport to="body"> agar tidak tertutup header /
                   menu bawah aplikasi. Teleport sengaja DI DALAM div pembungkus
                   tunggal: MainLayout hanya boleh punya SATU elemen anak
                   langsung, kalau tidak transisi halaman error dan tombol
                   Detail tidak berfungsi.
                 - HP     : bottom sheet (sudut atas membulat, handle, maks 88dvh).
                 - >= sm  : dialog di tengah, lebar maks 'sm:max-w-lg'.
                 - Cara menutup: tombol X, tombol "Tutup Rincian", ketuk area
                   gelap, geser ke bawah (HP), atau tekan Esc.
                 ================================================================ -->
            <Teleport to="body">
                <div
                    v-if="showModal"
                    @click.self="closeModal"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="judul-modal-rincian"
                    class="fixed inset-0 z-[9999] flex items-end sm:items-center justify-center bg-black/60 p-0 sm:p-4 backdrop-blur-xs"
                >
                    <div
                        class="bg-white w-full sm:max-w-lg shadow-2xl relative animate-in fade-in zoom-in duration-200 flex flex-col overflow-hidden rounded-t-3xl sm:rounded-2xl max-h-[88dvh] sm:max-h-[calc(100dvh-2rem)]"
                    >
                        <!-- ============ 1) HEADER (tetap) ============ -->
                        <div
                            class="shrink-0 border-b bg-gray-50"
                            @touchstart.passive="mulaiGeserSheet"
                            @touchend.passive="selesaiGeserSheet"
                        >
                            <!-- Handle bottom sheet (HP) -->
                            <div class="sm:hidden flex justify-center pt-2">
                                <span class="h-1.5 w-10 rounded-full bg-gray-300"></span>
                            </div>

                            <div class="flex items-start justify-between gap-3 px-4 sm:px-6 pt-2 sm:pt-3.5 pb-3">
                                <div class="min-w-0 flex-1">
                                    <h3
                                        id="judul-modal-rincian"
                                        class="text-sm sm:text-lg font-bold text-gray-900 leading-snug"
                                    >
                                        Rincian Kuota & Riwayat Pegawai
                                    </h3>
                                    <p class="hidden sm:block text-xs text-gray-500 mt-0.5">
                                        Klik salah satu riwayat untuk melihat
                                        rincian lengkap
                                    </p>

                                    <!-- Profil pegawai ringkas (satu baris) -->
                                    <div
                                        v-if="selectedPegawai"
                                        class="mt-2 flex items-center gap-2.5 min-w-0"
                                    >
                                        <div class="w-8 h-8 rounded-full bg-green-600 flex items-center justify-center text-white font-bold text-sm uppercase shadow-sm shrink-0">
                                            {{ selectedPegawai.nama?.charAt(0) || "?" }}
                                        </div>
                                        <div class="min-w-0">
                                            <p class="font-bold text-gray-900 text-sm leading-tight truncate">
                                                {{ selectedPegawai.nama }}
                                            </p>
                                            <p class="text-[11px] text-gray-500 leading-tight mt-0.5 truncate">
                                                NIP:
                                                {{ selectedPegawai.nip || "-" }}
                                                &middot;
                                                {{
                                                    selectedPegawai.jabatan ||
                                                    selectedPegawai.kelompok_substansi ||
                                                    "Staf / Pegawai"
                                                }}
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <!-- Tombol X (selalu terlihat, target sentuh 40px) -->
                                <button
                                    @click="closeModal"
                                    type="button"
                                    aria-label="Tutup rincian"
                                    class="shrink-0 h-10 w-10 inline-flex items-center justify-center rounded-full text-gray-500 hover:text-red-500 bg-white border border-gray-200 hover:bg-gray-100 transition cursor-pointer"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <!-- ============ 2) BODY (satu area scroll) ============ -->
                        <div
                            v-if="selectedPegawai"
                            class="flex-1 min-h-0 overflow-y-auto overscroll-contain"
                        >
                            <!-- 4 KPI ringkas (Hak → Terpakai → Sisa → Saldo Akhir).
                                 HP: 2x2. >= sm: satu baris dan MENEMPEL di atas saat
                                 riwayat di-scroll, supaya Saldo Akhir selalu terlihat. -->
                            <div class="px-3 sm:px-6 pt-3 pb-3 sm:pb-2.5 sm:sticky sm:top-0 sm:z-10 sm:bg-white sm:border-b sm:border-gray-100">
                                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 sm:gap-2.5">
                                    <!-- KPI 1: Hak -->
                                    <div class="bg-white px-2.5 py-2 rounded-xl border border-gray-200 shadow-sm text-center">
                                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider leading-tight">
                                            Hak {{ tahunAktif }}
                                        </p>
                                        <p class="text-lg sm:text-xl font-black text-gray-800 leading-tight mt-0.5">
                                            {{ getSisaTahunIni(selectedPegawai) }}
                                            <span class="text-[11px] font-normal">hari</span>
                                        </p>
                                    </div>

                                    <!-- KPI 2: Terpakai (Rose) — sudah termasuk Tgh -->
                                    <div class="bg-rose-50 px-2.5 py-2 rounded-xl border border-rose-100 shadow-sm text-center">
                                        <p class="text-[10px] font-bold text-rose-500 uppercase tracking-wider leading-tight">
                                            Terpakai {{ tahunAktif }}
                                        </p>
                                        <p class="text-lg sm:text-xl font-black text-rose-600 leading-tight mt-0.5">
                                            {{ getTerpakaiTampil(selectedPegawai) }}
                                            <span class="text-[11px] font-normal">hari</span>
                                        </p>
                                    </div>

                                    <!-- KPI 3: Sisa (Biru) -->
                                    <div class="bg-blue-50 px-2.5 py-2 rounded-xl border border-blue-100 shadow-sm text-center">
                                        <p class="text-[10px] font-bold text-blue-500 uppercase tracking-wider leading-tight">
                                            Sisa {{ tahunAktif }}
                                        </p>
                                        <p class="text-lg sm:text-xl font-black text-blue-600 leading-tight mt-0.5">
                                            {{ getSisaTahunBerjalan(selectedPegawai) }}
                                            <span class="text-[11px] font-normal">hari</span>
                                        </p>
                                    </div>

                                    <!-- KPI 4: Saldo Akhir (Hitam Solid) -->
                                    <div class="bg-slate-800 border border-slate-900 text-white px-2.5 py-2 rounded-xl shadow-md text-center">
                                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider leading-tight">
                                            Saldo Akhir {{ tahunAktif }}
                                        </p>
                                        <p class="text-lg sm:text-xl font-black text-white leading-tight mt-0.5">
                                            {{ getSaldoAkhir(selectedPegawai) }}
                                            <span class="text-[11px] font-normal">hari</span>
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <!-- ================= END KPI ================= -->

                            <!-- ISI: rumus -> riwayat -->
                            <div class="p-3 pt-3 sm:px-6 sm:py-4 space-y-3 sm:space-y-4">
                                <!-- Rumus Saldo Akhir (ringkas).
                                     Hak + Bawaan [+ Tgh tahun lalu] - Terpakai = Saldo.
                                     Terpakai sudah termasuk hari yang ditangguhkan. -->
                                <div class="bg-gray-50 px-3 py-2 rounded-lg text-[11px] sm:text-xs text-gray-600 border border-gray-100">
                                    Saldo Akhir {{ tahunAktif }}:
                                    <span class="font-semibold text-gray-800">Hak {{ getSisaTahunIni(selectedPegawai) }}</span>
                                    +
                                    <span class="font-semibold text-gray-800">Bawaan {{ getSaldoBawaanEligible(selectedPegawai) }}</span>
                                    <template v-if="getSisaDitangguhkanTahunLalu(selectedPegawai) > 0">
                                        +
                                        <span class="font-semibold text-gray-800"
                                            >Tgh {{ tahunAktif - 1 }}
                                            {{ getSisaDitangguhkanTahunLalu(selectedPegawai) }}</span
                                        >
                                    </template>
                                    −
                                    <span class="font-semibold text-gray-800">Terpakai {{ getTerpakaiTampil(selectedPegawai) }}</span>
                                    =
                                    <span class="font-bold text-green-700">{{ getSaldoAkhir(selectedPegawai) }} hari</span>
                                    <p
                                        v-if="getSisaDitangguhkanTahunIni(selectedPegawai) > 0"
                                        class="mt-1 text-[11px] text-gray-500"
                                    >
                                        (Terpakai {{ getTerpakaiTampil(selectedPegawai) }} =
                                        {{ getTerpakai(selectedPegawai) }} hari digunakan +
                                        {{ getSisaDitangguhkanTahunIni(selectedPegawai) }} hari
                                        ditangguhkan)
                                    </p>
                                    <p
                                        v-if="adaKoreksi(selectedPegawai)"
                                        class="mt-1 text-[11px] text-amber-700"
                                    >
                                        Terpakai memakai angka koreksi Admin HR
                                        ({{
                                            getKoreksiSaldo(selectedPegawai) +
                                            getSisaDitangguhkanTahunIni(selectedPegawai)
                                        }}
                                        hari). Angka dari sistem:
                                        {{ getTerpakaiSistemTampil(selectedPegawai) }} hari.
                                    </p>
                                </div>

                                <!-- ============ RIWAYAT PENGAJUAN CUTI ============ -->
                                <div>
                                    <h5 class="font-bold text-gray-900 text-sm sm:text-base mb-2 flex items-center gap-2">
                                        <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                        Riwayat Pengajuan Cuti
                                        <span class="inline-block min-w-[20px] px-1.5 py-0.5 rounded-full bg-gray-100 text-gray-600 text-[11px] font-bold text-center">{{
                                            selectedPegawai.pengajuan_cuti?.length || 0
                                        }}</span>
                                    </h5>

                                    <!-- Ringkasan verifikasi Terpakai: total Cuti Tahunan
                                         disetujui di tahun aktif vs angka sistem. Hijau
                                         kalau cocok, kuning kalau berbeda. -->
                                    <div
                                        class="mb-3 rounded-lg border px-3 py-2 text-xs leading-relaxed"
                                        :class="
                                            ringkasanDisetujui.total === ringkasanDisetujui.sistem
                                                ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
                                                : 'border-amber-200 bg-amber-50 text-amber-800'
                                        "
                                    >
                                        Cuti Tahunan disetujui {{ tahunAktif }}:
                                        <span class="font-bold">{{ ringkasanDisetujui.total }} hari</span>
                                        dari {{ ringkasanDisetujui.jumlahPengajuan }}
                                        pengajuan. Sistem mencatat
                                        <span class="font-bold">{{ ringkasanDisetujui.sistem }} hari</span>.
                                        <template v-if="ringkasanDisetujui.total !== ringkasanDisetujui.sistem">
                                            Angkanya berbeda, periksa riwayat di bawah.
                                        </template>
                                        <template v-if="getSisaDitangguhkanTahunIni(selectedPegawai) > 0">
                                            Ditambah Tgh
                                            {{ getSisaDitangguhkanTahunIni(selectedPegawai) }}
                                            hari, Terpakai yang tampil
                                            {{ getTerpakaiSistemTampil(selectedPegawai) }}
                                            hari.
                                        </template>
                                        <template v-if="adaKoreksi(selectedPegawai)">
                                            Terpakai yang tampil di tabel:
                                            <span class="font-bold"
                                                >{{ getTerpakaiTampil(selectedPegawai) }}
                                                hari (koreksi Admin HR)</span
                                            >.
                                        </template>
                                    </div>

                                    <!-- Filter: chip status + hanya Cuti Tahunan -->
                                    <div class="mb-3 space-y-2">
                                        <div
                                            class="flex flex-wrap gap-1.5"
                                            role="group"
                                            aria-label="Filter status riwayat"
                                        >
                                            <button
                                                v-for="opsi in OPSI_STATUS_RIWAYAT"
                                                :key="opsi.key"
                                                type="button"
                                                @click="
                                                    filterStatusRiwayat = opsi.key;
                                                    expandedRiwayatId = null;
                                                "
                                                :aria-pressed="filterStatusRiwayat === opsi.key"
                                                class="inline-flex min-h-[32px] cursor-pointer items-center gap-1 rounded-full border px-3 py-1 text-xs font-semibold transition"
                                                :class="
                                                    filterStatusRiwayat === opsi.key
                                                        ? 'border-emerald-600 bg-emerald-600 text-white'
                                                        : 'border-gray-300 bg-white text-gray-600 hover:bg-gray-50'
                                                "
                                            >
                                                {{ opsi.label }}
                                                <span
                                                    class="rounded-full px-1.5 text-[10px] font-bold"
                                                    :class="
                                                        filterStatusRiwayat === opsi.key
                                                            ? 'bg-white/25'
                                                            : 'bg-gray-100 text-gray-500'
                                                    "
                                                    >{{ jumlahPerStatus[opsi.key] }}</span
                                                >
                                            </button>
                                        </div>
                                        <label class="flex cursor-pointer select-none items-center gap-2 text-xs text-gray-600">
                                            <input
                                                type="checkbox"
                                                v-model="hanyaCutiTahunan"
                                                class="h-4 w-4 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500"
                                            />
                                            Hanya Cuti Tahunan (jenis lain tidak
                                            mengurangi kuota)
                                        </label>
                                    </div>

                                    <!-- Kosong: tidak ada riwayat sama sekali, atau
                                         tidak ada yang cocok dengan filter -->
                                    <div
                                        v-if="riwayatTersaring.length === 0"
                                        class="text-center py-6 bg-gray-50 rounded-xl border border-dashed border-gray-200 text-xs text-gray-400"
                                    >
                                        <template
                                            v-if="
                                                !selectedPegawai.pengajuan_cuti ||
                                                selectedPegawai.pengajuan_cuti.length === 0
                                            "
                                        >
                                            Belum ada catatan riwayat pengajuan cuti.
                                        </template>
                                        <template v-else>
                                            Tidak ada pengajuan yang cocok dengan
                                            filter. Pilih status "Semua" atau
                                            matikan "Hanya Cuti Tahunan".
                                        </template>
                                    </div>

                                    <!-- List riwayat -->
                                    <div v-else class="space-y-2">
                                        <div
                                            v-for="riwayat in riwayatTersaring"
                                            :key="riwayat.id"
                                            @click="toggleExpand(riwayat.id)"
                                            class="bg-white p-2.5 sm:p-4 rounded-xl border border-gray-200/80 text-sm flex flex-col gap-2 transition-colors duration-150 hover:shadow-sm cursor-pointer relative overflow-hidden group"
                                            :class="[
                                                kelasGarisStatus(riwayat.status),
                                                expandedRiwayatId === riwayat.id
                                                    ? 'ring-2 ring-green-500/20'
                                                    : '',
                                            ]"
                                        >
                                            <!-- Baris atas: Jenis Cuti & Badge Status -->
                                            <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-1">
                                                <span class="font-bold text-gray-900 text-sm sm:text-base group-hover:text-green-700 transition">{{
                                                    riwayat.jenis_cuti
                                                }}</span>
                                                <div class="flex items-start sm:items-center justify-between sm:justify-start gap-2">
                                                    <span
                                                        class="px-2 py-0.5 sm:px-2.5 sm:py-1 rounded-md font-bold text-[11px] sm:text-xs tracking-wide uppercase min-w-0 break-words"
                                                        :class="kelasBadgeStatus(riwayat.status)"
                                                    >
                                                        {{ getStatusLabel(riwayat.status) }}
                                                    </span>
                                                    <svg
                                                        class="w-4 h-4 text-gray-400 transition-transform duration-200 shrink-0"
                                                        :class="{
                                                            'rotate-180 text-green-600':
                                                                expandedRiwayatId === riwayat.id,
                                                        }"
                                                        fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                                    >
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                                    </svg>
                                                </div>
                                            </div>

                                            <!-- Tanggal, Durasi, dan badge lintas tahun -->
                                            <div class="text-gray-600 text-xs sm:text-sm flex flex-wrap items-center gap-x-2 gap-y-0.5 font-medium">
                                                <span>{{ riwayat.tanggal_mulai }} s/d {{ riwayat.tanggal_selesai }}</span>
                                                <span>&middot;</span>
                                                <span class="font-bold text-gray-900">{{ getDurasi(riwayat) }} Hari</span>
                                                <span
                                                    v-if="hariDihitungBeda(riwayat)"
                                                    class="rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-700"
                                                    :title="`Cuti lintas tahun: hanya ${riwayat.hari_di_tahun_ini} hari yang jatuh di ${tahunAktif}`"
                                                    >dihitung di {{ tahunAktif }}:
                                                    {{ riwayat.hari_di_tahun_ini }} hari</span
                                                >
                                            </div>

                                            <!-- Keterangan / Alasan -->
                                            <div
                                                v-if="riwayat.keterangan"
                                                class="text-gray-800 bg-gray-50/80 border border-gray-200/60 px-3 py-2 rounded-lg italic text-xs sm:text-sm font-medium leading-relaxed break-words"
                                            >
                                                "{{ formatKeteranganRapi(riwayat.keterangan) }}"
                                            </div>

                                            <!-- AREA RINCIAN SAAT KARTU DIKLIK -->
                                            <div
                                                v-if="expandedRiwayatId === riwayat.id"
                                                @click.stop
                                                class="mt-1 pt-2.5 border-t-2 border-dashed border-green-200 bg-green-50/40 -mx-2.5 sm:-mx-4 -mb-2.5 sm:-mb-4 p-2.5 sm:p-4 rounded-b-xl space-y-2.5 text-xs cursor-default"
                                            >
                                                <div class="font-bold text-green-900 uppercase tracking-wider text-[11px] flex items-center gap-1.5">
                                                    <svg class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                                    </svg>
                                                    Rincian Lengkap Pengajuan
                                                </div>

                                                <!-- Alamat & kontak selama cuti -->
                                                <div class="grid grid-cols-1 md:grid-cols-2 gap-2.5 bg-white p-2.5 rounded-lg border border-green-100">
                                                    <div>
                                                        <span class="block text-gray-400 text-[10px] uppercase font-semibold">Alamat Selama Cuti</span>
                                                        <p class="font-medium text-gray-800 mt-0.5 break-words">
                                                            {{ riwayat.alamat_cuti || "-" }}
                                                        </p>
                                                    </div>
                                                    <div>
                                                        <span class="block text-gray-400 text-[10px] uppercase font-semibold">Nomor Kontak / Telepon</span>
                                                        <p class="font-medium text-gray-800 mt-0.5 break-words">
                                                            {{ riwayat.no_telp || "-" }}
                                                        </p>
                                                    </div>
                                                </div>

                                                <!-- Lampiran -->
                                                <div class="flex items-center justify-between bg-white p-2.5 rounded-lg border border-green-100 gap-3">
                                                    <div class="flex items-center gap-2">
                                                        <svg class="w-5 h-5 text-red-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                                        </svg>
                                                        <div>
                                                            <span class="block text-gray-800 font-semibold">Lampiran Pendukung</span>
                                                            <span class="text-gray-400 text-[10px]">{{
                                                                riwayat.lampiran
                                                                    ? "File tersedia di sistem"
                                                                    : "Tidak ada lampiran diunggah"
                                                            }}</span>
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
                                                    <span v-else class="text-gray-400 italic text-[11px] shrink-0">Tanpa Lampiran</span>
                                                </div>

                                                <!-- Approval chain: SEMUA level + jaring pengaman filter L1 -->
                                                <div
                                                    v-if="getVisibleApprovalChain(riwayat.approval_chain, selectedPegawai).length > 0"
                                                    class="bg-white p-2.5 rounded-lg border border-green-100 space-y-1"
                                                >
                                                    <div
                                                        v-for="(step, idx) in getVisibleApprovalChain(riwayat.approval_chain, selectedPegawai)"
                                                        :key="step.level ?? `tangguh-${idx}`"
                                                        class="flex items-start sm:items-center gap-1.5 text-gray-600"
                                                    >
                                                        <svg
                                                            v-if="step.status === 'tangguh'"
                                                            class="w-3.5 h-3.5 text-orange-400 shrink-0"
                                                            fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                                        >
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                                        </svg>
                                                        <svg
                                                            v-else
                                                            class="w-3.5 h-3.5 text-gray-400 shrink-0"
                                                            fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                                        >
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                                        </svg>
                                                        <span class="flex-1 min-w-0 break-words">
                                                            <template v-if="step.level">
                                                                L{{ step.level }} &middot;
                                                            </template>
                                                            <strong class="text-gray-800">{{ step.nama }}</strong>
                                                            <span class="text-gray-400 text-[10px]">
                                                                ({{ step.label }})
                                                            </span>
                                                        </span>
                                                        <span
                                                            class="font-bold uppercase text-[11px] shrink-0"
                                                            :class="approvalStepClass(step.status)"
                                                        >
                                                            {{ approvalStepLabel(step.status) }}
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <!-- ================= END RIWAYAT ================= -->
                            </div>
                        </div>

                        <!-- ============ 3) FOOTER (tetap) ============ -->
                        <div class="shrink-0 border-t bg-gray-50 px-3 sm:px-6 pt-2.5 sm:pt-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))] sm:pb-3">
                            <button
                                @click="closeModal"
                                type="button"
                                class="w-full sm:w-auto sm:ml-auto sm:flex min-h-[44px] sm:min-h-0 justify-center items-center px-6 py-2.5 sm:py-2 bg-gray-800 hover:bg-gray-700 text-white text-sm font-semibold rounded-xl transition shadow-sm cursor-pointer whitespace-nowrap"
                            >
                                Tutup Rincian
                            </button>
                        </div>
                    </div>
                </div>
            </Teleport>
        </div>
    </MainLayout>
</template>

