<script setup>
import {
    ref,
    reactive,
    computed,
    watch,
    nextTick,
    onMounted,
    onBeforeUnmount,
    h,
} from "vue";
import { router, useForm, Head, Link } from "@inertiajs/vue3";
import MainLayout from "@/Layouts/MainLayout.vue";
import SelAngka from "@/Components/SelAngka.vue";
import { useTahunFilter } from "@/composables/useTahunFilter";

// Tahun kalender sebenarnya (hari ini). Dipakai sebagai default filter tahun
// dan sebagai batas tahun yang boleh digenerate (currentYear + 1).
const currentYear = new Date().getFullYear();

// Ikon pensil kecil untuk penanda kolom yang bisa diedit (dipakai di header
// tabel, label kartu HP, dan legenda). Komponen fungsional supaya path SVG
// tidak diulang di tiap tempat.
// Hiasan saja (aria-hidden); artinya dijelaskan oleh legenda di bawah tabel
// dan tooltip pada header.
const IkonPensil = () =>
    h(
        "svg",
        {
            class: "w-3 h-3 shrink-0 text-emerald-600",
            fill: "none",
            stroke: "currentColor",
            viewBox: "0 0 24 24",
            "aria-hidden": "true",
        },
        [
            h("path", {
                "stroke-linecap": "round",
                "stroke-linejoin": "round",
                "stroke-width": "2",
                d: "M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z",
            }),
        ],
    );

// [TERPAKAI DIKUNCI] Ikon gembok kecil untuk penanda kolom yang HANYA TAMPIL
// (Cuti Terpakai). Angkanya diubah per bulan di Rekap Laporan.
const IkonGembok = () =>
    h(
        "svg",
        {
            class: "w-3 h-3 shrink-0 text-slate-500",
            fill: "none",
            stroke: "currentColor",
            viewBox: "0 0 24 24",
            "aria-hidden": "true",
        },
        [
            h("path", {
                "stroke-linecap": "round",
                "stroke-linejoin": "round",
                "stroke-width": "2",
                d: "M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z",
            }),
        ],
    );

// Tooltip untuk header kolom yang bisa diedit.
const KETERANGAN_BISA_DIEDIT = "Kolom bisa diedit: klik angkanya";

// [TERPAKAI DIKUNCI] Tooltip kolom Terpakai.
const KETERANGAN_TERPAKAI_DIKUNCI =
    "Terpakai dikunci di halaman ini. Ubah per bulan di Rekap Laporan.";

const NAMA_BULAN_PENDEK = [
    "Jan",
    "Feb",
    "Mar",
    "Apr",
    "Mei",
    "Jun",
    "Jul",
    "Agu",
    "Sep",
    "Okt",
    "Nov",
    "Des",
];

/**
 * Hitung saldo bawaan eligible — selaras dengan Dashboard karyawan & Excel:
 * IF (sisa N-2 == 12 AND sisa N-1 == 12) → 12
 * else → MIN(6, sisa N-1)
 * [DIUBAH] Batas total 24 hari DINONAKTIFKAN: Bawaan tidak bergantung pada Hak
 * (selaras dengan SaldoCuti::TERAPKAN_BATAS_ATAS_TOTAL_HAK = false di backend).
 * Jika backend sudah kirim saldo_bawaan_eligible, pakai itu.
 */
const getSaldoBawaanEligible = (pegawai) => {
    if (pegawai?.saldo_bawaan_eligible != null) {
        return Number(pegawai.saldo_bawaan_eligible) || 0;
    }
    const sisaN2 = Number(pegawai?.sisa_cuti_dua_tahun_lalu ?? 0);
    const sisaN1 = Number(pegawai?.carry_forward_normal ?? 0);
    const normal = sisaN2 === 12 && sisaN1 === 12 ? 12 : Math.min(6, sisaN1);
    return Math.max(0, normal);
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
// [TERPAKAI DIKUNCI] Cuti Terpakai HANYA TAMPIL di halaman ini.
// Angkanya berasal dari backend ('cuti_terpakai') dan diubah per bulan di
// Rekap Laporan (koreksi bulanan), supaya selalu jelas koreksinya jatuh di
// bulan mana. Tidak ada lagi input Terpakai / tombol "kembalikan ke sistem"
// di sini.
//
// Field dari backend:
//   cuti_terpakai          : Terpakai efektif (belum termasuk Tgh tahun ini)
//   cuti_terpakai_sistem   : hitungan murni sistem (riwayat disetujui)
//   ada_koreksi            : ada koreksi (tahunan lama ATAU bulanan)
//   ada_koreksi_bulanan    : ada koreksi bulanan dari Rekap Laporan
//   koreksi_bulanan        : { bulan(1-12): nilai }
//   koreksi_saldo          : koreksi tahunan LAMA (null = tidak ada), info saja
// ================================================================

// Ada koreksi dari Admin HR atau tidak (bulanan atau tahunan lama).
const adaKoreksi = (pegawai) => {
    if (pegawai?.ada_koreksi != null) return Boolean(pegawai.ada_koreksi);
    const k = pegawai?.koreksi_saldo;
    return k !== null && k !== undefined;
};

const adaKoreksiBulanan = (pegawai) => Boolean(pegawai?.ada_koreksi_bulanan);

// Nama bulan yang dikoreksi di Rekap Laporan, mis. ["Okt", "Des"].
const bulanDikoreksi = (pegawai) =>
    Object.keys(pegawai?.koreksi_bulanan || {})
        .map(Number)
        .filter((b) => b >= 1 && b <= 12)
        .sort((a, b) => a - b)
        .map((b) => NAMA_BULAN_PENDEK[b - 1]);

// Angka cuti terpakai MURNI dari sistem (riwayat pengajuan disetujui),
// dipakai untuk keterangan "Cuti terpakai dari sistem: X hari".
const getTerpakaiSistem = (pegawai) =>
    Number(
        pegawai?.cuti_terpakai_sistem ?? pegawai?.cuti_terpakai_murni ?? 0,
    ) || 0;

// ================================================================
// Sisa ditangguhkan (Tgh) — tampil di tabel utama & modal Rincian.
// Nilainya dihitung backend dari tabel cuti_ditangguhkans (SUM jumlah_hari
// per tahun_asal).
//
// [ATURAN]
//   - Tgh TAHUN YANG DILIHAT (N) adalah BAGIAN dari Terpakai N:
//     Terpakai (tampil) = cuti terpakai + Tgh N. Angka Tgh tetap tampil
//     sebagai catatan di kolom Tgh. Karena Tgh sudah ada di dalam Terpakai,
//     Sisa N dan Saldo Akhir N TIDAK mengurangi Tgh lagi
//     (Sisa N = Hak - Terpakai; Saldo Akhir = Total - Terpakai).
//   - Tgh N-1 (tahun lalu) MENAMBAH Total Tersedia (aturan A, sama dengan
//     backend RekapKuotaDetailController::TGH_MASUK_TOTAL = true):
//     Total = Hak + Bawaan + Tgh N-1.
//   - Tgh N-2 hanya CATATAN Admin HR (tidak memengaruhi saldo).
// Admin HR boleh mengubah angkanya langsung di baris (klik angkanya);
// backend menyesuaikan total lewat setTotalDitangguhkan().
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

// Ditangguhkan pada tahun yang sedang dilihat (tahun_asal = tahun aktif).
const getSisaDitangguhkanTahunIni = (pegawai) =>
    Number(pegawai?.sisa_ditangguhkan_tahun_ini ?? 0);

const getTotalTersedia = (pegawai) => {
    if (pegawai?.total_cuti_tersedia != null) {
        return Number(pegawai.total_cuti_tersedia) || 0;
    }
    // Total Tersedia = Hak + Bawaan Eligible + Tgh tahun lalu (aturan A)
    return (
        getSisaTahunIni(pegawai) +
        getSaldoBawaanEligible(pegawai) +
        getSisaDitangguhkanN1(pegawai)
    );
};

// Cuti Terpakai yang DITAMPILKAN = cuti terpakai dari backend
// ('cuti_terpakai': sudah ikut koreksi bulanan Rekap Laporan) + Tgh tahun
// ini. Sama dengan Dashboard karyawan (mis. 2 digunakan + 1 ditangguhkan = 3).
// Nilai 'cuti_terpakai' dari backend TIDAK menyertakan Tgh.
const getTerpakaiTampil = (pegawai) =>
    Number(pegawai?.cuti_terpakai ?? 0) + getSisaDitangguhkanTahunIni(pegawai);

// Angka sistem (tanpa koreksi Admin) dalam bentuk TAMPIL: sistem + Tgh tahun ini.
const getTerpakaiSistemTampil = (pegawai) =>
    getTerpakaiSistem(pegawai) + getSisaDitangguhkanTahunIni(pegawai);

const getSaldoAkhir = (pegawai) => {
    if (pegawai?.saldo_akhir != null) {
        return Number(pegawai.saldo_akhir) || 0;
    }
    // Tgh tahun ini sudah termasuk di Terpakai (tampil), jadi tidak
    // dikurangkan lagi: Saldo Akhir = Total - Terpakai (tampil).
    return Math.max(0, getTotalTersedia(pegawai) - getTerpakaiTampil(pegawai));
};

// Sisa jatah tahun berjalan untuk KPI modal. Ini berbeda dari Total
// Tersedia karena tidak memasukkan Saldo Bawaan Eligible maupun Tgh N-1.
// Sisa = Hak - Terpakai (tampil, sudah termasuk Tgh tahun ini).
// Angka final dari backend ('sisa_kuota_tahun_ini') dipakai bila tersedia.
const getSisaTahunBerjalan = (pegawai) => {
    if (pegawai?.sisa_kuota_tahun_ini != null) {
        return Math.max(0, Number(pegawai.sisa_kuota_tahun_ini) || 0);
    }
    const sisa = getSisaTahunIni(pegawai) - getTerpakaiTampil(pegawai);
    return Math.max(0, sisa);
};

// Teks tooltip untuk Tgh tahun lalu (N-1): menambah Total Tersedia.
const KETERANGAN_TGH_N1 = "Tgh tahun lalu menambah Total Tersedia";

// Teks tooltip untuk Tgh dua tahun lalu (N-2): hanya catatan.
const KETERANGAN_TGH_N2 = "Catatan Admin HR (tidak memengaruhi saldo)";

// Teks tooltip untuk angka hasil hitungan yang bisa diklik (sorot rumusnya).
const KETERANGAN_KLIK_RUMUS = "Klik untuk menyorot kolom sumber rumusnya";

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
    // true = semua pegawai aktif sudah punya dompet tahun depan
    // (dikirim RekapKuotaDetailController::index()). Tombol Generate dikunci.
    // Nilai ini mengacu ke tahun kalender depan (currentYear + 1).
    sudahGenerateTahunDepan: {
        type: Boolean,
        default: false,
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
// halaman ini (header kolom, KPI modal, edit inline, tombol Generate, dll).
// ================================================================
const filterTahun = ref(Number(props.filters?.tahun) || currentYear);

const tahunAktif = computed(() => Number(filterTahun.value) || currentYear);

// [TERPAKAI DIKUNCI] Link ke Rekap Laporan, sudah terfilter ke pegawai &
// tahun yang sedang dilihat, tempat Terpakai diubah per bulan.
const urlRekapLaporan = (pegawai) =>
    route("admin.rekap", {
        tahun: tahunAktif.value,
        search: pegawai?.nip || pegawai?.nama || undefined,
    });

// Tooltip kotak Terpakai: keterangan kunci + bulan yang dikoreksi.
const titleTerpakai = (pegawai) => {
    let teks = KETERANGAN_TERPAKAI_DIKUNCI;
    if (adaKoreksi(pegawai)) {
        const bulan = bulanDikoreksi(pegawai);
        teks += ` Dikoreksi Admin HR${bulan.length ? ` (bulan: ${bulan.join(", ")})` : ""}, sistem mencatat ${getTerpakaiSistemTampil(pegawai)} hari.`;
    }
    return teks;
};

// Tgh tahun ini tetap tercatat dan menjadi bagian dari Terpakai.
const KETERANGAN_TGH_TAHUN_INI = computed(
    () =>
        `Cuti ${tahunAktif.value} yang ditangguhkan: tetap tercatat dan sudah termasuk dalam Terpakai ${tahunAktif.value}.`,
);

// Tooltip kolom "Sisa {tahun}" (kolom hitungan otomatis, tidak bisa diedit).
const KETERANGAN_SISA_TAHUN_INI = computed(
    () =>
        `Sisa jatah ${tahunAktif.value} = Hak − Terpakai (Terpakai sudah termasuk Tgh ${tahunAktif.value}; belum termasuk bawaan). Dihitung otomatis.`,
);

// Tooltip Bawaan: aturannya bukan penjumlahan, jadi dijelaskan di sini
// (bukan lewat panel) supaya sorotan warna tetap bersih.
const KETERANGAN_BAWAAN = computed(
    () =>
        `Bawaan = yang lebih kecil antara 6 dan Sisa ${tahunAktif.value - 1}. Bila Sisa ${tahunAktif.value - 2} dan Sisa ${tahunAktif.value - 1} sama-sama 12, bawaan 12. Klik untuk menyorot sumbernya.`,
);

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

// Ada baris data di halaman ini? Dipakai untuk memilih tampilan
// tabel / kartu mobile / state kosong.
const punyaData = computed(() => daftarPegawaiHalamanIni.value.length > 0);

// Nama kelompok substansi untuk tampilan. Nilai kosong atau "-" dari
// backend memakai fallback "Biro Perencanaan".
const getKelompok = (pegawai) => {
    const k = (pegawai?.kelompok_substansi ?? "").toString().trim();
    return k === "" || k === "-" ? "Biro Perencanaan" : k;
};

const kpiTotalPegawai = computed(() => props.kpi?.total_pegawai ?? 0);

const kpiRataRataTerpakai = computed(() => props.kpi?.rata_rata_terpakai ?? 0);

const kpiRataRataSaldoAkhir = computed(
    () => props.kpi?.rata_rata_saldo_akhir ?? 0,
);

const kpiAkumulasiPenuh = computed(() => props.kpi?.akumulasi_penuh ?? 0);
// ================= END KPI CARDS =================

// ========================================== //
// ---> FITUR: EDIT SALDO INLINE (KLIK ANGKA) <---
// ========================================== //
// Klik salah satu angka yang boleh diedit (Sisa N-2, Tgh N-2, Sisa N-1,
// Tgh N-1, Tgh N, Hak) -> SEMUA kapsul editable di baris itu berubah jadi
// input seukuran kapsul (baris tidak melebar), fokus ke angka yang diklik.
// Hanya SATU baris yang boleh diedit pada satu waktu ('editingId').
// Isian sejajar dengan sheet "Saldo Tahunan" Excel:
//   - hak           -> hak_tahun_ini            (Hak {tahun})
//   - sisa_n1       -> carry_forward_normal     (Sisa {tahun-1})
//   - sisa_n2       -> sisa_cuti_dua_tahun_lalu (Sisa {tahun-2})
//   - tgh_n2/n1/n0  -> sisa_ditangguhkan_*      (N-2 hanya catatan; N-1
//                                                menambah Total; N sudah
//                                                termasuk di Terpakai)
// Bawaan Eligible, Sisa {tahun}, Total Tersedia, Terpakai & Saldo Akhir
// dihitung otomatis (pratinjau langsung saat mengetik; angka final tetap
// dari backend).
//
// [TERPAKAI DIKUNCI] Terpakai TIDAK bisa diedit di sini. Admin HR mengubahnya
// per bulan di Rekap Laporan. Mengubah Tgh tahun ini tetap menggeser angka
// Terpakai yang tampil (Terpakai = cuti terpakai + Tgh tahun ini).
//
// Enter = Simpan, Esc = Batal.
const editingId = ref(null);

// useForm hanya dipakai untuk processing, errors, dan kirim PUT.
// Isian disimpan di 'draft' (reactive biasa) supaya tidak bergantung pada
// perilaku defaults()/isDirty milik useForm.
const saldoForm = useForm({});

const draft = reactive({
    sisa_n2: 0,
    tgh_n2: 0,
    sisa_n1: 0,
    tgh_n1: 0,
    tgh_n0: 0,
    hak: 0,
});
const snapshotDraft = ref("");
const isDirty = computed(() => JSON.stringify(draft) !== snapshotDraft.value);

// Ubah isian (bisa '' saat dikosongkan) jadi angka bulat >= 0.
const angka = (v) => Math.max(0, Number(v) || 0);

const isSavingSaldo = computed(() => saldoForm.processing);

// Pesan sukses singkat di atas halaman setelah Simpan Saldo.
const pesanSukses = ref("");
let timerPesanSukses = null;

const isEditing = (pegawai) =>
    editingId.value !== null && editingId.value === pegawai?.id;

// Data pegawai (dari props terbaru) yang sedang diedit.
const pegawaiEdit = computed(
    () =>
        daftarPegawaiHalamanIni.value.find((p) => p.id === editingId.value) ??
        null,
);

// Semua pesan error validasi backend untuk baris yang sedang diedit.
const daftarErrorEdit = computed(() =>
    Object.values(saldoForm.errors || {}).filter(Boolean),
);

// Pratinjau hitungan saat mengetik. Rumus SAMA dengan getSaldoBawaanEligible
// & SaldoCuti::hitungBawaan di backend. Angka final tetap dari backend.
const previewEdit = computed(() => {
    const p = pegawaiEdit.value;
    if (!p) return null;

    const hak = angka(draft.hak);
    const n1 = angka(draft.sisa_n1);
    const n2 = angka(draft.sisa_n2);
    // Tgh tahun ini sudah termasuk di Terpakai (tampil).
    const tghTahunIni = angka(draft.tgh_n0);

    const normal = n2 === 12 && n1 === 12 ? 12 : Math.min(6, n1);
    // [DIUBAH] Batas 24 dinonaktifkan: Bawaan tidak bergantung pada Hak
    // (selaras dengan SaldoCuti::TERAPKAN_BATAS_ATAS_TOTAL_HAK = false).
    const bawaan = Math.max(0, normal);
    // Terpakai (dikunci) dari backend + Tgh tahun ini hasil ketikan.
    const terpakai = (Number(p.cuti_terpakai) || 0) + tghTahunIni;
    // Tgh N-1 menambah Total Tersedia (sama dengan backend). Tgh N-2 hanya
    // catatan, jadi tidak masuk.
    const total = hak + bawaan + angka(draft.tgh_n1);

    return {
        bawaan,
        total,
        terpakai,
        // Tgh tahun ini sudah ada di dalam 'terpakai', jangan dikurangkan lagi.
        saldoAkhir: Math.max(0, total - terpakai),
    };
});

// Nilai yang ditampilkan: pratinjau saat baris sedang diedit, angka
// backend saat tidak. Dipakai tabel DAN kartu supaya satu sumber logika.
const nilaiSisaN2 = (p) =>
    isEditing(p) ? angka(draft.sisa_n2) : getSisaDuaTahunLalu(p);
const nilaiSisaN1 = (p) =>
    isEditing(p) ? angka(draft.sisa_n1) : getSisaTahunLalu(p);
const nilaiHak = (p) => (isEditing(p) ? angka(draft.hak) : getSisaTahunIni(p));
const nilaiTghN2 = (p) =>
    isEditing(p) ? angka(draft.tgh_n2) : getSisaDitangguhkanN2(p);
const nilaiTghN1 = (p) =>
    isEditing(p) ? angka(draft.tgh_n1) : getSisaDitangguhkanN1(p);
const nilaiTghN0 = (p) =>
    isEditing(p) ? angka(draft.tgh_n0) : getSisaDitangguhkanTahunIni(p);
const nilaiBawaan = (p) =>
    isEditing(p) && previewEdit.value
        ? previewEdit.value.bawaan
        : getSaldoBawaanEligible(p);
const nilaiTotal = (p) =>
    isEditing(p) && previewEdit.value
        ? previewEdit.value.total
        : getTotalTersedia(p);
// Terpakai tampil = cuti terpakai + Tgh tahun ini.
const nilaiTerpakai = (p) =>
    isEditing(p) && previewEdit.value
        ? previewEdit.value.terpakai
        : getTerpakaiTampil(p);
const nilaiSaldoAkhir = (p) =>
    isEditing(p) && previewEdit.value
        ? previewEdit.value.saldoAkhir
        : getSaldoAkhir(p);

// Sisa jatah tahun berjalan = Hak - Terpakai (belum termasuk bawaan).
// Terpakai sudah memuat Tgh tahun ini, jadi Tgh tidak dikurangkan lagi.
// Kolom hitungan otomatis (tidak bisa diedit). Memakai nilaiHak() dan
// nilaiTerpakai(), jadi ikut berubah langsung saat baris sedang diedit.
const nilaiSisaTahunIni = (p) => Math.max(0, nilaiHak(p) - nilaiTerpakai(p));

// ================================================================
// FITUR: SOROT RUMUS DENGAN WARNA (KLIK ANGKA HASIL)
// Kolom hasil hitungan (Sisa N, Bawaan, Total, Terpakai, Saldo Akhir)
// masing-masing punya WARNA sendiri. Klik angka hasil di satu baris:
//   - angka yang diklik terisi penuh dengan warnanya,
//   - kolom sumber rumusnya terisi warna yang SAMA (lebih muda) dan diberi
//     tanda + / − di atasnya,
//   - kolom lain di baris itu meredup,
//   - header kolom sumber ikut diberi warna.
// Tidak ada panel penjelasan; cukup lewat warna. Aturan Bawaan (bukan
// penjumlahan) dijelaskan lewat tooltip.
//
// Rumus (sesuai catatan Admin HR):
//   1. Sisa N        = Hak N − Terpakai N
//   2. Bawaan N      = min(6, Sisa N-1), atau 12 bila Sisa N-2 dan N-1
//                      sama-sama 12 (lihat getSaldoBawaanEligible)
//   3. Total N       = Tgh N-1 + Bawaan N + Hak N
//   4. Saldo Akhir N = Total N − Terpakai N
//   5. Terpakai N    = cuti terpakai (dikunci) + Tgh N
// [TERPAKAI DIKUNCI] Klik angka Terpakai sekarang menyorot sumbernya
// (Tgh tahun ini), bukan masuk mode edit.
//
// Hanya SATU baris yang disorot pada satu waktu.
// SOROTAN TETAP MENYALA selama baris itu diedit, jadi admin bisa menyorot
// Sisa N, lalu mengklik Hak untuk mengubahnya sambil melihat pratinjau.
// Sorotan ditutup oleh: Simpan berhasil, Batal, Esc, klik di luar area
// baris/rumus, pindah ke baris lain, atau klik angka yang sama lagi.
//
// Klik angka hasil juga MELEPAS seleksi kotak input sebelumnya
// ('fokusEdit' = null), jadi cincin oranye / lencana Δ dari kotak yang
// tadi difokus tidak ikut menempel.
// ================================================================
const rumusAktif = ref(null); // { id, kolom: 'sisa'|'bawaan'|'total'|'terpakai'|'saldo' }

const toggleRumus = (p, kolom) => {
    // Pindah ke kolom lain: seleksi (sorot dampak) kotak sebelumnya dilepas.
    fokusEdit.value = null;

    const a = rumusAktif.value;
    rumusAktif.value =
        a?.id === p.id && a?.kolom === kolom ? null : { id: p.id, kolom };
};

const rumusDibuka = (p, kolom) =>
    rumusAktif.value?.id === p.id && rumusAktif.value?.kolom === kolom;

// Kolom sumber (kunci sel) untuk tiap hasil hitungan.
const SUMBER_RUMUS = {
    sisa: ["hak", "terpakai"],
    bawaan: ["sisa_n2", "sisa_n1"],
    total: ["tgh_n1", "bawaan", "hak"],
    terpakai: ["tgh_n0"],
    saldo: ["total", "terpakai"],
};

// Kunci sel untuk angka hasil yang diklik (target).
const KUNCI_TARGET_RUMUS = {
    sisa: "sisa_n0",
    bawaan: "bawaan",
    total: "total",
    terpakai: "terpakai",
    saldo: "saldo",
};

// Warna per hasil hitungan. Ditulis literal supaya terbaca Tailwind.
//   dasar       = warna kolom saat tidak disorot
//   pilih       = kolom yang diklik (terisi penuh)
//   sumber      = kolom yang masuk rumus (warna kolom yang diklik)
//   lencana     = bulatan tanda + / −
//   header      = warna header kolom sumber; headerPilih = header kolom target
const WARNA_HASIL = {
    sisa: {
        dasar: "bg-sky-100 text-sky-800 border-sky-300/80",
        pilih: "bg-sky-600 text-white border-sky-700 ring-2 ring-offset-1 ring-sky-600",
        sumber: "bg-sky-200 text-sky-900 border-sky-500 ring-2 ring-offset-1 ring-sky-500",
        lencana: "bg-sky-600 text-white",
        header: "bg-sky-100",
        headerPilih: "bg-sky-200",
    },
    bawaan: {
        dasar: "bg-emerald-100 text-emerald-800 border-emerald-300/80",
        pilih: "bg-emerald-600 text-white border-emerald-700 ring-2 ring-offset-1 ring-emerald-600",
        sumber: "bg-emerald-200 text-emerald-900 border-emerald-500 ring-2 ring-offset-1 ring-emerald-500",
        lencana: "bg-emerald-600 text-white",
        header: "bg-emerald-100",
        headerPilih: "bg-emerald-200",
    },
    total: {
        dasar: "bg-indigo-100 text-indigo-800 border-indigo-300/80",
        pilih: "bg-indigo-600 text-white border-indigo-700 ring-2 ring-offset-1 ring-indigo-600",
        sumber: "bg-indigo-200 text-indigo-900 border-indigo-500 ring-2 ring-offset-1 ring-indigo-500",
        lencana: "bg-indigo-600 text-white",
        header: "bg-indigo-100",
        headerPilih: "bg-indigo-200",
    },
    terpakai: {
        dasar: "bg-rose-100 text-rose-800 border-rose-300/80",
        pilih: "bg-rose-600 text-white border-rose-700 ring-2 ring-offset-1 ring-rose-600",
        sumber: "bg-rose-200 text-rose-900 border-rose-500 ring-2 ring-offset-1 ring-rose-500",
        lencana: "bg-rose-600 text-white",
        header: "bg-rose-100",
        headerPilih: "bg-rose-200",
    },
    saldo: {
        dasar: "bg-slate-800 text-white border-slate-900",
        pilih: "bg-slate-800 text-white border-slate-900 ring-2 ring-offset-1 ring-slate-900",
        sumber: "bg-amber-200 text-amber-900 border-amber-500 ring-2 ring-offset-1 ring-amber-500",
        lencana: "bg-amber-600 text-white",
        header: "bg-amber-100",
        headerPilih: "bg-slate-300",
    },
};

// Kolom input (Sisa N-2, Sisa N-1, Tgh, Hak) TIDAK berwarna: putih netral.
// Warnanya baru muncul sebagai kolom sumber saat ada rumus yang disorot.
const KELAS_INPUT_NETRAL = "bg-white text-slate-700 border-slate-300";

// Tanda operasi di atas kolom sumber. Bawaan sengaja kosong karena
// aturannya bukan penjumlahan (dijelaskan lewat tooltip).
const OPERATOR_SUMBER = {
    sisa: { hak: "+", terpakai: "−" },
    total: { tgh_n1: "+", bawaan: "+", hak: "+" },
    terpakai: { tgh_n0: "+" },
    saldo: { total: "+", terpakai: "−" },
};

// ================================================================
// FITUR: SOROT DAMPAK EDIT (ARAH KEBALIKAN DARI SOROT RUMUS)
// Sorot rumus menjawab "angka ini dihitung dari kolom mana?". Fitur ini
// menjawab pertanyaan yang muncul saat admin klik edit: "kalau saya ubah
// kolom ini, kolom mana yang ikut berubah?".
//
// Saat baris diedit dan satu kotak input sedang difokus ('fokusEdit'):
//   - kotak yang difokus tetap normal,
//   - kolom yang ikut berubah diberi cincin ORANYE + lencana "Δ", header-nya
//     berwarna oranye, dan tidak diredupkan,
//   - kolom yang tidak terkait meredup.
//
// Fokus dilacak lewat 'focusin' pada atribut data-edit-field. Fitur ini
// BERJALAN BERSAMAAN dengan sorot rumus.
//
// PETA DAMPAK (harus selaras dengan rumus di atas dan previewEdit):
//   Sisa N-2 / Sisa N-1 -> Bawaan -> Total -> Saldo Akhir
//   Tgh N-1             -> Total -> Saldo Akhir
//   Hak                 -> Sisa N, Total -> Saldo Akhir
//   Tgh N               -> Terpakai -> Sisa N, Saldo Akhir
//   Tgh N-2             -> (hanya catatan, tidak memengaruhi kolom lain)
// Kalau rumus di backend/previewEdit berubah, UBAH PETA INI JUGA.
// ================================================================
const DAMPAK_EDIT = {
    sisa_n2: ["bawaan", "total", "saldo"],
    tgh_n2: [],
    sisa_n1: ["bawaan", "total", "saldo"],
    tgh_n1: ["total", "saldo"],
    tgh_n0: ["terpakai", "sisa_n0", "saldo"],
    hak: ["sisa_n0", "total", "saldo"],
};

// Cincin oranye untuk kolom yang terdampak (literal supaya terbaca Tailwind).
const KELAS_DAMPAK = "ring-2 ring-offset-1 ring-orange-400";

// Nama field draft yang sedang difokus pada baris yang diedit.
const fokusEdit = ref(null);

// 'fokus' | 'dampak' | 'redup' | null untuk satu sel di baris pegawai p.
const statusDampak = (p, key) => {
    if (!isEditing(p) || !fokusEdit.value) return null;
    if (key === fokusEdit.value) return "fokus";
    return DAMPAK_EDIT[fokusEdit.value]?.includes(key) ? "dampak" : "redup";
};

// Lacak kotak input yang sedang difokus (tabel & kartu HP memakai atribut
// data-edit-field yang sama).
// Setiap kali kotak input berpindah fokus (klik atau Tab), seleksi lama
// DITUTUP dan hanya kotak baru yang aktif: sorotan dampak pindah ke kotak
// yang baru difokus, sorotan rumus sebelumnya ditutup.
const lacakFokusEdit = (e) => {
    if (editingId.value === null) return;
    const field = e.target?.getAttribute?.("data-edit-field");
    if (!field) return;
    fokusEdit.value = field;
    rumusAktif.value = null;
};

// Keluar dari mode edit = sorotan dampak ikut hilang.
watch(editingId, (id) => {
    if (id === null) fokusEdit.value = null;
});
// ================= END FITUR SOROT DAMPAK EDIT =================

// 'pilih' | 'sumber' | 'redup' | null untuk satu sel di baris pegawai p.
const statusSel = (p, key) => {
    const a = rumusAktif.value;
    if (!a || a.id !== p.id) return null;
    if (KUNCI_TARGET_RUMUS[a.kolom] === key) return "pilih";
    if (SUMBER_RUMUS[a.kolom].includes(key)) return "sumber";
    return "redup";
};

// Warna sel: warna target/sumber kalau sedang disorot, kalau tidak warna dasarnya.
// Kolom yang terdampak edit (dan tidak sedang menjadi target/sumber rumus)
// diberi cincin oranye.
const kelasSel = (p, key, dasar) => {
    const s = statusSel(p, key);
    const a = rumusAktif.value;
    if (s === "pilih") return WARNA_HASIL[a.kolom].pilih;
    if (s === "sumber") return WARNA_HASIL[a.kolom].sumber;
    if (statusDampak(p, key) === "dampak") return `${dasar} ${KELAS_DAMPAK}`;
    return dasar;
};

// Hanya warna sorotan (tanpa warna dasar). Dipakai kotak INPUT yang sedang
// diedit supaya warna sumber/target tetap terlihat. Kosong kalau tidak disorot.
const kelasSorot = (p, key) => {
    const s = statusSel(p, key);
    const a = rumusAktif.value;
    if (s === "pilih") return WARNA_HASIL[a.kolom].pilih;
    if (s === "sumber") return WARNA_HASIL[a.kolom].sumber;
    if (statusDampak(p, key) === "dampak") return KELAS_DAMPAK;
    return "";
};

// Sel yang tidak ikut rumus meredup. Sel yang terdampak edit (atau yang
// sedang difokus) tidak diredupkan; sel di luar dampak edit meredup lebih
// ringan supaya tetap bisa diklik.
const kelasRedup = (p, key) => {
    const r = statusSel(p, key);
    const d = statusDampak(p, key);
    if (r === "pilih" || r === "sumber") return "";
    if (d === "dampak" || d === "fokus") return "";
    if (r === "redup") return "opacity-30";
    if (d === "redup") return "opacity-40";
    return "";
};

// Header kolom ikut berwarna (sama untuk semua baris). Selalu mengembalikan
// background PENUH (tanpa opacity) karena header menempel (sticky) di atas
// isi tabel; kalau transparan, isi baris akan tembus ke header.
// Urutan: warna rumus (target/sumber) -> warna dampak edit (kotak difokus
// hijau, kolom terdampak oranye) -> redup bila ada sorotan lain.
const kelasHeader = (key) => {
    const a = rumusAktif.value;
    if (a) {
        if (KUNCI_TARGET_RUMUS[a.kolom] === key)
            return WARNA_HASIL[a.kolom].headerPilih;
        if (SUMBER_RUMUS[a.kolom].includes(key))
            return WARNA_HASIL[a.kolom].header;
    }

    const f = fokusEdit.value;
    const sedangEdit = editingId.value !== null && Boolean(f);
    if (sedangEdit) {
        if (key === f) return "bg-emerald-100";
        if (DAMPAK_EDIT[f]?.includes(key)) return "bg-orange-100";
    }

    if (a || sedangEdit) return "bg-slate-50 text-slate-300";
    return "bg-slate-50";
};

// Teks operasi (+ / −) untuk kolom sumber, atau null.
const lencanaOp = (p, key) => {
    const a = rumusAktif.value;
    if (statusSel(p, key) !== "sumber") return null;
    return OPERATOR_SUMBER[a.kolom]?.[key] ?? null;
};

// Bulatan kecil di atas kolom: tanda +/− untuk kolom sumber rumus, atau
// "Δ" oranye untuk kolom yang ikut berubah saat kotak input diedit.
// Taruh di dalam elemen 'relative' (<td class="relative"> atau pembungkus
// kartu). 'atas' = posisi vertikal.
const LencanaOp = (propsLencana) => {
    const posisi = `absolute left-1/2 ${propsLencana.atas || "top-0.5"} z-10 flex h-4 w-4 -translate-x-1/2 items-center justify-center rounded-full text-[11px] font-bold leading-none pointer-events-none`;

    const teks = lencanaOp(propsLencana.p, propsLencana.kunci);
    if (teks) {
        return h(
            "span",
            {
                class: `${posisi} ${WARNA_HASIL[rumusAktif.value.kolom].lencana}`,
                "aria-hidden": "true",
            },
            teks,
        );
    }

    // Kolom yang bukan sumber rumus tapi ikut berubah karena edit.
    const s = statusSel(propsLencana.p, propsLencana.kunci);
    if (
        s !== "sumber" &&
        s !== "pilih" &&
        statusDampak(propsLencana.p, propsLencana.kunci) === "dampak"
    ) {
        return h(
            "span",
            {
                class: `${posisi} bg-orange-500 text-white`,
                title: "Ikut berubah saat kolom yang diedit diubah",
                "aria-hidden": "true",
            },
            "Δ",
        );
    }

    return null;
};
LencanaOp.props = ["p", "kunci", "atas"];

// Kartu HP: kunci stat memakai '-' (mis. 'sisa-n2'), kunci rumus memakai '_'.
const kunciRumus = (key) => key.replace(/-/g, "_");

// Kartu HP: stat hasil hitungan yang bisa diklik -> kolom rumusnya.
const KOLOM_RUMUS_KARTU = {
    "sisa-n0": "sisa",
    bawaan: "bawaan",
    total: "total",
    terpakai: "terpakai",
};

// Klik di luar area rumus (angka hasil / kapsul & input di baris) menutup
// sorotan. Memakai composedPath() karena saat kapsul diklik ia langsung
// berganti jadi <input>; elemen lamanya sudah lepas dari DOM sehingga
// closest() akan keliru menganggapnya "di luar".
const tutupRumusSaatKlikLuar = (e) => {
    if (!rumusAktif.value) return;
    const didalamArea = e
        .composedPath()
        .some((n) => n instanceof Element && n.hasAttribute("data-rumus-area"));
    if (!didalamArea) rumusAktif.value = null;
};
// ================= END FITUR SOROT RUMUS =================

// Isi draft dari data pegawai, lalu simpan kondisinya sebagai baseline
// (isDirty = false sampai admin benar-benar mengubah isian).
const isiDraftDariPegawai = (p) => {
    saldoForm.clearErrors();
    Object.assign(draft, {
        sisa_n2: getSisaDuaTahunLalu(p),
        tgh_n2: getSisaDitangguhkanN2(p),
        sisa_n1: getSisaTahunLalu(p),
        tgh_n1: getSisaDitangguhkanN1(p),
        tgh_n0: getSisaDitangguhkanTahunIni(p),
        hak: getSisaTahunIni(p),
    });
    snapshotDraft.value = JSON.stringify(draft);
};

// Ubah satu isian draft.
const ubahDraft = (field, nilai) => {
    draft[field] = nilai;
};

// Fokus ke kotak yang sedang terlihat (tabel & kartu sama-sama ada di DOM,
// salah satunya disembunyikan CSS, jadi pilih yang offsetParent-nya ada).
const fokuskan = async (field) => {
    await nextTick();
    const el = [
        ...document.querySelectorAll(`[data-edit-field="${field}"]`),
    ].find((e) => e.offsetParent !== null);
    el?.focus();
};

// Masuk mode edit untuk satu baris. Kalau ada baris lain yang sedang
// diedit, perubahannya dibuang (hanya satu baris aktif). Kalau baris ini
// sudah diedit, hanya pindah fokus.
// Kotak yang diklik langsung menjadi fokus sorotan dampak edit (menggantikan
// seleksi kotak sebelumnya).
const mulaiEdit = (pegawai, field = "hak") => {
    if (isSavingSaldo.value) return;
    // Klik kotak baru = sorotan rumus sebelumnya ditutup.
    rumusAktif.value = null;
    if (!isEditing(pegawai)) {
        editingId.value = pegawai.id;
        clearTimeout(timerPesanSukses);
        pesanSukses.value = "";
        isiDraftDariPegawai(pegawai);
    }
    fokusEdit.value = field;
    fokuskan(field);
};

// Keluar dari mode edit tanpa menyimpan (sorotan ikut ditutup).
const batalEdit = () => {
    if (isSavingSaldo.value) return;
    editingId.value = null;
    rumusAktif.value = null;
    fokusEdit.value = null;
    saldoForm.clearErrors();
};

// Setelah simpan, baris kembali ke mode tampil. Data terbaru datang dari
// props (diperbarui Inertia), sehingga angka di baris ikut berubah.
// [TERPAKAI DIKUNCI] 'koreksi_saldo' & 'pakai_sistem' tidak dikirim lagi.
const simpanSaldo = (pegawai) => {
    if (!pegawai || !isEditing(pegawai) || isSavingSaldo.value) return;
    if (!isDirty.value) return;

    const namaPegawai = pegawai.nama;
    const idAktif = pegawai.id;

    saldoForm
        .transform(() => ({
            hak_tahun_ini: angka(draft.hak),
            carry_forward_normal: angka(draft.sisa_n1),
            sisa_cuti_dua_tahun_lalu: angka(draft.sisa_n2),
            sisa_ditangguhkan_dua_tahun_lalu: angka(draft.tgh_n2),
            sisa_ditangguhkan_tahun_lalu: angka(draft.tgh_n1),
            sisa_ditangguhkan_tahun_ini: angka(draft.tgh_n0),
            tahun: tahunAktif.value,
        }))
        .put(route("admin.rekap-kuota.update-saldo", idAktif), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: async () => {
                await nextTick();
                if (editingId.value === idAktif) {
                    editingId.value = null;
                }
                // Simpan berhasil: sorotan rumus & dampak ikut ditutup.
                rumusAktif.value = null;
                fokusEdit.value = null;
                saldoForm.clearErrors();
                pesanSukses.value = `Saldo ${namaPegawai} tahun ${tahunAktif.value} berhasil disimpan.`;
                clearTimeout(timerPesanSukses);
                timerPesanSukses = setTimeout(
                    () => (pesanSukses.value = ""),
                    5000,
                );
            },
        });
};

// Tahun berganti / halaman atau hasil filter berganti: baris yang sedang
// diedit mungkin sudah tidak ada, jadi mode edit dibatalkan.
watch(filterTahun, () => {
    editingId.value = null;
    rumusAktif.value = null;
    fokusEdit.value = null;
    saldoForm.clearErrors();
});

watch(
    () => props.dataPegawai?.data,
    () => {
        if (
            rumusAktif.value &&
            !daftarPegawaiHalamanIni.value.some(
                (p) => p.id === rumusAktif.value.id,
            )
        ) {
            rumusAktif.value = null;
        }
        if (editingId.value !== null && !pegawaiEdit.value) {
            editingId.value = null;
            saldoForm.clearErrors();
        }
    },
);
// ================= END FITUR EDIT SALDO INLINE =================

// ================================================================
// KARTU PEGAWAI (TAMPILAN MOBILE / TABLET)
// Di layar di bawah breakpoint 'lg' (1024px), tabel 14 kolom diganti kartu per
// pegawai supaya tidak perlu geser horizontal. Angkanya TETAP memakai
// helper yang sama dengan tabel (satu sumber logika).
// 'field' = nama field draft bila kotak itu bisa diedit inline.
// 'kunci' = kotak dikunci (hanya tampil), yaitu Terpakai.
//
// URUTAN SAMA DENGAN TABEL: Sisa tahun ini (hitungan otomatis) dan Tgh tahun
// ini (bagian dari Terpakai) diletakkan tepat di samping Tgh tahun lalu,
// sebelum Bawaan.
//
// WARNA: kolom input (Sisa N-2, Sisa N-1, Tgh, Hak) putih netral, dan kolom
// hasil hitungan memakai warna dari WARNA_HASIL (sama dengan tabel).
// ================================================================
const getStatKartu = (pegawai) => {
    const t = tahunAktif.value;
    const akhirTahun = (tahun) => String(tahun).slice(-2);
    const netral = KELAS_INPUT_NETRAL;

    return [
        {
            key: "sisa-n2",
            label: `Sisa ${t - 2}`,
            nilai: nilaiSisaN2(pegawai),
            kelas: netral,
            labelKelas: "text-slate-500",
            field: "sisa_n2",
            max: 12,
        },
        {
            key: "tgh-n2",
            label: `Tgh '${akhirTahun(t - 2)}`,
            nilai: nilaiTghN2(pegawai),
            kelas: netral,
            labelKelas: "text-slate-500",
            field: "tgh_n2",
            title: KETERANGAN_TGH_N2,
        },
        {
            key: "sisa-n1",
            label: `Sisa ${t - 1}`,
            nilai: nilaiSisaN1(pegawai),
            kelas: netral,
            labelKelas: "text-slate-500",
            field: "sisa_n1",
            max: 12,
        },
        {
            key: "tgh-n1",
            label: `Tgh '${akhirTahun(t - 1)}`,
            nilai: nilaiTghN1(pegawai),
            kelas: netral,
            labelKelas: "text-slate-500",
            field: "tgh_n1",
            title: KETERANGAN_TGH_N1,
        },
        {
            // Hitungan otomatis (Hak - Terpakai), tanpa 'field' = tidak bisa
            // diedit. Bisa diklik untuk menyorot rumusnya.
            key: "sisa-n0",
            label: `Sisa ${t}`,
            nilai: nilaiSisaTahunIni(pegawai),
            kelas: WARNA_HASIL.sisa.dasar,
            labelKelas: "text-slate-500",
            title: KETERANGAN_SISA_TAHUN_INI.value,
        },
        {
            key: "tgh-n0",
            label: `Tgh '${akhirTahun(t)}`,
            nilai: nilaiTghN0(pegawai),
            kelas: netral,
            labelKelas: "text-slate-500",
            field: "tgh_n0",
            title: KETERANGAN_TGH_TAHUN_INI.value,
        },
        {
            key: "bawaan",
            label: `Bawaan ${t}`,
            nilai: nilaiBawaan(pegawai),
            kelas: WARNA_HASIL.bawaan.dasar,
            labelKelas: "text-slate-500",
            title: KETERANGAN_BAWAAN.value,
        },
        {
            key: "hak",
            label: `Hak ${t}`,
            nilai: nilaiHak(pegawai),
            kelas: netral,
            labelKelas: "text-slate-500",
            field: "hak",
            max: 365,
        },
        {
            key: "total",
            label: `Total ${t}`,
            nilai: nilaiTotal(pegawai),
            kelas: WARNA_HASIL.total.dasar,
            labelKelas: "text-slate-500",
        },
        {
            // [TERPAKAI DIKUNCI] tanpa 'field': hanya tampil. Diketuk untuk
            // menyorot sumbernya (Tgh tahun ini).
            key: "terpakai",
            label: `Terpakai ${t}`,
            nilai: nilaiTerpakai(pegawai),
            kelas: WARNA_HASIL.terpakai.dasar,
            labelKelas: "text-slate-500",
            bintang: !isEditing(pegawai) && adaKoreksi(pegawai),
            kunci: true,
            title: titleTerpakai(pegawai),
        },
    ];
};

// State untuk Modal Rincian & Riwayat Cuti (hanya-baca, tanpa form edit)
const showModal = ref(false);
const selectedPegawai = ref(null);

// Menyimpan ID riwayat cuti yang sedang di-expand (dibuka rinciannya)
const expandedRiwayatId = ref(null);

// ================================================================
// HELPER DURASI (dipakai filter riwayat & kartu riwayat)
// ================================================================
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

// ================================================================
// FILTER RIWAYAT DI MODAL RINCIAN
// Membantu Admin HR memverifikasi angka Terpakai sebelum mengoreksi
// (koreksi dilakukan per bulan di Rekap Laporan):
//   - Chip status (default: Disetujui, yang menjadi dasar hitungan Terpakai)
//   - "Hanya Cuti Tahunan" (default aktif; jenis lain tidak mengurangi kuota)
//   - Ringkasan total hari Cuti Tahunan disetujui di tahun aktif, dibanding
//     angka sistem. Hari lintas tahun memakai 'hari_di_tahun_ini' dari backend.
//
// 'Ditangguhkan' (dibatalkan_ditangguhkan: dilakukan atasan, masuk Tgh) dan
// 'Dibatalkan' (dibatalkan_reguler: dilakukan pemohon, hari dikembalikan)
// adalah DUA chip terpisah. Key chip = nilai status asli, jadi
// cocokStatusRiwayat cukup membandingkan langsung.
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
// (Angka sistem di sini = cuti disetujui saja, belum termasuk Tgh.)
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
    expandedRiwayatId.value = null; // Reset saat buka pegawai baru
    filterStatusRiwayat.value = "disetujui";
    hanyaCutiTahunan.value = true;
    showModal.value = true;
};

const closeModal = () => {
    showModal.value = false;
    selectedPegawai.value = null;
    expandedRiwayatId.value = null;
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
    dibatalkan_ditangguhkan: "Ditangguhkan",
    dibatalkan_reguler: "Dibatalkan",
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

// ================================================================
// TOOLBAR SEARCH + FILTER (disamakan dengan Rekap Laporan)
// - Dropdown filter KUSTOM (Kelompok Substansi & Tahun) menggantikan
//   <select> bawaan browser: selalu terbuka ke bawah, selebar kotaknya,
//   tinggi dibatasi (bisa di-scroll), teks panjang membungkus.
// - Hanya satu dropdown terbuka sekaligus; menutup saat klik di luar
//   atau menekan Esc.
// - Chip "Filter aktif" + tombol "Hapus semua" untuk search & kelompok
//   (tahun tidak dihitung filter aktif karena selalu punya nilai).
// ================================================================
const toolbarRef = ref(null);
const dropdownTerbuka = ref(null); // 'kelompok' | 'tahun' | null

const toggleDropdown = (nama) => {
    dropdownTerbuka.value = dropdownTerbuka.value === nama ? null : nama;
};

const pilihKelompok = (nilai) => {
    filterKelompok.value = nilai;
    dropdownTerbuka.value = null;
};

const pilihTahun = (nilai) => {
    filterTahun.value = Number(nilai);
    dropdownTerbuka.value = null;
};

// Nilai "-" dari backend (pegawai tanpa kelompok) ditampilkan sebagai
// "Tanpa kelompok"; nilai yang dikirim ke backend tetap "-".
const tampilKelompok = (k) => (k === "-" ? "Tanpa kelompok" : k);

const labelKelompok = computed(() =>
    filterKelompok.value
        ? tampilKelompok(filterKelompok.value)
        : "Semua Kelompok Substansi",
);

const tutupDropdownSaatKlikLuar = (event) => {
    if (toolbarRef.value && !toolbarRef.value.contains(event.target)) {
        dropdownTerbuka.value = null;
    }
};

// Tombol Esc menutup modal yang sedang terbuka (paling atas dulu).
// Kalau tidak ada modal: Esc membatalkan mode edit inline (sekaligus
// menutup sorotan rumus & dampak); kalau tidak sedang edit, Esc menutup
// sorotan.
const tutupModalSaatEsc = (event) => {
    if (event.key !== "Escape") return;
    if (isGenerateSaldoModalOpen.value) closeGenerateSaldoModal();
    else if (isImportModalOpen.value) closeImportModal();
    else if (showModal.value) closeModal();
    else if (editingId.value !== null) batalEdit();
    else if (rumusAktif.value) rumusAktif.value = null;
};

onMounted(() => {
    document.addEventListener("click", tutupDropdownSaatKlikLuar);
    document.addEventListener("click", tutupRumusSaatKlikLuar);
    document.addEventListener("keydown", tutupModalSaatEsc);
    document.addEventListener("focusin", lacakFokusEdit);
});
onBeforeUnmount(() => {
    document.removeEventListener("click", tutupDropdownSaatKlikLuar);
    document.removeEventListener("click", tutupRumusSaatKlikLuar);
    document.removeEventListener("keydown", tutupModalSaatEsc);
    document.removeEventListener("focusin", lacakFokusEdit);
    clearTimeout(timerPesanSukses);
    clearTimeout(timerPesanImpor);
    // Pastikan scroll halaman tidak tertinggal terkunci
    document.body.style.overflow = "";
});

const isFilterAktif = computed(
    () => Boolean(search.value) || Boolean(filterKelompok.value),
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
    if (filterKelompok.value) {
        chips.push({
            key: "kelompok",
            label: tampilKelompok(filterKelompok.value),
            clear: () => {
                filterKelompok.value = "";
            },
        });
    }
    return chips;
});

// Reset search & kelompok (tahun dibiarkan). Pengambilan data ulang
// dilakukan otomatis oleh watch([search, filterKelompok, filterTahun]).
const resetFilters = () => {
    search.value = "";
    filterKelompok.value = "";
};

// Ada baris di halaman ini yang angka Terpakai-nya dikoreksi Admin HR?
// (dipakai untuk catatan kaki di bawah tabel)
const adaItemDikoreksi = computed(() =>
    daftarPegawaiHalamanIni.value.some(adaKoreksi),
);

// Navigasi pagination (Laravel paginator links)
const goToPage = (url) => {
    if (!url) return;
    router.get(url, {}, { preserveState: true, preserveScroll: true });
};

// Paginasi ringkas untuk HP: hanya Sebelumnya / Berikutnya. Link pertama
// dan terakhir dari paginator Laravel selalu "Previous" dan "Next".
const linkSebelumnya = computed(() => props.dataPegawai?.links?.[0] ?? null);
const linkBerikutnya = computed(() => {
    const links = props.dataPegawai?.links;
    return links?.length ? links[links.length - 1] : null;
});

// ========================================== //
// ---> FITUR: IMPOR KUOTA CUTI <---
// ========================================== //
// Modal terpisah dari modal Rincian Riwayat, dengan useForm terpisah
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
// 'saldo-cuti:generate-tahun-baru'. Tidak ada jadwal otomatis;
// dompet tahun baru hanya dibuat lewat tombol ini.
//
// Memakai modal DUA LANGKAH karena ini aksi massal untuk SEMUA pegawai:
//   Langkah 1 : pilih tahun + penjelasan logika
//   Langkah 2 : dialog konfirmasi akhir ("Ya, Generate Saldo {tahun}")
// Request baru dikirim setelah langkah 2 dikonfirmasi.
//
// TAHUN TUJUAN DINAMIS: mengikuti tahun yang sedang dilihat di Filter
// Tahun, yaitu 'tahunAktif + 1'.
//   - Lihat 2026 -> tombol "Generate Saldo 2027"
//   - Lihat 2027 -> tombol "Generate Saldo 2028"
// Tombol HANYA aktif kalau tahun tujuan sama dengan tahun kalender depan
// (currentYear + 1) — batas yang sama dengan validasi backend. Jadi saat
// admin melihat 2027 (tujuan 2028), tombol tetap tampil dengan label yang
// benar tetapi terkunci, dengan alasan yang dijelaskan di bawah judul.
const isGenerateSaldoModalOpen = ref(false);
// false = langkah 1 (pilih tahun), true = langkah 2 (konfirmasi akhir)
const langkahKonfirmasiGenerate = ref(false);

// Tahun yang akan digenerate = tahun yang sedang dilihat + 1.
const tahunTujuanGenerate = computed(() => tahunAktif.value + 1);

// Satu-satunya tahun yang diizinkan digenerate: tahun kalender depan.
// Batas sebenarnya tetap divalidasi di backend.
const TAHUN_GENERATE_DIIZINKAN = currentYear + 1;

const generateSaldoForm = useForm({
    tahun: TAHUN_GENERATE_DIIZINKAN,
});

// Opsi dropdown di modal (dibaca template lewat v-for). Hanya berisi tahun
// tujuan yang sedang aktif. Kalau suatu saat tahun berjalan perlu dibuka,
// tambahkan currentYear ke daftar ini dan ke aturan 'tahunTujuanDiizinkan'
// (peringatan di modal akan muncul otomatis).
const pilihanTahunGenerate = computed(() => [tahunTujuanGenerate.value]);

// Tahun tujuan sesuai tahun yang boleh digenerate?
const tahunTujuanDiizinkan = computed(
    () => tahunTujuanGenerate.value === TAHUN_GENERATE_DIIZINKAN,
);

// ---- Waktu yang diizinkan ----
// Generate tahun depan baru dibuka mulai bulan ini (1 = Januari ...
// 12 = Desember). Ubah angka ini bila aturan biro berbeda (mis. 12 kalau
// hanya boleh pada Desember). Batas yang sama HARUS ada juga di backend
// (AdminController::generateSaldoManual), karena jam browser bisa diubah
// pengguna; pengecekan di sini hanya pengaman tampilan.
const BULAN_BUKA_GENERATE = 12;
const NAMA_BULAN_PANJANG = [
    "Januari",
    "Februari",
    "Maret",
    "April",
    "Mei",
    "Juni",
    "Juli",
    "Agustus",
    "September",
    "Oktober",
    "November",
    "Desember",
];

// Periode generate (pengaman tampilan; server juga mengecek).
const dalamPeriodeGenerate = new Date().getMonth() + 1 >= BULAN_BUKA_GENERATE;

// Tombol aktif kalau:
//   1) tahun tujuan = tahun kalender depan,
//   2) periodenya sudah dibuka, DAN
//   3) masih ada pegawai aktif yang belum punya dompet tahun depan.
const generateDibuka = computed(
    () =>
        tahunTujuanDiizinkan.value &&
        dalamPeriodeGenerate &&
        !props.sudahGenerateTahunDepan,
);

const alasanGenerateTutup = computed(() => {
    const tujuan = tahunTujuanGenerate.value;

    // Tahun yang dilihat terlalu jauh ke depan (mis. lihat 2027, tujuan 2028)
    if (tujuan > TAHUN_GENERATE_DIIZINKAN) {
        return `Saldo ${tujuan} belum bisa digenerate. Baru dibuka mulai 1 ${NAMA_BULAN_PANJANG[BULAN_BUKA_GENERATE - 1]} ${tujuan - 1}.`;
    }

    // Tahun yang dilihat sudah lewat (tujuan sudah berlalu)
    if (tujuan < TAHUN_GENERATE_DIIZINKAN) {
        return `Saldo ${tujuan} sudah melewati periode generate. Generate hanya untuk tahun ${TAHUN_GENERATE_DIIZINKAN}.`;
    }

    if (!dalamPeriodeGenerate) {
        return `Generate saldo ${tujuan} baru dibuka mulai 1 ${NAMA_BULAN_PANJANG[BULAN_BUKA_GENERATE - 1]} ${currentYear}.`;
    }

    return `Semua pegawai aktif sudah punya saldo ${tujuan}.`;
});

const openGenerateSaldoModal = () => {
    if (!generateDibuka.value) return;
    generateSaldoForm.tahun = tahunTujuanGenerate.value;
    generateSaldoForm.clearErrors();
    langkahKonfirmasiGenerate.value = false;
    isGenerateSaldoModalOpen.value = true;
};

const closeGenerateSaldoModal = () => {
    isGenerateSaldoModalOpen.value = false;
    langkahKonfirmasiGenerate.value = false;
};

const lanjutKonfirmasiGenerate = () => {
    generateSaldoForm.clearErrors();
    langkahKonfirmasiGenerate.value = true;
};

const submitGenerateSaldo = () => {
    if (!generateDibuka.value || generateSaldoForm.processing) return;

    // Pastikan tahun yang dikirim selalu sama dengan label tombol.
    generateSaldoForm.tahun = tahunTujuanGenerate.value;

    generateSaldoForm.post(route("admin.saldo.generate"), {
        preserveScroll: true,
        onSuccess: () => closeGenerateSaldoModal(),
        // Error tahun tampil di langkah 1, jadi kembalikan ke sana
        onError: () => {
            langkahKonfirmasiGenerate.value = false;
        },
    });
};
// ================= END FITUR GENERATE SALDO TAHUN BARU =================

// ================================================================
// KUNCI SCROLL HALAMAN SAAT MODAL TERBUKA (penting di HP)
// Tanpa ini, menggeser jari di dalam modal ikut menggulir halaman di
// belakangnya. Dipasang paling bawah karena membaca ketiga state modal.
// ================================================================
const adaModalTerbuka = computed(
    () =>
        showModal.value ||
        isImportModalOpen.value ||
        isGenerateSaldoModalOpen.value,
);

watch(adaModalTerbuka, (terbuka) => {
    document.body.style.overflow = terbuka ? "hidden" : "";
});
</script>

<template>
    <Head title="Rekap Kuota Detail Cuti" />

    <MainLayout>
        <div class="relative w-full h-full">
            <!-- Div pembungkus tunggal: MainLayout hanya boleh punya SATU
                 elemen anak langsung agar transisi halaman Inertia/Vue tidak
                 error. Komentar ini sengaja diletakkan DI DALAM div (bukan
                 sejajar di luarnya), supaya root slot tetap satu elemen. -->
            <div class="p-3 sm:p-6 bg-white rounded-xl shadow-sm">
                <!-- Banner sukses Simpan Saldo inline. Hilang sendiri setelah
                     5 detik dan bisa ditutup manual. -->
                <div
                    v-if="pesanSukses"
                    role="status"
                    class="mb-4 flex items-start justify-between gap-3 rounded-lg border border-emerald-200 bg-emerald-50 px-3 sm:px-4 py-3 text-sm text-emerald-800"
                >
                    <p class="min-w-0 break-words">{{ pesanSukses }}</p>
                    <button
                        type="button"
                        @click="pesanSukses = ''"
                        aria-label="Tutup pesan"
                        class="shrink-0 px-1 text-lg leading-none font-bold text-emerald-700 hover:text-emerald-900 cursor-pointer"
                    >
                        &times;
                    </button>
                </div>

                <!-- Banner hasil impor (flash 'success'). Bisa ditutup manual
                     dan hilang sendiri setelah 15 detik. -->
                <div
                    v-if="pesanImpor"
                    role="status"
                    class="mb-4 flex items-start justify-between gap-3 rounded-lg border border-emerald-200 bg-emerald-50 px-3 sm:px-4 py-3 text-sm text-emerald-800"
                >
                    <p class="min-w-0 break-words">{{ pesanImpor }}</p>
                    <button
                        type="button"
                        @click="pesanImpor = ''"
                        aria-label="Tutup pesan"
                        class="shrink-0 px-1 text-lg leading-none font-bold text-emerald-700 hover:text-emerald-900 cursor-pointer"
                    >
                        &times;
                    </button>
                </div>

                <!-- Baris judul: judul + subjudul di kiri, tombol aksi
                 (Impor, Generate Saldo, Export) di kanan pada layar >= lg
                 (1024px), konsisten di semua tingkat zoom. Subjudul boleh
                 membungkus; kalau ruang sangat sempit, tombol turun ke
                 baris kedua tapi tetap rata kanan. Di HP / tablet kecil
                 tombol berada di bawah judul.


                 Skema warna tombol per jenis aksi:
                   - Impor           -> Blue
                   - Generate Saldo  -> Emerald
                   - Export          -> Hijau tua (green-700) -->
                <div
                    class="flex flex-col gap-3 sm:gap-4 mb-4 sm:mb-5 lg:flex-row lg:items-center lg:justify-between"
                >
                    <div class="lg:min-w-[14rem] lg:flex-1">
                        <h2
                            class="text-base sm:text-lg font-bold text-gray-800"
                        >
                            Rekap Kuota Detail Cuti
                        </h2>
                        <p class="text-xs sm:text-sm text-gray-500 mt-0.5">
                            Lihat sisa, edit saldo langsung di baris (klik
                            angkanya), dan riwayat pemakaian kuota cuti tahunan
                            tiap pegawai. Cuti Terpakai hanya tampil di sini;
                            ubah per bulan di Rekap Laporan.
                        </p>
                    </div>

                    <!-- HP: grid 2 kolom (Impor & Generate sejajar, Export
                         selebar penuh di bawahnya). sm: satu baris rata
                         kiri. >= lg: rata kanan di sebelah judul. -->
                    <div
                        class="grid grid-cols-2 gap-2 w-full sm:flex sm:flex-wrap sm:items-center lg:w-auto lg:justify-end"
                    >
                        <!-- Tombol Impor Kuota -->
                        <button
                            type="button"
                            @click="openImportModal"
                            class="inline-flex items-center justify-center gap-1.5 sm:gap-2 min-h-[44px] sm:min-h-0 px-3 sm:px-4 xl:px-5 py-2.5 bg-blue-600 border border-transparent rounded-full font-semibold text-xs sm:text-sm text-white text-center leading-tight sm:whitespace-nowrap hover:bg-blue-700 transition ease-in-out duration-150 shadow-sm cursor-pointer sm:flex-none"
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
                                    d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"
                                />
                            </svg>
                            Impor Kuota
                        </button>

                        <!-- Tombol Generate Saldo Tahun Baru (Manual).
                             Label memuat TAHUN TUJUAN yang DINAMIS (tahun
                             yang sedang dilihat + 1). Nonaktif kalau tahun
                             tujuan bukan tahun kalender depan, di luar waktu
                             yang diizinkan, atau kalau semua pegawai aktif
                             sudah punya saldo tahun depan; alasannya tampil
                             di bawah judul dan sebagai tooltip. -->
                        <button
                            type="button"
                            @click="openGenerateSaldoModal"
                            :disabled="!generateDibuka"
                            :title="
                                generateDibuka
                                    ? `Generate Saldo ${tahunTujuanGenerate} (Manual)`
                                    : alasanGenerateTutup
                            "
                            :aria-describedby="
                                !generateDibuka
                                    ? 'ket-generate-tutup'
                                    : undefined
                            "
                            class="inline-flex items-center justify-center gap-1.5 sm:gap-2 min-h-[44px] sm:min-h-0 px-3 sm:px-4 xl:px-5 py-2.5 bg-emerald-600 border border-transparent rounded-full font-semibold text-xs sm:text-sm text-white text-center leading-tight sm:whitespace-nowrap hover:bg-emerald-700 transition ease-in-out duration-150 shadow-sm cursor-pointer sm:flex-none disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-emerald-600"
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
                                    d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
                                />
                            </svg>
                            Generate Saldo {{ tahunTujuanGenerate }}
                        </button>

                        <!-- Tombol Export — href memakai 'exportUrl' sehingga file
                             yang diunduh mengikuti tahun (& filter) aktif. -->
                        <a
                            :href="exportUrl"
                            class="col-span-2 sm:col-span-1 sm:flex-none min-h-[44px] sm:min-h-0 sm:whitespace-nowrap bg-green-700 hover:bg-green-800 text-white px-3 sm:px-4 xl:px-5 py-2.5 rounded-full text-xs sm:text-sm font-semibold transition inline-flex items-center justify-center gap-2"
                        >
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="w-4 h-4 shrink-0"
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

                <!-- Alasan tombol Generate nonaktif. Tampil kalau tahun
                     tujuan tidak diizinkan, di luar waktu yang diizinkan,
                     DAN saat semua pegawai aktif sudah punya saldo tahun
                     depan. -->
                <p
                    v-if="!generateDibuka"
                    id="ket-generate-tutup"
                    class="-mt-2 mb-4 text-xs text-amber-700 lg:text-right"
                >
                    {{ alasanGenerateTutup }}
                </p>

                <!-- KPI Cards (versi putih). Angka diambil dari prop 'kpi'
                 backend, dihitung dari SELURUH data hasil filter. -->
                <div
                    class="grid grid-cols-2 md:grid-cols-4 gap-2.5 sm:gap-4 mb-4 sm:mb-6"
                >
                    <div
                        class="bg-white border border-slate-200 rounded-xl p-3 sm:p-4 shadow-sm flex flex-col justify-between"
                    >
                        <h4
                            class="text-slate-500 text-[10px] font-bold uppercase tracking-wider mb-1.5 sm:mb-2"
                        >
                            Total Pegawai
                        </h4>
                        <div class="flex items-baseline gap-1">
                            <span
                                class="text-xl sm:text-2xl font-extrabold text-slate-800"
                                >{{ kpiTotalPegawai }}</span
                            >
                        </div>
                    </div>

                    <div
                        class="bg-white border border-slate-200 rounded-xl p-3 sm:p-4 shadow-sm flex flex-col justify-between"
                    >
                        <h4
                            class="text-slate-500 text-[10px] font-bold uppercase tracking-wider mb-1.5 sm:mb-2"
                        >
                            Rata-Rata Terpakai
                        </h4>
                        <div class="flex items-baseline gap-1.5">
                            <span
                                class="text-xl sm:text-2xl font-extrabold text-rose-500"
                                >{{ kpiRataRataTerpakai }}</span
                            >
                            <span
                                class="text-xs sm:text-sm font-medium text-slate-400"
                                >hari</span
                            >
                        </div>
                    </div>

                    <div
                        class="bg-white border border-slate-200 rounded-xl p-3 sm:p-4 shadow-sm flex flex-col justify-between"
                    >
                        <h4
                            class="text-slate-500 text-[10px] font-bold uppercase tracking-wider mb-1.5 sm:mb-2"
                        >
                            Rata-Rata Saldo Akhir
                        </h4>
                        <div class="flex items-baseline gap-1.5">
                            <span
                                class="text-xl sm:text-2xl font-extrabold text-emerald-500"
                                >{{ kpiRataRataSaldoAkhir }}</span
                            >
                            <span
                                class="text-xs sm:text-sm font-medium text-slate-400"
                                >hari</span
                            >
                        </div>
                    </div>

                    <div
                        class="bg-white border border-slate-200 rounded-xl p-3 sm:p-4 shadow-sm flex flex-col justify-between"
                    >
                        <h4
                            class="text-slate-500 text-[10px] font-bold uppercase tracking-wider mb-1.5 sm:mb-2"
                        >
                            Akumulasi Penuh (12/12)
                        </h4>
                        <div class="flex items-baseline gap-1.5">
                            <span
                                class="text-xl sm:text-2xl font-extrabold text-blue-600"
                                >{{ kpiAkumulasiPenuh }}</span
                            >
                            <span
                                class="text-xs sm:text-sm font-medium text-slate-400"
                                >orang</span
                            >
                        </div>
                    </div>
                </div>
                <!-- ================= END KPI CARDS ================= -->

                <!-- ===================== TOOLBAR: SEARCH + FILTER =====================
                     HP / tablet: baris 1 = Cari + Tahun (di HP hanya angka
                     tahun supaya ringkas), baris 2 = Kelompok Substansi
                     selebar penuh. Layar >= lg: satu baris saja:
                     Cari (melebar) | Kelompok Substansi | Tahun.
                     Urutan visual diatur dengan 'order' sehingga urutan tab
                     keyboard tetap: Cari -> Tahun -> Kelompok di HP.
                     Filter memakai dropdown KUSTOM (selalu terbuka ke bawah,
                     bisa di-scroll). Filter yang sedang dipakai diberi
                     border hijau supaya mudah dikenali. -->
                <div
                    ref="toolbarRef"
                    @keydown.esc="dropdownTerbuka = null"
                    class="grid grid-cols-[minmax(0,1fr)_auto] gap-2 mb-3 lg:flex lg:items-center"
                >
                    <!-- Search (dengan tombol hapus saat ada isi) -->
                    <div
                        class="relative order-1 min-w-0 lg:min-w-[12rem] lg:flex-1"
                    >
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
                                    d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
                                />
                            </svg>
                        </div>
                        <input
                            id="cari-kuota"
                            name="cari_kuota"
                            v-model="search"
                            type="text"
                            placeholder="Cari nama / NIP..."
                            aria-label="Cari nama atau NIP pegawai"
                            class="w-full min-h-[44px] lg:min-h-[40px] pl-9 pr-10 py-2 text-base sm:text-sm border focus:border-emerald-500 focus:ring-emerald-500 rounded-lg shadow-sm bg-white"
                            :class="
                                search
                                    ? 'border-emerald-500'
                                    : 'border-gray-300'
                            "
                        />
                        <button
                            v-if="search"
                            type="button"
                            @click="search = ''"
                            aria-label="Hapus pencarian"
                            class="absolute inset-y-0 right-0 flex items-center px-3 text-gray-400 hover:text-gray-600 cursor-pointer"
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
                                    d="M6 18L18 6M6 6l12 12"
                                />
                            </svg>
                        </button>
                    </div>

                    <!-- Filter Tahun (ikon kalender). Default = tahun
                         berjalan. Opsi dari daftarTahunPilihan. Di HP
                         labelnya hanya angka tahun. -->
                    <div
                        class="relative order-2 w-32 sm:w-40 lg:order-3 lg:flex-none"
                    >
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

                        <button
                            id="filter-tahun-kuota"
                            type="button"
                            :aria-label="`Filter tahun, saat ini ${tahunAktif}`"
                            aria-haspopup="listbox"
                            :aria-expanded="dropdownTerbuka === 'tahun'"
                            @click="toggleDropdown('tahun')"
                            class="w-full min-h-[44px] lg:min-h-[40px] text-left text-base sm:text-sm border border-gray-300 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 focus:outline-none rounded-lg shadow-sm text-gray-700 py-2 pl-9 pr-8 bg-white"
                        >
                            <span class="block truncate">
                                <span class="sm:hidden">{{ tahunAktif }}</span>
                                <span class="hidden sm:inline"
                                    >Tahun {{ tahunAktif }}</span
                                >
                            </span>
                        </button>

                        <span
                            class="absolute inset-y-0 right-0 flex items-center pr-2 pointer-events-none"
                        >
                            <svg
                                class="w-3.5 h-3.5 text-gray-500 transition-transform"
                                :class="{
                                    'rotate-180': dropdownTerbuka === 'tahun',
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
                                ></path>
                            </svg>
                        </span>

                        <ul
                            v-if="dropdownTerbuka === 'tahun'"
                            role="listbox"
                            class="absolute right-0 top-full z-30 mt-1 max-h-60 w-full min-w-[8rem] overflow-y-auto overscroll-contain rounded-lg border border-gray-200 bg-white py-1 shadow-lg"
                        >
                            <li
                                v-for="tahun in daftarTahunPilihan"
                                :key="tahun"
                            >
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
                                    class="block w-full px-3 py-2.5 sm:py-2 text-left text-sm"
                                >
                                    Tahun {{ tahun }}
                                </button>
                            </li>
                        </ul>
                    </div>

                    <!-- Filter Kelompok Substansi (HP: baris sendiri penuh) -->
                    <div
                        class="relative order-3 col-span-2 min-w-0 lg:order-2 lg:col-span-1 lg:max-w-[28rem] lg:flex-[1.3]"
                    >
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

                        <button
                            id="filter-kelompok-kuota"
                            type="button"
                            aria-label="Filter kelompok substansi"
                            aria-haspopup="listbox"
                            :aria-expanded="dropdownTerbuka === 'kelompok'"
                            @click="toggleDropdown('kelompok')"
                            class="w-full min-h-[44px] lg:min-h-[40px] text-left text-base sm:text-sm border focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 focus:outline-none rounded-lg shadow-sm py-2 pl-9 pr-8 bg-white"
                            :class="
                                filterKelompok
                                    ? 'border-emerald-500 text-gray-800'
                                    : 'border-gray-300 text-gray-600'
                            "
                        >
                            <span class="block truncate">{{
                                labelKelompok
                            }}</span>
                        </button>

                        <span
                            class="absolute inset-y-0 right-0 flex items-center pr-2 pointer-events-none"
                        >
                            <svg
                                class="w-3.5 h-3.5 text-gray-500 transition-transform"
                                :class="{
                                    'rotate-180':
                                        dropdownTerbuka === 'kelompok',
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
                                ></path>
                            </svg>
                        </span>

                        <ul
                            v-if="dropdownTerbuka === 'kelompok'"
                            role="listbox"
                            class="absolute left-0 right-0 top-full z-30 mt-1 max-h-[min(18rem,50dvh)] overflow-y-auto overscroll-contain rounded-lg border border-gray-200 bg-white py-1 shadow-lg"
                        >
                            <li>
                                <button
                                    type="button"
                                    role="option"
                                    :aria-selected="!filterKelompok"
                                    @click="pilihKelompok('')"
                                    :class="
                                        !filterKelompok
                                            ? 'bg-emerald-50 text-emerald-700 font-semibold'
                                            : 'text-gray-700 hover:bg-gray-50'
                                    "
                                    class="block w-full break-words px-3 py-2.5 sm:py-2 text-left text-sm"
                                >
                                    Semua Kelompok Substansi
                                </button>
                            </li>
                            <li
                                v-for="kelompok in daftarKelompokSubstansi"
                                :key="kelompok"
                            >
                                <button
                                    type="button"
                                    role="option"
                                    :aria-selected="filterKelompok === kelompok"
                                    @click="pilihKelompok(kelompok)"
                                    :class="
                                        filterKelompok === kelompok
                                            ? 'bg-emerald-50 text-emerald-700 font-semibold'
                                            : 'text-gray-700 hover:bg-gray-50'
                                    "
                                    class="block w-full break-words px-3 py-2.5 sm:py-2 text-left text-sm"
                                >
                                    {{ tampilKelompok(kelompok) }}
                                </button>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- ===================== CHIP FILTER AKTIF =====================
                     Hanya dirender kalau ada filter aktif (search / kelompok). -->
                <div
                    v-if="activeFilterChips.length > 0"
                    class="flex flex-wrap items-center gap-2 mb-4 text-xs text-gray-500"
                >
                    <span>Filter aktif:</span>
                    <span
                        v-for="chip in activeFilterChips"
                        :key="chip.key"
                        class="inline-flex max-w-full items-center gap-1.5 bg-emerald-50 text-emerald-700 font-semibold pl-3 pr-2 py-1 rounded-full"
                    >
                        <span class="min-w-0 break-words">{{
                            chip.label
                        }}</span>
                        <button
                            type="button"
                            @click="chip.clear()"
                            class="shrink-0 px-1 text-emerald-600/70 hover:text-emerald-700"
                            aria-label="Hapus filter"
                        >
                            ✕
                        </button>
                    </span>
                    <button
                        type="button"
                        @click="resetFilters"
                        class="text-gray-400 underline hover:text-gray-600 py-1"
                    >
                        Hapus semua
                    </button>
                </div>

                <!-- ===================== STATE KOSONG =====================
                     Satu state kosong untuk semua ukuran layar (dengan tombol
                     reset filter). Tabel & kartu hanya dirender kalau ada data. -->
                <div
                    v-if="!punyaData"
                    class="rounded-lg border border-slate-200 bg-white px-4 sm:px-6 py-12 sm:py-14"
                >
                    <div
                        class="flex flex-col items-center justify-center text-center gap-3"
                    >
                        <div
                            class="w-14 h-14 rounded-2xl bg-slate-100 flex items-center justify-center"
                        >
                            <svg
                                class="w-7 h-7 text-slate-400"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="1.5"
                                    d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"
                                ></path>
                            </svg>
                        </div>
                        <p class="text-sm font-semibold text-gray-600 max-w-sm">
                            Tidak ada data pegawai ditemukan untuk tahun
                            {{ tahunAktif }}.
                        </p>
                        <button
                            v-if="isFilterAktif"
                            type="button"
                            @click="resetFilters"
                            class="mt-1 inline-flex items-center gap-1.5 min-h-[40px] px-4 py-2 bg-white border border-gray-300 rounded-full text-xs font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-800 transition-colors"
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
                                    d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
                                ></path>
                            </svg>
                            Reset Filter
                        </button>
                    </div>
                </div>

                <!-- ===================== KARTU PEGAWAI (HP & TABLET) =====================
                     Tampil di bawah breakpoint 'lg' (1024px). Satu kartu per pegawai:
                     nama + Saldo Akhir di atas, angka rincian di tengah
                     (grid 4 kolom), tombol aksi di bawah.
                     EDIT INLINE: ketuk salah satu angka yang bisa diedit,
                     lalu Sisa N-2, Tgh, Sisa N-1, dan Hak berubah
                     menjadi input; Bawaan, Sisa tahun ini, Total, Terpakai, dan
                     Saldo Akhir menampilkan pratinjau langsung.
                     Ikon pensil di label = kotak bisa diedit (kotak yang
                     punya 'field' di getStatKartu).
                     [TERPAKAI DIKUNCI] Ikon gembok di label Terpakai = hanya
                     tampil; ubah per bulan di Rekap Laporan (ada tautannya).
                     Tanda * = Terpakai dikoreksi Admin HR.
                     SOROT RUMUS: ketuk Sisa tahun ini, Bawaan, Total, Terpakai,
                     atau Saldo Akhir -> kotak sumbernya terisi warna yang sama
                     dan diberi tanda + / −, kotak lain meredup. Sorotan TETAP
                     menyala saat kotak sumber diketuk untuk diedit.
                     SOROT DAMPAK EDIT: saat sebuah kotak difokus untuk diedit,
                     kotak yang ikut berubah diberi cincin oranye + tanda Δ. -->
                <p
                    v-if="punyaData"
                    class="mb-2 text-[11px] leading-relaxed text-slate-500 lg:hidden"
                >
                    <span class="font-semibold text-slate-700">Tgh</span> = cuti
                    ditangguhkan. Tgh tahun {{ tahunAktif }} sudah termasuk
                    dalam Terpakai.
                    <span class="font-semibold text-amber-600">*</span> =
                    Terpakai dikoreksi Admin HR di Rekap Laporan.
                    <span class="inline-block align-middle"
                        ><IkonPensil
                    /></span>
                    = bisa diedit, ketuk angkanya.
                    <span class="inline-block align-middle"
                        ><IkonGembok
                    /></span>
                    = Terpakai dikunci, ubah di Rekap Laporan. Ketuk Sisa
                    {{ tahunAktif }}, Bawaan, Total, Terpakai, atau Saldo Akhir
                    untuk menyorot kolom sumber rumusnya.
                    <span
                        class="inline-flex h-4 w-4 items-center justify-center rounded-full bg-orange-500 align-middle text-[11px] font-bold leading-none text-white"
                        >Δ</span
                    >
                    = ikut berubah saat kotak yang sedang diedit diubah.
                </p>
                <div
                    v-if="punyaData"
                    class="grid grid-cols-1 gap-3 md:grid-cols-2 lg:hidden"
                >
                    <div
                        v-for="(pegawai, index) in dataPegawai.data"
                        :key="pegawai.id"
                        class="rounded-xl border bg-white p-3 shadow-sm transition-colors"
                        :class="
                            isEditing(pegawai)
                                ? 'border-emerald-400 ring-2 ring-emerald-200 bg-emerald-50/30 md:col-span-2'
                                : 'border-slate-200'
                        "
                    >
                        <div class="flex items-start gap-2.5">
                            <span
                                class="flex h-7 min-w-[28px] shrink-0 items-center justify-center rounded-full bg-slate-100 px-1.5 text-xs font-bold text-slate-600"
                            >
                                {{ (dataPegawai.from ?? 1) + index }}
                            </span>

                            <div class="min-w-0 flex-1">
                                <p
                                    class="break-words text-sm font-bold leading-snug text-slate-800"
                                >
                                    {{ pegawai.nama }}
                                </p>
                                <p
                                    class="mt-0.5 break-words text-[11px] leading-snug text-slate-500"
                                >
                                    {{ getKelompok(pegawai) }}
                                </p>
                            </div>

                            <div class="shrink-0 text-center">
                                <p
                                    class="text-[10px] font-bold uppercase leading-tight tracking-wide text-slate-500"
                                >
                                    Saldo Akhir
                                </p>
                                <!-- Saldo Akhir: ketuk untuk menyorot rumus -->
                                <button
                                    type="button"
                                    data-rumus-area
                                    @click="toggleRumus(pegawai, 'saldo')"
                                    :aria-pressed="
                                        rumusDibuka(pegawai, 'saldo')
                                    "
                                    :title="KETERANGAN_KLIK_RUMUS"
                                    class="mt-1 inline-block min-w-[40px] cursor-pointer rounded-md border px-2.5 py-1 text-sm font-extrabold shadow-sm transition"
                                    :class="
                                        kelasSel(
                                            pegawai,
                                            'saldo',
                                            WARNA_HASIL.saldo.dasar,
                                        )
                                    "
                                >
                                    {{ nilaiSaldoAkhir(pegawai) }}
                                </button>
                            </div>
                        </div>

                        <div
                            class="mt-3 grid grid-cols-4 gap-x-2 gap-y-2.5 border-t border-slate-100 pt-3"
                        >
                            <!-- data-rumus-area: klik di dalam kotak ini TIDAK
                                 menutup sorotan rumus (kotak berganti jadi
                                 input saat diketuk). -->
                            <div
                                v-for="stat in getStatKartu(pegawai)"
                                :key="stat.key"
                                data-rumus-area
                                class="relative flex flex-col items-center gap-1 rounded-lg p-0.5 text-center transition"
                                :class="
                                    kelasRedup(pegawai, kunciRumus(stat.key))
                                "
                                :title="stat.title"
                            >
                                <!-- Tanda + / − untuk kolom sumber rumus, atau
                                     Δ untuk kolom yang ikut berubah saat edit -->
                                <LencanaOp
                                    :p="pegawai"
                                    :kunci="kunciRumus(stat.key)"
                                    atas="-top-2"
                                />

                                <!-- Label + ikon pensil (kotak yang bisa
                                     diedit) atau ikon gembok (kotak dikunci) -->
                                <span
                                    class="inline-flex items-center justify-center gap-0.5 text-[10px] font-semibold leading-tight"
                                    :class="stat.labelKelas"
                                >
                                    {{ stat.label }}
                                    <IkonPensil v-if="stat.field" />
                                    <IkonGembok v-else-if="stat.kunci" />
                                </span>

                                <!-- Kotak yang bisa diedit: ketuk -> semua kotak
                                     editable di kartu ini jadi input -->
                                <SelAngka
                                    v-if="stat.field"
                                    besar
                                    :field="stat.field"
                                    :model-value="draft[stat.field]"
                                    @update:model-value="
                                        ubahDraft(stat.field, $event)
                                    "
                                    :nilai="stat.nilai"
                                    :editing="isEditing(pegawai)"
                                    :bintang="stat.bintang"
                                    :label="stat.label"
                                    :max="stat.max"
                                    :title="stat.title"
                                    :kelas="
                                        kelasSel(
                                            pegawai,
                                            kunciRumus(stat.key),
                                            stat.kelas,
                                        )
                                    "
                                    :sorot="
                                        kelasSorot(
                                            pegawai,
                                            kunciRumus(stat.key),
                                        )
                                    "
                                    @mulai="mulaiEdit(pegawai, stat.field)"
                                    @simpan="simpanSaldo(pegawai)"
                                />
                                <!-- Hasil hitungan yang bisa diklik: Sisa N,
                                     Bawaan, Total, Terpakai (dikunci) -->
                                <button
                                    v-else-if="KOLOM_RUMUS_KARTU[stat.key]"
                                    type="button"
                                    data-rumus-area
                                    @click="
                                        toggleRumus(
                                            pegawai,
                                            KOLOM_RUMUS_KARTU[stat.key],
                                        )
                                    "
                                    :aria-pressed="
                                        rumusDibuka(
                                            pegawai,
                                            KOLOM_RUMUS_KARTU[stat.key],
                                        )
                                    "
                                    :title="stat.title || KETERANGAN_KLIK_RUMUS"
                                    class="inline-block min-w-[36px] cursor-pointer rounded-md border px-2 py-1 text-xs font-bold transition"
                                    :class="
                                        kelasSel(
                                            pegawai,
                                            kunciRumus(stat.key),
                                            stat.kelas,
                                        )
                                    "
                                >
                                    {{ stat.nilai
                                    }}<sup
                                        v-if="stat.bintang"
                                        class="ml-0.5 font-black text-amber-600"
                                        >*</sup
                                    >
                                </button>
                                <span
                                    v-else
                                    class="inline-block min-w-[36px] rounded-md border px-2 py-1 text-xs font-bold"
                                    :class="stat.kelas"
                                >
                                    {{ stat.nilai }}
                                </span>
                            </div>
                        </div>

                        <!-- Info Terpakai dikunci + error validasi (hanya saat
                             baris ini diedit). -->
                        <div
                            v-if="isEditing(pegawai)"
                            data-rumus-area
                            class="mt-3 space-y-2 rounded-lg border border-amber-200 bg-amber-50/60 px-3 py-2"
                        >
                            <p class="text-xs text-amber-800">
                                Terpakai dikunci di sini.
                                <Link
                                    :href="urlRekapLaporan(pegawai)"
                                    class="font-semibold underline"
                                    >Ubah per bulan di Rekap Laporan</Link
                                >.
                            </p>
                            <ul
                                v-if="daftarErrorEdit.length > 0"
                                class="space-y-0.5 text-[11px] text-red-600"
                            >
                                <li
                                    v-for="(pesan, i) in daftarErrorEdit"
                                    :key="i"
                                >
                                    {{ pesan }}
                                </li>
                            </ul>
                        </div>

                        <!-- Rumus ringkas + tombol aksi dalam satu baris -->
                        <div
                            class="mt-3 flex items-center justify-between gap-2 border-t border-slate-100 pt-3"
                        >
                            <p
                                class="min-w-0 text-[11px] leading-snug text-slate-500"
                            >
                                Total
                                <span class="font-semibold text-slate-700">{{
                                    nilaiTotal(pegawai)
                                }}</span>
                                &minus; Terpakai
                                <span class="font-semibold text-slate-700">{{
                                    nilaiTerpakai(pegawai)
                                }}</span>
                                = Saldo
                                <span class="font-bold text-slate-800">{{
                                    nilaiSaldoAkhir(pegawai)
                                }}</span>
                            </p>

                            <!-- Mode tampil: Riwayat + ubah Terpakai -->
                            <div
                                v-if="!isEditing(pegawai)"
                                class="flex shrink-0 items-center gap-1.5"
                            >
                                <Link
                                    :href="urlRekapLaporan(pegawai)"
                                    class="inline-flex min-h-[40px] min-w-[40px] cursor-pointer items-center justify-center rounded-full border border-gray-300 bg-white px-2.5 py-1.5 text-gray-600 shadow-sm transition hover:bg-gray-50 hover:text-gray-900"
                                    title="Ubah Terpakai per bulan di Rekap Laporan"
                                    aria-label="Ubah Terpakai di Rekap Laporan"
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
                                            d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"
                                        />
                                    </svg>
                                </Link>
                                <button
                                    @click="openDetailModal(pegawai)"
                                    type="button"
                                    class="inline-flex min-h-[40px] min-w-[40px] cursor-pointer items-center justify-center rounded-full border border-gray-300 bg-white px-2.5 py-1.5 text-gray-600 shadow-sm transition hover:bg-gray-50 hover:text-gray-900"
                                    title="Lihat Rincian & Riwayat"
                                    aria-label="Lihat Rincian dan Riwayat"
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
                                            d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"
                                        />
                                    </svg>
                                </button>
                            </div>

                            <!-- Mode edit: Batal + Simpan -->
                            <div
                                v-else
                                class="flex shrink-0 items-center gap-1.5"
                            >
                                <button
                                    type="button"
                                    @click="batalEdit"
                                    :disabled="isSavingSaldo"
                                    class="min-h-[40px] cursor-pointer rounded-full bg-gray-200 px-3.5 py-1.5 text-xs font-semibold text-gray-700 transition hover:bg-gray-300 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    Batal
                                </button>
                                <button
                                    type="button"
                                    @click="simpanSaldo(pegawai)"
                                    :disabled="isSavingSaldo || !isDirty"
                                    class="inline-flex min-h-[40px] cursor-pointer items-center justify-center gap-1.5 rounded-full bg-emerald-600 px-3.5 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {{
                                        isSavingSaldo
                                            ? "Menyimpan..."
                                            : "Simpan"
                                    }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!--
          TABEL MONITORING (LAYAR LEBAR, >= lg / 1024px): SATU BARIS PER PEGAWAI.
          Semua label tahun di header DINAMIS mengikuti Filter Tahun
          ('tahunAktif'). Setiap angka dibungkus "kapsul" warna dengan
          min-w tetap supaya sejajar; Saldo Akhir diberi background gelap
          sebagai fokus utama.


          HEADER FREEZE: wadah tabel punya tinggi maksimal dan scroll
          sendiri (overflow-auto + max-h), sehingga header 'sticky top-0'
          tetap terlihat saat baris digulir. Kolom No & Nama menempel di
          kiri DAN header-nya menempel di atas (z-30, di atas sel isi z-10).


          URUTAN KOLOM: No, Nama, Sisa N-2, Tgh N-2, Sisa N-1, Tgh N-1,
          Sisa N (hitungan otomatis), Tgh N (bagian dari Terpakai), Bawaan, Hak,
          Total, Terpakai, Saldo Akhir, Aksi.


          ATURAN Tgh: Tgh N (tahun yang dilihat) tetap tercatat dan SUDAH
          TERMASUK dalam Terpakai N (Terpakai = cuti terpakai + Tgh N), jadi
          tidak dikurangkan lagi dari Sisa N dan Saldo Akhir.
          Tgh N-1 MENAMBAH Total Tersedia. Tgh N-2 hanya catatan Admin HR.


          PENANDA KOLOM BISA DIEDIT: ikon pensil kecil di header kolom
          Sisa N-2, Tgh N-2, Sisa N-1, Tgh N-1, Tgh N, dan Hak.
          Kolom hitungan otomatis (Sisa N, Bawaan, Total, Saldo Akhir) tidak
          diberi ikon.
          [TERPAKAI DIKUNCI] Kolom Terpakai diberi ikon GEMBOK: hanya tampil,
          diubah per bulan di Rekap Laporan (ikon tautan di sebelah angkanya).


          WARNA KOLOM: kolom input (Sisa N-2, Tgh, Sisa N-1, Hak) putih
          netral. Hanya kolom hasil yang berwarna: Sisa N (sky), Bawaan
          (emerald), Total (indigo), Terpakai (rose), Saldo Akhir (gelap).


          SOROT RUMUS: angka Sisa N, Bawaan, Total, Terpakai, dan Saldo Akhir
          bisa diklik. Angka yang diklik terisi penuh, kolom sumber rumusnya
          terisi warna yang sama dan diberi tanda + / −, kolom lain meredup,
          dan header kolom sumber ikut berwarna. Sorotan TETAP menyala saat
          kolom sumber (mis. Hak) diklik untuk diedit, dan ditutup oleh
          Simpan / Batal / Esc / klik di luar. Mengklik angka hasil juga
          melepas seleksi (sorot dampak) kotak input sebelumnya.


          SOROT DAMPAK EDIT: saat sebuah kotak input difokus (klik atau Tab),
          kolom yang ikut berubah diberi cincin oranye + lencana Δ, header-nya
          berwarna oranye, dan kolom lain meredup. Berjalan bersamaan dengan
          sorot rumus.


          EDIT INLINE: klik salah satu kapsul yang bisa diedit (Sisa, Tgh,
          Hak) -> semua kapsul editable di baris itu menjadi input
          seukuran kapsul. Sisa N, Bawaan, Total, Terpakai, dan Saldo Akhir
          menampilkan pratinjau langsung. Kolom Aksi berubah jadi Simpan
          (centang) dan Batal (X). Enter = Simpan, Esc = Batal.


          Tampilan disamakan dengan Rekap Laporan: kartu ber-border membulat,
          kolom No & Nama menempel (sticky) di kiri saat tabel digeser, efek
          hover ungu. Tabel 'w-full' dan rapat ('px-2 py-3'). Di layar kecil
          diganti kartu di atas.
        -->
                <div
                    v-if="punyaData"
                    class="hidden lg:block overflow-auto max-h-[calc(100dvh-10rem)] rounded-lg border border-slate-200 bg-white"
                >
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr
                                class="bg-slate-50 border-b border-slate-200 text-[10px] leading-tight text-slate-500 uppercase tracking-wide font-bold align-middle"
                            >
                                <th
                                    class="sticky left-0 top-0 z-30 bg-slate-50 w-12 min-w-[3rem] px-2 py-3 text-center border-r border-slate-200 shadow-[inset_0_-1px_0_0_#e2e8f0]"
                                >
                                    No
                                </th>
                                <th
                                    class="sticky left-12 top-0 z-30 bg-slate-50 min-w-[190px] px-3 py-3 border-r-2 border-slate-300 shadow-[2px_0_4px_-2px_rgba(0,0,0,0.08),inset_0_-1px_0_0_#e2e8f0]"
                                >
                                    Nama Pegawai
                                </th>
                                <!-- Sisa N-2 (bisa diedit) -->
                                <th
                                    class="sticky top-0 z-20 px-2 py-3 text-center transition-colors shadow-[inset_0_-1px_0_0_#e2e8f0]"
                                    :class="kelasHeader('sisa_n2')"
                                    :title="KETERANGAN_BISA_DIEDIT"
                                >
                                    <span
                                        class="inline-flex items-center justify-center gap-1"
                                    >
                                        Sisa {{ tahunAktif - 2 }}
                                        <IkonPensil />
                                    </span>
                                </th>
                                <!-- Tgh N-2 (bisa diedit, hanya catatan) -->
                                <th
                                    class="sticky top-0 z-20 px-2 py-3 text-center transition-colors shadow-[inset_0_-1px_0_0_#e2e8f0]"
                                    :class="kelasHeader('tgh_n2')"
                                    :title="`${KETERANGAN_TGH_N2}. ${KETERANGAN_BISA_DIEDIT}`"
                                >
                                    <span
                                        class="inline-flex items-center justify-center gap-1"
                                    >
                                        Tgh '{{
                                            (tahunAktif - 2)
                                                .toString()
                                                .slice(-2)
                                        }}
                                        <IkonPensil />
                                    </span>
                                </th>
                                <!-- Sisa N-1 (bisa diedit) -->
                                <th
                                    class="sticky top-0 z-20 px-2 py-3 text-center transition-colors shadow-[inset_0_-1px_0_0_#e2e8f0]"
                                    :class="kelasHeader('sisa_n1')"
                                    :title="KETERANGAN_BISA_DIEDIT"
                                >
                                    <span
                                        class="inline-flex items-center justify-center gap-1"
                                    >
                                        Sisa {{ tahunAktif - 1 }}
                                        <IkonPensil />
                                    </span>
                                </th>
                                <!-- Tgh N-1 (bisa diedit, menambah Total Tersedia) -->
                                <th
                                    class="sticky top-0 z-20 px-2 py-3 text-center transition-colors shadow-[inset_0_-1px_0_0_#e2e8f0]"
                                    :class="kelasHeader('tgh_n1')"
                                    :title="`${KETERANGAN_TGH_N1}. ${KETERANGAN_BISA_DIEDIT}`"
                                >
                                    <span
                                        class="inline-flex items-center justify-center gap-1"
                                    >
                                        Tgh '{{
                                            (tahunAktif - 1)
                                                .toString()
                                                .slice(-2)
                                        }}
                                        <IkonPensil />
                                    </span>
                                </th>
                                <!-- Sisa N: hitungan otomatis (Hak - Terpakai),
                                     TIDAK bisa diedit, jadi tanpa ikon pensil.
                                     Angkanya bisa diklik untuk menyorot rumus. -->
                                <th
                                    class="sticky top-0 z-20 px-2 py-3 text-center transition-colors shadow-[inset_0_-1px_0_0_#e2e8f0]"
                                    :class="kelasHeader('sisa_n0')"
                                    :title="KETERANGAN_SISA_TAHUN_INI"
                                >
                                    Sisa {{ tahunAktif }}
                                </th>
                                <!-- Ditangguhkan tahun ini (tetap tercatat, sudah
                                     termasuk dalam Terpakai): tepat di samping
                                     Sisa tahun ini (bisa diedit) -->
                                <th
                                    class="sticky top-0 z-20 px-2 py-3 text-center transition-colors shadow-[inset_0_-1px_0_0_#e2e8f0]"
                                    :class="kelasHeader('tgh_n0')"
                                >
                                    <span
                                        class="inline-flex items-center justify-center gap-1"
                                        :title="KETERANGAN_BISA_DIEDIT"
                                    >
                                        <span
                                            >Tgh '{{
                                                tahunAktif.toString().slice(-2)
                                            }}</span
                                        >
                                        <IkonPensil />
                                        <span
                                            role="img"
                                            tabindex="0"
                                            :title="KETERANGAN_TGH_TAHUN_INI"
                                            :aria-label="
                                                KETERANGAN_TGH_TAHUN_INI
                                            "
                                            class="inline-flex h-3.5 w-3.5 shrink-0 cursor-help items-center justify-center rounded-full border border-slate-400 text-[9px] font-bold normal-case leading-none text-slate-500"
                                            >?</span
                                        >
                                    </span>
                                </th>
                                <th
                                    class="sticky top-0 z-20 px-2 py-3 text-center transition-colors shadow-[inset_0_-1px_0_0_#e2e8f0]"
                                    :class="kelasHeader('bawaan')"
                                    :title="KETERANGAN_BAWAAN"
                                >
                                    Bawaan Eligible {{ tahunAktif }}
                                </th>
                                <!-- Hak (bisa diedit) -->
                                <th
                                    class="sticky top-0 z-20 px-2 py-3 text-center transition-colors shadow-[inset_0_-1px_0_0_#e2e8f0]"
                                    :class="kelasHeader('hak')"
                                    :title="KETERANGAN_BISA_DIEDIT"
                                >
                                    <span
                                        class="inline-flex items-center justify-center gap-1"
                                    >
                                        Hak {{ tahunAktif }}
                                        <IkonPensil />
                                    </span>
                                </th>
                                <th
                                    class="sticky top-0 z-20 px-2 py-3 text-center transition-colors shadow-[inset_0_-1px_0_0_#e2e8f0]"
                                    :class="kelasHeader('total')"
                                >
                                    Total Tersedia {{ tahunAktif }}
                                </th>
                                <!-- [TERPAKAI DIKUNCI] ikon gembok: hanya tampil -->
                                <th
                                    class="sticky top-0 z-20 px-2 py-3 text-center transition-colors shadow-[inset_0_-1px_0_0_#e2e8f0]"
                                    :class="kelasHeader('terpakai')"
                                    :title="KETERANGAN_TERPAKAI_DIKUNCI"
                                >
                                    <span
                                        class="inline-flex items-center justify-center gap-1"
                                    >
                                        Terpakai {{ tahunAktif }}
                                        <IkonGembok />
                                    </span>
                                </th>
                                <th
                                    class="sticky top-0 z-20 px-2 py-3 text-center transition-colors shadow-[inset_0_-1px_0_0_#e2e8f0]"
                                    :class="kelasHeader('saldo')"
                                >
                                    Saldo Akhir {{ tahunAktif }}
                                </th>
                                <th
                                    class="sticky top-0 z-20 bg-slate-50 px-2 py-3 text-center min-w-[6.5rem] shadow-[inset_0_-1px_0_0_#e2e8f0]"
                                >
                                    Aksi
                                </th>
                            </tr>
                        </thead>
                        <tbody class="text-sm align-middle">
                            <template
                                v-for="(pegawai, index) in dataPegawai.data"
                                :key="pegawai.id"
                            >
                                <tr
                                    class="group border-b border-slate-100 transition-all duration-150"
                                    :class="
                                        isEditing(pegawai)
                                            ? 'bg-emerald-50/60 shadow-[inset_4px_0_0_0_theme(colors.emerald.500)]'
                                            : 'hover:bg-violet-50 hover:shadow-[inset_4px_0_0_0_theme(colors.violet.400)]'
                                    "
                                >
                                    <!-- No urut (ikut halaman paginasi) -->
                                    <td
                                        class="sticky left-0 z-10 w-12 min-w-[3rem] px-2 py-3 text-center text-sm font-semibold text-slate-800 border-r border-slate-200 transition-colors duration-150"
                                        :class="
                                            isEditing(pegawai)
                                                ? 'bg-emerald-50'
                                                : 'bg-white group-hover:bg-violet-50'
                                        "
                                    >
                                        {{ (dataPegawai.from ?? 1) + index }}
                                    </td>

                                    <!-- Nama & Kelompok Substansi (boleh membungkus) -->
                                    <td
                                        class="sticky left-12 z-10 min-w-[190px] px-3 py-3 border-r-2 border-slate-300 shadow-[2px_0_4px_-2px_rgba(0,0,0,0.08)] transition-colors duration-150"
                                        :class="
                                            isEditing(pegawai)
                                                ? 'bg-emerald-50'
                                                : 'bg-white group-hover:bg-violet-50'
                                        "
                                    >
                                        <div
                                            class="font-bold text-slate-800 text-sm"
                                        >
                                            {{ pegawai.nama }}
                                        </div>
                                        <div
                                            class="text-[11px] text-slate-500 mt-0.5"
                                        >
                                            {{ getKelompok(pegawai) }}
                                        </div>
                                    </td>

                                    <!-- Sisa N-2 (EDITABLE, klik angka; putih netral).
                                         Sumber rumus Bawaan. data-rumus-area =
                                         klik di sini tidak menutup sorotan. -->
                                    <td class="relative px-2 py-3 text-center">
                                        <LencanaOp
                                            :p="pegawai"
                                            kunci="sisa_n2"
                                        />
                                        <div
                                            data-rumus-area
                                            class="inline-block rounded-md transition"
                                            :class="
                                                kelasRedup(pegawai, 'sisa_n2')
                                            "
                                        >
                                            <SelAngka
                                                field="sisa_n2"
                                                :model-value="draft.sisa_n2"
                                                @update:model-value="
                                                    ubahDraft('sisa_n2', $event)
                                                "
                                                :nilai="
                                                    getSisaDuaTahunLalu(pegawai)
                                                "
                                                :editing="isEditing(pegawai)"
                                                :label="`Sisa ${tahunAktif - 2}`"
                                                :max="12"
                                                :kelas="
                                                    kelasSel(
                                                        pegawai,
                                                        'sisa_n2',
                                                        KELAS_INPUT_NETRAL,
                                                    )
                                                "
                                                :sorot="
                                                    kelasSorot(
                                                        pegawai,
                                                        'sisa_n2',
                                                    )
                                                "
                                                @mulai="
                                                    mulaiEdit(
                                                        pegawai,
                                                        'sisa_n2',
                                                    )
                                                "
                                                @simpan="simpanSaldo(pegawai)"
                                            />
                                        </div>
                                    </td>

                                    <!-- Ditangguhkan N-2 (netral, EDITABLE):
                                         hanya catatan, tidak memengaruhi saldo -->
                                    <td class="relative px-2 py-3 text-center">
                                        <LencanaOp
                                            :p="pegawai"
                                            kunci="tgh_n2"
                                        />
                                        <div
                                            data-rumus-area
                                            class="inline-block rounded-md transition"
                                            :class="
                                                kelasRedup(pegawai, 'tgh_n2')
                                            "
                                        >
                                            <SelAngka
                                                field="tgh_n2"
                                                :model-value="draft.tgh_n2"
                                                @update:model-value="
                                                    ubahDraft('tgh_n2', $event)
                                                "
                                                :nilai="
                                                    getSisaDitangguhkanN2(
                                                        pegawai,
                                                    )
                                                "
                                                :editing="isEditing(pegawai)"
                                                :label="`Tgh ${tahunAktif - 2}`"
                                                :title="KETERANGAN_TGH_N2"
                                                :kelas="
                                                    kelasSel(
                                                        pegawai,
                                                        'tgh_n2',
                                                        KELAS_INPUT_NETRAL,
                                                    )
                                                "
                                                :sorot="
                                                    kelasSorot(
                                                        pegawai,
                                                        'tgh_n2',
                                                    )
                                                "
                                                @mulai="
                                                    mulaiEdit(pegawai, 'tgh_n2')
                                                "
                                                @simpan="simpanSaldo(pegawai)"
                                            />
                                        </div>
                                    </td>

                                    <!-- Sisa N-1 (EDITABLE; putih netral; sumber
                                         rumus Bawaan) -->
                                    <td class="relative px-2 py-3 text-center">
                                        <LencanaOp
                                            :p="pegawai"
                                            kunci="sisa_n1"
                                        />
                                        <div
                                            data-rumus-area
                                            class="inline-block rounded-md transition"
                                            :class="
                                                kelasRedup(pegawai, 'sisa_n1')
                                            "
                                        >
                                            <SelAngka
                                                field="sisa_n1"
                                                :model-value="draft.sisa_n1"
                                                @update:model-value="
                                                    ubahDraft('sisa_n1', $event)
                                                "
                                                :nilai="
                                                    getSisaTahunLalu(pegawai)
                                                "
                                                :editing="isEditing(pegawai)"
                                                :label="`Sisa ${tahunAktif - 1}`"
                                                :max="12"
                                                :kelas="
                                                    kelasSel(
                                                        pegawai,
                                                        'sisa_n1',
                                                        KELAS_INPUT_NETRAL,
                                                    )
                                                "
                                                :sorot="
                                                    kelasSorot(
                                                        pegawai,
                                                        'sisa_n1',
                                                    )
                                                "
                                                @mulai="
                                                    mulaiEdit(
                                                        pegawai,
                                                        'sisa_n1',
                                                    )
                                                "
                                                @simpan="simpanSaldo(pegawai)"
                                            />
                                        </div>
                                    </td>

                                    <!-- Ditangguhkan N-1 (netral, EDITABLE):
                                         MENAMBAH Total Tersedia (sumber rumus
                                         Total) -->
                                    <td class="relative px-2 py-3 text-center">
                                        <LencanaOp
                                            :p="pegawai"
                                            kunci="tgh_n1"
                                        />
                                        <div
                                            data-rumus-area
                                            class="inline-block rounded-md transition"
                                            :class="
                                                kelasRedup(pegawai, 'tgh_n1')
                                            "
                                        >
                                            <SelAngka
                                                field="tgh_n1"
                                                :model-value="draft.tgh_n1"
                                                @update:model-value="
                                                    ubahDraft('tgh_n1', $event)
                                                "
                                                :nilai="
                                                    getSisaDitangguhkanN1(
                                                        pegawai,
                                                    )
                                                "
                                                :editing="isEditing(pegawai)"
                                                :label="`Tgh ${tahunAktif - 1}`"
                                                :title="KETERANGAN_TGH_N1"
                                                :kelas="
                                                    kelasSel(
                                                        pegawai,
                                                        'tgh_n1',
                                                        KELAS_INPUT_NETRAL,
                                                    )
                                                "
                                                :sorot="
                                                    kelasSorot(
                                                        pegawai,
                                                        'tgh_n1',
                                                    )
                                                "
                                                @mulai="
                                                    mulaiEdit(pegawai, 'tgh_n1')
                                                "
                                                @simpan="simpanSaldo(pegawai)"
                                            />
                                        </div>
                                    </td>

                                    <!-- Sisa tahun ini = Hak - Terpakai
                                         (sky, hitungan OTOMATIS, tidak bisa diedit).
                                         Terpakai sudah termasuk Tgh tahun ini.
                                         Saat baris diedit menampilkan pratinjau.
                                         Klik angkanya untuk menyorot rumus. -->
                                    <td class="relative px-2 py-3 text-center">
                                        <LencanaOp
                                            :p="pegawai"
                                            kunci="sisa_n0"
                                        />
                                        <button
                                            type="button"
                                            data-rumus-area
                                            @click="
                                                toggleRumus(pegawai, 'sisa')
                                            "
                                            :aria-pressed="
                                                rumusDibuka(pegawai, 'sisa')
                                            "
                                            :title="`${KETERANGAN_SISA_TAHUN_INI} ${KETERANGAN_KLIK_RUMUS}.`"
                                            class="inline-block min-w-[40px] px-2 py-1 rounded-md font-semibold text-xs border cursor-pointer transition"
                                            :class="[
                                                kelasSel(
                                                    pegawai,
                                                    'sisa_n0',
                                                    WARNA_HASIL.sisa.dasar,
                                                ),
                                                kelasRedup(pegawai, 'sisa_n0'),
                                            ]"
                                        >
                                            {{ nilaiSisaTahunIni(pegawai) }}
                                        </button>
                                    </td>

                                    <!-- Ditangguhkan tahun ini (netral, EDITABLE):
                                         tetap tercatat dan sudah termasuk dalam
                                         Terpakai (sumber rumus Terpakai). Tepat di
                                         samping Sisa tahun ini. -->
                                    <td class="relative px-2 py-3 text-center">
                                        <LencanaOp
                                            :p="pegawai"
                                            kunci="tgh_n0"
                                        />
                                        <div
                                            data-rumus-area
                                            class="inline-block rounded-md transition"
                                            :class="
                                                kelasRedup(pegawai, 'tgh_n0')
                                            "
                                        >
                                            <SelAngka
                                                field="tgh_n0"
                                                :model-value="draft.tgh_n0"
                                                @update:model-value="
                                                    ubahDraft('tgh_n0', $event)
                                                "
                                                :nilai="
                                                    getSisaDitangguhkanTahunIni(
                                                        pegawai,
                                                    )
                                                "
                                                :editing="isEditing(pegawai)"
                                                :label="`Tgh ${tahunAktif}`"
                                                :title="
                                                    KETERANGAN_TGH_TAHUN_INI
                                                "
                                                :kelas="
                                                    kelasSel(
                                                        pegawai,
                                                        'tgh_n0',
                                                        KELAS_INPUT_NETRAL,
                                                    )
                                                "
                                                :sorot="
                                                    kelasSorot(
                                                        pegawai,
                                                        'tgh_n0',
                                                    )
                                                "
                                                @mulai="
                                                    mulaiEdit(pegawai, 'tgh_n0')
                                                "
                                                @simpan="simpanSaldo(pegawai)"
                                            />
                                        </div>
                                    </td>

                                    <!-- Saldo Bawaan Eligible (Emerald) — otomatis;
                                         saat edit menampilkan pratinjau. Klik
                                         untuk menyorot sumbernya. Aturannya
                                         (bukan penjumlahan) ada di tooltip. -->
                                    <td class="relative px-2 py-3 text-center">
                                        <LencanaOp
                                            :p="pegawai"
                                            kunci="bawaan"
                                        />
                                        <button
                                            type="button"
                                            data-rumus-area
                                            @click="
                                                toggleRumus(pegawai, 'bawaan')
                                            "
                                            :aria-pressed="
                                                rumusDibuka(pegawai, 'bawaan')
                                            "
                                            :title="KETERANGAN_BAWAAN"
                                            class="inline-block min-w-[40px] px-2 py-1 rounded-md font-bold text-xs border cursor-pointer transition"
                                            :class="[
                                                kelasSel(
                                                    pegawai,
                                                    'bawaan',
                                                    WARNA_HASIL.bawaan.dasar,
                                                ),
                                                kelasRedup(pegawai, 'bawaan'),
                                            ]"
                                        >
                                            {{ nilaiBawaan(pegawai) }}
                                        </button>
                                    </td>

                                    <!-- Hak tahun berjalan (EDITABLE, putih netral);
                                         sumber rumus Total dan Sisa N -->
                                    <td class="relative px-2 py-3 text-center">
                                        <LencanaOp :p="pegawai" kunci="hak" />
                                        <div
                                            data-rumus-area
                                            class="inline-block rounded-md transition"
                                            :class="kelasRedup(pegawai, 'hak')"
                                        >
                                            <SelAngka
                                                field="hak"
                                                :model-value="draft.hak"
                                                @update:model-value="
                                                    ubahDraft('hak', $event)
                                                "
                                                :nilai="
                                                    getSisaTahunIni(pegawai)
                                                "
                                                :editing="isEditing(pegawai)"
                                                :label="`Hak ${tahunAktif}`"
                                                :kelas="
                                                    kelasSel(
                                                        pegawai,
                                                        'hak',
                                                        KELAS_INPUT_NETRAL,
                                                    )
                                                "
                                                :sorot="
                                                    kelasSorot(pegawai, 'hak')
                                                "
                                                @mulai="
                                                    mulaiEdit(pegawai, 'hak')
                                                "
                                                @simpan="simpanSaldo(pegawai)"
                                            />
                                        </div>
                                    </td>

                                    <!-- Total Hak Tersedia (Indigo) — otomatis;
                                         saat edit menampilkan pratinjau. Klik
                                         untuk menyorot rumus. -->
                                    <td class="relative px-2 py-3 text-center">
                                        <LencanaOp :p="pegawai" kunci="total" />
                                        <button
                                            type="button"
                                            data-rumus-area
                                            @click="
                                                toggleRumus(pegawai, 'total')
                                            "
                                            :aria-pressed="
                                                rumusDibuka(pegawai, 'total')
                                            "
                                            :title="KETERANGAN_KLIK_RUMUS"
                                            class="inline-block min-w-[40px] px-2 py-1 rounded-md font-bold text-xs border cursor-pointer transition"
                                            :class="[
                                                kelasSel(
                                                    pegawai,
                                                    'total',
                                                    WARNA_HASIL.total.dasar,
                                                ),
                                                kelasRedup(pegawai, 'total'),
                                            ]"
                                        >
                                            {{ nilaiTotal(pegawai) }}
                                        </button>
                                    </td>

                                    <!-- [TERPAKAI DIKUNCI] Terpakai (Rose) — HANYA
                                         TAMPIL. Angka TAMPIL = cuti terpakai (sudah
                                         ikut koreksi bulanan Rekap Laporan) + Tgh
                                         tahun ini. Tanda * kecil muncul kalau
                                         angkanya hasil koreksi Admin HR. Klik
                                         angkanya = menyorot sumbernya (Tgh tahun
                                         ini). Ikon kalender di sampingnya = tautan
                                         ke Rekap Laporan (sudah terfilter ke
                                         pegawai ini) untuk mengubah per bulan. -->
                                    <td class="relative px-2 py-3 text-center">
                                        <LencanaOp
                                            :p="pegawai"
                                            kunci="terpakai"
                                        />
                                        <div
                                            data-rumus-area
                                            class="inline-flex items-center gap-1 rounded-md transition"
                                            :class="
                                                kelasRedup(pegawai, 'terpakai')
                                            "
                                        >
                                            <button
                                                type="button"
                                                data-rumus-area
                                                @click="
                                                    toggleRumus(
                                                        pegawai,
                                                        'terpakai',
                                                    )
                                                "
                                                :aria-pressed="
                                                    rumusDibuka(
                                                        pegawai,
                                                        'terpakai',
                                                    )
                                                "
                                                :title="titleTerpakai(pegawai)"
                                                class="inline-block min-w-[40px] px-2 py-1 rounded-md font-bold text-xs border cursor-pointer transition"
                                                :class="
                                                    kelasSel(
                                                        pegawai,
                                                        'terpakai',
                                                        WARNA_HASIL.terpakai
                                                            .dasar,
                                                    )
                                                "
                                            >
                                                {{ nilaiTerpakai(pegawai)
                                                }}<sup
                                                    v-if="
                                                        !isEditing(pegawai) &&
                                                        adaKoreksi(pegawai)
                                                    "
                                                    class="ml-0.5 font-black text-amber-600"
                                                    >*</sup
                                                >
                                            </button>
                                            <Link
                                                :href="urlRekapLaporan(pegawai)"
                                                title="Ubah Terpakai per bulan di Rekap Laporan"
                                                aria-label="Ubah Terpakai per bulan di Rekap Laporan"
                                                class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-500 transition hover:border-rose-300 hover:bg-rose-50 hover:text-rose-700 focus:outline-none focus:ring-2 focus:ring-rose-300"
                                            >
                                                <svg
                                                    class="h-3.5 w-3.5"
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
                                            </Link>
                                        </div>
                                    </td>

                                    <!-- Saldo Akhir (gelap agar paling menonjol) —
                                         saat edit menampilkan pratinjau. Klik
                                         untuk menyorot rumus (Total dan Terpakai
                                         berwarna amber). 'relative' + LencanaOp
                                         dipakai untuk lencana Δ dampak edit. -->
                                    <td class="relative px-2 py-3 text-center">
                                        <LencanaOp :p="pegawai" kunci="saldo" />
                                        <button
                                            type="button"
                                            data-rumus-area
                                            @click="
                                                toggleRumus(pegawai, 'saldo')
                                            "
                                            :aria-pressed="
                                                rumusDibuka(pegawai, 'saldo')
                                            "
                                            :title="KETERANGAN_KLIK_RUMUS"
                                            class="inline-block min-w-[40px] px-2.5 py-1 rounded-md font-extrabold text-xs shadow-sm border cursor-pointer transition"
                                            :class="[
                                                kelasSel(
                                                    pegawai,
                                                    'saldo',
                                                    WARNA_HASIL.saldo.dasar,
                                                ),
                                                kelasRedup(pegawai, 'saldo'),
                                            ]"
                                        >
                                            {{ nilaiSaldoAkhir(pegawai) }}
                                        </button>
                                    </td>

                                    <!-- Aksi: mode tampil = Riwayat;
                                         mode edit = Simpan (centang) + Batal (X) -->
                                    <td class="px-2 py-3 text-center">
                                        <div
                                            class="inline-flex items-center gap-1"
                                        >
                                            <template
                                                v-if="!isEditing(pegawai)"
                                            >
                                                <button
                                                    @click="
                                                        openDetailModal(pegawai)
                                                    "
                                                    type="button"
                                                    class="inline-flex items-center px-2.5 py-1.5 border border-gray-300 rounded-full text-gray-600 bg-white hover:bg-gray-50 hover:text-gray-900 transition shadow-sm cursor-pointer"
                                                    title="Lihat Rincian & Riwayat"
                                                    aria-label="Lihat Rincian dan Riwayat"
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
                                                            d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"
                                                        />
                                                    </svg>
                                                </button>
                                            </template>
                                            <template v-else>
                                                <button
                                                    type="button"
                                                    @click="
                                                        simpanSaldo(pegawai)
                                                    "
                                                    :disabled="
                                                        isSavingSaldo ||
                                                        !isDirty
                                                    "
                                                    class="inline-flex items-center px-2.5 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-full shadow-sm transition cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
                                                    title="Simpan (Enter)"
                                                    aria-label="Simpan"
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
                                                            d="M5 13l4 4L19 7"
                                                        />
                                                    </svg>
                                                </button>
                                                <button
                                                    type="button"
                                                    @click="batalEdit"
                                                    :disabled="isSavingSaldo"
                                                    class="inline-flex items-center px-2.5 py-1.5 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-full transition cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
                                                    title="Batal (Esc)"
                                                    aria-label="Batal edit"
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
                                                            d="M6 18L18 6M6 6l12 12"
                                                        />
                                                    </svg>
                                                </button>
                                            </template>
                                        </div>
                                    </td>
                                </tr>

                                <!-- Baris pesan error validasi backend (hanya
                                     muncul untuk baris yang sedang diedit).
                                     colspan = jumlah kolom tabel (14). -->
                                <tr
                                    v-if="
                                        isEditing(pegawai) &&
                                        daftarErrorEdit.length > 0
                                    "
                                    class="border-b border-slate-100 bg-red-50"
                                >
                                    <td colspan="14" class="px-4 py-2">
                                        <ul
                                            class="space-y-0.5 text-xs text-red-600"
                                        >
                                            <li
                                                v-for="(
                                                    pesan, i
                                                ) in daftarErrorEdit"
                                                :key="i"
                                            >
                                                {{ pesan }}
                                            </li>
                                        </ul>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <!-- Legenda ikon pensil & gembok (layar lebar): menjelaskan
                     penanda di header kolom. Selalu tampil selama tabel tampil. -->
                <p
                    v-if="punyaData"
                    class="mt-2 hidden lg:flex flex-wrap items-center gap-x-1.5 gap-y-1 text-xs text-slate-500"
                >
                    <IkonPensil />
                    <span>= kolom bisa diedit, klik angkanya.</span>
                    <IkonGembok />
                    <span
                        >= Terpakai dikunci, ubah per bulan di Rekap
                        Laporan.</span
                    >
                    <span class="text-slate-400">
                        Sisa {{ tahunAktif }}, Bawaan, Total, dan Saldo Akhir
                        dihitung otomatis. Tgh {{ tahunAktif }} tetap tercatat
                        dan sudah termasuk dalam Terpakai.
                    </span>
                </p>

                <!-- Legenda warna hasil hitungan (layar lebar). Klik angka
                     berwarna untuk menyorot kolom sumbernya, termasuk Terpakai
                     (menyorot Tgh tahun ini). Esc atau klik di luar untuk
                     menutup sorotan. -->
                <p
                    v-if="punyaData"
                    class="mt-1 hidden lg:flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500"
                >
                    <span
                        >Klik angka berwarna untuk menyorot sumber
                        rumusnya:</span
                    >
                    <span
                        class="rounded px-1.5 py-0.5 font-semibold"
                        :class="WARNA_HASIL.sisa.dasar"
                        >Sisa {{ tahunAktif }}</span
                    >
                    <span
                        class="rounded px-1.5 py-0.5 font-semibold"
                        :class="WARNA_HASIL.bawaan.dasar"
                        >Bawaan</span
                    >
                    <span
                        class="rounded px-1.5 py-0.5 font-semibold"
                        :class="WARNA_HASIL.total.dasar"
                        >Total</span
                    >
                    <span
                        class="rounded px-1.5 py-0.5 font-semibold"
                        :class="WARNA_HASIL.terpakai.dasar"
                        >Terpakai</span
                    >
                    <span
                        class="rounded px-1.5 py-0.5 font-semibold"
                        :class="WARNA_HASIL.saldo.dasar"
                        >Saldo Akhir</span
                    >
                </p>

                <!-- Legenda dampak edit (layar lebar): arti lencana Δ oranye. -->
                <p
                    v-if="punyaData"
                    class="mt-1 hidden lg:flex flex-wrap items-center gap-x-1.5 gap-y-1 text-xs text-slate-500"
                >
                    <span
                        class="inline-flex h-4 w-4 items-center justify-center rounded-full bg-orange-500 text-[11px] font-bold leading-none text-white"
                        >Δ</span
                    >
                    <span
                        >= kolom yang ikut berubah saat kotak yang sedang diedit
                        diubah. Header kolomnya juga berwarna oranye.</span
                    >
                </p>

                <!-- Petunjuk mode edit inline (layar lebar) -->
                <p
                    v-if="editingId !== null"
                    class="mt-3 hidden lg:block text-xs text-emerald-700"
                >
                    Mode edit tahun {{ tahunAktif }}: ubah angka di baris yang
                    disorot. Sisa {{ tahunAktif }}, Bawaan, Total, Terpakai, dan
                    Saldo Akhir dihitung otomatis. Tekan Enter untuk menyimpan
                    atau Esc untuk membatalkan.
                </p>

                <!-- Catatan kaki: hanya tampil kalau ada minimal 1 baris yang
                     angka Terpakai-nya dikoreksi Admin HR. -->
                <p v-if="adaItemDikoreksi" class="mt-3 text-xs text-amber-700">
                    * Angka bertanda bintang dikoreksi Admin HR di Rekap
                    Laporan. Arahkan kursor ke angkanya untuk melihat bulan yang
                    dikoreksi, atau klik ikon kalender untuk membuka Rekap
                    Laporan pegawai itu.
                </p>

                <!-- Pagination (link dari Laravel paginator, gaya Rekap Laporan).
                     HP: ringkas (Sebelumnya / Hal x dari y / Berikutnya).
                     >= sm: deretan nomor halaman lengkap. -->
                <div
                    v-if="dataPegawai?.links && dataPegawai.links.length > 3"
                    class="flex flex-col sm:flex-row items-center justify-between gap-3 mt-4"
                >
                    <p class="text-xs text-gray-500 text-center sm:text-left">
                        Menampilkan
                        <span class="font-semibold text-gray-700">{{
                            dataPegawai.from ?? 0
                        }}</span>
                        -
                        <span class="font-semibold text-gray-700">{{
                            dataPegawai.to ?? 0
                        }}</span>
                        dari
                        <span class="font-semibold text-gray-700">{{
                            dataPegawai.total ?? 0
                        }}</span>
                        pegawai
                    </p>

                    <!-- Paginasi ringkas (HP) -->
                    <div
                        class="flex w-full items-center justify-between gap-2 sm:hidden"
                    >
                        <button
                            type="button"
                            :disabled="!linkSebelumnya?.url"
                            @click="goToPage(linkSebelumnya?.url)"
                            class="min-h-[44px] flex-1 rounded-full border border-gray-300 px-4 text-xs font-semibold text-gray-600 transition-colors hover:bg-gray-50"
                            :class="
                                !linkSebelumnya?.url
                                    ? 'opacity-40 cursor-not-allowed'
                                    : 'cursor-pointer'
                            "
                        >
                            &laquo; Sebelumnya
                        </button>
                        <span
                            class="shrink-0 px-1 text-xs font-semibold text-gray-600"
                        >
                            {{ dataPegawai.current_page ?? 1 }} /
                            {{ dataPegawai.last_page ?? 1 }}
                        </span>
                        <button
                            type="button"
                            :disabled="!linkBerikutnya?.url"
                            @click="goToPage(linkBerikutnya?.url)"
                            class="min-h-[44px] flex-1 rounded-full border border-gray-300 px-4 text-xs font-semibold text-gray-600 transition-colors hover:bg-gray-50"
                            :class="
                                !linkBerikutnya?.url
                                    ? 'opacity-40 cursor-not-allowed'
                                    : 'cursor-pointer'
                            "
                        >
                            Berikutnya &raquo;
                        </button>
                    </div>

                    <!-- Paginasi lengkap (>= sm) -->
                    <div
                        class="hidden sm:flex flex-wrap items-center justify-center gap-1"
                    >
                        <button
                            v-for="(link, index) in dataPegawai.links"
                            :key="index"
                            type="button"
                            v-html="link.label"
                            :disabled="!link.url"
                            @click="goToPage(link.url)"
                            class="min-w-[32px] h-8 px-3 text-xs font-semibold rounded-full border transition-colors"
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

            <!-- Semua modal di-Teleport ke <body> supaya TIDAK terjebak di
                 stacking context konten MainLayout (header/topbar tidak lagi
                 menutupi bagian atas modal, backdrop menutup seluruh layar).
                 Tetap berada di dalam div pembungkus tunggal. -->
            <Teleport to="body">
                <!-- ============================================================
                 MODAL RINCIAN KUOTA & RIWAYAT CUTI PEGAWAI (HANYA-BACA)
                 Form edit saldo ada di baris tabel (klik angka).
                 Modal ini hanya menampilkan KPI, rumus Saldo Akhir, dan
                 seluruh riwayat pengajuan.
                 Struktur 3 bagian:
                   1) Header  : tetap di atas (judul, pegawai, tombol X)
                   2) Body    : SATU-SATUNYA area yang scroll
                   3) Footer  : tetap di bawah (Tutup)
                 HP   : bottom sheet (sudut atas membulat, handle, tinggi
                        maks 88dvh), bisa ditutup dengan X, klik backdrop,
                        geser ke bawah, atau Esc.
                 >= sm: dialog di tengah, lebar 'sm:max-w-lg'.
                 ============================================================ -->
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
                                <span
                                    class="h-1.5 w-10 rounded-full bg-gray-300"
                                ></span>
                            </div>

                            <div
                                class="flex items-start justify-between gap-3 px-4 sm:px-6 pt-2 sm:pt-3.5 pb-3"
                            >
                                <div class="min-w-0 flex-1">
                                    <h3
                                        id="judul-modal-rincian"
                                        class="text-sm sm:text-lg font-bold text-gray-900 leading-snug"
                                    >
                                        Rincian Kuota & Riwayat Pegawai
                                    </h3>
                                    <p
                                        class="hidden sm:block text-xs text-gray-500 mt-0.5"
                                    >
                                        Lihat rincian saldo dan riwayat
                                        pengajuan cuti. Untuk mengubah saldo,
                                        klik angkanya pada baris pegawai. Cuti
                                        Terpakai diubah di Rekap Laporan.
                                    </p>

                                    <!-- Profil pegawai ringkas (satu baris) -->
                                    <div
                                        v-if="selectedPegawai"
                                        class="mt-2 flex items-center gap-2.5 min-w-0"
                                    >
                                        <div
                                            class="w-8 h-8 rounded-full bg-green-600 flex items-center justify-center text-white font-bold text-sm uppercase shadow-sm shrink-0"
                                        >
                                            {{
                                                selectedPegawai.nama?.charAt(
                                                    0,
                                                ) || "?"
                                            }}
                                        </div>
                                        <div class="min-w-0">
                                            <p
                                                class="font-bold text-gray-900 text-sm leading-tight truncate"
                                            >
                                                {{ selectedPegawai.nama }}
                                            </p>
                                            <p
                                                class="text-[11px] text-gray-500 leading-tight mt-0.5 truncate"
                                            >
                                                NIP:
                                                {{ selectedPegawai.nip || "-" }}
                                                &middot;
                                                {{
                                                    selectedPegawai.jabatan ||
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
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        class="w-5 h-5"
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
                        </div>

                        <!-- ============ 2) BODY (satu area scroll) ============ -->
                        <div
                            v-if="selectedPegawai"
                            class="flex-1 min-h-0 overflow-y-auto overscroll-contain"
                        >
                            <!-- 4 KPI ringkas (Hak → Terpakai → Sisa → Saldo Akhir).
                             HP: 2x2. >= sm: satu baris. -->
                            <div class="px-3 sm:px-6 pt-3 pb-3 sm:pb-2.5">
                                <div
                                    class="grid grid-cols-2 sm:grid-cols-4 gap-2 sm:gap-2.5"
                                >
                                    <!-- KPI 1: Hak -->
                                    <div
                                        class="bg-white px-2.5 py-2 rounded-xl border border-gray-200 shadow-sm text-center"
                                    >
                                        <p
                                            class="text-[10px] font-bold text-gray-400 uppercase tracking-wider leading-tight"
                                        >
                                            Hak {{ tahunAktif }}
                                        </p>
                                        <p
                                            class="text-lg sm:text-xl font-black text-gray-800 leading-tight mt-0.5"
                                        >
                                            {{
                                                getSisaTahunIni(selectedPegawai)
                                            }}
                                            <span
                                                class="text-[11px] font-normal"
                                                >hari</span
                                            >
                                        </p>
                                    </div>

                                    <!-- KPI 2: Terpakai (Rose) — angka tampil,
                                         sudah termasuk Tgh tahun ini -->
                                    <div
                                        class="bg-rose-50 px-2.5 py-2 rounded-xl border border-rose-100 shadow-sm text-center"
                                    >
                                        <p
                                            class="text-[10px] font-bold text-rose-500 uppercase tracking-wider leading-tight"
                                        >
                                            Terpakai {{ tahunAktif }}
                                        </p>
                                        <p
                                            class="text-lg sm:text-xl font-black text-rose-600 leading-tight mt-0.5"
                                        >
                                            {{
                                                getTerpakaiTampil(
                                                    selectedPegawai,
                                                )
                                            }}
                                            <span
                                                class="text-[11px] font-normal"
                                                >hari</span
                                            >
                                        </p>
                                    </div>

                                    <!-- KPI 3: Sisa (Biru) -->
                                    <div
                                        class="bg-blue-50 px-2.5 py-2 rounded-xl border border-blue-100 shadow-sm text-center"
                                    >
                                        <p
                                            class="text-[10px] font-bold text-blue-500 uppercase tracking-wider leading-tight"
                                        >
                                            Sisa {{ tahunAktif }}
                                        </p>
                                        <p
                                            class="text-lg sm:text-xl font-black text-blue-600 leading-tight mt-0.5"
                                        >
                                            {{
                                                getSisaTahunBerjalan(
                                                    selectedPegawai,
                                                )
                                            }}
                                            <span
                                                class="text-[11px] font-normal"
                                                >hari</span
                                            >
                                        </p>
                                    </div>

                                    <!-- KPI 4: Saldo Akhir (Hitam Solid) -->
                                    <div
                                        class="bg-slate-800 border border-slate-900 text-white px-2.5 py-2 rounded-xl shadow-md text-center"
                                    >
                                        <p
                                            class="text-[10px] font-bold text-slate-400 uppercase tracking-wider leading-tight"
                                        >
                                            Saldo Akhir {{ tahunAktif }}
                                        </p>
                                        <p
                                            class="text-lg sm:text-xl font-black text-white leading-tight mt-0.5"
                                        >
                                            {{ getSaldoAkhir(selectedPegawai) }}
                                            <span
                                                class="text-[11px] font-normal"
                                                >hari</span
                                            >
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <!-- ================= END KPI ================= -->

                            <div
                                class="p-3 sm:px-6 sm:py-4 space-y-3 sm:space-y-4"
                            >
                                <!-- Kotak Cara Perhitungan Saldo Akhir:
                                     "Saldo Akhir {tahun}: Hak X + Bawaan Y [+ Tgh
                                     tahun lalu T] − Terpakai Z = N hari".
                                     'Terpakai' = cuti terpakai (ikut koreksi bulanan
                                     Rekap Laporan) + Tgh tahun ini. Kalau Tgh tahun
                                     ini > 0, rinciannya tampil di baris kecil di
                                     bawah rumus.
                                     Kalau ada koreksi, catatan kecil di bawahnya
                                     menjelaskan bahwa angka sistem tidak dipakai
                                     dan menunjuk bulan yang dikoreksi. -->
                                <div
                                    class="bg-gray-50 px-3 sm:px-4 py-2 rounded-lg text-xs text-gray-600 border border-gray-100 leading-relaxed"
                                >
                                    Saldo Akhir {{ tahunAktif }}:
                                    <span class="font-semibold text-gray-800"
                                        >Hak
                                        {{
                                            getSisaTahunIni(selectedPegawai)
                                        }}</span
                                    >
                                    +
                                    <span class="font-semibold text-gray-800"
                                        >Bawaan
                                        {{
                                            getSaldoBawaanEligible(
                                                selectedPegawai,
                                            )
                                        }}</span
                                    >
                                    <template
                                        v-if="
                                            getSisaDitangguhkanN1(
                                                selectedPegawai,
                                            ) > 0
                                        "
                                    >
                                        +
                                        <span
                                            class="font-semibold text-gray-800"
                                            >Tgh {{ tahunAktif - 1 }}
                                            {{
                                                getSisaDitangguhkanN1(
                                                    selectedPegawai,
                                                )
                                            }}</span
                                        >
                                    </template>
                                    −
                                    <span class="font-semibold text-gray-800"
                                        >Terpakai
                                        {{
                                            getTerpakaiTampil(selectedPegawai)
                                        }}</span
                                    >
                                    =
                                    <span class="font-bold text-green-700"
                                        >{{
                                            getSaldoAkhir(selectedPegawai)
                                        }}
                                        hari</span
                                    >
                                    <p
                                        v-if="
                                            getSisaDitangguhkanTahunIni(
                                                selectedPegawai,
                                            ) > 0
                                        "
                                        class="mt-1 text-[11px] text-gray-500"
                                    >
                                        (Terpakai
                                        {{ getTerpakaiTampil(selectedPegawai) }}
                                        =
                                        {{
                                            getTerpakaiTampil(selectedPegawai) -
                                            getSisaDitangguhkanTahunIni(
                                                selectedPegawai,
                                            )
                                        }}
                                        hari digunakan +
                                        {{
                                            getSisaDitangguhkanTahunIni(
                                                selectedPegawai,
                                            )
                                        }}
                                        hari ditangguhkan)
                                    </p>
                                    <p
                                        v-if="adaKoreksi(selectedPegawai)"
                                        class="mt-1 text-[11px] text-amber-700"
                                    >
                                        Terpakai memakai angka koreksi Admin HR
                                        ({{
                                            getTerpakaiTampil(selectedPegawai)
                                        }}
                                        hari<template
                                            v-if="
                                                bulanDikoreksi(selectedPegawai)
                                                    .length > 0
                                            "
                                            >, dikoreksi pada bulan
                                            {{
                                                bulanDikoreksi(
                                                    selectedPegawai,
                                                ).join(", ")
                                            }}</template
                                        >). Angka dari sistem:
                                        {{
                                            getTerpakaiSistemTampil(
                                                selectedPegawai,
                                            )
                                        }}
                                        hari.
                                    </p>
                                    <p class="mt-1.5 text-[11px] text-gray-500">
                                        Terpakai dikunci di halaman ini.
                                        <Link
                                            :href="
                                                urlRekapLaporan(selectedPegawai)
                                            "
                                            class="font-semibold text-emerald-700 underline"
                                            >Ubah per bulan di Rekap
                                            Laporan</Link
                                        >.
                                    </p>
                                </div>
                                <!-- ================= END KOTAK PERHITUNGAN ================= -->

                                <!-- Catatan Admin HR (catatan_saldo), hanya tampil
                                     kalau ada isinya. Kotak oranye "Sisa
                                     Ditangguhkan" sudah dihapus karena angkanya
                                     sudah terlihat di rumus dan di kolom Tgh. -->
                                <p
                                    v-if="selectedPegawai.catatan_saldo"
                                    class="rounded-lg border border-gray-100 bg-gray-50 px-3 sm:px-4 py-2 text-xs text-gray-600 italic break-words"
                                >
                                    {{ selectedPegawai.catatan_saldo }}
                                </p>

                                <!-- RIWAYAT: menampilkan SEMUA riwayat (tidak dipotong) -->
                                <div>
                                    <h5
                                        class="font-bold text-gray-900 text-base mb-3 flex items-center gap-2"
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
                                        <span
                                            class="inline-block min-w-[20px] px-1.5 py-0.5 rounded-full bg-gray-100 text-gray-600 text-[11px] font-bold text-center"
                                            >{{
                                                selectedPegawai.pengajuan_cuti
                                                    ?.length || 0
                                            }}</span
                                        >
                                    </h5>

                                    <!-- Ringkasan untuk verifikasi Terpakai:
                                         total Cuti Tahunan disetujui di tahun aktif
                                         vs angka sistem (cuti disetujui saja; Tgh
                                         dihitung terpisah di kolom Tgh). -->
                                    <div
                                        class="mb-3 rounded-lg border px-3 py-2 text-xs leading-relaxed"
                                        :class="
                                            ringkasanDisetujui.total ===
                                            ringkasanDisetujui.sistem
                                                ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
                                                : 'border-amber-200 bg-amber-50 text-amber-800'
                                        "
                                    >
                                        Cuti Tahunan disetujui {{ tahunAktif }}:
                                        <span class="font-bold"
                                            >{{
                                                ringkasanDisetujui.total
                                            }}
                                            hari</span
                                        >
                                        dari
                                        {{ ringkasanDisetujui.jumlahPengajuan }}
                                        pengajuan. Sistem mencatat
                                        <span class="font-bold"
                                            >{{
                                                ringkasanDisetujui.sistem
                                            }}
                                            hari</span
                                        >.
                                        <template
                                            v-if="
                                                ringkasanDisetujui.total !==
                                                ringkasanDisetujui.sistem
                                            "
                                        >
                                            Angkanya berbeda, periksa riwayat di
                                            bawah.
                                        </template>
                                        <template
                                            v-if="
                                                getSisaDitangguhkanTahunIni(
                                                    selectedPegawai,
                                                ) > 0
                                            "
                                        >
                                            Ditambah Tgh
                                            {{
                                                getSisaDitangguhkanTahunIni(
                                                    selectedPegawai,
                                                )
                                            }}
                                            hari, Terpakai yang tampil
                                            {{
                                                getTerpakaiSistemTampil(
                                                    selectedPegawai,
                                                )
                                            }}
                                            hari.
                                        </template>
                                        <template
                                            v-if="adaKoreksi(selectedPegawai)"
                                        >
                                            Terpakai yang tampil di tabel:
                                            <span class="font-bold"
                                                >{{
                                                    getTerpakaiTampil(
                                                        selectedPegawai,
                                                    )
                                                }}
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
                                                    filterStatusRiwayat =
                                                        opsi.key;
                                                    expandedRiwayatId = null;
                                                "
                                                :aria-pressed="
                                                    filterStatusRiwayat ===
                                                    opsi.key
                                                "
                                                class="inline-flex min-h-[32px] cursor-pointer items-center gap-1 rounded-full border px-3 py-1 text-xs font-semibold transition"
                                                :class="
                                                    filterStatusRiwayat ===
                                                    opsi.key
                                                        ? 'border-emerald-600 bg-emerald-600 text-white'
                                                        : 'border-gray-300 bg-white text-gray-600 hover:bg-gray-50'
                                                "
                                            >
                                                {{ opsi.label }}
                                                <span
                                                    class="rounded-full px-1.5 text-[10px] font-bold"
                                                    :class="
                                                        filterStatusRiwayat ===
                                                        opsi.key
                                                            ? 'bg-white/25'
                                                            : 'bg-gray-100 text-gray-500'
                                                    "
                                                    >{{
                                                        jumlahPerStatus[
                                                            opsi.key
                                                        ]
                                                    }}</span
                                                >
                                            </button>
                                        </div>
                                        <label
                                            class="flex cursor-pointer select-none items-center gap-2 text-xs text-gray-600"
                                        >
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
                                                selectedPegawai.pengajuan_cuti
                                                    .length === 0
                                            "
                                        >
                                            Belum ada catatan riwayat pengajuan
                                            cuti.
                                        </template>
                                        <template v-else>
                                            Tidak ada pengajuan yang cocok
                                            dengan filter. Pilih status "Semua"
                                            atau matikan "Hanya Cuti Tahunan".
                                        </template>
                                    </div>

                                    <!-- List riwayat, garis warna kiri sesuai status asli -->
                                    <div v-else class="space-y-2.5">
                                        <div
                                            v-for="riwayat in riwayatTersaring"
                                            :key="riwayat.id"
                                            @click="toggleExpand(riwayat.id)"
                                            class="bg-white p-3 sm:p-4 rounded-xl border border-gray-200/80 text-sm flex flex-col gap-2.5 transition-colors duration-150 hover:shadow-sm cursor-pointer relative overflow-hidden group"
                                            :class="[
                                                kelasGarisStatus(
                                                    riwayat.status,
                                                ),
                                                expandedRiwayatId === riwayat.id
                                                    ? 'ring-2 ring-green-500/20'
                                                    : '',
                                            ]"
                                        >
                                            <!-- Baris atas: Jenis Cuti & Badge Status -->
                                            <div
                                                class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-1.5"
                                            >
                                                <span
                                                    class="font-bold text-gray-900 text-base break-words group-hover:text-green-700 transition"
                                                    >{{
                                                        riwayat.jenis_cuti
                                                    }}</span
                                                >
                                                <div
                                                    class="flex items-start sm:items-center justify-between sm:justify-start gap-2"
                                                >
                                                    <span
                                                        class="px-2.5 py-1 rounded-md font-bold text-[11px] sm:text-xs tracking-wide uppercase min-w-0 break-words"
                                                        :class="
                                                            kelasBadgeStatus(
                                                                riwayat.status,
                                                            )
                                                        "
                                                    >
                                                        {{
                                                            getStatusLabel(
                                                                riwayat.status,
                                                            )
                                                        }}
                                                    </span>
                                                    <svg
                                                        class="w-4 h-4 mt-1 sm:mt-0 text-gray-400 transition-transform duration-200 shrink-0"
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
                                                    >{{ riwayat.tanggal_mulai }}
                                                    s/d
                                                    {{
                                                        riwayat.tanggal_selesai
                                                    }}</span
                                                >
                                                <span>&middot;</span>
                                                <span
                                                    class="font-bold text-gray-900"
                                                    >{{
                                                        getDurasi(riwayat)
                                                    }}
                                                    Hari</span
                                                >
                                                <span
                                                    v-if="
                                                        hariDihitungBeda(
                                                            riwayat,
                                                        )
                                                    "
                                                    class="rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-700"
                                                    :title="`Cuti lintas tahun: hanya ${riwayat.hari_di_tahun_ini} hari yang jatuh di ${tahunAktif}`"
                                                    >dihitung di
                                                    {{ tahunAktif }}:
                                                    {{
                                                        riwayat.hari_di_tahun_ini
                                                    }}
                                                    hari</span
                                                >
                                            </div>

                                            <!-- Keterangan / Alasan -->
                                            <div
                                                v-if="riwayat.keterangan"
                                                class="text-gray-800 bg-gray-50/80 border border-gray-200/60 p-3 rounded-xl italic text-sm font-medium leading-relaxed break-words"
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
                                                v-if="
                                                    expandedRiwayatId ===
                                                    riwayat.id
                                                "
                                                @click.stop
                                                class="mt-1 pt-3 border-t-2 border-dashed border-green-200 bg-green-50/40 -mx-3 sm:-mx-4 -mb-3 sm:-mb-4 p-3 sm:p-4 rounded-b-xl space-y-3 text-xs cursor-default"
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
                                                            >Alamat Selama
                                                            Cuti</span
                                                        >
                                                        <p
                                                            class="font-medium text-gray-800 mt-0.5 break-words"
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
                                                            class="font-medium text-gray-800 mt-0.5 break-words"
                                                        >
                                                            {{
                                                                riwayat.no_telp ||
                                                                "-"
                                                            }}
                                                        </p>
                                                    </div>
                                                </div>

                                                <!-- Lampiran file / surat -->
                                                <div
                                                    class="flex flex-wrap items-center justify-between bg-white p-3 rounded-lg border border-green-100 gap-3"
                                                >
                                                    <div
                                                        class="flex items-center gap-2 min-w-0"
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
                                                        class="px-3 py-2 sm:py-1.5 bg-green-600 hover:bg-green-700 text-white font-semibold rounded-md shadow-xs transition shrink-0"
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
                                                    class="bg-white p-3 rounded-lg border border-green-100 space-y-2 sm:space-y-1"
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
                                                            class="w-3.5 h-3.5 mt-0.5 sm:mt-0 text-orange-400 shrink-0"
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
                                                            class="w-3.5 h-3.5 mt-0.5 sm:mt-0 text-gray-400 shrink-0"
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
                                                        <span
                                                            class="flex-1 min-w-0 break-words"
                                                        >
                                                            <template
                                                                v-if="
                                                                    step.level
                                                                "
                                                            >
                                                                L{{
                                                                    step.level
                                                                }}
                                                                &middot;
                                                            </template>
                                                            <strong
                                                                class="text-gray-800"
                                                                >{{
                                                                    step.nama
                                                                }}</strong
                                                            >
                                                            <span
                                                                class="text-gray-400 text-[10px]"
                                                            >
                                                                ({{
                                                                    step.label
                                                                }})
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
                            </div>
                        </div>

                        <!-- ============ 3) FOOTER (tetap) ============ -->
                        <div
                            class="shrink-0 border-t bg-gray-50 px-3 sm:px-6 pt-2.5 sm:pt-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))] sm:pb-3"
                        >
                            <div class="flex items-center sm:justify-end">
                                <button
                                    @click="closeModal"
                                    type="button"
                                    class="flex-1 sm:flex-none min-h-[44px] sm:min-h-0 justify-center items-center inline-flex px-6 py-2.5 sm:py-2 bg-gray-800 hover:bg-gray-700 text-white text-sm font-semibold rounded-xl transition shadow-sm cursor-pointer whitespace-nowrap"
                                >
                                    Tutup Rincian
                                </button>
                            </div>
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
                    <div
                        class="absolute inset-0"
                        @click="closeImportModal"
                    ></div>

                    <div
                        class="relative bg-white p-4 sm:p-6 pb-[calc(1rem+env(safe-area-inset-bottom))] sm:pb-6 rounded-t-2xl sm:rounded-2xl w-full max-w-md shadow-2xl max-h-[90dvh] overflow-y-auto overscroll-contain sm:my-auto"
                    >
                        <div
                            class="flex justify-between items-center mb-4 sm:mb-5 border-b pb-3"
                        >
                            <h3
                                class="text-base sm:text-lg font-bold text-gray-800"
                            >
                                Impor Kuota Cuti
                            </h3>
                            <button
                                type="button"
                                @click="closeImportModal"
                                aria-label="Tutup"
                                class="px-2 text-gray-400 hover:text-red-500 font-bold text-2xl leading-none cursor-pointer outline-none"
                            >
                                &times;
                            </button>
                        </div>

                        <form @submit.prevent="submitImportForm">
                            <p class="text-sm text-gray-500 mb-4">
                                Unggah file Excel (.xlsx) atau CSV berisi data
                                kuota cuti pegawai untuk diperbarui secara
                                massal.
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
                                Impor untuk tahun
                                <b>{{ tahunAktif }}</b> (sesuai Filter Tahun).
                                Gunakan template Rekap Cuti, sheet "Saldo
                                Tahunan". Yang diperbarui hanya angka Sisa
                                {{ tahunAktif - 2 }}, Sisa {{ tahunAktif - 1 }},
                                dan Hak {{ tahunAktif }}. Kolom hasil hitungan
                                diabaikan dan dihitung ulang oleh sistem. Sel
                                kosong tidak mengubah data.
                            </p>

                            <!-- TOMBOL AKSI -->
                            <div
                                class="flex flex-col-reverse gap-2 pt-4 border-t border-gray-100 sm:flex-row sm:justify-end"
                            >
                                <button
                                    type="button"
                                    @click="closeImportModal"
                                    class="min-h-[44px] sm:min-h-0 px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 cursor-pointer transition"
                                >
                                    Batal
                                </button>
                                <button
                                    type="submit"
                                    class="min-h-[44px] sm:min-h-0 px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 cursor-pointer transition disabled:opacity-50 disabled:cursor-not-allowed"
                                    :disabled="
                                        importForm.processing ||
                                        !importForm.file
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
                <!-- 'saldo-cuti:generate-tahun-baru'.          -->
                <!-- Tidak dijadwalkan otomatis.                -->
                <!-- Dua langkah: (1) pilih tahun, (2) konfirmasi. -->
                <!-- Tahun tujuan DINAMIS (tahun dilihat + 1).  -->
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
                        class="relative bg-white p-4 sm:p-6 pb-[calc(1rem+env(safe-area-inset-bottom))] sm:pb-6 rounded-t-2xl sm:rounded-2xl w-full max-w-md shadow-2xl max-h-[90dvh] overflow-y-auto overscroll-contain sm:my-auto"
                    >
                        <div
                            class="flex justify-between items-center mb-4 border-b pb-3"
                        >
                            <h3
                                class="text-base sm:text-lg font-bold text-gray-800"
                            >
                                Generate Saldo Tahun Baru
                            </h3>
                            <button
                                type="button"
                                @click="closeGenerateSaldoModal"
                                aria-label="Tutup"
                                class="px-2 text-gray-400 hover:text-red-500 font-bold text-2xl leading-none cursor-pointer outline-none"
                            >
                                &times;
                            </button>
                        </div>

                        <form
                            @submit.prevent="
                                langkahKonfirmasiGenerate
                                    ? submitGenerateSaldo()
                                    : lanjutKonfirmasiGenerate()
                            "
                        >
                            <!-- LANGKAH 1: pilih tahun -->
                            <template v-if="!langkahKonfirmasiGenerate">
                                <p class="text-sm text-gray-600 mb-4">
                                    Ini akan
                                    <strong
                                        >membuat saldo yang belum ada</strong
                                    >
                                    untuk pegawai aktif pada tahun di bawah.
                                    Saldo yang sudah ada tidak diubah, karena
                                    bawaannya otomatis diperbarui setiap kali
                                    cuti disetujui, dibatalkan, atau
                                    ditangguhkan. Bawaan dari sisa saldo biasa
                                    maks. 6 hari (12 hari jika sisa N-2 dan N-1
                                    sama-sama 12). Catatan penangguhan hanya
                                    untuk pelaporan, bukan carry-over.
                                </p>

                                <label
                                    class="block text-sm font-medium text-gray-700 mb-1"
                                >
                                    Tahun yang digenerate
                                </label>
                                <!-- Opsi dirender dinamis dari 'pilihanTahunGenerate'
                                 (computed: tahun yang dilihat + 1), sehingga selalu
                                 sama dengan label tombol. -->
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
                                    v-if="
                                        generateSaldoForm.tahun === currentYear
                                    "
                                    class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-md px-3 py-2 mb-2"
                                >
                                    Generate tahun berjalan akan menghitung
                                    ulang Sisa tahun lalu dan menimpa angka yang
                                    diisi manual atau diimpor. Hak yang sudah
                                    diisi tidak berubah.
                                </p>
                                <p
                                    v-if="generateSaldoForm.errors.tahun"
                                    class="text-sm text-red-600 mb-2"
                                >
                                    {{ generateSaldoForm.errors.tahun }}
                                </p>

                                <div
                                    class="flex flex-col-reverse gap-2 pt-4 border-t border-gray-100 mt-3 sm:flex-row sm:justify-end"
                                >
                                    <button
                                        type="button"
                                        @click="closeGenerateSaldoModal"
                                        class="min-h-[44px] sm:min-h-0 px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 cursor-pointer transition"
                                    >
                                        Batal
                                    </button>
                                    <button
                                        type="submit"
                                        class="min-h-[44px] sm:min-h-0 px-4 py-2 bg-emerald-600 text-white rounded-md hover:bg-emerald-700 cursor-pointer transition"
                                    >
                                        Lanjutkan
                                    </button>
                                </div>
                            </template>

                            <!-- LANGKAH 2: konfirmasi akhir. Request baru dikirim
                             setelah tombol "Ya, Generate Saldo" ditekan. -->
                            <template v-else>
                                <div
                                    class="rounded-lg border border-amber-200 bg-amber-50 p-3 sm:p-4 text-amber-900"
                                >
                                    <p class="text-sm font-bold">
                                        Generate saldo tahun
                                        {{ generateSaldoForm.tahun }}?
                                    </p>
                                    <ul
                                        class="mt-2 list-disc space-y-1 pl-5 text-xs leading-relaxed"
                                    >
                                        <li>
                                            Berlaku untuk
                                            <b>semua pegawai</b>, bukan satu
                                            orang.
                                        </li>
                                        <li>
                                            Hanya pegawai aktif yang belum punya
                                            saldo yang dibuatkan.
                                        </li>
                                        <li>
                                            Saldo yang sudah ada tidak diubah.
                                        </li>
                                    </ul>
                                </div>

                                <div
                                    class="flex flex-col-reverse gap-2 pt-4 border-t border-gray-100 mt-4 sm:flex-row sm:justify-end"
                                >
                                    <button
                                        type="button"
                                        @click="
                                            langkahKonfirmasiGenerate = false
                                        "
                                        :disabled="generateSaldoForm.processing"
                                        class="min-h-[44px] sm:min-h-0 px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 cursor-pointer transition disabled:opacity-50 disabled:cursor-not-allowed"
                                    >
                                        Kembali
                                    </button>
                                    <button
                                        type="submit"
                                        :disabled="generateSaldoForm.processing"
                                        class="min-h-[44px] sm:min-h-0 px-4 py-2 bg-emerald-600 text-white rounded-md hover:bg-emerald-700 cursor-pointer transition disabled:opacity-50 disabled:cursor-not-allowed"
                                    >
                                        {{
                                            generateSaldoForm.processing
                                                ? "Memproses..."
                                                : `Ya, Generate Saldo ${generateSaldoForm.tahun}`
                                        }}
                                    </button>
                                </div>
                            </template>
                        </form>
                    </div>
                </div>
            </Teleport>
            <!-- end div pembungkus tunggal -->
        </div>
    </MainLayout>
</template>
