<script setup>
import MainLayout from "@/Layouts/MainLayout.vue";
import { Head, useForm, usePage } from "@inertiajs/vue3";
import { computed, ref } from "vue";

const page = usePage();
const user = computed(() => page.props.auth.user);

// Badge status kepegawaian (PPPK ditampilkan sebagai P3K)
const badgeKepegawaian = computed(() => {
    const nilai = user.value?.status_kepegawaian;
    switch (nilai) {
        case "PNS":
            return {
                label: "PNS",
                class: "text-indigo-700 bg-indigo-50 border-indigo-100",
            };
        case "PPPK":
            return {
                label: "P3K",
                class: "text-amber-700 bg-amber-50 border-amber-100",
            };
        default:
            return null;
    }
});

// Jabatan hanya ditampilkan sebagai badge kalau ada isinya dan tidak sama
// dengan nama (mis. akun Admin HR yang jabatannya sama dengan namanya).
const tampilBadgeJabatan = computed(
    () => !!user.value?.jabatan && user.value.jabatan !== user.value.nama,
);

// Setup Form Data Diri (Menambahkan alamat_domisili & no_telepon)
const profileForm = useForm({
    _method: "patch",
    nama: user.value.nama,
    alamat_domisili: user.value.alamat_domisili || "", // Kolom agar bisa diedit
    no_telepon: user.value.no_telepon || "", // Kolom baru: Nomor Telepon
    foto_profil: null,
});

// Setup Form Ubah Password
const passwordForm = useForm({
    current_password: "",
    password: "",
    password_confirmation: "",
});

// --- TOMBOL MATA (tampilkan / sembunyikan kata sandi) ---
const showCurrentPassword = ref(false);
const showNewPassword = ref(false);
const showConfirmPassword = ref(false);

// --- INDIKATOR KEKUATAN KATA SANDI ---
// Skor 0-4: panjang >= 8, huruf besar & kecil, angka, simbol.
const passwordStrength = computed(() => {
    const p = passwordForm.password || "";
    if (!p) return { score: 0, label: "", textClass: "", barClass: "" };

    let score = 0;
    if (p.length >= 8) score++;
    if (/[a-z]/.test(p) && /[A-Z]/.test(p)) score++;
    if (/\d/.test(p)) score++;
    if (/[^A-Za-z0-9]/.test(p)) score++;

    if (score <= 1) {
        return {
            score: 1,
            label: "Lemah",
            textClass: "text-red-600",
            barClass: "bg-red-400",
        };
    }
    if (score === 2) {
        return {
            score: 2,
            label: "Cukup",
            textClass: "text-amber-600",
            barClass: "bg-amber-400",
        };
    }
    if (score === 3) {
        return {
            score: 3,
            label: "Baik",
            textClass: "text-lime-600",
            barClass: "bg-lime-500",
        };
    }
    return {
        score: 4,
        label: "Kuat",
        textClass: "text-emerald-600",
        barClass: "bg-emerald-500",
    };
});

// --- FITUR PREVIEW FOTO PROFIL ---
const photoInput = ref(null);
const photoPreview = ref(null);

const selectNewPhoto = () => {
    photoInput.value.click();
};

const updatePhotoPreview = () => {
    const photo = photoInput.value.files[0];
    if (!photo) return;
    profileForm.foto_profil = photo;
    const reader = new FileReader();
    reader.onload = (e) => {
        photoPreview.value = e.target.result;
    };
    reader.readAsDataURL(photo);
};

const cancelPhoto = () => {
    profileForm.foto_profil = null;
    photoPreview.value = null;
    if (photoInput.value) photoInput.value.value = "";
    profileForm.clearErrors();
};

const updateProfile = () => {
    profileForm.post(route("profile.update"), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            profileForm.clearErrors();
            profileForm.foto_profil = null;
            photoPreview.value = null;
        },
    });
};

const updatePassword = () => {
    passwordForm.put(route("password.update"), {
        preserveScroll: true,
        onSuccess: () => passwordForm.reset(),
    });
};
</script>

<template>
    <Head title="Pengaturan Akun" />
    <MainLayout>
        <div class="max-w-4xl mx-auto space-y-6">
            <!-- Header Halaman -->
            <div>
                <h1
                    class="text-2xl md:text-3xl font-bold text-slate-800 tracking-tight"
                >
                    Pengaturan Akun
                </h1>
                <p class="text-slate-500 mt-1 text-sm">
                    Kelola informasi data diri, foto profil, dan keamanan akun
                    Anda di sini.
                </p>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- KOLOM KIRI (Info Pegawai & Ganti Foto) -->
                <div class="md:col-span-1 space-y-6">
                    <div
                        class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200 text-center flex flex-col items-center relative overflow-hidden"
                    >
                        <!-- Header kartu: gradasi hijau + hiasan lingkaran + aksen emas -->
                        <div
                            class="absolute top-0 left-0 w-full h-24 bg-gradient-to-br from-emerald-900 via-emerald-800 to-green-700 z-0 overflow-hidden"
                        >
                            <div
                                class="absolute -top-8 -right-6 w-28 h-28 rounded-full bg-white/10"
                            ></div>
                            <div
                                class="absolute top-10 -left-8 w-24 h-24 rounded-full bg-white/5"
                            ></div>
                            <div
                                class="absolute -bottom-6 right-10 w-16 h-16 rounded-full bg-amber-400/25"
                            ></div>
                            <div
                                class="absolute bottom-0 left-0 w-full h-1 bg-gradient-to-r from-amber-400/0 via-amber-400/70 to-amber-400/0"
                            ></div>
                        </div>

                        <!-- Avatar + tombol kamera kecil -->
                        <div class="relative mt-8 z-10">
                            <div
                                class="w-28 h-28 rounded-full bg-slate-100 flex items-center justify-center border-4 border-white ring-2 ring-emerald-500 shadow-md text-slate-700 font-bold text-3xl overflow-hidden"
                            >
                                <img
                                    v-if="photoPreview"
                                    :src="photoPreview"
                                    class="w-full h-full object-cover"
                                />
                                <img
                                    v-else-if="user.foto_profil"
                                    :src="`/storage/${user.foto_profil}`"
                                    class="w-full h-full object-cover"
                                />
                                <span v-else>{{ user.nama.charAt(0) }}</span>
                            </div>
                            <button
                                v-if="!profileForm.foto_profil"
                                @click="selectNewPhoto"
                                type="button"
                                title="Ganti foto profil"
                                aria-label="Ganti foto profil"
                                class="absolute bottom-0 right-0 w-9 h-9 rounded-full bg-gradient-to-br from-green-500 to-green-600 text-white border-2 border-white shadow-md flex items-center justify-center hover:from-green-600 hover:to-green-700 transition focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2"
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
                                        d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"
                                    ></path>
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"
                                    ></path>
                                </svg>
                            </button>
                        </div>

                        <div class="mt-4 w-full">
                            <h3
                                class="text-lg font-bold text-slate-800 leading-tight"
                            >
                                {{ user.nama }}
                            </h3>
                            <p
                                class="text-sm font-semibold text-slate-500 mt-0.5"
                            >
                                {{ user.nip || "NIP belum diatur" }}
                            </p>
                            <div
                                v-if="
                                    badgeKepegawaian ||
                                    tampilBadgeJabatan ||
                                    user.kelompok_substansi
                                "
                                class="mt-3 flex flex-col items-center gap-1.5"
                            >
                                <!-- Badge Status Kepegawaian (PNS / P3K) -->
                                <span
                                    v-if="badgeKepegawaian"
                                    class="text-xs font-semibold border px-3 py-1.5 rounded-full w-full max-w-[200px] truncate"
                                    :class="badgeKepegawaian.class"
                                >
                                    {{ badgeKepegawaian.label }}
                                </span>
                                <span
                                    v-if="tampilBadgeJabatan"
                                    class="text-xs font-semibold text-green-700 bg-green-50 border border-green-100 px-3 py-1.5 rounded-full w-full max-w-[200px] truncate"
                                    :title="user.jabatan"
                                >
                                    {{ user.jabatan }}
                                </span>
                                <span
                                    v-if="user.kelompok_substansi"
                                    class="text-xs font-semibold text-blue-700 bg-blue-50 border border-blue-100 px-3 py-1.5 rounded-full w-full max-w-[200px] truncate"
                                    :title="user.kelompok_substansi"
                                >
                                    {{ user.kelompok_substansi }}
                                </span>
                            </div>
                        </div>
                        <input
                            id="foto-profil"
                            name="foto_profil"
                            type="file"
                            ref="photoInput"
                            class="hidden"
                            @change="updatePhotoPreview"
                            accept="image/*"
                        />
                        <!-- Tombol simpan/batal hanya muncul setelah memilih foto baru -->
                        <div
                            v-if="profileForm.foto_profil"
                            class="mt-6 w-full space-y-2"
                        >
                            <button
                                @click="updateProfile"
                                :disabled="profileForm.processing"
                                type="button"
                                class="w-full px-4 py-2 bg-gradient-to-r from-green-500 to-green-600 border border-green-700/20 text-white rounded-lg text-sm font-semibold hover:from-green-600 hover:to-green-700 transition flex items-center justify-center gap-2 shadow-md shadow-green-500/20 disabled:opacity-50"
                            >
                                <svg
                                    v-if="profileForm.processing"
                                    class="animate-spin h-4 w-4 text-white"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                >
                                    <circle
                                        class="opacity-25"
                                        cx="12"
                                        cy="12"
                                        r="10"
                                        stroke="currentColor"
                                        stroke-width="4"
                                    ></circle>
                                    <path
                                        class="opacity-75"
                                        fill="currentColor"
                                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                                    ></path>
                                </svg>
                                <span v-else>Simpan Foto Profil</span>
                            </button>
                            <button
                                @click="cancelPhoto"
                                :disabled="profileForm.processing"
                                type="button"
                                class="w-full px-4 py-2 bg-white border border-slate-200 text-slate-500 rounded-lg text-sm font-semibold hover:bg-slate-50 transition flex items-center justify-center disabled:opacity-50"
                            >
                                Batal
                            </button>
                        </div>
                        <p
                            v-else
                            class="mt-4 text-[11px] font-medium text-slate-400"
                        >
                            Klik ikon kamera untuk mengganti foto.
                        </p>
                        <div
                            v-if="profileForm.errors.foto_profil"
                            class="mt-3 p-2 bg-red-50 border border-red-200 rounded-lg text-xs text-red-600 text-left w-full"
                        >
                            {{ profileForm.errors.foto_profil }}
                        </div>
                        <div
                            v-if="
                                profileForm.recentlySuccessful && !photoPreview
                            "
                            class="mt-3 p-2 bg-green-50 border border-green-200 rounded-lg text-xs text-green-700 text-center w-full font-medium"
                        >
                            Foto berhasil diperbarui!
                        </div>
                    </div>
                </div>
                <!-- KOLOM KANAN (Form Update) -->
                <div class="md:col-span-2 space-y-6">
                    <!-- Form Data Diri -->
                    <div
                        class="bg-white p-4 sm:p-6 rounded-2xl shadow-sm border border-slate-200"
                    >
                        <div class="flex items-start gap-3 mb-5">
                            <div
                                class="w-9 h-9 shrink-0 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center"
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
                                        d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"
                                    ></path>
                                </svg>
                            </div>
                            <div>
                                <h3
                                    class="text-lg font-bold text-slate-800 leading-tight"
                                >
                                    Informasi Profil
                                </h3>
                                <p class="text-xs text-slate-500 mt-0.5">
                                    Perbarui data diri dan lihat data
                                    kepegawaian Anda.
                                </p>
                            </div>
                        </div>
                        <form
                            @submit.prevent="updateProfile"
                            id="profile-form"
                            class="space-y-4"
                        >
                            <!-- Input Nama (Full Width) -->
                            <div>
                                <label
                                    for="nama-lengkap"
                                    class="block text-sm font-medium text-slate-700 mb-1"
                                    >Nama Lengkap</label
                                >
                                <input
                                    id="nama-lengkap"
                                    name="nama"
                                    v-model="profileForm.nama"
                                    type="text"
                                    class="w-full min-h-[44px] rounded-lg border-slate-300 shadow-sm focus:border-green-500 focus:ring-green-500 text-base sm:text-sm py-2 px-3"
                                />
                                <span
                                    v-if="profileForm.errors.nama"
                                    class="text-xs text-red-500 mt-1"
                                    >{{ profileForm.errors.nama }}</span
                                >
                            </div>
                            <!-- Input Alamat Domisili (Bisa Diedit) -->
                            <div>
                                <label
                                    for="alamat-domisili"
                                    class="block text-sm font-medium text-slate-700 mb-1"
                                    >Alamat Domisili</label
                                >
                                <textarea
                                    id="alamat-domisili"
                                    name="alamat_domisili"
                                    v-model="profileForm.alamat_domisili"
                                    rows="2"
                                    placeholder="Contoh: Jl. Sudirman No. 10, Jakarta Selatan"
                                    class="w-full min-h-[72px] rounded-lg border-slate-300 shadow-sm focus:border-green-500 focus:ring-green-500 text-base sm:text-sm py-2 px-3 resize-y"
                                ></textarea>
                                <span
                                    v-if="profileForm.errors.alamat_domisili"
                                    class="text-xs text-red-500 mt-1"
                                    >{{
                                        profileForm.errors.alamat_domisili
                                    }}</span
                                >
                            </div>
                            <!-- Input Nomor Telepon (Baru) -->
                            <div>
                                <label
                                    for="nomor-telepon"
                                    class="block text-sm font-medium text-slate-700 mb-1"
                                    >Nomor Telepon</label
                                >
                                <input
                                    id="nomor-telepon"
                                    name="no_telepon"
                                    v-model="profileForm.no_telepon"
                                    type="text"
                                    placeholder="Contoh: 081234567890"
                                    class="w-full min-h-[44px] rounded-lg border-slate-300 shadow-sm focus:border-green-500 focus:ring-green-500 text-base sm:text-sm py-2 px-3"
                                />
                                <span
                                    v-if="profileForm.errors.no_telepon"
                                    class="text-xs text-red-500 mt-1"
                                    >{{ profileForm.errors.no_telepon }}</span
                                >
                            </div>

                            <!-- Data Kepegawaian (Hanya Baca) -->
                            <div class="pt-6 mt-4 border-t border-slate-100">
                                <p
                                    class="flex items-center gap-1.5 text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-3"
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
                                            d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"
                                        ></path>
                                    </svg>
                                    Data Kepegawaian (Hanya Baca)
                                </p>
                                <dl
                                    class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4 rounded-xl border border-slate-100 bg-slate-50/70 p-4"
                                >
                                    <div>
                                        <dt
                                            class="text-[11px] font-semibold text-slate-400 uppercase tracking-wide"
                                        >
                                            Nomor Induk Pegawai (NIP)
                                        </dt>
                                        <dd
                                            class="mt-0.5 text-sm font-semibold text-slate-800 break-words"
                                        >
                                            {{ user.nip || "-" }}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt
                                            class="text-[11px] font-semibold text-slate-400 uppercase tracking-wide"
                                        >
                                            Kelompok Substansi
                                        </dt>
                                        <dd
                                            class="mt-0.5 text-sm font-semibold text-slate-800 break-words"
                                        >
                                            {{ user.kelompok_substansi || "-" }}
                                        </dd>
                                    </div>
                                    <div class="sm:col-span-2">
                                        <dt
                                            class="text-[11px] font-semibold text-slate-400 uppercase tracking-wide"
                                        >
                                            Jabatan
                                        </dt>
                                        <dd
                                            class="mt-0.5 text-sm font-semibold text-slate-800 break-words"
                                        >
                                            {{ user.jabatan || "-" }}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt
                                            class="text-[11px] font-semibold text-slate-400 uppercase tracking-wide"
                                        >
                                            Tim Kerja
                                        </dt>
                                        <dd
                                            class="mt-0.5 text-sm font-semibold text-slate-800 break-words"
                                        >
                                            {{ user.tim_kerja || "-" }}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt
                                            class="text-[11px] font-semibold text-slate-400 uppercase tracking-wide"
                                        >
                                            Status Kepegawaian
                                        </dt>
                                        <dd
                                            class="mt-0.5 text-sm font-semibold text-slate-800 break-words"
                                        >
                                            {{ badgeKepegawaian?.label || "-" }}
                                        </dd>
                                    </div>
                                </dl>
                                <p class="mt-2 text-[11px] text-slate-400">
                                    Data ini dikelola oleh Admin SDM. Hubungi
                                    Admin SDM jika ada yang perlu diperbaiki.
                                </p>
                            </div>
                            <div
                                class="flex items-center justify-between pt-4 mt-4 border-t border-slate-100"
                            >
                                <span
                                    v-if="
                                        profileForm.recentlySuccessful &&
                                        !profileForm.foto_profil
                                    "
                                    class="text-sm text-green-600 font-medium"
                                    >Profil berhasil disimpan.</span
                                >
                                <span v-else></span>
                                <button
                                    type="submit"
                                    :disabled="profileForm.processing"
                                    class="min-h-[44px] w-full sm:w-auto px-5 py-2 bg-gradient-to-r from-green-500 to-green-600 text-white rounded-lg text-sm font-semibold hover:from-green-600 hover:to-green-700 shadow-md shadow-green-500/20 transition disabled:opacity-50"
                                >
                                    Simpan Perubahan
                                </button>
                            </div>
                        </form>
                    </div>
                    <!-- Form Ubah Password -->
                    <div
                        id="password"
                        class="bg-white p-4 sm:p-6 rounded-2xl shadow-sm border border-slate-200"
                    >
                        <div class="flex items-start gap-3 mb-5">
                            <div
                                class="w-9 h-9 shrink-0 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center"
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
                                        d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"
                                    ></path>
                                </svg>
                            </div>
                            <div>
                                <h3
                                    class="text-lg font-bold text-slate-800 leading-tight"
                                >
                                    Ubah Kata Sandi
                                </h3>
                                <p class="text-xs text-slate-500 mt-0.5">
                                    Pastikan akun Anda menggunakan kata sandi
                                    yang panjang dan sulit ditebak agar tetap
                                    aman.
                                </p>
                            </div>
                        </div>
                        <form
                            @submit.prevent="updatePassword"
                            class="space-y-4"
                        >
                            <!-- Kata Sandi Saat Ini -->
                            <div>
                                <label
                                    for="password-saat-ini"
                                    class="block text-sm font-medium text-slate-700 mb-1"
                                    >Kata Sandi Saat Ini</label
                                >
                                <div class="relative">
                                    <input
                                        id="password-saat-ini"
                                        name="current_password"
                                        v-model="passwordForm.current_password"
                                        :type="
                                            showCurrentPassword
                                                ? 'text'
                                                : 'password'
                                        "
                                        autocomplete="current-password"
                                        class="w-full min-h-[44px] rounded-lg border-slate-300 shadow-sm focus:border-green-500 focus:ring-green-500 text-base sm:text-sm py-2 pl-3 pr-11"
                                    />
                                    <button
                                        type="button"
                                        @click="
                                            showCurrentPassword =
                                                !showCurrentPassword
                                        "
                                        aria-label="Tampilkan atau sembunyikan kata sandi"
                                        class="absolute right-1 top-1/2 -translate-y-1/2 flex min-h-[40px] min-w-[40px] items-center justify-center text-slate-400 hover:text-slate-600 transition-colors"
                                    >
                                        <svg
                                            v-if="!showCurrentPassword"
                                            class="w-[18px] h-[18px]"
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
                                        <svg
                                            v-else
                                            class="w-[18px] h-[18px]"
                                            fill="none"
                                            stroke="currentColor"
                                            viewBox="0 0 24 24"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                stroke-width="2"
                                                d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.29 3.29m0 0a10.05 10.05 0 015.188-1.528c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"
                                            ></path>
                                        </svg>
                                    </button>
                                </div>
                                <span
                                    v-if="passwordForm.errors.current_password"
                                    class="text-xs text-red-500 mt-1"
                                    >{{
                                        passwordForm.errors.current_password
                                    }}</span
                                >
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <!-- Kata Sandi Baru -->
                                <div>
                                    <label
                                        for="password-baru"
                                        class="block text-sm font-medium text-slate-700 mb-1"
                                        >Kata Sandi Baru</label
                                    >
                                    <div class="relative">
                                        <input
                                            id="password-baru"
                                            name="password"
                                            v-model="passwordForm.password"
                                            :type="
                                                showNewPassword
                                                    ? 'text'
                                                    : 'password'
                                            "
                                            autocomplete="new-password"
                                            class="w-full min-h-[44px] rounded-lg border-slate-300 shadow-sm focus:border-green-500 focus:ring-green-500 text-base sm:text-sm py-2 pl-3 pr-11"
                                        />
                                        <button
                                            type="button"
                                            @click="
                                                showNewPassword =
                                                    !showNewPassword
                                            "
                                            aria-label="Tampilkan atau sembunyikan kata sandi baru"
                                            class="absolute right-1 top-1/2 -translate-y-1/2 flex min-h-[40px] min-w-[40px] items-center justify-center text-slate-400 hover:text-slate-600 transition-colors"
                                        >
                                            <svg
                                                v-if="!showNewPassword"
                                                class="w-[18px] h-[18px]"
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
                                            <svg
                                                v-else
                                                class="w-[18px] h-[18px]"
                                                fill="none"
                                                stroke="currentColor"
                                                viewBox="0 0 24 24"
                                            >
                                                <path
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                    stroke-width="2"
                                                    d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.29 3.29m0 0a10.05 10.05 0 015.188-1.528c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"
                                                ></path>
                                            </svg>
                                        </button>
                                    </div>

                                    <!-- Indikator kekuatan kata sandi -->
                                    <div
                                        v-if="passwordForm.password"
                                        class="mt-2"
                                    >
                                        <div class="flex gap-1">
                                            <span
                                                v-for="n in 4"
                                                :key="n"
                                                class="h-1.5 flex-1 rounded-full transition-colors"
                                                :class="
                                                    n <= passwordStrength.score
                                                        ? passwordStrength.barClass
                                                        : 'bg-slate-200'
                                                "
                                            ></span>
                                        </div>
                                        <p
                                            class="mt-1 text-[11px] font-semibold"
                                            :class="passwordStrength.textClass"
                                        >
                                            Kekuatan: {{ passwordStrength.label }}
                                        </p>
                                    </div>

                                    <span
                                        v-if="passwordForm.errors.password"
                                        class="text-xs text-red-500 mt-1 block"
                                        >{{
                                            passwordForm.errors.password
                                        }}</span
                                    >
                                </div>
                                <!-- Konfirmasi Kata Sandi Baru -->
                                <div>
                                    <label
                                        for="konfirmasi-password-baru"
                                        class="block text-sm font-medium text-slate-700 mb-1"
                                        >Konfirmasi Kata Sandi Baru</label
                                    >
                                    <div class="relative">
                                        <input
                                            id="konfirmasi-password-baru"
                                            name="password_confirmation"
                                            v-model="
                                                passwordForm.password_confirmation
                                            "
                                            :type="
                                                showConfirmPassword
                                                    ? 'text'
                                                    : 'password'
                                            "
                                            autocomplete="new-password"
                                            class="w-full min-h-[44px] rounded-lg border-slate-300 shadow-sm focus:border-green-500 focus:ring-green-500 text-base sm:text-sm py-2 pl-3 pr-11"
                                        />
                                        <button
                                            type="button"
                                            @click="
                                                showConfirmPassword =
                                                    !showConfirmPassword
                                            "
                                            aria-label="Tampilkan atau sembunyikan konfirmasi kata sandi"
                                            class="absolute right-1 top-1/2 -translate-y-1/2 flex min-h-[40px] min-w-[40px] items-center justify-center text-slate-400 hover:text-slate-600 transition-colors"
                                        >
                                            <svg
                                                v-if="!showConfirmPassword"
                                                class="w-[18px] h-[18px]"
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
                                            <svg
                                                v-else
                                                class="w-[18px] h-[18px]"
                                                fill="none"
                                                stroke="currentColor"
                                                viewBox="0 0 24 24"
                                            >
                                                <path
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                    stroke-width="2"
                                                    d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.29 3.29m0 0a10.05 10.05 0 015.188-1.528c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"
                                                ></path>
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div
                                class="flex flex-col items-start justify-between gap-3 pt-2 sm:flex-row sm:items-center"
                            >
                                <span
                                    v-if="passwordForm.recentlySuccessful"
                                    class="text-sm text-green-600 font-medium"
                                    >Kata sandi berhasil diperbarui.</span
                                >
                                <span v-else></span>
                                <button
                                    type="submit"
                                    :disabled="passwordForm.processing"
                                    class="min-h-[44px] w-full sm:w-auto px-5 py-2 bg-gradient-to-r from-green-500 to-green-600 text-white rounded-lg text-sm font-semibold hover:from-green-600 hover:to-green-700 shadow-md shadow-green-500/20 transition disabled:opacity-50"
                                >
                                    Perbarui Kata Sandi
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </MainLayout>
</template>