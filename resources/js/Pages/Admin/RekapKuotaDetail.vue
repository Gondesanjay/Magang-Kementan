<script setup>
import {
    ref,
    computed,
    watch,
    nextTick,
    onMounted,
    onBeforeUnmount,
} from "vue";
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
 * Total hak + bawaan dibatasi maksimal 24 hari.
 * Jika backend sudah kirim saldo_bawaan_eligible, pakai itu.
 */
const getSaldoBawaanEligible = (pegawai) => {
    if (pegawai?.saldo_bawaan_eligible != null) {
        return Number(pegawai.saldo_bawaan_eligible) || 0;
    }
    const sisaN2 = Number(pegawai?.sisa_cuti_dua_tahun_lalu ?? 0);
    const sisaN1 = Number(pegawai?.carry_forward_normal ?? 0);
    const kuota = Number(pegawai?.kuota_tahunan ?? pegawai?.hak_tahun_ini ?? 0);
    const normal = sisaN2 === 12 && sisaN1 === 12 ? 12 : Math.min(6, sisaN1);
    return Math.max(0, Math.min(normal, 24 - kuota));
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
// Sisa ditangguhkan (Tgh) — tampil di tabel utama & modal Detail.
// Nilainya dihitung backend dari tabel cuti_ditangguhkans, KECUALI Admin HR
// mengisi override manual (kolom saldo_cutis.koreksi_ditangguhkan); angka
// yang dikirim backend di 'sisa_ditangguhkan_*' SUDAH memperhitungkan itu.
// HANYA INFORMASI: saldo cuti yang ditangguhkan sudah dikembalikan, jadi
// Tgh tidak menambah Terpakai maupun Bawaan.
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

// Angka Tgh murni HITUNGAN SISTEM (tanpa override Admin HR). Dipakai untuk
// keterangan "Ikut data sistem (X hari)" di form edit.
const getTghSistemN1 = (pegawai) =>
    Number(pegawai?.ditangguhkan_sistem_n1 ?? 0) || 0;

const getTghSistemN2 = (pegawai) =>
    Number(pegawai?.ditangguhkan_sistem_n2 ?? 0) || 0;

// Override Tgh dari Admin HR: null = ikut sistem, angka (TERMASUK 0) =
// dipakai apa adanya. Sama seperti koreksi cuti terpakai, jangan cek dengan
// `> 0` atau `!k`; bedakan null dan 0 lewat `=== null`.
const getKoreksiTgh = (nilai) =>
    nilai === null || nilai === undefined ? null : Math.max(0, Number(nilai) || 0);

// Dipakai untuk v-if di modal: blok "Sisa Ditangguhkan" hanya
// dirender kalau salah satu nilainya > 0.
const punyaSisaDitangguhkan = (pegawai) =>
    getSisaDitangguhkanN2(pegawai) > 0 || getSisaDitangguhkanN1(pegawai) > 0;

// Teks tooltip yang sama untuk semua tampilan Tgh.
const KETERANGAN_TGH =
    "Hari cuti ditangguhkan (saldo sudah dikembalikan, hanya informasi)";

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

// ================================================================
// KARTU PEGAWAI (TAMPILAN MOBILE / TABLET)
// Di layar di bawah breakpoint 'lg' (1024px), tabel 12 kolom diganti kartu per
// pegawai supaya tidak perlu geser horizontal. Angkanya TETAP memakai
// helper yang sama dengan tabel (satu sumber logika).
// ================================================================
const getStatKartu = (pegawai) => {
    const t = tahunAktif.value;
    const akhirTahun = (tahun) => String(tahun).slice(-2);

    return [
        {
            key: "sisa-n2",
            label: `Sisa ${t - 2}`,
            nilai: getSisaDuaTahunLalu(pegawai),
            kelas: "bg-slate-200 text-slate-700 border-slate-300/80",
            labelKelas: "text-slate-500",
        },
        {
            key: "tgh-n2",
            label: `Tgh '${akhirTahun(t - 2)}`,
            nilai: getSisaDitangguhkanN2(pegawai),
            kelas: "bg-orange-100 text-orange-800 border-orange-300/80",
            labelKelas: "text-orange-600",
            title: KETERANGAN_TGH,
        },
        {
            key: "sisa-n1",
            label: `Sisa ${t - 1}`,
            nilai: getSisaTahunLalu(pegawai),
            kelas: "bg-slate-200 text-slate-700 border-slate-300/80",
            labelKelas: "text-slate-500",
        },
        {
            key: "tgh-n1",
            label: `Tgh '${akhirTahun(t - 1)}`,
            nilai: getSisaDitangguhkanN1(pegawai),
            kelas: "bg-orange-100 text-orange-800 border-orange-300/80",
            labelKelas: "text-orange-600",
            title: KETERANGAN_TGH,
        },
        {
            key: "bawaan",
            label: `Bawaan ${t}`,
            nilai: getSaldoBawaanEligible(pegawai),
            kelas: "bg-emerald-100 text-emerald-800 border-emerald-300/80",
            labelKelas: "text-slate-500",
        },
        {
            key: "hak",
            label: `Hak ${t}`,
            nilai: getSisaTahunIni(pegawai),
            kelas: "bg-blue-100 text-blue-800 border-blue-300/80",
            labelKelas: "text-slate-500",
        },
        {
            key: "total",
            label: `Total ${t}`,
            nilai: getTotalTersedia(pegawai),
            kelas: "bg-indigo-100 text-indigo-800 border-indigo-300/80",
            labelKelas: "text-slate-500",
        },
        {
            key: "terpakai",
            label: `Terpakai ${t}`,
            nilai: pegawai.cuti_terpakai || 0,
            kelas: "bg-rose-100 text-rose-800 border-rose-300/80",
            labelKelas: "text-slate-500",
            bintang: adaKoreksi(pegawai),
            title: adaKoreksi(pegawai)
                ? `Dikoreksi Admin HR (sistem mencatat ${getTerpakaiSistem(pegawai)} hari)`
                : "",
        },
    ];
};

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
//   - pakai_sistem_tgh_n1 + ditangguhkan_n1 -> Ditangguhkan {tahun-1}
//   - pakai_sistem_tgh_n2 + ditangguhkan_n2 -> Ditangguhkan {tahun-2}
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
// DITANGGUHKAN (Tgh) — pola override yang SAMA dengan koreksi terpakai:
//   - pakai_sistem_tgh_nX = true  -> ditangguhkan_nX dikirim null (ikut
//                                    hitungan sistem dari cuti_ditangguhkans)
//   - pakai_sistem_tgh_nX = false -> angka di kotak dipakai apa adanya,
//                                    TERMASUK 0
// Tgh hanya informasi pelaporan; mengubahnya TIDAK mengubah Terpakai,
// Bawaan, maupun Saldo Akhir (saldo sudah dikembalikan oleh alur
// Penangguhan Cuti).
const saldoForm = useForm({
    hak_tahun_ini: 0,
    pakai_sistem: true,
    koreksi_saldo: "",
    carry_forward_normal: 0,
    sisa_cuti_dua_tahun_lalu: 0,
    pakai_sistem_tgh_n1: true,
    ditangguhkan_n1: 0,
    pakai_sistem_tgh_n2: true,
    ditangguhkan_n2: 0,
});

const isSavingSaldo = computed(() => saldoForm.processing);

// Pesan sukses singkat di dalam modal setelah Simpan Saldo (modal tidak
// ditutup otomatis).
const pesanSukses = ref("");
let timerPesanSukses = null;

// Nilai dari data pegawai dijadikan "defaults" form, sehingga
// saldoForm.isDirty hanya true kalau admin benar-benar mengubah isian.
// Tanpa koreksi (null): checkbox tercentang dan kotak menampilkan angka
// sistem. Dengan koreksi (termasuk 0): checkbox mati dan kotak berisi
// angka koreksi. Aturan yang sama berlaku untuk Ditangguhkan N-1 & N-2.
const isiSaldoFormDariPegawai = (pegawai) => {
    const koreksi = getKoreksiSaldo(pegawai);
    const koreksiTghN1 = getKoreksiTgh(pegawai?.koreksi_ditangguhkan_n1);
    const koreksiTghN2 = getKoreksiTgh(pegawai?.koreksi_ditangguhkan_n2);

    saldoForm.defaults({
        hak_tahun_ini: getSisaTahunIni(pegawai),
        pakai_sistem: koreksi === null,
        koreksi_saldo: koreksi ?? getTerpakaiSistem(pegawai),
        carry_forward_normal: getSisaTahunLalu(pegawai),
        sisa_cuti_dua_tahun_lalu: getSisaDuaTahunLalu(pegawai),
        pakai_sistem_tgh_n1: koreksiTghN1 === null,
        ditangguhkan_n1: koreksiTghN1 ?? getTghSistemN1(pegawai),
        pakai_sistem_tgh_n2: koreksiTghN2 === null,
        ditangguhkan_n2: koreksiTghN2 ?? getTghSistemN2(pegawai),
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

// Sama untuk Ditangguhkan N-1 dan N-2: dicentang -> kotak kembali ke angka
// hitungan sistem.
watch(
    () => saldoForm.pakai_sistem_tgh_n1,
    (dicentang) => {
        if (dicentang && selectedPegawai.value) {
            saldoForm.ditangguhkan_n1 = getTghSistemN1(selectedPegawai.value);
            saldoForm.clearErrors("ditangguhkan_n1");
        }
    },
);

watch(
    () => saldoForm.pakai_sistem_tgh_n2,
    (dicentang) => {
        if (dicentang && selectedPegawai.value) {
            saldoForm.ditangguhkan_n2 = getTghSistemN2(selectedPegawai.value);
            saldoForm.clearErrors("ditangguhkan_n2");
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
            // Ditangguhkan N-1 & N-2: aturan yang sama dengan koreksi terpakai.
            ditangguhkan_n1: data.pakai_sistem_tgh_n1
                ? null
                : data.ditangguhkan_n1 === "" || data.ditangguhkan_n1 == null
                  ? null
                  : Number(data.ditangguhkan_n1),
            ditangguhkan_n2: data.pakai_sistem_tgh_n2
                ? null
                : data.ditangguhkan_n2 === "" || data.ditangguhkan_n2 == null
                  ? null
                  : Number(data.ditangguhkan_n2),
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
                clearTimeout(timerPesanSukses);
                timerPesanSukses = setTimeout(
                    () => (pesanSukses.value = ""),
                    4000,
                );
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
    clearTimeout(timerPesanSukses);
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
    clearTimeout(timerPesanSukses);
    pesanSukses.value = "";
    saldoForm.clearErrors();
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
const tutupModalSaatEsc = (event) => {
    if (event.key !== "Escape") return;
    if (isGenerateSaldoModalOpen.value) closeGenerateSaldoModal();
    else if (isImportModalOpen.value) closeImportModal();
    else if (showModal.value) closeModal();
};

onMounted(() => {
    document.addEventListener("click", tutupDropdownSaatKlikLuar);
    document.addEventListener("keydown", tutupModalSaatEsc);
});
onBeforeUnmount(() => {
    document.removeEventListener("click", tutupDropdownSaatKlikLuar);
    document.removeEventListener("keydown", tutupModalSaatEsc);
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
// Dropdown di template membaca array ini lewat v-for.
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
                        <h2 class="text-base sm:text-lg font-bold text-gray-800">
                            Rekap Kuota Detail Cuti
                        </h2>
                        <p class="text-xs sm:text-sm text-gray-500 mt-0.5">
                            Lihat sisa, kelola saldo, dan riwayat pemakaian
                            kuota cuti tahunan tiap pegawai.
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

                        <!-- Tombol Generate Saldo Tahun Baru (Manual) -->
                        <button
                            type="button"
                            @click="openGenerateSaldoModal"
                            title="Generate Saldo Tahun Baru (Manual)"
                            class="inline-flex items-center justify-center gap-1.5 sm:gap-2 min-h-[44px] sm:min-h-0 px-3 sm:px-4 xl:px-5 py-2.5 bg-emerald-600 border border-transparent rounded-full font-semibold text-xs sm:text-sm text-white text-center leading-tight sm:whitespace-nowrap hover:bg-emerald-700 transition ease-in-out duration-150 shadow-sm cursor-pointer sm:flex-none"
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
                            Generate Tahun Baru
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
                            <span class="text-xs sm:text-sm font-medium text-slate-400"
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
                            <span class="text-xs sm:text-sm font-medium text-slate-400"
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
                            <span class="text-xs sm:text-sm font-medium text-slate-400"
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
                    <div class="relative order-1 min-w-0 lg:min-w-[12rem] lg:flex-1">
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
                                    'rotate-180': dropdownTerbuka === 'kelompok',
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
                        <span class="min-w-0 break-words">{{ chip.label }}</span>
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
                        <p
                            class="text-sm font-semibold text-gray-600 max-w-sm"
                        >
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
                     nama + Saldo Akhir di atas, 8 angka rincian di tengah
                     (grid 4 kolom), tombol Kelola Kuota di bawah.
                     Tanda * = Terpakai dikoreksi manual Admin HR. -->
                <p
                    v-if="punyaData"
                    class="mb-2 text-[11px] leading-relaxed text-slate-500 lg:hidden"
                >
                    <span class="font-semibold text-orange-600">Tgh</span> =
                    cuti ditangguhkan (saldo sudah dikembalikan, hanya
                    informasi).
                    <span class="font-semibold text-amber-600">*</span> =
                    Terpakai dikoreksi manual Admin HR.
                </p>
                <div
                    v-if="punyaData"
                    class="grid grid-cols-1 gap-3 md:grid-cols-2 lg:hidden"
                >
                    <div
                        v-for="(pegawai, index) in dataPegawai.data"
                        :key="pegawai.id"
                        class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm"
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
                                <span
                                    class="mt-1 inline-block min-w-[40px] rounded-md border border-slate-900 bg-slate-800 px-2.5 py-1 text-sm font-extrabold text-white shadow-sm"
                                >
                                    {{ getSaldoAkhir(pegawai) }}
                                </span>
                            </div>
                        </div>

                        <div
                            class="mt-3 grid grid-cols-4 gap-x-2 gap-y-2.5 border-t border-slate-100 pt-3"
                        >
                            <div
                                v-for="stat in getStatKartu(pegawai)"
                                :key="stat.key"
                                class="flex flex-col items-center gap-1 text-center"
                                :title="stat.title"
                            >
                                <span
                                    class="text-[10px] font-semibold leading-tight"
                                    :class="stat.labelKelas"
                                >
                                    {{ stat.label }}
                                </span>
                                <span
                                    class="inline-block min-w-[32px] rounded-md border px-2 py-1 text-xs font-bold"
                                    :class="stat.kelas"
                                >
                                    {{ stat.nilai
                                    }}<sup
                                        v-if="stat.bintang"
                                        class="font-black text-amber-600"
                                        >*</sup
                                    >
                                </span>
                            </div>
                        </div>

                        <!-- Rumus ringkas + tombol aksi dalam satu baris -->
                        <div
                            class="mt-3 flex items-center justify-between gap-2 border-t border-slate-100 pt-3"
                        >
                            <p class="min-w-0 text-[11px] leading-snug text-slate-500">
                                Total
                                <span class="font-semibold text-slate-700">{{
                                    getTotalTersedia(pegawai)
                                }}</span>
                                &minus; Terpakai
                                <span class="font-semibold text-slate-700">{{
                                    pegawai.cuti_terpakai || 0
                                }}</span>
                                = Saldo
                                <span class="font-bold text-slate-800">{{
                                    getSaldoAkhir(pegawai)
                                }}</span>
                            </p>
                            <button
                                @click="openDetailModal(pegawai)"
                                type="button"
                                class="inline-flex min-h-[40px] shrink-0 cursor-pointer items-center justify-center gap-1.5 rounded-full border border-gray-300 bg-white px-3.5 py-1.5 text-xs font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50 hover:text-gray-900"
                                title="Lihat Riwayat & Kelola Kuota"
                            >
                                <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    class="h-4 w-4 text-gray-500"
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
                                Kelola
                            </button>
                        </div>
                    </div>
                </div>

                <!--
          TABEL MONITORING (LAYAR LEBAR, >= lg / 1024px): SATU BARIS PER PEGAWAI.
          Semua label tahun di header DINAMIS mengikuti Filter Tahun
          ('tahunAktif'). Setiap angka dibungkus "kapsul" warna dengan
          min-w tetap supaya sejajar; Saldo Akhir diberi background gelap
          sebagai fokus utama. Dua kolom Tgh hanya informasi.

          Tampilan disamakan dengan Rekap Laporan: kartu ber-border membulat,
          kolom No & Nama menempel (sticky) di kiri saat tabel digeser, efek
          hover ungu. Tabel 'w-full' dan rapat ('px-2 py-3') — hanya bisa
          digeser kalau layar tidak muat. Di layar kecil diganti kartu di atas.
        -->
                <div
                    v-if="punyaData"
                    class="hidden lg:block overflow-x-auto rounded-lg border border-slate-200 bg-white"
                >
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr
                                class="bg-slate-50 border-b border-slate-200 text-[10px] leading-tight text-slate-500 uppercase tracking-wide font-bold align-middle"
                            >
                                <th
                                    class="sticky left-0 z-20 bg-slate-50 w-12 min-w-[3rem] px-2 py-3 text-center border-r border-slate-200"
                                >
                                    No
                                </th>
                                <th
                                    class="sticky left-12 z-20 bg-slate-50 min-w-[190px] px-3 py-3 border-r-2 border-slate-300 shadow-[2px_0_4px_-2px_rgba(0,0,0,0.08)]"
                                >
                                    Nama Pegawai
                                </th>
                                <th class="px-2 py-3 text-center">
                                    Sisa {{ tahunAktif - 2 }}
                                </th>
                                <th
                                    class="px-2 py-3 text-center text-orange-600"
                                    :title="KETERANGAN_TGH"
                                >
                                    Tgh '{{
                                        (tahunAktif - 2).toString().slice(-2)
                                    }}
                                </th>
                                <th class="px-2 py-3 text-center">
                                    Sisa {{ tahunAktif - 1 }}
                                </th>
                                <th
                                    class="px-2 py-3 text-center text-orange-600"
                                    :title="KETERANGAN_TGH"
                                >
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
                                v-for="(pegawai, index) in dataPegawai.data"
                                :key="pegawai.id"
                                class="group border-b border-slate-100 hover:bg-violet-50 hover:shadow-[inset_4px_0_0_0_theme(colors.violet.400)] transition-all duration-150"
                            >
                                <!-- No urut (ikut halaman paginasi) -->
                                <td
                                    class="sticky left-0 z-10 bg-white group-hover:bg-violet-50 w-12 min-w-[3rem] px-2 py-3 text-center text-sm font-semibold text-slate-800 border-r border-slate-200 transition-colors duration-150"
                                >
                                    {{ (dataPegawai.from ?? 1) + index }}
                                </td>

                                <!-- Nama & Kelompok Substansi (boleh membungkus) -->
                                <td
                                    class="sticky left-12 z-10 bg-white group-hover:bg-violet-50 min-w-[190px] px-3 py-3 border-r-2 border-slate-300 shadow-[2px_0_4px_-2px_rgba(0,0,0,0.08)] transition-colors duration-150"
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

                                <!-- Sisa N-2 -->
                                <td class="px-2 py-3 text-center">
                                    <span
                                        class="inline-block min-w-[32px] px-2 py-1 bg-slate-200 text-slate-700 rounded-md font-semibold text-xs border border-slate-300/80"
                                    >
                                        {{ getSisaDuaTahunLalu(pegawai) }}
                                    </span>
                                </td>

                                <!-- Ditangguhkan N-2 (Oranye): informasi,
                                     tidak menjadi bawaan -->
                                <td class="px-2 py-3 text-center">
                                    <span
                                        class="inline-block min-w-[32px] px-2 py-1 bg-orange-100 text-orange-800 rounded-md font-bold text-xs border border-orange-300/80"
                                        :title="KETERANGAN_TGH"
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

                                <!-- Ditangguhkan N-1 (Oranye): informasi,
                                     tidak menjadi bawaan -->
                                <td class="px-2 py-3 text-center">
                                    <span
                                        class="inline-block min-w-[32px] px-2 py-1 bg-orange-100 text-orange-800 rounded-md font-bold text-xs border border-orange-300/80"
                                        :title="KETERANGAN_TGH"
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
                                        class="inline-flex items-center gap-1.5 px-2.5 py-1.5 border border-gray-300 rounded-full text-xs font-medium text-gray-700 bg-white hover:bg-gray-50 hover:text-gray-900 transition shadow-sm cursor-pointer whitespace-nowrap"
                                        title="Lihat Riwayat & Kelola Kuota"
                                        aria-label="Kelola Kuota"
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
                                        <!-- Tombol Aksi di tabel SELALU hanya ikon (tooltip
                                             + aria-label tetap ada) supaya tampilannya sama
                                             di semua lebar layar dan zoom. -->
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Catatan kaki: hanya tampil kalau ada minimal 1 baris yang
                     angka Terpakai-nya dikoreksi manual Admin HR. -->
                <p
                    v-if="adaItemDikoreksi"
                    class="mt-3 text-xs text-amber-700"
                >
                    * Angka bertanda bintang disesuaikan manual oleh Admin HR. Lihat angka sistem di “Kelola Kuota”.
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
                 MODAL DETAIL KUOTA + EDIT SALDO + RIWAYAT CUTI PEGAWAI
                 Struktur 3 bagian:
                   1) Header  : tetap di atas (judul, pegawai, tombol X)
                   2) Body    : SATU-SATUNYA area yang scroll
                   3) Footer  : tetap di bawah (Batalkan, Simpan, Tutup)
                 HP   : bottom sheet (sudut atas membulat, handle, tinggi
                        maks 88dvh), bisa ditutup dengan X, klik backdrop,
                        geser ke bawah, atau Esc.
                 >= sm: dialog di tengah. Lebar dibatasi 'sm:max-w-lg'
                        (512px) supaya tidak terlalu lebar; tinggi maks =
                        layar - 2rem, sehingga muat di zoom 100%.
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
                                    Kelola saldo cuti pegawai dan lihat riwayat
                                    pengajuannya di satu tempat
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
                                            selectedPegawai.nama?.charAt(0) ||
                                            "?"
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
                        <!-- Pembungkus KPI + Tab. Layar >= sm: MENEMPEL di atas
                             saat isi di-scroll, supaya Saldo Akhir selalu terlihat
                             ketika admin mengubah angka. HP: 'contents' (tidak
                             menempel) agar ruang form tetap lega; hanya tab yang
                             menempel seperti sebelumnya. -->
                        <div
                            class="contents sm:block sm:sticky sm:top-0 sm:z-10 sm:bg-white"
                        >
                        <!-- 4 KPI ringkas (Hak → Terpakai → Sisa → Saldo Akhir).
                             HP: 2x2. >= sm: satu baris. -->
                        <div class="px-3 sm:px-6 pt-3 sm:pt-3 pb-3 sm:pb-2.5">
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
                                        {{ getSisaTahunIni(selectedPegawai) }}
                                        <span class="text-[11px] font-normal"
                                            >hari</span
                                        >
                                    </p>
                                </div>

                                <!-- KPI 2: Terpakai (Rose) -->
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
                                        {{ selectedPegawai.cuti_terpakai || 0 }}
                                        <span class="text-[11px] font-normal"
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
                                        <span class="text-[11px] font-normal"
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
                                        <span class="text-[11px] font-normal"
                                            >hari</span
                                        >
                                    </p>
                                </div>
                            </div>
                        </div>
                        <!-- ================= END KPI ================= -->

                        <!-- TAB: Kelola Saldo | Riwayat. Label dipendekkan di
                             HP ("Saldo"). Angka pada tab Riwayat = jumlah
                             seluruh riwayat. Tab menempel di atas saat scroll. -->
                        <div
                            class="sticky top-0 z-10 sm:static bg-white border-b border-gray-200 px-3 sm:px-6"
                            role="tablist"
                        >
                            <div class="flex gap-1">
                                <button
                                    type="button"
                                    role="tab"
                                    :aria-selected="modalTab === 'saldo'"
                                    @click="modalTab = 'saldo'"
                                    class="flex-1 sm:flex-none px-4 py-2.5 text-sm font-semibold border-b-2 -mb-px transition cursor-pointer"
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
                                    class="flex-1 sm:flex-none px-4 py-2.5 text-sm font-semibold border-b-2 -mb-px transition cursor-pointer"
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

                        </div>
                        <!-- END pembungkus KPI + Tab -->

                        <!-- ISI TAB -->
                        <div class="p-3 sm:px-6 sm:py-4 space-y-3 sm:space-y-4">
                            <template v-if="modalTab === 'saldo'">
                                <!-- Kotak Cara Perhitungan Saldo Akhir:
                                 "Saldo Akhir {tahun}: Hak X + Bawaan Y - Terpakai Z = N hari".
                                 'Terpakai' dari backend SUDAH final (angka Admin
                                 bila ada, kalau tidak angka sistem). Kalau Admin
                                 mengisi koreksi, catatan kecil di bawah rumus
                                 menjelaskan bahwa angka sistem tidak dipakai. -->
                                <div
                                    class="bg-gray-50 px-3 sm:px-4 py-2 rounded-lg text-xs text-gray-600 border border-gray-100 leading-relaxed"
                                >
                                    Saldo Akhir {{ tahunAktif }}:
                                    <span class="font-semibold text-gray-800"
                                        >Hak
                                        {{ getSisaTahunIni(selectedPegawai) }}</span
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
                                    -
                                    <span class="font-semibold text-gray-800"
                                        >Terpakai
                                        {{
                                            selectedPegawai.cuti_terpakai || 0
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
                                        v-if="adaKoreksi(selectedPegawai)"
                                        class="mt-1 text-[11px] text-amber-700"
                                    >
                                        Terpakai memakai angka koreksi Admin HR
                                        ({{
                                            getKoreksiSaldo(selectedPegawai)
                                        }}
                                        hari). Angka dari sistem:
                                        {{
                                            getTerpakaiSistem(selectedPegawai)
                                        }}
                                        hari.
                                    </p>
                                </div>
                                <!-- ================= END KOTAK PERHITUNGAN ================= -->

                                <!-- ========================================== -->
                                <!-- FITUR: EDIT SALDO PERORANGAN               -->
                                <!-- Satu-satunya tempat mengubah kuota/saldo    -->
                                <!-- cuti pegawai. Tombol Simpan & Batalkan ada  -->
                                <!-- di footer modal (terhubung lewat form=id).  -->
                                <!-- ========================================== -->
                                <div
                                    class="rounded-xl border border-emerald-200 bg-emerald-50/60 p-3 sm:p-4"
                                >
                                    <div
                                        class="flex items-center justify-between gap-2"
                                    >
                                        <p
                                            class="text-sm font-bold text-emerald-900"
                                        >
                                            Edit Saldo Perorangan
                                        </p>
                                        <span
                                            class="shrink-0 text-[10px] font-bold text-emerald-700 bg-emerald-100 border border-emerald-300 rounded-full px-2 py-0.5 uppercase tracking-wide"
                                        >
                                            Admin HR
                                        </span>
                                    </div>
                                    <p
                                        class="text-xs text-emerald-700/80 mt-0.5 mb-2.5"
                                    >
                                        Perubahan ini berlaku untuk tahun
                                        {{ tahunAktif }}.
                                    </p>

                                    <!-- Form dibagi 2 kelompok, semua baris memakai
                                         grid yang sama: [label] [kolom angka]. Kolom
                                         angka SAMA lebar (5rem di HP, 6rem >= sm) dan
                                         sejajar di kedua kelompok.
                                           1) Tahun berjalan : Hak, Koreksi Terpakai
                                           2) Saldo tahun lalu: Sisa N-1, Sisa N-2
                                         'Ditangguhkan' (informasi, tidak memengaruhi
                                         Saldo Akhir) tampil di bawah label Sisa N-1 /
                                         N-2 sebagai kotak angka kecil yang bisa diedit,
                                         lengkap dengan checkbox "Ikut data sistem",
                                         karena penangguhan hanya ada untuk tahun lalu. -->
                                    <form
                                        id="form-saldo"
                                        @submit.prevent="simpanSaldo"
                                        class="space-y-3"
                                    >
                                        <!-- ===== KELOMPOK 1: TAHUN BERJALAN ===== -->
                                        <div
                                            class="overflow-hidden rounded-lg border border-emerald-100 bg-white divide-y divide-gray-100"
                                        >
                                            <div
                                                class="bg-gray-50 px-3 py-1.5 text-[10px] font-bold uppercase tracking-wide text-gray-500"
                                            >
                                                Tahun {{ tahunAktif }}
                                            </div>

                                            <!-- Hak tahun berjalan -->
                                            <div class="grid grid-cols-[minmax(0,1fr)_5rem] sm:grid-cols-[minmax(0,1fr)_6rem] items-center gap-x-3 px-3 py-2.5">
                                                <label
                                                    for="input-hak-tahun"
                                                    class="text-xs font-semibold leading-snug text-blue-800"
                                                >
                                                    Hak Cuti
                                                    <span
                                                        class="font-normal text-gray-500"
                                                        >(Jatah Tahunan)</span
                                                    >
                                                </label>
                                                <input
                                                    id="input-hak-tahun"
                                                    type="number"
                                                    inputmode="numeric"
                                                    v-model="saldoForm.hak_tahun_ini"
                                                    min="0"
                                                    class="w-full h-11 sm:h-9 rounded-md border border-gray-300 bg-white px-2 text-center text-base sm:text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-500"
                                                />
                                                <p
                                                    v-if="saldoForm.errors.hak_tahun_ini"
                                                    class="col-span-2 mt-1 text-[11px] text-red-600"
                                                >
                                                    {{ saldoForm.errors.hak_tahun_ini }}
                                                </p>
                                            </div>

                                            <!-- Koreksi Jumlah Cuti Terpakai (angka FINAL,
                                                 bukan +/-). Checkbox dicentang = ikut angka
                                                 sistem (kotak dinonaktifkan). Tidak dicentang
                                                 = angka di kotak dipakai apa adanya, termasuk
                                                 0. Batas 0..365 sesuai validasi backend.
                                                 Angka dari sistem digabung ke label checkbox. -->
                                            <div class="grid grid-cols-[minmax(0,1fr)_5rem] sm:grid-cols-[minmax(0,1fr)_6rem] items-center gap-x-3 bg-amber-50/50 px-3 py-2.5">
                                                <div class="min-w-0">
                                                    <label
                                                        for="input-koreksi-saldo"
                                                        class="block text-xs font-semibold leading-snug text-amber-800"
                                                    >
                                                        Koreksi Jumlah Cuti Terpakai
                                                        (Hari)
                                                    </label>
                                                    <label
                                                        class="mt-1 flex cursor-pointer select-none items-center gap-2 py-0.5 text-[11px] font-medium text-amber-800"
                                                    >
                                                        <input
                                                            type="checkbox"
                                                            v-model="saldoForm.pakai_sistem"
                                                            class="h-5 w-5 sm:h-4 sm:w-4 shrink-0 rounded border-amber-300 text-amber-600 focus:ring-amber-500"
                                                        />
                                                        <span
                                                            >Gunakan data dari sistem
                                                            ({{
                                                                getTerpakaiSistem(
                                                                    selectedPegawai,
                                                                )
                                                            }}
                                                            hari)</span
                                                        >
                                                    </label>
                                                </div>
                                                <input
                                                    id="input-koreksi-saldo"
                                                    type="number"
                                                    inputmode="numeric"
                                                    step="1"
                                                    min="0"
                                                    max="365"
                                                    v-model="saldoForm.koreksi_saldo"
                                                    :disabled="saldoForm.pakai_sistem"
                                                    :required="!saldoForm.pakai_sistem"
                                                    class="w-full h-11 sm:h-9 rounded-md border border-amber-300 bg-white px-2 text-center text-base sm:text-sm focus:border-amber-500 focus:ring-2 focus:ring-amber-500 disabled:cursor-not-allowed disabled:border-gray-200 disabled:bg-gray-100 disabled:text-gray-500"
                                                />
                                                <p
                                                    v-if="saldoForm.errors.koreksi_saldo"
                                                    class="col-span-2 mt-1 text-[11px] text-red-600"
                                                >
                                                    {{ saldoForm.errors.koreksi_saldo }}
                                                </p>
                                            </div>
                                        </div>

                                        <!-- ===== KELOMPOK 2: SALDO TAHUN LALU ===== -->
                                        <div
                                            class="overflow-hidden rounded-lg border border-emerald-100 bg-white divide-y divide-gray-100"
                                        >
                                            <div
                                                class="bg-gray-50 px-3 py-1.5 text-[10px] font-bold uppercase tracking-wide text-gray-500"
                                            >
                                                Saldo Tahun Lalu
                                            </div>

                                            <!-- Sisa N-1 (carry forward) + Ditangguhkan N-1 (bisa diedit) -->
                                            <div class="grid grid-cols-[minmax(0,1fr)_5rem] sm:grid-cols-[minmax(0,1fr)_6rem] items-center gap-x-3 px-3 py-2.5">
                                                <div class="min-w-0">
                                                    <label
                                                        for="input-sisa-n1"
                                                        class="block text-xs font-semibold leading-snug text-emerald-800"
                                                    >
                                                        Sisa Cuti {{ tahunAktif - 1 }}
                                                    </label>

                                                    <!-- Ditangguhkan N-1: kotak angka + checkbox
                                                         "Ikut data sistem". Hanya informasi,
                                                         tidak memengaruhi Saldo Akhir. -->
                                                    <div
                                                        class="mt-1.5 flex items-center gap-1.5 text-[11px] text-orange-700"
                                                        :title="KETERANGAN_TGH"
                                                    >
                                                        <label
                                                            for="input-tgh-n1"
                                                            class="font-semibold"
                                                            >Ditangguhkan:</label
                                                        >
                                                        <input
                                                            id="input-tgh-n1"
                                                            type="number"
                                                            inputmode="numeric"
                                                            step="1"
                                                            min="0"
                                                            max="365"
                                                            v-model="saldoForm.ditangguhkan_n1"
                                                            :disabled="saldoForm.pakai_sistem_tgh_n1"
                                                            :required="!saldoForm.pakai_sistem_tgh_n1"
                                                            class="h-9 sm:h-8 w-16 sm:w-14 rounded-md border border-orange-300 bg-white px-1 text-center text-base sm:text-sm focus:border-orange-500 focus:ring-2 focus:ring-orange-500 disabled:cursor-not-allowed disabled:border-gray-200 disabled:bg-gray-100 disabled:text-gray-500"
                                                        />
                                                        <span>hari</span>
                                                    </div>
                                                    <label
                                                        class="mt-1 flex cursor-pointer select-none items-center gap-2 py-0.5 text-[11px] font-medium text-orange-800"
                                                    >
                                                        <input
                                                            type="checkbox"
                                                            v-model="saldoForm.pakai_sistem_tgh_n1"
                                                            class="h-5 w-5 sm:h-4 sm:w-4 shrink-0 rounded border-orange-300 text-orange-600 focus:ring-orange-500"
                                                        />
                                                        <span
                                                            >Ikut data sistem
                                                            ({{
                                                                getTghSistemN1(
                                                                    selectedPegawai,
                                                                )
                                                            }}
                                                            hari)</span
                                                        >
                                                    </label>
                                                    <p
                                                        v-if="saldoForm.errors.ditangguhkan_n1"
                                                        class="mt-1 text-[11px] text-red-600"
                                                    >
                                                        {{ saldoForm.errors.ditangguhkan_n1 }}
                                                    </p>
                                                </div>
                                                <input
                                                    id="input-sisa-n1"
                                                    type="number"
                                                    inputmode="numeric"
                                                    v-model="saldoForm.carry_forward_normal"
                                                    min="0"
                                                    max="12"
                                                    class="w-full h-11 sm:h-9 rounded-md border border-gray-300 bg-white px-2 text-center text-base sm:text-sm focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500"
                                                />
                                                <p
                                                    v-if="saldoForm.errors.carry_forward_normal"
                                                    class="col-span-2 mt-1 text-[11px] text-red-600"
                                                >
                                                    {{ saldoForm.errors.carry_forward_normal }}
                                                </p>
                                            </div>

                                            <!-- Sisa N-2 + Ditangguhkan N-2 (bisa diedit) -->
                                            <div class="grid grid-cols-[minmax(0,1fr)_5rem] sm:grid-cols-[minmax(0,1fr)_6rem] items-center gap-x-3 px-3 py-2.5">
                                                <div class="min-w-0">
                                                    <label
                                                        for="input-sisa-n2"
                                                        class="block text-xs font-semibold leading-snug text-emerald-800"
                                                    >
                                                        Sisa Cuti {{ tahunAktif - 2 }}
                                                    </label>

                                                    <!-- Ditangguhkan N-2: pola sama dengan N-1 -->
                                                    <div
                                                        class="mt-1.5 flex items-center gap-1.5 text-[11px] text-orange-700"
                                                        :title="KETERANGAN_TGH"
                                                    >
                                                        <label
                                                            for="input-tgh-n2"
                                                            class="font-semibold"
                                                            >Ditangguhkan:</label
                                                        >
                                                        <input
                                                            id="input-tgh-n2"
                                                            type="number"
                                                            inputmode="numeric"
                                                            step="1"
                                                            min="0"
                                                            max="365"
                                                            v-model="saldoForm.ditangguhkan_n2"
                                                            :disabled="saldoForm.pakai_sistem_tgh_n2"
                                                            :required="!saldoForm.pakai_sistem_tgh_n2"
                                                            class="h-9 sm:h-8 w-16 sm:w-14 rounded-md border border-orange-300 bg-white px-1 text-center text-base sm:text-sm focus:border-orange-500 focus:ring-2 focus:ring-orange-500 disabled:cursor-not-allowed disabled:border-gray-200 disabled:bg-gray-100 disabled:text-gray-500"
                                                        />
                                                        <span>hari</span>
                                                    </div>
                                                    <label
                                                        class="mt-1 flex cursor-pointer select-none items-center gap-2 py-0.5 text-[11px] font-medium text-orange-800"
                                                    >
                                                        <input
                                                            type="checkbox"
                                                            v-model="saldoForm.pakai_sistem_tgh_n2"
                                                            class="h-5 w-5 sm:h-4 sm:w-4 shrink-0 rounded border-orange-300 text-orange-600 focus:ring-orange-500"
                                                        />
                                                        <span
                                                            >Ikut data sistem
                                                            ({{
                                                                getTghSistemN2(
                                                                    selectedPegawai,
                                                                )
                                                            }}
                                                            hari)</span
                                                        >
                                                    </label>
                                                    <p
                                                        v-if="saldoForm.errors.ditangguhkan_n2"
                                                        class="mt-1 text-[11px] text-red-600"
                                                    >
                                                        {{ saldoForm.errors.ditangguhkan_n2 }}
                                                    </p>
                                                </div>
                                                <input
                                                    id="input-sisa-n2"
                                                    type="number"
                                                    inputmode="numeric"
                                                    v-model="saldoForm.sisa_cuti_dua_tahun_lalu"
                                                    min="0"
                                                    max="12"
                                                    class="w-full h-11 sm:h-9 rounded-md border border-gray-300 bg-white px-2 text-center text-base sm:text-sm focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500"
                                                />
                                                <p
                                                    v-if="saldoForm.errors.sisa_cuti_dua_tahun_lalu"
                                                    class="col-span-2 mt-1 text-[11px] text-red-600"
                                                >
                                                    {{ saldoForm.errors.sisa_cuti_dua_tahun_lalu }}
                                                </p>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                                <!-- ================= END FITUR EDIT SALDO PERORANGAN ================= -->

                                <!-- Sisa Ditangguhkan: HANYA tampil jika ada nilai > 0.
                                 Ringkasan informasi + catatan_saldo (kalau ada) untuk
                                 data yang sudah tersimpan. -->
                                <div
                                    v-if="punyaSisaDitangguhkan(selectedPegawai)"
                                    class="rounded-xl border border-orange-200 bg-orange-50 p-3 sm:p-4"
                                >
                                    <p
                                        class="text-[11px] font-bold uppercase tracking-wider text-orange-700 mb-2"
                                    >
                                        Sisa Ditangguhkan (informasi)
                                    </p>
                                    <div class="grid grid-cols-2 gap-3 text-sm">
                                        <div>
                                            <span
                                                class="text-xs text-orange-600/80"
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
                                            <span
                                                class="text-xs text-orange-600/80"
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
                                    <p class="mt-2 text-xs text-orange-800/80">
                                        Saldo sudah dikembalikan; Tgh hanya
                                        informasi dan tidak menjadi carry-over.
                                    </p>
                                    <p
                                        v-if="selectedPegawai.catatan_saldo"
                                        class="mt-2 text-xs text-orange-800/80 italic break-words"
                                    >
                                        {{ selectedPegawai.catatan_saldo }}
                                    </p>
                                </div>
                            </template>

                            <!-- TAB RIWAYAT: menampilkan SEMUA riwayat (tidak dipotong) -->
                            <template v-if="modalTab === 'riwayat'">
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
                                    </h5>

                                    <!-- Kosong -->
                                    <div
                                        v-if="
                                            !selectedPegawai.pengajuan_cuti ||
                                            selectedPegawai.pengajuan_cuti
                                                .length === 0
                                        "
                                        class="text-center py-6 bg-gray-50 rounded-xl border border-dashed border-gray-200 text-xs text-gray-400"
                                    >
                                        Belum ada catatan riwayat pengajuan
                                        cuti.
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
                                                    riwayat.status ===
                                                    'disetujui',
                                                'border-l-4 border-l-amber-500 hover:bg-amber-100':
                                                    riwayat.status?.includes(
                                                        'menunggu',
                                                    ),
                                                'border-l-4 border-l-rose-500 hover:bg-rose-100':
                                                    riwayat.status ===
                                                    'ditolak',
                                                'border-l-4 border-l-gray-400 hover:bg-gray-200':
                                                    riwayat.status ===
                                                    'dibatalkan_ditangguhkan',
                                                'ring-2 ring-green-500/20':
                                                    expandedRiwayatId ===
                                                    riwayat.id,
                                            }"
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
                                                    >{{
                                                        riwayat.tanggal_mulai
                                                    }}
                                                    s/d
                                                    {{
                                                        riwayat.tanggal_selesai
                                                    }}</span
                                                >
                                                <span>&middot;</span>
                                                <span
                                                    class="font-bold text-gray-900"
                                                    >{{ getDurasi(riwayat) }}
                                                    Hari</span
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
                                                                v-if="step.level"
                                                            >
                                                                L{{ step.level }}
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
                            </template>
                        </div>
                        <!-- ================= END ISI TAB ================= -->
                    </div>

                    <!-- ============ 3) FOOTER (tetap) ============
                         Tab Saldo : [Batalkan] [Simpan Saldo] ........ [Tutup Rincian]
                         Tab Riwayat: hanya [Tutup Rincian].
                         HP: Batalkan & Simpan berdampingan penuh; Tutup
                         disembunyikan di tab Saldo karena sudah ada tombol X.
                         Tombol Simpan terhubung ke form lewat atribut form. -->
                    <div
                        class="shrink-0 border-t bg-gray-50 px-3 sm:px-6 pt-2.5 sm:pt-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))] sm:pb-3"
                    >
                        <!-- Pesan sukses setelah Simpan Saldo. Modal tetap
                             terbuka supaya admin bisa langsung melihat
                             perubahan pada kartu KPI. -->
                        <p
                            v-if="pesanSukses && modalTab === 'saldo'"
                            role="status"
                            class="mb-2 text-xs font-semibold text-emerald-700 bg-emerald-100 border border-emerald-200 rounded-lg px-3 py-1.5"
                        >
                            {{ pesanSukses }}
                        </p>

                        <div class="flex items-center gap-2 sm:justify-between">
                            <div
                                v-if="modalTab === 'saldo'"
                                class="flex flex-1 sm:flex-none items-center gap-2"
                            >
                                <button
                                    type="button"
                                    @click="resetSaldoForm"
                                    :disabled="
                                        isSavingSaldo || !saldoForm.isDirty
                                    "
                                    class="flex-1 sm:flex-none min-h-[44px] sm:min-h-0 px-4 sm:px-5 py-2.5 sm:py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 text-sm font-semibold rounded-full transition disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer whitespace-nowrap"
                                >
                                    Batalkan
                                </button>
                                <button
                                    type="submit"
                                    form="form-saldo"
                                    :disabled="
                                        isSavingSaldo || !saldoForm.isDirty
                                    "
                                    class="flex-1 sm:flex-none min-h-[44px] sm:min-h-0 justify-center inline-flex items-center gap-2 px-4 sm:px-5 py-2.5 sm:py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-full shadow-sm transition disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer whitespace-nowrap"
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

                            <button
                                @click="closeModal"
                                type="button"
                                class="min-h-[44px] sm:min-h-0 justify-center items-center px-6 py-2.5 sm:py-2 bg-gray-800 hover:bg-gray-700 text-white text-sm font-semibold rounded-xl transition shadow-sm cursor-pointer whitespace-nowrap sm:ml-auto"
                                :class="
                                    modalTab === 'saldo'
                                        ? 'hidden sm:inline-flex'
                                        : 'inline-flex flex-1 sm:flex-none'
                                "
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
                <div class="absolute inset-0" @click="closeImportModal"></div>

                <div
                    class="relative bg-white p-4 sm:p-6 pb-[calc(1rem+env(safe-area-inset-bottom))] sm:pb-6 rounded-t-2xl sm:rounded-2xl w-full max-w-md shadow-2xl max-h-[90dvh] overflow-y-auto overscroll-contain sm:my-auto"
                >
                    <div
                        class="flex justify-between items-center mb-4 sm:mb-5 border-b pb-3"
                    >
                        <h3 class="text-base sm:text-lg font-bold text-gray-800">
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
                    class="relative bg-white p-4 sm:p-6 pb-[calc(1rem+env(safe-area-inset-bottom))] sm:pb-6 rounded-t-2xl sm:rounded-2xl w-full max-w-md shadow-2xl max-h-[90dvh] overflow-y-auto overscroll-contain sm:my-auto"
                >
                    <div
                        class="flex justify-between items-center mb-4 border-b pb-3"
                    >
                        <h3 class="text-base sm:text-lg font-bold text-gray-800">
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

                    <form @submit.prevent="submitGenerateSaldo">
                        <p class="text-sm text-gray-600 mb-4">
                            Ini akan menghitung ulang carry-over saldo cuti
                            untuk
                            <strong>semua pegawai</strong>
                            pada tahun di bawah, memakai logika yang sama
                            persis dengan generate otomatis tiap 1 Januari
                            (carry-over dari sisa saldo biasa maks. 6 hari, atau
                            12 hari jika sisa N-2 dan N-1 sama-sama 12; total
                            hak + bawaan maks. 24 hari). Catatan penangguhan
                            hanya untuk pelaporan, bukan carry-over.
                            Aman
                            diulang: hasilnya sama untuk data yang sama, dan
                            Hak yang sudah diisi tidak berubah. Sisa tahun lalu
                            dan carry-over dihitung ulang.
                        </p>

                        <label
                            class="block text-sm font-medium text-gray-700 mb-1"
                        >
                            Tahun yang digenerate
                        </label>
                        <!-- Opsi dirender dinamis dari 'pilihanTahunGenerate',
                             sehingga catatan di <script> berlaku: cukup tambahkan
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
                                :disabled="generateSaldoForm.processing"
                                class="min-h-[44px] sm:min-h-0 px-4 py-2 bg-emerald-600 text-white rounded-md hover:bg-emerald-700 cursor-pointer transition disabled:opacity-50 disabled:cursor-not-allowed"
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
            </Teleport>
            <!-- end div pembungkus tunggal -->
        </div>
    </MainLayout>
</template>