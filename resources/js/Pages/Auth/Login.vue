<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps({
    canResetPassword: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});

const form = useForm({
    nip: '',
    password: '',
    remember: false,
});

const showPassword = ref(false);

const togglePassword = () => {
    showPassword.value = !showPassword.value;
};

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};

// State untuk menampilkan Modal Panduan Informasi
const showInfoModal = ref(false);

// Fungsi untuk memunculkan pop-up saat tulisan Lupa Password diklik
const handleForgotPassword = () => {
    alert("Hubungi Kepegawaian");
};
</script>

<template>
    <Head title="Log in" />

    <div class="min-h-screen flex relative font-sans bg-white overflow-hidden">
        
        <!-- FLOATING HEADER UTAMA -->
        <div class="absolute top-4 left-0 right-0 z-50 flex justify-center px-4 md:px-8">
            <div class="bg-white rounded-full shadow-md px-6 py-2.5 flex items-center justify-between w-full max-w-6xl border border-slate-100">
                
                <!-- Kiri: Logo & Teks Kementan -->
                <div class="flex items-center gap-3">
                    <img src="/images/logo-kementan.png" alt="Logo Kementan" class="h-10 md:h-11 object-contain" />
                    <div class="hidden sm:flex sm:flex-col justify-center pt-0.5">
                        <h1 class="font-bold text-slate-800 text-[11px] md:text-[13px] leading-tight mb-0.5">KEMENTERIAN PERTANIAN</h1>
                        <p class="text-[9px] md:text-[11px] text-slate-500 leading-tight">Ministry of Indonesia</p>
                    </div>
                </div>
                
                <!-- Tengah: Garis Pemisah & Teks Portal -->
                <div class="hidden lg:flex items-center gap-4 pl-4">
                    <div class="h-8 w-[1.5px] bg-slate-200"></div>
                    <div class="text-slate-800 font-bold text-[15px] tracking-wide pt-0.5">
                        AgriLeave <span class="text-slate-500 font-normal ml-1">- Portal Cuti Digital Terintegrasi</span>
                    </div>
                </div>

                <!-- Kanan: Tombol Informasi -->
                <div class="ml-auto">
                    <button 
                        type="button"
                        @click="showInfoModal = true"
                        class="bg-[#8c439e] hover:bg-[#7a398a] text-white text-[11px] md:text-xs font-bold px-5 py-2.5 rounded-lg transition-colors shadow-sm tracking-wide"
                    >
                        INFORMASI
                    </button>
                </div>
            </div>
        </div>

        <!-- SISI KIRI: ILUSTRASI SMART FARMING -->
        <div class="hidden lg:block lg:w-[55%] relative bg-[#133c36]">
            <img src="/images/ilustrasi-farm.png" alt="Ilustrasi Smart Farming" class="absolute inset-0 w-full h-full object-cover object-right" />
        </div>

        <!-- SISI KANAN: FORMULIR LOGIN -->
        <div class="w-full lg:w-[45%] flex flex-col justify-center px-8 sm:px-16 md:px-24 relative z-10 bg-white pt-24 lg:pt-0">
            <div class="w-full max-w-[360px] mx-auto">
                
                <!-- Logo AgriLeave -->
                <div class="flex items-center gap-3 mb-8">
                    <img src="/images/logo-agrileave-solo.png" alt="Logo AgriLeave" class="h-10 w-auto" />
                    <span class="text-[26px] font-medium text-[#2d4c42] tracking-tight pt-1">AgriLeave</span>
                </div>

                <!-- Judul & Subjudul -->
                <div class="mb-8">
                    <h2 class="text-[28px] font-bold text-[#357a55] mb-2">Selamat Datang</h2>
                    <p class="text-slate-600 text-[13px] leading-relaxed">
                        Masukkan NIP dan password Anda untuk masuk ke sistem.
                    </p>
                </div>

                <!-- Alert Status -->
                <div v-if="status" class="mb-4 text-sm font-medium text-green-600 bg-green-50 p-3 rounded-md border border-green-200">
                    {{ status }}
                </div>

                <form @submit.prevent="submit" class="space-y-5">
                    
                    <!-- Input NIP -->
                    <div class="space-y-1.5">
                        <label for="nip" class="block text-xs font-bold text-slate-800">NIP</label>
                        <input
                            id="nip"
                            type="text"
                            class="w-full px-4 py-3 bg-[#f4f7fb] border border-transparent rounded-lg text-slate-800 text-[13px] focus:bg-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all outline-none"
                            v-model="form.nip"
                            required
                            autofocus
                            autocomplete="username"
                            placeholder="1999088777"
                        />
                        <div v-if="form.errors.nip" class="text-red-500 text-xs mt-1 font-semibold">{{ form.errors.nip }}</div>
                    </div>

                    <!-- Input Password -->
                    <div class="space-y-1.5 relative">
                        <label for="password" class="block text-xs font-bold text-slate-800">Password</label>
                        <div class="relative">
                            <input
                                id="password"
                                :type="showPassword ? 'text' : 'password'"
                                class="w-full pl-4 pr-11 py-3 bg-[#f4f7fb] border border-transparent rounded-lg text-slate-800 text-[13px] focus:bg-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all outline-none"
                                v-model="form.password"
                                required
                                autocomplete="current-password"
                                placeholder="••••••••"
                            />
                            <!-- Tombol Mata (Toggle Password) -->
                            <button type="button" @click="togglePassword" class="absolute right-3 top-1/2 -translate-y-1/2 p-1 text-slate-400 hover:text-slate-600 transition-colors">
                                <svg v-if="!showPassword" class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                                <svg v-else class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.29 3.29m0 0a10.05 10.05 0 015.188-1.528c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"></path></svg>
                            </button>
                        </div>
                        <div v-if="form.errors.password" class="text-red-500 text-xs mt-1 font-semibold">{{ form.errors.password }}</div>
                    </div>

                    <!-- Ingat Saya -->
                    <div class="pt-1">
                        <label class="flex items-center gap-2.5 cursor-pointer w-max group">
                            <input 
                                type="checkbox" 
                                v-model="form.remember" 
                                class="w-[18px] h-[18px] rounded border-slate-300 text-blue-600 bg-slate-50 focus:ring-blue-500 focus:ring-offset-0 cursor-pointer transition-colors" 
                            />
                            <span class="text-[13px] text-slate-600 font-medium group-hover:text-slate-900 transition-colors">Ingat saya</span>
                        </label>
                    </div>

                    <!-- Tombol Masuk -->
                    <div class="pt-3">
                        <button
                            type="submit"
                            class="w-full py-3 bg-[#1c75f2] hover:bg-[#1560c9] text-white font-bold text-[13px] tracking-wide rounded-lg shadow-md transition-all flex justify-center items-center active:scale-[0.98]"
                            :class="{ 'opacity-70 cursor-not-allowed': form.processing }"
                            :disabled="form.processing"
                        >
                            <span v-if="form.processing" class="flex items-center gap-2">
                                <svg class="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                                MEMPROSES...
                            </span>
                            <span v-else>MASUK</span>
                        </button>
                    </div>

                    <!-- Lupa Password (Modifikasi sesuai request) -->
                    <div class="text-center pt-2">
                        <button
                            v-if="canResetPassword"
                            type="button"
                            @click.prevent="handleForgotPassword"
                            class="text-[13px] font-bold text-slate-700 hover:text-[#1c75f2] transition-colors hover:underline bg-transparent border-none cursor-pointer p-0"
                        >
                            Lupa password?
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- FOOTER BAWAH (Diubah menjadi warna hitam) -->
        <div class="absolute bottom-0 left-0 right-0 py-4 flex justify-center items-center px-6 z-50 bg-white/70 backdrop-blur-sm lg:bg-white/40">
            <div class="text-center text-[11px] text-black font-semibold drop-shadow-sm">
                &copy; 2026 Kementerian Pertanian Republik Indonesia - Biro Organisasi dan Kepegawaian.
            </div>
        </div>
    </div>

    <!-- POP-UP MODAL INFORMASI PANDUAN PENGGUNAAN -->
    <Teleport to="body">
        <div v-if="showInfoModal" class="fixed inset-0 z-[9999] flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4" @click.self="showInfoModal = false">
            <div class="bg-white rounded-2xl max-w-2xl w-full shadow-2xl border border-slate-100 overflow-hidden transform animate-in zoom-in duration-200 flex flex-col max-h-[90vh]">
                
                <!-- Modal Header -->
                <div class="p-5 border-b border-slate-100 flex items-start gap-4 shrink-0 bg-slate-50/50">
                    <div class="bg-purple-100 text-[#8c439e] p-3 rounded-xl">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    </div>
                    <div class="flex-1 pt-1">
                        <h3 class="text-lg font-bold text-slate-800 leading-tight">Panduan Penggunaan AgriLeave</h3>
                        <p class="text-xs font-semibold text-slate-500 mt-1">Pusat Informasi & Bantuan Portal Cuti Digital Terintegrasi</p>
                    </div>
                    <button type="button" @click="showInfoModal = false" class="text-slate-400 hover:text-slate-600 hover:bg-slate-100 p-2 rounded-full transition cursor-pointer">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </button>
                </div>

                <!-- Modal Body -->
                <div class="p-6 overflow-y-auto flex-1 space-y-6 text-sm text-slate-600">
                    
                    <div>
                        <h4 class="font-bold text-slate-800 text-[15px] mb-2 flex items-center gap-2">
                            <span class="bg-[#8c439e] text-white w-5 h-5 rounded-full flex items-center justify-center text-xs">1</span>
                            Cara Masuk (Login)
                        </h4>
                        <ul class="list-disc pl-9 space-y-1.5">
                            <li>Masukkan Nomor Induk Pegawai (NIP) Anda yang terdaftar pada sistem kepegawaian.</li>
                            <li>Masukkan kata sandi (password). Jika Anda baru pertama kali login, gunakan password bawaan (default) yang diberikan oleh Admin HR.</li>
                            <li>Klik tombol biru <strong>MASUK</strong>.</li>
                        </ul>
                    </div>

                    <div>
                        <h4 class="font-bold text-slate-800 text-[15px] mb-2 flex items-center gap-2">
                            <span class="bg-[#8c439e] text-white w-5 h-5 rounded-full flex items-center justify-center text-xs">2</span>
                            Lupa Kata Sandi
                        </h4>
                        <ul class="list-disc pl-9 space-y-1.5">
                            <li>Karena alasan keamanan, pemulihan kata sandi tidak dapat dilakukan secara mandiri.</li>
                            <li>Silakan hubungi Admin Kepegawaian atau atasan langsung Anda untuk mengajukan permintaan reset kata sandi.</li>
                            <li>Gunakan kata sandi sementara yang diberikan Admin untuk login, lalu segera perbarui kata sandi Anda di menu Pengaturan Akun.</li>
                        </ul>
                    </div>

                    <div class="bg-blue-50 border border-blue-100 rounded-xl p-4 flex gap-3">
                        <svg class="w-5 h-5 text-blue-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                        <div>
                            <strong class="text-blue-800 block mb-1">Kendala Teknis?</strong>
                            <span class="text-blue-700 text-xs leading-relaxed">
                                Jika Anda masih tidak dapat mengakses portal setelah mencoba langkah di atas, silakan hubungi Biro Organisasi dan Kepegawaian (Subkoordinator Disiplin dan Penghargaan) melalui Helpdesk Internal atau ext: 1234.
                            </span>
                        </div>
                    </div>

                </div>

                <!-- Modal Footer -->
                <div class="p-4 border-t border-slate-100 bg-slate-50 flex justify-end shrink-0">
                    <button type="button" @click="showInfoModal = false" class="px-6 py-2.5 bg-slate-800 hover:bg-slate-900 text-white rounded-lg text-[13px] font-bold tracking-wide transition shadow-sm">
                        Mengerti, Tutup
                    </button>
                </div>

            </div>
        </div>
    </Teleport>

</template>