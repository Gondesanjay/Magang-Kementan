<script setup>
import { ref, watch, onErrorCaptured } from 'vue';
import { useForm, Head, router } from '@inertiajs/vue3';
import MainLayout from '@/Layouts/MainLayout.vue';

const props = defineProps({
    pegawai: {
        type: Array,
        default: () => []
    },
    filters: Object
});

const vueError = ref(null);
onErrorCaptured((err) => {
    vueError.value = err.toString();
    console.error("VUE ERROR TERTANGKAP:", err);
    return false; 
});

// State untuk Pencarian
const search = ref(props.filters?.search || '');

// Kirim request pencarian otomatis saat mengetik (menggunakan native setTimeout sebagai pengganti lodash)
let searchTimeout = null;
watch(search, (value) => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        router.get(
            route('admin.pegawai'),
            { search: value },
            { preserveState: true, replace: true, preserveScroll: true }
        );
    }, 300);
});

const formatRole = (roleId) => {
    const roles = { 1: 'Karyawan', 2: 'Atasan L1', 3: 'Atasan L2', 4: 'Atasan L3', 5: 'Admin HR', 6: 'Atasan L4' };
    return roles[roleId] || 'Tidak Diketahui';
};

const isModalOpen = ref(false);
const isEditMode = ref(false);
const currentEditId = ref(null);

const form = useForm({
    nip: '',
    nama: '',
    departemen: '', 
    divisi: '',     
    jabatan: '',    
    role_id: 1,
    tanggal_masuk: '',
    // ---> PENAMBAHAN: Field Saldo Cuti langsung di form ini <---
    // 'kuota_tahunan' dipakai di mode Tambah MAUPUN Edit.
    // 'sisa' hanya ditampilkan & relevan di mode Edit (lihat template),
    // karena untuk pegawai baru, sisa cuti otomatis sama dengan kuotanya.
    kuota_tahunan: 12,
    sisa: 12,
    // ---> PENAMBAHAN: field baru untuk kolom 'sisa_cuti_tahun_lalu' <---
    // Ini kolom BERBEDA dari 'sisa' di atas. Kolom ini yang dipakai
    // DashboardController untuk menghitung kartu "Sisa Tahun Kemarin"
    // dan ikut menentukan "Total Cuti Tersedia" karyawan.
    sisa_cuti_tahun_lalu: 0,
});

const showToast = ref(false);
const toastTitle = ref('');
const toastMessage = ref('');
let toastTimer = null;

const displayToast = (title, message) => {
    toastTitle.value = title;
    toastMessage.value = message;
    showToast.value = true;

    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
        showToast.value = false;
    }, 3500);
};

const openAddModal = () => {
    isEditMode.value = false;
    currentEditId.value = null;
    form.reset();
    // form.reset() akan mengembalikan kuota_tahunan & sisa ke nilai default
    // yang didefinisikan di useForm() di atas (12 dan 12).
    isModalOpen.value = true;
};

const openEditModal = (item) => {
    isEditMode.value = true;
    currentEditId.value = item.id;
    form.nip = item.nip || '';
    form.nama = item.nama || '';
    form.departemen = item.departemen || '';
    form.divisi = item.divisi || '';
    form.jabatan = item.jabatan || '';
    form.role_id = item.role_id || 1;
    form.tanggal_masuk = item.tanggal_masuk || '';

    // ---> PENAMBAHAN: Isi field saldo cuti dari data pegawai <---
    // Backend (AdminController::kelolaPegawai) mengirim relasi 'saldo_cuti'
    // yang sudah difilter khusus tahun berjalan, jadi paling banyak berisi
    // 1 baris. Kalau pegawai ini belum punya saldo sama sekali (misalnya
    // pegawai lama yang dibuat sebelum fitur ini ada), fallback ke 12/12
    // supaya Admin tinggal klik Simpan untuk langsung mengisi jatahnya.
    const saldoSekarang = item.saldo_cuti && item.saldo_cuti.length > 0 ? item.saldo_cuti[0] : null;
    form.kuota_tahunan = saldoSekarang?.kuota_tahunan ?? 12;
    form.sisa = saldoSekarang?.sisa ?? 12;
    form.sisa_cuti_tahun_lalu = saldoSekarang?.sisa_cuti_tahun_lalu ?? 0;

    isModalOpen.value = true;
};

const submitForm = () => {
    if (isEditMode.value) {
        form.put(route('admin.pegawai.update', currentEditId.value), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => { 
                isModalOpen.value = false; 
                displayToast('Pembaruan Sukses', 'Data pegawai berhasil diperbarui.');
                form.reset(); 
            }
        });
    } else {
        form.post(route('admin.pegawai.store'), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => { 
                isModalOpen.value = false; 
                displayToast('Penambahan Sukses', 'Data pegawai baru berhasil ditambahkan.');
                form.reset(); 
            }
        });
    }
};

const resetPassword = (item) => {
    const konfirmasi = window.confirm(
        `Reset password untuk "${item.nama}" ke default (password123)?\n\nPegawai akan wajib mengganti passwordnya sendiri saat login berikutnya.`
    );

    if (!konfirmasi) return;

    router.post(route('admin.pegawai.reset-password', item.id), {}, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            displayToast('Password Direset', `Akun ${item.nama} kembali ke password default.`);
        }
    });
};
</script>

<template>
    <Head title="Kelola Pegawai" />

    <MainLayout>
      <div>
        <div v-if="vueError" class="bg-red-600 text-white p-6 rounded-lg mb-6 shadow-xl font-mono text-sm border-4 border-red-800">
            <h2 class="text-xl font-black mb-2">🚨 VUE RENDER ERROR TERTANGKAP!</h2>
            <p>Pesan Error: <strong>{{ vueError }}</strong></p>
        </div>

        <div v-else class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 relative z-10">
            <div class="flex flex-col md:flex-row justify-between items-center mb-6 border-b pb-4 gap-4">
                <h2 class="text-2xl font-bold text-gray-800 w-full md:w-auto">Master Data Pegawai</h2>
                
                <div class="flex items-center gap-3 w-full md:w-auto justify-end">
                    <!-- KOTAK SEARCH -->
                    <div class="relative w-full md:w-72">
                        <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-gray-400">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                        </span>
                        <input 
                            type="text" 
                            v-model="search" 
                            placeholder="Cari nama, NIP..." 
                            class="w-full pl-9 pr-4 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                        />
                    </div>

                    <!-- Tombol Tambah -->
                    <button type="button" @click="openAddModal" class="px-5 py-2 bg-indigo-600 text-white rounded-md text-sm font-extrabold hover:bg-indigo-700 cursor-pointer shadow-md transition-all whitespace-nowrap">
                        + Tambah Pegawai
                    </button>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">NIP</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nama Pegawai</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Subbagian/Kel.</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Tim Kerja</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Jabatan</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Hak Akses</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <tr v-if="!pegawai || pegawai.length === 0">
                            <td colspan="7" class="px-6 py-12 text-center text-gray-500 font-bold bg-gray-50/50">
                                <span class="block text-xl mb-2">📭</span>
                                Data Pegawai tidak ditemukan.
                            </td>
                        </tr>
                        <tr v-else v-for="item in pegawai" :key="item.id" class="hover:bg-gray-50">
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900">{{ item?.nip || '-' }}</td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900 font-medium">{{ item?.nama || '-' }}</td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">{{ item?.departemen || '-' }}</td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">{{ item?.divisi || '-' }}</td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">{{ item?.jabatan || '-' }}</td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">{{ formatRole(item?.role_id) }}</td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-center space-x-1">
                                <button type="button" @click="openEditModal(item)" class="text-indigo-600 hover:text-indigo-900 cursor-pointer font-bold px-3 py-1 bg-indigo-50 hover:bg-indigo-100 rounded transition-colors">
                                    Edit
                                </button>
                                <button type="button" @click="resetPassword(item)" class="text-amber-600 hover:text-amber-900 cursor-pointer font-bold px-3 py-1 bg-amber-50 hover:bg-amber-100 rounded transition-colors">
                                    Reset Password
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- MODAL FORM -->
        <Teleport to="body">
            <div v-if="isModalOpen" class="fixed inset-0 flex items-center justify-center p-4" style="z-index: 999999;">
                <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="isModalOpen = false"></div>
                <div class="relative bg-white p-6 rounded-xl w-full max-w-lg shadow-2xl max-h-[90vh] overflow-y-auto z-10">
                    <div class="flex justify-between items-center mb-4 border-b pb-3">
                        <h3 class="text-lg font-bold text-gray-800">
                            {{ isEditMode ? 'Edit Data Pegawai' : 'Tambah Data Pegawai Baru' }}
                        </h3>
                        <button type="button" @click="isModalOpen = false" class="text-gray-400 hover:text-red-500 font-bold text-2xl cursor-pointer outline-none">&times;</button>
                    </div>
                    
                    <form @submit.prevent="submitForm">
                        <div class="mb-3">
                            <label class="block text-sm font-medium text-gray-700">NIP</label>
                            <input type="text" v-model="form.nip" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required />
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium text-gray-700">Tanggal Masuk (TMT)</label>
                            <input type="date" v-model="form.tanggal_masuk" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required />
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium text-gray-700">Nama Lengkap & Gelar</label>
                            <input type="text" v-model="form.nama" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required />
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium text-gray-700">Subbagian / Kelompok</label>
                            <input type="text" v-model="form.departemen" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required />
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium text-gray-700">Tim Kerja</label>
                            <input type="text" v-model="form.divisi" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                        </div>
                        <div class="mb-3">
                            <label class="block text-sm font-medium text-gray-700">Jabatan</label>
                            <input type="text" v-model="form.jabatan" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required />
                        </div>
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700">Hak Akses (Role)</label>
                            <select v-model="form.role_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required>
                                <option value="1">Karyawan / Staf</option>
                                <option value="2">Atasan L1 (Ketua Tim Kerja)</option>
                                <option value="3">Atasan L2 (Ketua Kelompok)</option>
                                <option value="4">Atasan L3 (Kasubag TU)</option>
                                <option value="5">Admin HR</option>
                                <option value="6">Atasan L4 (Kepala Biro)</option>
                            </select>
                        </div>

                        <!-- ========================================== -->
                        <!-- PENAMBAHAN: FIELD SALDO CUTI                -->
                        <!-- ========================================== -->
                        <div class="mb-4 p-4 bg-indigo-50/60 rounded-lg border border-indigo-100">
                            <p class="text-xs font-bold text-indigo-700 uppercase mb-3">Jatah Cuti Tahunan {{ new Date().getFullYear() }}</p>

                            <!-- Mode Tambah: kuota + sisa tahun lalu (kalau ada carry-over) -->
                            <div v-if="!isEditMode" class="space-y-3">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Jatah Cuti Tahunan (Hari)</label>
                                    <input type="number" min="0" v-model.number="form.kuota_tahunan" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required />
                                    <p class="text-xs text-gray-500 mt-1">Default 12 hari. Bisa diubah, misalnya untuk pegawai yang masuk pertengahan tahun.</p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Sisa Cuti Tahun Lalu (jika ada carry-over)</label>
                                    <input type="number" min="0" v-model.number="form.sisa_cuti_tahun_lalu" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required />
                                    <p class="text-xs text-gray-500 mt-1">Biasanya 0 untuk pegawai baru, kecuali ada pengecualian khusus.</p>
                                </div>
                            </div>

                            <!-- Mode Edit: 3 field terpisah -->
                            <div v-else class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Kuota Tahunan</label>
                                    <input type="number" min="0" v-model.number="form.kuota_tahunan" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Sisa Cuti Saat Ini</label>
                                    <input type="number" :value="form.sisa" disabled class="mt-1 block w-full border-gray-200 bg-gray-100 text-gray-500 rounded-md shadow-sm cursor-not-allowed" />
                                    <p class="text-xs text-gray-500 mt-1">Dihitung otomatis (kuota + sisa tahun lalu − cuti terpakai), tidak bisa diedit manual.</p>
                                </div>
                                <div class="col-span-2">
                                    <label class="block text-sm font-medium text-gray-700">Sisa Cuti Tahun Lalu</label>
                                    <input type="number" min="0" v-model.number="form.sisa_cuti_tahun_lalu" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required />
                                    <p class="text-xs text-gray-500 mt-1">Ini yang tampil di kartu "Sisa Tahun Kemarin" pada Dashboard pegawai.</p>
                                </div>
                            </div>
                        </div>
                        <!-- ========================================== -->
                        <!-- END PENAMBAHAN FIELD SALDO CUTI              -->
                        <!-- ========================================== -->

                        <div class="flex justify-end space-x-2 mt-6 pt-4 border-t border-gray-100">
                            <button type="button" @click="isModalOpen = false" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 cursor-pointer transition">Batal</button>
                            <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 cursor-pointer transition" :disabled="form.processing">
                                {{ isEditMode ? 'Simpan Perubahan' : 'Simpan Data' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>

        <!-- TOAST NOTIFIKASI UNIVERSAL -->
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
                    v-if="showToast"
                    class="fixed bottom-6 right-6 flex items-center gap-3 bg-white border border-green-200 shadow-2xl rounded-lg px-4 py-3 max-w-sm"
                    style="z-index: 999999;"
                >
                    <span class="flex-shrink-0 flex items-center justify-center w-8 h-8 rounded-full bg-green-100">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-green-600" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M16.704 5.29a1 1 0 010 1.415l-7.5 7.5a1 1 0 01-1.415 0l-3.5-3.5a1 1 0 111.415-1.415L8.5 12.086l6.793-6.793a1 1 0 011.415 0z" clip-rule="evenodd" />
                        </svg>
                    </span>
                    <div class="text-sm">
                        <p class="font-semibold text-gray-800">{{ toastTitle }}</p>
                        <p class="text-gray-500">{{ toastMessage }}</p>
                    </div>
                    <button type="button" @click="showToast = false" class="ml-2 text-gray-400 hover:text-gray-600 cursor-pointer">&times;</button>
                </div>
            </Transition>
        </Teleport>
      </div>
    </MainLayout>
</template>