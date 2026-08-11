<script setup>
import { computed, ref, watch } from "vue";
import { Link, usePage, router } from "@inertiajs/vue3";

const page = usePage();
const user = computed(() => page.props.auth.user);

const isActive = (routeName) => {
    return route().current(routeName) || route().current(routeName + ".*");
};

const isProfileOpen = ref(false);
const isSidebarOpen = ref(true);
const isNotifOpen = ref(false);

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

// Fungsi Tandai Semua Dibaca
const markAllAsRead = () => {
    router.post(
        route("notifications.readAll"),
        {},
        { 
            preserveScroll: true,
            onSuccess: () => {
                // PERBAIKAN: Jangan dikosongkan ([]), tapi ubah status is_read menjadi true.
                // Ini akan membuat notifikasi tetap ada di list, tapi warnanya menjadi pudar 
                // dan indikator lonceng merahnya akan hilang.
                notifications.value = notifications.value.map((n) => ({
                    ...n,
                    is_read: true,
                }));
            }
        }
    );
};

// Fungsi Hapus Notifikasi per Item
const hapusNotifikasi = (id) => {
    router.delete(
        route("notifikasi.destroy", id),
        { 
            preserveScroll: true,
            onSuccess: () => {
                // Filter array lokal agar item yang diklik langsung menghilang dari list
                notifications.value = notifications.value.filter((n) => n.id !== id);
            }
        }
    );
};
</script>

<template>
    <!-- BACKGROUND UTAMA GRADASI SOFT -->
    <div class="min-h-screen flex font-sans bg-gradient-to-br from-[#e0f2f1] via-[#f0f9ff] to-[#e0f2fe]">
        
        <!-- SIDEBAR PREMIUM GRADASI GELAP -->
        <aside
            :class="[
                'bg-gradient-to-b from-[#1e293b] via-[#0f172a] to-[#020617] border-r border-slate-700/50 flex flex-col transition-all duration-300 shadow-[4px_0_24px_rgba(0,0,0,0.15)] z-40 hidden md:flex text-white h-screen fixed top-0 left-0',
                isSidebarOpen ? 'w-64' : 'w-20',
            ]"
        >
            <div
                class="h-20 flex items-center border-b border-slate-700/50 shrink-0 px-4 overflow-hidden"
                :class="
                    isSidebarOpen ? 'justify-start gap-3' : 'justify-center'
                "
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
                    v-show="isSidebarOpen"
                    class="transition-opacity duration-200 whitespace-nowrap"
                >
                    <span
                        class="block text-base font-bold text-white tracking-wide leading-tight"
                        >Agri<span class="text-green-500">Leave</span></span
                    >
                    <span
                        class="block text-[9px] text-slate-400 uppercase tracking-wider"
                        >Kementerian Pertanian</span
                    >
                </div>
            </div>

            <nav class="flex-1 overflow-y-auto py-6 px-3 space-y-1 custom-scrollbar">
                <p
                    v-show="isSidebarOpen"
                    class="px-3 text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-2 mt-2"
                >
                    Main Menu
                </p>
                <!-- MENU AKTIF DENGAN GLOW EFFECT -->
                <Link
                    :href="route('dashboard')"
                    :title="!isSidebarOpen ? 'Dashboard' : ''"
                    :class="[
                        'flex items-center gap-3 px-3 py-2.5 rounded-xl font-medium text-sm transition-all duration-300',
                        isActive('dashboard')
                            ? 'bg-gradient-to-r from-green-500 to-green-600 text-white shadow-lg shadow-green-500/30 border border-green-400/20'
                            : 'text-slate-400 hover:bg-slate-800/50 hover:text-white',
                        !isSidebarOpen ? 'justify-center' : '',
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
                    <span v-show="isSidebarOpen" class="truncate"
                        >Dashboard</span
                    >
                </Link>

                <div v-if="[1, 2, 3, 4].includes(user.role_id)">
                    <p
                        v-show="isSidebarOpen"
                        class="px-3 text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-2 mt-6"
                    >
                        Employee
                    </p>
                    <Link
                        :href="route('karyawan.ajukan')"
                        :title="!isSidebarOpen ? 'Ajukan Cuti' : ''"
                        :class="[
                            'flex items-center gap-3 px-3 py-2.5 rounded-xl font-medium text-sm transition-all duration-300',
                            isActive('karyawan.ajukan')
                                ? 'bg-gradient-to-r from-green-500 to-green-600 text-white shadow-lg shadow-green-500/30 border border-green-400/20'
                                : 'text-slate-400 hover:bg-slate-800/50 hover:text-white',
                            !isSidebarOpen ? 'justify-center' : '',
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
                        <span v-show="isSidebarOpen" class="truncate"
                            >Ajukan Cuti</span
                        >
                    </Link>
                    <Link
                        :href="route('karyawan.riwayat')"
                        :title="!isSidebarOpen ? 'Riwayat Pengajuan' : ''"
                        :class="[
                            'flex items-center gap-3 px-3 py-2.5 rounded-xl font-medium text-sm transition-all duration-300 mt-1',
                            isActive('karyawan.riwayat')
                                ? 'bg-gradient-to-r from-green-500 to-green-600 text-white shadow-lg shadow-green-500/30 border border-green-400/20'
                                : 'text-slate-400 hover:bg-slate-800/50 hover:text-white',
                            !isSidebarOpen ? 'justify-center' : '',
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
                        <span v-show="isSidebarOpen" class="truncate"
                            >Riwayat Pengajuan</span
                        >
                    </Link>
                    <Link
                        :href="route('karyawan.kalender')"
                        :title="!isSidebarOpen ? 'Status Tim' : ''"
                        :class="[
                            'flex items-center gap-3 px-3 py-2.5 rounded-xl font-medium text-sm transition-all duration-300 mt-1',
                            isActive('karyawan.kalender')
                                ? 'bg-gradient-to-r from-green-500 to-green-600 text-white shadow-lg shadow-green-500/30 border border-green-400/20'
                                : 'text-slate-400 hover:bg-slate-800/50 hover:text-white',
                            !isSidebarOpen ? 'justify-center' : '',
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
                        <span v-show="isSidebarOpen" class="truncate"
                            >Status Tim</span
                        >
                    </Link>
                </div>

                <div v-if="[2, 3, 4].includes(user.role_id)">
                    <p
                        v-show="isSidebarOpen"
                        class="px-3 text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-2 mt-6"
                    >
                        Managerial
                    </p>
                    <Link
                        :href="route('atasan.approval')"
                        :title="!isSidebarOpen ? 'Approval Cuti' : ''"
                        :class="[
                            'flex items-center gap-3 px-3 py-2.5 rounded-xl font-medium text-sm transition-all duration-300',
                            isActive('atasan.approval')
                                ? 'bg-gradient-to-r from-green-500 to-green-600 text-white shadow-lg shadow-green-500/30 border border-green-400/20'
                                : 'text-slate-400 hover:bg-slate-800/50 hover:text-white',
                            !isSidebarOpen ? 'justify-center' : '',
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
                        <span v-show="isSidebarOpen" class="truncate"
                            >Approval Cuti</span
                        >
                    </Link>
                    <Link
                        :href="route('atasan.pembatalan')"
                        :title="!isSidebarOpen ? 'Batalkan Cuti' : ''"
                        :class="[
                            'flex items-center gap-3 px-3 py-2.5 rounded-xl font-medium text-sm transition-all duration-300 mt-1',
                            isActive('atasan.pembatalan')
                                ? 'bg-gradient-to-r from-green-500 to-green-600 text-white shadow-lg shadow-green-500/30 border border-green-400/20'
                                : 'text-slate-400 hover:bg-slate-800/50 hover:text-white',
                            !isSidebarOpen ? 'justify-center' : '',
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
                        <span v-show="isSidebarOpen" class="truncate"
                            >Batalkan Cuti</span
                        >
                    </Link>
                </div>

                <div v-if="user.role_id === 5">
                    <p
                        v-show="isSidebarOpen"
                        class="px-3 text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-2 mt-6"
                    >
                        HR Admin
                    </p>
                    <Link
                        :href="route('admin.rekap')"
                        :title="!isSidebarOpen ? 'Rekap Laporan' : ''"
                        :class="[
                            'flex items-center gap-3 px-3 py-2.5 rounded-xl font-medium text-sm transition-all duration-300',
                            isActive('admin.rekap')
                                ? 'bg-gradient-to-r from-green-500 to-green-600 text-white shadow-lg shadow-green-500/30 border border-green-400/20'
                                : 'text-slate-400 hover:bg-slate-800/50 hover:text-white',
                            !isSidebarOpen ? 'justify-center' : '',
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
                        <span v-show="isSidebarOpen" class="truncate"
                            >Rekap Laporan</span
                        >
                    </Link>
                </div>
            </nav>

            <div class="p-3 bg-[#020617]/50 border-t border-slate-800/50 shrink-0">
                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    :title="!isSidebarOpen ? 'Logout' : ''"
                    :class="[
                        'w-full flex items-center justify-center gap-2 px-4 py-2 bg-slate-800/60 hover:bg-slate-700/80 text-slate-300 hover:text-white border border-slate-700/50 rounded-xl text-sm font-medium transition-all duration-200',
                        !isSidebarOpen ? 'px-2' : '',
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
                    <span v-show="isSidebarOpen" class="truncate">Logout</span>
                </Link>
            </div>
        </aside>

        <!-- KONTEN UTAMA -->
        <div
            :class="[
                'flex-1 flex flex-col min-h-screen transition-all duration-300 relative',
                isSidebarOpen ? 'ml-0 md:ml-64' : 'ml-0 md:ml-20',
            ]"
        >
            <!-- HEADER KACA (GLASSMORPHISM) -->
            <header
                class="h-20 bg-white/70 backdrop-blur-xl border-b border-white/50 flex items-center justify-between px-6 md:px-8 z-30 sticky top-0 shadow-sm"
            >
                <div class="flex items-center gap-4">
                    <button
                        @click="isSidebarOpen = !isSidebarOpen"
                        class="p-2.5 rounded-xl bg-white hover:bg-green-50 text-slate-700 transition focus:outline-none focus:ring-2 focus:ring-green-500 shadow-sm border border-slate-100"
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
                            ></path>
                        </svg>
                    </button>
                    <h2
                        class="text-xl font-extrabold text-slate-800 hidden md:block tracking-tight"
                    >
                        Portal Cuti Digital Terintegrasi
                    </h2>
                </div>

                <div class="flex items-center gap-6">
                    <span
                        class="text-xs font-semibold text-green-700 bg-green-50 border border-green-200 px-3 py-1.5 rounded-full hidden sm:inline-block shadow-sm"
                    >
                        {{ user.departemen }}
                    </span>

                    <div class="relative">
                        <!-- PEMBARUAN: Tombol Notifikasi menggunakan Gambar Lonceng 3D -->
                        <button
                            @click="
                                isNotifOpen = !isNotifOpen;
                                isProfileOpen = false;
                            "
                            class="relative focus:outline-none p-1.5 rounded-xl hover:bg-white/50 cursor-pointer transition-all hover:scale-110"
                        >
                            <img src="/images/lonceng.png" alt="Notifikasi" class="w-7 h-7 drop-shadow-md" />
                            <span
                                v-if="unreadCount > 0"
                                class="absolute -top-1 -right-1 w-5 h-5 bg-red-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center ring-2 ring-white shadow-sm"
                            >
                                {{ unreadCount }}
                            </span>
                        </button>

                        <div
                            v-if="isNotifOpen"
                            @click="isNotifOpen = false"
                            class="fixed inset-0 z-40"
                        ></div>

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
                                class="absolute right-0 top-full mt-3 w-80 bg-white/95 backdrop-blur-md rounded-2xl shadow-2xl border border-white/50 overflow-hidden z-50 text-left"
                            >
                                <!-- Header Dropdown -->
                                <div class="px-4 py-3.5 bg-slate-50/80 border-b border-slate-100/50 flex justify-between items-center">
                                    <div class="flex items-center gap-2">
                                        <div class="w-2 h-2 rounded-full bg-green-500 animate-pulse"></div>
                                        <h3 class="font-bold text-sm text-slate-800 tracking-tight">Notifikasi Pengajuan</h3>
                                    </div>
                                    <button @click="markAllAsRead" class="text-[10px] bg-green-100 text-green-700 font-extrabold px-2 py-0.5 rounded-full tracking-wide hover:bg-green-200 transition cursor-pointer">
                                        TANDAI SEMUA DIBACA
                                    </button>
                                </div>

                                <!-- Daftar Item Notifikasi -->
                                <div class="max-h-72 overflow-y-auto divide-y divide-slate-50">
                                    <template v-if="notifications && notifications.length > 0">
                                        <div
                                            v-for="notif in notifications"
                                            :key="notif.id"
                                            class="p-3.5 hover:bg-slate-50 transition-all duration-150 flex items-start gap-3 group"
                                            :class="{'bg-white': !notif.is_read, 'bg-slate-50/30 opacity-80': notif.is_read}"
                                        >
                                            <button 
                                                @click.prevent="hapusNotifikasi(notif.id)" 
                                                class="shrink-0 mt-0.5 transition-transform hover:scale-110 focus:outline-none cursor-pointer"
                                                title="Klik untuk menghapus notifikasi"
                                            >
                                                <div v-if="notif.pesan.toLowerCase().includes('tolak') || notif.pesan.toLowerCase().includes('batal') || notif.judul.toLowerCase().includes('tolak')" 
                                                     class="bg-red-50 text-red-500 p-2 rounded-full flex items-center justify-center border border-red-100 shadow-sm">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"></path></svg>
                                                </div>
                                                <div v-else 
                                                     class="bg-green-50 text-green-500 p-2 rounded-full flex items-center justify-center border border-green-100 shadow-sm">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
                                                </div>
                                            </button>
                                            
                                            <div class="flex-1 min-w-0 pr-2">
                                                <p class="text-xs font-bold text-slate-800 leading-snug group-hover:text-green-700 transition-colors cursor-default">
                                                    {{ notif.judul }}
                                                </p>
                                                <p class="text-[11px] text-slate-500 mt-1 leading-relaxed cursor-default">
                                                    {{ notif.pesan }}
                                                </p>
                                                <p class="text-[10px] text-slate-400 font-medium mt-1.5 flex items-center gap-1 cursor-default">
                                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                    </svg>
                                                    {{ new Date(notif.created_at).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) }}
                                                </p>
                                            </div>
                                            <span v-if="!notif.is_read" class="w-2 h-2 bg-green-500 rounded-full mt-1.5 shrink-0 shadow-sm"></span>
                                        </div>
                                    </template>
                                    
                                    <!-- PEMBARUAN: Kondisi Kosong juga menggunakan Ikon 3D -->
                                    <div v-else class="px-6 py-8 text-center bg-white/50">
                                        <div class="w-14 h-14 mx-auto mb-3 flex items-center justify-center">
                                            <img src="/images/lonceng.png" alt="Kosong" class="w-full h-full object-contain opacity-70 grayscale-[30%]" />
                                        </div>
                                        <p class="text-xs font-semibold text-slate-500">Belum ada notifikasi</p>
                                    </div>
                                </div>

                                <!-- Footer Dropdown -->
                                <div class="p-2.5 bg-slate-50/80 border-t border-slate-100/50 text-center">
                                    <Link
                                        :href="route('karyawan.riwayat')"
                                        @click="isNotifOpen = false"
                                        class="text-[11px] font-bold text-green-600 hover:text-green-700 transition-colors inline-flex items-center gap-1 py-1"
                                    >
                                        Lihat Riwayat Pengajuan
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                        </svg>
                                    </Link>
                                </div>
                            </div>
                        </transition>
                    </div>

                    <div class="h-6 w-px bg-slate-200"></div>

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
                                    alt="Profile"
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

                        <div
                            v-if="isProfileOpen"
                            @click="isProfileOpen = false"
                            class="fixed inset-0 z-40"
                        ></div>

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
                                class="absolute right-0 mt-2 w-56 bg-white/95 backdrop-blur-md rounded-2xl shadow-xl border border-white/50 py-2 z-50"
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

            <!-- AREA KONTEN (TRANSFOMASI TRANSPARAN) -->
            <main class="flex-1 p-6 md:p-8 overflow-y-auto relative z-10">
                <transition name="fade" mode="out-in">
                    <slot />
                </transition>
            </main>

            <!-- FOOTER BAWAH KACA -->
            <footer
                class="border-t border-slate-200/50 py-4 text-center text-[11px] font-medium text-slate-500 shrink-0 bg-white/40 backdrop-blur-sm relative z-10"
            >
                &copy; 2026 Kementerian Pertanian Republik Indonesia - Biro
                Organisasi dan SDM Aparatur.
            </footer>
        </div>
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