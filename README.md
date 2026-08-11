Langkah 1: Clone atau Pull Proyek dari GitHub
Bagi Aisah yang baru saja menarik kode dari GitHub, jalankan perintah berikut di terminal:

Jika baru pertama kali mengunduh repositori:

Bash
git clone https://github.com/Gondesanjay/Magang-Kementan.git
cd Magang-Kementan
Jika foldernya sudah ada di laptop:

Bash
git pull origin main
Langkah 2: Instalasi Dependensi PHP & Node.js
Karena file vendor/ dan node_modules/ tidak diikutkan ke GitHub, Aisah harus mengunduhnya ulang di laptopnya:

Install package PHP menggunakan Composer:

Bash
composer install
Install package JavaScript menggunakan npm:

Bash
npm install
Langkah 3: Konfigurasi File .env
File .env tidak ikut ter-upload ke GitHub demi keamanan. Aisah harus membuatnya secara manual di folder utama proyek:

Salin file contoh .env.example menjadi .env:

Bash
cp .env.example .env
(Atau secara manual buat file baru bernama .env dan salin isinya dari .env.example).

Generate kunci aplikasi Laravel:

Bash
php artisan key:generate
Buka file .env menggunakan teks editor (seperti VS Code), lalu sesuaikan pengaturan Database agar terhubung ke MySQL lokal milik Aisah:

Cuplikan kode
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=db_cuti_kementan  # Sesuaikan dengan nama database di phpMyAdmin/MySQL Aisah
DB_USERNAME=root              # Username database lokal (biasanya root)
DB_PASSWORD=                  # Password database lokal (kosongkan jika tidak ada)
Langkah 4: Database Migration & Seeder
Setelah database diatur di file .env, pastikan Aisah sudah membuat sebuah database kosong (misalnya bernama db_cuti_kementan) melalui phpMyAdmin atau terminal MySQL.

Selanjutnya jalankan perintah migrasi untuk membuat tabel-tabel (termasuk tabel notifications milik Aisah dan tabel pendukung lainnya):

Bash
php artisan migrate
(Opsional: Jika kalian memiliki file seeder untuk data awal seperti role atau akun admin uji coba, jalankan php artisan db:seed).

Langkah 5: Menghubungkan Storage (Opsional tapi Penting)
Karena aplikasi ini mengunggah foto profil pegawai ke folder storage, pastikan tautan storage diaktifkan dengan perintah:

Bash
php artisan storage:link
Langkah 6: Menjalankan Aplikasi
Untuk menjalankan proyek Laravel + Inertia/Vue secara bersamaan, Aisah perlu membuka 2 tab/jendela terminal yang berbeda:

Terminal 1 (Untuk Frontend Vite):

Bash
npm run dev
Terminal 2 (Untuk Backend Laravel):

Bash
php artisan serve
