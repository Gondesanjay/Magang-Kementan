<script setup>
import MainLayout from '@/Layouts/MainLayout.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const page = usePage();
const user = computed(() => page.props.auth.user);

// Setup Form Data Diri (HANYA NAMA DAN FOTO)
const profileForm = useForm({
    _method: 'patch',
    nama: user.value.nama,
    foto_profil: null, 
});

// Setup Form Ubah Password
const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
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
    if (photoInput.value) photoInput.value.value = '';
    profileForm.clearErrors();
};

const updateProfile = () => {
    profileForm.post(route('profile.update'), {
        forceFormData: true, 
        preserveScroll: true,
        onSuccess: () => {
            profileForm.clearErrors();
            profileForm.foto_profil = null; 
            photoPreview.value = null; 
        }
    });
};

const updatePassword = () => {
    passwordForm.put(route('password.update'), {
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
                <h1 class="text-2xl md:text-3xl font-bold text-slate-800 tracking-tight">Pengaturan Akun</h1>
                <p class="text-slate-500 mt-1 text-sm">Kelola informasi data diri, foto profil, dan keamanan akun Anda di sini.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                
                <!-- KOLOM KIRI (Info Pegawai & Ganti Foto) -->
                <div class="md:col-span-1 space-y-6">
                    <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200 text-center flex flex-col items-center relative overflow-hidden">
                        
                        <div class="absolute top-0 left-0 w-full h-24 bg-slate-900 z-0"></div>
                        
                        <div class="relative w-28 h-28 rounded-full bg-slate-100 flex items-center justify-center border-4 border-white shadow-md text-slate-700 font-bold text-3xl overflow-hidden mt-8 z-10">
                            <img v-if="photoPreview" :src="photoPreview" class="w-full h-full object-cover">
                            <img v-else-if="user.foto_profil" :src="`/storage/${user.foto_profil}`" class="w-full h-full object-cover">
                            <span v-else>{{ user.nama.charAt(0) }}</span>
                        </div>
                        
                        <div class="mt-4 w-full">
                            <h3 class="text-lg font-bold text-slate-800 leading-tight">{{ user.nama }}</h3>
                            <p class="text-sm font-semibold text-slate-500 mt-0.5">{{ user.nip || 'NIP Belum Diatur' }}</p>
                            
                            <div class="mt-3 flex flex-col items-center gap-1.5">
                                <span class="text-xs font-semibold text-green-700 bg-green-50 border border-green-100 px-3 py-1.5 rounded-full w-full max-w-[200px] truncate">
                                    {{ user.jabatan || 'Jabatan Kosong' }}
                                </span>
                                <span class="text-xs font-semibold text-blue-700 bg-blue-50 border border-blue-100 px-3 py-1.5 rounded-full w-full max-w-[200px] truncate">
                                    {{ user.departemen || 'Divisi/Departemen Kosong' }}
                                </span>
                            </div>
                        </div>

                        <input type="file" ref="photoInput" class="hidden" @change="updatePhotoPreview" accept="image/*">
                        
                        <!-- LOGIKA TOMBOL DINAMIS -->
                        <div class="mt-6 w-full space-y-2">
                            <button v-if="!profileForm.foto_profil" @click="selectNewPhoto" type="button" class="w-full px-4 py-2 bg-slate-50 border border-slate-200 text-slate-600 rounded-lg text-sm font-semibold hover:bg-slate-100 transition flex items-center justify-center gap-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                                Pilih Foto Baru
                            </button>

                            <template v-else>
                                <button @click="updateProfile" :disabled="profileForm.processing" type="button" class="w-full px-4 py-2 bg-green-600 border border-green-700 text-white rounded-lg text-sm font-semibold hover:bg-green-700 transition flex items-center justify-center gap-2 shadow-sm disabled:opacity-50">
                                    <svg v-if="profileForm.processing" class="animate-spin h-4 w-4 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                                    <span v-else>Simpan Foto Profil</span>
                                </button>
                                <button @click="cancelPhoto" :disabled="profileForm.processing" type="button" class="w-full px-4 py-2 bg-white border border-slate-200 text-slate-500 rounded-lg text-sm font-semibold hover:bg-slate-50 transition flex items-center justify-center disabled:opacity-50">
                                    Batal
                                </button>
                            </template>
                        </div>
                        
                        <div v-if="profileForm.errors.foto_profil" class="mt-3 p-2 bg-red-50 border border-red-200 rounded-lg text-xs text-red-600 text-left w-full">
                            {{ profileForm.errors.foto_profil }}
                        </div>
                        <div v-if="profileForm.recentlySuccessful && !photoPreview" class="mt-3 p-2 bg-green-50 border border-green-200 rounded-lg text-xs text-green-700 text-center w-full font-medium">
                            Foto berhasil diperbarui!
                        </div>

                    </div>
                </div>

                <!-- KOLOM KANAN (Form Update) -->
                <div class="md:col-span-2 space-y-6">
                    
                    <!-- Form Data Diri -->
                    <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200">
                        <h3 class="text-lg font-bold text-slate-800 mb-1">Informasi Profil</h3>
                        <p class="text-xs text-slate-500 mb-5">Perbarui nama lengkap dan lihat data kepegawaian Anda.</p>
                        
                        <form @submit.prevent="updateProfile" id="profile-form" class="space-y-4">
                            
                            <!-- Input Nama (Bisa Diedit) -->
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">Nama Lengkap</label>
                                <input v-model="profileForm.nama" type="text" class="w-full md:w-1/2 rounded-lg border-slate-300 shadow-sm focus:border-green-500 focus:ring-green-500 text-sm py-2 px-3">
                                <span v-if="profileForm.errors.nama" class="text-xs text-red-500 mt-1">{{ profileForm.errors.nama }}</span>
                            </div>

                            <!-- Input NIP, Divisi, Jabatan (Read-Only) -->
                            <div class="pt-4 mt-2 border-t border-slate-100">
                                <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-4">Data Kepegawaian (Hanya Baca)</p>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-slate-700 mb-1">Nomor Induk Pegawai (NIP)</label>
                                        <input :value="user.nip || 'Belum Diatur'" type="text" disabled class="w-full rounded-lg border-slate-200 bg-slate-50 text-slate-500 shadow-sm text-sm py-2 px-3 cursor-not-allowed">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-slate-700 mb-1">Divisi / Departemen</label>
                                        <input :value="user.departemen || 'Belum Diatur'" type="text" disabled class="w-full rounded-lg border-slate-200 bg-slate-50 text-slate-500 shadow-sm text-sm py-2 px-3 cursor-not-allowed">
                                    </div>
                                    <div class="md:col-span-2">
                                        <label class="block text-sm font-medium text-slate-700 mb-1">Jabatan</label>
                                        <input :value="user.jabatan || 'Belum Diatur'" type="text" disabled class="w-full rounded-lg border-slate-200 bg-slate-50 text-slate-500 shadow-sm text-sm py-2 px-3 cursor-not-allowed">
                                    </div>
                                </div>
                            </div>

                            <div class="flex items-center justify-between pt-4 mt-2 border-t border-slate-100">
                                <span v-if="profileForm.recentlySuccessful && !profileForm.foto_profil" class="text-sm text-green-600 font-medium">Profil berhasil disimpan.</span>
                                <span v-else></span>
                                
                                <button type="submit" :disabled="profileForm.processing" class="px-5 py-2 bg-slate-800 text-white rounded-lg text-sm font-semibold hover:bg-slate-900 transition disabled:opacity-50">
                                    Simpan Perubahan
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Form Ubah Password -->
                    <div id="password" class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200">
                        <h3 class="text-lg font-bold text-slate-800 mb-1">Ubah Password</h3>
                        <p class="text-xs text-slate-500 mb-5">Pastikan akun Anda menggunakan kata sandi yang panjang dan acak agar tetap aman.</p>
                        
                        <form @submit.prevent="updatePassword" class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">Password Saat Ini</label>
                                <input v-model="passwordForm.current_password" type="password" class="w-full rounded-lg border-slate-300 shadow-sm focus:border-green-500 focus:ring-green-500 text-sm py-2 px-3">
                                <span v-if="passwordForm.errors.current_password" class="text-xs text-red-500 mt-1">{{ passwordForm.errors.current_password }}</span>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">Password Baru</label>
                                    <input v-model="passwordForm.password" type="password" class="w-full rounded-lg border-slate-300 shadow-sm focus:border-green-500 focus:ring-green-500 text-sm py-2 px-3">
                                    <span v-if="passwordForm.errors.password" class="text-xs text-red-500 mt-1">{{ passwordForm.errors.password }}</span>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">Konfirmasi Password Baru</label>
                                    <input v-model="passwordForm.password_confirmation" type="password" class="w-full rounded-lg border-slate-300 shadow-sm focus:border-green-500 focus:ring-green-500 text-sm py-2 px-3">
                                </div>
                            </div>

                            <div class="flex items-center justify-between pt-2">
                                <span v-if="passwordForm.recentlySuccessful" class="text-sm text-green-600 font-medium">Password diperbarui.</span>
                                <span v-else></span>
                                
                                <button type="submit" :disabled="passwordForm.processing" class="px-5 py-2 bg-slate-800 text-white rounded-lg text-sm font-semibold hover:bg-slate-900 transition disabled:opacity-50">
                                    Perbarui Password
                                </button>
                            </div>
                        </form>
                    </div>

                </div>
            </div>
        </div>
    </MainLayout>
</template>