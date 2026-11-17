<script setup>
import MainLayout from "@/Layouts/MainLayout.vue";
import { Head, useForm, usePage, router } from "@inertiajs/vue3";
import { computed, ref, watch, onMounted, onUnmounted, nextTick } from "vue";
import Swal from "sweetalert2";

const props = defineProps({
    sisa_cuti: Number,
    total_cuti_tersedia: Number,
    // Daftar Hari Libur Nasional & Cuti Bersama dari tabel hari_libur
    // (dikirim CutiController@create)
    hariLiburs: {
        type: Array,
        default: () => [],
    },
    // Tahun yang sudah punya baris saldo (dikirim CutiController@create).
    // null = backend belum mengirim, pengecekan di form dilewati.
    tahun_saldo_tersedia: {
        type: Array,
        default: null,
    },
    // Sisa saldo PER TAHUN dari CutiController@create, mis. {"2026": 8, "2027": 12}.
    // Dipakai untuk cek saldo cuti lintas tahun. Kosong = jatuh ke saldo tahun
    // berjalan (sisa_cuti).
    saldo_per_tahun: {
        type: Object,
        default: () => ({}),
    },
    // Rentang pengajuan AKTIF milik user (menunggu_lX / disetujui),
    // dikirim CutiController@create. Dipakai untuk peringatan dini bila
    // tanggal yang dipilih bentrok dengan pengajuan lain. Backend
    // (CutiController::store) tetap penjaga akhir.
    pengajuan_aktif: {
        type: Array,
        default: () => [],
    },
    // Masa kerja & kelayakan Cuti Besar dihitung backend dari
    // tanggal_masuk (CutiController@create). Dipakai sebagai sumber utama
    // supaya konsisten dengan validasi store(). null = backend belum
    // mengirim, jatuh ke perkiraan dari NIP.
    masa_kerja_tahun: {
        type: Number,
        default: null,
    },
    boleh_cuti_besar: {
        type: Boolean,
        default: null,
    },
    // Cuti Alasan Penting hanya untuk PNS (dikirim CutiController@create).
    boleh_cuti_alasan_penting: {
        type: Boolean,
        default: true,
    },
    // Cuti Melahirkan hanya untuk perempuan, dibaca dari digit ke-15 NIP
    // (2 = perempuan). Dikirim CutiController@create.
    boleh_cuti_melahirkan: {
        type: Boolean,
        default: true,
    },
    // Hari Cuti Tahunan yang masih menunggu persetujuan (sudah mengurangi
    // sisa_cuti). Dikirim CutiController@create.
    cuti_menunggu: {
        type: Number,
        default: 0,
    },
    // Batas tahun tanggal mulai pengajuan, mis. { min: 2024, max: 2027 }
    // (dari LeaveYearWindow di CutiController@create). Tanggal di luar batas
    // dikunci di kalender. null = backend belum mengirim, tidak ada pembatasan
    // di kalender (server tetap penjaga akhir).
    batas_tahun_pengajuan: {
        type: Object,
        default: null,
    },
});

// Jalur cadangan jika props sisa_cuti tidak dikirim
const page = usePage();
const authUser = computed(() => page.props.auth.user);

// ================= MASA KERJA (syarat Cuti Besar) =================
// Sumber utama: prop dari backend (dihitung dari tanggal_masuk, sama dengan
// validasi di CutiController::store). Cadangan: perkiraan dari NIP.
// Format NIP PNS 18 digit: 8 digit tgl lahir + 6 digit TMT (YYYYMM) + 6 digit lainnya
// Tahun pengangkatan = digit ke-9 s/d ke-12 → substring(8, 12)
const masaKerjaTahun = computed(() => {
    if (typeof props.masa_kerja_tahun === "number") {
        return props.masa_kerja_tahun;
    }
    const nip = authUser.value?.nip || "";
    if (nip.length >= 12) {
        const tahunAngkat = parseInt(nip.substring(8, 12), 10);
        if (!isNaN(tahunAngkat) && tahunAngkat > 1900) {
            return new Date().getFullYear() - tahunAngkat;
        }
    }
    // Default aman: jangan mengunci opsi secara keliru. Backend tetap penjaga akhir.
    return 5;
});

const canChooseCutiBesar = computed(() =>
    typeof props.boleh_cuti_besar === "boolean"
        ? props.boleh_cuti_besar
        : masaKerjaTahun.value >= 5,
);

const canChooseAlasanPenting = computed(() => props.boleh_cuti_alasan_penting);

const canChooseMelahirkan = computed(() => props.boleh_cuti_melahirkan);
// ================= END MASA KERJA =================

// ================= ALUR PERSETUJUAN DINAMIS =================
// Mengikuti kolom "Level Approval" di Excel Nama-Nama Pegawai Biro Perencanaan
const LEVEL_INFO = {
    1: { judul: "Ketua Tim Kerja", sub: "Atasan Langsung (L1)" },
    2: { judul: "Ketua Kelompok Substansi", sub: "(L2)" },
    3: { judul: "Kasubag TU", sub: "(L3)" },
    4: { judul: "Kepala Biro", sub: "Perencanaan (L4)" },
};

const teks = (v) =>
    String(v ?? "")
        .trim()
        .toUpperCase();

const kategoriPemohon = computed(() => {
    const u = authUser.value || {};
    const jabatan = teks(u.jabatan);
    const unit = teks(u.kelompok_substansi ?? u.unit_kerja);
    const tim = teks(u.tim_kerja);
    const adaTim = tim !== "" && tim !== "-";
    const levelExcel = teks(u.level);

    if (jabatan.includes("KEPALA BIRO") || levelExcel === "LEVEL 4") {
        return { label: "Kepala Biro", levels: [] };
    }
    if (jabatan.includes("KEPALA SUBBAGIAN") || levelExcel === "LEVEL 3") {
        return { label: "Kepala Subbagian Tata Usaha", levels: [4] };
    }
    if (jabatan.startsWith("KETUA KELOMPOK") || levelExcel === "LEVEL 2") {
        return { label: "Ketua Kelompok Substansi", levels: [3, 4] };
    }
    if (jabatan.startsWith("KETUA TIM KERJA") || levelExcel === "LEVEL 1") {
        return { label: "Ketua Tim Kerja", levels: [2, 3, 4] };
    }
    // Staf
    if (unit.includes("SUBBAGIAN") || unit.includes("TATA USAHA")) {
        return { label: "Staf Subbagian Tata Usaha", levels: [3, 4] };
    }
    if (adaTim) {
        return { label: "Staf Tim Kerja", levels: [1, 3, 4] };
    }
    if (unit !== "") {
        return { label: "Staf Kelompok Substansi", levels: [2, 3, 4] };
    }
    // Fallback
    return { label: "Staf", levels: [1, 3, 4] };
});

const alurSteps = computed(() =>
    kategoriPemohon.value.levels.map((lv, i) => ({
        no: i + 1,
        level: lv,
        ...LEVEL_INFO[lv],
    })),
);
// ================= END ALUR PERSETUJUAN =================

// Form
const form = useForm({
    jenis_cuti: "Cuti Tahunan",
    anak_ke: "",
    tanggal_mulai: "",
    tanggal_selesai: "",
    keterangan: "",
    alamat_cuti: "",
    no_telp: "",
    lampiran: null,
});

const gunakanAlamatDomisili = ref(false);
const gunakanNomorTeleponProfil = ref(false);
const lampiranInput = ref(null);

// ================= ALAMAT & NO. HP (RINGKAS, TERISI DARI PROFIL) =================
// Secara bawaan alamat & nomor HP diambil dari profil dan tampil sebagai satu
// baris ringkas. Kolom isian baru terbuka bila user menekan "Ubah", atau bila
// profil belum lengkap / server mengembalikan error di kolom tersebut.
const editorKontakTerbuka = ref(false);

const profilAlamat = computed(() => authUser.value?.alamat_domisili || "");
const profilTelepon = computed(() => authUser.value?.no_telepon || "");

const terapkanDefaultKontak = () => {
    gunakanAlamatDomisili.value = !!profilAlamat.value;
    form.alamat_cuti = profilAlamat.value;
    gunakanNomorTeleponProfil.value = !!profilTelepon.value;
    form.no_telp = profilTelepon.value;
    editorKontakTerbuka.value = !(profilAlamat.value && profilTelepon.value);
};
terapkanDefaultKontak();

const ringkasKontak = computed(() => {
    const alamat = (form.alamat_cuti || "").trim() || "Alamat belum diisi";
    const telp = (form.no_telp || "").trim() || "HP belum diisi";
    return `${alamat} · ${telp}`;
});

// Buka editor otomatis bila ada error di alamat / telepon
watch(
    () => [form.errors.alamat_cuti, form.errors.no_telp],
    ([a, t]) => {
        if (a || t) editorKontakTerbuka.value = true;
    },
);

// Hapus error kolom begitu user mengubah isinya
watch(
    () => form.keterangan,
    () => {
        if (form.errors.keterangan) form.clearErrors("keterangan");
    },
);
watch(
    () => form.alamat_cuti,
    () => {
        if (form.errors.alamat_cuti) form.clearErrors("alamat_cuti");
    },
);
watch(
    () => form.no_telp,
    () => {
        if (form.errors.no_telp) form.clearErrors("no_telp");
    },
);
// ================= END ALAMAT & NO. HP =================

// ================================================================
// DROPDOWN JENIS CUTI KUSTOM
// Daftar selalu terbuka ke bawah dengan lebar sama dengan tombolnya,
// tinggi dibatasi (bisa di-scroll), teks panjang membungkus.
// Menutup saat klik di luar (elemen tanpa atribut data-dropdown) atau Esc.
// Nilai tetap disimpan di form.jenis_cuti.
// Opsi "Cuti Besar" terkunci (disabled) bila masa kerja belum memenuhi syarat.
// Opsi "Cuti Alasan Penting" terkunci bila pegawai bukan PNS.
// ================================================================
const dropdownJenisTerbuka = ref(false);

const toggleDropdownJenis = () => {
    dropdownJenisTerbuka.value = !dropdownJenisTerbuka.value;
};

const opsiJenisCuti = computed(() => [
    { value: "Cuti Tahunan", label: "Cuti Tahunan", disabled: false },
    {
        value: "Cuti Melahirkan",
        label: canChooseMelahirkan.value
            ? "Cuti Melahirkan"
            : "Cuti Melahirkan (Hanya untuk pegawai perempuan)",
        disabled: !canChooseMelahirkan.value,
    },
    {
        value: "Cuti Besar",
        label: canChooseCutiBesar.value
            ? "Cuti Besar"
            : "Cuti Besar (Belum memenuhi syarat masa kerja 5 tahun)",
        disabled: !canChooseCutiBesar.value,
    },
    {
        value: "Cuti Alasan Penting",
        label: canChooseAlasanPenting.value
            ? "Cuti Alasan Penting"
            : "Cuti Alasan Penting (Hanya untuk PNS)",
        disabled: !canChooseAlasanPenting.value,
    },
]);

const jenisCutiTerpilihLabel = computed(() => {
    const cocok = opsiJenisCuti.value.find((o) => o.value === form.jenis_cuti);
    return cocok ? cocok.label : form.jenis_cuti || "Pilih jenis cuti";
});

const pilihJenisCuti = (opt) => {
    if (opt.disabled) return;
    form.jenis_cuti = opt.value;
    dropdownJenisTerbuka.value = false;
};

const tutupDropdownSaatKlikLuar = (event) => {
    if (!event.target.closest?.("[data-dropdown]")) {
        dropdownJenisTerbuka.value = false;
    }
};
// ================= END DROPDOWN JENIS CUTI KUSTOM =================

// ================= VALIDASI ANAK KE- (inline, tanpa popup) =================
// Pesan merah tampil di bawah kolom, halaman scroll ke kolom itu, dan
// pesan hilang begitu user mulai mengetik / mengubah nilai.
const anakKeInput = ref(null);
const anakKeError = ref("");

watch(
    () => form.anak_ke,
    () => {
        if (anakKeError.value) anakKeError.value = "";
        if (form.errors.anak_ke) form.clearErrors("anak_ke");
    },
);

// Jika user ganti jenis cuti, error ikut dibersihkan
watch(
    () => form.jenis_cuti,
    () => {
        anakKeError.value = "";
    },
);
// ================= END VALIDASI ANAK KE- =================

// ================= VALIDASI LAMPIRAN =================
// Lampiran bersifat OPSIONAL. Aturan: PDF/JPG/PNG, maksimal 2 MB.
// Pengecekan di browser mencegah upload sia-sia; backend
// (CutiController::store) tetap penjaga akhir.
const MAX_LAMPIRAN = 2 * 1024 * 1024; // 2 MB
const EKSTENSI_BOLEH = ["pdf", "jpg", "jpeg", "png"];
const MIME_BOLEH = ["application/pdf", "image/jpeg", "image/png"];
const lampiranError = ref("");

const tolakLampiran = (pesan) => {
    lampiranError.value = pesan;
    form.lampiran = null;
    if (lampiranInput.value) lampiranInput.value.value = ""; // kosongkan input agar bisa pilih ulang
};

const pilihLampiran = () => {
    lampiranInput.value?.click();
};

const onLampiranChange = (e) => {
    const file = e.target.files[0] ?? null;
    lampiranError.value = "";
    form.clearErrors("lampiran");

    if (!file) {
        form.lampiran = null; // lampiran opsional
        return;
    }

    const ekstensi = file.name.split(".").pop().toLowerCase();
    // file.type bisa kosong di beberapa perangkat, jadi ekstensi tetap dicek
    const tipeValid =
        EKSTENSI_BOLEH.includes(ekstensi) &&
        (file.type === "" || MIME_BOLEH.includes(file.type));

    if (!tipeValid) {
        return tolakLampiran(
            "Format file tidak didukung. Gunakan PDF, JPG, atau PNG.",
        );
    }
    if (file.size > MAX_LAMPIRAN) {
        const mb = (file.size / 1024 / 1024).toFixed(1);
        return tolakLampiran(
            `Ukuran file ${mb} MB melebihi batas maksimal 2 MB.`,
        );
    }
    form.lampiran = file;
};

const formatUkuranLampiran = (bytes) => {
    if (bytes == null || isNaN(bytes)) return "";
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
};

const hapusLampiran = () => {
    lampiranError.value = "";
    form.clearErrors("lampiran");
    form.lampiran = null;
    if (lampiranInput.value) lampiranInput.value.value = "";
};

// Cadangan: jika server tetap membalas 413 (melewati post_max_size PHP),
// tampilkan pesan biasa, bukan modal halaman error.
const stopInvalidListener = router.on("invalid", (event) => {
    if (event.detail?.response?.status === 413) {
        event.preventDefault();
        tolakLampiran("Ukuran file terlalu besar. Maksimal 2 MB.");
    }
});
// ================= END VALIDASI LAMPIRAN =================

const toggleAlamatDomisili = (e) => {
    form.alamat_cuti = e.target.checked
        ? authUser.value.alamat_domisili || ""
        : "";
};

const toggleNomorTeleponProfil = (e) => {
    form.no_telp = e.target.checked ? authUser.value.no_telepon || "" : "";
};

const resetSemuaForm = () => {
    form.reset();
    form.clearErrors();
    lampiranError.value = "";
    anakKeError.value = "";
    form.jenis_cuti = "Cuti Tahunan";
    dropdownJenisTerbuka.value = false;
    sheetTanggalTerbuka.value = false;
    sheetKetentuanTerbuka.value = false;
    terapkanDefaultKontak();
    if (lampiranInput.value) {
        lampiranInput.value.value = "";
    }
};

// ================= HELPER TANGGAL (hindari masalah timezone) =================
const toDateStr = (date) => {
    const yyyy = date.getFullYear();
    const mm = String(date.getMonth() + 1).padStart(2, "0");
    const dd = String(date.getDate()).padStart(2, "0");
    return `${yyyy}-${mm}-${dd}`;
};

const parseLocalDate = (dateStr) => new Date(`${dateStr}T00:00:00`);

const todayStr = computed(() => toDateStr(new Date()));

// Jumlah hari kalender inklusif antara dua tanggal (YYYY-MM-DD)
const selisihHariInklusif = (a, b) =>
    Math.round((parseLocalDate(b) - parseLocalDate(a)) / 86400000) + 1;

const namaBulanSingkat = [
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

// Contoh: "21 – 23 Okt 2026", "28 Okt – 2 Nov 2026", "31 Des 2026 – 1 Jan 2027"
const formatRentang = (mulai, selesai) => {
    if (!mulai) return "";
    const a = parseLocalDate(mulai);
    const b = parseLocalDate(selesai || mulai);
    const tgl = (d) => d.getDate();
    const bln = (d) => namaBulanSingkat[d.getMonth()];

    if (!selesai || mulai === selesai) {
        return `${tgl(a)} ${bln(a)} ${a.getFullYear()}`;
    }
    if (a.getFullYear() !== b.getFullYear()) {
        return `${tgl(a)} ${bln(a)} ${a.getFullYear()} – ${tgl(b)} ${bln(b)} ${b.getFullYear()}`;
    }
    if (a.getMonth() !== b.getMonth()) {
        return `${tgl(a)} ${bln(a)} – ${tgl(b)} ${bln(b)} ${b.getFullYear()}`;
    }
    return `${tgl(a)} – ${tgl(b)} ${bln(b)} ${b.getFullYear()}`;
};

const formatTanggalPendek = (dateStr) =>
    parseLocalDate(dateStr).toLocaleDateString("id-ID", {
        day: "2-digit",
        month: "short",
    });

// ================= BATAS RENTANG TANGGAL (selaras CutiController) =================
// Tanggal mulai dibatasi ke jendela tahun (prop batas_tahun_pengajuan, dari
// LeaveYearWindow): paling awal 1 Januari tahun min, paling akhir 31 Desember
// tahun max. Tanggal di luar jendela dikunci di kalender (lihat
// tanggalTerkunci). Tanggal sebelum hari ini tetap boleh dipilih (backdate).
// Batas panjang satu pengajuan: backend MAKS_RENTANG_HARI = 92 (hari kalender
// inklusif). Backend tetap penjaga akhir.
const MAKS_RENTANG_HARI = 92; // hari kalender inklusif

// Selesai: mulai + 91 hari (92 hari inklusif). null = belum ada tanggal mulai.
const maxTanggalSelesai = computed(() => {
    if (!form.tanggal_mulai) return null;
    const m = parseLocalDate(form.tanggal_mulai);
    m.setDate(m.getDate() + (MAKS_RENTANG_HARI - 1)); // 92 hari inklusif
    return toDateStr(m);
});

// Jika tanggal mulai berubah dan selesai sudah di luar batas, rapikan selesai.
watch(
    () => form.tanggal_mulai,
    (val) => {
        if (!val || !form.tanggal_selesai) return;
        if (form.tanggal_selesai < val) {
            form.tanggal_selesai = val;
        } else if (
            maxTanggalSelesai.value &&
            form.tanggal_selesai > maxTanggalSelesai.value
        ) {
            form.tanggal_selesai = maxTanggalSelesai.value;
        }
    },
);
// ================= END BATAS RENTANG TANGGAL =================

// ================= HARI LIBUR =================
// Setiap item dinormalisasi supaya tampilan sama dengan Dashboard:
//  - judul      : keterangan tanpa "(PROYEKSI - ...)"
//  - isCutiBersama / jenisLabel : "Cuti Bersama" atau "Libur Nasional"
//  - isProyeksi : tampil sebagai badge kecil
const normalisasiLibur = (libur) => {
    const ket = String(libur.keterangan || "");
    // Sumber utama: kolom is_cuti_bersama (boolean / 0 / 1 / "1") dari tabel
    // hari_libur. Cadangan: kolom jenis (bila ada) atau teks keterangan.
    const flagCutiBersama = [libur.is_cuti_bersama, libur.cuti_bersama].some(
        (v) => v === true || v === 1 || v === "1",
    );
    const jenisMentah = String(
        libur.jenis ?? libur.tipe ?? libur.kategori ?? libur.jenis_libur ?? "",
    ).toLowerCase();

    const isCutiBersama =
        flagCutiBersama ||
        jenisMentah.includes("cuti") ||
        /cuti\s*bersama/i.test(ket);
    const isProyeksi = /proyeksi/i.test(ket);
    const judul =
        ket
            .replace(/\(\s*proyeksi[^)]*\)/i, "")
            .replace(/\s{2,}/g, " ")
            .trim() || ket;

    const tgl = parseLocalDate(libur.tanggal);
    const valid = !Number.isNaN(tgl.getTime());

    return {
        id: libur.id ?? libur.tanggal,
        tanggal: libur.tanggal,
        judul,
        isCutiBersama,
        isProyeksi,
        jenisLabel: isCutiBersama ? "Cuti Bersama" : "Libur Nasional",
        hari: valid ? String(tgl.getDate()).padStart(2, "0") : "-",
        bulan: valid ? namaBulanSingkat[tgl.getMonth()].toUpperCase() : "",
    };
};

const hariLiburSet = computed(() => {
    return new Set(props.hariLiburs.map((h) => h.tanggal));
});

const liburMap = computed(
    () =>
        new Map(props.hariLiburs.map((h) => [h.tanggal, normalisasiLibur(h)])),
);

const isHariLibur = (dateStr) => hariLiburSet.value.has(dateStr);

const hariLiburDalamRentang = computed(() => {
    if (!form.tanggal_mulai || !form.tanggal_selesai) return [];

    let start = parseLocalDate(form.tanggal_mulai);
    let end = parseLocalDate(form.tanggal_selesai);
    if (start > end) return [];

    const hasil = [];
    let current = new Date(start);
    while (current <= end) {
        const dateStr = toDateStr(current);
        const libur = liburMap.value.get(dateStr);
        if (libur) hasil.push(libur);
        current.setDate(current.getDate() + 1);
    }
    return hasil;
});

// Hitung hari kerja (identik dengan backend hitungHariKerja)
const hitungHariKerja = (mulai, selesai) => {
    if (!mulai || !selesai) return 0;

    let start = parseLocalDate(mulai);
    let end = parseLocalDate(selesai);
    if (start > end) return 0;

    let count = 0;
    let current = new Date(start);

    while (current <= end) {
        const dayOfWeek = current.getDay();
        const dateStr = toDateStr(current);
        const isWeekend = dayOfWeek === 0 || dayOfWeek === 6;

        if (!isWeekend && !isHariLibur(dateStr)) {
            count++;
        }
        current.setDate(current.getDate() + 1);
    }
    return count;
};

// Estimasi hari kerja
const estimasiHari = computed(() =>
    hitungHariKerja(form.tanggal_mulai, form.tanggal_selesai),
);

// [BARU] Hari kerja dipecah per tahun kalender (cuti lintas tahun), sama
// dengan CutiController::hitungHariKerjaPerTahun().
// Contoh: 29 Des 2026 - 4 Jan 2027 -> { 2026: 3, 2027: 2 }.
const hariPerTahun = computed(() => {
    const hasil = {};
    if (!form.tanggal_mulai || !form.tanggal_selesai) return hasil;
    if (form.tanggal_mulai > form.tanggal_selesai) return hasil;

    const awal = parseInt(form.tanggal_mulai.slice(0, 4), 10);
    const akhir = parseInt(form.tanggal_selesai.slice(0, 4), 10);
    if (isNaN(awal) || isNaN(akhir)) return hasil;

    for (let t = awal; t <= akhir; t++) {
        const dari = t === awal ? form.tanggal_mulai : `${t}-01-01`;
        const sampai = t === akhir ? form.tanggal_selesai : `${t}-12-31`;
        hasil[t] = hitungHariKerja(dari, sampai);
    }
    return hasil;
});

// Saldo
const saldoSaatIni = computed(() => {
    return (
        props.sisa_cuti ??
        props.total_cuti_tersedia ??
        page.props.auth?.user?.sisa_cuti ??
        page.props.auth?.user?.total_cuti_tersedia ??
        0
    );
});

// [BARU] Tahun pertama yang saldonya tidak cukup (null = semua aman).
// Memakai saldo_per_tahun dari backend; bila tahun itu tidak ada di prop,
// jatuh ke saldo tahun berjalan. Hanya untuk Cuti Tahunan.
const tahunSaldoKurang = computed(() => {
    if (form.jenis_cuti !== "Cuti Tahunan") return null;

    for (const [tahun, hari] of Object.entries(hariPerTahun.value)) {
        if (hari <= 0) continue;
        const tersedia = props.saldo_per_tahun?.[tahun] ?? saldoSaatIni.value;
        if (hari > tersedia) {
            return { tahun: Number(tahun), hari, tersedia };
        }
    }
    return null;
});

// [BARU] Rincian per tahun untuk Ringkasan Saldo: sisa saat ini, hari yang
// dipotong, dan sisa setelah pengajuan, untuk SETIAP tahun yang disentuh
// rentang cuti. Jenis cuti selain Cuti Tahunan tidak memotong (potong = 0).
const rincianPerTahun = computed(() =>
    Object.entries(hariPerTahun.value).map(([tahun, hari]) => {
        const tersedia = props.saldo_per_tahun?.[tahun] ?? saldoSaatIni.value;
        const potong = form.jenis_cuti === "Cuti Tahunan" ? hari : 0;
        return {
            tahun: Number(tahun),
            tersedia,
            potong,
            setelah: tersedia - potong,
        };
    }),
);

const lintasTahun = computed(
    () =>
        form.jenis_cuti === "Cuti Tahunan" && rincianPerTahun.value.length > 1,
);

const sisaSetelahPengajuan = computed(() => {
    if (form.jenis_cuti === "Cuti Tahunan") {
        // Cuti lintas tahun: tampilkan sisa pada tahun yang saldonya kurang.
        if (tahunSaldoKurang.value) {
            const k = tahunSaldoKurang.value;
            return k.tersedia - k.hari;
        }
        return saldoSaatIni.value - estimasiHari.value;
    }
    return saldoSaatIni.value;
});

// Jumlah hari yang benar-benar memotong saldo (hanya Cuti Tahunan).
const hariDipotong = computed(() =>
    form.jenis_cuti === "Cuti Tahunan" ? estimasiHari.value : 0,
);

// ================= SALDO TAHUN BELUM ADA (KHUSUS CUTI TAHUNAN) =================
// Tahun PERTAMA dalam rentang tanggal cuti yang saldonya belum dibuat Admin SDM.
// Yang dicek semua tahun yang disentuh rentang (mis. 31 Des 2026 - 1 Jan 2027
// menyentuh 2026 dan 2027), bukan hanya tanggal mulai. Jenis cuti lain tidak
// memotong saldo, jadi tidak dicek. Pengajuan yang melintasi pergantian tahun
// diblokir sampai Admin SDM men-generate saldo tahun tujuan.
// Ini hanya peringatan dini; backend (CutiController::store) tetap penjaga akhir.
const cariTahunTanpaSaldo = (mulai, selesai) => {
    if (!Array.isArray(props.tahun_saldo_tersedia)) return null;
    if (form.jenis_cuti !== "Cuti Tahunan") return null;
    if (!mulai || !selesai) return null;
    if (mulai > selesai) return null;

    const awal = parseInt(mulai.slice(0, 4), 10);
    const akhir = parseInt(selesai.slice(0, 4), 10);
    if (isNaN(awal) || isNaN(akhir)) return null;

    for (let t = awal; t <= akhir; t++) {
        if (!props.tahun_saldo_tersedia.includes(t)) return t;
    }
    return null;
};

const tahunTanpaSaldo = computed(() =>
    cariTahunTanpaSaldo(form.tanggal_mulai, form.tanggal_selesai),
);

// Versi untuk pilihan sementara (draft) di panel kalender: peringatan dini
// sebelum user menekan "Pilih Tanggal".
const tahunTanpaSaldoDraft = computed(() =>
    draftStart.value
        ? cariTahunTanpaSaldo(
              draftStart.value,
              draftEnd.value || draftStart.value,
          )
        : null,
);
// ================= END SALDO TAHUN BELUM ADA =================

// ================= PENGAJUAN GANDA / TANGGAL BENTROK =================
// Pengajuan aktif milik user yang rentang tanggalnya bersinggungan dengan
// rentang yang sedang dipilih. Dua rentang bentrok jika:
//   mulai_baru <= selesai_lama  DAN  selesai_baru >= mulai_lama
// Berlaku untuk SEMUA jenis cuti. Ini hanya peringatan dini; backend
// (CutiController::store) tetap penjaga akhir.
const pengajuanBentrok = computed(() => {
    if (!form.tanggal_mulai || !form.tanggal_selesai) return null;
    if (form.tanggal_mulai > form.tanggal_selesai) return null;

    return (
        props.pengajuan_aktif.find(
            (p) =>
                form.tanggal_mulai <= p.tanggal_selesai &&
                form.tanggal_selesai >= p.tanggal_mulai,
        ) ?? null
    );
});
// ================= END PENGAJUAN GANDA / TANGGAL BENTROK =================

// ================= RINGKASAN TANGGAL DI FORM UTAMA =================
const labelTanggal = computed(() => {
    if (!form.tanggal_mulai) return "Pilih tanggal cuti";
    const rentang = formatRentang(form.tanggal_mulai, form.tanggal_selesai);
    return `${rentang} · ${estimasiHari.value} hari kerja`;
});

const ringkasSaldo = computed(() => {
    if (form.jenis_cuti !== "Cuti Tahunan") {
        return `Sisa saldo ${saldoSaatIni.value} hari · jenis cuti ini tidak memotong saldo cuti tahunan`;
    }
    if (!form.tanggal_mulai || !form.tanggal_selesai) {
        return props.cuti_menunggu > 0
            ? `Sisa saldo ${saldoSaatIni.value} hari (sudah dikurangi ${props.cuti_menunggu} hari yang menunggu persetujuan)`
            : `Sisa saldo ${saldoSaatIni.value} hari`;
    }
    // [BARU] Cuti lintas tahun: jelaskan pecahan hari kerja per tahun.
    if (lintasTahun.value) {
        return rincianPerTahun.value
            .map(
                (r) =>
                    `${r.tahun}: potong ${r.potong}, sisa ${r.tersedia} → ${r.setelah}`,
            )
            .join(" · ");
    }
    return `Sisa ${saldoSaatIni.value} hari → setelah cuti ${sisaSetelahPengajuan.value} hari`;
});

const labelTombolAjukan = computed(() =>
    form.tanggal_mulai && form.tanggal_selesai
        ? `Ajukan Cuti · ${estimasiHari.value} hari kerja`
        : "Ajukan Cuti",
);

const tombolNonaktif = computed(
    () =>
        form.processing ||
        estimasiHari.value === 0 ||
        tahunTanpaSaldo.value !== null ||
        pengajuanBentrok.value !== null ||
        (form.jenis_cuti === "Cuti Tahunan" &&
            sisaSetelahPengajuan.value < 0) ||
        (form.jenis_cuti === "Cuti Alasan Penting" &&
            !canChooseAlasanPenting.value) ||
        (form.jenis_cuti === "Cuti Melahirkan" && !canChooseMelahirkan.value) ||
        (form.jenis_cuti === "Cuti Besar" && !canChooseCutiBesar.value),
);
// ================= END RINGKASAN TANGGAL =================

// ================= PANEL KALENDER (BOTTOM SHEET) =================
// Aturan memilih tanggal (draft, baru diterapkan saat "Pilih Tanggal" ditekan):
//  - Ketuk 1 tanggal            → cuti sehari.
//  - Ketuk tanggal lain          → rentang diperluas ke tanggal itu.
//  - Ketuk tanggal terpilih lagi → pilihan dibatalkan.
//      · jika rentang, ketuk tanggal awal/akhir → tanggal itu dilepas,
//        tanggal lainnya tetap terpilih.
//  - Ketuk tanggal DI TENGAH rentang → rentang dipersempit lewat tepi terdekat.
//  - Tombol "Reset" mengosongkan semua pilihan.
// Tanggal yang sudah lewat BOLEH dipilih. Yang dinonaktifkan: tanggal di luar
// jendela tahun pengajuan, dan tanggal yang membuat rentang melebihi 92 hari
// kalender.
const sheetTanggalTerbuka = ref(false);
const draftStart = ref(null);
const draftEnd = ref(null); // null = cuti sehari (hanya draftStart)
const sorotTanggal = ref(null);
const pickerBulan = ref(false);

const namaHariMini = ["Min", "Sen", "Sel", "Rab", "Kam", "Jum", "Sab"];

const calendarMonth = ref(
    new Date(new Date().getFullYear(), new Date().getMonth(), 1),
);

const calendarLabel = computed(() =>
    calendarMonth.value.toLocaleDateString("id-ID", {
        month: "long",
        year: "numeric",
    }),
);

const tahunKalender = computed(() => calendarMonth.value.getFullYear());

const prevCalendarMonth = () => {
    const d = new Date(calendarMonth.value);
    d.setMonth(d.getMonth() - 1);
    calendarMonth.value = d;
};

const nextCalendarMonth = () => {
    const d = new Date(calendarMonth.value);
    d.setMonth(d.getMonth() + 1);
    calendarMonth.value = d;
};

const geserTahunKalender = (delta) => {
    calendarMonth.value = new Date(
        tahunKalender.value + delta,
        calendarMonth.value.getMonth(),
        1,
    );
};

const pilihBulanKalender = (index) => {
    calendarMonth.value = new Date(tahunKalender.value, index, 1);
    pickerBulan.value = false;
};

const keHariIni = () => {
    const n = new Date();
    calendarMonth.value = new Date(n.getFullYear(), n.getMonth(), 1);
    pickerBulan.value = false;
};

const bukaSheetTanggal = () => {
    draftStart.value = form.tanggal_mulai || null;
    // Cuti sehari disimpan sebagai draftEnd = null supaya konsisten
    draftEnd.value =
        form.tanggal_selesai && form.tanggal_selesai !== form.tanggal_mulai
            ? form.tanggal_selesai
            : null;
    sorotTanggal.value = null;
    pickerBulan.value = false;

    if (form.tanggal_mulai) {
        const d = parseLocalDate(form.tanggal_mulai);
        calendarMonth.value = new Date(d.getFullYear(), d.getMonth(), 1);
    } else {
        keHariIni();
    }
    dropdownJenisTerbuka.value = false;
    sheetTanggalTerbuka.value = true;
};

const tutupSheetTanggal = () => {
    sheetTanggalTerbuka.value = false;
};

const resetDraft = () => {
    draftStart.value = null;
    draftEnd.value = null;
};

// Tanggal tidak boleh dipilih bila:
//  - tahunnya di luar jendela pengajuan (prop batas_tahun_pengajuan), atau
//  - perluasan rentang ke tanggal itu melebihi MAKS_RENTANG_HARI.
const tanggalTerkunci = (dateStr) => {
    // Batas jendela tahun (min..max), bila dikirim backend.
    if (props.batas_tahun_pengajuan) {
        const th = Number(dateStr.slice(0, 4));
        if (
            th < props.batas_tahun_pengajuan.min ||
            th > props.batas_tahun_pengajuan.max
        ) {
            return true;
        }
    }

    const s = draftStart.value;
    if (!s) return false;
    const e = draftEnd.value || s;
    if (dateStr >= s && dateStr <= e) return false; // di dalam pilihan: aman

    const awal = dateStr < s ? dateStr : s;
    const akhir = dateStr > e ? dateStr : e;
    return selisihHariInklusif(awal, akhir) > MAKS_RENTANG_HARI;
};

const pilihTanggal = (cell) => {
    if (!cell || cell.isLocked) return;

    const d = cell.dateStr;
    const s = draftStart.value;
    const e = draftEnd.value || s;

    // 1) Belum ada pilihan → mulai dari tanggal ini (cuti sehari)
    if (!s) {
        draftStart.value = d;
        draftEnd.value = null;
        return;
    }

    // 2) Baru satu tanggal terpilih
    if (s === e) {
        if (d === s) {
            // ketuk lagi = batalkan
            resetDraft();
        } else if (d > s) {
            draftEnd.value = d;
        } else {
            // tanggal sebelum "mulai": rentang diperluas ke belakang
            draftEnd.value = s;
            draftStart.value = d;
        }
        return;
    }

    // 3) Sudah berupa rentang (s < e)
    if (d === s) {
        // lepas tanggal awal → tanggal akhir menjadi satu-satunya pilihan
        draftStart.value = e;
        draftEnd.value = null;
        return;
    }
    if (d === e) {
        // lepas tanggal akhir → tanggal awal menjadi satu-satunya pilihan
        draftEnd.value = null;
        return;
    }
    if (d < s) {
        draftStart.value = d; // perluas ke belakang
        return;
    }
    if (d > e) {
        draftEnd.value = d; // perluas ke depan
        return;
    }
    // di tengah rentang: persempit lewat tepi terdekat
    const jarakKeAwal = parseLocalDate(d) - parseLocalDate(s);
    const jarakKeAkhir = parseLocalDate(e) - parseLocalDate(d);
    if (jarakKeAwal < jarakKeAkhir) {
        draftStart.value = d;
    } else {
        draftEnd.value = d;
    }
};

const konfirmasiTanggal = () => {
    if (!draftStart.value) return;
    form.tanggal_mulai = draftStart.value;
    form.tanggal_selesai = draftEnd.value || draftStart.value;
    form.clearErrors("tanggal_mulai", "tanggal_selesai");
    sheetTanggalTerbuka.value = false;
};

const estimasiDraft = computed(() =>
    draftStart.value
        ? hitungHariKerja(draftStart.value, draftEnd.value || draftStart.value)
        : 0,
);

// Daftar hari libur yang jatuh di dalam rentang yang sedang dipilih
// (draft), dipakai untuk menjelaskan kenapa tanggal tertentu tidak dihitung.
const liburDalamDraft = computed(() => {
    if (!draftStart.value) return [];
    const akhir = parseLocalDate(draftEnd.value || draftStart.value);
    const hasil = [];
    const cur = parseLocalDate(draftStart.value);
    while (cur <= akhir) {
        const libur = liburMap.value.get(toDateStr(cur));
        if (libur) hasil.push(libur);
        cur.setDate(cur.getDate() + 1);
    }
    return hasil;
});

const labelTombolPilihTanggal = computed(() =>
    draftStart.value
        ? `Pilih Tanggal (${formatRentang(draftStart.value, draftEnd.value || draftStart.value)})`
        : "Pilih Tanggal",
);

const calendarDays = computed(() => {
    const year = calendarMonth.value.getFullYear();
    const month = calendarMonth.value.getMonth();

    const firstDay = new Date(year, month, 1);
    const totalDaysInMonth = new Date(year, month + 1, 0).getDate();
    const leadingBlanks = firstDay.getDay();

    const start = draftStart.value;
    const rangeEnd = draftEnd.value || draftStart.value;
    const isMulti = !!(start && rangeEnd && rangeEnd !== start);

    const cells = [];
    for (let i = 0; i < leadingBlanks; i++) {
        cells.push(null);
    }

    for (let day = 1; day <= totalDaysInMonth; day++) {
        const date = new Date(year, month, day);
        const dateStr = toDateStr(date);
        const dayOfWeek = date.getDay();

        cells.push({
            day,
            dateStr,
            isWeekend: dayOfWeek === 0 || dayOfWeek === 6,
            isLocked: tanggalTerkunci(dateStr),
            isToday: dateStr === todayStr.value,
            libur: liburMap.value.get(dateStr) ?? null,
            isStart: dateStr === start,
            isEnd: dateStr === rangeEnd,
            inRange: isMulti && dateStr > start && dateStr < rangeEnd,
            isMulti,
        });
    }
    return cells;
});

const bandClass = (c) => {
    if (!c.isMulti) return "";
    if (c.isStart) return "bg-emerald-100 rounded-l-full";
    if (c.isEnd) return "bg-emerald-100 rounded-r-full";
    if (c.inRange) return "bg-emerald-100";
    return "";
};

// Status tampilan tiap tanggal:
//  - terpilih (awal/akhir) : isi HIJAU penuh, teks putih
//  - di dalam rentang       : latar hijau muda dari band (sel transparan)
//  - terkunci               : abu-abu pudar, tidak bisa diketuk
//                             (di luar jendela tahun, atau melebihi 92 hari)
//  - tidak dipilih          : isi PUTIH
// Efek hover hanya di perangkat yang punya kursor.
const cellClass = (c) => {
    if (c.isStart || c.isEnd) return "bg-emerald-600 text-white font-bold";
    if (c.isLocked) return "bg-white text-slate-300 cursor-not-allowed";
    if (c.inRange) {
        return c.libur
            ? c.libur.isCutiBersama
                ? "bg-transparent text-amber-600 font-semibold"
                : "bg-transparent text-rose-600 font-semibold"
            : "bg-transparent text-emerald-800 font-semibold";
    }
    if (c.libur) {
        return c.libur.isCutiBersama
            ? "bg-white text-amber-600 font-semibold [@media(hover:hover)]:hover:bg-amber-50"
            : "bg-white text-rose-600 font-semibold [@media(hover:hover)]:hover:bg-rose-50";
    }
    if (c.isWeekend) {
        return "bg-white text-slate-400 [@media(hover:hover)]:hover:bg-slate-100";
    }
    return "bg-white text-slate-700 [@media(hover:hover)]:hover:bg-slate-100";
};

const hariLiburBulanIni = computed(() => {
    const prefix = `${calendarMonth.value.getFullYear()}-${String(calendarMonth.value.getMonth() + 1).padStart(2, "0")}`;
    return [...liburMap.value.values()]
        .filter((l) => l.tanggal.startsWith(prefix))
        .sort((a, b) => a.tanggal.localeCompare(b.tanggal));
});

const sorotLibur = (libur) => {
    sorotTanggal.value = libur.tanggal;
};
// ================= END PANEL KALENDER =================

// ================= PANEL KETENTUAN & ALUR PERSETUJUAN =================
// Di HP: dibuka lewat tautan "Lihat ketentuan & alur persetujuan" (bottom sheet).
// Di desktop (lg ke atas): isinya tampil permanen di kolom samping.
const sheetKetentuanTerbuka = ref(false);

const bukaSheetKetentuan = () => {
    dropdownJenisTerbuka.value = false;
    sheetKetentuanTerbuka.value = true;
};

const daftarKetentuan = [
    "Lupa mengajukan? Cuti yang sudah terlanjur diambil (kemarin, minggu lalu, dan seterusnya) masih bisa diajukan sekarang.",
    "Yang dihitung sebagai hari cuti hanya hari kerja. Sabtu, Minggu, dan hari libur tidak dihitung.",
    "Sisa cuti tahun lalu bisa dipakai di tahun ini, maksimal 6 hari. Kalau cuti 2 tahun terakhir sama sekali tidak dipakai, maksimal 12 hari.",
    "Kalau cuti Anda melewati tahun baru (misalnya akhir Desember sampai awal Januari), hari cutinya dihitung terpisah per tahun. Cuti di tahun baru baru bisa diajukan setelah Admin SDM menyiapkan jatah cuti tahun itu.",
];
// ================= END PANEL KETENTUAN =================

// ================= KUNCI SCROLL, TOMBOL ESC, & LISTENER GLOBAL =================
watch(
    () => sheetTanggalTerbuka.value || sheetKetentuanTerbuka.value,
    (terbuka) => {
        document.body.style.overflow = terbuka ? "hidden" : "";
    },
);

const onKeydownGlobal = (e) => {
    if (e.key === "Escape") {
        sheetTanggalTerbuka.value = false;
        sheetKetentuanTerbuka.value = false;
        dropdownJenisTerbuka.value = false;
    }
};

onMounted(() => {
    document.addEventListener("click", tutupDropdownSaatKlikLuar);
    document.addEventListener("keydown", onKeydownGlobal);
});

onUnmounted(() => {
    document.removeEventListener("click", tutupDropdownSaatKlikLuar);
    document.removeEventListener("keydown", onKeydownGlobal);
    document.body.style.overflow = "";
    if (typeof stopInvalidListener === "function") stopInvalidListener();
});
// ================= END LISTENER GLOBAL =================

const submit = () => {
    if (form.processing) return;

    if (!form.jenis_cuti) {
        Swal.fire({
            icon: "error",
            title: "Jenis cuti belum dipilih",
            text: "Silakan pilih jenis cuti terlebih dahulu.",
            confirmButtonColor: "#ef4444",
        });
        return;
    }

    if (form.jenis_cuti === "Cuti Besar" && !canChooseCutiBesar.value) {
        Swal.fire({
            icon: "error",
            title: "Belum memenuhi syarat",
            text: `Cuti Besar hanya dapat diajukan jika masa kerja sudah mencapai 5 tahun. Masa kerja Anda saat ini terhitung ${masaKerjaTahun.value} tahun.`,
            confirmButtonColor: "#ef4444",
        });
        return;
    }

    if (
        form.jenis_cuti === "Cuti Alasan Penting" &&
        !canChooseAlasanPenting.value
    ) {
        Swal.fire({
            icon: "error",
            title: "Belum memenuhi syarat",
            text: "Cuti Alasan Penting hanya dapat diajukan oleh pegawai berstatus PNS.",
            confirmButtonColor: "#ef4444",
        });
        return;
    }

    if (form.jenis_cuti === "Cuti Melahirkan" && !canChooseMelahirkan.value) {
        Swal.fire({
            icon: "error",
            title: "Belum memenuhi syarat",
            text: "Cuti Melahirkan hanya dapat diajukan oleh pegawai perempuan.",
            confirmButtonColor: "#ef4444",
        });
        return;
    }

    // Tanggal belum dipilih: buka panel kalender.
    if (!form.tanggal_mulai || !form.tanggal_selesai) {
        bukaSheetTanggal();
        return;
    }

    if (tahunTanpaSaldo.value !== null) {
        Swal.fire({
            icon: "error",
            title: "Saldo belum tersedia",
            text: `Saldo cuti tahun ${tahunTanpaSaldo.value} belum tersedia. Cuti Tahunan untuk tahun tersebut baru bisa diajukan setelah Admin SDM membuat saldonya.`,
            confirmButtonColor: "#ef4444",
        });
        return;
    }

    // [BARU] Saldo salah satu tahun tidak cukup (termasuk cuti lintas tahun).
    if (tahunSaldoKurang.value) {
        const k = tahunSaldoKurang.value;
        Swal.fire({
            icon: "error",
            title: "Saldo tidak mencukupi",
            text: `Saldo cuti tahun ${k.tahun} tidak cukup: butuh ${k.hari} hari kerja, tersedia ${k.tersedia} hari.`,
            confirmButtonColor: "#ef4444",
        });
        return;
    }

    // Tanggal bentrok dengan pengajuan aktif lain.
    if (pengajuanBentrok.value) {
        Swal.fire({
            icon: "error",
            title: "Tanggal bentrok",
            text: "Tanggal yang dipilih bentrok dengan pengajuan cuti Anda yang masih aktif.",
            confirmButtonColor: "#ef4444",
        });
        return;
    }

    // Lampiran bermasalah: jangan kirim, pesan sudah tampil di bawah input.
    if (lampiranError.value) {
        Swal.fire({
            icon: "error",
            title: "Lampiran tidak valid",
            text: lampiranError.value,
            confirmButtonColor: "#ef4444",
        });
        return;
    }

    // Cuti Melahirkan: anak ke- wajib. Pesan tampil merah di bawah kolom
    // (tanpa SweetAlert), halaman scroll ke kolom itu.
    if (form.jenis_cuti === "Cuti Melahirkan") {
        const n = parseInt(form.anak_ke, 10);
        if (!form.anak_ke || isNaN(n) || n < 1) {
            anakKeError.value =
                "Kolom Anak ke-berapa wajib diisi untuk Cuti Melahirkan.";
            nextTick(() => {
                anakKeInput.value?.scrollIntoView({
                    behavior: "smooth",
                    block: "center",
                });
                anakKeInput.value?.focus({ preventScroll: true });
            });
            return;
        }
    } else {
        form.anak_ke = "";
    }

    // Kolom wajib lain: pesan inline, tanpa popup.
    if (!String(form.keterangan || "").trim()) {
        form.setError("keterangan", "Keterangan / alasan cuti wajib diisi.");
        return;
    }
    if (!String(form.alamat_cuti || "").trim()) {
        form.setError("alamat_cuti", "Alamat selama cuti wajib diisi.");
        editorKontakTerbuka.value = true;
        return;
    }
    if (!String(form.no_telp || "").trim()) {
        form.setError("no_telp", "No. telepon / HP wajib diisi.");
        editorKontakTerbuka.value = true;
        return;
    }

    form.post(route("karyawan.ajukan.store"), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            resetSemuaForm();
            Swal.fire({
                icon: "success",
                title: "Pengajuan Berhasil!",
                text: "Permohonan cuti Anda berhasil dikirim dan sedang menunggu persetujuan Atasan.",
                confirmButtonColor: "#10b981",
                confirmButtonText: "Mengerti",
            });
        },
    });
};
</script>

<template>
    <Head title="Ajukan Cuti" />

    <MainLayout>
        <!-- Satu komponen untuk semua ukuran layar:
             - HP / tablet : hanya formulir (singkat), lebar maks. xl.
             - Desktop (lg+) : formulir + kolom samping berisi ringkasan saldo,
               alur persetujuan, dan ketentuan.

             Bar tombol di bawah formulir memakai `sticky bottom-[4.5rem]` di HP.
             Padding bawah pembungkus di HP (pb-28 = 7rem, lebih besar dari 4.5rem)
             menjaga agar bar tidak menimpa kolom terakhir (Lampiran Dokumen) saat
             halaman di-scroll sampai bawah. Di md ke atas kembali pb-6. -->
        <div class="w-full min-w-0 max-w-xl lg:max-w-5xl mx-auto pb-28 md:pb-6">
            <div class="mb-3">
                <h1
                    class="text-xl sm:text-2xl font-bold text-slate-800 tracking-tight"
                >
                    Ajukan Cuti
                </h1>
                <p class="hidden sm:block text-slate-500 mt-1 text-sm">
                    Lengkapi data berikut untuk mengajukan permohonan cuti baru.
                </p>
            </div>

            <div
                class="lg:grid lg:grid-cols-[minmax(0,1fr)_320px] lg:gap-5 lg:items-start"
            >
                <!-- Kartu utama. Tanpa overflow-hidden agar bar tombol bisa "sticky". -->
                <div
                    class="bg-white rounded-2xl shadow-sm border border-slate-200 min-w-0"
                >
                    <div class="p-4 sm:p-5 space-y-4">
                        <!-- Error saldo (dari server) -->
                        <div
                            v-if="
                                form.errors.tanggal_mulai &&
                                form.errors.tanggal_mulai.includes('Saldo')
                            "
                            class="p-3 bg-red-50 text-red-700 rounded-xl border border-red-200 flex items-start gap-2.5"
                        >
                            <svg
                                class="w-4 h-4 text-red-500 mt-0.5 shrink-0"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
                                ></path>
                            </svg>
                            <span class="text-xs font-semibold break-words">{{
                                form.errors.tanggal_mulai
                            }}</span>
                        </div>

                        <!-- Error jenis cuti (masa kerja Cuti Besar / saldo tahun belum ada / saldo per tahun kurang / bukan PNS) -->
                        <div
                            v-if="form.errors.jenis_cuti"
                            class="p-3 bg-red-50 text-red-700 rounded-xl border border-red-200 flex items-start gap-2.5"
                        >
                            <svg
                                class="w-4 h-4 text-red-500 mt-0.5 shrink-0"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
                                ></path>
                            </svg>
                            <span class="text-xs font-semibold break-words">{{
                                form.errors.jenis_cuti
                            }}</span>
                        </div>

                        <form @submit.prevent="submit" class="space-y-4">
                            <!-- 1. Jenis Cuti (DROPDOWN KUSTOM) -->
                            <div>
                                <label
                                    for="jenis-cuti"
                                    class="block text-sm font-semibold text-slate-700 mb-1.5"
                                >
                                    Jenis Cuti
                                    <span class="text-red-500">*</span>
                                </label>

                                <div
                                    data-dropdown
                                    class="relative w-full"
                                    @keydown.esc="dropdownJenisTerbuka = false"
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
                                                d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                                            />
                                        </svg>
                                    </div>

                                    <button
                                        id="jenis-cuti"
                                        type="button"
                                        aria-label="Pilih jenis cuti"
                                        aria-haspopup="listbox"
                                        :aria-expanded="dropdownJenisTerbuka"
                                        :title="jenisCutiTerpilihLabel"
                                        @click="toggleDropdownJenis"
                                        class="w-full min-h-[44px] text-left text-sm border bg-white cursor-pointer rounded-lg shadow-sm text-gray-700 py-2 pl-9 pr-8 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 focus:outline-none"
                                        :class="
                                            form.errors.jenis_cuti
                                                ? 'border-red-400'
                                                : 'border-gray-300'
                                        "
                                    >
                                        <span class="block truncate">{{
                                            jenisCutiTerpilihLabel
                                        }}</span>
                                    </button>

                                    <span
                                        class="absolute inset-y-0 right-0 flex items-center pr-2 pointer-events-none"
                                    >
                                        <svg
                                            class="w-3.5 h-3.5 text-gray-500 transition-transform"
                                            :class="{
                                                'rotate-180':
                                                    dropdownJenisTerbuka,
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
                                        v-if="dropdownJenisTerbuka"
                                        role="listbox"
                                        class="absolute left-0 right-0 top-full z-30 mt-1 max-h-60 overflow-y-auto rounded-lg border border-gray-200 bg-white py-1 shadow-lg"
                                    >
                                        <li
                                            v-for="opt in opsiJenisCuti"
                                            :key="opt.value"
                                        >
                                            <button
                                                type="button"
                                                role="option"
                                                :title="opt.label"
                                                :aria-selected="
                                                    form.jenis_cuti ===
                                                    opt.value
                                                "
                                                :aria-disabled="opt.disabled"
                                                :disabled="opt.disabled"
                                                @click="pilihJenisCuti(opt)"
                                                :class="
                                                    opt.disabled
                                                        ? 'text-gray-400 bg-gray-50 cursor-not-allowed'
                                                        : form.jenis_cuti ===
                                                            opt.value
                                                          ? 'bg-emerald-50 text-emerald-700 font-semibold cursor-pointer'
                                                          : 'text-gray-700 hover:bg-gray-50 cursor-pointer'
                                                "
                                                class="block w-full whitespace-normal break-words leading-tight px-3 py-2 text-left text-sm"
                                            >
                                                {{ opt.label }}
                                            </button>
                                        </li>
                                    </ul>
                                </div>
                            </div>

                            <!-- Anak ke- (hanya Cuti Melahirkan) -->
                            <div
                                v-if="form.jenis_cuti === 'Cuti Melahirkan'"
                                class="animate-in fade-in duration-300"
                            >
                                <label
                                    for="anak-ke"
                                    class="block text-sm font-semibold text-slate-700 mb-1.5"
                                >
                                    Anak ke-berapa
                                    <span class="text-red-500">*</span>
                                </label>
                                <input
                                    id="anak-ke"
                                    name="anak_ke"
                                    ref="anakKeInput"
                                    v-model="form.anak_ke"
                                    type="number"
                                    min="1"
                                    placeholder="Contoh: 1, 2, atau 3"
                                    class="w-full min-h-[44px] text-base sm:text-sm rounded-lg py-2 px-3"
                                    :class="
                                        anakKeError || form.errors.anak_ke
                                            ? 'border-red-400 focus:ring-red-500 focus:border-red-500'
                                            : 'border-slate-300 focus:ring-emerald-500 focus:border-emerald-500'
                                    "
                                    :aria-invalid="
                                        !!(anakKeError || form.errors.anak_ke)
                                    "
                                    aria-describedby="anak-ke-error"
                                />
                                <span
                                    v-if="anakKeError || form.errors.anak_ke"
                                    id="anak-ke-error"
                                    class="text-xs text-red-500 mt-1 block"
                                    role="alert"
                                >
                                    {{ anakKeError || form.errors.anak_ke }}
                                </span>
                            </div>

                            <!-- 2. Tanggal Cuti (satu kolom, membuka panel kalender) -->
                            <div>
                                <label
                                    for="tanggal-cuti"
                                    class="block text-sm font-semibold text-slate-700 mb-1.5"
                                >
                                    Tanggal Cuti
                                    <span class="text-red-500">*</span>
                                </label>
                                <button
                                    id="tanggal-cuti"
                                    type="button"
                                    aria-haspopup="dialog"
                                    @click="bukaSheetTanggal"
                                    class="w-full min-h-[44px] flex items-center gap-2 text-left text-sm border bg-white rounded-lg shadow-sm py-2 px-3 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 focus:outline-none cursor-pointer"
                                    :class="
                                        form.errors.tanggal_mulai ||
                                        form.errors.tanggal_selesai
                                            ? 'border-red-400'
                                            : 'border-gray-300'
                                    "
                                >
                                    <svg
                                        class="w-4 h-4 text-emerald-600 shrink-0"
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"
                                        ></path>
                                    </svg>
                                    <span
                                        class="flex-1 min-w-0 truncate"
                                        :class="
                                            form.tanggal_mulai
                                                ? 'font-semibold text-slate-800'
                                                : 'text-slate-400'
                                        "
                                    >
                                        {{ labelTanggal }}
                                    </span>
                                    <svg
                                        class="w-3.5 h-3.5 text-gray-400 shrink-0"
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M9 5l7 7-7 7"
                                        ></path>
                                    </svg>
                                </button>

                                <p class="text-[11px] text-slate-400 mt-1">
                                    Satu pengajuan maksimal 3 bulan ({{
                                        MAKS_RENTANG_HARI
                                    }}
                                    hari, termasuk akhir pekan). Butuh lebih
                                    lama? Ajukan lagi terpisah.
                                </p>

                                <span
                                    v-if="
                                        form.errors.tanggal_mulai &&
                                        !form.errors.tanggal_mulai.includes(
                                            'Saldo',
                                        )
                                    "
                                    class="text-xs text-red-500 mt-1 block"
                                >
                                    {{ form.errors.tanggal_mulai }}
                                </span>
                                <span
                                    v-if="form.errors.tanggal_selesai"
                                    class="text-xs text-red-500 mt-1 block"
                                >
                                    {{ form.errors.tanggal_selesai }}
                                </span>

                                <!-- Ringkasan saldo: satu baris (lintas tahun: pecahan per tahun) -->
                                <p
                                    class="text-[11px] mt-1.5 font-medium"
                                    :class="
                                        form.jenis_cuti === 'Cuti Tahunan' &&
                                        sisaSetelahPengajuan < 0
                                            ? 'text-red-600'
                                            : 'text-slate-500'
                                    "
                                >
                                    {{ ringkasSaldo }}
                                </p>
                                <p
                                    v-if="
                                        form.jenis_cuti === 'Cuti Tahunan' &&
                                        sisaSetelahPengajuan < 0
                                    "
                                    class="text-[11px] mt-0.5 font-bold text-red-600"
                                >
                                    <template v-if="tahunSaldoKurang">
                                        Saldo cuti tahun
                                        {{ tahunSaldoKurang.tahun }} tidak
                                        cukup: butuh
                                        {{ tahunSaldoKurang.hari }} hari kerja,
                                        tersedia
                                        {{ tahunSaldoKurang.tersedia }} hari.
                                    </template>
                                    <template v-else>
                                        Saldo cuti tidak mencukupi untuk tanggal
                                        yang dipilih.
                                    </template>
                                </p>

                                <!-- Peringatan: hanya tampil bila relevan -->
                                <div class="mt-2 space-y-2">
                                    <p
                                        v-if="hariLiburDalamRentang.length > 0"
                                        class="text-[11px] text-blue-700 bg-blue-50 border border-blue-100 rounded-lg px-3 py-2 leading-relaxed break-words"
                                    >
                                        <span class="font-semibold"
                                            >{{
                                                hariLiburDalamRentang.length
                                            }}
                                            tanggal tidak dihitung</span
                                        >
                                        karena libur:
                                        <span
                                            v-for="(
                                                libur, idx
                                            ) in hariLiburDalamRentang"
                                            :key="libur.tanggal"
                                        >
                                            <span class="font-semibold">{{
                                                libur.judul
                                            }}</span>
                                            ({{
                                                formatTanggalPendek(
                                                    libur.tanggal,
                                                )
                                            }})<span
                                                v-if="
                                                    idx <
                                                    hariLiburDalamRentang.length -
                                                        1
                                                "
                                                >,
                                            </span>
                                        </span>
                                    </p>

                                    <p
                                        v-if="
                                            estimasiHari === 0 &&
                                            form.tanggal_mulai &&
                                            form.tanggal_selesai
                                        "
                                        class="text-[11px] font-bold text-red-500"
                                    >
                                        *Tanggal yang dipilih tidak valid karena
                                        hanya mencakup akhir pekan
                                        (Sabtu/Minggu) dan/atau hari libur
                                        nasional/cuti bersama.
                                    </p>

                                    <!-- Saldo tahun belum dibuat Admin SDM (khusus Cuti Tahunan) -->
                                    <div
                                        v-if="tahunTanpaSaldo !== null"
                                        class="flex items-start gap-2 bg-red-50 border border-red-200 rounded-lg px-3 py-2"
                                    >
                                        <svg
                                            class="w-4 h-4 text-red-500 mt-0.5 shrink-0"
                                            fill="none"
                                            stroke="currentColor"
                                            viewBox="0 0 24 24"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                stroke-width="2"
                                                d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
                                            ></path>
                                        </svg>
                                        <p
                                            class="min-w-0 text-[11px] text-red-700 leading-relaxed break-words"
                                        >
                                            <span class="font-semibold"
                                                >Saldo cuti tahun
                                                {{ tahunTanpaSaldo }} belum
                                                tersedia.</span
                                            >
                                            Cuti Tahunan untuk tahun tersebut
                                            baru bisa diajukan setelah Admin SDM
                                            membuat saldonya. Pilih tanggal di
                                            tahun yang saldonya sudah ada, atau
                                            pilih jenis cuti lain yang tidak
                                            memotong saldo.
                                        </p>
                                    </div>

                                    <!-- Tanggal bentrok dengan pengajuan aktif lain -->
                                    <div
                                        v-if="pengajuanBentrok"
                                        class="flex items-start gap-2 bg-red-50 border border-red-200 rounded-lg px-3 py-2"
                                    >
                                        <svg
                                            class="w-4 h-4 text-red-500 mt-0.5 shrink-0"
                                            fill="none"
                                            stroke="currentColor"
                                            viewBox="0 0 24 24"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                stroke-width="2"
                                                d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
                                            ></path>
                                        </svg>
                                        <p
                                            class="min-w-0 text-[11px] text-red-700 leading-relaxed break-words"
                                        >
                                            <span class="font-semibold"
                                                >Tanggal bentrok dengan
                                                pengajuan lain.</span
                                            >
                                            Anda sudah punya
                                            {{
                                                pengajuanBentrok.jenis_cuti
                                            }}
                                            ({{
                                                formatTanggalPendek(
                                                    pengajuanBentrok.tanggal_mulai,
                                                )
                                            }}
                                            -
                                            {{
                                                formatTanggalPendek(
                                                    pengajuanBentrok.tanggal_selesai,
                                                )
                                            }}) yang
                                            {{
                                                pengajuanBentrok.status ===
                                                "disetujui"
                                                    ? "sudah disetujui"
                                                    : "masih menunggu persetujuan"
                                            }}. Batalkan pengajuan tersebut
                                            terlebih dahulu atau pilih tanggal
                                            lain.
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <!-- 3. Alasan Cuti -->
                            <div>
                                <label
                                    for="keterangan"
                                    class="block text-sm font-semibold text-slate-700 mb-1.5"
                                >
                                    Alasan Cuti
                                    <span class="text-red-500">*</span>
                                </label>
                                <textarea
                                    id="keterangan"
                                    name="keterangan"
                                    v-model="form.keterangan"
                                    rows="2"
                                    placeholder="Tuliskan alasan atau keperluan cuti Anda..."
                                    class="w-full min-h-[64px] text-base sm:text-sm rounded-lg py-2 px-3 resize-y"
                                    :class="
                                        form.errors.keterangan
                                            ? 'border-red-400 focus:ring-red-500 focus:border-red-500'
                                            : 'border-slate-300 focus:ring-emerald-500 focus:border-emerald-500'
                                    "
                                ></textarea>
                                <span
                                    v-if="form.errors.keterangan"
                                    class="text-xs text-red-500 mt-1 block"
                                    role="alert"
                                >
                                    {{ form.errors.keterangan }}
                                </span>
                            </div>

                            <!-- 4. Alamat & No. HP (ringkas, terisi dari profil) -->
                            <div
                                class="rounded-xl border px-3 py-2.5"
                                :class="
                                    form.errors.alamat_cuti ||
                                    form.errors.no_telp
                                        ? 'bg-red-50/40 border-red-300'
                                        : 'bg-slate-50 border-slate-200'
                                "
                            >
                                <div class="flex items-center gap-2">
                                    <svg
                                        class="w-4 h-4 text-slate-400 shrink-0"
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"
                                        ></path>
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"
                                        ></path>
                                    </svg>
                                    <p
                                        class="min-w-0 flex-1 truncate text-xs text-slate-600"
                                        :title="ringkasKontak"
                                    >
                                        <span
                                            class="font-semibold text-slate-700"
                                            >Alamat &amp; HP:</span
                                        >
                                        {{ ringkasKontak }}
                                    </p>
                                    <button
                                        type="button"
                                        @click="
                                            editorKontakTerbuka =
                                                !editorKontakTerbuka
                                        "
                                        :aria-expanded="editorKontakTerbuka"
                                        class="shrink-0 min-h-[36px] px-2 text-xs font-bold text-emerald-700 hover:text-emerald-800"
                                    >
                                        {{
                                            editorKontakTerbuka
                                                ? "Tutup"
                                                : "Ubah"
                                        }}
                                    </button>
                                </div>

                                <div
                                    v-show="editorKontakTerbuka"
                                    class="mt-3 pt-3 border-t border-slate-200 space-y-3"
                                >
                                    <div>
                                        <div
                                            class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 mb-1.5"
                                        >
                                            <label
                                                for="alamat-cuti"
                                                class="block text-xs font-semibold text-slate-700"
                                            >
                                                Alamat Selama Cuti
                                                <span class="text-red-500"
                                                    >*</span
                                                >
                                            </label>
                                            <label
                                                for="gunakan-alamat-domisili"
                                                class="flex min-h-[32px] items-center gap-2 text-[11px] font-medium text-slate-600 cursor-pointer"
                                            >
                                                <input
                                                    id="gunakan-alamat-domisili"
                                                    name="gunakan_alamat_domisili"
                                                    type="checkbox"
                                                    v-model="
                                                        gunakanAlamatDomisili
                                                    "
                                                    @change="
                                                        toggleAlamatDomisili
                                                    "
                                                    class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500"
                                                />
                                                Sesuai alamat domisili di profil
                                            </label>
                                        </div>
                                        <textarea
                                            id="alamat-cuti"
                                            name="alamat_cuti"
                                            v-model="form.alamat_cuti"
                                            rows="2"
                                            :disabled="gunakanAlamatDomisili"
                                            placeholder="Contoh: Jl. Merdeka No. 10..."
                                            class="w-full min-h-[56px] text-base sm:text-sm border-slate-300 rounded-lg focus:ring-emerald-500 focus:border-emerald-500 py-2 px-3 resize-y disabled:bg-slate-100 disabled:text-slate-500"
                                        ></textarea>
                                        <span
                                            v-if="form.errors.alamat_cuti"
                                            class="text-xs text-red-500 mt-1 block"
                                            role="alert"
                                        >
                                            {{ form.errors.alamat_cuti }}
                                        </span>
                                    </div>

                                    <div>
                                        <div
                                            class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 mb-1.5"
                                        >
                                            <label
                                                for="no-telp"
                                                class="block text-xs font-semibold text-slate-700"
                                            >
                                                No. Telepon / HP
                                                <span class="text-red-500"
                                                    >*</span
                                                >
                                            </label>
                                            <label
                                                for="gunakan-nomor-telepon-profil"
                                                class="flex min-h-[32px] items-center gap-2 text-[11px] font-medium text-slate-600 cursor-pointer"
                                            >
                                                <input
                                                    id="gunakan-nomor-telepon-profil"
                                                    name="gunakan_nomor_telepon_profil"
                                                    type="checkbox"
                                                    v-model="
                                                        gunakanNomorTeleponProfil
                                                    "
                                                    @change="
                                                        toggleNomorTeleponProfil
                                                    "
                                                    class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500"
                                                />
                                                Sesuai nomor telepon di profil
                                            </label>
                                        </div>
                                        <input
                                            id="no-telp"
                                            name="no_telp"
                                            v-model="form.no_telp"
                                            type="tel"
                                            :disabled="
                                                gunakanNomorTeleponProfil
                                            "
                                            placeholder="Contoh: 081234567890"
                                            class="w-full min-h-[44px] text-base sm:text-sm border-slate-300 rounded-lg focus:ring-emerald-500 focus:border-emerald-500 py-2 px-3 disabled:bg-slate-100 disabled:text-slate-500"
                                        />
                                        <span
                                            v-if="form.errors.no_telp"
                                            class="text-xs text-red-500 mt-1 block"
                                            role="alert"
                                        >
                                            {{ form.errors.no_telp }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- 5. Lampiran (opsional) — tampil sebagai kolom input -->
                            <div>
                                <label
                                    for="lampiran-pilih"
                                    class="block text-sm font-semibold text-slate-700 mb-1.5"
                                >
                                    Lampiran Dokumen
                                    <span
                                        class="text-xs font-normal text-slate-400"
                                        >(opsional)</span
                                    >
                                </label>

                                <input
                                    id="lampiran"
                                    name="lampiran"
                                    ref="lampiranInput"
                                    type="file"
                                    class="hidden"
                                    @change="onLampiranChange"
                                    accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                                />

                                <div
                                    class="flex items-center gap-2 w-full min-h-[44px] rounded-lg border bg-white shadow-sm pl-3 pr-1.5 py-1.5 focus-within:border-emerald-500 focus-within:ring-1 focus-within:ring-emerald-500"
                                    :class="
                                        lampiranError || form.errors.lampiran
                                            ? 'border-red-400'
                                            : 'border-gray-300'
                                    "
                                >
                                    <svg
                                        class="w-4 h-4 text-slate-400 shrink-0"
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"
                                        ></path>
                                    </svg>

                                    <!-- Belum ada file: seluruh kolom bisa diklik -->
                                    <template v-if="!form.lampiran">
                                        <button
                                            id="lampiran-pilih"
                                            type="button"
                                            @click="pilihLampiran"
                                            class="flex-1 min-w-0 min-h-[34px] text-left text-sm text-slate-400 truncate focus:outline-none cursor-pointer"
                                        >
                                            Belum ada file dipilih
                                        </button>
                                        <button
                                            type="button"
                                            @click="pilihLampiran"
                                            class="shrink-0 min-h-[34px] px-3 rounded-md bg-emerald-50 text-xs font-bold text-emerald-700 hover:bg-emerald-100 transition"
                                        >
                                            Pilih File
                                        </button>
                                    </template>

                                    <!-- File sudah dipilih: nama + ukuran di dalam kolom -->
                                    <template v-else>
                                        <div
                                            class="min-w-0 flex-1 leading-tight"
                                        >
                                            <p
                                                class="truncate text-sm font-semibold text-slate-700"
                                                :title="form.lampiran.name"
                                            >
                                                {{ form.lampiran.name }}
                                            </p>
                                            <p
                                                class="text-[11px] text-slate-400"
                                            >
                                                {{
                                                    formatUkuranLampiran(
                                                        form.lampiran.size,
                                                    )
                                                }}
                                            </p>
                                        </div>
                                        <button
                                            type="button"
                                            @click="pilihLampiran"
                                            class="shrink-0 min-h-[34px] px-3 rounded-md bg-emerald-50 text-xs font-bold text-emerald-700 hover:bg-emerald-100 transition"
                                        >
                                            Ganti
                                        </button>
                                        <button
                                            type="button"
                                            @click="hapusLampiran"
                                            class="shrink-0 min-h-[34px] px-3 rounded-md text-xs font-semibold text-slate-500 hover:bg-red-50 hover:text-red-600 transition"
                                            aria-label="Hapus lampiran"
                                        >
                                            Hapus
                                        </button>
                                    </template>
                                </div>

                                <p class="text-[11px] text-slate-400 mt-1">
                                    PDF, JPG, atau PNG · maksimal 2 MB
                                </p>
                                <span
                                    v-if="lampiranError || form.errors.lampiran"
                                    class="text-xs text-red-500 mt-1 block"
                                    role="alert"
                                >
                                    {{ lampiranError || form.errors.lampiran }}
                                </span>
                            </div>
                        </form>
                    </div>

                    <!-- BAR TOMBOL (sticky di HP)
                         Nilai bottom di HP menyisakan ruang untuk navigasi bawah aplikasi.
                         Sesuaikan 4.5rem dengan tinggi navigasi bawah di MainLayout. -->
                    <div
                        class="sticky bottom-[calc(4.5rem+env(safe-area-inset-bottom,0px))] md:bottom-0 z-20 bg-white/95 backdrop-blur border-t border-slate-100 rounded-b-2xl px-4 sm:px-5 py-3"
                    >
                        <button
                            type="button"
                            @click="submit"
                            :disabled="tombolNonaktif"
                            class="w-full min-h-[46px] px-6 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-sm font-bold shadow-md shadow-emerald-600/20 transition disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            {{
                                form.processing
                                    ? "Mengirim..."
                                    : labelTombolAjukan
                            }}
                        </button>

                        <div
                            class="mt-2 flex items-center justify-between gap-3"
                        >
                            <button
                                type="button"
                                @click="resetSemuaForm"
                                class="min-h-[36px] text-xs font-semibold text-slate-500 hover:text-slate-700"
                            >
                                Batal
                            </button>
                            <!-- Hanya di HP/tablet; di desktop isinya sudah ada di kolom samping -->
                            <button
                                type="button"
                                @click="bukaSheetKetentuan"
                                aria-haspopup="dialog"
                                class="lg:hidden min-h-[36px] text-xs font-semibold text-emerald-700 hover:text-emerald-800 underline underline-offset-2 text-right"
                            >
                                Lihat ketentuan &amp; alur persetujuan
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ================= KOLOM SAMPING (DESKTOP SAJA) ================= -->
                <aside class="hidden lg:block space-y-4 min-w-0">
                    <!-- Ringkasan Saldo -->
                    <section
                        class="bg-white rounded-2xl shadow-sm border border-slate-200 p-4"
                    >
                        <div class="flex items-center gap-2 mb-3">
                            <svg
                                class="w-4 h-4 text-emerald-600"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"
                                ></path>
                            </svg>
                            <h2 class="text-sm font-bold text-slate-800">
                                Ringkasan Saldo
                            </h2>
                        </div>

                        <!-- [BARU] Cuti Tahunan lintas tahun: satu kartu per tahun,
                             masing-masing berisi sisa saat ini, potongan, dan sisa setelahnya. -->
                        <div v-if="lintasTahun" class="space-y-2.5">
                            <div
                                v-for="r in rincianPerTahun"
                                :key="r.tahun"
                                class="rounded-xl border px-3 py-2.5"
                                :class="
                                    r.setelah < 0
                                        ? 'border-red-200 bg-red-50'
                                        : 'border-slate-200 bg-slate-50'
                                "
                            >
                                <p
                                    class="text-xs font-extrabold text-slate-700 mb-1.5"
                                >
                                    Saldo {{ r.tahun }}
                                </p>
                                <dl class="space-y-1 text-sm">
                                    <div
                                        class="flex items-center justify-between gap-3"
                                    >
                                        <dt class="text-slate-500">
                                            Sisa saat ini
                                        </dt>
                                        <dd class="font-bold text-slate-800">
                                            {{ r.tersedia }} hari
                                        </dd>
                                    </div>
                                    <div
                                        class="flex items-center justify-between gap-3"
                                    >
                                        <dt class="text-slate-500">
                                            Pengajuan ini
                                        </dt>
                                        <dd
                                            class="font-bold"
                                            :class="
                                                r.potong > 0
                                                    ? 'text-red-500'
                                                    : 'text-slate-800'
                                            "
                                        >
                                            {{ r.potong }} hari
                                        </dd>
                                    </div>
                                    <div
                                        class="flex items-center justify-between gap-3 border-t border-slate-200 pt-1"
                                    >
                                        <dt
                                            class="font-semibold text-slate-600"
                                        >
                                            Sisa setelah
                                        </dt>
                                        <dd
                                            class="font-extrabold"
                                            :class="
                                                r.setelah < 0
                                                    ? 'text-red-600'
                                                    : 'text-emerald-700'
                                            "
                                        >
                                            {{ r.setelah }} hari
                                        </dd>
                                    </div>
                                </dl>
                            </div>
                        </div>

                        <!-- Cuti satu tahun / jenis cuti lain: tampilan ringkas seperti semula -->
                        <dl v-else class="space-y-2 text-sm">
                            <div
                                class="flex items-center justify-between gap-3"
                            >
                                <dt class="text-slate-500">
                                    Sisa saldo saat ini
                                </dt>
                                <dd class="font-bold text-slate-800">
                                    {{ saldoSaatIni }} hari
                                </dd>
                            </div>
                            <div
                                class="flex items-center justify-between gap-3"
                            >
                                <dt class="text-slate-500">Pengajuan ini</dt>
                                <dd
                                    class="font-bold"
                                    :class="
                                        hariDipotong > 0
                                            ? 'text-red-500'
                                            : 'text-slate-800'
                                    "
                                >
                                    {{ hariDipotong }} hari
                                </dd>
                            </div>
                        </dl>

                        <div
                            v-if="!lintasTahun"
                            class="mt-3 flex items-center justify-between gap-3 rounded-xl px-3 py-2.5 text-sm font-bold"
                            :class="
                                sisaSetelahPengajuan < 0
                                    ? 'bg-red-50 text-red-700'
                                    : 'bg-emerald-50 text-emerald-700'
                            "
                        >
                            <span>Sisa setelah pengajuan</span>
                            <span>{{ sisaSetelahPengajuan }} hari</span>
                        </div>

                        <p
                            v-if="form.jenis_cuti !== 'Cuti Tahunan'"
                            class="mt-2 text-[11px] text-slate-400 leading-snug"
                        >
                            Jenis cuti ini tidak memotong saldo cuti tahunan.
                        </p>
                    </section>

                    <!-- Alur Persetujuan -->
                    <section
                        class="bg-white rounded-2xl shadow-sm border border-slate-200 p-4"
                    >
                        <div
                            class="flex items-center justify-between gap-2 mb-3"
                        >
                            <h2 class="text-sm font-bold text-slate-800">
                                Alur Persetujuan
                            </h2>
                            <span
                                class="rounded-full bg-emerald-100 text-emerald-700 px-2 py-0.5 text-[10px] font-bold text-right"
                            >
                                {{ kategoriPemohon.label }}
                            </span>
                        </div>

                        <p
                            v-if="alurSteps.length === 0"
                            class="text-xs text-slate-600 leading-relaxed bg-slate-50 rounded-lg px-3 py-2.5"
                        >
                            Jabatan Anda berada di level tertinggi Biro,
                            sehingga tidak ada jenjang persetujuan di dalam
                            biro.
                        </p>

                        <ol v-else class="relative">
                            <li class="flex gap-3 pb-3 relative">
                                <span
                                    class="absolute left-[13px] top-7 bottom-0 w-px bg-slate-200"
                                ></span>
                                <span
                                    class="relative z-10 h-7 w-7 shrink-0 rounded-full bg-emerald-600 text-white text-[11px] font-bold flex items-center justify-center"
                                    >1</span
                                >
                                <div class="min-w-0 pt-0.5">
                                    <p
                                        class="text-sm font-semibold text-slate-800"
                                    >
                                        Anda (Pemohon)
                                    </p>
                                    <p class="text-[11px] text-slate-400">
                                        Mengajukan cuti
                                    </p>
                                </div>
                            </li>
                            <li
                                v-for="(step, idx) in alurSteps"
                                :key="step.level"
                                class="flex gap-3 relative"
                                :class="
                                    idx < alurSteps.length - 1 ? 'pb-3' : ''
                                "
                            >
                                <span
                                    v-if="idx < alurSteps.length - 1"
                                    class="absolute left-[13px] top-7 bottom-0 w-px bg-slate-200"
                                ></span>
                                <span
                                    class="relative z-10 h-7 w-7 shrink-0 rounded-full bg-blue-100 text-blue-700 text-[11px] font-bold flex items-center justify-center"
                                >
                                    {{ step.no + 1 }}
                                </span>
                                <div class="min-w-0 pt-0.5">
                                    <p
                                        class="text-sm font-semibold text-slate-800"
                                    >
                                        {{ step.judul }}
                                    </p>
                                    <p class="text-[11px] text-slate-400">
                                        {{ step.sub }}
                                    </p>
                                </div>
                            </li>
                        </ol>
                    </section>

                    <!-- Ketentuan (bisa dilipat agar kolom tetap ringkas) -->
                    <details
                        class="group bg-white rounded-2xl shadow-sm border border-slate-200"
                    >
                        <summary
                            class="flex items-center justify-between gap-2 cursor-pointer list-none px-4 py-3 text-sm font-bold text-slate-800"
                        >
                            Ketentuan Pengajuan
                            <svg
                                class="w-3.5 h-3.5 text-slate-500 transition-transform group-open:rotate-180"
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
                        </summary>
                        <ul class="divide-y divide-slate-100 px-4 pb-3">
                            <li
                                v-for="(item, idx) in daftarKetentuan"
                                :key="idx"
                                class="flex items-start gap-2.5 py-2"
                            >
                                <svg
                                    class="w-4 h-4 text-emerald-600 mt-0.5 shrink-0"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2.5"
                                        d="M5 13l4 4L19 7"
                                    ></path>
                                </svg>
                                <p
                                    class="text-[12px] text-slate-600 leading-snug"
                                >
                                    {{ item }}
                                </p>
                            </li>
                        </ul>
                    </details>
                </aside>
            </div>
        </div>
    </MainLayout>

    <!-- ================= PANEL KALENDER (BOTTOM SHEET) ================= -->
    <Teleport to="body">
        <div
            v-if="sheetTanggalTerbuka"
            class="fixed inset-0 z-[9999] flex items-end justify-center bg-slate-900/50 backdrop-blur-sm p-0 sm:items-center sm:p-4"
            @click.self="tutupSheetTanggal"
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-label="Pilih tanggal cuti"
                class="bg-white rounded-t-3xl sm:rounded-2xl w-full max-w-md shadow-2xl border border-slate-100 flex flex-col max-h-[92dvh] animate-in slide-in-from-bottom duration-200"
            >
                <div class="pt-2.5 pb-1 flex justify-center sm:hidden">
                    <span class="h-1 w-10 rounded-full bg-slate-200"></span>
                </div>

                <div
                    class="px-4 pt-1 sm:pt-4 pb-2 flex items-center justify-between shrink-0"
                >
                    <h3 class="text-base font-bold text-slate-800">
                        Pilih Tanggal Cuti
                    </h3>
                    <button
                        type="button"
                        @click="tutupSheetTanggal"
                        class="text-slate-400 hover:text-slate-600 hover:bg-slate-100 p-1.5 rounded-full transition"
                        aria-label="Tutup"
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

                <div class="px-4 pb-3 overflow-y-auto flex-1">
                    <!-- Navigasi bulan -->
                    <div class="flex items-center justify-between mb-2">
                        <button
                            type="button"
                            @click="
                                pickerBulan
                                    ? geserTahunKalender(-1)
                                    : prevCalendarMonth()
                            "
                            class="min-w-[40px] min-h-[40px] flex items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 transition"
                            :aria-label="
                                pickerBulan
                                    ? 'Tahun sebelumnya'
                                    : 'Bulan sebelumnya'
                            "
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
                                    d="M15 19l-7-7 7-7"
                                ></path>
                            </svg>
                        </button>

                        <button
                            type="button"
                            @click="pickerBulan = !pickerBulan"
                            :aria-expanded="pickerBulan"
                            class="flex items-center gap-1.5 px-3 min-h-[40px] rounded-lg text-sm font-bold text-slate-800 capitalize hover:bg-slate-100 transition"
                        >
                            {{ pickerBulan ? tahunKalender : calendarLabel }}
                            <svg
                                class="w-3.5 h-3.5 text-slate-500 transition-transform"
                                :class="{ 'rotate-180': pickerBulan }"
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
                        </button>

                        <div class="flex items-center">
                            <button
                                type="button"
                                @click="keHariIni"
                                class="mr-1 px-2 min-h-[32px] rounded-lg text-[11px] font-bold text-emerald-700 hover:bg-emerald-50 transition"
                            >
                                Hari ini
                            </button>
                            <button
                                type="button"
                                @click="
                                    pickerBulan
                                        ? geserTahunKalender(1)
                                        : nextCalendarMonth()
                                "
                                class="min-w-[40px] min-h-[40px] flex items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 transition"
                                :aria-label="
                                    pickerBulan
                                        ? 'Tahun berikutnya'
                                        : 'Bulan berikutnya'
                                "
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
                                        d="M9 5l7 7-7 7"
                                    ></path>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <!-- Pemilih bulan & tahun -->
                    <div v-if="pickerBulan" class="grid grid-cols-3 gap-2 py-2">
                        <button
                            v-for="(nama, idx) in namaBulanSingkat"
                            :key="nama"
                            type="button"
                            @click="pilihBulanKalender(idx)"
                            class="min-h-[44px] rounded-lg text-sm font-semibold transition"
                            :class="
                                idx === calendarMonth.getMonth()
                                    ? 'bg-emerald-600 text-white'
                                    : 'bg-slate-50 text-slate-700 hover:bg-slate-100'
                            "
                        >
                            {{ nama }}
                        </button>
                    </div>

                    <!-- Kalender -->
                    <div v-else>
                        <p class="text-[11px] text-slate-400 mb-1.5">
                            Ketuk tanggal untuk memilih, ketuk lagi untuk
                            membatalkan. Ketuk tanggal lain untuk cuti lebih
                            dari sehari (maksimal 3 bulan per pengajuan).
                        </p>

                        <div class="grid grid-cols-7 text-center mb-1">
                            <span
                                v-for="hari in namaHariMini"
                                :key="hari"
                                class="text-[10px] font-semibold text-slate-400 py-1"
                            >
                                {{ hari }}
                            </span>
                        </div>

                        <div class="grid grid-cols-7 gap-y-1">
                            <template
                                v-for="(cell, idx) in calendarDays"
                                :key="idx"
                            >
                                <div v-if="!cell" class="h-9"></div>
                                <div
                                    v-else
                                    class="h-9 flex items-center justify-center"
                                    :class="bandClass(cell)"
                                >
                                    <button
                                        type="button"
                                        :disabled="cell.isLocked"
                                        :aria-label="`${cell.day}${cell.libur ? ', ' + cell.libur.judul : ''}`"
                                        :aria-pressed="
                                            cell.isStart || cell.isEnd
                                        "
                                        @click="pilihTanggal(cell)"
                                        class="relative h-9 w-9 flex items-center justify-center rounded-full text-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500"
                                        :class="[
                                            cellClass(cell),
                                            cell.isToday &&
                                            !cell.isStart &&
                                            !cell.isEnd
                                                ? 'ring-1 ring-slate-300'
                                                : '',
                                            sorotTanggal === cell.dateStr
                                                ? 'ring-2 ring-rose-300'
                                                : '',
                                        ]"
                                    >
                                        {{ cell.day }}
                                        <span
                                            v-if="cell.libur"
                                            class="absolute bottom-1 h-1 w-1 rounded-full"
                                            :class="
                                                cell.isStart || cell.isEnd
                                                    ? 'bg-white'
                                                    : cell.libur.isCutiBersama
                                                      ? 'bg-amber-500'
                                                      : 'bg-rose-500'
                                            "
                                        ></span>
                                    </button>
                                </div>
                            </template>
                        </div>
                    </div>

                    <!-- Legenda + Reset -->
                    <div
                        class="flex flex-wrap items-center gap-x-3 gap-y-1 mt-3 px-2.5 py-2 rounded-lg bg-slate-50"
                    >
                        <div class="flex items-center gap-1.5">
                            <span
                                class="w-2.5 h-2.5 rounded-full bg-emerald-600"
                            ></span>
                            <span class="text-[10px] text-slate-500">
                                Dipilih{{
                                    draftStart
                                        ? ` (${estimasiDraft} hari kerja)`
                                        : ""
                                }}
                            </span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span
                                class="w-2.5 h-2.5 rounded-full bg-rose-500"
                            ></span>
                            <span class="text-[10px] text-slate-500"
                                >Libur Nasional</span
                            >
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span
                                class="w-2.5 h-2.5 rounded-full bg-amber-500"
                            ></span>
                            <span class="text-[10px] text-slate-500"
                                >Cuti Bersama</span
                            >
                        </div>
                        <button
                            v-if="draftStart"
                            type="button"
                            @click="resetDraft"
                            class="ml-auto px-2 min-h-[28px] rounded-md text-[11px] font-bold text-rose-600 hover:bg-rose-50 transition"
                        >
                            Reset
                        </button>
                    </div>

                    <!-- Libur bulan ini -->
                    <div class="mt-3">
                        <div class="flex items-center justify-between mb-1.5">
                            <p
                                class="text-[10px] font-extrabold text-slate-400 uppercase tracking-wider"
                            >
                                Libur bulan ini
                            </p>
                            <p
                                class="text-[10px] font-semibold text-slate-400 capitalize"
                            >
                                {{ calendarLabel }}
                            </p>
                        </div>

                        <ul
                            v-if="hariLiburBulanIni.length > 0"
                            class="space-y-1.5 max-h-36 overflow-y-auto pr-0.5"
                        >
                            <li
                                v-for="libur in hariLiburBulanIni"
                                :key="libur.id"
                            >
                                <button
                                    type="button"
                                    @click="sorotLibur(libur)"
                                    class="w-full flex items-center gap-2.5 rounded-lg border border-slate-100 bg-slate-50/70 px-2 py-1.5 text-left hover:bg-slate-50 transition"
                                    :class="
                                        sorotTanggal === libur.tanggal
                                            ? 'ring-1 ring-rose-300'
                                            : ''
                                    "
                                >
                                    <span
                                        class="shrink-0 rounded-md px-1.5 py-1 text-[10px] font-extrabold leading-none whitespace-nowrap"
                                        :class="
                                            libur.isCutiBersama
                                                ? 'bg-amber-100 text-amber-700'
                                                : 'bg-rose-100 text-rose-600'
                                        "
                                    >
                                        {{ libur.hari }} {{ libur.bulan }}
                                    </span>
                                    <span
                                        class="min-w-0 flex-1 text-xs font-semibold text-slate-700 break-words leading-snug"
                                    >
                                        {{ libur.judul }}
                                    </span>
                                    <span
                                        class="shrink-0 flex flex-col items-end gap-0.5"
                                    >
                                        <span
                                            class="rounded-full px-2 py-0.5 text-[9px] font-extrabold uppercase tracking-wide whitespace-nowrap"
                                            :class="
                                                libur.isCutiBersama
                                                    ? 'bg-amber-100 text-amber-700'
                                                    : 'bg-rose-100 text-rose-600'
                                            "
                                        >
                                            {{ libur.jenisLabel }}
                                        </span>
                                        <span
                                            v-if="libur.isProyeksi"
                                            class="rounded-full bg-slate-200/80 px-1.5 py-px text-[9px] font-bold uppercase tracking-wide text-slate-500"
                                            title="Proyeksi, belum resmi SKB"
                                        >
                                            Proyeksi
                                        </span>
                                    </span>
                                </button>
                            </li>
                        </ul>
                        <p v-else class="text-xs text-slate-400 py-1">
                            Tidak ada hari libur di bulan ini.
                        </p>
                    </div>
                </div>

                <div
                    class="px-4 py-3 border-t border-slate-100 bg-white shrink-0 rounded-b-2xl"
                >
                    <!-- Keterangan kenapa tanggal terpilih tidak dihitung (libur) -->
                    <div
                        v-if="draftStart && liburDalamDraft.length > 0"
                        class="mb-2 rounded-lg bg-blue-50 border border-blue-100 px-3 py-2 text-[11px] text-blue-700 leading-relaxed break-words"
                    >
                        <span class="font-semibold"
                            >{{ liburDalamDraft.length }} tanggal tidak
                            dihitung</span
                        >
                        karena libur:
                        <span
                            v-for="(libur, idx) in liburDalamDraft"
                            :key="libur.tanggal"
                        >
                            <span class="font-semibold">{{ libur.judul }}</span>
                            ({{ formatTanggalPendek(libur.tanggal) }},
                            {{ libur.jenisLabel }})<span
                                v-if="idx < liburDalamDraft.length - 1"
                                >,
                            </span>
                        </span>
                    </div>
                    <p
                        v-if="draftStart && estimasiDraft === 0"
                        class="text-[11px] font-bold text-red-500 mb-2 text-center"
                    >
                        Rentang ini hanya berisi akhir pekan / hari libur, jadi
                        0 hari kerja.
                    </p>
                    <!-- Peringatan dini: saldo tahun tujuan belum dibuat Admin SDM -->
                    <div
                        v-if="tahunTanpaSaldoDraft !== null"
                        class="mb-2 rounded-lg bg-red-50 border border-red-200 px-3 py-2 text-[11px] text-red-700 leading-relaxed break-words"
                    >
                        <span class="font-semibold"
                            >Saldo cuti tahun {{ tahunTanpaSaldoDraft }} belum
                            tersedia.</span
                        >
                        Cuti Tahunan di tahun itu belum bisa diajukan sampai
                        Admin SDM membuat saldonya.
                    </div>
                    <button
                        type="button"
                        @click="konfirmasiTanggal"
                        :disabled="!draftStart"
                        class="w-full min-h-[46px] flex items-center justify-center gap-2 px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-sm font-bold shadow-md shadow-emerald-600/20 transition disabled:opacity-50 disabled:cursor-not-allowed"
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
                                stroke-width="2.5"
                                d="M5 13l4 4L19 7"
                            ></path>
                        </svg>
                        <span class="truncate">{{
                            labelTombolPilihTanggal
                        }}</span>
                    </button>
                </div>
            </div>
        </div>
    </Teleport>

    <!-- ================= PANEL KETENTUAN & ALUR PERSETUJUAN (HP) ================= -->
    <Teleport to="body">
        <div
            v-if="sheetKetentuanTerbuka"
            class="fixed inset-0 z-[9999] flex items-end justify-center bg-slate-900/50 backdrop-blur-sm p-0 sm:items-center sm:p-4"
            @click.self="sheetKetentuanTerbuka = false"
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-label="Ketentuan dan alur persetujuan"
                class="bg-white rounded-t-3xl sm:rounded-2xl w-full max-w-md shadow-2xl border border-slate-100 flex flex-col max-h-[92dvh] animate-in slide-in-from-bottom duration-200"
            >
                <div class="pt-2.5 pb-1 flex justify-center sm:hidden">
                    <span class="h-1 w-10 rounded-full bg-slate-200"></span>
                </div>

                <div
                    class="px-4 pt-1 sm:pt-4 pb-2 flex items-center justify-between gap-3 shrink-0"
                >
                    <div class="flex items-center gap-2 min-w-0">
                        <svg
                            class="w-5 h-5 text-emerald-600 shrink-0"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"
                            ></path>
                        </svg>
                        <h3 class="text-base font-bold text-slate-800 truncate">
                            Ketentuan &amp; Alur Persetujuan
                        </h3>
                    </div>
                    <button
                        type="button"
                        @click="sheetKetentuanTerbuka = false"
                        class="text-slate-400 hover:text-slate-600 hover:bg-slate-100 p-1.5 rounded-full transition shrink-0"
                        aria-label="Tutup"
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

                <div class="px-4 pb-3 overflow-y-auto flex-1 space-y-5">
                    <!-- Alur Persetujuan Anda -->
                    <section>
                        <div
                            class="flex items-center justify-between gap-2 mb-2"
                        >
                            <h4 class="text-xs font-extrabold text-slate-700">
                                Alur Persetujuan Anda
                            </h4>
                            <span
                                class="rounded-full bg-emerald-100 text-emerald-700 px-2 py-0.5 text-[10px] font-bold"
                            >
                                {{ kategoriPemohon.label }}
                            </span>
                        </div>

                        <p
                            v-if="alurSteps.length === 0"
                            class="text-xs text-slate-600 leading-relaxed bg-slate-50 rounded-lg px-3 py-2.5"
                        >
                            Jabatan Anda berada di level tertinggi Biro,
                            sehingga tidak ada jenjang persetujuan di dalam
                            biro.
                        </p>

                        <ol v-else class="relative">
                            <li class="flex gap-3 pb-3 relative">
                                <span
                                    class="absolute left-[13px] top-7 bottom-0 w-px bg-slate-200"
                                ></span>
                                <span
                                    class="relative z-10 h-7 w-7 shrink-0 rounded-full bg-emerald-600 text-white text-[11px] font-bold flex items-center justify-center"
                                    >1</span
                                >
                                <div class="min-w-0 pt-0.5">
                                    <p
                                        class="text-sm font-semibold text-slate-800"
                                    >
                                        Anda (Pemohon)
                                    </p>
                                    <p class="text-[11px] text-slate-400">
                                        Mengajukan cuti
                                    </p>
                                </div>
                            </li>
                            <li
                                v-for="(step, idx) in alurSteps"
                                :key="step.level"
                                class="flex gap-3 relative"
                                :class="
                                    idx < alurSteps.length - 1 ? 'pb-3' : ''
                                "
                            >
                                <span
                                    v-if="idx < alurSteps.length - 1"
                                    class="absolute left-[13px] top-7 bottom-0 w-px bg-slate-200"
                                ></span>
                                <span
                                    class="relative z-10 h-7 w-7 shrink-0 rounded-full bg-blue-100 text-blue-700 text-[11px] font-bold flex items-center justify-center"
                                >
                                    {{ step.no + 1 }}
                                </span>
                                <div class="min-w-0 pt-0.5">
                                    <p
                                        class="text-sm font-semibold text-slate-800"
                                    >
                                        {{ step.judul }}
                                    </p>
                                    <p class="text-[11px] text-slate-400">
                                        {{ step.sub }}
                                    </p>
                                </div>
                            </li>
                        </ol>
                    </section>

                    <!-- Ketentuan Pengajuan -->
                    <section>
                        <h4 class="text-xs font-extrabold text-slate-700 mb-2">
                            Ketentuan Pengajuan
                        </h4>
                        <ul class="divide-y divide-slate-100">
                            <li
                                v-for="(item, idx) in daftarKetentuan"
                                :key="idx"
                                class="flex items-start gap-2.5 py-2"
                            >
                                <svg
                                    class="w-4 h-4 text-emerald-600 mt-0.5 shrink-0"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2.5"
                                        d="M5 13l4 4L19 7"
                                    ></path>
                                </svg>
                                <p
                                    class="text-[13px] text-slate-600 leading-snug"
                                >
                                    {{ item }}
                                </p>
                            </li>
                        </ul>
                    </section>
                </div>

                <div
                    class="px-4 py-3 border-t border-slate-100 shrink-0 rounded-b-2xl"
                >
                    <button
                        type="button"
                        @click="sheetKetentuanTerbuka = false"
                        class="w-full min-h-[46px] flex items-center justify-center gap-2 px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-sm font-bold shadow-md shadow-emerald-600/20 transition"
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
                                stroke-width="2.5"
                                d="M5 13l4 4L19 7"
                            ></path>
                        </svg>
                        Mengerti
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>