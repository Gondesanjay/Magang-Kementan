<script setup>
import { Head, useForm } from "@inertiajs/vue3";
import { computed, ref } from "vue";

const form = useForm({
    password: "",
    password_confirmation: "",
});

const showPassword = ref(false);
const showPasswordConfirmation = ref(false);

const togglePassword = () => {
    showPassword.value = !showPassword.value;
};
const togglePasswordConfirmation = () => {
    showPasswordConfirmation.value = !showPasswordConfirmation.value;
};

// Indikator kekuatan (visual saja; validasi server tetap min:8)
const passwordStrength = computed(() => {
    const p = form.password || "";
    if (!p) return { score: 0, label: "", textClass: "", barClass: "" };
    let score = 0;
    if (p.length >= 8) score++;
    if (/[a-z]/.test(p) && /[A-Z]/.test(p)) score++;
    if (/\d/.test(p)) score++;
    if (/[^A-Za-z0-9]/.test(p)) score++;
    if (score <= 1) {
        return { score: 1, label: "Lemah", textClass: "text-red-600", barClass: "bg-red-400" };
    }
    if (score === 2) {
        return { score: 2, label: "Cukup", textClass: "text-amber-600", barClass: "bg-amber-400" };
    }
    if (score === 3) {
        return { score: 3, label: "Baik", textClass: "text-lime-600", barClass: "bg-lime-500" };
    }
    return { score: 4, label: "Kuat", textClass: "text-emerald-600", barClass: "bg-emerald-500" };
});

const submit = () => {
    form.post(route("password.change.store"), {
        onFinish: () => form.reset(),
    });
};
</script>

<template>
    <Head title="Ganti Kata Sandi" />
    <div
        class="min-h-screen flex flex-col items-center justify-center relative font-sans overflow-hidden px-4 py-8"
        style="background: linear-gradient(135deg, #ecfdf5 0%, #f0fdf4 40%, #fffbeb 100%)"
    >
        <!-- Dekorasi lingkaran -->
        <div class="absolute -top-32 -left-32 w-96 h-96 bg-emerald-200/40 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute -bottom-32 -right-32 w-96 h-96 bg-amber-200/30 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute top-1/3 right-10 w-40 h-40 bg-green-200/20 rounded-full blur-2xl pointer-events-none"></div>

        <!-- Kartu utama -->
        <div
            class="relative z-10 w-full max-w-[480px] bg-white rounded-[28px] shadow-2xl shadow-emerald-900/10 border border-emerald-100 overflow-hidden"
        >
            <!-- Header gradasi hijau + aksen emas -->
            <div
                class="relative px-8 pt-8 pb-6 text-center overflow-hidden"
                style="background: linear-gradient(135deg, #064e3b 0%, #047857 50%, #059669 100%)"
            >
                <div class="absolute -top-10 -right-10 w-36 h-36 rounded-full bg-white/10"></div>
                <div class="absolute top-12 -left-8 w-24 h-24 rounded-full bg-white/5"></div>
                <div class="absolute bottom-0 left-0 right-0 h-1 bg-gradient-to-r from-transparent via-amber-400/80 to-transparent"></div>

                <div class="relative w-24 h-24 mx-auto mb-3 flex items-center justify-center drop-shadow-xl">
                    <img
                        src="/images/shield-icon.png"
                        alt="Keamanan"
                        class="w-full h-full object-contain"
                    />
                </div>
                <h1 class="relative text-2xl sm:text-[26px] font-extrabold text-white tracking-tight">
                    Ganti Kata Sandi
                </h1>
                <p class="relative text-emerald-100/90 text-xs sm:text-sm mt-1">
                    Jaga keamanan akun dengan kata sandi yang kuat
                </p>
            </div>

            <div class="px-8 sm:px-10 py-7">
                <!-- Info pertama kali -->
                <div
                    class="flex items-start gap-3 bg-emerald-50 border border-emerald-100 p-4 rounded-2xl mb-6 text-emerald-900"
                >
                    <svg class="w-5 h-5 text-emerald-600 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <p class="text-xs leading-relaxed font-medium">
                        Ini adalah kali pertama Anda masuk. Demi keamanan sistem,
                        ubah kata sandi bawaan menjadi kata sandi baru sebelum
                        melanjutkan.
                    </p>
                </div>

                <form @submit.prevent="submit" class="space-y-4">
                    <!-- Kata sandi baru -->
                    <div class="space-y-1.5">
                        <label for="password" class="block text-xs font-bold text-slate-700">
                            Kata Sandi Baru
                        </label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 pointer-events-none text-slate-400">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                </svg>
                            </span>
                            <input
                                id="password"
                                name="password"
                                :type="showPassword ? 'text' : 'password'"
                                class="w-full min-h-[44px] pl-10 pr-12 py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 text-base focus:bg-white focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 transition-all outline-none"
                                v-model="form.password"
                                required
                                autocomplete="new-password"
                                placeholder="Masukkan kata sandi baru"
                                autofocus
                            />
                            <button
                                type="button"
                                @click="togglePassword"
                                aria-label="Tampilkan atau sembunyikan kata sandi baru"
                                class="absolute right-1 top-1/2 -translate-y-1/2 flex min-h-[40px] min-w-[40px] items-center justify-center text-slate-400 hover:text-slate-600 transition-colors cursor-pointer"
                            >
                                <svg v-if="!showPassword" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                </svg>
                                <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.29 3.29m0 0a10.05 10.05 0 015.188-1.528c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                                </svg>
                            </button>
                        </div>
                        <!-- Indikator kekuatan -->
                        <div v-if="form.password" class="mt-2">
                            <div class="flex gap-1">
                                <span
                                    v-for="n in 4"
                                    :key="n"
                                    class="h-1.5 flex-1 rounded-full transition-colors"
                                    :class="n <= passwordStrength.score ? passwordStrength.barClass : 'bg-slate-200'"
                                ></span>
                            </div>
                            <p class="mt-1 text-[11px] font-semibold" :class="passwordStrength.textClass">
                                Kekuatan: {{ passwordStrength.label }}
                            </p>
                        </div>
                        <div v-if="form.errors.password" class="text-red-500 text-xs mt-1 font-semibold">
                            {{ form.errors.password }}
                        </div>
                    </div>

                    <!-- Konfirmasi -->
                    <div class="space-y-1.5">
                        <label for="password_confirmation" class="block text-xs font-bold text-slate-700">
                            Konfirmasi Kata Sandi Baru
                        </label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 pointer-events-none text-slate-400">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                </svg>
                            </span>
                            <input
                                id="password_confirmation"
                                name="password_confirmation"
                                :type="showPasswordConfirmation ? 'text' : 'password'"
                                class="w-full min-h-[44px] pl-10 pr-12 py-3 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 text-base focus:bg-white focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 transition-all outline-none"
                                v-model="form.password_confirmation"
                                required
                                autocomplete="new-password"
                                placeholder="Masukkan ulang kata sandi baru"
                            />
                            <button
                                type="button"
                                @click="togglePasswordConfirmation"
                                aria-label="Tampilkan atau sembunyikan konfirmasi kata sandi"
                                class="absolute right-1 top-1/2 -translate-y-1/2 flex min-h-[40px] min-w-[40px] items-center justify-center text-slate-400 hover:text-slate-600 transition-colors cursor-pointer"
                            >
                                <svg v-if="!showPasswordConfirmation" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                </svg>
                                <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.29 3.29m0 0a10.05 10.05 0 015.188-1.528c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                                </svg>
                            </button>
                        </div>
                        <div v-if="form.errors.password_confirmation" class="text-red-500 text-xs mt-1 font-semibold">
                            {{ form.errors.password_confirmation }}
                        </div>
                    </div>

                    <div class="pt-2">
                        <button
                            type="submit"
                            class="w-full min-h-[48px] py-3.5 text-white font-bold text-xs tracking-wider rounded-xl shadow-lg shadow-emerald-600/25 transition-all flex justify-center items-center gap-2 active:scale-[0.98] cursor-pointer disabled:opacity-70 disabled:cursor-not-allowed"
                            style="background: linear-gradient(135deg, #047857, #059669)"
                            :disabled="form.processing"
                        >
                            <span v-if="form.processing" class="flex items-center gap-2">
                                <svg class="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                MENYIMPAN...
                            </span>
                            <span v-else class="flex items-center gap-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                </svg>
                                SIMPAN KATA SANDI
                            </span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="mt-6 text-center text-xs text-slate-500 flex items-center gap-1.5 relative z-10 font-medium">
            <svg class="w-3.5 h-3.5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
            </svg>
            <span>Keamanan Anda adalah prioritas kami</span>
        </div>
    </div>
</template>