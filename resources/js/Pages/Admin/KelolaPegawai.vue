<script setup>
import {
    computed,
    ref,
    watch,
    onErrorCaptured,
    onMounted,
    onBeforeUnmount,
    nextTick,
} from "vue";
import { useForm, Head, router } from "@inertiajs/vue3";
import MainLayout from "@/Layouts/MainLayout.vue";

// Definisikan props dengan nilai default agar tidak error jika data dari backend kosong
const props = defineProps({
    // Setiap item pegawai membawa field `bisa_dihapus` (boolean) dari
    // AdminController@kelolaPegawai. Dipakai untuk menentukan pop-up yang
    // muncul saat tombol Hapus diklik (konfirmasi hapus / info tidak bisa dihapus)
    // dan untuk menentukan checkbox pilih-banyak (hapus massal) aktif atau tidak.
    pegawai: {
        type: Array,
        default: () => [],
    },
    // Daftar unik Kelompok Substansi dari database (dikirim AdminController@kelolaPegawai)
    daftarKelompok: {
        type: Array,
        default: () => [],
    },
    // Daftar unik Tim Kerja dari seluruh kelompok (dikirim AdminController@kelolaPegawai)
    daftarTimKerja: {
        type: Array,
        default: () => [],
    },
    // Pemetaan { "Nama Kelompok": ["Tim A", "Tim B"] } untuk dropdown Tim Kerja
    timPerKelompok: {
        type: [Object, Array],
        default: () => ({}),
    },
    // Kelompok yang tidak punya tim kerja (Tim Kerja dikunci di form)
    daftarKelompokTanpaTim: {
        type: Array,
        default: () => [],
    },
    // Daftar jabatan fungsional STAF (dropdown Jabatan untuk role Karyawan/Staf)
    daftarJabatanFungsional: {
        type: Array,
        default: () => [],
    },
    // Versi berkelompok per rumpun: [{ label, options: [...] }] (opsional, dari controller).
    // Kalau kosong, dropdown jabatan memakai daftar datar di atas.
    daftarJabatanFungsionalGrup: {
        type: Array,
        default: () => [],
    },
    // Jabatan tetap per role_id: { 4: "Kepala Subbagian Tata Usaha", 6: "Kepala Biro Perencanaan" }
    jabatanTetap: {
        type: [Object, Array],
        default: () => ({}),
    },
    // Jabatan fungsional yang dipakai Ketua (dropdown "Ubah jabatan fungsional")
    daftarJabatanKetua: {
        type: Array,
        default: () => [],
    },
    // Versi berkelompok untuk jabatan Ketua (opsional, dari controller)
    daftarJabatanKetuaGrup: {
        type: Array,
        default: () => [],
    },
    // Jabatan fungsional bawaan tiap Ketua: { "Tim Kerja X": "Perencana Ahli Muda", ... }
    jabatanKetuaDefault: {
        type: [Object, Array],
        default: () => ({}),
    },
    // Kata sandi bawaan hasil reset (opsional, sebaiknya dikirim dari backend/config
    // supaya tidak ditulis keras di antarmuka). Kalau kosong, teksnya tidak ditampilkan.
    passwordDefault: {
        type: String,
        default: "",
    },
    // Pilihan jenis kepegawaian (PNS / PPPK), dikirim dari AdminController@kelolaPegawai
    // CPNS dihapus sesuai permintaan
    daftarStatusKepegawaian: {
        type: Array,
        default: () => ["PNS", "PPPK"],
    },
});

// ---> SISTEM PENDETEKSI ERROR OTOMATIS <---
const vueError = ref(null);
onErrorCaptured((err) => {
    vueError.value = err.toString();
    console.error("VUE ERROR TERTANGKAP:", err);
    return false; // Mencegah error merusak seluruh halaman
});

const muatUlangHalaman = () => window.location.reload();

// Catatan: formatRole tetap memuat role 5 (Admin SDM) agar pegawai yang
// sudah berstatus Admin SDM tetap tampil benar di tabel.
const formatRole = (roleId) => {
    const roles = {
        1: "Karyawan",
        2: "Atasan L1",
        3: "Atasan L2",
        4: "Atasan L3",
        5: "Admin SDM",
        6: "Atasan L4",
    };
    return roles[roleId] || "Tidak Diketahui";
};

// ===================== FITUR SEARCH + FILTER + PAGINATION =====================
const searchNama = ref("");
const selectedKelompokSubstansi = ref("");
// Filter status kepegawaian (bawaan: hanya yang Aktif)
const selectedStatus = ref("Aktif");
// Filter status kepegawaian (PNS / PPPK / Belum diisi). Bawaan: semua.

const selectedStatusKepegawaian = ref("");
// Harus sama dengan STATUS_PEGAWAI di AdminController
const daftarStatus = [
    "Aktif",
    "Pensiun",
    "Mutasi Keluar",
    "Resign / Berhenti",
    "Meninggal Dunia",
];

// ================= TANPA KELOMPOK =================
// Nilai kosong / strip dari data lama ditampilkan sebagai "Tanpa Kelompok"
const LABEL_TANPA_KELOMPOK = "Tanpa Kelompok";

const kosongKelompok = (nilai) => {
    const s = String(nilai ?? "").trim();
    return s === "" || s === "-" || s === "–" || s === "—" || s === ".";
};

/** Label tampilan kelompok (strip / kosong → Tanpa Kelompok) */
const labelKelompokTampil = (nilai) =>
    kosongKelompok(nilai) ? LABEL_TANPA_KELOMPOK : String(nilai).trim();

/** Nilai filter internal untuk "tanpa kelompok" (pakai "-" agar cocok data lama) */
const NILAI_TANPA_KELOMPOK = "-";
// ================= END TANPA KELOMPOK =================

/** Kunci perbandingan kelompok: abaikan huruf besar/kecil & spasi berlebih
 *  (mis. "SUBBAGIAN TATA USAHA" dianggap sama dengan "Subbagian Tata Usaha") */
const kunciKelompok = (nilai) =>
    String(nilai ?? "")
        .trim()
        .replace(/\s+/g, " ")
        .toLowerCase();

const currentPage = ref(1);
const itemsPerPage = 10;

const filteredPegawai = computed(() => {
    const keyword = searchNama.value.trim().toLowerCase();

    return props.pegawai.filter((item) => {
        const nama = (item?.nama || "").toLowerCase();
        const nip = String(item?.nip || "").toLowerCase();
        const kelompokSubstansi = item?.kelompok_substansi || "";
        const status = item?.status_aktif || "Aktif";

        // Filter kelompok: nilai kosong/strip cocok dengan pilihan "Tanpa Kelompok" ("-")
        const cocokKelompok = !selectedKelompokSubstansi.value
            ? true
            : kosongKelompok(selectedKelompokSubstansi.value)
              ? kosongKelompok(kelompokSubstansi)
              : kunciKelompok(kelompokSubstansi) ===
                kunciKelompok(selectedKelompokSubstansi.value);

        const statusKepegawaian = String(item?.status_kepegawaian ?? "").trim();
        const cocokStatusKepegawaian =
            !selectedStatusKepegawaian.value ||
            statusKepegawaian === selectedStatusKepegawaian.value;

        return (
            (!keyword || nama.includes(keyword) || nip.includes(keyword)) &&
            cocokKelompok &&
            (!selectedStatus.value || status === selectedStatus.value) &&
            cocokStatusKepegawaian
        );
    });
});

const daftarKelompokSubstansi = computed(() => {
    // Kumpulkan nilai unik; kosong/strip digabung jadi satu nilai internal "-".
    // Nama yang sama hanya beda huruf besar/kecil digabung jadi satu pilihan;
    // penulisan yang BUKAN kapital semua (mis. "Subbagian Tata Usaha")
    // diutamakan dibanding "SUBBAGIAN TATA USAHA".
    const peta = new Map();
    let adaTanpa = false;
    const kapitalSemua = (s) => s === s.toUpperCase() && s !== s.toLowerCase();
    for (const item of props.pegawai) {
        const raw = item?.kelompok_substansi;
        if (kosongKelompok(raw)) {
            adaTanpa = true;
            continue;
        }
        const bersih = String(raw).trim().replace(/\s+/g, " ");
        const kunci = kunciKelompok(bersih);
        const ada = peta.get(kunci);
        if (!ada || (kapitalSemua(ada) && !kapitalSemua(bersih))) {
            peta.set(kunci, bersih);
        }
    }
    const bernama = [...peta.values()].sort((a, b) => a.localeCompare(b));
    // "Tanpa Kelompok" ditaruh di awal daftar (setelah "Semua Kelompok" di dropdown)
    return adaTanpa ? [NILAI_TANPA_KELOMPOK, ...bernama] : bernama;
});

// ================================================================
// DROPDOWN FILTER KUSTOM (Kelompok Substansi & Status Kepegawaian)
// Disamakan dengan dropdown di halaman Persetujuan Cuti / Dashboard /
// Rekap Kuota Detail: tombol berikon, daftar SELALU terbuka ke bawah
// dengan lebar SAMA dengan tombolnya, tinggi dibatasi (bisa di-scroll),
// teks panjang membungkus (tidak terpotong) dan ada title untuk teks
// utuh. Hanya satu dropdown terbuka sekaligus; menutup saat klik di luar
// (elemen tanpa atribut data-dropdown) atau menekan Esc.
// Nilai tetap disimpan di ref lama (selectedKelompokSubstansi,
// selectedStatus) sehingga filteredPegawai, reset halaman & reset
// pilihan hapus massal TIDAK berubah.
// ================================================================
const dropdownFilterTerbuka = ref(null); // 'kelompok' | 'status' | null

const toggleDropdownFilter = (nama) => {
    dropdownFilterTerbuka.value =
        dropdownFilterTerbuka.value === nama ? null : nama;
};

const dropdownFilters = computed(() => {
    const daftar = [
        {
            key: "kelompok",
            id: "filter-kelompok-pegawai-admin",
            ariaLabel: "Filter kelompok substansi",
            widthClass: "lg:w-72",
            withIcon: true,
            selected: selectedKelompokSubstansi.value,
            options: [
                { value: "", label: "Semua Kelompok" },
                ...daftarKelompokSubstansi.value.map((k) => ({
                    value: k,
                    label: labelKelompokTampil(k),
                })),
            ],
        },
        {
            key: "status",
            id: "filter-status-pegawai-admin",
            ariaLabel: "Filter status pegawai",
            widthClass: "lg:w-52",
            withIcon: false,
            selected: selectedStatus.value,
            options: [
                { value: "", label: "Semua Status" },
                ...daftarStatus.map((s) => ({ value: s, label: s })),
            ],
        },
        {
            key: "statusKepegawaian",
            id: "filter-status-kepegawaian-admin",
            ariaLabel: "Filter status kepegawaian",
            widthClass: "lg:w-64",
            withIcon: true,
            selected: selectedStatusKepegawaian.value,
            options: [
                { value: "", label: "Semua Status Kepegawaian" },
                ...props.daftarStatusKepegawaian.map((s) => ({
                    value: s,
                    label: s,
                })),
            ],
        },
    ];

    return daftar.map((f) => {
        const cocok = f.options.find((o) => o.value === f.selected);
        return {
            ...f,
            selectedLabel: cocok
                ? cocok.label
                : f.selected || f.options[0].label,
        };
    });
});

const pilihFilter = (key, nilai) => {
    if (key === "kelompok") selectedKelompokSubstansi.value = nilai;
    else if (key === "status") selectedStatus.value = nilai;
    else if (key === "statusKepegawaian")
        selectedStatusKepegawaian.value = nilai;
    dropdownFilterTerbuka.value = null;
};

const tutupDropdownFilterSaatKlikLuar = (event) => {
    if (!event.target.closest?.("[data-dropdown]")) {
        dropdownFilterTerbuka.value = null;
    }
};
// ================= END DROPDOWN FILTER KUSTOM =================

const totalPages = computed(() =>
    Math.max(1, Math.ceil(filteredPegawai.value.length / itemsPerPage)),
);

const paginatedPegawai = computed(() => {
    const start = (currentPage.value - 1) * itemsPerPage;
    return filteredPegawai.value.slice(start, start + itemsPerPage);
});

watch(
    [
        searchNama,
        selectedKelompokSubstansi,
        selectedStatus,
        selectedStatusKepegawaian,
    ],
    () => {
        currentPage.value = 1;
    },
);

// Jaga halaman aktif tetap valid saat data berkurang (mis. setelah hapus
// pegawai terakhir di halaman terakhir).
watch(totalPages, (total) => {
    if (currentPage.value > total) currentPage.value = total;
});

// Daftar nomor halaman yang ditampilkan (dengan "..." jika halaman banyak)
// Contoh: 1 ... 4 5 6 ... 10
const halamanTampil = computed(() => {
    const total = totalPages.value;
    const cur = currentPage.value;

    if (total <= 7) {
        return Array.from({ length: total }, (_, i) => i + 1);
    }

    const pages = [1];
    const start = Math.max(2, cur - 1);
    const end = Math.min(total - 1, cur + 1);

    if (start > 2) pages.push("...");
    for (let i = start; i <= end; i++) pages.push(i);
    if (end < total - 1) pages.push("...");
    pages.push(total);

    return pages;
});

const goToPage = (page) => {
    currentPage.value = Math.min(Math.max(page, 1), totalPages.value);
};
// ===================== END SEARCH + FILTER + PAGINATION =====================

// State reaktivitas modal
const isModalOpen = ref(false);
const isEditMode = ref(false);
const currentEditId = ref(null);

// Role asli pegawai saat modal Edit dibuka (null saat Tambah).
// Dipakai agar opsi "Admin SDM" stabil dan tidak ikut hilang saat dropdown diganti.
const roleAwalEdit = ref(null);

const form = useForm({
    nip: "",
    nama: "",
    jabatan: "",
    kelompok_substansi: "",
    tim_kerja: "",
    role_id: 1,
    tanggal_masuk: "",
    no_telepon: "",
    alamat_domisili: "",
    status_aktif: "Aktif",
    status_kepegawaian: "",
});

// ===================== DROPDOWN KELOMPOK SUBSTANSI & TIM KERJA (FORM) =====================
// Nilai khusus pada dropdown untuk memunculkan input teks (menambah pilihan baru)
const OPSI_BARU = "__opsi_baru__";

// Jika true, field tampil sebagai input teks biasa (untuk mengetik nilai baru)
const kelompokModeBaru = ref(false);
const timKerjaModeBaru = ref(false);

// Bandingkan teks tanpa peduli huruf besar/kecil & spasi berlebih
const samaTeks = (a, b) =>
    String(a ?? "")
        .trim()
        .toLowerCase() ===
    String(b ?? "")
        .trim()
        .toLowerCase();

// Cari nilai di daftar opsi (abaikan huruf besar/kecil). Kalau ketemu, pakai
// penulisan dari daftar; kalau tidak, kembalikan nilai aslinya.
const samakanDenganOpsi = (nilai, daftar) =>
    (daftar || []).find((opsi) => samaTeks(opsi, nilai)) ?? (nilai || "");

// Opsi Kelompok Substansi = daftar dari controller (struktur resmi Biro +
// kelompok yang ada di database). Kalau props kosong, pakai data pegawai.
// Nilai yang sedang dipilih ditambahkan agar data lama tetap tampil saat edit.
const opsiKelompokForm = computed(() => {
    const hasil = props.daftarKelompok.length
        ? [...props.daftarKelompok]
        : [...daftarKelompokSubstansi.value];

    const nilai = form.kelompok_substansi;
    if (nilai && !hasil.some((opsi) => samaTeks(opsi, nilai))) {
        hasil.push(nilai);
    }

    return hasil;
});

// Opsi Tim Kerja = tim milik kelompok yang sedang dipilih.
// Sumber utama: timPerKelompok dari controller. Kalau props kosong, pakai
// data pegawai. Nilai yang sedang dipilih ditambahkan agar tidak hilang.
const opsiTimKerjaForm = computed(() => {
    const hasil = [];
    const tambah = (tim) => {
        if (tim && !hasil.some((opsi) => samaTeks(opsi, tim))) {
            hasil.push(tim);
        }
    };

    const dariServer = props.timPerKelompok?.[form.kelompok_substansi];
    if (Array.isArray(dariServer)) {
        dariServer.forEach(tambah);
    } else {
        props.pegawai.forEach((item) => {
            if (item?.kelompok_substansi === form.kelompok_substansi) {
                tambah(item?.tim_kerja);
            }
        });
    }

    tambah(form.tim_kerja);

    return hasil;
});

// True jika kelompok yang dipilih memang tidak punya tim kerja
// (mis. Subbagian Tata Usaha) -> kolom Tim Kerja dikunci.
const kelompokTanpaTim = computed(() => {
    if (kelompokModeBaru.value || !form.kelompok_substansi) return false;
    return props.daftarKelompokTanpaTim.some((k) =>
        samaTeks(k, form.kelompok_substansi),
    );
});

const onKelompokChange = (value) => {
    resetFungsionalKetua();
    dropdownFormTerbuka.value = null;

    if (value === OPSI_BARU) {
        kelompokModeBaru.value = true;
        form.kelompok_substansi = "";
    } else {
        form.kelompok_substansi = value;
    }

    // Ganti kelompok => pilihan tim kerja sebelumnya tidak berlaku lagi
    form.tim_kerja = "";
    timKerjaModeBaru.value = false;
};

const onTimKerjaChange = (value) => {
    resetFungsionalKetua();
    dropdownFormTerbuka.value = null;

    if (value === OPSI_BARU) {
        timKerjaModeBaru.value = true;
        form.tim_kerja = "";
    } else {
        form.tim_kerja = value;
    }
};

// Kembali dari input teks ke dropdown
const kembaliKeDropdownKelompok = () => {
    resetFungsionalKetua();
    kelompokModeBaru.value = false;
    form.kelompok_substansi = "";
    form.tim_kerja = "";
    timKerjaModeBaru.value = false;
};

const kembaliKeDropdownTimKerja = () => {
    resetFungsionalKetua();
    timKerjaModeBaru.value = false;
    form.tim_kerja = "";
};

const resetModeDropdown = () => {
    dropdownFormTerbuka.value = null;
    kelompokModeBaru.value = false;
    timKerjaModeBaru.value = false;
    resetJabatan();
};
// ===================== END DROPDOWN KELOMPOK & TIM KERJA =====================

// ===================== DROPDOWN JABATAN & ATURAN ROLE (FORM) =====================
// Bentuk isian jabatan mengikuti Hak Akses (role):
//   1 = staf (pilih dari daftar), 2 = Ketua Tim, 3 = Ketua Kelompok,
//   4 / 6 = jabatan tetap (Kasubag TU / Kepala Biro), lainnya = teks bebas.
const roleAktif = computed(() => Number(form.role_id));

const jabatanJenis = computed(() => {
    const r = roleAktif.value;
    if (r === 1) return "staf";
    if (r === 2) return "ketua_tim";
    if (r === 3) return "ketua_kelompok";
    if (props.jabatanTetap?.[r]) return "tetap";
    return "bebas";
});

const perluAwalan = computed(() =>
    ["ketua_tim", "ketua_kelompok"].includes(jabatanJenis.value),
);

// Pilihan dropdown (nama jabatan fungsional) atau OPSI_BARU ("Lainnya")
const jabatanPilihan = ref("");
// Teks ketikan sendiri (untuk "Lainnya" atau role Admin)
const jabatanTeks = ref("");
// True jika admin memilih mengubah jabatan fungsional Ketua dari bawaan Excel
const ubahFungsional = ref(false);

// Awalan otomatis untuk Ketua: "Ketua Tim Kerja X / " atau "Ketua Kelompok Y / "
const awalanJabatan = computed(() => {
    if (jabatanJenis.value === "ketua_tim") {
        const tim = String(form.tim_kerja || "").trim();
        return tim ? `Ketua ${tim} / ` : "";
    }
    if (jabatanJenis.value === "ketua_kelompok") {
        const kelompok = String(form.kelompok_substansi || "").trim();
        return kelompok ? `Ketua ${kelompok} / ` : "";
    }
    return "";
});

// Jabatan fungsional bawaan Ketua menurut Excel (dicari dari nama Tim / Kelompok)
const fungsionalBawaan = computed(() => {
    let kunci = "";
    if (jabatanJenis.value === "ketua_tim") kunci = form.tim_kerja;
    if (jabatanJenis.value === "ketua_kelompok")
        kunci = form.kelompok_substansi;
    if (!kunci) return "";

    const entri = Object.entries(props.jabatanKetuaDefault || {}).find(
        ([nama]) => samaTeks(nama, kunci),
    );
    return entri ? entri[1] : "";
});

// True = Ketua memakai jabatan fungsional bawaan (tanpa dropdown)
const pakaiBawaan = computed(
    () =>
        perluAwalan.value && !!fungsionalBawaan.value && !ubahFungsional.value,
);

// Opsi dropdown: Ketua hanya 4 jabatan fungsional, staf memakai daftar penuh
const opsiFungsional = computed(() =>
    perluAwalan.value
        ? props.daftarJabatanKetua
        : props.daftarJabatanFungsional,
);

const bagianJabatan = computed(() => {
    if (pakaiBawaan.value) return fungsionalBawaan.value;
    return jabatanPilihan.value === OPSI_BARU
        ? jabatanTeks.value.trim()
        : jabatanPilihan.value;
});

// Nilai akhir yang dikirim ke server
const jabatanGabungan = computed(() => {
    switch (jabatanJenis.value) {
        case "tetap":
            return props.jabatanTetap?.[roleAktif.value] || "";
        case "bebas":
            return jabatanTeks.value.trim();
        case "staf":
            return bagianJabatan.value;
        default: // ketua_tim / ketua_kelompok
            return awalanJabatan.value && bagianJabatan.value
                ? awalanJabatan.value + bagianJabatan.value
                : "";
    }
});

// Jaga form.jabatan selalu sama dengan hasil gabungan
watch(jabatanGabungan, (nilai) => {
    form.jabatan = nilai;
});

// Salah ketik yang pernah masuk dari Excel -> penulisan yang benar
const ALIAS_JABATAN = {
    "perencan ahli pertama": "Perencana Ahli Pertama",
};

const cocokkanFungsional = (teks, daftar) => {
    const bersih = String(teks ?? "").trim();
    const nilai = ALIAS_JABATAN[bersih.toLowerCase()] || bersih;
    return (daftar || []).find((opsi) => samaTeks(opsi, nilai)) ?? null;
};

const resetJabatan = () => {
    jabatanPilihan.value = "";
    jabatanTeks.value = "";
    ubahFungsional.value = false;
    dropdownJabatanBuka.value = false;
};

// Dipanggil saat Tim Kerja / Kelompok berganti: Ketua kembali ke jabatan
// fungsional bawaan tim/kelompok yang baru. Jabatan staf tidak ikut direset.
const resetFungsionalKetua = () => {
    if (!perluAwalan.value) return;
    resetJabatan();
};

const kembaliKeBawaan = () => resetJabatan();

// Isi state jabatan dari jabatan lama (dipanggil saat Edit, SETELAH role,
// kelompok & tim kerja terisi).
const muatJabatan = (jabatanLama) => {
    resetJabatan();

    const teks = String(jabatanLama || "").trim();
    const jenis = jabatanJenis.value;

    if (jenis === "tetap") return;
    if (jenis === "bebas") {
        jabatanTeks.value = teks;
        return;
    }

    let bagian = teks;

    if (jenis !== "staf") {
        const awalan = awalanJabatan.value;
        if (awalan && teks.toLowerCase().startsWith(awalan.toLowerCase())) {
            bagian = teks.slice(awalan.length);
        } else if (teks.includes(" / ")) {
            // Data lama yang awalannya beda penulisan: ambil bagian sesudah " / "
            bagian = teks.slice(teks.lastIndexOf(" / ") + 3);
        }
    }

    if (!bagian) return;

    const cocok = cocokkanFungsional(bagian, opsiFungsional.value);

    if (jenis !== "staf") {
        // Sama dengan bawaan Excel -> tidak perlu dianggap "diubah"
        if (cocok && samaTeks(cocok, fungsionalBawaan.value)) return;
        ubahFungsional.value = true;
    }

    if (cocok) {
        jabatanPilihan.value = cocok;
    } else {
        jabatanPilihan.value = OPSI_BARU;
        jabatanTeks.value = bagian;
    }
};

// ---> Aturan struktur menurut Hak Akses (sesuai Excel) <---
//   Kepala Biro (6)     : tanpa Kelompok & Tim Kerja
//   Kasubag TU (4)      : Kelompok otomatis "Subbagian Tata Usaha", tanpa Tim Kerja
//   Ketua Kelompok (3)  : tanpa Tim Kerja
const NAMA_KELOMPOK_TU = "Subbagian Tata Usaha";

const timDikunciRole = computed(() => [3, 4, 6].includes(roleAktif.value));
const timTerkunci = computed(
    () => kelompokTanpaTim.value || timDikunciRole.value,
);
const pesanTimTerkunci = computed(() =>
    timDikunciRole.value
        ? "Posisi ini tidak memiliki tim kerja"
        : "Kelompok ini tidak memiliki tim kerja",
);

const terapkanAturanRole = () => {
    const r = roleAktif.value;

    if (r === 6) {
        kelompokModeBaru.value = false;
        timKerjaModeBaru.value = false;
        form.kelompok_substansi = "";
        form.tim_kerja = "";
    } else if (r === 4) {
        kelompokModeBaru.value = false;
        timKerjaModeBaru.value = false;
        form.kelompok_substansi = NAMA_KELOMPOK_TU;
        form.tim_kerja = "";
    } else if (r === 3) {
        timKerjaModeBaru.value = false;
        form.tim_kerja = "";
    }
};

// Ganti role => isian jabatan sebelumnya tidak berlaku lagi
const onRoleChange = () => {
    resetJabatan();
    terapkanAturanRole();
};
// ===================== END DROPDOWN JABATAN =====================

// ===================== DROPDOWN JABATAN CUSTOM (SCROLL + MAX-HEIGHT) =====================
// Pengganti <select> bawaan browser untuk pilihan Jabatan. Daftarnya berupa
// kotak melayang (absolute) dengan tinggi tetap (max-h-60) + overflow-y-auto.
// Daftar SELALU terbuka ke bawah; kalau melewati tepi bawah modal, modal
// digulung otomatis (lihat watch di bagian dropdown form).
const dropdownJabatanBuka = ref(false);
const dropdownJabatanRef = ref(null); // pembungkus (untuk deteksi klik di luar)

// Tombol dikunci saat Ketua belum memilih Tim Kerja / Kelompok
const jabatanDisabled = computed(
    () => perluAwalan.value && !awalanJabatan.value,
);

// Teks yang tampil di tombol pemicu
const labelJabatanPilihan = computed(() =>
    jabatanPilihan.value === OPSI_BARU
        ? "Lainnya (ketik sendiri)..."
        : jabatanPilihan.value,
);

// Opsi untuk kotak daftar, selalu dalam bentuk grup [{ label, options }].
// Memakai versi berkelompok dari controller kalau ada; kalau tidak, seluruh
// opsi dimasukkan ke satu grup tanpa label (tampil sebagai daftar biasa).
const opsiGrupJabatan = computed(() => {
    const grup = perluAwalan.value
        ? props.daftarJabatanKetuaGrup
        : props.daftarJabatanFungsionalGrup;

    if (Array.isArray(grup) && grup.length) return grup;

    return [{ label: "", options: opsiFungsional.value }];
});

// Judul grup hanya ditampilkan kalau memang ada lebih dari satu grup berlabel
const tampilkanJudulGrup = computed(
    () =>
        opsiGrupJabatan.value.length > 1 &&
        opsiGrupJabatan.value.some((g) => g.label),
);

const toggleDropdownJabatan = () => {
    if (jabatanDisabled.value) return;
    dropdownFormTerbuka.value = null;
    dropdownJabatanBuka.value = !dropdownJabatanBuka.value;
};

const pilihJabatan = (nilai) => {
    jabatanPilihan.value = nilai;
    dropdownJabatanBuka.value = false;
};

// Tutup daftar kalau pengguna mengklik di luar komponen
const tutupJikaKlikDiLuar = (event) => {
    if (
        dropdownJabatanBuka.value &&
        dropdownJabatanRef.value &&
        !dropdownJabatanRef.value.contains(event.target)
    ) {
        dropdownJabatanBuka.value = false;
    }
};
// ===================== END DROPDOWN JABATAN CUSTOM =====================

// ===================== DROPDOWN KUSTOM DI DALAM FORM (TAMBAH / UBAH PEGAWAI) =====================
// Pengganti <select> bawaan browser untuk Hak Akses, Kelompok Substansi,
// Tim Kerja & Status Kepegawaian di modal Tambah Pegawai / Ubah Data Pegawai.
// Daftar berupa kotak melayang selebar tombol, SELALU terbuka ke bawah dengan
// tinggi tetap (max-h-60) + bisa di-scroll, teks panjang membungkus, hanya
// satu yang terbuka sekaligus, tertutup saat klik di luar / Esc. Kalau daftar
// melewati tepi bawah modal, modal digulung otomatis supaya seluruh daftar
// terlihat. Nilai tetap disimpan di form.* sehingga submit ke server TIDAK
// berubah.
const dropdownFormTerbuka = ref(null); // 'role' | 'kelompok' | 'tim' | 'status' | null

// Daftar melayang: selalu ke bawah, tinggi tetap
const KELAS_DAFTAR_FORM =
    "absolute left-0 right-0 top-full z-50 mt-1 max-h-60 overflow-y-auto rounded-md border border-gray-400 bg-white py-1 shadow-xl";

// Tombol pemicu: border lebih tegas (gray-400) agar kotak mudah terlihat
const kelasTombolForm = (field) => [
    "mt-1 flex min-h-[44px] w-full items-center justify-between gap-2 rounded-md border bg-white px-3 py-2 text-left text-base sm:text-sm text-gray-900 shadow-sm cursor-pointer focus:outline-none focus:ring-2 disabled:bg-gray-100 disabled:text-gray-600 disabled:border-gray-300 disabled:cursor-not-allowed",
    form.errors[field]
        ? "border-red-500 focus:border-red-500 focus:ring-red-500"
        : "border-gray-400 hover:border-gray-500 focus:border-indigo-600 focus:ring-indigo-500",
];

// Opsi dalam daftar: ringkas (40px), opsi terpilih lebih kontras
const kelasOpsiForm = (aktif) => [
    "flex min-h-[40px] w-full items-center justify-between gap-2 whitespace-normal break-words px-3 py-2 text-left text-sm leading-tight cursor-pointer",
    aktif
        ? "bg-indigo-100 font-semibold text-indigo-800"
        : "text-gray-800 hover:bg-indigo-50 hover:text-indigo-700",
];

// Warna teks tombol: nilai terisi = gelap, placeholder = abu-abu jelas terbaca
const kelasLabelForm = (terisi) => (terisi ? "text-gray-900" : "text-gray-600");

// Opsi dianggap terpilih hanya jika nilainya tidak kosong (placeholder tidak ikut disorot)
const opsiAktif = (sekarang, nilai) => nilai !== "" && sekarang === nilai;

const toggleDropdownForm = (nama) => {
    dropdownJabatanBuka.value = false;
    dropdownFormTerbuka.value =
        dropdownFormTerbuka.value === nama ? null : nama;
};

// Opsi Hak Akses. Admin SDM hanya muncul jika pegawai yang diedit SUDAH
// berperan Admin SDM sejak modal dibuka (roleAwalEdit).
const opsiRoleForm = computed(() => {
    const daftar = [
        { value: 1, label: "Karyawan / Staf" },
        { value: 2, label: "Atasan L1 (Ketua Tim Kerja)" },
        { value: 3, label: "Atasan L2 (Ketua Kelompok)" },
        { value: 4, label: "Atasan L3 (Kasubag TU)" },
    ];
    if (roleAwalEdit.value === 5) daftar.push({ value: 5, label: "Admin SDM" });
    daftar.push({ value: 6, label: "Atasan L4 (Kepala Biro)" });
    return daftar;
});

const labelRoleForm = computed(
    () =>
        opsiRoleForm.value.find((o) => o.value === Number(form.role_id))
            ?.label || "-- Pilih Hak Akses --",
);

const pilihRoleForm = (nilai) => {
    form.role_id = nilai;
    dropdownFormTerbuka.value = null;
    onRoleChange();
};

const opsiKelompokDropdown = computed(() => [
    { value: "", label: "-- Pilih Kelompok --" },
    ...opsiKelompokForm.value.map((k) => ({ value: k, label: k })),
    { value: OPSI_BARU, label: "+ Tambah kelompok baru..." },
]);

const labelKelompokForm = computed(
    () => form.kelompok_substansi || "-- Pilih Kelompok --",
);

const placeholderTimForm = computed(() =>
    !form.kelompok_substansi
        ? "Pilih kelompok dulu"
        : opsiTimKerjaForm.value.length
          ? "-- Pilih Tim Kerja --"
          : "-- Tidak ada tim kerja --",
);

const opsiTimDropdown = computed(() => [
    { value: "", label: placeholderTimForm.value },
    ...opsiTimKerjaForm.value.map((t) => ({ value: t, label: t })),
    { value: OPSI_BARU, label: "+ Tambah tim kerja baru..." },
]);

const labelTimForm = computed(() => form.tim_kerja || placeholderTimForm.value);

const opsiStatusForm = computed(() =>
    daftarStatus.map((s) => ({ value: s, label: s })),
);

const pilihStatusForm = (nilai) => {
    form.status_aktif = nilai;
    dropdownFormTerbuka.value = null;
};

// Status Kepegawaian (PNS / PPPK) — terpisah dari status_aktif
const opsiStatusKepegawaianForm = computed(() =>
    props.daftarStatusKepegawaian.map((s) => ({ value: s, label: s })),
);

const labelStatusKepegawaianForm = computed(
    () => form.status_kepegawaian || "-- Pilih Status Kepegawaian --",
);

const pilihStatusKepegawaianForm = (nilai) => {
    form.status_kepegawaian = nilai;
    dropdownFormTerbuka.value = null;
};

// Saat daftar (dropdown form ATAU jabatan) terbuka, gulung modal secukupnya
// supaya seluruh kotak daftar kelihatan, walau melewati tepi bawah modal.
const gulungKeDaftarTerbuka = () => {
    nextTick(() => {
        document.querySelector("[data-daftar-form]")?.scrollIntoView({
            block: "nearest",
            behavior: "smooth",
        });
    });
};

watch(dropdownFormTerbuka, (nama) => {
    if (nama) gulungKeDaftarTerbuka();
});

watch(dropdownJabatanBuka, (buka) => {
    if (buka) gulungKeDaftarTerbuka();
});

const tutupDropdownFormSaatKlikLuar = (event) => {
    if (!event.target.closest?.("[data-dropdown-form]")) {
        dropdownFormTerbuka.value = null;
    }
};
// ===================== END DROPDOWN KUSTOM DI DALAM FORM =====================

// ---> HELPER VALIDASI: class input berubah merah jika ada error dari Laravel <---
const inputClass = (field) => [
    "mt-1 block w-full rounded-md shadow-sm min-h-[44px] text-base sm:text-sm text-gray-900",
    form.errors[field]
        ? "border-red-500 focus:border-red-500 focus:ring-red-500"
        : "border-gray-400 hover:border-gray-500 focus:border-indigo-600 focus:ring-indigo-500",
];

// Jumlah field yang error (untuk banner ringkasan di atas form)
const jumlahError = computed(() => Object.keys(form.errors).length);

// Fungsi buka modal Tambah
const openAddModal = () => {
    isEditMode.value = false;
    currentEditId.value = null;
    roleAwalEdit.value = null;
    form.reset();
    form.clearErrors();
    resetModeDropdown();
    isModalOpen.value = true;
};

// Fungsi buka modal Edit
const openEditModal = (item) => {
    isEditMode.value = true;
    currentEditId.value = item.id;
    form.clearErrors();
    resetModeDropdown();
    form.nip = item.nip || "";
    form.nama = item.nama || "";
    form.jabatan = item.jabatan || "";
    // Samakan penulisan data lama (mis. "Tim Kerja Anggaran Ii") dengan opsi
    // dropdown ("... II") supaya pilihan yang benar langsung terpilih.
    form.kelompok_substansi = samakanDenganOpsi(
        item.kelompok_substansi,
        props.daftarKelompok,
    );
    form.tim_kerja = samakanDenganOpsi(
        item.tim_kerja,
        props.timPerKelompok?.[form.kelompok_substansi],
    );
    // Data lama di kelompok tanpa tim: kosongkan supaya konsisten dengan kunci
    if (kelompokTanpaTim.value) form.tim_kerja = "";
    form.role_id = item.role_id || 1;
    roleAwalEdit.value = Number(item.role_id) || null;
    form.tanggal_masuk = item.tanggal_masuk_form || item.tanggal_masuk || "";
    form.no_telepon = item.no_telepon || "";
    form.alamat_domisili = item.alamat_domisili || "";
    form.status_aktif = item.status_aktif || "Aktif";
    form.status_kepegawaian = item.status_kepegawaian || "";

    // Terapkan aturan role (mis. Kepala Biro: kosongkan kelompok "-" dari data lama)
    terapkanAturanRole();

    // Isi dropdown jabatan dari data lama (setelah role, kelompok & tim terisi)
    muatJabatan(item.jabatan);
    form.jabatan = jabatanGabungan.value;

    isModalOpen.value = true;
};

// Fungsi tutup modal form (bersihkan error agar tidak muncul lagi saat dibuka ulang)
const closeModal = () => {
    isModalOpen.value = false;
    form.clearErrors();
    resetModeDropdown();
};

// Fungsi simpan (Store / Update)
const submitForm = () => {
    form.jabatan = jabatanGabungan.value;

    // Dropdown kustom tidak punya validasi "required" bawaan browser, jadi
    // Kelompok Substansi (wajib kecuali Kepala Biro) dicek di sini.
    form.clearErrors();
    const galat = {};
    if (
        roleAktif.value !== 6 &&
        !String(form.kelompok_substansi || "").trim()
    ) {
        galat.kelompok_substansi = "Kelompok Substansi wajib dipilih.";
    }
    if (!String(form.status_kepegawaian || "").trim()) {
        galat.status_kepegawaian = "Status Kepegawaian wajib dipilih.";
    }
    if (Object.keys(galat).length) {
        form.setError(galat);
        return;
    }

    const opsi = {
        preserveScroll: true,
        onSuccess: () => {
            isModalOpen.value = false;
            form.reset();
            resetModeDropdown();
        },
        // Modal tetap terbuka & pesan error tampil di bawah tiap input
        onError: (errors) => {
            console.warn("Validasi gagal:", errors);
        },
    };

    if (isEditMode.value) {
        form.put(route("admin.pegawai.update", currentEditId.value), opsi);
    } else {
        form.post(route("admin.pegawai.store"), opsi);
    }
};

// ---> STATE UNTUK TOAST NOTIFIKASI SUKSES RESET PASSWORD <---
const showResetToast = ref(false);
const resetToastNama = ref("");
let resetToastTimer = null;

// ---> STATE UNTUK TOAST NOTIFIKASI ERROR (reset password / hapus gagal) <---
const showErrorToast = ref(false);
const errorToastMessage = ref("");
let errorToastTimer = null;

const tampilkanErrorToast = (errors) => {
    const pesan = Object.values(errors || {})[0];
    errorToastMessage.value = pesan || "Terjadi kesalahan. Silakan coba lagi.";
    showErrorToast.value = true;
    clearTimeout(errorToastTimer);
    errorToastTimer = setTimeout(() => {
        showErrorToast.value = false;
    }, 5000);
};

// ---> STATE UNTUK TOAST NOTIFIKASI SUKSES UMUM (hasil hapus massal) <---
const showSuksesToast = ref(false);
const suksesToastMessage = ref("");
let suksesToastTimer = null;

const tampilkanSuksesToast = (pesan) => {
    suksesToastMessage.value = pesan;
    showSuksesToast.value = true;
    clearTimeout(suksesToastTimer);
    suksesToastTimer = setTimeout(() => {
        showSuksesToast.value = false;
    }, 5000);
};

// ========================================== //
// ---> FITUR: RESET PASSWORD PEGAWAI <---
// ========================================== //
const showModalReset = ref(false);
const pegawaiYangAkanDireset = ref(null);
// Cegah klik ganda saat permintaan sedang berjalan
const sedangMereset = ref(false);

const konfirmasiReset = (item) => {
    pegawaiYangAkanDireset.value = item;
    showModalReset.value = true;
};

const batalReset = () => {
    if (sedangMereset.value) return;
    showModalReset.value = false;
    pegawaiYangAkanDireset.value = null;
};

const eksekusiReset = () => {
    if (!pegawaiYangAkanDireset.value || sedangMereset.value) return;

    const item = pegawaiYangAkanDireset.value;

    router.post(
        route("admin.pegawai.reset-password", item.id),
        {},
        {
            preserveScroll: true,
            onStart: () => {
                sedangMereset.value = true;
            },
            onFinish: () => {
                sedangMereset.value = false;
            },
            onSuccess: () => {
                showModalReset.value = false;
                pegawaiYangAkanDireset.value = null;

                resetToastNama.value = item.nama;
                showResetToast.value = true;
                clearTimeout(resetToastTimer);
                resetToastTimer = setTimeout(() => {
                    showResetToast.value = false;
                }, 3500);
            },
            onError: (errors) => {
                showModalReset.value = false;
                pegawaiYangAkanDireset.value = null;
                tampilkanErrorToast(errors);
            },
        },
    );
};

// ========================================== //
// ---> FITUR: HAPUS DATA PEGAWAI (SOFT DELETE) <---
// ========================================== //
// Fitur hapus HANYA untuk koreksi kesalahan administratif (data salah ketik /
// duplikat / batal penempatan).
//
// PERTAHANAN LAPIS 1 (antarmuka, POP-UP DINAMIS): tombol Hapus SELALU
//   terlihat & bisa diklik di semua perangkat (termasuk HP/tablet yang tidak
//   punya hover). Hasil kliknya dibedakan memakai item.bisa_dihapus yang
//   dikirim dari AdminController@kelolaPegawai:
//     - Skenario A (bisa_dihapus = true)  : modal konfirmasi "Ya, Hapus".
//     - Skenario B (bisa_dihapus = false atau tidak ada) : modal info
//       "TIDAK BISA DIHAPUS" yang mengarahkan Admin memakai EDIT -> Status
//       Kepegawaian (Pensiun / Resign). Tidak ada tombol "Ya, Hapus".
//       Jika field bisa_dihapus tidak dikirim, dianggap TIDAK boleh dihapus
//       (default paling aman).
// PERTAHANAN LAPIS 2 (backend): AdminController@destroyPegawai tetap
//   memvalidasi ulang; pesan penolakannya tampil lewat toast error di bawah.
const showModalHapus = ref(false);
const pegawaiYangAkanDihapus = ref(null);
const sedangMenghapus = ref(false);

// Skenario B: pop-up info untuk pegawai yang TIDAK memenuhi syarat hapus
const showModalTidakBisaHapus = ref(false);
const pegawaiTidakBisaDihapus = ref(null);

const konfirmasiHapus = (item) => {
    if (item?.bisa_dihapus) {
        // Skenario A: boleh dihapus -> tampilkan konfirmasi
        pegawaiYangAkanDihapus.value = item;
        showModalHapus.value = true;
        return;
    }

    // Skenario B: tidak boleh dihapus -> tampilkan penjelasan saja
    pegawaiTidakBisaDihapus.value = item;
    showModalTidakBisaHapus.value = true;
};

const tutupModalTidakBisaHapus = () => {
    showModalTidakBisaHapus.value = false;
    pegawaiTidakBisaDihapus.value = null;
};

const batalHapus = () => {
    if (sedangMenghapus.value) return;
    showModalHapus.value = false;
    pegawaiYangAkanDihapus.value = null;
};

const eksekusiHapus = () => {
    if (!pegawaiYangAkanDihapus.value || sedangMenghapus.value) return;

    router.delete(
        route("admin.pegawai.destroy", pegawaiYangAkanDihapus.value.id),
        {
            preserveScroll: true,
            onStart: () => {
                sedangMenghapus.value = true;
            },
            onFinish: () => {
                sedangMenghapus.value = false;
            },
            onSuccess: () => {
                showModalHapus.value = false;
                pegawaiYangAkanDihapus.value = null;
            },
            onError: (errors) => {
                showModalHapus.value = false;
                pegawaiYangAkanDihapus.value = null;
                tampilkanErrorToast(errors);
            },
        },
    );
};

// ========================================== //
// ---> FITUR: HAPUS MASSAL PEGAWAI (PILIH BANYAK) <---
// ========================================== //
// Hanya pegawai dengan bisa_dihapus = true yang bisa dipilih (aturan SAMA
// dengan hapus satuan: tanpa riwayat cuti DAN akun masih baru). Checkbox
// pegawai lain dinonaktifkan dengan tooltip penjelasan.
// PERTAHANAN LAPIS 2: AdminController@destroyPegawaiMassal memvalidasi ulang
// tiap ID; yang tidak memenuhi syarat dilewati (tidak menggagalkan semuanya).
const BATAS_HAPUS_MASSAL = 100; // samakan dengan 'max' di backend
const selectedIds = ref([]);
const showModalHapusMassal = ref(false);
const sedangMenghapusMassal = ref(false);

const bisaDipilih = (item) => item?.bisa_dihapus === true;

// Pilih-semua untuk halaman yang sedang tampil
const barisHalamanBisaDipilih = computed(() =>
    paginatedPegawai.value.filter(bisaDipilih),
);

const semuaHalamanTerpilih = computed(
    () =>
        barisHalamanBisaDipilih.value.length > 0 &&
        barisHalamanBisaDipilih.value.every((p) =>
            selectedIds.value.includes(p.id),
        ),
);

const sebagianHalamanTerpilih = computed(
    () =>
        !semuaHalamanTerpilih.value &&
        barisHalamanBisaDipilih.value.some((p) =>
            selectedIds.value.includes(p.id),
        ),
);

const toggleSemuaHalaman = () => {
    const idHalaman = barisHalamanBisaDipilih.value.map((p) => p.id);
    selectedIds.value = semuaHalamanTerpilih.value
        ? selectedIds.value.filter((id) => !idHalaman.includes(id))
        : [...new Set([...selectedIds.value, ...idHalaman])].slice(
              0,
              BATAS_HAPUS_MASSAL,
          );
};

// Pilih semua hasil filter (lintas halaman) yang boleh dihapus
const bisaDihapusHasilFilter = computed(() =>
    filteredPegawai.value.filter(bisaDipilih),
);

const pilihSemuaHasilFilter = () => {
    selectedIds.value = bisaDihapusHasilFilter.value
        .slice(0, BATAS_HAPUS_MASSAL)
        .map((p) => p.id);
};

const kosongkanPilihan = () => {
    selectedIds.value = [];
};

const pegawaiTerpilih = computed(() =>
    props.pegawai.filter((p) => selectedIds.value.includes(p.id)),
);

// Ganti filter/pencarian => pilihan direset supaya tidak ada pegawai yang
// terhapus tanpa terlihat oleh admin.
watch(
    [
        searchNama,
        selectedKelompokSubstansi,
        selectedStatus,
        selectedStatusKepegawaian,
    ],
    kosongkanPilihan,
);

// Setelah data dari server berubah, buang pilihan yang sudah tidak valid
watch(
    () => props.pegawai,
    (daftar) => {
        const idValid = new Set(
            (daftar || []).filter(bisaDipilih).map((p) => p.id),
        );
        selectedIds.value = selectedIds.value.filter((id) => idValid.has(id));
    },
);

const bukaModalHapusMassal = () => {
    if (!selectedIds.value.length) return;
    showModalHapusMassal.value = true;
};

const batalHapusMassal = () => {
    if (sedangMenghapusMassal.value) return;
    showModalHapusMassal.value = false;
};

const eksekusiHapusMassal = () => {
    if (!selectedIds.value.length || sedangMenghapusMassal.value) return;

    router.delete(route("admin.pegawai.destroy-massal"), {
        data: { ids: selectedIds.value },
        preserveScroll: true,
        onStart: () => {
            sedangMenghapusMassal.value = true;
        },
        onFinish: () => {
            sedangMenghapusMassal.value = false;
        },
        onSuccess: (page) => {
            showModalHapusMassal.value = false;
            selectedIds.value = [];
            const pesan = page.props.flash?.success;
            if (pesan) tampilkanSuksesToast(pesan);
        },
        onError: (errors) => {
            showModalHapusMassal.value = false;
            tampilkanErrorToast(errors);
        },
    });
};
// ================= END HAPUS MASSAL =================

// ========================================== //
// ---> FITUR: IMPOR DATA PEGAWAI <---
// ========================================== //
const isImportModalOpen = ref(false);

const importForm = useForm({
    file: null,
});

const importFileInput = ref(null);

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
    importForm.clearErrors();
};

// ---> POP-UP HASIL IMPOR <---
// Pesan ringkasan dikirim controller lewat flash (success / error). Keduanya
// dianggap "onSuccess" oleh Inertia karena controller memakai back()->with(...),
// jadi flash dibaca di onSuccess lalu ditampilkan sebagai pop-up.
const showModalHasilImpor = ref(false);
const hasilImpor = ref({ jenis: "sukses", pesan: "" });

const tampilkanHasilImpor = (jenis, pesan) => {
    hasilImpor.value = { jenis, pesan };
    showModalHasilImpor.value = true;
};

const tutupHasilImpor = () => {
    showModalHasilImpor.value = false;
};

// Angka ringkasan diambil dari pesan controller. Kalau format pesan berubah,
// hasilnya null dan pop-up hanya menampilkan teks pesannya.
const angkaRingkasanImpor = computed(() => {
    const pesan = hasilImpor.value.pesan || "";
    const ambil = (re) => {
        const hasil = pesan.match(re);
        return hasil ? Number(hasil[1]) : null;
    };

    const baru = ambil(/(\d+)\s+pegawai baru/i);
    const diperbarui = ambil(/(\d+)\s+pegawai diperbarui/i);
    const dilewati = ambil(/(\d+)\s+baris dilewati/i);

    return baru === null || diperbarui === null || dilewati === null
        ? null
        : { baru, diperbarui, dilewati };
});

const submitImportForm = () => {
    if (!importForm.file) return;

    importForm.post(route("admin.pegawai.import"), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: (page) => {
            const flash = page.props.flash ?? {};
            closeImportModal();

            if (flash.error) {
                tampilkanHasilImpor("gagal", flash.error);
            } else if (flash.success) {
                tampilkanHasilImpor("sukses", flash.success);
            }
        },
    });
};

// ========================================== //
// ---> TOMBOL ESC: tutup modal paling atas <---
// ========================================== //
const onKeydownGlobal = (event) => {
    if (event.key !== "Escape") return;

    if (showModalHasilImpor.value) tutupHasilImpor();
    else if (showModalHapusMassal.value) batalHapusMassal();
    else if (showModalHapus.value) batalHapus();
    else if (showModalTidakBisaHapus.value) tutupModalTidakBisaHapus();
    else if (showModalReset.value) batalReset();
    else if (isImportModalOpen.value) closeImportModal();
    else if (isModalOpen.value) {
        // Esc menutup dropdown yang terbuka dulu, baru modalnya
        if (dropdownFormTerbuka.value) dropdownFormTerbuka.value = null;
        else if (dropdownJabatanBuka.value) dropdownJabatanBuka.value = false;
        else closeModal();
    }
    // Dropdown filter (Kelompok / Status) di baris filter halaman
    else if (dropdownFilterTerbuka.value) dropdownFilterTerbuka.value = null;
};

onMounted(() => {
    document.addEventListener("mousedown", tutupJikaKlikDiLuar);
    document.addEventListener("click", tutupDropdownFilterSaatKlikLuar);
    document.addEventListener("click", tutupDropdownFormSaatKlikLuar);
    document.addEventListener("keydown", onKeydownGlobal);
});
onBeforeUnmount(() => {
    document.removeEventListener("mousedown", tutupJikaKlikDiLuar);
    document.removeEventListener("click", tutupDropdownFilterSaatKlikLuar);
    document.removeEventListener("click", tutupDropdownFormSaatKlikLuar);
    document.removeEventListener("keydown", onKeydownGlobal);
    clearTimeout(resetToastTimer);
    clearTimeout(errorToastTimer);
    clearTimeout(suksesToastTimer);
});
</script>

<template>
    <Head title="Kelola Pegawai" />

    <MainLayout>
        <div>
            <!-- KOTAK PENDETEKSI ERROR -->
            <div
                v-if="vueError"
                class="mb-6 rounded-lg border border-red-200 bg-red-50 p-6 text-red-800 shadow-sm"
                role="alert"
            >
                <h2 class="text-lg font-bold">Halaman ini gagal ditampilkan</h2>
                <p class="mt-1 text-sm">
                    Coba muat ulang halaman. Jika masalah berlanjut, hubungi
                    pengembang dan sertakan pesan di bawah ini.
                </p>
                <p
                    class="mt-3 break-words rounded bg-white/70 p-2 font-mono text-xs"
                >
                    {{ vueError }}
                </p>
                <button
                    type="button"
                    @click="muatUlangHalaman"
                    class="mt-4 min-h-[44px] rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 cursor-pointer"
                >
                    Muat ulang halaman
                </button>
            </div>

            <!-- KONTEN UTAMA -->
            <div
                v-else
                class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-4 sm:p-6 relative z-10"
            >
                <!-- ================= HEADER HALAMAN =================
                        Disamakan dengan halaman Rekap Kuota Detail: judul &
                        deskripsi di kiri, tombol aksi di pojok KANAN ATAS dalam
                        satu baris (mulai lebar lg). Di layar lebih kecil, tombol
                        turun ke bawah judul. Tombol yang terlalu banyak akan
                        membungkus rapi (flex-wrap) tanpa terpotong. -->
                <div
                    class="flex flex-col lg:flex-row lg:items-start lg:justify-between mb-6 border-b pb-4 gap-4"
                >
                    <div class="min-w-0 lg:flex-1">
                        <h2 class="text-2xl font-bold text-gray-800">
                            Master Data Pegawai
                        </h2>
                        <p class="text-sm text-gray-500 mt-1">
                            Kelola data induk seluruh pegawai dan hak aksesnya.
                            Untuk kuota &amp; saldo cuti, kelola melalui menu
                            <span class="font-medium text-gray-600"
                                >Rekap Kuota Detail</span
                            >.
                        </p>
                    </div>

                    <!-- Kontainer tombol aksi (kanan atas) -->
                    <div
                        class="flex flex-wrap items-center lg:justify-end gap-3 shrink-0"
                    >
                        <div
                            class="inline-flex h-11 min-w-[150px] items-center justify-center gap-3 rounded-lg border border-indigo-100 bg-indigo-50 px-4 text-indigo-900 shadow-sm"
                            aria-live="polite"
                        >
                            <span
                                class="flex h-8 min-w-[2rem] items-center justify-center rounded-full bg-indigo-600 px-2 text-sm font-bold text-white"
                            >
                                {{ filteredPegawai.length }}
                            </span>
                            <span
                                class="text-sm font-semibold whitespace-nowrap"
                            >
                                Total Pegawai
                            </span>
                        </div>

                        <!-- Tombol Impor Data: biru -->
                        <button
                            type="button"
                            @click="openImportModal"
                            class="inline-flex min-h-[44px] items-center gap-2 whitespace-nowrap px-5 py-2.5 bg-blue-600 border border-transparent rounded-full font-semibold text-sm text-white hover:bg-blue-700 transition ease-in-out duration-150 shadow-sm cursor-pointer"
                        >
                            <svg
                                class="w-4 h-4"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                                aria-hidden="true"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"
                                />
                            </svg>
                            Impor Data
                        </button>

                        <!-- Tombol Tambah Pegawai: ungu/violet -->
                        <button
                            type="button"
                            @click="openAddModal"
                            class="min-h-[44px] whitespace-nowrap px-5 py-2.5 bg-violet-600 text-white rounded-full text-sm font-extrabold hover:bg-violet-700 cursor-pointer shadow-lg hover:scale-105 transition-all"
                        >
                            + Tambah Pegawai
                        </button>
                    </div>
                </div>

                <!-- ================= BARIS FILTER & PENCARIAN =================
                        Dua dropdown kustom (Kelompok Substansi & Status): daftar
                        terbuka ke bawah, selebar tombol, tinggi dibatasi (bisa
                        di-scroll), teks panjang turun ke baris berikutnya, dan
                        Esc / klik di luar menutupnya. Keduanya dirender lewat
                        satu v-for dari `dropdownFilters`. -->
                <div
                    @keydown.esc="dropdownFilterTerbuka = null"
                    class="mb-5 flex flex-col gap-2 lg:flex-row lg:flex-wrap lg:items-center lg:gap-2.5"
                >
                    <!-- Search Input dengan Icon
                            Mobile: full width | Desktop: flex-1 dalam satu baris -->
                    <div
                        class="relative min-w-0 w-full lg:flex-1 lg:min-w-[12rem] lg:max-w-sm"
                    >
                        <div
                            class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"
                        >
                            <svg
                                class="h-4 w-4 text-gray-500"
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
                            id="cari-pegawai-admin"
                            name="cari_pegawai"
                            v-model="searchNama"
                            type="text"
                            placeholder="Cari nama atau NIP..."
                            aria-label="Cari nama atau NIP"
                            class="min-h-[44px] pl-10 pr-11 w-full rounded-lg border-gray-400 shadow-sm text-base sm:text-sm placeholder-gray-500 focus:border-indigo-600 focus:ring-indigo-500 transition-colors"
                        />
                        <button
                            v-if="searchNama"
                            type="button"
                            @click="searchNama = ''"
                            aria-label="Hapus pencarian"
                            class="absolute inset-y-0 right-0 min-h-[44px] min-w-[44px] px-3 flex items-center justify-center text-gray-500 hover:text-gray-700 cursor-pointer transition-colors"
                        >
                            <svg
                                class="w-4 h-4"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                                aria-hidden="true"
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

                    <!-- DROPDOWN FILTER KUSTOM: Kelompok + Status
                            Mobile: 2 kolom sampingan (hemat) | Desktop: ikut baris flex -->
                    <div
                        class="grid grid-cols-2 gap-2 w-full lg:w-auto lg:flex lg:gap-2.5 lg:shrink-0"
                    >
                        <div
                            v-for="f in dropdownFilters"
                            :key="f.key"
                            data-dropdown
                            class="relative w-full min-w-0 lg:shrink-0"
                            :class="f.widthClass"
                        >
                            <div
                                class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"
                            >
                                <svg
                                    class="w-4 h-4 text-gray-500"
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
                                :aria-expanded="dropdownFilterTerbuka === f.key"
                                :title="f.selectedLabel"
                                @click="toggleDropdownFilter(f.key)"
                                class="w-full min-h-[44px] text-left text-base sm:text-sm border border-gray-400 hover:border-gray-500 focus:border-indigo-600 focus:ring-2 focus:ring-indigo-500 focus:outline-none rounded-lg shadow-sm text-gray-900 py-2 pl-9 pr-8 bg-white cursor-pointer transition-colors"
                            >
                                <span class="block truncate">{{
                                    f.selectedLabel
                                }}</span>
                            </button>
                            <span
                                class="absolute inset-y-0 right-0 flex items-center pr-2 pointer-events-none"
                            >
                                <svg
                                    class="w-3.5 h-3.5 text-gray-600 transition-transform"
                                    :class="{
                                        'rotate-180':
                                            dropdownFilterTerbuka === f.key,
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
                                    ></path>
                                </svg>
                            </span>
                            <ul
                                v-if="dropdownFilterTerbuka === f.key"
                                role="listbox"
                                class="absolute left-0 right-0 top-full z-30 mt-1 max-h-60 overflow-y-auto rounded-lg border border-gray-400 bg-white py-1 shadow-xl"
                            >
                                <li v-for="opt in f.options" :key="opt.value">
                                    <button
                                        type="button"
                                        role="option"
                                        :title="opt.label"
                                        :aria-selected="
                                            f.selected === opt.value
                                        "
                                        @click="pilihFilter(f.key, opt.value)"
                                        :class="
                                            f.selected === opt.value
                                                ? 'bg-indigo-100 text-indigo-800 font-semibold'
                                                : 'text-gray-800 hover:bg-gray-100'
                                        "
                                        class="block w-full whitespace-normal break-words leading-tight px-3 py-2 text-left text-sm cursor-pointer"
                                    >
                                        {{ opt.label }}
                                    </button>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Penanda filter kelompok yang sedang aktif -->
                <div
                    v-if="selectedKelompokSubstansi"
                    class="flex flex-wrap items-center gap-2 mb-4 text-xs"
                >
                    <span
                        class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-indigo-50 text-indigo-700 border border-indigo-200 font-semibold shadow-sm"
                    >
                        {{ labelKelompokTampil(selectedKelompokSubstansi) }}
                        <button
                            type="button"
                            @click="selectedKelompokSubstansi = ''"
                            class="hover:text-indigo-900 cursor-pointer"
                            title="Hapus filter kelompok"
                            aria-label="Hapus filter kelompok"
                        >
                            <svg
                                class="w-3.5 h-3.5"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                                aria-hidden="true"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M6 18L18 6M6 6l12 12"
                                />
                            </svg>
                        </button>
                    </span>
                    <span class="text-gray-500"
                        >{{ filteredPegawai.length }} pegawai ditemukan</span
                    >
                </div>
                <!-- ================= END BARIS FILTER & PENCARIAN ================= -->

                <!-- ================= BAR AKSI HAPUS MASSAL ================= -->
                <div
                    v-if="selectedIds.length"
                    class="mb-4 flex flex-col gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm sm:flex-row sm:items-center sm:justify-between"
                    role="status"
                >
                    <div class="text-red-800">
                        <b>{{ selectedIds.length }}</b> pegawai dipilih
                        <button
                            v-if="
                                bisaDihapusHasilFilter.length >
                                selectedIds.length
                            "
                            type="button"
                            @click="pilihSemuaHasilFilter"
                            class="ml-2 underline hover:text-red-950 cursor-pointer"
                        >
                            Pilih semua
                            {{
                                Math.min(
                                    bisaDihapusHasilFilter.length,
                                    BATAS_HAPUS_MASSAL,
                                )
                            }}
                            yang bisa dihapus
                        </button>
                    </div>
                    <div class="flex gap-2">
                        <button
                            type="button"
                            @click="kosongkanPilihan"
                            class="min-h-[44px] rounded-md border border-gray-300 bg-white px-3 py-1.5 text-gray-700 hover:bg-gray-50 cursor-pointer"
                        >
                            Batal pilih
                        </button>
                        <button
                            type="button"
                            @click="bukaModalHapusMassal"
                            class="min-h-[44px] rounded-md bg-red-600 px-3 py-1.5 font-semibold text-white hover:bg-red-700 cursor-pointer"
                        >
                            Hapus terpilih
                        </button>
                    </div>
                </div>
                <!-- ================= END BAR AKSI HAPUS MASSAL ================= -->

                <!-- ================= TABEL (DESKTOP, lg ke atas) =================
                        6 kolom: Pegawai (Nama + NIP) | Kelompok & Tim Kerja |
                        Jabatan | Hak Akses & Status | Aksi.
                        PERBAIKAN: lebar minimum kolom dikecilkan & padding sel
                        dirapatkan; kolom Aksi dibuat "menyusut sesuai isi"
                        (w-px + whitespace-nowrap) sehingga tiga tombol (Ubah,
                        Atur ulang sandi, Hapus) SELALU tampil utuh tanpa
                        terpotong. Kolom teks lain yang menyerap sisa ruang dan
                        membungkus teks panjang (break-words + tooltip title).
                        Di bawah lg (tablet / HP) ditampilkan sebagai kartu. -->
                <div class="hidden overflow-x-auto lg:block">
                    <table
                        class="w-full table-auto divide-y divide-gray-200 text-sm"
                    >
                        <thead class="bg-gray-50">
                            <tr>
                                <!-- Checkbox pilih semua (halaman ini, hanya yang bisa dihapus) -->
                                <th scope="col" class="w-px px-2 py-3">
                                    <input
                                        type="checkbox"
                                        :checked="semuaHalamanTerpilih"
                                        :indeterminate="sebagianHalamanTerpilih"
                                        :disabled="
                                            barisHalamanBisaDipilih.length === 0
                                        "
                                        @change="toggleSemuaHalaman"
                                        aria-label="Pilih semua pegawai yang bisa dihapus di halaman ini"
                                        class="h-4 w-4 rounded border-gray-400 text-red-600 focus:ring-red-500 cursor-pointer disabled:cursor-not-allowed disabled:border-gray-300 disabled:bg-gray-200"
                                    />
                                </th>
                                <th
                                    scope="col"
                                    class="min-w-[150px] px-2 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600"
                                >
                                    Pegawai
                                </th>
                                <th
                                    scope="col"
                                    class="min-w-[130px] px-2 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600"
                                >
                                    Kelompok &amp; Tim Kerja
                                </th>
                                <th
                                    scope="col"
                                    class="min-w-[120px] px-2 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600"
                                >
                                    Jabatan
                                </th>
                                <th
                                    scope="col"
                                    class="min-w-[100px] px-2 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-600"
                                >
                                    Hak Akses &amp; Status
                                </th>
                                <th
                                    scope="col"
                                    class="w-px whitespace-nowrap px-2 py-3 text-center text-xs font-semibold uppercase tracking-wide text-gray-600"
                                >
                                    Aksi
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <tr v-if="!pegawai || pegawai.length === 0">
                                <td
                                    colspan="6"
                                    class="px-6 py-12 text-center text-gray-500 font-bold bg-gray-50/50"
                                >
                                    <span class="block text-xl mb-2">📭</span>
                                    Data pegawai kosong atau belum dimasukkan
                                </td>
                            </tr>
                            <tr v-else-if="filteredPegawai.length === 0">
                                <td
                                    colspan="6"
                                    class="px-6 py-12 text-center text-gray-500 font-bold bg-gray-50/50"
                                >
                                    Tidak ada pegawai yang sesuai dengan filter
                                    saat ini
                                </td>
                            </tr>
                            <template v-else>
                                <tr
                                    v-for="item in paginatedPegawai"
                                    :key="item.id"
                                    class="hover:bg-gray-50"
                                    :class="{
                                        'bg-red-50/40': selectedIds.includes(
                                            item.id,
                                        ),
                                    }"
                                >
                                    <!-- Checkbox pilih (nonaktif bila tidak boleh dihapus) -->
                                    <td
                                        class="px-2 py-3 align-top"
                                        :title="
                                            bisaDipilih(item)
                                                ? ''
                                                : 'Tidak dapat dihapus: sudah punya riwayat cuti atau akun lebih dari 7 hari. Ubah status menjadi Pensiun / Mutasi Keluar / Resign.'
                                        "
                                    >
                                        <input
                                            type="checkbox"
                                            :value="item.id"
                                            v-model="selectedIds"
                                            :disabled="!bisaDipilih(item)"
                                            :aria-label="`Pilih ${item?.nama || 'pegawai'}`"
                                            class="mt-0.5 h-4 w-4 rounded border-gray-400 text-red-600 focus:ring-red-500 cursor-pointer disabled:cursor-not-allowed disabled:border-gray-300 disabled:bg-gray-200"
                                        />
                                    </td>

                                    <!-- Pegawai: Nama + NIP -->
                                    <td class="px-2 py-3 align-top">
                                        <div
                                            class="break-words font-semibold text-gray-900 leading-snug"
                                            :title="item?.nama || '-'"
                                        >
                                            {{ item?.nama || "-" }}
                                        </div>
                                        <div
                                            class="mt-0.5 break-all text-xs tabular-nums text-gray-500"
                                        >
                                            {{ item?.nip || "-" }}
                                        </div>
                                    </td>

                                    <!-- Kelompok Substansi + Tim Kerja -->
                                    <td class="px-2 py-3 align-top">
                                        <div
                                            class="break-words leading-snug"
                                            :class="
                                                kosongKelompok(
                                                    item?.kelompok_substansi,
                                                )
                                                    ? 'italic text-gray-400'
                                                    : 'text-gray-800'
                                            "
                                            :title="
                                                labelKelompokTampil(
                                                    item?.kelompok_substansi,
                                                )
                                            "
                                        >
                                            {{
                                                labelKelompokTampil(
                                                    item?.kelompok_substansi,
                                                )
                                            }}
                                        </div>
                                        <div
                                            v-if="item?.tim_kerja"
                                            class="mt-1 break-words text-xs leading-snug text-gray-500"
                                            :title="item.tim_kerja"
                                        >
                                            {{ item.tim_kerja }}
                                        </div>
                                    </td>

                                    <!-- Jabatan -->
                                    <td class="px-2 py-3 align-top">
                                        <div
                                            class="break-words leading-snug text-gray-700"
                                            :title="item?.jabatan || '-'"
                                        >
                                            {{ item?.jabatan || "-" }}
                                        </div>
                                        <span
                                            v-if="item?.status_kepegawaian"
                                            class="mt-1 inline-flex items-center rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-semibold text-indigo-700"
                                        >
                                            {{ item.status_kepegawaian }}
                                        </span>
                                    </td>

                                    <!-- Hak Akses + Status -->
                                    <td class="px-2 py-3 align-top">
                                        <div
                                            class="whitespace-nowrap text-gray-700"
                                        >
                                            {{ formatRole(item?.role_id) }}
                                        </div>
                                        <span
                                            class="mt-1 inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                            :class="
                                                (item?.status_aktif ||
                                                    'Aktif') === 'Aktif'
                                                    ? 'bg-green-100 text-green-700'
                                                    : 'bg-gray-200 text-gray-600'
                                            "
                                        >
                                            {{ item?.status_aktif || "Aktif" }}
                                        </span>
                                    </td>

                                    <!-- Aksi: tidak boleh terpotong / turun baris -->
                                    <td
                                        class="w-px whitespace-nowrap px-2 py-3 align-top"
                                    >
                                        <div
                                            class="flex flex-nowrap items-center justify-center gap-1.5"
                                        >
                                            <!-- Edit -->
                                            <button
                                                type="button"
                                                @click="openEditModal(item)"
                                                title="Ubah data pegawai"
                                                :aria-label="`Ubah data ${item?.nama || 'pegawai'}`"
                                                class="shrink-0 p-2 bg-blue-50 text-blue-600 rounded-full hover:bg-blue-100 hover:text-blue-700 transition-colors shadow-sm cursor-pointer"
                                            >
                                                <svg
                                                    xmlns="http://www.w3.org/2000/svg"
                                                    fill="none"
                                                    viewBox="0 0 24 24"
                                                    stroke-width="1.5"
                                                    stroke="currentColor"
                                                    class="w-4 h-4"
                                                    aria-hidden="true"
                                                >
                                                    <path
                                                        stroke-linecap="round"
                                                        stroke-linejoin="round"
                                                        d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L6.832 19.82a4.5 4.5 0 01-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 011.13-1.897L16.863 4.487zm0 0L19.5 7.125"
                                                    />
                                                </svg>
                                            </button>

                                            <!-- Atur ulang kata sandi -->
                                            <button
                                                type="button"
                                                @click="konfirmasiReset(item)"
                                                title="Atur ulang kata sandi"
                                                :aria-label="`Atur ulang kata sandi ${item?.nama || 'pegawai'}`"
                                                class="shrink-0 p-2 bg-orange-50 text-orange-600 rounded-full hover:bg-orange-100 hover:text-orange-700 transition-colors shadow-sm cursor-pointer"
                                            >
                                                <svg
                                                    xmlns="http://www.w3.org/2000/svg"
                                                    fill="none"
                                                    viewBox="0 0 24 24"
                                                    stroke-width="1.5"
                                                    stroke="currentColor"
                                                    class="w-4 h-4"
                                                    aria-hidden="true"
                                                >
                                                    <path
                                                        stroke-linecap="round"
                                                        stroke-linejoin="round"
                                                        d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1.001.43-1.563A6 6 0 1121.75 8.25z"
                                                    />
                                                </svg>
                                            </button>

                                            <!-- Hapus: selalu bisa diklik, pop-up berbeda
                                                    tergantung item.bisa_dihapus (lihat konfirmasiHapus) -->
                                            <button
                                                type="button"
                                                @click="konfirmasiHapus(item)"
                                                title="Hapus pegawai"
                                                :aria-label="`Hapus ${item?.nama || 'pegawai'}`"
                                                class="shrink-0 p-2 bg-red-50 text-red-600 rounded-full hover:bg-red-100 hover:text-red-700 transition-colors shadow-sm cursor-pointer"
                                            >
                                                <svg
                                                    xmlns="http://www.w3.org/2000/svg"
                                                    fill="none"
                                                    viewBox="0 0 24 24"
                                                    stroke-width="1.5"
                                                    stroke="currentColor"
                                                    class="w-4 h-4"
                                                    aria-hidden="true"
                                                >
                                                    <path
                                                        stroke-linecap="round"
                                                        stroke-linejoin="round"
                                                        d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"
                                                    />
                                                </svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <!-- ================= KARTU (TABLET / MOBILE, di bawah lg) ================= -->
                <div class="space-y-3 lg:hidden">
                    <!-- Pilih semua (halaman ini) untuk tampilan HP -->
                    <label
                        v-if="barisHalamanBisaDipilih.length > 0"
                        class="flex min-h-[44px] items-center gap-3 rounded-lg border border-gray-200 bg-gray-50 px-4 text-sm text-gray-700 cursor-pointer"
                    >
                        <input
                            type="checkbox"
                            :checked="semuaHalamanTerpilih"
                            :indeterminate="sebagianHalamanTerpilih"
                            @change="toggleSemuaHalaman"
                            class="h-5 w-5 rounded border-gray-400 text-red-600 focus:ring-red-500"
                        />
                        Pilih semua yang bisa dihapus di halaman ini
                    </label>

                    <article
                        v-for="item in paginatedPegawai"
                        :key="item.id"
                        class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm"
                        :class="{
                            'border-red-300 bg-red-50/40': selectedIds.includes(
                                item.id,
                            ),
                        }"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex min-w-0 items-start gap-3">
                                <input
                                    type="checkbox"
                                    :value="item.id"
                                    v-model="selectedIds"
                                    :disabled="!bisaDipilih(item)"
                                    :aria-label="`Pilih ${item?.nama || 'pegawai'}`"
                                    :title="
                                        bisaDipilih(item)
                                            ? ''
                                            : 'Tidak dapat dihapus: sudah punya riwayat cuti atau akun lebih dari 7 hari.'
                                    "
                                    class="mt-1 h-5 w-5 flex-shrink-0 rounded border-gray-400 text-red-600 focus:ring-red-500 disabled:cursor-not-allowed disabled:border-gray-300 disabled:bg-gray-200"
                                />
                                <div class="min-w-0">
                                    <h3
                                        class="break-words font-semibold text-gray-900"
                                    >
                                        {{ item?.nama || "-" }}
                                    </h3>
                                    <p
                                        class="mt-1 break-words text-xs text-gray-500"
                                    >
                                        {{ item?.nip || "-" }}
                                    </p>
                                </div>
                            </div>
                            <span
                                class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold"
                                :class="
                                    (item?.status_aktif || 'Aktif') === 'Aktif'
                                        ? 'bg-green-100 text-green-700'
                                        : 'bg-gray-200 text-gray-600'
                                "
                            >
                                {{ item?.status_aktif || "Aktif" }}
                            </span>
                        </div>
                        <dl class="mt-3 space-y-2 text-sm">
                            <div class="flex justify-between gap-3">
                                <dt class="text-gray-500">
                                    Kelompok substansi
                                </dt>
                                <dd
                                    class="break-words text-right"
                                    :class="
                                        kosongKelompok(item?.kelompok_substansi)
                                            ? 'italic text-gray-400'
                                            : 'text-gray-800'
                                    "
                                >
                                    {{
                                        labelKelompokTampil(
                                            item?.kelompok_substansi,
                                        )
                                    }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt class="text-gray-500">Tim kerja</dt>
                                <dd
                                    class="break-words text-right text-gray-800"
                                >
                                    {{ item?.tim_kerja || "-" }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt class="text-gray-500">Jabatan</dt>
                                <dd
                                    class="break-words text-right text-gray-800"
                                >
                                    {{ item?.jabatan || "-" }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt class="text-gray-500">
                                    Status kepegawaian
                                </dt>
                                <dd
                                    class="break-words text-right text-gray-800"
                                >
                                    {{ item?.status_kepegawaian || "-" }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt class="text-gray-500">Hak akses</dt>
                                <dd
                                    class="break-words text-right text-gray-800"
                                >
                                    {{ formatRole(item?.role_id) }}
                                </dd>
                            </div>
                        </dl>
                        <div class="mt-4 grid grid-cols-3 gap-2">
                            <button
                                type="button"
                                @click="openEditModal(item)"
                                :aria-label="`Ubah data ${item?.nama || 'pegawai'}`"
                                class="min-h-[44px] rounded-lg bg-blue-50 px-2 py-2 text-xs font-semibold text-blue-700 cursor-pointer"
                            >
                                Ubah
                            </button>
                            <button
                                type="button"
                                @click="konfirmasiReset(item)"
                                :aria-label="`Atur ulang kata sandi ${item?.nama || 'pegawai'}`"
                                class="min-h-[44px] rounded-lg bg-orange-50 px-2 py-2 text-xs font-semibold text-orange-700 cursor-pointer"
                            >
                                Atur ulang sandi
                            </button>
                            <button
                                type="button"
                                @click="konfirmasiHapus(item)"
                                :aria-label="`Hapus ${item?.nama || 'pegawai'}`"
                                class="min-h-[44px] rounded-lg bg-red-50 px-2 py-2 text-xs font-semibold text-red-700 cursor-pointer"
                            >
                                Hapus
                            </button>
                        </div>
                    </article>
                    <p
                        v-if="!pegawai || pegawai.length === 0"
                        class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-8 text-center text-sm font-semibold text-gray-500"
                    >
                        Data pegawai kosong atau belum dimasukkan.
                    </p>
                    <p
                        v-else-if="filteredPegawai.length === 0"
                        class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-8 text-center text-sm font-semibold text-gray-500"
                    >
                        Tidak ada pegawai yang sesuai dengan filter saat ini.
                    </p>
                </div>

                <!-- PAGINATION -->
                <div
                    v-if="filteredPegawai.length > 0"
                    class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 pt-4"
                >
                    <span class="text-sm text-gray-500">
                        Halaman {{ currentPage }} dari {{ totalPages }} ({{
                            filteredPegawai.length
                        }}
                        data)
                    </span>
                    <nav
                        class="flex flex-wrap items-center gap-1"
                        aria-label="Navigasi halaman"
                    >
                        <button
                            type="button"
                            @click="goToPage(currentPage - 1)"
                            :disabled="currentPage === 1"
                            class="min-h-[44px] rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:text-gray-400 disabled:hover:bg-white cursor-pointer"
                        >
                            &laquo; Sebelumnya
                        </button>

                        <template
                            v-for="(halaman, idx) in halamanTampil"
                            :key="`halaman-${idx}`"
                        >
                            <span
                                v-if="halaman === '...'"
                                class="px-2 text-sm text-gray-400 select-none"
                            >
                                &hellip;
                            </span>
                            <button
                                v-else
                                type="button"
                                @click="goToPage(halaman)"
                                :aria-current="
                                    halaman === currentPage ? 'page' : undefined
                                "
                                :aria-label="`Halaman ${halaman}`"
                                class="min-h-[44px] min-w-[44px] rounded-md border px-3 py-1.5 text-sm font-medium cursor-pointer transition-colors"
                                :class="
                                    halaman === currentPage
                                        ? 'border-blue-600 bg-blue-600 text-white'
                                        : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50'
                                "
                            >
                                {{ halaman }}
                            </button>
                        </template>

                        <button
                            type="button"
                            @click="goToPage(currentPage + 1)"
                            :disabled="currentPage === totalPages"
                            class="min-h-[44px] rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:text-gray-400 disabled:hover:bg-white cursor-pointer"
                        >
                            Berikutnya &raquo;
                        </button>
                    </nav>
                </div>
            </div>

            <!-- ========================================== -->
            <!-- MODAL FORM EDIT / TAMBAH PEGAWAI           -->
            <!-- ========================================== -->
            <Teleport to="body">
                <div
                    v-if="isModalOpen"
                    class="fixed inset-0 flex items-end justify-center p-0 sm:items-center sm:p-4"
                    style="z-index: 999999"
                >
                    <div
                        class="absolute inset-0 bg-black/60 backdrop-blur-sm"
                        @click="closeModal"
                    ></div>

                    <div
                        class="relative bg-white p-4 sm:p-6 rounded-t-2xl sm:rounded-xl w-full max-w-2xl shadow-2xl max-h-[90dvh] overflow-y-auto z-10"
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="judul-modal-pegawai"
                    >
                        <div
                            class="flex justify-between items-center mb-5 border-b pb-3"
                        >
                            <h3
                                id="judul-modal-pegawai"
                                class="text-lg font-bold text-gray-800"
                            >
                                {{
                                    isEditMode
                                        ? "Edit Data Pegawai"
                                        : "Tambah Data Pegawai Baru"
                                }}
                            </h3>
                            <button
                                type="button"
                                @click="closeModal"
                                aria-label="Tutup"
                                class="min-h-[44px] min-w-[44px] text-gray-400 hover:text-red-500 font-bold text-2xl cursor-pointer outline-none"
                            >
                                &times;
                            </button>
                        </div>

                        <!-- BANNER RINGKASAN ERROR VALIDASI -->
                        <div
                            v-if="jumlahError > 0"
                            class="mb-4 flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 p-3"
                            role="alert"
                        >
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke-width="1.5"
                                stroke="currentColor"
                                class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5"
                                aria-hidden="true"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"
                                />
                            </svg>
                            <p class="text-sm text-red-700">
                                Data belum bisa disimpan. Periksa
                                <span class="font-semibold"
                                    >{{ jumlahError }} isian</span
                                >
                                yang ditandai merah di bawah ini.
                            </p>
                        </div>

                        <form @submit.prevent="submitForm">
                            <p
                                class="text-xs font-bold uppercase tracking-wide text-gray-500 mb-3"
                            >
                                Identitas &amp; Kontak
                            </p>

                            <div
                                class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-3"
                            >
                                <div>
                                    <label
                                        for="pegawai-nip"
                                        class="block text-sm font-medium text-gray-700"
                                    >
                                        NIP <span class="text-red-500">*</span>
                                    </label>
                                    <input
                                        id="pegawai-nip"
                                        name="nip"
                                        type="text"
                                        v-model="form.nip"
                                        required
                                        maxlength="18"
                                        inputmode="numeric"
                                        placeholder="18 digit angka"
                                        :class="inputClass('nip')"
                                    />
                                    <p
                                        v-if="form.errors.nip"
                                        class="mt-1 text-xs text-red-600"
                                    >
                                        {{ form.errors.nip }}
                                    </p>
                                </div>
                                <div>
                                    <label
                                        for="pegawai-nama"
                                        class="block text-sm font-medium text-gray-700"
                                    >
                                        Nama Lengkap &amp; Gelar
                                        <span class="text-red-500">*</span>
                                    </label>
                                    <input
                                        id="pegawai-nama"
                                        name="nama"
                                        type="text"
                                        v-model="form.nama"
                                        required
                                        :class="inputClass('nama')"
                                    />
                                    <p
                                        v-if="form.errors.nama"
                                        class="mt-1 text-xs text-red-600"
                                    >
                                        {{ form.errors.nama }}
                                    </p>
                                </div>
                            </div>

                            <div
                                class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-3"
                            >
                                <div>
                                    <label
                                        for="pegawai-telepon"
                                        class="block text-sm font-medium text-gray-700"
                                    >
                                        No Telepon
                                    </label>
                                    <input
                                        id="pegawai-telepon"
                                        name="no_telepon"
                                        type="tel"
                                        inputmode="tel"
                                        v-model="form.no_telepon"
                                        placeholder="08xxxxxxxxxx"
                                        :class="inputClass('no_telepon')"
                                    />
                                    <p
                                        v-if="form.errors.no_telepon"
                                        class="mt-1 text-xs text-red-600"
                                    >
                                        {{ form.errors.no_telepon }}
                                    </p>
                                </div>
                                <div>
                                    <label
                                        for="pegawai-role"
                                        class="block text-sm font-medium text-gray-700"
                                    >
                                        Hak Akses (Role)
                                        <span class="text-red-500">*</span>
                                    </label>
                                    <div class="relative" data-dropdown-form>
                                        <button
                                            id="pegawai-role"
                                            type="button"
                                            @click="toggleDropdownForm('role')"
                                            aria-haspopup="listbox"
                                            :aria-expanded="
                                                dropdownFormTerbuka === 'role'
                                            "
                                            :title="labelRoleForm"
                                            :class="kelasTombolForm('role_id')"
                                        >
                                            <span
                                                class="truncate"
                                                :class="
                                                    kelasLabelForm(form.role_id)
                                                "
                                                >{{ labelRoleForm }}</span
                                            >
                                            <svg
                                                class="h-4 w-4 flex-shrink-0 text-gray-600 transition-transform"
                                                :class="{
                                                    'rotate-180':
                                                        dropdownFormTerbuka ===
                                                        'role',
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
                                        </button>
                                        <ul
                                            v-if="
                                                dropdownFormTerbuka === 'role'
                                            "
                                            role="listbox"
                                            data-daftar-form
                                            :class="KELAS_DAFTAR_FORM"
                                        >
                                            <li
                                                v-for="opt in opsiRoleForm"
                                                :key="opt.value"
                                            >
                                                <button
                                                    type="button"
                                                    role="option"
                                                    :title="opt.label"
                                                    :aria-selected="
                                                        Number(form.role_id) ===
                                                        opt.value
                                                    "
                                                    @click="
                                                        pilihRoleForm(opt.value)
                                                    "
                                                    :class="
                                                        kelasOpsiForm(
                                                            opsiAktif(
                                                                Number(
                                                                    form.role_id,
                                                                ),
                                                                opt.value,
                                                            ),
                                                        )
                                                    "
                                                >
                                                    <span
                                                        class="min-w-0 break-words"
                                                        >{{ opt.label }}</span
                                                    >
                                                    <svg
                                                        v-if="
                                                            opsiAktif(
                                                                Number(
                                                                    form.role_id,
                                                                ),
                                                                opt.value,
                                                            )
                                                        "
                                                        class="h-4 w-4 flex-shrink-0 text-indigo-700"
                                                        viewBox="0 0 20 20"
                                                        fill="currentColor"
                                                        aria-hidden="true"
                                                    >
                                                        <path
                                                            fill-rule="evenodd"
                                                            d="M16.704 5.29a1 1 0 010 1.415l-7.5 7.5a1 1 0 01-1.415 0l-3.5-3.5a1 1 0 111.415-1.415L8.5 12.086l6.793-6.793a1 1 0 011.415 0z"
                                                            clip-rule="evenodd"
                                                        />
                                                    </svg>
                                                </button>
                                            </li>
                                        </ul>
                                    </div>
                                    <p
                                        v-if="form.errors.role_id"
                                        class="mt-1 text-xs text-red-600"
                                    >
                                        {{ form.errors.role_id }}
                                    </p>
                                </div>
                            </div>

                            <div class="mb-5">
                                <label
                                    for="pegawai-alamat"
                                    class="block text-sm font-medium text-gray-700"
                                >
                                    Alamat
                                </label>
                                <textarea
                                    id="pegawai-alamat"
                                    name="alamat_domisili"
                                    v-model="form.alamat_domisili"
                                    rows="2"
                                    :class="inputClass('alamat_domisili')"
                                ></textarea>
                                <p
                                    v-if="form.errors.alamat_domisili"
                                    class="mt-1 text-xs text-red-600"
                                >
                                    {{ form.errors.alamat_domisili }}
                                </p>
                            </div>

                            <div class="border-t border-gray-200 pt-4 mb-2">
                                <p
                                    class="text-xs font-bold uppercase tracking-wide text-gray-500 mb-3"
                                >
                                    Posisi &amp; Jabatan
                                </p>

                                <div
                                    class="grid grid-cols-1 md:grid-cols-2 gap-4"
                                >
                                    <!-- KELOMPOK SUBSTANSI: dropdown (atau input teks jika menambah baru) -->
                                    <div>
                                        <label
                                            for="kelompok-substansi-pegawai"
                                            class="block text-sm font-medium text-gray-700"
                                        >
                                            Kelompok Substansi
                                            <span
                                                v-if="roleAktif !== 6"
                                                class="text-red-500"
                                                >*</span
                                            >
                                        </label>

                                        <!-- Kepala Biro: tidak punya kelompok -->
                                        <input
                                            v-if="roleAktif === 6"
                                            id="kelompok-substansi-pegawai"
                                            name="kelompok_substansi"
                                            type="text"
                                            disabled
                                            value="Kepala Biro tidak memiliki kelompok"
                                            class="mt-1 block w-full min-h-[44px] rounded-md border-gray-300 bg-gray-100 text-base sm:text-sm text-gray-600 shadow-sm cursor-not-allowed"
                                        />

                                        <div
                                            v-else-if="!kelompokModeBaru"
                                            class="relative"
                                            data-dropdown-form
                                        >
                                            <button
                                                id="kelompok-substansi-pegawai"
                                                type="button"
                                                @click="
                                                    toggleDropdownForm(
                                                        'kelompok',
                                                    )
                                                "
                                                :disabled="roleAktif === 4"
                                                aria-haspopup="listbox"
                                                :aria-expanded="
                                                    dropdownFormTerbuka ===
                                                    'kelompok'
                                                "
                                                :title="labelKelompokForm"
                                                :class="
                                                    kelasTombolForm(
                                                        'kelompok_substansi',
                                                    )
                                                "
                                            >
                                                <span
                                                    class="truncate"
                                                    :class="
                                                        kelasLabelForm(
                                                            form.kelompok_substansi,
                                                        )
                                                    "
                                                    >{{
                                                        labelKelompokForm
                                                    }}</span
                                                >
                                                <svg
                                                    class="h-4 w-4 flex-shrink-0 text-gray-600 transition-transform"
                                                    :class="{
                                                        'rotate-180':
                                                            dropdownFormTerbuka ===
                                                            'kelompok',
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
                                            </button>
                                            <ul
                                                v-if="
                                                    dropdownFormTerbuka ===
                                                    'kelompok'
                                                "
                                                role="listbox"
                                                data-daftar-form
                                                :class="KELAS_DAFTAR_FORM"
                                            >
                                                <li
                                                    v-for="opt in opsiKelompokDropdown"
                                                    :key="opt.value"
                                                >
                                                    <button
                                                        type="button"
                                                        role="option"
                                                        :title="opt.label"
                                                        :aria-selected="
                                                            form.kelompok_substansi ===
                                                            opt.value
                                                        "
                                                        @click="
                                                            onKelompokChange(
                                                                opt.value,
                                                            )
                                                        "
                                                        :class="
                                                            kelasOpsiForm(
                                                                opsiAktif(
                                                                    form.kelompok_substansi,
                                                                    opt.value,
                                                                ),
                                                            )
                                                        "
                                                    >
                                                        <span
                                                            class="min-w-0 break-words"
                                                            :class="
                                                                opt.value === ''
                                                                    ? 'text-gray-500'
                                                                    : ''
                                                            "
                                                            >{{
                                                                opt.label
                                                            }}</span
                                                        >
                                                        <svg
                                                            v-if="
                                                                opsiAktif(
                                                                    form.kelompok_substansi,
                                                                    opt.value,
                                                                )
                                                            "
                                                            class="h-4 w-4 flex-shrink-0 text-indigo-700"
                                                            viewBox="0 0 20 20"
                                                            fill="currentColor"
                                                            aria-hidden="true"
                                                        >
                                                            <path
                                                                fill-rule="evenodd"
                                                                d="M16.704 5.29a1 1 0 010 1.415l-7.5 7.5a1 1 0 01-1.415 0l-3.5-3.5a1 1 0 111.415-1.415L8.5 12.086l6.793-6.793a1 1 0 011.415 0z"
                                                                clip-rule="evenodd"
                                                            />
                                                        </svg>
                                                    </button>
                                                </li>
                                            </ul>
                                        </div>

                                        <div v-else>
                                            <input
                                                id="kelompok-substansi-pegawai"
                                                name="kelompok_substansi"
                                                type="text"
                                                v-model="
                                                    form.kelompok_substansi
                                                "
                                                required
                                                placeholder="Ketik nama kelompok baru"
                                                :class="
                                                    inputClass(
                                                        'kelompok_substansi',
                                                    )
                                                "
                                            />
                                            <button
                                                type="button"
                                                @click="
                                                    kembaliKeDropdownKelompok
                                                "
                                                class="mt-1 min-h-[32px] text-xs text-indigo-600 hover:text-indigo-800 cursor-pointer"
                                            >
                                                &laquo; Kembali ke daftar
                                                pilihan
                                            </button>
                                        </div>

                                        <p
                                            v-if="
                                                form.errors.kelompok_substansi
                                            "
                                            class="mt-1 text-xs text-red-600"
                                        >
                                            {{ form.errors.kelompok_substansi }}
                                        </p>
                                    </div>

                                    <!-- TIM KERJA: dropdown bergantung pada kelompok yang dipilih -->
                                    <div>
                                        <label
                                            for="tim-kerja-pegawai"
                                            class="block text-sm font-medium text-gray-700"
                                        >
                                            Tim Kerja
                                        </label>

                                        <!-- Terkunci: kelompok ini tidak punya tim kerja / posisi ini tanpa tim -->
                                        <input
                                            v-if="timTerkunci"
                                            id="tim-kerja-pegawai"
                                            name="tim_kerja"
                                            type="text"
                                            disabled
                                            :value="pesanTimTerkunci"
                                            class="mt-1 block w-full min-h-[44px] rounded-md border-gray-300 bg-gray-100 text-base sm:text-sm text-gray-600 shadow-sm cursor-not-allowed"
                                        />

                                        <div
                                            v-else-if="!timKerjaModeBaru"
                                            class="relative"
                                            data-dropdown-form
                                        >
                                            <button
                                                id="tim-kerja-pegawai"
                                                type="button"
                                                @click="
                                                    toggleDropdownForm('tim')
                                                "
                                                :disabled="
                                                    !form.kelompok_substansi
                                                "
                                                aria-haspopup="listbox"
                                                :aria-expanded="
                                                    dropdownFormTerbuka ===
                                                    'tim'
                                                "
                                                :title="labelTimForm"
                                                :class="
                                                    kelasTombolForm('tim_kerja')
                                                "
                                            >
                                                <span
                                                    class="truncate"
                                                    :class="
                                                        kelasLabelForm(
                                                            form.tim_kerja,
                                                        )
                                                    "
                                                    >{{ labelTimForm }}</span
                                                >
                                                <svg
                                                    class="h-4 w-4 flex-shrink-0 text-gray-600 transition-transform"
                                                    :class="{
                                                        'rotate-180':
                                                            dropdownFormTerbuka ===
                                                            'tim',
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
                                            </button>
                                            <ul
                                                v-if="
                                                    dropdownFormTerbuka ===
                                                    'tim'
                                                "
                                                role="listbox"
                                                data-daftar-form
                                                :class="KELAS_DAFTAR_FORM"
                                            >
                                                <li
                                                    v-for="opt in opsiTimDropdown"
                                                    :key="opt.value"
                                                >
                                                    <button
                                                        type="button"
                                                        role="option"
                                                        :title="opt.label"
                                                        :aria-selected="
                                                            form.tim_kerja ===
                                                            opt.value
                                                        "
                                                        @click="
                                                            onTimKerjaChange(
                                                                opt.value,
                                                            )
                                                        "
                                                        :class="
                                                            kelasOpsiForm(
                                                                opsiAktif(
                                                                    form.tim_kerja,
                                                                    opt.value,
                                                                ),
                                                            )
                                                        "
                                                    >
                                                        <span
                                                            class="min-w-0 break-words"
                                                            :class="
                                                                opt.value === ''
                                                                    ? 'text-gray-500'
                                                                    : ''
                                                            "
                                                            >{{
                                                                opt.label
                                                            }}</span
                                                        >
                                                        <svg
                                                            v-if="
                                                                opsiAktif(
                                                                    form.tim_kerja,
                                                                    opt.value,
                                                                )
                                                            "
                                                            class="h-4 w-4 flex-shrink-0 text-indigo-700"
                                                            viewBox="0 0 20 20"
                                                            fill="currentColor"
                                                            aria-hidden="true"
                                                        >
                                                            <path
                                                                fill-rule="evenodd"
                                                                d="M16.704 5.29a1 1 0 010 1.415l-7.5 7.5a1 1 0 01-1.415 0l-3.5-3.5a1 1 0 111.415-1.415L8.5 12.086l6.793-6.793a1 1 0 011.415 0z"
                                                                clip-rule="evenodd"
                                                            />
                                                        </svg>
                                                    </button>
                                                </li>
                                            </ul>
                                        </div>

                                        <div v-else>
                                            <input
                                                id="tim-kerja-pegawai"
                                                name="tim_kerja"
                                                type="text"
                                                v-model="form.tim_kerja"
                                                placeholder="Ketik nama tim kerja baru"
                                                :class="inputClass('tim_kerja')"
                                            />
                                            <button
                                                type="button"
                                                @click="
                                                    kembaliKeDropdownTimKerja
                                                "
                                                class="mt-1 min-h-[32px] text-xs text-indigo-600 hover:text-indigo-800 cursor-pointer"
                                            >
                                                &laquo; Kembali ke daftar
                                                pilihan
                                            </button>
                                        </div>

                                        <p
                                            v-if="form.errors.tim_kerja"
                                            class="mt-1 text-xs text-red-600"
                                        >
                                            {{ form.errors.tim_kerja }}
                                        </p>
                                    </div>
                                </div>

                                <!-- JABATAN: bentuk isian mengikuti Hak Akses -->
                                <div class="mt-4">
                                    <label
                                        for="jabatan-pegawai"
                                        class="block text-sm font-medium text-gray-700"
                                    >
                                        Jabatan
                                        <span class="text-red-500">*</span>
                                    </label>

                                    <!-- Kasubag TU / Kepala Biro: terisi otomatis -->
                                    <input
                                        v-if="jabatanJenis === 'tetap'"
                                        id="jabatan-pegawai"
                                        name="jabatan"
                                        type="text"
                                        disabled
                                        :value="jabatanGabungan"
                                        class="mt-1 block w-full min-h-[44px] rounded-md border-gray-300 bg-gray-100 text-base sm:text-sm text-gray-700 shadow-sm cursor-not-allowed"
                                    />

                                    <!-- Admin: teks bebas -->
                                    <input
                                        v-else-if="jabatanJenis === 'bebas'"
                                        id="jabatan-pegawai"
                                        name="jabatan"
                                        type="text"
                                        v-model="jabatanTeks"
                                        required
                                        :class="inputClass('jabatan')"
                                    />

                                    <!-- Staf / Ketua Tim / Ketua Kelompok -->
                                    <template v-else>
                                        <p
                                            v-if="perluAwalan && !awalanJabatan"
                                            class="mt-1 text-xs text-amber-700"
                                        >
                                            {{
                                                jabatanJenis === "ketua_tim"
                                                    ? "Pilih Tim Kerja terlebih dahulu."
                                                    : "Pilih Kelompok Substansi terlebih dahulu."
                                            }}
                                        </p>

                                        <!-- Ketua: jabatan lengkap terisi otomatis sesuai Excel -->
                                        <div v-if="pakaiBawaan">
                                            <p
                                                class="mt-1 rounded-md border border-gray-300 bg-gray-100 px-3 py-2 text-sm text-gray-800 break-words"
                                            >
                                                {{ jabatanGabungan }}
                                            </p>
                                            <button
                                                type="button"
                                                @click="ubahFungsional = true"
                                                class="mt-1 min-h-[32px] text-xs text-indigo-600 hover:text-indigo-800 cursor-pointer"
                                            >
                                                Ubah jabatan fungsional
                                            </button>
                                        </div>

                                        <!-- Staf, atau Ketua yang jabatan fungsionalnya diubah / tidak punya bawaan -->
                                        <template v-else>
                                            <p
                                                v-if="
                                                    perluAwalan && awalanJabatan
                                                "
                                                class="mt-1 rounded-md border border-gray-300 bg-gray-100 px-3 py-2 text-sm text-gray-700 break-words"
                                            >
                                                {{ awalanJabatan }}
                                            </p>

                                            <!-- DROPDOWN JABATAN CUSTOM: kotak melayang, selalu ke bawah, tinggi tetap + scroll -->
                                            <div
                                                ref="dropdownJabatanRef"
                                                class="relative"
                                                @keydown.esc.stop="
                                                    dropdownJabatanBuka = false
                                                "
                                            >
                                                <!-- Tombol pemicu (tampil seperti input) -->
                                                <button
                                                    id="jabatan-pegawai"
                                                    type="button"
                                                    @click="
                                                        toggleDropdownJabatan
                                                    "
                                                    :disabled="jabatanDisabled"
                                                    aria-haspopup="listbox"
                                                    :aria-expanded="
                                                        dropdownJabatanBuka
                                                    "
                                                    :class="
                                                        kelasTombolForm(
                                                            'jabatan',
                                                        )
                                                    "
                                                >
                                                    <span
                                                        class="truncate"
                                                        :class="
                                                            kelasLabelForm(
                                                                jabatanPilihan,
                                                            )
                                                        "
                                                    >
                                                        {{
                                                            labelJabatanPilihan ||
                                                            "-- Pilih Jabatan --"
                                                        }}
                                                    </span>
                                                    <svg
                                                        class="h-4 w-4 flex-shrink-0 text-gray-600 transition-transform"
                                                        :class="{
                                                            'rotate-180':
                                                                dropdownJabatanBuka,
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
                                                </button>

                                                <!-- Kotak daftar melayang -->
                                                <div
                                                    v-if="dropdownJabatanBuka"
                                                    role="listbox"
                                                    data-daftar-form
                                                    :class="KELAS_DAFTAR_FORM"
                                                >
                                                    <!-- Kosongkan pilihan -->
                                                    <button
                                                        type="button"
                                                        role="option"
                                                        @click="
                                                            pilihJabatan('')
                                                        "
                                                        class="block min-h-[40px] w-full px-3 py-2 text-left text-sm text-gray-500 hover:bg-gray-100 cursor-pointer"
                                                    >
                                                        -- Pilih Jabatan --
                                                    </button>

                                                    <template
                                                        v-for="grup in opsiGrupJabatan"
                                                        :key="
                                                            grup.label ||
                                                            'tanpa-grup'
                                                        "
                                                    >
                                                        <p
                                                            v-if="
                                                                tampilkanJudulGrup &&
                                                                grup.label
                                                            "
                                                            class="sticky top-0 z-10 border-y border-gray-200 bg-gray-100 px-3 py-1 text-[11px] font-bold uppercase tracking-wide text-gray-600"
                                                        >
                                                            {{ grup.label }}
                                                        </p>
                                                        <button
                                                            v-for="j in grup.options"
                                                            :key="j"
                                                            type="button"
                                                            role="option"
                                                            :aria-selected="
                                                                jabatanPilihan ===
                                                                j
                                                            "
                                                            @click="
                                                                pilihJabatan(j)
                                                            "
                                                            :class="
                                                                kelasOpsiForm(
                                                                    jabatanPilihan ===
                                                                        j,
                                                                )
                                                            "
                                                        >
                                                            <span
                                                                class="min-w-0 break-words"
                                                                >{{ j }}</span
                                                            >
                                                            <svg
                                                                v-if="
                                                                    jabatanPilihan ===
                                                                    j
                                                                "
                                                                class="h-4 w-4 flex-shrink-0 text-indigo-700"
                                                                viewBox="0 0 20 20"
                                                                fill="currentColor"
                                                                aria-hidden="true"
                                                            >
                                                                <path
                                                                    fill-rule="evenodd"
                                                                    d="M16.704 5.29a1 1 0 010 1.415l-7.5 7.5a1 1 0 01-1.415 0l-3.5-3.5a1 1 0 111.415-1.415L8.5 12.086l6.793-6.793a1 1 0 011.415 0z"
                                                                    clip-rule="evenodd"
                                                                />
                                                            </svg>
                                                        </button>
                                                    </template>

                                                    <!-- Opsi ketik sendiri -->
                                                    <button
                                                        type="button"
                                                        role="option"
                                                        :aria-selected="
                                                            jabatanPilihan ===
                                                            OPSI_BARU
                                                        "
                                                        @click="
                                                            pilihJabatan(
                                                                OPSI_BARU,
                                                            )
                                                        "
                                                        :class="[
                                                            'border-t border-gray-200',
                                                            kelasOpsiForm(
                                                                jabatanPilihan ===
                                                                    OPSI_BARU,
                                                            ),
                                                        ]"
                                                    >
                                                        <span
                                                            class="min-w-0 break-words"
                                                            >Lainnya (ketik
                                                            sendiri)...</span
                                                        >
                                                        <svg
                                                            v-if="
                                                                jabatanPilihan ===
                                                                OPSI_BARU
                                                            "
                                                            class="h-4 w-4 flex-shrink-0 text-indigo-700"
                                                            viewBox="0 0 20 20"
                                                            fill="currentColor"
                                                            aria-hidden="true"
                                                        >
                                                            <path
                                                                fill-rule="evenodd"
                                                                d="M16.704 5.29a1 1 0 010 1.415l-7.5 7.5a1 1 0 01-1.415 0l-3.5-3.5a1 1 0 111.415-1.415L8.5 12.086l6.793-6.793a1 1 0 011.415 0z"
                                                                clip-rule="evenodd"
                                                            />
                                                        </svg>
                                                    </button>
                                                </div>
                                            </div>

                                            <input
                                                v-if="
                                                    jabatanPilihan === OPSI_BARU
                                                "
                                                id="jabatan-pegawai-manual"
                                                name="jabatan_manual"
                                                type="text"
                                                v-model="jabatanTeks"
                                                required
                                                aria-label="Jabatan yang diketik sendiri"
                                                placeholder="Ketik jabatan yang belum ada di daftar"
                                                :class="inputClass('jabatan')"
                                            />

                                            <button
                                                v-if="
                                                    perluAwalan &&
                                                    fungsionalBawaan
                                                "
                                                type="button"
                                                @click="kembaliKeBawaan"
                                                class="mt-1 min-h-[32px] text-xs text-indigo-600 hover:text-indigo-800 cursor-pointer"
                                            >
                                                &laquo; Kembali ke jabatan
                                                otomatis
                                            </button>
                                        </template>
                                    </template>

                                    <p
                                        v-if="form.errors.jabatan"
                                        class="mt-1 text-xs text-red-600"
                                    >
                                        {{ form.errors.jabatan }}
                                    </p>
                                </div>

                                <div
                                    class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4"
                                >
                                    <div>
                                        <label
                                            for="pegawai-tanggal-masuk"
                                            class="block text-sm font-medium text-gray-700"
                                        >
                                            Tanggal Masuk (TMT)
                                        </label>
                                        <input
                                            id="pegawai-tanggal-masuk"
                                            name="tanggal_masuk"
                                            type="date"
                                            v-model="form.tanggal_masuk"
                                            :class="inputClass('tanggal_masuk')"
                                        />
                                        <p class="mt-1 text-xs text-gray-500">
                                            Kosongkan untuk pegawai baru agar
                                            diambil dari NIP. Dipakai untuk
                                            syarat masa kerja Cuti Besar.
                                        </p>
                                        <p
                                            v-if="form.errors.tanggal_masuk"
                                            class="mt-1 text-xs text-red-600"
                                        >
                                            {{ form.errors.tanggal_masuk }}
                                        </p>
                                    </div>

                                    <div>
                                        <label
                                            for="pegawai-status"
                                            class="block text-sm font-medium text-gray-700"
                                        >
                                            Status Pegawai
                                            <span class="text-red-500">*</span>
                                        </label>
                                        <div
                                            class="relative"
                                            data-dropdown-form
                                        >
                                            <button
                                                id="pegawai-status"
                                                type="button"
                                                @click="
                                                    toggleDropdownForm('status')
                                                "
                                                aria-haspopup="listbox"
                                                :aria-expanded="
                                                    dropdownFormTerbuka ===
                                                    'status'
                                                "
                                                :title="form.status_aktif"
                                                :class="
                                                    kelasTombolForm(
                                                        'status_aktif',
                                                    )
                                                "
                                            >
                                                <span
                                                    class="truncate"
                                                    :class="
                                                        kelasLabelForm(
                                                            form.status_aktif,
                                                        )
                                                    "
                                                    >{{
                                                        form.status_aktif
                                                    }}</span
                                                >
                                                <svg
                                                    class="h-4 w-4 flex-shrink-0 text-gray-600 transition-transform"
                                                    :class="{
                                                        'rotate-180':
                                                            dropdownFormTerbuka ===
                                                            'status',
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
                                            </button>
                                            <ul
                                                v-if="
                                                    dropdownFormTerbuka ===
                                                    'status'
                                                "
                                                role="listbox"
                                                data-daftar-form
                                                :class="KELAS_DAFTAR_FORM"
                                            >
                                                <li
                                                    v-for="opt in opsiStatusForm"
                                                    :key="opt.value"
                                                >
                                                    <button
                                                        type="button"
                                                        role="option"
                                                        :title="opt.label"
                                                        :aria-selected="
                                                            form.status_aktif ===
                                                            opt.value
                                                        "
                                                        @click="
                                                            pilihStatusForm(
                                                                opt.value,
                                                            )
                                                        "
                                                        :class="
                                                            kelasOpsiForm(
                                                                opsiAktif(
                                                                    form.status_aktif,
                                                                    opt.value,
                                                                ),
                                                            )
                                                        "
                                                    >
                                                        <span
                                                            class="min-w-0 break-words"
                                                            >{{
                                                                opt.label
                                                            }}</span
                                                        >
                                                        <svg
                                                            v-if="
                                                                opsiAktif(
                                                                    form.status_aktif,
                                                                    opt.value,
                                                                )
                                                            "
                                                            class="h-4 w-4 flex-shrink-0 text-indigo-700"
                                                            viewBox="0 0 20 20"
                                                            fill="currentColor"
                                                            aria-hidden="true"
                                                        >
                                                            <path
                                                                fill-rule="evenodd"
                                                                d="M16.704 5.29a1 1 0 010 1.415l-7.5 7.5a1 1 0 01-1.415 0l-3.5-3.5a1 1 0 111.415-1.415L8.5 12.086l6.793-6.793a1 1 0 011.415 0z"
                                                                clip-rule="evenodd"
                                                            />
                                                        </svg>
                                                    </button>
                                                </li>
                                            </ul>
                                        </div>
                                        <p
                                            v-if="form.errors.status_aktif"
                                            class="mt-1 text-xs text-red-600"
                                        >
                                            {{ form.errors.status_aktif }}
                                        </p>
                                    </div>

                                    <!-- Status Kepegawaian (PNS / PPPK) -->
                                    <div>
                                        <label
                                            for="pegawai-status-kepegawaian"
                                            class="block text-sm font-medium text-gray-700"
                                        >
                                            Status Kepegawaian
                                            <span class="text-red-500">*</span>
                                        </label>
                                        <div
                                            class="relative"
                                            data-dropdown-form
                                        >
                                            <button
                                                id="pegawai-status-kepegawaian"
                                                type="button"
                                                @click="
                                                    toggleDropdownForm(
                                                        'statusKepegawaian',
                                                    )
                                                "
                                                aria-haspopup="listbox"
                                                :aria-expanded="
                                                    dropdownFormTerbuka ===
                                                    'statusKepegawaian'
                                                "
                                                :title="
                                                    labelStatusKepegawaianForm
                                                "
                                                :class="
                                                    kelasTombolForm(
                                                        'status_kepegawaian',
                                                    )
                                                "
                                            >
                                                <span
                                                    class="truncate"
                                                    :class="
                                                        kelasLabelForm(
                                                            form.status_kepegawaian,
                                                        )
                                                    "
                                                    >{{
                                                        labelStatusKepegawaianForm
                                                    }}</span
                                                >
                                                <svg
                                                    class="h-4 w-4 flex-shrink-0 text-gray-600 transition-transform"
                                                    :class="{
                                                        'rotate-180':
                                                            dropdownFormTerbuka ===
                                                            'statusKepegawaian',
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
                                            </button>
                                            <ul
                                                v-if="
                                                    dropdownFormTerbuka ===
                                                    'statusKepegawaian'
                                                "
                                                role="listbox"
                                                data-daftar-form
                                                :class="KELAS_DAFTAR_FORM"
                                            >
                                                <li
                                                    v-for="opt in opsiStatusKepegawaianForm"
                                                    :key="opt.value"
                                                >
                                                    <button
                                                        type="button"
                                                        role="option"
                                                        :aria-selected="
                                                            form.status_kepegawaian ===
                                                            opt.value
                                                        "
                                                        @click="
                                                            pilihStatusKepegawaianForm(
                                                                opt.value,
                                                            )
                                                        "
                                                        :class="
                                                            kelasOpsiForm(
                                                                opsiAktif(
                                                                    form.status_kepegawaian,
                                                                    opt.value,
                                                                ),
                                                            )
                                                        "
                                                    >
                                                        <span
                                                            class="min-w-0 break-words"
                                                            :class="
                                                                opt.value === ''
                                                                    ? 'text-gray-500'
                                                                    : ''
                                                            "
                                                            >{{
                                                                opt.label
                                                            }}</span
                                                        >
                                                        <svg
                                                            v-if="
                                                                opsiAktif(
                                                                    form.status_kepegawaian,
                                                                    opt.value,
                                                                )
                                                            "
                                                            class="h-4 w-4 flex-shrink-0 text-indigo-700"
                                                            viewBox="0 0 20 20"
                                                            fill="currentColor"
                                                            aria-hidden="true"
                                                        >
                                                            <path
                                                                fill-rule="evenodd"
                                                                d="M16.704 5.29a1 1 0 010 1.415l-7.5 7.5a1 1 0 01-1.415 0l-3.5-3.5a1 1 0 111.415-1.415L8.5 12.086l6.793-6.793a1 1 0 011.415 0z"
                                                                clip-rule="evenodd"
                                                            />
                                                        </svg>
                                                    </button>
                                                </li>
                                            </ul>
                                        </div>
                                        <p
                                            v-if="
                                                form.errors.status_kepegawaian
                                            "
                                            class="mt-1 text-xs text-red-600"
                                        >
                                            {{ form.errors.status_kepegawaian }}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div
                                v-if="isEditMode"
                                class="mt-4 flex items-start gap-2 rounded-lg border border-blue-100 bg-blue-50/60 p-3"
                            >
                                <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke-width="1.5"
                                    stroke="currentColor"
                                    class="w-5 h-5 text-blue-500 flex-shrink-0 mt-0.5"
                                    aria-hidden="true"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"
                                    />
                                </svg>
                                <p
                                    class="text-xs text-blue-700 leading-relaxed"
                                >
                                    Untuk mengubah kuota/saldo cuti pegawai ini
                                    (hak tahunan, sisa cuti, atau cuti
                                    ditangguhkan), buka menu
                                    <span class="font-semibold"
                                        >Rekap Kuota Detail</span
                                    >
                                    lalu klik
                                    <span class="font-semibold"
                                        >Kelola Kuota</span
                                    >
                                    pada baris pegawai terkait.
                                </p>
                            </div>

                            <div
                                class="flex justify-end gap-2 mt-6 pt-4 border-t border-gray-200"
                            >
                                <button
                                    type="button"
                                    @click="closeModal"
                                    class="min-h-[44px] px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 cursor-pointer transition"
                                >
                                    Batal
                                </button>
                                <button
                                    type="submit"
                                    class="min-h-[44px] px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 cursor-pointer transition disabled:opacity-50 disabled:cursor-not-allowed"
                                    :disabled="form.processing"
                                >
                                    {{
                                        form.processing
                                            ? "Menyimpan..."
                                            : isEditMode
                                              ? "Simpan Perubahan"
                                              : "Simpan Data"
                                    }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </Teleport>

            <!-- ========================================== -->
            <!-- MODAL IMPOR DATA PEGAWAI                   -->
            <!-- ========================================== -->
            <Teleport to="body">
                <div
                    v-if="isImportModalOpen"
                    class="fixed inset-0 flex items-end justify-center p-0 sm:items-center sm:p-4"
                    style="z-index: 999999"
                >
                    <div
                        class="absolute inset-0 bg-black/60 backdrop-blur-sm"
                        @click="closeImportModal"
                    ></div>

                    <div
                        class="relative bg-white p-4 sm:p-6 rounded-t-2xl sm:rounded-xl w-full max-w-md shadow-2xl max-h-[90dvh] overflow-y-auto z-10"
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="judul-modal-impor"
                    >
                        <div
                            class="flex justify-between items-center mb-5 border-b pb-3"
                        >
                            <h3
                                id="judul-modal-impor"
                                class="text-lg font-bold text-gray-800"
                            >
                                Impor Data Pegawai
                            </h3>
                            <button
                                type="button"
                                @click="closeImportModal"
                                aria-label="Tutup"
                                class="min-h-[44px] min-w-[44px] text-gray-400 hover:text-red-500 font-bold text-2xl cursor-pointer outline-none"
                            >
                                &times;
                            </button>
                        </div>

                        <form @submit.prevent="submitImportForm">
                            <p class="text-sm text-gray-500 mb-4">
                                Unggah file Excel (.xlsx) atau CSV berisi data
                                pegawai untuk ditambahkan secara massal.
                            </p>

                            <div class="mb-2">
                                <label
                                    for="impor-file-pegawai"
                                    class="block text-sm font-medium text-gray-700 mb-1"
                                >
                                    File Excel / CSV
                                </label>
                                <input
                                    id="impor-file-pegawai"
                                    name="file"
                                    ref="importFileInput"
                                    type="file"
                                    accept=".xlsx,.xls,.csv"
                                    @change="handleImportFileChange"
                                    class="block w-full text-sm text-gray-600 border border-gray-400 rounded-md cursor-pointer focus:outline-none file:mr-3 file:py-2 file:px-4 file:border-0 file:bg-blue-50 file:text-blue-700 file:font-semibold hover:file:bg-blue-100"
                                />
                            </div>

                            <p
                                v-if="importForm.errors.file"
                                class="text-sm text-red-600 mb-2"
                                role="alert"
                            >
                                {{ importForm.errors.file }}
                            </p>

                            <p class="text-xs text-gray-500 mb-5">
                                Pastikan kolom pada file mengikuti format
                                template yang sudah ditentukan (NIP, Nama,
                                Kelompok Substansi, Tim Kerja, Jabatan, Status
                                Kepegawaian, Level/Role, dsb).
                            </p>

                            <div
                                class="flex justify-end gap-2 pt-4 border-t border-gray-100"
                            >
                                <button
                                    type="button"
                                    @click="closeImportModal"
                                    class="min-h-[44px] px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 cursor-pointer transition"
                                >
                                    Batal
                                </button>
                                <button
                                    type="submit"
                                    class="min-h-[44px] px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 cursor-pointer transition disabled:opacity-50 disabled:cursor-not-allowed"
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
            </Teleport>

            <!-- ========================================== -->
            <!-- POP-UP HASIL IMPOR (berhasil / gagal)      -->
            <!-- ========================================== -->
            <Teleport to="body">
                <Transition
                    enter-active-class="transition ease-out duration-200"
                    enter-from-class="opacity-0"
                    enter-to-class="opacity-100"
                    leave-active-class="transition ease-in duration-150"
                    leave-from-class="opacity-100"
                    leave-to-class="opacity-0"
                >
                    <div
                        v-if="showModalHasilImpor"
                        class="fixed inset-0 z-[999999] flex items-end justify-center overflow-y-auto overflow-x-hidden bg-slate-900/50 backdrop-blur-sm sm:items-center"
                    >
                        <div
                            class="absolute inset-0"
                            @click="tutupHasilImpor"
                        ></div>

                        <div class="relative w-full max-w-md">
                            <div
                                class="relative max-h-[90dvh] overflow-y-auto rounded-t-2xl bg-white p-5 text-center shadow-2xl sm:rounded-2xl sm:p-6"
                                role="alertdialog"
                                aria-modal="true"
                                aria-labelledby="judul-hasil-impor"
                            >
                                <div
                                    class="mx-auto mb-5 flex h-14 w-14 items-center justify-center rounded-full"
                                    :class="
                                        hasilImpor.jenis === 'sukses'
                                            ? 'bg-green-50'
                                            : 'bg-red-50'
                                    "
                                >
                                    <svg
                                        v-if="hasilImpor.jenis === 'sukses'"
                                        class="h-7 w-7 text-green-600"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke-width="2"
                                        stroke="currentColor"
                                        aria-hidden="true"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            d="M5 13l4 4L19 7"
                                        />
                                    </svg>
                                    <svg
                                        v-else
                                        class="h-7 w-7 text-red-600"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke-width="2"
                                        stroke="currentColor"
                                        aria-hidden="true"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            d="M6 18L18 6M6 6l12 12"
                                        />
                                    </svg>
                                </div>

                                <h3
                                    id="judul-hasil-impor"
                                    class="mb-3 text-lg font-bold text-slate-800"
                                >
                                    {{
                                        hasilImpor.jenis === "sukses"
                                            ? "Impor Selesai"
                                            : "Impor Gagal"
                                    }}
                                </h3>

                                <!-- Ringkasan jumlah (hanya untuk impor yang berhasil) -->
                                <div
                                    v-if="
                                        hasilImpor.jenis === 'sukses' &&
                                        angkaRingkasanImpor
                                    "
                                    class="mb-4 grid grid-cols-3 gap-2"
                                >
                                    <div class="rounded-lg bg-green-50 p-3">
                                        <p
                                            class="text-xl font-bold text-green-700"
                                        >
                                            {{ angkaRingkasanImpor.baru }}
                                        </p>
                                        <p class="text-xs text-green-700">
                                            Pegawai baru
                                        </p>
                                    </div>
                                    <div class="rounded-lg bg-blue-50 p-3">
                                        <p
                                            class="text-xl font-bold text-blue-700"
                                        >
                                            {{ angkaRingkasanImpor.diperbarui }}
                                        </p>
                                        <p class="text-xs text-blue-700">
                                            Diperbarui
                                        </p>
                                    </div>
                                    <div class="rounded-lg bg-amber-50 p-3">
                                        <p
                                            class="text-xl font-bold text-amber-700"
                                        >
                                            {{ angkaRingkasanImpor.dilewati }}
                                        </p>
                                        <p class="text-xs text-amber-700">
                                            Dilewati
                                        </p>
                                    </div>
                                </div>

                                <p
                                    class="mb-6 break-words text-sm leading-relaxed text-slate-500"
                                >
                                    {{ hasilImpor.pesan }}
                                </p>

                                <button
                                    type="button"
                                    @click="tutupHasilImpor"
                                    class="min-h-[44px] w-full cursor-pointer rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-all hover:bg-indigo-700 hover:shadow-md"
                                >
                                    Oke
                                </button>
                            </div>
                        </div>
                    </div>
                </Transition>
            </Teleport>

            <!-- TOAST NOTIFIKASI SUKSES RESET KATA SANDI -->
            <Teleport to="body">
                <Transition
                    enter-active-class="transition ease-out duration-200"
                    enter-from-class="opacity-0 translate-y-2"
                    enter-to-class="opacity-100 translate-y-0"
                    leave-active-class="transition ease-in duration-150"
                    leave-from-class="opacity-100 translate-y-0"
                    leave-to-class="opacity-0 translate-y-2"
                >
                    <div
                        v-if="showResetToast"
                        class="fixed bottom-4 left-4 right-4 sm:left-auto sm:bottom-6 sm:right-6 flex items-center gap-3 bg-white border border-green-200 shadow-2xl rounded-lg px-4 py-3 sm:max-w-sm"
                        style="z-index: 999999"
                        role="status"
                    >
                        <span
                            class="flex-shrink-0 flex items-center justify-center w-8 h-8 rounded-full bg-green-100"
                        >
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-5 w-5 text-green-600"
                                viewBox="0 0 20 20"
                                fill="currentColor"
                                aria-hidden="true"
                            >
                                <path
                                    fill-rule="evenodd"
                                    d="M16.704 5.29a1 1 0 010 1.415l-7.5 7.5a1 1 0 01-1.415 0l-3.5-3.5a1 1 0 111.415-1.415L8.5 12.086l6.793-6.793a1 1 0 011.415 0z"
                                    clip-rule="evenodd"
                                />
                            </svg>
                        </span>
                        <div class="min-w-0 text-sm">
                            <p class="font-semibold text-gray-800">
                                Kata sandi berhasil diatur ulang
                            </p>
                            <p class="text-gray-500 break-words">
                                Akun
                                <span class="font-medium">{{
                                    resetToastNama
                                }}</span>
                                sekarang menggunakan kata sandi bawaan.
                            </p>
                        </div>
                        <button
                            type="button"
                            @click="showResetToast = false"
                            aria-label="Tutup notifikasi"
                            class="ml-auto min-h-[44px] min-w-[32px] text-gray-400 hover:text-gray-600 cursor-pointer"
                        >
                            &times;
                        </button>
                    </div>
                </Transition>
            </Teleport>

            <!-- TOAST NOTIFIKASI SUKSES UMUM (hasil hapus massal) -->
            <Teleport to="body">
                <Transition
                    enter-active-class="transition ease-out duration-200"
                    enter-from-class="opacity-0 translate-y-2"
                    enter-to-class="opacity-100 translate-y-0"
                    leave-active-class="transition ease-in duration-150"
                    leave-from-class="opacity-100 translate-y-0"
                    leave-to-class="opacity-0 translate-y-2"
                >
                    <div
                        v-if="showSuksesToast"
                        class="fixed bottom-4 left-4 right-4 sm:left-auto sm:bottom-6 sm:right-6 flex items-center gap-3 bg-white border border-green-200 shadow-2xl rounded-lg px-4 py-3 sm:max-w-sm"
                        style="z-index: 999999"
                        role="status"
                    >
                        <span
                            class="flex-shrink-0 flex items-center justify-center w-8 h-8 rounded-full bg-green-100"
                        >
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-5 w-5 text-green-600"
                                viewBox="0 0 20 20"
                                fill="currentColor"
                                aria-hidden="true"
                            >
                                <path
                                    fill-rule="evenodd"
                                    d="M16.704 5.29a1 1 0 010 1.415l-7.5 7.5a1 1 0 01-1.415 0l-3.5-3.5a1 1 0 111.415-1.415L8.5 12.086l6.793-6.793a1 1 0 011.415 0z"
                                    clip-rule="evenodd"
                                />
                            </svg>
                        </span>
                        <p class="min-w-0 text-sm text-gray-700 break-words">
                            {{ suksesToastMessage }}
                        </p>
                        <button
                            type="button"
                            @click="showSuksesToast = false"
                            aria-label="Tutup notifikasi"
                            class="ml-auto min-h-[44px] min-w-[32px] text-gray-400 hover:text-gray-600 cursor-pointer"
                        >
                            &times;
                        </button>
                    </div>
                </Transition>
            </Teleport>

            <!-- TOAST NOTIFIKASI ERROR (atur ulang kata sandi / hapus gagal) -->
            <Teleport to="body">
                <Transition
                    enter-active-class="transition ease-out duration-200"
                    enter-from-class="opacity-0 translate-y-2"
                    enter-to-class="opacity-100 translate-y-0"
                    leave-active-class="transition ease-in duration-150"
                    leave-from-class="opacity-100 translate-y-0"
                    leave-to-class="opacity-0 translate-y-2"
                >
                    <div
                        v-if="showErrorToast"
                        class="fixed bottom-4 left-4 right-4 sm:left-auto sm:bottom-6 sm:right-6 flex items-center gap-3 bg-white border border-red-200 shadow-2xl rounded-lg px-4 py-3 sm:max-w-sm"
                        style="z-index: 999999"
                        role="alert"
                    >
                        <span
                            class="flex-shrink-0 flex items-center justify-center w-8 h-8 rounded-full bg-red-100"
                        >
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-5 w-5 text-red-600"
                                viewBox="0 0 20 20"
                                fill="currentColor"
                                aria-hidden="true"
                            >
                                <path
                                    fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"
                                    clip-rule="evenodd"
                                />
                            </svg>
                        </span>
                        <div class="min-w-0 text-sm">
                            <p class="font-semibold text-gray-800">
                                Aksi gagal
                            </p>
                            <p class="text-gray-500 break-words">
                                {{ errorToastMessage }}
                            </p>
                        </div>
                        <button
                            type="button"
                            @click="showErrorToast = false"
                            aria-label="Tutup notifikasi"
                            class="ml-auto min-h-[44px] min-w-[32px] text-gray-400 hover:text-gray-600 cursor-pointer"
                        >
                            &times;
                        </button>
                    </div>
                </Transition>
            </Teleport>

            <!-- ========================================== -->
            <!-- MODAL KONFIRMASI HAPUS MASSAL              -->
            <!-- (hanya pegawai bisa_dihapus = true)        -->
            <!-- ========================================== -->
            <Teleport to="body">
                <Transition
                    enter-active-class="transition ease-out duration-200"
                    enter-from-class="opacity-0"
                    enter-to-class="opacity-100"
                    leave-active-class="transition ease-in duration-150"
                    leave-from-class="opacity-100"
                    leave-to-class="opacity-0"
                >
                    <div
                        v-if="showModalHapusMassal"
                        class="fixed inset-0 z-[999999] flex items-end justify-center overflow-y-auto overflow-x-hidden bg-slate-900/50 backdrop-blur-sm sm:items-center"
                    >
                        <div
                            class="absolute inset-0"
                            @click="batalHapusMassal"
                        ></div>

                        <div class="relative w-full max-w-md">
                            <div
                                class="relative max-h-[90dvh] overflow-y-auto rounded-t-2xl bg-white p-5 text-center shadow-2xl sm:rounded-2xl sm:p-6"
                                role="alertdialog"
                                aria-modal="true"
                                aria-labelledby="judul-modal-hapus-massal"
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
                                        aria-hidden="true"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
                                        />
                                    </svg>
                                </div>

                                <h3
                                    id="judul-modal-hapus-massal"
                                    class="mb-2 text-lg font-bold text-slate-800"
                                >
                                    Hapus {{ selectedIds.length }} Pegawai
                                </h3>

                                <p class="mb-3 text-sm text-slate-500">
                                    Pegawai berikut akan dihapus:
                                </p>

                                <ul
                                    class="mb-4 max-h-40 space-y-1 overflow-y-auto rounded-lg border border-slate-100 bg-slate-50 p-3 text-left text-sm text-slate-700"
                                >
                                    <li
                                        v-for="p in pegawaiTerpilih"
                                        :key="p.id"
                                        class="break-words"
                                    >
                                        {{ p.nama }}
                                        <span class="text-xs text-slate-400"
                                            >({{ p.nip }})</span
                                        >
                                    </li>
                                </ul>

                                <!-- Peringatan tegas: hapus hanya untuk koreksi data -->
                                <div
                                    class="mb-6 flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 p-3 text-left"
                                    role="alert"
                                >
                                    <svg
                                        class="h-5 w-5 flex-shrink-0 text-red-500 mt-0.5"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke-width="1.5"
                                        stroke="currentColor"
                                        aria-hidden="true"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"
                                        />
                                    </svg>
                                    <p
                                        class="text-[13px] leading-relaxed text-red-700"
                                    >
                                        <span class="font-bold"
                                            >PERINGATAN:</span
                                        >
                                        Hapus hanya untuk mengoreksi data yang
                                        salah input atau duplikat. Untuk pegawai
                                        resign atau pensiun, ubah statusnya
                                        lewat fitur Ubah. Akses login dicabut,
                                        namun data tetap tersimpan sebagai arsip
                                        (soft delete).
                                    </p>
                                </div>

                                <div class="grid grid-cols-2 gap-3">
                                    <button
                                        @click="batalHapusMassal"
                                        :disabled="sedangMenghapusMassal"
                                        type="button"
                                        class="min-h-[44px] px-4 py-2.5 text-sm font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 rounded-xl transition-colors cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
                                    >
                                        Batal
                                    </button>
                                    <button
                                        @click="eksekusiHapusMassal"
                                        :disabled="sedangMenghapusMassal"
                                        type="button"
                                        class="min-h-[44px] px-4 py-2.5 text-sm font-semibold text-white bg-red-600 hover:bg-red-700 rounded-xl shadow-sm hover:shadow-md transition-all cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
                                    >
                                        {{
                                            sedangMenghapusMassal
                                                ? "Menghapus..."
                                                : `Ya, Hapus ${selectedIds.length}`
                                        }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </Transition>
            </Teleport>

            <!-- ========================================== -->
            <!-- SKENARIO A: MODAL KONFIRMASI HAPUS         -->
            <!-- (hanya untuk pegawai bisa_dihapus = true)  -->
            <!-- ========================================== -->
            <Teleport to="body">
                <Transition
                    enter-active-class="transition ease-out duration-200"
                    enter-from-class="opacity-0"
                    enter-to-class="opacity-100"
                    leave-active-class="transition ease-in duration-150"
                    leave-from-class="opacity-100"
                    leave-to-class="opacity-0"
                >
                    <div
                        v-if="showModalHapus"
                        class="fixed inset-0 z-[999999] flex items-end justify-center overflow-y-auto overflow-x-hidden bg-slate-900/50 backdrop-blur-sm sm:items-center"
                    >
                        <div class="absolute inset-0" @click="batalHapus"></div>

                        <div class="relative w-full max-w-md">
                            <div
                                class="relative max-h-[90dvh] overflow-y-auto rounded-t-2xl bg-white p-5 text-center shadow-2xl sm:rounded-2xl sm:p-6"
                                role="alertdialog"
                                aria-modal="true"
                                aria-labelledby="judul-modal-hapus"
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
                                        aria-hidden="true"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
                                        />
                                    </svg>
                                </div>

                                <h3
                                    id="judul-modal-hapus"
                                    class="mb-2 text-lg font-bold text-slate-800"
                                >
                                    Konfirmasi Hapus
                                </h3>

                                <p class="mb-3 text-sm text-slate-500">
                                    Apakah Anda yakin ingin menghapus pegawai
                                    ini?
                                </p>

                                <div
                                    class="mb-5 p-3 bg-slate-50 border border-slate-100 rounded-lg"
                                >
                                    <p
                                        class="text-sm font-extrabold text-slate-700 break-words"
                                    >
                                        {{ pegawaiYangAkanDihapus?.nama }}
                                    </p>
                                </div>

                                <!-- Peringatan tegas: hapus hanya untuk koreksi data -->
                                <div
                                    class="mb-6 flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 p-3 text-left"
                                    role="alert"
                                >
                                    <svg
                                        class="h-5 w-5 flex-shrink-0 text-red-500 mt-0.5"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke-width="1.5"
                                        stroke="currentColor"
                                        aria-hidden="true"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"
                                        />
                                    </svg>
                                    <p
                                        class="text-[13px] leading-relaxed text-red-700"
                                    >
                                        <span class="font-bold"
                                            >PERINGATAN:</span
                                        >
                                        Fitur hapus hanya untuk mengoreksi data
                                        yang salah ketik atau duplikat. Jika
                                        pegawai ini resign atau pensiun,
                                        batalkan penghapusan dan gunakan fitur
                                        Ubah untuk mengganti statusnya. Akses
                                        login akan dicabut, namun data tetap
                                        tersimpan sebagai arsip (soft delete).
                                    </p>
                                </div>

                                <div class="grid grid-cols-2 gap-3">
                                    <button
                                        @click="batalHapus"
                                        :disabled="sedangMenghapus"
                                        type="button"
                                        class="min-h-[44px] px-4 py-2.5 text-sm font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 rounded-xl transition-colors cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
                                    >
                                        Batal
                                    </button>
                                    <button
                                        @click="eksekusiHapus"
                                        :disabled="sedangMenghapus"
                                        type="button"
                                        class="min-h-[44px] px-4 py-2.5 text-sm font-semibold text-white bg-red-600 hover:bg-red-700 rounded-xl shadow-sm hover:shadow-md transition-all cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
                                    >
                                        {{
                                            sedangMenghapus
                                                ? "Menghapus..."
                                                : "Ya, Hapus"
                                        }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </Transition>
            </Teleport>

            <!-- ========================================== -->
            <!-- SKENARIO B: POP-UP INFO "TIDAK BISA DIHAPUS" -->
            <!-- Tidak ada tombol "Ya, Hapus" di sini        -->
            <!-- ========================================== -->
            <Teleport to="body">
                <Transition
                    enter-active-class="transition ease-out duration-200"
                    enter-from-class="opacity-0"
                    enter-to-class="opacity-100"
                    leave-active-class="transition ease-in duration-150"
                    leave-from-class="opacity-100"
                    leave-to-class="opacity-0"
                >
                    <div
                        v-if="showModalTidakBisaHapus"
                        class="fixed inset-0 z-[999999] flex items-end justify-center overflow-y-auto overflow-x-hidden bg-slate-900/50 backdrop-blur-sm sm:items-center"
                    >
                        <div
                            class="absolute inset-0"
                            @click="tutupModalTidakBisaHapus"
                        ></div>

                        <div class="relative w-full max-w-md">
                            <div
                                class="relative max-h-[90dvh] overflow-y-auto rounded-t-2xl bg-white p-5 text-center shadow-2xl sm:rounded-2xl sm:p-6"
                                role="alertdialog"
                                aria-modal="true"
                                aria-labelledby="judul-modal-tidak-bisa-hapus"
                            >
                                <div
                                    class="mx-auto flex items-center justify-center h-14 w-14 rounded-full bg-amber-50 mb-5"
                                >
                                    <svg
                                        class="h-7 w-7 text-amber-500"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke-width="2"
                                        stroke="currentColor"
                                        aria-hidden="true"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"
                                        />
                                    </svg>
                                </div>

                                <h3
                                    id="judul-modal-tidak-bisa-hapus"
                                    class="mb-2 text-lg font-bold text-slate-800"
                                >
                                    Tidak Bisa Dihapus
                                </h3>

                                <div
                                    class="mb-5 p-3 bg-slate-50 border border-slate-100 rounded-lg"
                                >
                                    <p
                                        class="text-sm font-extrabold text-slate-700 break-words"
                                    >
                                        {{ pegawaiTidakBisaDihapus?.nama }}
                                    </p>
                                </div>

                                <div
                                    class="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-3 text-left"
                                >
                                    <p
                                        class="text-[13px] leading-relaxed text-amber-800"
                                    >
                                        <span class="font-bold"
                                            >TIDAK BISA DIHAPUS:</span
                                        >
                                        Pegawai ini sudah memiliki riwayat cuti
                                        atau sudah lama terdaftar. Untuk
                                        mengeluarkan pegawai, gunakan fitur Ubah
                                        (ikon pensil) dan ganti statusnya
                                        menjadi Pensiun, Mutasi Keluar, atau
                                        Resign.
                                    </p>
                                </div>

                                <button
                                    @click="tutupModalTidakBisaHapus"
                                    type="button"
                                    class="min-h-[44px] w-full px-4 py-2.5 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl shadow-sm hover:shadow-md transition-all cursor-pointer"
                                >
                                    Oke, mengerti
                                </button>
                            </div>
                        </div>
                    </div>
                </Transition>
            </Teleport>

            <!-- ========================================== -->
            <!-- MODAL KONFIRMASI ATUR ULANG KATA SANDI     -->
            <!-- ========================================== -->
            <Teleport to="body">
                <Transition
                    enter-active-class="transition ease-out duration-200"
                    enter-from-class="opacity-0"
                    enter-to-class="opacity-100"
                    leave-active-class="transition ease-in duration-150"
                    leave-from-class="opacity-100"
                    leave-to-class="opacity-0"
                >
                    <div
                        v-if="showModalReset"
                        class="fixed inset-0 z-[999999] flex items-end justify-center overflow-y-auto overflow-x-hidden bg-slate-900/50 backdrop-blur-sm sm:items-center"
                    >
                        <div class="absolute inset-0" @click="batalReset"></div>

                        <div class="relative w-full max-w-md">
                            <div
                                class="relative max-h-[90dvh] overflow-y-auto rounded-t-2xl bg-white p-5 text-center shadow-2xl sm:rounded-2xl sm:p-6"
                                role="alertdialog"
                                aria-modal="true"
                                aria-labelledby="judul-modal-reset"
                            >
                                <div
                                    class="mx-auto flex items-center justify-center h-14 w-14 rounded-full bg-orange-50 mb-5"
                                >
                                    <svg
                                        class="h-7 w-7 text-orange-600"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke-width="2"
                                        stroke="currentColor"
                                        aria-hidden="true"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"
                                        />
                                    </svg>
                                </div>

                                <h3
                                    id="judul-modal-reset"
                                    class="mb-2 text-lg font-bold text-slate-800"
                                >
                                    Atur Ulang Kata Sandi
                                </h3>

                                <p class="mb-3 text-sm text-slate-500">
                                    Apakah Anda yakin ingin mengatur ulang kata
                                    sandi pegawai ini?
                                </p>

                                <div
                                    class="mb-5 p-3 bg-slate-50 border border-slate-100 rounded-lg"
                                >
                                    <p
                                        class="text-sm font-extrabold text-slate-700 break-words"
                                    >
                                        {{ pegawaiYangAkanDireset?.nama }}
                                    </p>
                                </div>

                                <p
                                    class="mb-6 text-[13px] text-slate-400 leading-relaxed"
                                >
                                    Kata sandi akan diubah menjadi kata sandi
                                    bawaan
                                    <span
                                        v-if="passwordDefault"
                                        class="font-bold text-slate-600"
                                        >{{ passwordDefault }}</span
                                    >. Pegawai wajib menggantinya saat login
                                    berikutnya.
                                </p>

                                <div class="grid grid-cols-2 gap-3">
                                    <button
                                        @click="batalReset"
                                        :disabled="sedangMereset"
                                        type="button"
                                        class="min-h-[44px] px-4 py-2.5 text-sm font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 rounded-xl transition-colors cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
                                    >
                                        Batal
                                    </button>
                                    <button
                                        @click="eksekusiReset"
                                        :disabled="sedangMereset"
                                        type="button"
                                        class="min-h-[44px] px-4 py-2.5 text-sm font-semibold text-white bg-orange-500 hover:bg-orange-600 rounded-xl shadow-sm hover:shadow-md transition-all cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
                                    >
                                        {{
                                            sedangMereset
                                                ? "Memproses..."
                                                : "Ya, Atur Ulang"
                                        }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </Transition>
            </Teleport>
        </div>
    </MainLayout>
</template>


