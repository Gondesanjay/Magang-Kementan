import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                brand: {
                    50: '#eefbf3',
                    100: '#d6f5e3',
                    200: '#aeebc9',
                    300: '#7bdaa8',
                    400: '#45c184',
                    500: '#22a866', // hijau utama (link, focus ring, CTA)
                    600: '#158752', // hover state tombol/link utama
                    700: '#106b43', // teks aktif (tab, dsb)
                    800: '#0f5537',
                    900: '#0d472f',
                    950: '#062a1c',
                },
                surface: {
                    DEFAULT: '#f4f7f9', // background halaman
                    card: '#ffffff',
                    dark: '#0b3324', // background sidebar gelap
                },
                status: {
                    pending: '#f59e0b', // Menunggu / Ditangguhkan
                    rejected: '#ef4444', // Ditolak
                    approved: '#22c55e', // Disetujui
                },
            },
            borderRadius: {
                xl: '1rem',
                '2xl': '1.25rem',
            },
            boxShadow: {
                card: '0 1px 3px 0 rgba(16, 24, 40, 0.06), 0 1px 2px -1px rgba(16, 24, 40, 0.06)',
                'card-hover': '0 4px 12px -2px rgba(16, 24, 40, 0.10)',
            },
        },
    },

    plugins: [forms],
};