<script setup>
import MainLayout from "@/Layouts/MainLayout.vue";
import { Head, useForm, usePage } from "@inertiajs/vue3";
import { computed, ref, watch } from "vue";
import Swal from "sweetalert2";


const props = defineProps({
    sisa_cuti: Number,
    total_cuti_tersedia: Number, // Props cadangan, dikirim juga oleh CutiController::create()
    // BARU: daftar Hari Libur Nasional & Cuti Bersama dari tabel hari_libur
    // (dikirim CutiController@create), dipakai untuk menghitung "Estimasi
    // Jumlah Hari Kerja" secara real-time supaya tanggal merah/cuti bersama
    // TIDAK ikut memotong saldo cuti tahunan pegawai — identik dengan
    // perhitungan final yang dilakukan backend di CutiController@store.
    hariLiburs: {
        type: Array,
        default: () => [],
    },
});


// Tetap sediakan usePage sebagai jalur cadangan terakhir, kalau-kalau
// halaman ini suatu saat dirender tanpa props sisa_cuti dari controller.
const page = usePage();
const authUser = computed(() => page.props.auth.user);


// ================= MASA KERJA DARI NIP (untuk syarat Cuti Besar) =================
// PENTING: berbeda dari draf awal yang memakai
// `page.props.auth.user.pegawai?.nip` — di seluruh file ini (lihat
// authUser.value.alamat_domisili, authUser.value.no_telepon di atas)
// object `auth.user` dari backend SUDAH LANGSUNG berupa data Pegawai
// (Auth::user() di CutiController.php juga langsung memperlakukan
// $user sebagai model Pegawai, bukan $user->pegawai). Jadi NIP diambil
// langsung dari authUser.value.nip, BUKAN authUser.value.pegawai.nip,
// supaya konsisten dengan pola yang sudah dipakai di file ini.
//
// Format NIP PNS 18 digit: 8 digit tanggal lahir (YYYYMMDD) + 6 digit
// TMT/tahun-bulan pengangkatan (YYYYMM) + 6 digit lainnya. Tahun
// pengangkatan = digit ke-9 s/d ke-12 → substring(8, 12).
const masaKerjaTahun = computed(() => {
    const nip = authUser.value?.nip || "";
    if (nip.length >= 12) {
        const tahunAngkat = parseInt(nip.substring(8, 12), 10);
        if (!isNaN(tahunAngkat) && tahunAngkat > 1900) {
            const tahunSekarang = new Date().getFullYear();
            return tahunSekarang - tahunAngkat;
        }
    }
    // Default aman: kalau NIP tidak terbaca/formatnya tidak sesuai,
    // jangan mengunci opsi Cuti Besar secara keliru — anggap sudah
    // memenuhi syarat, biar backend (CutiController@store) yang jadi
    // penjaga akhir kalau ternyata memang belum 5 tahun.
    return 5;
});


// Apakah Cuti Besar boleh dipilih? (Syarat: masa kerja >= 5 tahun)
const canChooseCutiBesar = computed(() => masaKerjaTahun.value >= 5);
// ================= END MASA KERJA DARI NIP =================


// Setup Form Pengajuan Cuti (Menambahkan field anak_ke & lampiran)
const form = useForm({
    jenis_cuti: "Cuti Tahunan",
    anak_ke: "",
    tanggal_mulai: "",
    tanggal_selesai: "",
    keterangan: "",
    alamat_cuti: "",
    no_telp: "",
    lampiran: null, // Tambahan field file
});


// State checkbox untuk alamat domisili
const gunakanAlamatDomisili = ref(false);


// State checkbox untuk nomor telepon profil
const gunakanNomorTeleponProfil = ref(false);


const toggleAlamatDomisili = (e) => {
    if (e.target.checked) {
        // Otomatis isi dengan alamat domisili dari profil user, jika kosong beri string kosong
        form.alamat_cuti = authUser.value.alamat_domisili || "";
    } else {
        form.alamat_cuti = "";
    }
};


const toggleNomorTeleponProfil = (e) => {
    if (e.target.checked) {
        // Otomatis isi dengan nomor telepon dari profil user, jika kosong beri string kosong
        // Nama field di object auth.user adalah "no_telepon" (lihat HandleInertiaRequests.php)
        form.no_telp = authUser.value.no_telepon || "";
    } else {
        form.no_telp = "";
    }
};


// LOGIKA: Mendapatkan tanggal hari ini (Format YYYY-MM-DD)
const todayStr = computed(() => {
    const today = new Date();
    const yyyy = today.getFullYear();
    const mm = String(today.getMonth() + 1).padStart(2, "0");
    const dd = String(today.getDate()).padStart(2, "0");
    return `${yyyy}-${mm}-${dd}`;
});


// LOGIKA: Cek apakah tanggal mulai yang diketik manual ada di masa lalu
const isPastDate = computed(() => {
    if (!form.tanggal_mulai) return false;
    return form.tanggal_mulai < todayStr.value;
});


// ================= LOGIKA HARI LIBUR NASIONAL & CUTI BERSAMA =================
// Dipakai untuk mengecualikan tanggal merah/cuti bersama dari perhitungan
// "Estimasi Jumlah Hari Kerja", supaya hari-hari tersebut TIDAK memotong
// saldo cuti tahunan pegawai — identik dengan kebijakan yang sudah
// dituliskan di kartu "Ketentuan Pengajuan": "Pengajuan cuti yang jatuh
// pada akhir pekan atau hari libur nasional tidak akan dihitung sebagai
// hari cuti."
//
// hariLiburSet: Set berisi string tanggal 'YYYY-MM-DD' untuk pencarian
// cepat (O(1)) saat looping tanggal di estimasiHari(). Dibangun dari prop
// hariLiburs yang sudah dinormalisasi ke 'Y-m-d' oleh backend.
const hariLiburSet = computed(() => {
    return new Set(props.hariLiburs.map((h) => h.tanggal));
});


// Helper: cek apakah sebuah tanggal ('YYYY-MM-DD') termasuk hari libur
// (Libur Nasional ATAU Cuti Bersama — keduanya diperlakukan sama, sama-sama
// tidak memotong saldo cuti).
const isHariLibur = (dateStr) => hariLiburSet.value.has(dateStr);


// Helper: format objek Date menjadi string 'YYYY-MM-DD' (lokal, bukan UTC)
// supaya konsisten dengan format tanggal di hariLiburSet dan input <input
// type="date">.
const toDateStr = (date) => {
    const yyyy = date.getFullYear();
    const mm = String(date.getMonth() + 1).padStart(2, "0");
    const dd = String(date.getDate()).padStart(2, "0");
    return `${yyyy}-${mm}-${dd}`;
};


// Daftar hari libur yang JATUH DI DALAM rentang tanggal yang sedang dipilih
// pegawai — dipakai untuk menampilkan info transparan ("tanggal X dan Y
// tidak dihitung karena hari libur") di bawah kartu estimasi.
const hariLiburDalamRentang = computed(() => {
    if (!form.tanggal_mulai || !form.tanggal_selesai) return [];


    let start = new Date(form.tanggal_mulai);
    let end = new Date(form.tanggal_selesai);
    if (start > end) return [];


    const hasil = [];
    let current = new Date(start);
    while (current <= end) {
        const dateStr = toDateStr(current);
        const libur = props.hariLiburs.find((h) => h.tanggal === dateStr);
        if (libur) {
            hasil.push(libur);
        }
        current.setDate(current.getDate() + 1);
    }
    return hasil;
});
// ================= END LOGIKA HARI LIBUR =================


// Kalkulasi estimasi hari secara real-time TANPA hari Sabtu, Minggu,
// DAN tanpa tanggal Libur Nasional / Cuti Bersama (lihat hariLiburSet di
// atas). Identik dengan rumus hitungHariKerja() di CutiController.php
// (backend) supaya angka yang tampil di form SAMA PERSIS dengan yang akan
// dihitung ulang & disimpan saat submit.
const estimasiHari = computed(() => {
    if (!form.tanggal_mulai || !form.tanggal_selesai) return 0;


    let start = new Date(form.tanggal_mulai);
    let end = new Date(form.tanggal_selesai);


    // Pastikan start tidak lebih besar dari end
    if (start > end) return 0;


    let count = 0;
    let current = new Date(start);


    // Looping setiap hari dari start sampai end
    while (current <= end) {
        const dayOfWeek = current.getDay();
        const dateStr = toDateStr(current);
        const isWeekend = dayOfWeek === 0 || dayOfWeek === 6; // 0 = Minggu, 6 = Sabtu


        // Hitung hari ini HANYA jika bukan akhir pekan DAN bukan hari libur
        // nasional/cuti bersama.
        if (!isWeekend && !isHariLibur(dateStr)) {
            count++;
        }
        current.setDate(current.getDate() + 1);
    }


    return count;
});


// SALDO SAAT INI: prioritaskan props dari controller (sudah dihitung akurat
// & konsisten dengan Dashboard). Fallback ke data auth global, lalu 0 —
// BUKAN angka hardcode, supaya tidak menyesatkan untuk user lain.
const saldoSaatIni = computed(() => {
    return (
        props.sisa_cuti ??
        props.total_cuti_tersedia ??
        page.props.auth?.user?.sisa_cuti ??
        page.props.auth?.user?.total_cuti_tersedia ??
        0
    );
});


// Hanya Cuti Tahunan yang memotong Saldo Cuti
const sisaSetelahPengajuan = computed(() => {
    if (form.jenis_cuti === "Cuti Tahunan") {
        return saldoSaatIni.value - estimasiHari.value;
    }
    return saldoSaatIni.value; // Jenis cuti lain tidak memotong saldo
});


// ================= LOGIKA KALENDER MINI (SIDEBAR) =================
// Kalender kecil di sidebar, MURNI sebagai tampilan visual tambahan —
// TIDAK menggantikan input <input type="date"> Tanggal Mulai/Selesai di
// atas (input tanggal tetap jadi satu-satunya cara mengisi form).
// Kalender ini menandai: akhir pekan, hari libur (pakai hariLiburSet &
// isHariLibur yang sudah ada), rentang tanggal yang sedang dipilih, dan
// hari ini.
const namaHariMini = ["Min", "Sen", "Sel", "Rab", "Kam", "Jum", "Sab"];

// Bulan yang sedang ditampilkan di kalender mini (disimpan sebagai
// tanggal 1 pada bulan tsb). Default: bulan berjalan.
const calendarMonth = ref(
    new Date(new Date().getFullYear(), new Date().getMonth(), 1),
);

const calendarLabel = computed(() =>
    calendarMonth.value.toLocaleDateString("id-ID", {
        month: "long",
        year: "numeric",
    }),
);

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

// Begitu pegawai memilih Tanggal Mulai, kalender otomatis pindah ke
// bulan tsb supaya langsung menampilkan konteks yang relevan.
watch(
    () => form.tanggal_mulai,
    (val) => {
        if (val) {
            const d = new Date(val);
            calendarMonth.value = new Date(d.getFullYear(), d.getMonth(), 1);
        }
    },
);

// Grid tanggal untuk bulan yang sedang ditampilkan. Sel kosong (null) di
// awal array supaya kolom tanggal sejajar dengan header Min-Sab.
const calendarDays = computed(() => {
    const year = calendarMonth.value.getFullYear();
    const month = calendarMonth.value.getMonth();

    const firstDay = new Date(year, month, 1);
    const totalDaysInMonth = new Date(year, month + 1, 0).getDate();
    const leadingBlanks = firstDay.getDay(); // 0 (Minggu) - 6 (Sabtu)

    const cells = [];
    for (let i = 0; i < leadingBlanks; i++) {
        cells.push(null);
    }

    for (let day = 1; day <= totalDaysInMonth; day++) {
        const date = new Date(year, month, day);
        const dateStr = toDateStr(date);
        const dayOfWeek = date.getDay();

        let inRange = false;
        if (form.tanggal_mulai && form.tanggal_selesai) {
            inRange =
                dateStr >= form.tanggal_mulai &&
                dateStr <= form.tanggal_selesai;
        }

        cells.push({
            day,
            dateStr,
            isWeekend: dayOfWeek === 0 || dayOfWeek === 6,
            isHoliday: isHariLibur(dateStr),
            isToday: dateStr === todayStr.value,
            isRangeStart: dateStr === form.tanggal_mulai,
            isRangeEnd: dateStr === form.tanggal_selesai,
            inRange,
        });
    }

    return cells;
});

// Daftar hari libur terdekat (mulai hari ini dan seterusnya, diurutkan naik),
// ditampilkan sebagai pelengkap di bawah kalender mini supaya pegawai bisa
// langsung lihat tanggal merah/cuti bersama berikutnya tanpa harus
// menavigasi kalender.
const hariLiburTerdekat = computed(() => {
    return [...props.hariLiburs]
        .filter((h) => h.tanggal >= todayStr.value)
        .sort((a, b) => a.tanggal.localeCompare(b.tanggal))
        .slice(0, 3);
});

// Helper: format 'YYYY-MM-DD' menjadi "01 Okt 2026" (format tanggal Indonesia
// singkat, dua digit hari + nama bulan singkatan + tahun).
const namaBulanSingkat = [
    "Jan", "Feb", "Mar", "Apr", "Mei", "Jun",
    "Jul", "Agu", "Sep", "Okt", "Nov", "Des",
];
const formatTanggalLibur = (dateStr) => {
    const d = new Date(dateStr);
    const dd = String(d.getDate()).padStart(2, "0");
    return `${dd} ${namaBulanSingkat[d.getMonth()]} ${d.getFullYear()}`;
};
// ================= END LOGIKA KALENDER MINI =================


const submit = () => {
    if (!form.jenis_cuti) {
        Swal.fire({
            icon: "error",
            title: "Jenis cuti belum dipilih",
            text: "Silakan pilih jenis cuti terlebih dahulu.",
            confirmButtonColor: "#ef4444",
        });
        return;
    }


    // Jaga-jaga di sisi frontend: tolak submit kalau entah bagaimana
    // "Cuti Besar" masih terpilih padahal syarat masa kerja 5 tahun
    // belum terpenuhi (mis. sempat terpilih sebelum data NIP termuat).
    // Penjaga TERAKHIR & PALING SAH tetap validasi di
    // CutiController@store (backend), karena opsi <option disabled>
    // di HTML tetap bisa dilewati lewat inspect element.
    if (form.jenis_cuti === "Cuti Besar" && !canChooseCutiBesar.value) {
        Swal.fire({
            icon: "error",
            title: "Belum memenuhi syarat",
            text: `Cuti Besar hanya dapat diajukan jika masa kerja sudah mencapai 5 tahun. Masa kerja Anda saat ini terhitung ${masaKerjaTahun.value} tahun.`,
            confirmButtonColor: "#ef4444",
        });
        return;
    }


    // Kosongkan form.anak_ke jika jenis cuti bukan Melahirkan
    if (form.jenis_cuti !== "Cuti Melahirkan") {
        form.anak_ke = "";
    }


    form.post(route("karyawan.ajukan.store"), {
        forceFormData: true, // Wajib karena ada file (lampiran) yang dikirim
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            form.jenis_cuti = "Cuti Tahunan";
            gunakanAlamatDomisili.value = false;
            gunakanNomorTeleponProfil.value = false;
            Swal.fire({
                icon: "success",
                title: "Pengajuan Berhasil!",
                text: "Permohonan cuti Anda berhasil dikirim dan sedang menunggu persetujuan Atasan.",
                confirmButtonColor: "#10b981",
                confirmButtonText: "OK",
            });
        },
    });
};
</script>


<template>
    <Head title="Ajukan Cuti" />


    <MainLayout>
        <div class="max-w-6xl mx-auto space-y-6 pb-12">
            <div>
                <h1
                    class="text-2xl md:text-3xl font-bold text-slate-800 tracking-tight"
                >
                    Formulir Pengajuan Cuti
                </h1>
                <p class="text-slate-500 mt-1 text-sm">
                    Silakan lengkapi data di bawah ini untuk mengajukan
                    permohonan cuti baru.
                </p>
            </div>


            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-stretch">
                <div
                    class="lg:col-span-2 bg-white p-6 md:p-8 rounded-2xl shadow-sm border border-slate-200 h-full flex flex-col justify-between"
                >
                    <div>
                        <div
                            v-if="
                                form.errors.tanggal_mulai &&
                                form.errors.tanggal_mulai.includes('Saldo')
                            "
                            class="mb-6 p-4 bg-red-50 text-red-700 rounded-xl border border-red-200 flex items-start gap-3"
                        >
                            <svg
                                class="w-5 h-5 text-red-500 mt-0.5"
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
                            <span class="text-sm font-semibold">{{
                                form.errors.tanggal_mulai
                            }}</span>
                        </div>


                        <!-- BARU: Peringatan khusus dari backend kalau validasi masa
                             kerja Cuti Besar ditolak oleh CutiController@store -->
                        <div
                            v-if="form.errors.jenis_cuti"
                            class="mb-6 p-4 bg-red-50 text-red-700 rounded-xl border border-red-200 flex items-start gap-3"
                        >
                            <svg
                                class="w-5 h-5 text-red-500 mt-0.5"
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
                            <span class="text-sm font-semibold">{{
                                form.errors.jenis_cuti
                            }}</span>
                        </div>


                        <form @submit.prevent="submit" class="space-y-6">
                            <div>
                                <label
                                    class="block text-sm font-semibold text-slate-700 mb-2"
                                    >Jenis Cuti
                                    <span class="text-red-500">*</span></label
                                >
                                <select
                                    v-model="form.jenis_cuti"
                                    class="w-full text-sm border-slate-200 rounded-xl focus:ring-green-500 focus:border-green-500 py-2.5 px-3 bg-white"
                                >
                                    <option value="Cuti Tahunan">
                                        Cuti Tahunan
                                    </option>
                                    <option value="Cuti Melahirkan">
                                        Cuti Melahirkan
                                    </option>
                                    <!-- Terkunci (disabled) kalau masa kerja belum
                                         genap 5 tahun, dihitung dari NIP. Browser
                                         otomatis menampilkan opsi disabled ini
                                         berwarna abu-abu. -->
                                    <option
                                        value="Cuti Besar"
                                        :disabled="!canChooseCutiBesar"
                                    >
                                        Cuti Besar{{
                                            !canChooseCutiBesar
                                                ? " (Belum memenuhi syarat masa kerja 5 tahun)"
                                                : ""
                                        }}
                                    </option>
                                    <option value="Cuti Alasan Penting">
                                        Cuti Alasan Penting
                                    </option>
                                </select>
                                <span
                                    v-if="form.errors.jenis_cuti"
                                    class="text-xs text-red-500 mt-1 block"
                                >
                                    {{ form.errors.jenis_cuti }}
                                </span>
                                <!-- BARU: keterangan info (bukan error) supaya pegawai
                                     paham kenapa Cuti Besar terkunci, ditampilkan
                                     hanya kalau belum ada error validasi lain di
                                     field yang sama -->
                                <span
                                    v-else-if="!canChooseCutiBesar"
                                    class="text-xs text-slate-400 mt-1 block"
                                >
                                    Opsi "Cuti Besar" terkunci karena masa kerja
                                    Anda saat ini baru
                                    {{ masaKerjaTahun }} tahun (syarat minimal 5
                                    tahun).
                                </span>
                            </div>


                            <div
                                v-if="form.jenis_cuti === 'Cuti Melahirkan'"
                                class="animate-in fade-in duration-300"
                            >
                                <label
                                    class="block text-sm font-semibold text-slate-700 mb-2"
                                >
                                    Anak ke-berapa
                                    <span class="text-red-500">*</span>
                                </label>
                                <input
                                    v-model="form.anak_ke"
                                    type="number"
                                    min="1"
                                    required
                                    placeholder="Contoh: 1, 2, atau 3"
                                    class="w-full md:w-1/2 text-sm border-slate-200 rounded-xl focus:ring-green-500 focus:border-green-500 py-2.5 px-3"
                                />
                            </div>


                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label
                                        class="block text-sm font-semibold text-slate-700 mb-2"
                                        >Tanggal Mulai
                                        <span class="text-red-500"
                                            >*</span
                                        ></label
                                    >
                                    <input
                                        v-model="form.tanggal_mulai"
                                        type="date"
                                        :min="todayStr"
                                        required
                                        class="w-full text-sm border-slate-200 rounded-xl focus:ring-green-500 focus:border-green-500 py-2.5 px-3"
                                    />
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
                                </div>
                                <div>
                                    <label
                                        class="block text-sm font-semibold text-slate-700 mb-2"
                                        >Tanggal Selesai
                                        <span class="text-red-500"
                                            >*</span
                                        ></label
                                    >
                                    <input
                                        v-model="form.tanggal_selesai"
                                        type="date"
                                        :min="form.tanggal_mulai || todayStr"
                                        required
                                        class="w-full text-sm border-slate-200 rounded-xl focus:ring-green-500 focus:border-green-500 py-2.5 px-3"
                                    />
                                    <span
                                        v-if="form.errors.tanggal_selesai"
                                        class="text-xs text-red-500 mt-1 block"
                                    >
                                        {{ form.errors.tanggal_selesai }}
                                    </span>
                                </div>
                            </div>


                            <div class="space-y-2">
                                <div
                                    class="flex items-center justify-between bg-slate-50 border border-slate-200 p-4 rounded-xl"
                                >
                                    <div
                                        class="flex items-center gap-2 text-slate-600"
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
                                                d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"
                                            ></path>
                                        </svg>
                                        <span class="text-sm font-medium"
                                            >Estimasi Jumlah Hari Kerja</span
                                        >
                                    </div>
                                    <span
                                        class="text-base font-bold text-green-700"
                                        >{{ estimasiHari }} Hari</span
                                    >
                                </div>


                                <!-- BARU: Info transparan tanggal libur yang dikecualikan
                                     dari perhitungan, supaya pegawai paham kenapa jumlah
                                     harinya lebih sedikit dari selisih tanggal biasa. -->
                                <div
                                    v-if="hariLiburDalamRentang.length > 0"
                                    class="flex items-start gap-2 bg-blue-50 border border-blue-100 rounded-xl px-3.5 py-2.5"
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
                                            >{{
                                                hariLiburDalamRentang.length
                                            }}
                                            tanggal tidak dihitung</span
                                        >
                                        karena bertepatan dengan hari libur:
                                        <span
                                            v-for="(
                                                libur, idx
                                            ) in hariLiburDalamRentang"
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
                                                    idx <
                                                    hariLiburDalamRentang.length -
                                                        1
                                                "
                                                >,
                                            </span>
                                        </span>
                                    </p>
                                </div>


                                <p
                                    v-if="
                                        estimasiHari === 0 &&
                                        form.tanggal_mulai &&
                                        form.tanggal_selesai &&
                                        !isPastDate
                                    "
                                    class="text-xs font-bold text-red-500 animate-pulse"
                                >
                                    *Tanggal yang dipilih tidak valid karena
                                    hanya mencakup akhir pekan (Sabtu/Minggu)
                                    dan/atau hari libur nasional/cuti bersama.
                                </p>


                                <p
                                    v-if="isPastDate"
                                    class="text-xs font-bold text-red-500 animate-pulse"
                                >
                                    *Peringatan: Anda tidak dapat mengajukan
                                    cuti untuk tanggal yang sudah lewat.
                                </p>
                            </div>


                            <div>
                                <label
                                    class="block text-sm font-semibold text-slate-700 mb-2"
                                    >Keterangan / Alasan Cuti
                                    <span class="text-red-500">*</span></label
                                >
                                <textarea
                                    v-model="form.keterangan"
                                    rows="3"
                                    required
                                    placeholder="Jelaskan alasan atau keperluan cuti Anda secara detail..."
                                    class="w-full text-sm border-slate-200 rounded-xl focus:ring-green-500 focus:border-green-500 py-2.5 px-3 resize-none"
                                ></textarea>
                                <span
                                    v-if="form.errors.keterangan"
                                    class="text-xs text-red-500 mt-1 block"
                                    >{{ form.errors.keterangan }}</span
                                >
                            </div>


                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <!-- Bagian Alamat Selama Cuti + Checkbox Alamat Domisili -->
                                <div>
                                    <div
                                        class="flex items-center justify-between mb-2"
                                    >
                                        <label
                                            class="block text-sm font-semibold text-slate-700"
                                        >
                                            Alamat Selama Cuti
                                            <span class="text-red-500">*</span>
                                        </label>
                                        <label
                                            class="flex items-center gap-2 text-xs font-medium text-slate-600 cursor-pointer"
                                        >
                                            <input
                                                type="checkbox"
                                                v-model="gunakanAlamatDomisili"
                                                @change="toggleAlamatDomisili"
                                                class="rounded border-slate-300 text-green-600 focus:ring-green-500"
                                            />
                                            Sesuai alamat domisili di profil
                                        </label>
                                    </div>
                                    <textarea
                                        v-model="form.alamat_cuti"
                                        rows="2"
                                        required
                                        :disabled="gunakanAlamatDomisili"
                                        placeholder="Contoh: Jl. Merdeka No. 10..."
                                        class="w-full text-sm border-slate-200 rounded-xl focus:ring-green-500 focus:border-green-500 py-2.5 px-3 resize-none disabled:bg-slate-50 disabled:text-slate-500"
                                    ></textarea>
                                    <span
                                        v-if="form.errors.alamat_cuti"
                                        class="text-xs text-red-500 mt-1 block"
                                        >{{ form.errors.alamat_cuti }}</span
                                    >
                                </div>


                                <!-- Bagian No. Telepon / HP + Checkbox Nomor Telepon Profil -->
                                <div>
                                    <div
                                        class="flex items-center justify-between mb-2"
                                    >
                                        <label
                                            class="block text-sm font-semibold text-slate-700"
                                        >
                                            No. Telepon / HP
                                            <span class="text-red-500">*</span>
                                        </label>
                                        <label
                                            class="flex items-center gap-2 text-xs font-medium text-slate-600 cursor-pointer"
                                        >
                                            <input
                                                type="checkbox"
                                                v-model="
                                                    gunakanNomorTeleponProfil
                                                "
                                                @change="
                                                    toggleNomorTeleponProfil
                                                "
                                                class="rounded border-slate-300 text-green-600 focus:ring-green-500"
                                            />
                                            Sesuai nomor telepon di profil
                                        </label>
                                    </div>
                                    <input
                                        v-model="form.no_telp"
                                        type="tel"
                                        required
                                        :disabled="gunakanNomorTeleponProfil"
                                        placeholder="Contoh: 081234567890"
                                        class="w-full text-sm border-slate-200 rounded-xl focus:ring-green-500 focus:border-green-500 py-2.5 px-3 disabled:bg-slate-50 disabled:text-slate-500"
                                    />
                                    <span
                                        v-if="form.errors.no_telp"
                                        class="text-xs text-red-500 mt-1 block"
                                        >{{ form.errors.no_telp }}</span
                                    >
                                </div>
                            </div>


                            <!-- Tambahan Baru: Input Lampiran File (Opsional) -->
                            <div>
                                <label
                                    class="block text-sm font-semibold text-slate-700 mb-2"
                                >
                                    Lampiran Dokumen
                                    <span class="text-slate-400 font-normal"
                                        >(Opsional: PDF/Gambar, Maks 2MB)</span
                                    >
                                </label>
                                <input
                                    type="file"
                                    @input="
                                        form.lampiran = $event.target.files[0]
                                    "
                                    accept=".pdf,.jpg,.jpeg,.png"
                                    class="w-full text-sm text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-semibold file:bg-green-50 file:text-green-700 hover:file:bg-green-100 border border-slate-200 rounded-xl cursor-pointer p-1"
                                />
                                <span
                                    v-if="form.errors.lampiran"
                                    class="text-xs text-red-500 mt-1 block"
                                    >{{ form.errors.lampiran }}</span
                                >
                            </div>
                        </form>
                    </div>


                    <div
                        class="flex items-center justify-end gap-3 pt-6 mt-6 border-t border-slate-100"
                    >
                        <button
                            type="button"
                            @click="
                                form.reset();
                                gunakanAlamatDomisili = false;
                                gunakanNomorTeleponProfil = false;
                            "
                            class="px-5 py-2.5 border border-slate-300 text-slate-600 rounded-xl text-sm font-semibold hover:bg-slate-50 transition"
                        >
                            Batal
                        </button>
                        <button
                            @click="submit"
                            :disabled="
                                form.processing ||
                                estimasiHari === 0 ||
                                isPastDate ||
                                (form.jenis_cuti === 'Cuti Tahunan' &&
                                    sisaSetelahPengajuan < 0) ||
                                (form.jenis_cuti === 'Cuti Besar' &&
                                    !canChooseCutiBesar)
                            "
                            class="px-6 py-2.5 bg-green-600 hover:bg-green-700 text-white rounded-xl text-sm font-semibold shadow-md shadow-green-600/20 transition disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            Ajukan Cuti
                        </button>
                    </div>
                </div>


                <div class="lg:col-span-1 flex flex-col gap-6">
                    <div
                        class="bg-white p-5 rounded-2xl shadow-sm border border-slate-200"
                    >
                        <div class="flex items-center gap-2 mb-4">
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
                                    d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"
                                ></path>
                            </svg>
                            <h3 class="text-base font-bold text-slate-800">
                                Ringkasan Saldo
                            </h3>
                        </div>


                        <div class="space-y-3">
                            <div
                                class="flex justify-between items-center text-sm"
                            >
                                <span class="text-slate-600"
                                    >Sisa Saldo Saat Ini</span
                                >
                                <span class="font-bold text-slate-800"
                                    >{{ saldoSaatIni }} Hari</span
                                >
                            </div>
                            <div
                                class="flex justify-between items-center text-sm border-b border-slate-100 pb-3"
                            >
                                <span class="text-slate-600"
                                    >Estimasi Pengajuan</span
                                >
                                <span
                                    class="font-bold"
                                    :class="
                                        form.jenis_cuti === 'Cuti Tahunan'
                                            ? 'text-red-500'
                                            : 'text-slate-500'
                                    "
                                    >{{
                                        form.jenis_cuti === "Cuti Tahunan"
                                            ? estimasiHari
                                            : 0
                                    }}
                                    Hari</span
                                >
                            </div>
                            <div
                                class="flex justify-between items-center text-sm bg-green-50 p-3 rounded-lg border border-green-100"
                            >
                                <span class="font-semibold text-green-800"
                                    >Sisa Setelah Pengajuan</span
                                >
                                <span
                                    class="font-bold text-green-700 text-base"
                                    :class="{
                                        'text-red-600':
                                            sisaSetelahPengajuan < 0,
                                    }"
                                >
                                    {{ sisaSetelahPengajuan }} Hari
                                </span>
                            </div>
                            <div
                                v-if="form.jenis_cuti !== 'Cuti Tahunan'"
                                class="text-[10px] text-center text-slate-400 font-semibold italic mt-2"
                            >
                                *Jenis cuti ini tidak memotong saldo cuti
                                tahunan.
                            </div>
                        </div>
                    </div>


                    <!-- BARU: Kalender Mini — tampilan visual saja, tidak
                         menggantikan input tanggal di form. Diletakkan tepat
                         di bawah kartu Ringkasan Saldo. -->
                    <div
                        class="bg-white p-5 rounded-2xl shadow-sm border border-slate-200"
                    >
                        <div class="flex items-center justify-between mb-4">
                            <button
                                type="button"
                                @click="prevCalendarMonth"
                                class="w-7 h-7 flex items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 transition"
                                aria-label="Bulan sebelumnya"
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
                            <h3
                                class="text-sm font-bold text-slate-800 capitalize"
                            >
                                {{ calendarLabel }}
                            </h3>
                            <button
                                type="button"
                                @click="nextCalendarMonth"
                                class="w-7 h-7 flex items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 transition"
                                aria-label="Bulan berikutnya"
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


                        <div class="grid grid-cols-7 gap-y-1 text-center">
                            <span
                                v-for="hari in namaHariMini"
                                :key="hari"
                                class="text-[10px] font-semibold text-slate-400 py-1"
                            >
                                {{ hari }}
                            </span>


                            <template
                                v-for="(cell, idx) in calendarDays"
                                :key="idx"
                            >
                                <div v-if="!cell" class="h-7"></div>
                                <div
                                    v-else
                                    class="h-7 flex items-center justify-center text-xs rounded-lg"
                                    :class="[
                                        cell.isRangeStart || cell.isRangeEnd
                                            ? 'bg-green-600 text-white font-bold'
                                            : cell.inRange
                                            ? 'bg-green-100 text-green-700 font-semibold'
                                            : cell.isHoliday
                                            ? 'bg-red-50 text-red-600 font-semibold'
                                            : cell.isWeekend
                                            ? 'text-slate-400'
                                            : 'text-slate-600',
                                        cell.isToday &&
                                        !cell.isRangeStart &&
                                        !cell.isRangeEnd
                                            ? 'ring-1 ring-green-500'
                                            : '',
                                    ]"
                                >
                                    {{ cell.day }}
                                </div>
                            </template>
                        </div>


                        <div
                            class="flex flex-wrap items-center gap-x-3 gap-y-1.5 mt-4 pt-3 border-t border-slate-100"
                        >
                            <div class="flex items-center gap-1.5">
                                <span
                                    class="w-2.5 h-2.5 rounded-full bg-green-600"
                                ></span>
                                <span class="text-[10px] text-slate-500"
                                    >Rentang dipilih</span
                                >
                            </div>
                            <div class="flex items-center gap-1.5">
                                <span
                                    class="w-2.5 h-2.5 rounded-full bg-red-400"
                                ></span>
                                <span class="text-[10px] text-slate-500"
                                    >Hari libur</span
                                >
                            </div>
                            <div class="flex items-center gap-1.5">
                                <span
                                    class="w-2.5 h-2.5 rounded-full border border-green-500"
                                ></span>
                                <span class="text-[10px] text-slate-500"
                                    >Hari ini</span
                                >
                            </div>
                        </div>
                    </div>

                    <!-- BARU: Hari Libur Terdekat — mengisi ruang kosong di
                         bawah kalender mini, memakai data hariLiburs yang
                         sama dengan yang dipakai di form. -->
                    <div
                        class="bg-white p-5 rounded-2xl shadow-sm border border-slate-200"
                    >
                        <h3 class="text-sm font-bold text-slate-800 mb-4">
                            Hari Libur Terdekat
                        </h3>

                        <div
                            v-if="hariLiburTerdekat.length > 0"
                            class="space-y-2.5"
                        >
                            <div
                                v-for="libur in hariLiburTerdekat"
                                :key="libur.tanggal"
                                class="flex items-center gap-3 bg-slate-50 rounded-xl p-3"
                            >
                                <div
                                    class="w-9 h-9 shrink-0 rounded-lg bg-blue-50 flex items-center justify-center"
                                >
                                    <svg
                                        class="w-4 h-4 text-blue-500"
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
                                </div>
                                <div>
                                    <p
                                        class="text-xs font-bold text-slate-800 leading-snug"
                                    >
                                        {{ libur.keterangan }}
                                    </p>
                                    <p class="text-[11px] text-slate-400">
                                        {{ formatTanggalLibur(libur.tanggal) }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <p
                            v-else
                            class="text-xs text-slate-400 text-center py-2"
                        >
                            Tidak ada hari libur dalam waktu dekat.
                        </p>
                    </div>
                </div>
            </div>

            <!-- DIPINDAH dari sidebar kanan: Ketentuan Pengajuan & Alur
                 Persetujuan Staf. Ditaruh sebagai baris tersendiri full-width
                 di bawah form + sidebar, supaya sidebar kanan tidak menumpuk
                 dan tombol "Ajukan Cuti" tetap langsung di bawah form. -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div
                    class="bg-slate-900 p-5 rounded-2xl shadow-sm border border-slate-800"
                >
                    <div class="flex items-center gap-2 mb-3">
                        <svg
                            class="w-5 h-5 text-blue-400"
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
                        <h3 class="text-sm font-bold text-white">
                            Ketentuan Pengajuan
                        </h3>
                    </div>
                    <ul
                        class="text-xs text-slate-300 space-y-2 list-disc pl-4 marker:text-slate-500"
                    >
                        <li>
                            Tidak ada batasan waktu minimum pengajuan, pegawai
                            dapat mengajukan cuti darurat kapan saja.
                        </li>
                        <li>
                            Pengajuan cuti yang jatuh pada akhir pekan atau
                            hari libur nasional tidak akan dihitung sebagai
                            hari cuti.
                        </li>
                        <li>
                            Sisa cuti tahunan yang tidak terpakai dapat
                            diakumulasikan ke tahun berikutnya (maksimal 6
                            hari).
                        </li>
                        <li>
                            Cuti Besar hanya dapat diajukan oleh pegawai
                            dengan masa kerja minimal 5 tahun.
                        </li>
                    </ul>
                </div>

                <div
                    class="bg-blue-50 p-5 rounded-2xl shadow-sm border border-blue-100"
                >
                    <div class="flex items-center gap-2 mb-4">
                        <svg
                            class="w-5 h-5 text-blue-600"
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
                        <h3 class="text-sm font-bold text-blue-900">
                            Alur Persetujuan Staf
                        </h3>
                    </div>

                    <!-- Ditata mendatar (bukan bertumpuk vertikal seperti
                         sebelumnya) supaya proporsional dengan tinggi kartu
                         "Ketentuan Pengajuan" di sebelahnya. -->
                    <div
                        class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 sm:gap-2"
                    >
                        <div
                            class="flex flex-1 items-center gap-2 text-center sm:text-left"
                        >
                            <div
                                class="flex-1 flex flex-col sm:flex-row items-center gap-2"
                            >
                                <div class="flex flex-col items-center gap-1">
                                    <div
                                        class="w-7 h-7 rounded-full bg-blue-500 flex items-center justify-center text-white text-xs font-bold shrink-0"
                                    >
                                        1
                                    </div>
                                    <div>
                                        <p
                                            class="text-xs font-bold text-blue-900"
                                        >
                                            Ketua Tim Kerja
                                        </p>
                                        <p class="text-[11px] text-blue-700">
                                            Atasan Langsung (L1)
                                        </p>
                                    </div>
                                </div>

                                <div
                                    class="hidden sm:block flex-1 h-0.5 bg-blue-200 rounded-full"
                                ></div>

                                <div class="flex flex-col items-center gap-1">
                                    <div
                                        class="w-7 h-7 rounded-full bg-blue-400 flex items-center justify-center text-white text-xs font-bold shrink-0"
                                    >
                                        2
                                    </div>
                                    <div>
                                        <p
                                            class="text-xs font-bold text-blue-900"
                                        >
                                            Kasubag TU
                                        </p>
                                        <p class="text-[11px] text-blue-700">
                                            (L3)
                                        </p>
                                    </div>
                                </div>

                                <div
                                    class="hidden sm:block flex-1 h-0.5 bg-blue-200 rounded-full"
                                ></div>

                                <div class="flex flex-col items-center gap-1">
                                    <div
                                        class="w-7 h-7 rounded-full bg-blue-300 flex items-center justify-center text-white text-xs font-bold shrink-0"
                                    >
                                        3
                                    </div>
                                    <div>
                                        <p
                                            class="text-xs font-bold text-blue-900"
                                        >
                                            Kepala Biro
                                        </p>
                                        <p class="text-[11px] text-blue-700">
                                            Perencanaan (L4)
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </MainLayout>
</template>