import '../css/app.css';
import './bootstrap';


import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';


const appName = import.meta.env.VITE_APP_NAME || 'Laravel';


// ---------------------------------------------------------------------------
// Penanda "sudah logout" per tab (sessionStorage). Dipasang saat logout dan
// dihapus saat login berhasil dikirim. Dipakai untuk memastikan halaman lama
// (yang muncul lewat tombol Back) selalu dialihkan ke /login.
// ---------------------------------------------------------------------------
const KUNCI_LOGOUT = 'sudah_logout';


const bacaPenanda = () => {
    try {
        return window.sessionStorage.getItem(KUNCI_LOGOUT) === '1';
    } catch (e) {
        return false;
    }
};


const aturPenanda = (aktif) => {
    try {
        if (aktif) {
            window.sessionStorage.setItem(KUNCI_LOGOUT, '1');
        } else {
            window.sessionStorage.removeItem(KUNCI_LOGOUT);
        }
    } catch (e) {
        // sessionStorage tidak tersedia: abaikan
    }
};


const harusKeLogin = () =>
    bacaPenanda() && !window.location.pathname.startsWith('/login');


// Sembunyikan / tampilkan seluruh halaman (dipakai agar data tidak terlihat).
const sembunyikanHalaman = () => {
    document.documentElement.style.visibility = 'hidden';
};


const tampilkanHalaman = () => {
    document.documentElement.style.visibility = '';
};


if (harusKeLogin()) {
    // Halaman lama dibuka lagi setelah logout: sembunyikan lalu ganti ke
    // /login tanpa merender aplikasi (tidak ada kilatan data).
    sembunyikanHalaman();
    window.location.replace('/login');
} else {
    createInertiaApp({
        title: (title) => `${title} - ${appName}`,
        resolve: (name) =>
            resolvePageComponent(
                `./Pages/${name}.vue`,
                import.meta.glob('./Pages/**/*.vue'),
            ),
        setup({ el, App, props, plugin }) {
            return createApp({ render: () => h(App, props) })
                .use(plugin)
                .use(ZiggyVue)
                .mount(el);
        },
        progress: {
            color: '#4B5563',
        },
    });
}


// Logout:
//   1) pasang penanda "sudah logout",
//   2) SEMBUNYIKAN halaman saat ini, supaya "foto" halaman yang disimpan
//      browser untuk tombol Back (BFCache) sudah kosong, bukan berisi data,
//   3) hancurkan riwayat halaman Inertia (router.clearHistory() hanya ada di
//      Inertia v2; dicek dulu agar tidak error di versi lama).
// Login: hapus penanda supaya halaman normal lagi.
router.on('before', (event) => {
    const { url, method } = event.detail.visit;
    const path = typeof url === 'string' ? url : url.pathname;


    if (path.includes('/logout')) {
        aturPenanda(true);
        sembunyikanHalaman();


        if (typeof router.clearHistory === 'function') {
            router.clearHistory();
        }
    }


    if (path.startsWith('/login') && String(method).toLowerCase() === 'post') {
        aturPenanda(false);
    }
});


// Jika permintaan logout gagal (error jaringan/server), pulihkan tampilan dan
// batalkan penanda supaya pengguna tidak terjebak di layar kosong.
const pulihkanJikaLogoutGagal = () => {
    aturPenanda(false);
    tampilkanHalaman();
};
router.on('error', pulihkanJikaLogoutGagal);
router.on('exception', pulihkanJikaLogoutGagal);
// Sesi habis di server: token CSRF tidak valid sehingga request apa pun
// mendapat 419. Alihkan ke /login, jangan tampilkan halaman error mentah.
router.on('invalid', (event) => {
    if (event.detail.response.status === 419) {
        event.preventDefault();
        sembunyikanHalaman();
        window.location.replace('/login');
    }
});

// Halaman dipulihkan lewat tombol Back/Forward (BFCache atau navigasi
// back_forward):
//   - sudah logout  -> sembunyikan seketika, lalu ganti ke /login
//   - masih login   -> muat ulang agar server memeriksa sesi
// Reload hanya terjadi sekali (setelah itu tipe navigasi menjadi 'reload').
window.addEventListener('pageshow', (event) => {
    const navEntry = window.performance?.getEntriesByType?.('navigation')?.[0];
    const dariBackForward = navEntry?.type === 'back_forward';


    if (event.persisted || dariBackForward) {
        if (harusKeLogin()) {
            sembunyikanHalaman();
            window.location.replace('/login');
        } else {
            window.location.reload();
        }
    }
});

