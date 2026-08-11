# 🚀 Panduan Instalasi & Menjalankan Proyek Magang Kementan

Dokumen ini berisi langkah-langkah untuk menjalankan proyek **Magang-Kementan** di laptop setelah melakukan clone atau pull dari GitHub.

> **Catatan:** Panduan ini ditujukan khususnya untuk anggota tim yang baru pertama kali mengambil proyek dari repository GitHub.

---

## 📋 Prasyarat

Pastikan beberapa tools berikut sudah terinstall di laptop:

* [Git](https://git-scm.com/)
* [PHP](https://www.php.net/)
* [Composer](https://getcomposer.org/)
* [Node.js & npm](https://nodejs.org/)
* MySQL / phpMyAdmin
* VS Code atau text editor lainnya

---

## 1. 📥 Clone atau Pull Proyek dari GitHub

### Jika baru pertama kali mengunduh repository

Buka terminal, kemudian jalankan:

```bash
git clone https://github.com/Gondesanjay/Magang-Kementan.git
cd Magang-Kementan
```

### Jika repository sudah pernah diunduh sebelumnya

Masuk terlebih dahulu ke folder proyek, kemudian jalankan:

```bash
git pull origin main
```

---

## 2. 📦 Instalasi Dependensi PHP & Node.js

Folder `vendor/` dan `node_modules/` tidak disimpan di GitHub. Oleh karena itu, dependensi perlu di-install kembali setelah melakukan clone.

### Install dependensi PHP

Jalankan:

```bash
composer install
```

### Install dependensi JavaScript

Jalankan:

```bash
npm install
```

---

## 3. ⚙️ Konfigurasi File `.env`

File `.env` tidak disertakan dalam repository GitHub karena berisi konfigurasi dan informasi sensitif.

### Membuat file `.env`

Salin file `.env.example` menjadi `.env`:

```bash
cp .env.example .env
```

> **Windows:** Jika perintah `cp` tidak dapat digunakan, file `.env` dapat dibuat secara manual dengan menyalin isi dari `.env.example`.

### Generate application key

Jalankan:

```bash
php artisan key:generate
```

### Konfigurasi database

Buka file `.env` menggunakan VS Code atau text editor lainnya, kemudian sesuaikan konfigurasi database dengan MySQL lokal:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=db_cuti_kementan
DB_USERNAME=root
DB_PASSWORD=
```

Sesuaikan:

* `DB_DATABASE` → nama database yang digunakan
* `DB_USERNAME` → username MySQL, biasanya `root`
* `DB_PASSWORD` → password MySQL. Kosongkan jika tidak menggunakan password

---

## 4. 🗄️ Database Migration & Seeder

Sebelum menjalankan migration, buat terlebih dahulu database kosong melalui **phpMyAdmin** atau terminal MySQL.

Contoh nama database:

```text
db_cuti_kementan
```

Setelah database dibuat dan konfigurasi `.env` sudah sesuai, jalankan:

```bash
php artisan migrate
```

Perintah tersebut akan membuat tabel-tabel yang diperlukan oleh aplikasi berdasarkan file migration yang tersedia.

### Menjalankan Seeder

Jika proyek memiliki seeder untuk membuat data awal, seperti:

* Role pengguna
* Akun admin
* Data dummy
* Data awal lainnya

Jalankan:

```bash
php artisan db:seed
```

Atau jika ingin melakukan migration sekaligus menjalankan seeder:

```bash
php artisan migrate --seed
```

> **Perhatian:** Jangan menjalankan `migrate:fresh` pada database yang berisi data penting karena perintah tersebut akan menghapus seluruh tabel dan membuatnya kembali.

---

## 5. 🔗 Menghubungkan Storage

Jika aplikasi menggunakan fitur upload, seperti **foto profil pegawai**, Laravel membutuhkan symbolic link dari folder `storage` ke `public`.

Jalankan:

```bash
php artisan storage:link
```

Perintah ini cukup dijalankan satu kali setelah setup awal proyek.

---

## 6. ▶️ Menjalankan Aplikasi

Proyek menggunakan **Laravel + Inertia/Vue + Vite**, sehingga diperlukan dua proses yang berjalan secara bersamaan.

Buka **2 terminal** di folder proyek.

### Terminal 1 — Menjalankan Vite

Jalankan:

```bash
npm run dev
```

Terminal ini digunakan untuk menjalankan frontend dan proses development Vite.

### Terminal 2 — Menjalankan Laravel

Jalankan:

```bash
php artisan serve
```

Setelah berhasil, biasanya aplikasi dapat diakses melalui:

```text
http://127.0.0.1:8000
```

---

## 🔄 Urutan Singkat Instalasi

Jika semua tools sudah tersedia, urutan setup secara umum adalah:

```bash
# 1. Clone repository
git clone https://github.com/Gondesanjay/Magang-Kementan.git
cd Magang-Kementan

# 2. Install dependency
composer install
npm install

# 3. Buat file .env
cp .env.example .env

# 4. Generate application key
php artisan key:generate

# 5. Sesuaikan database di .env
# DB_DATABASE=db_cuti_kementan
# DB_USERNAME=root
# DB_PASSWORD=

# 6. Jalankan migration
php artisan migrate

# 7. Jalankan seeder jika diperlukan
php artisan db:seed

# 8. Hubungkan storage
php artisan storage:link

# 9. Jalankan frontend
npm run dev

# 10. Jalankan Laravel di terminal lainnya
php artisan serve
```

---

## 🛠️ Troubleshooting

### `composer` tidak dikenali

Pastikan **Composer** sudah terinstall dan sudah masuk ke environment variable PATH.

Cek dengan:

```bash
composer --version
```

### `npm` tidak dikenali

Pastikan **Node.js** sudah terinstall.

Cek dengan:

```bash
node --version
npm --version
```

### Error koneksi database

Periksa kembali konfigurasi berikut pada `.env`:

```env
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=db_cuti_kementan
DB_USERNAME=root
DB_PASSWORD=
```

Pastikan MySQL sedang berjalan dan database tersebut sudah dibuat.

### Perubahan kode dari GitHub belum muncul

Jalankan:

```bash
git pull origin main
```

Jika terdapat perubahan pada dependency, jalankan kembali:

```bash
composer install
npm install
```

---

## 👥 Untuk Anggota Tim

Setiap kali ada perubahan terbaru dari anggota tim, jalankan:

```bash
git pull origin main
```

Sebelum melakukan perubahan kode, disarankan untuk selalu melakukan pull terlebih dahulu agar kode lokal tetap terbaru.

Setelah selesai melakukan perubahan:

```bash
git add .
git commit -m "Deskripsi perubahan"
git push origin main
```

> **Tips:** Gunakan pesan commit yang jelas agar anggota tim lain mudah mengetahui perubahan yang dilakukan.
