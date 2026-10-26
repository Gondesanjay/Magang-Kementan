<script setup>
import { computed, ref, watch, onMounted, onBeforeUnmount } from "vue";
import { Link, usePage, router } from "@inertiajs/vue3";
import axios from "axios"; // BARU: untuk kirim token FCM ke Laravel
// Firebase Cloud Messaging (push notification browser)
// Pastikan resources/js/firebase.js mengekspor `messaging`.
import { getToken, onMessage } from "firebase/messaging";
import { messaging } from "@/firebase";

const page = usePage();
const user = computed(() => page.props.auth.user);

const isActive = (routeName) => {
    return route().current(routeName) || route().current(routeName + ".*");
};

const isProfileOpen = ref(false);
const isSidebarOpen = ref(true); // mode desktop (lebar / ringkas)
const isMobileOpen = ref(false); // drawer di HP
const isNotifOpen = ref(false);

// Label menu tampil jika sidebar desktop terbuka ATAU drawer mobile terbuka
const showLabel = computed(() => isSidebarOpen.value || isMobileOpen.value);

// Tutup drawer otomatis setiap pindah halaman
watch(
    () => page.url,
    () => {
        isMobileOpen.value = false;
    },
);

// Kunci scroll body saat drawer terbuka
watch(isMobileOpen, (open) => {
    document.body.style.overflow = open ? "hidden" : "";
});

// ================= BOTTOM NAVIGATION (KHUSUS MOBILE) =================
// Menu bawah menyesuaikan kebutuhan inti tiap role:
//   - Role 1 (Karyawan)        : Beranda, Ajukan, Riwayat, Profil
//   - Role 2/3/4 (Atasan L1-3) : Beranda, Approval, Kalender Tim, Profil
//   - Role 6 (Kepala Biro)     : Beranda, Approval, Tangguhkan, Kalender Tim, Profil
//   - Role 5 (Admin SDM)       : Beranda, Pegawai, Rekap, Profil
// Menu yang tidak tampil di bottom bar tetap bisa dibuka lewat sidebar (hamburger).

// Kumpulan ikon (Heroicons outline) agar definisi menu di bawah tetap ringkas
const ICONS = {
    beranda:
        "M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6",
    ajukan: "M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z",
    riwayat: "M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z",
    approval:
        "M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4",
    tangguhkan:
        "M20.618 5.984A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z",
    kalender:
        "M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z",
    pegawai:
        "M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z",
    rekap: "M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z",
    profil: "M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z",
};

const bottomNavItems = computed(() => {
    const roleId = user.value?.role_id;

    const beranda = {
        key: "beranda",
        label: "Beranda",
        href: route("dashboard"),
        active: isActive("dashboard"),
        icon: ICONS.beranda,
    };

    const profil = {
        key: "profil",
        label: "Profil",
        href: route("profile.edit"),
        active: isActive("profile.edit"),
        icon: ICONS.profil,
    };

    const approval = {
        key: "approval",
        label: "Approval",
        href: route("atasan.approval"),
        active: isActive("atasan.approval"),
        icon: ICONS.approval,
    };

    const kalenderTim = {
        key: "kalender",
        label: "Kalender Tim",
        href: route("karyawan.kalender"),
        active: isActive("karyawan.kalender"),
        icon: ICONS.kalender,
    };

    // Role 1 - Karyawan / Staf
    if (roleId === 1) {
        return [
            beranda,
            {
                key: "ajukan",
                label: "Ajukan",
                href: route("karyawan.ajukan"),
                active: isActive("karyawan.ajukan"),
                icon: ICONS.ajukan,
            },
            {
                key: "riwayat",
                label: "Riwayat",
                href: route("karyawan.riwayat"),
                active: isActive("karyawan.riwayat"),
                icon: ICONS.riwayat,
            },
            profil,
        ];
    }

    // Role 6 - Atasan L4 / Kepala Biro (5 menu, ada menu eksklusif Tangguhkan)
    if (roleId === 6) {
        return [
            beranda,
            approval,
            {
                key: "tangguhkan",
                label: "Tangguhkan",
                href: route("atasan.penangguhan"),
                active: isActive("atasan.penangguhan"),
                icon: ICONS.tangguhkan,
            },
            kalenderTim,
            profil,
        ];
    }

    // Role 2, 3, 4 - Atasan L1 - L3
    if ([2, 3, 4].includes(roleId)) {
        return [beranda, approval, kalenderTim, profil];
    }

    // Role 5 - Admin SDM
    if (roleId === 5) {
        return [
            beranda,
            {
                key: "pegawai",
                label: "Pegawai",
                href: route("admin.pegawai"),
                active: isActive("admin.pegawai"),
                icon: ICONS.pegawai,
            },
            {
                key: "rekap",
                label: "Rekap",
                href: route("admin.rekap"),
                // Menu Rekap tetap menyala di kedua halaman rekap
                active: isActive("admin.rekap") || isActive("admin.monitoring"),
                icon: ICONS.rekap,
            },
            profil,
        ];
    }

    // Fallback (role tidak dikenal): menu minimal yang aman
    return [beranda, profil];
});

// Kolom grid menyesuaikan jumlah menu (Tailwind butuh class statis)
const bottomNavGridClass = computed(() => {
    const total = bottomNavItems.value.length;
    if (total >= 5) return "grid-cols-5";
    if (total === 4) return "grid-cols-4";
    return "grid-cols-2";
});

// Tab di dalam menu "Rekap" (khusus Admin HR / role 5, tampil di HP):
// memisahkan "Rekap Kuota Detail" dan "Rekap Laporan" yang sebelumnya
// hanya bisa dibuka lewat sidebar.
const rekapTabs = computed(() => {
    if (user.value?.role_id !== 5) return [];
    return [
        {
            key: "kuota",
            label: "Rekap Kuota",
            href: route("admin.monitoring"),
            active: isActive("admin.monitoring"),
        },
        {
            key: "laporan",
            label: "Rekap Laporan",
            href: route("admin.rekap"),
            active: isActive("admin.rekap"),
        },
    ];
});
const showRekapTabs = computed(
    () => rekapTabs.value.length > 0 && rekapTabs.value.some((t) => t.active),
);
// ================= END BOTTOM NAVIGATION =================

// ================= TIMEOUT SESI IDLE =================
// Harus LEBIH KECIL dari SESSION_LIFETIME di .env (menit).
// Test: SESSION_LIFETIME=30 -> 30 menit. Produksi: 30 menit.
const IDLE_LIMIT_MS = 29 * 60 * 1000;
let idleTimer = null;
let lastActivity = Date.now();
let sedangLogout = false;

const logoutKarenaIdle = () => {
    if (sedangLogout) return;
    sedangLogout = true;
    router.post(
        route("logout"),
        { fcm_token: fcmTokenSaatIni.value }, // BARU
        {
            onFinish: () => {
                window.location.href = route("login");
            },
        },
    );
};

const resetIdleTimer = () => {
    lastActivity = Date.now();
    clearTimeout(idleTimer);
    idleTimer = setTimeout(logoutKarenaIdle, IDLE_LIMIT_MS);
};

// Laptop sleep / tab di latar belakang bisa menunda setTimeout.
const cekSetelahTabAktif = () => {
    if (document.visibilityState !== "visible") return;
    if (Date.now() - lastActivity >= IDLE_LIMIT_MS) {
        logoutKarenaIdle();
    }
};

const eventAktivitas = [
    "mousemove",
    "mousedown",
    "keydown",
    "scroll",
    "touchstart",
];

// ================= FCM / PUSH NOTIFICATION =================
// Minta izin + ambil FCM token + simpan ke Laravel.
// Dipicu tombol (bukan otomatis), karena Chrome memblokir prompt tanpa gesture user.
// VAPID key dari .env: VITE_FIREBASE_VAPID_KEY=...
const fcmStatus = ref(""); // pesan singkat untuk UI
const fcmLoading = ref(false);
const fcmTokenSiap = ref(false);

// BARU: token FCM milik browser ini. Dikirim saat logout supaya server
// hanya menghapus token perangkat INI (perangkat lain milik user tetap aktif).
const bacaTokenTersimpan = () => {
    try {
        return localStorage.getItem("fcm_token") || "";
    } catch (e) {
        return "";
    }
};
const fcmTokenSaatIni = ref(bacaTokenTersimpan());

// Daftarkan service worker, ambil token, simpan ke database.
const ambilDanSimpanToken = async () => {
    const vapidKey = import.meta.env.VITE_FIREBASE_VAPID_KEY;
    if (!vapidKey) {
        throw new Error("VITE_FIREBASE_VAPID_KEY belum diisi di .env");
    }

    const registration = await navigator.serviceWorker.register(
        "/firebase-messaging-sw.js",
    );
    // Tunggu service worker benar-benar aktif, kalau tidak getToken bisa gagal
    // dengan "no active Service Worker" (terutama saat pertama kali).
    await navigator.serviceWorker.ready;

    const currentToken = await getToken(messaging, {
        vapidKey,
        serviceWorkerRegistration: registration,
    });

    if (!currentToken) return false;

    fcmTokenSaatIni.value = currentToken; // BARU
    try {
        localStorage.setItem("fcm_token", currentToken);
    } catch (e) {
        // penyimpanan lokal tidak tersedia, abaikan
    }
    await axios.post("/fcm-token", { token: currentToken });
    return true;
};

const requestNotificationPermission = async () => {
    if (fcmLoading.value) return;
    fcmLoading.value = true;
    fcmStatus.value = "";

    try {
        if (typeof window === "undefined" || !("Notification" in window)) {
            fcmStatus.value = "Browser tidak mendukung notifikasi.";
            return;
        }

        const permission = await Notification.requestPermission();

        if (permission !== "granted") {
            fcmStatus.value = "Izin notifikasi ditolak.";
            return;
        }

        const berhasil = await ambilDanSimpanToken();
        fcmTokenSiap.value = berhasil;
        fcmStatus.value = berhasil
            ? "Notifikasi berhasil diaktifkan."
            : "Gagal mengambil token perangkat.";
    } catch (error) {
        console.error("Terjadi kesalahan saat mengatur notifikasi:", error);
        fcmStatus.value = "Gagal mengaktifkan notifikasi.";
    } finally {
        fcmLoading.value = false;
    }
};

// Kalau izin sudah pernah diberikan, sinkronkan token diam-diam tiap halaman dibuka
// (token bisa berganti), tanpa pop-up. Tombol otomatis tampil "Notifikasi aktif".
const sinkronkanTokenJikaSudahIzin = async () => {
    if (!("Notification" in window) || Notification.permission !== "granted") {
        return;
    }
    try {
        fcmTokenSiap.value = await ambilDanSimpanToken();
    } catch (error) {
        console.error("Gagal sinkron token FCM:", error);
    }
};
// BARU: pesan yang masuk saat halaman ini masih terlihat (foreground).
// Lonceng selalu disegarkan. Pop-up sistem hanya ditampilkan kalau halaman
// sedang tidak difokuskan (mis. jendela terlihat tapi pengguna ada di jendela lain).
let hentikanFcmForeground = null;

const tampilkanNotifForeground = async (payload) => {
    const d = payload?.data || {};

    router.reload({ only: ["notifikasis"], preserveScroll: true });

    if (!("Notification" in window) || Notification.permission !== "granted") {
        return;
    }
    if (document.visibilityState === "visible" && document.hasFocus()) return;

    try {
        const reg = await navigator.serviceWorker.ready;
        await reg.showNotification(d.title || "AgriLeave", {
            body: d.body || "",
            icon: "/favicon.ico",
            data: { url: d.url || "/dashboard" },
        });
    } catch (e) {
        console.error("Gagal menampilkan notifikasi foreground:", e);
    }
};
// ================= END FCM / PUSH NOTIFICATION =================

onMounted(() => {
    eventAktivitas.forEach((e) =>
        window.addEventListener(e, resetIdleTimer, { passive: true }),
    );
    document.addEventListener("visibilitychange", cekSetelahTabAktif);
    resetIdleTimer();
    sinkronkanTokenJikaSudahIzin(); // BARU
    hentikanFcmForeground = onMessage(messaging, tampilkanNotifForeground); // BARU
});

onBeforeUnmount(() => {
    clearTimeout(idleTimer);
    eventAktivitas.forEach((e) =>
        window.removeEventListener(e, resetIdleTimer),
    );
    document.removeEventListener("visibilitychange", cekSetelahTabAktif);
    document.body.style.overflow = "";
    if (hentikanFcmForeground) hentikanFcmForeground(); // BARU
});
// ================= END TIMEOUT SESI IDLE =================

const closeDropdowns = () => {
    isNotifOpen.value = false;
    isProfileOpen.value = false;
};

const notifications = ref(page.props.notifikasis || []);

watch(
    () => page.props.notifikasis,
    (newNotifs) => {
        notifications.value = newNotifs || [];
    },
    { deep: true },
);

// Menghitung jumlah notifikasi yang belum dibaca
const unreadCount = computed(
    () => notifications.value.filter((n) => !n.is_read).length,
);

// Tandai semua dibaca (hanya menghilangkan titik hijau, notifikasi tetap tampil)
const markAllAsRead = () => {
    router.post(
        route("notifications.readAll"),
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                notifications.value = notifications.value.map((n) => ({
                    ...n,
                    is_read: true,
                }));
            },
        },
    );
};

// Hapus SEMUA notifikasi sekaligus
const hapusSemuaNotifikasi = () => {
    router.delete(route("notifications.destroyAll"), {
        preserveScroll: true,
        onSuccess: () => {
            notifications.value = [];
        },
    });
};

// Hapus satu notifikasi
const hapusNotifikasi = (id) => {
    router.delete(route("notifikasi.destroy", id), {
        preserveScroll: true,
        onSuccess: () => {
            notifications.value = notifications.value.filter(
                (n) => n.id !== id,
            );
        },
    });
};

// Klik item notifikasi: hapus dulu, lalu buka halaman tujuan (jika ada tautan)
const klikNotifikasi = (notif) => {
    router.delete(route("notifikasi.destroy", notif.id), {
        preserveScroll: true,
        onSuccess: () => {
            notifications.value = notifications.value.filter(
                (n) => n.id !== notif.id,
            );

            if (notif.tautan) {
                // Ambil path saja (aman meski tautan berisi host/port berbeda)
                let path = notif.tautan;
                try {
                    const url = new URL(path, window.location.origin);
                    path = url.pathname + url.search + url.hash;
                } catch (e) {
                    // sudah berupa path relatif
                }
                isNotifOpen.value = false;
                router.visit(path);
            }
        },
    });
};

// Jenis notifikasi dikenali dari JUDULNYA (lebih andal daripada isi pesan):
//   'kedaluwarsa' -> pengajuan kedaluwarsa otomatis
//   'pengingat'   -> pengingat ke atasan / Admin HR (pengajuan menggantung)
//   'biasa'       -> semua notifikasi lain
const jenisNotif = (notif) => {
    const judul = (notif.judul || "").toLowerCase();
    if (judul.includes("kedaluwarsa")) return "kedaluwarsa";
    if (judul.includes("pengingat") || judul.includes("menggantung")) {
        return "pengingat";
    }
    return "biasa";
};

// Warna badge notifikasi berdasarkan isi pesan/judul
const badgeStyle = (notif) => {
    const teks = `${notif.pesan} ${notif.judul}`.toLowerCase();

    // Dicek lebih dulu: kata "persetujuan" pada pesan pengingat mengandung
    // "setuju" dan akan salah diberi warna hijau oleh aturan di bawah.
    if (jenisNotif(notif) === "kedaluwarsa") {
        return "text-zinc-700 bg-zinc-100 border-zinc-300";
    }
    if (jenisNotif(notif) === "pengingat") {
        return "text-orange-700 bg-orange-50 border-orange-200";
    }

    if (teks.includes("tolak") || teks.includes("batal")) {
        return "text-red-600 bg-red-50 border-red-200";
    }
    if (teks.includes("setuju") || teks.includes("berhasil")) {
        return "text-green-700 bg-green-50 border-green-200";
    }
    if (teks.includes("tangguh")) {
        return "text-amber-600 bg-amber-50 border-amber-200";
    }
    if (teks.includes("saldo cuti diperbarui")) {
        return "text-sky-700 bg-sky-50 border-sky-200";
    }
    return "text-slate-600 bg-slate-50 border-slate-200";
};

const isIconTolak = (notif) => {
    const teks = `${notif.pesan} ${notif.judul}`.toLowerCase();
    return teks.includes("tolak") || teks.includes("batal");
};

const formatTanggal = (tanggal) => {
    return new Date(tanggal).toLocaleDateString("id-ID", {
        day: "numeric",
        month: "short",
        year: "numeric",
    });
};

const formatJam = (tanggal) => {
    return new Date(tanggal)
        .toLocaleTimeString("id-ID", {
            hour: "2-digit",
            minute: "2-digit",
        })
        .replace(".", ":");
};

// Label menu untuk route 'atasan.approval' dibuat dinamis sesuai role:
// - Atasan (role 2,3,4,6) -> "Approval Cuti"
// - Admin HR (role 5)     -> "Monitoring Cuti"
const approvalMenuLabel = computed(() =>
    user.value.role_id === 5 ? "Monitoring Cuti" : "Approval Cuti",
);
</script>

<template>
    <!-- BACKGROUND UTAMA GRADASI SOFT -->
    <div
        class="min-h-screen flex font-sans bg-gradient-to-br from-[#e0f2f1] via-[#f0f9ff] to-[#e0f2fe]"
    >
        <!-- BACKDROP DRAWER (HP) -->
        <div
            v-if="isMobileOpen"
            class="fixed inset-0 z-40 bg-slate-900/50 backdrop-blur-sm md:hidden"
            @click="isMobileOpen = false"
        ></div>

        <!-- SIDEBAR: drawer geser di HP, sidebar tetap di desktop -->
        <aside
            :class="[
                'bg-gradient-to-b from-[#1e293b] via-[#0f172a] to-[#020617] border-r border-slate-700/50 flex flex-col shadow-[4px_0_24px_rgba(0,0,0,0.15)] text-white fixed inset-y-0 left-0 w-64 z-50 md:z-40 transition-all duration-300',
                isMobileOpen ? 'translate-x-0' : '-translate-x-full',
                'md:translate-x-0',
                isSidebarOpen ? 'md:w-64' : 'md:w-20',
            ]"
        >
            <div
                class="h-16 md:h-20 flex items-center border-b border-slate-700/50 shrink-0 px-4 overflow-hidden"
                :class="showLabel ? 'justify-start gap-3' : 'justify-center'"
            >
                <div
                    class="w-10 h-10 rounded-xl bg-white flex items-center justify-center p-1 shadow-sm shrink-0"
                >
                    <img
                        src="/images/logo-kementan.png"
                        alt="Logo Kementan"
                        class="w-full h-full object-contain"
                    />
                </div>
                <div
                    v-show="showLabel"
                    class="transition-opacity duration-200 whitespace-nowrap"
                >
                    <span
                        class="block text-base font-bold text-white tracking-wide leading-tight"
                        >Agri<span class="text-green-500">Leave</span></span
                    >
                    <span
                        class="block text-[10px] font-semibold text-green-400 tracking-wide mt-0.5"
                        >Biro Perencanaan</span
                    >
                    <span
                        class="block text-[9px] text-slate-400 uppercase tracking-wider mt-0.5"
                        >Kementerian Pertanian</span
                    >
                </div>
                <!-- Tombol tutup drawer (HP) -->
                <button
                    class="ml-auto p-2 text-slate-400 hover:text-white md:hidden"
                    @click="isMobileOpen = false"
                    title="Tutup Menu"
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

            <nav
                class="flex-1 overflow-y-auto py-6 px-3 space-y-1 custom-scrollbar"
            >
                <p
                    v-show="showLabel"
                    class="px-3 text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-2 mt-2"
                >
                    Main Menu
                </p>
                <!-- MENU AKTIF DENGAN GLOW EFFECT -->
                <Link
                    :href="route('dashboard')"
                    :title="!showLabel ? 'Beranda' : ''"
                    :class="[
                        'flex items-center gap-3 px-3 py-2.5 rounded-xl font-medium text-sm transition-all duration-300',
                        isActive('dashboard')
                            ? 'bg-gradient-to-r from-green-500 to-green-600 text-white shadow-lg shadow-green-500/30 border border-green-400/20'
                            : 'text-slate-400 hover:bg-slate-800/50 hover:text-white',
                        !showLabel ? 'justify-center' : '',
                    ]"
                >
                    <svg
                        class="w-5 h-5 shrink-0"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"
                        ></path>
                    </svg>
                    <span v-show="showLabel" class="truncate">Beranda</span>
                </Link>

                <!-- Role 1,2,3,4,6 -> bisa mengajukan cuti -->
                <div v-if="[1, 2, 3, 4, 6].includes(user.role_id)">
                    <p
                        v-show="showLabel"
                        class="px-3 text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-2 mt-6"
                    >
                        Employee
                    </p>
                    <Link
                        :href="route('karyawan.ajukan')"
                        :title="!showLabel ? 'Ajukan Cuti' : ''"
                        :class="[
                            'flex items-center gap-3 px-3 py-2.5 rounded-xl font-medium text-sm transition-all duration-300',
                            isActive('karyawan.ajukan')
                                ? 'bg-gradient-to-r from-green-500 to-green-600 text-white shadow-lg shadow-green-500/30 border border-green-400/20'
                                : 'text-slate-400 hover:bg-slate-800/50 hover:text-white',
                            !showLabel ? 'justify-center' : '',
                        ]"
                    >
                        <svg
                            class="w-5 h-5 shrink-0"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                            ></path>
                        </svg>
                        <span v-show="showLabel" class="truncate"
                            >Ajukan Cuti</span
                        >
                    </Link>
                    <Link
                        :href="route('karyawan.riwayat')"
                        :title="!showLabel ? 'Riwayat Pengajuan' : ''"
                        :class="[
                            'flex items-center gap-3 px-3 py-2.5 rounded-xl font-medium text-sm transition-all duration-300 mt-1',
                            isActive('karyawan.riwayat')
                                ? 'bg-gradient-to-r from-green-500 to-green-600 text-white shadow-lg shadow-green-500/30 border border-green-400/20'
                                : 'text-slate-400 hover:bg-slate-800/50 hover:text-white',
                            !showLabel ? 'justify-center' : '',
                        ]"
                    >
                        <svg
                            class="w-5 h-5 shrink-0"
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
                        <span v-show="showLabel" class="truncate"
                            >Riwayat Pengajuan</span
                        >
                    </Link>
                    <Link
                        :href="route('karyawan.kalender')"
                        :title="!showLabel ? 'Status Tim' : ''"
                        :class="[
                            'flex items-center gap-3 px-3 py-2.5 rounded-xl font-medium text-sm transition-all duration-300 mt-1',
                            isActive('karyawan.kalender')
                                ? 'bg-gradient-to-r from-green-500 to-green-600 text-white shadow-lg shadow-green-500/30 border border-green-400/20'
                                : 'text-slate-400 hover:bg-slate-800/50 hover:text-white',
                            !showLabel ? 'justify-center' : '',
                        ]"
                    >
                        <svg
                            class="w-5 h-5 shrink-0"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"
                            ></path>
                        </svg>
                        <span v-show="showLabel" class="truncate"
                            >Status Tim</span
                        >
                    </Link>
                </div>

                <!-- Role 2,3,4,5,6 -> Approval, Role 6 saja -> Penangguhan -->
                <div v-if="[2, 3, 4, 5, 6].includes(user.role_id)">
                    <p
                        v-show="showLabel"
                        class="px-3 text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-2 mt-6"
                    >
                        Managerial
                    </p>
                    <!-- Label dinamis (route tetap 'atasan.approval'):
                         - Atasan (role 2,3,4,6) -> "Approval Cuti"
                         - Admin HR (role 5)     -> "Monitoring Cuti" -->
                    <Link
                        :href="route('atasan.approval')"
                        :title="!showLabel ? approvalMenuLabel : ''"
                        :class="[
                            'flex items-center gap-3 px-3 py-2.5 rounded-xl font-medium text-sm transition-all duration-300',
                            isActive('atasan.approval')
                                ? 'bg-gradient-to-r from-green-500 to-green-600 text-white shadow-lg shadow-green-500/30 border border-green-400/20'
                                : 'text-slate-400 hover:bg-slate-800/50 hover:text-white',
                            !showLabel ? 'justify-center' : '',
                        ]"
                    >
                        <svg
                            class="w-5 h-5 shrink-0"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"
                            ></path>
                        </svg>
                        <span v-show="showLabel" class="truncate">{{
                            approvalMenuLabel
                        }}</span>
                    </Link>
                    <!-- "Monitoring Cuti" (route 'atasan.kuota') disembunyikan untuk role 5.
                         Role 5 punya "Rekap Kuota Detail" di blok HR Admin. -->
                    <Link
                        v-if="user.role_id !== 5"
                        :href="route('atasan.kuota')"
                        :title="!showLabel ? 'Monitoring Cuti' : ''"
                        :class="[
                            'flex items-center gap-3 px-3 py-2.5 rounded-xl font-medium text-sm transition-all duration-300 mt-1',
                            isActive('atasan.kuota')
                                ? 'bg-gradient-to-r from-green-500 to-green-600 text-white shadow-lg shadow-green-500/30 border border-green-400/20'
                                : 'text-slate-400 hover:bg-slate-800/50 hover:text-white',
                            !showLabel ? 'justify-center' : '',
                        ]"
                    >
                        <svg
                            class="w-5 h-5 shrink-0"
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
                        <span v-show="showLabel" class="truncate"
                            >Monitoring Cuti</span
                        >
                    </Link>
                    <Link
                        v-if="user.role_id === 6"
                        :href="route('atasan.penangguhan')"
                        :title="!showLabel ? 'Penangguhan Cuti' : ''"
                        :class="[
                            'flex items-center gap-3 px-3 py-2.5 rounded-xl font-medium text-sm transition-all duration-300 mt-1',
                            isActive('atasan.penangguhan')
                                ? 'bg-gradient-to-r from-green-500 to-green-600 text-white shadow-lg shadow-green-500/30 border border-green-400/20'
                                : 'text-slate-400 hover:bg-slate-800/50 hover:text-white',
                            !showLabel ? 'justify-center' : '',
                        ]"
                    >
                        <svg
                            class="w-5 h-5 shrink-0"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"
                            ></path>
                        </svg>
                        <span v-show="showLabel" class="truncate"
                            >Penangguhan Cuti</span
                        >
                    </Link>
                </div>

                <!-- Role 5 -> HR Admin (Kelola Pegawai, Rekap Kuota Detail,
                     Rekap Laporan, Kelola Hari Libur). "Approval Cuti" untuk role 5
                     tetap ada di blok Managerial di atas. -->
                <div v-if="user.role_id === 5">
                    <p
                        v-show="showLabel"
                        class="px-3 text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-2 mt-6"
                    >
                        HR Admin
                    </p>

                    <Link
                        :href="route('admin.pegawai')"
                        :title="!showLabel ? 'Kelola Pegawai' : ''"
                        :class="[
                            'flex items-center gap-3 px-3 py-2.5 rounded-xl font-medium text-sm transition-all duration-300',
                            isActive('admin.pegawai')
                                ? 'bg-gradient-to-r from-green-500 to-green-600 text-white shadow-lg shadow-green-500/30 border border-green-400/20'
                                : 'text-slate-400 hover:bg-slate-800/50 hover:text-white',
                            !showLabel ? 'justify-center' : '',
                        ]"
                    >
                        <svg
                            class="w-5 h-5 shrink-0"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"
                            ></path>
                        </svg>
                        <span v-show="showLabel" class="truncate"
                            >Kelola Pegawai</span
                        >
                    </Link>

                    <!-- "Rekap Kuota Detail" (route 'admin.monitoring') -->
                    <Link
                        :href="route('admin.monitoring')"
                        :title="!showLabel ? 'Rekap Kuota Detail' : ''"
                        :class="[
                            'flex items-center gap-3 px-3 py-2.5 rounded-xl font-medium text-sm transition-all duration-300 mt-1',
                            isActive('admin.monitoring')
                                ? 'bg-gradient-to-r from-green-500 to-green-600 text-white shadow-lg shadow-green-500/30 border border-green-400/20'
                                : 'text-slate-400 hover:bg-slate-800/50 hover:text-white',
                            !showLabel ? 'justify-center' : '',
                        ]"
                    >
                        <svg
                            class="w-5 h-5 shrink-0"
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
                        <span v-show="showLabel" class="truncate"
                            >Rekap Kuota Detail</span
                        >
                    </Link>
                    <Link
                        :href="route('admin.rekap')"
                        :title="!showLabel ? 'Rekap Laporan' : ''"
                        :class="[
                            'flex items-center gap-3 px-3 py-2.5 rounded-xl font-medium text-sm transition-all duration-300 mt-1',
                            isActive('admin.rekap')
                                ? 'bg-gradient-to-r from-green-500 to-green-600 text-white shadow-lg shadow-green-500/30 border border-green-400/20'
                                : 'text-slate-400 hover:bg-slate-800/50 hover:text-white',
                            !showLabel ? 'justify-center' : '',
                        ]"
                    >
                        <svg
                            class="w-5 h-5 shrink-0"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                            ></path>
                        </svg>
                        <span v-show="showLabel" class="truncate"
                            >Rekap Laporan</span
                        >
                    </Link>
                    <Link
                        :href="route('admin.libur')"
                        :title="!showLabel ? 'Hari Libur' : ''"
                        :class="[
                            'flex items-center gap-3 px-3 py-2.5 rounded-xl font-medium text-sm transition-all duration-300 mt-1',
                            isActive('admin.libur')
                                ? 'bg-gradient-to-r from-green-500 to-green-600 text-white shadow-lg shadow-green-500/30 border border-green-400/20'
                                : 'text-slate-400 hover:bg-slate-800/50 hover:text-white',
                            !showLabel ? 'justify-center' : '',
                        ]"
                    >
                        <svg
                            class="w-5 h-5 shrink-0"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2-2v12a2 2 0 002 2z"
                            ></path>
                        </svg>
                        <span v-show="showLabel" class="truncate"
                            >Kelola Hari Libur</span
                        >
                    </Link>
                </div>
            </nav>

            <div
                class="p-3 bg-[#020617]/50 border-t border-slate-800/50 shrink-0"
            >
                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    :data="{ fcm_token: fcmTokenSaatIni }"
                    :title="!showLabel ? 'Logout' : ''"
                    :class="[
                        'w-full flex items-center justify-center gap-2 px-4 py-2 bg-slate-800/60 hover:bg-slate-700/80 text-slate-300 hover:text-white border border-slate-700/50 rounded-xl text-sm font-medium transition-all duration-200',
                        !showLabel ? 'px-2' : '',
                    ]"
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
                            d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"
                        ></path>
                    </svg>
                    <span v-show="showLabel" class="truncate">Logout</span>
                </Link>
            </div>
        </aside>

        <!-- KONTEN UTAMA -->
        <div
            :class="[
                'flex-1 min-w-0 flex flex-col min-h-screen transition-all duration-300 relative',
                isSidebarOpen ? 'ml-0 md:ml-64' : 'ml-0 md:ml-20',
            ]"
        >
            <!-- OVERLAY penutup dropdown (di luar header agar tidak terkena
                 efek backdrop-blur yang membatasi elemen fixed) -->
            <div
                v-if="isNotifOpen || isProfileOpen"
                class="fixed inset-0 z-20"
                @click="closeDropdowns"
            ></div>

            <!-- HEADER KACA (GLASSMORPHISM) -->
            <header
                class="h-16 md:h-20 bg-white/70 backdrop-blur-xl border-b border-white/50 flex items-center justify-between px-4 sm:px-6 md:px-8 z-30 sticky top-0 shadow-sm"
            >
                <div class="flex items-center gap-3 md:gap-4 min-w-0">
                    <!-- Tombol menu (HP) -->
                    <button
                        @click="isMobileOpen = true"
                        class="md:hidden p-2.5 rounded-xl bg-white hover:bg-green-50 text-slate-700 shadow-sm border border-slate-100"
                        title="Buka Menu"
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
                                d="M4 6h16M4 12h16M4 18h16"
                            />
                        </svg>
                    </button>
                    <!-- Tombol buka/tutup sidebar (Desktop) -->
                    <button
                        @click="isSidebarOpen = !isSidebarOpen"
                        class="hidden md:block p-2.5 rounded-xl bg-white hover:bg-green-50 text-slate-700 transition focus:outline-none focus:ring-2 focus:ring-green-500 shadow-sm border border-slate-100"
                        title="Buka / Tutup Sidebar"
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
                                d="M4 6h16M4 12h16M4 18h16"
                            />
                        </svg>
                    </button>

                    <span class="md:hidden font-bold text-slate-800 truncate">
                        Agri<span class="text-green-600">Leave</span>
                    </span>
                    <h2
                        class="text-xl font-extrabold text-slate-800 hidden md:block tracking-tight"
                    >
                        Portal Cuti Digital Terintegrasi
                    </h2>
                </div>

                <div class="flex items-center gap-3 sm:gap-6">
                    <span
                        v-if="user.kelompok_substansi || user.tim_kerja"
                        class="text-xs font-semibold text-green-700 bg-green-50 border border-green-200 px-3 py-1.5 rounded-full hidden sm:inline-block shadow-sm"
                    >
                        {{ user.kelompok_substansi || user.tim_kerja }}
                    </span>

                    <!-- sm:relative -> di HP dropdown menempel ke header (lebar penuh),
                         di layar >= sm menempel ke lonceng -->
                    <div class="sm:relative">
                        <!-- Tombol Notifikasi -->
                        <button
                            @click="
                                isNotifOpen = !isNotifOpen;
                                isProfileOpen = false;
                            "
                            class="relative focus:outline-none p-1.5 rounded-xl hover:bg-white/50 cursor-pointer transition-all hover:scale-110"
                        >
                            <img
                                src="/images/lonceng.png"
                                alt="Notifikasi"
                                class="w-7 h-7 drop-shadow-md"
                            />
                            <span
                                v-if="unreadCount > 0"
                                class="absolute -top-1 -right-1 w-5 h-5 bg-red-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center ring-2 ring-white shadow-sm"
                            >
                                {{ unreadCount }}
                            </span>
                        </button>

                        <!-- DROPDOWN NOTIFIKASI -->
                        <transition
                            enter-active-class="transition ease-out duration-200"
                            enter-from-class="transform opacity-0 scale-95 translate-y-1"
                            enter-to-class="transform opacity-100 scale-100 translate-y-0"
                            leave-active-class="transition ease-in duration-150"
                            leave-from-class="transform opacity-100 scale-100"
                            leave-to-class="transform opacity-0 scale-95"
                        >
                            <div
                                v-if="isNotifOpen"
                                class="absolute inset-x-3 top-full mt-2 sm:inset-x-auto sm:left-auto sm:right-0 sm:mt-3 sm:w-[340px] bg-white/95 backdrop-blur-md rounded-2xl shadow-2xl border border-white/50 overflow-hidden z-50 text-left"
                            >
                                <!-- Header Dropdown -->
                                <div
                                    class="px-3.5 py-2.5 sm:py-3 bg-slate-50/90 border-b border-slate-100/60 flex items-center justify-between gap-2"
                                >
                                    <div
                                        class="flex items-center gap-2 min-w-0"
                                    >
                                        <div
                                            class="w-2 h-2 rounded-full bg-green-500 animate-pulse shrink-0"
                                        ></div>
                                        <h3
                                            class="font-bold text-sm text-slate-800 tracking-tight truncate"
                                        >
                                            Notifikasi
                                        </h3>
                                        <span
                                            v-if="unreadCount > 0"
                                            class="inline-flex items-center justify-center min-w-[1.15rem] h-[1.15rem] px-1 rounded-full bg-red-500 text-white text-[10px] font-bold leading-none shrink-0"
                                        >
                                            {{ unreadCount }}
                                        </span>
                                    </div>
                                    <div
                                        v-if="notifications.length > 0"
                                        class="flex items-center gap-1.5 shrink-0"
                                    >
                                        <button
                                            type="button"
                                            @click="markAllAsRead"
                                            class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-[10px] font-semibold text-emerald-700 bg-white border border-emerald-200/80 hover:bg-emerald-50 transition cursor-pointer whitespace-nowrap"
                                        >
                                            <svg
                                                class="w-3 h-3 shrink-0"
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
                                            Tandai dibaca
                                        </button>
                                        <button
                                            type="button"
                                            @click="hapusSemuaNotifikasi"
                                            class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-[10px] font-semibold text-red-600 bg-white border border-red-200/80 hover:bg-red-50 transition cursor-pointer whitespace-nowrap"
                                        >
                                            <svg
                                                class="w-3 h-3 shrink-0"
                                                fill="none"
                                                stroke="currentColor"
                                                viewBox="0 0 24 24"
                                            >
                                                <path
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                    stroke-width="2.5"
                                                    d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
                                                ></path>
                                            </svg>
                                            Hapus
                                        </button>
                                    </div>
                                </div>

                                <!-- Daftar Item Notifikasi -->
                                <div
                                    class="max-h-[45vh] sm:max-h-72 overflow-y-auto divide-y divide-slate-50"
                                >
                                    <template
                                        v-if="
                                            notifications &&
                                            notifications.length > 0
                                        "
                                    >
                                        <!-- AREA KLIK SELURUH KOTAK -->
                                        <div
                                            v-for="notif in notifications"
                                            :key="notif.id"
                                            @click="klikNotifikasi(notif)"
                                            class="p-3 sm:p-4 hover:bg-slate-50 transition-all duration-150 flex items-start gap-3 sm:gap-3.5 group cursor-pointer"
                                            :class="{
                                                'bg-white': !notif.is_read,
                                                'bg-slate-50/30 opacity-80':
                                                    notif.is_read,
                                            }"
                                        >
                                            <!-- Ikon Kiri -->
                                            <div
                                                class="shrink-0 transition-transform group-hover:scale-110 mt-1"
                                            >
                                                <div
                                                    v-if="
                                                        jenisNotif(notif) ===
                                                        'kedaluwarsa'
                                                    "
                                                    class="bg-zinc-100 text-zinc-500 p-1.5 rounded-full flex items-center justify-center shadow-sm"
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
                                                            d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"
                                                        ></path>
                                                    </svg>
                                                </div>
                                                <div
                                                    v-else-if="
                                                        jenisNotif(notif) ===
                                                        'pengingat'
                                                    "
                                                    class="bg-orange-50 text-orange-500 p-1.5 rounded-full flex items-center justify-center shadow-sm"
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
                                                            d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"
                                                        ></path>
                                                    </svg>
                                                </div>
                                                <div
                                                    v-else-if="
                                                        isIconTolak(notif)
                                                    "
                                                    class="bg-red-50 text-red-500 p-1.5 rounded-full flex items-center justify-center shadow-sm"
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
                                                            d="M6 18L18 6M6 6l12 12"
                                                        ></path>
                                                    </svg>
                                                </div>
                                                <div
                                                    v-else
                                                    class="bg-green-50 text-green-500 p-1.5 rounded-full flex items-center justify-center shadow-sm"
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
                                                </div>
                                            </div>

                                            <!-- Konten Kanan -->
                                            <div class="flex-1 min-w-0 pr-2">
                                                <p
                                                    class="text-[13px] font-semibold text-slate-800 leading-snug group-hover:text-green-700 transition-colors"
                                                >
                                                    {{ notif.judul }}
                                                </p>

                                                <!-- Badge status (warna via badgeStyle) -->
                                                <div
                                                    class="mt-2 flex flex-wrap items-center gap-2.5"
                                                >
                                                    <span
                                                        class="inline-block max-w-full px-2.5 py-1 sm:px-3 sm:py-1.5 text-[11px] font-medium border rounded-2xl leading-relaxed"
                                                        :class="
                                                            badgeStyle(notif)
                                                        "
                                                    >
                                                        {{ notif.pesan }}
                                                    </span>
                                                </div>

                                                <!-- Format Tanggal dan Jam -->
                                                <p
                                                    class="text-[11px] text-slate-400 font-medium whitespace-nowrap flex items-center gap-1 mt-1.5"
                                                >
                                                    <svg
                                                        class="w-3 h-3"
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
                                                    {{
                                                        formatTanggal(
                                                            notif.created_at,
                                                        )
                                                    }},
                                                    {{
                                                        formatJam(
                                                            notif.created_at,
                                                        )
                                                    }}
                                                </p>
                                            </div>
                                            <span
                                                v-if="!notif.is_read"
                                                class="w-2.5 h-2.5 bg-green-500 rounded-full mt-1 shrink-0 shadow-sm"
                                            ></span>
                                        </div>
                                    </template>

                                    <!-- Kondisi Kosong -->
                                    <div
                                        v-else
                                        class="px-6 py-8 text-center bg-white/50"
                                    >
                                        <div
                                            class="w-14 h-14 mx-auto mb-3 flex items-center justify-center"
                                        >
                                            <img
                                                src="/images/lonceng.png"
                                                alt="Kosong"
                                                class="w-full h-full object-contain opacity-70 grayscale-[30%]"
                                            />
                                        </div>
                                        <p
                                            class="text-xs font-semibold text-slate-500"
                                        >
                                            Belum ada notifikasi
                                        </p>
                                    </div>
                                </div>

                                <!-- Footer Dropdown -->
                                <div
                                    class="p-2 sm:p-3 bg-white border-t border-slate-100 text-center shadow-[0_-4px_6px_-1px_rgba(0,0,0,0.02)] relative z-10"
                                >
                                    <Link
                                        :href="route('karyawan.riwayat')"
                                        @click="isNotifOpen = false"
                                        class="text-sm font-semibold text-blue-600 hover:text-blue-700 transition-colors py-1 inline-block"
                                    >
                                        Lihat semua pengajuan
                                    </Link>
                                </div>
                            </div>
                        </transition>
                    </div>

                    <div class="h-6 w-px bg-slate-200 hidden sm:block"></div>

                    <div class="relative">
                        <button
                            @click="
                                isProfileOpen = !isProfileOpen;
                                isNotifOpen = false;
                            "
                            class="flex items-center gap-3 hover:bg-white p-1.5 rounded-xl transition-all outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 cursor-pointer border border-transparent hover:border-slate-100 hover:shadow-sm"
                        >
                            <div
                                class="w-10 h-10 rounded-full bg-slate-100 flex items-center justify-center border border-slate-200 text-slate-700 font-bold text-sm shadow-sm overflow-hidden"
                            >
                                <img
                                    v-if="
                                        user.foto_profil ||
                                        user.profile_photo_path
                                    "
                                    :src="
                                        user.foto_profil
                                            ? `/storage/${user.foto_profil}`
                                            : `/storage/${user.profile_photo_path}`
                                    "
                                    alt="Profil"
                                    class="w-full h-full object-cover"
                                />
                                <span v-else>{{ user.nama.charAt(0) }}</span>
                            </div>
                            <div class="hidden lg:block text-left">
                                <p
                                    class="text-sm font-bold text-slate-800 leading-tight"
                                >
                                    {{ user.nama }}
                                </p>
                                <p
                                    class="text-[11px] font-medium text-slate-500 leading-tight mt-0.5"
                                >
                                    {{ user.jabatan }}
                                </p>
                            </div>
                            <svg
                                class="w-4 h-4 text-slate-400 hidden lg:block transition-transform duration-200"
                                :class="{ 'rotate-180': isProfileOpen }"
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

                        <transition
                            enter-active-class="transition ease-out duration-100"
                            enter-from-class="transform opacity-0 scale-95"
                            enter-to-class="transform opacity-100 scale-100"
                            leave-active-class="transition ease-in duration-75"
                            leave-from-class="transform opacity-100 scale-100"
                            leave-to-class="transform opacity-0 scale-95"
                        >
                            <div
                                v-if="isProfileOpen"
                                class="absolute right-0 mt-2 w-56 max-w-[calc(100vw-1.5rem)] bg-white/95 backdrop-blur-md rounded-2xl shadow-xl border border-white/50 py-2 z-50"
                            >
                                <div
                                    class="px-4 py-3 border-b border-slate-50 lg:hidden"
                                >
                                    <p
                                        class="text-sm font-semibold text-slate-800 truncate"
                                    >
                                        {{ user.nama }}
                                    </p>
                                    <p class="text-xs text-slate-500 truncate">
                                        {{ user.jabatan }}
                                    </p>
                                </div>
                                <Link
                                    :href="route('profile.edit')"
                                    class="flex items-center gap-3 px-4 py-2.5 text-sm font-medium text-slate-600 hover:text-green-600 hover:bg-slate-50 transition-colors"
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
                                            d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"
                                        ></path>
                                    </svg>
                                    Profil & Pengaturan
                                </Link>
                                <button
                                    type="button"
                                    @click="requestNotificationPermission"
                                    :disabled="fcmLoading || fcmTokenSiap"
                                    class="w-full flex items-center gap-3 px-4 py-2.5 text-sm font-medium text-slate-600 hover:text-green-600 hover:bg-slate-50 transition-colors text-left disabled:opacity-60 disabled:cursor-not-allowed"
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
                                            d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"
                                        ></path>
                                    </svg>
                                    <span class="min-w-0">
                                        <span class="block">
                                            {{
                                                fcmLoading
                                                    ? "Mengaktifkan..."
                                                    : fcmTokenSiap
                                                      ? "Notifikasi aktif"
                                                      : "Aktifkan Notifikasi"
                                            }}
                                        </span>
                                        <span
                                            v-if="fcmStatus"
                                            class="block text-[10px] font-normal text-slate-400 mt-0.5 truncate"
                                            >{{ fcmStatus }}</span
                                        >
                                    </span>
                                </button>
                                <Link
                                    :href="route('profile.edit') + '#password'"
                                    class="flex items-center gap-3 px-4 py-2.5 text-sm font-medium text-slate-600 hover:text-green-600 hover:bg-slate-50 transition-colors"
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
                                            d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"
                                        ></path>
                                    </svg>
                                    Ubah Password
                                </Link>
                                <div class="h-px bg-slate-100 my-1"></div>
                                <Link
                                    :href="route('logout')"
                                    method="post"
                                    as="button"
                                    :data="{ fcm_token: fcmTokenSaatIni }"
                                    class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-red-600 hover:bg-red-50 transition-colors text-left font-medium cursor-pointer"
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
                                            d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"
                                        ></path>
                                    </svg>
                                    Keluar Aplikasi
                                </Link>
                            </div>
                        </transition>
                    </div>
                </div>
            </header>

            <!-- AREA KONTEN -->
            <main
                class="flex-1 min-w-0 p-4 sm:p-6 md:p-8 overflow-y-auto relative z-10"
            >
                <!-- TAB REKAP (HP, Admin HR): pindah antara Rekap Kuota & Rekap Laporan -->
                <div
                    v-if="showRekapTabs"
                    class="md:hidden mb-4 flex w-full rounded-xl border border-slate-200 bg-white p-1 shadow-sm"
                    role="tablist"
                    aria-label="Pilih jenis rekap"
                >
                    <Link
                        v-for="tab in rekapTabs"
                        :key="tab.key"
                        :href="tab.href"
                        role="tab"
                        :aria-selected="tab.active"
                        class="flex-1 rounded-lg px-3 py-2 text-center text-xs font-bold transition-all"
                        :class="
                            tab.active
                                ? 'bg-green-600 text-white shadow-sm'
                                : 'text-slate-500 hover:text-slate-700'
                        "
                    >
                        {{ tab.label }}
                    </Link>
                </div>

                <transition name="fade" mode="out-in">
                    <slot />
                </transition>
            </main>

            <!-- FOOTER BAWAH KACA
                 pb-20 hanya di HP: memberi ruang agar footer tidak tertutup
                 Bottom Navigation yang fixed di bawah layar. -->
            <footer
                class="border-t border-slate-200/50 py-4 pb-20 md:pb-4 px-4 text-center text-[11px] font-medium text-slate-500 shrink-0 bg-white/40 backdrop-blur-sm relative z-10"
            >
                &copy; 2026 Kementerian Pertanian Republik Indonesia - Biro Perencanaan.
            </footer>
        </div>

        <!-- BOTTOM NAVIGATION (khusus HP)
             Fixed di bawah layar. Isi menu menyesuaikan role (4 menu, atau 5 menu
             untuk Kepala Biro / role 6). Disembunyikan di md ke atas.
             z-30 supaya tetap di bawah drawer sidebar (z-50) & backdrop (z-40).
             pb safe-area agar tidak tertutup home-indicator iPhone. -->
        <nav
            v-if="user"
            class="md:hidden fixed bottom-0 inset-x-0 z-30 bg-white border-t border-slate-200 shadow-[0_-4px_12px_rgba(15,23,42,0.06)] pb-[env(safe-area-inset-bottom)]"
            aria-label="Navigasi bawah"
        >
            <ul class="grid" :class="bottomNavGridClass">
                <li v-for="item in bottomNavItems" :key="item.key">
                    <Link
                        :href="item.href"
                        :aria-current="item.active ? 'page' : undefined"
                        class="relative flex flex-col items-center justify-center gap-0.5 py-2 text-[10px] font-semibold transition-colors"
                        :class="
                            item.active
                                ? 'text-green-600'
                                : 'text-slate-400 hover:text-slate-600'
                        "
                    >
                        <span
                            v-if="item.active"
                            class="absolute top-0 h-0.5 w-8 rounded-b-full bg-green-500"
                        ></span>
                        <svg
                            class="w-6 h-6"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                :stroke-width="item.active ? 2.25 : 1.75"
                                :d="item.icon"
                            ></path>
                        </svg>
                        <span class="truncate max-w-full px-1">{{
                            item.label
                        }}</span>
                    </Link>
                </li>
            </ul>
        </nav>
    </div>
</template>

<style>
/* Animasi Transisi Halaman */
.fade-enter-active,
.fade-leave-active {
    transition:
        opacity 0.3s ease,
        transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}
.fade-enter-from,
.fade-leave-to {
    opacity: 0;
    transform: translateY(15px);
}

/* Kustomisasi Scrollbar Khusus Sidebar */
.custom-scrollbar::-webkit-scrollbar {
    width: 4px;
}
.custom-scrollbar::-webkit-scrollbar-track {
    background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
    background: #334155;
    border-radius: 10px;
}
.custom-scrollbar:hover::-webkit-scrollbar-thumb {
    background: #475569;
}
</style>