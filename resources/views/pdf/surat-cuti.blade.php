<!DOCTYPE html>
<html lang="id">


<head>
    <meta charset="UTF-8">
    <title>Surat Persetujuan Cuti - {{ $pegawai->nama }}</title>
    <style>
        body {
            font-family: 'Times New Roman', Times, serif;
            font-size: 12pt;
            line-height: 1.5;
            padding: 2cm 1.5cm;
            color: #000;
        }


        .title {
            text-align: center;
            font-weight: bold;
            font-size: 14pt;
            margin-bottom: 25px;
            text-decoration: underline;
        }


        .content {
            margin-top: 20px;
            text-align: justify;
        }


        .table-info {
            width: 100%;
            margin-top: 15px;
            margin-bottom: 20px;
            border-collapse: collapse;
        }


        .table-info td {
            padding: 4px 0;
            vertical-align: top;
        }


        .table-info td:first-child {
            width: 25%;
        }


        .table-info td:nth-child(2) {
            width: 3%;
            text-align: center;
        }


        .status-box {
            font-weight: bold;
            margin-top: 15px;
            margin-bottom: 15px;
        }


        .signature-table {
            width: 100%;
            margin-top: 60px;
            text-align: center;
            border-collapse: collapse;
        }


        .signature-table td {
            width: 50%;
            padding: 5px;
            vertical-align: bottom;
        }


        .signature-date {
            text-align: center;
            margin-bottom: 10px;
        }


        /* PERBAIKAN KERAPIAN FORMAT: font-size nama penandatangan diperkecil
           lagi ke 10pt (dari semula 11pt) karena nama yang lebih panjang
           bergelar (mis. "Seta Rukmalasari Agustina, S.P., M.M.A., M.Sc.")
           masih terpotong jadi dua baris di 11pt. Ukuran 10pt dipastikan
           cukup untuk memuat nama terpanjang dalam satu baris di lebar
           kolom tanda tangan (50% halaman), sekaligus tetap proporsional
           dengan teks jabatan di atasnya. */
        .signature-name {
            text-decoration: underline;
            font-weight: bold;
            font-size: 10pt;
            white-space: nowrap;
            margin-top: 80px;
        }
    </style>
</head>


<body>


    <div class="title">LEMBAR PERSETUJUAN CUTI</div>


    <div class="content">
        <p>Berdasarkan permohonan cuti yang telah diajukan melalui sistem kepegawaian, dengan ini disampaikan bahwa:</p>


        <table class="table-info">
            <tr>
                <td>Nama</td>
                <td>:</td>
                <td>{{ $pegawai->nama }}</td>
            </tr>
            <tr>
                <td>NIP</td>
                <td>:</td>
                <td>{{ $pegawai->nip ?? '-' }}</td>
            </tr>
            <tr>
                <td>Jabatan</td>
                <td>:</td>
                <td>{{ $pegawai->jabatan ?? '-' }}</td>
            </tr>
            <tr>
                <td>Unit Kerja</td>
                <td>:</td>
                <td>{{ $pegawai->departemen ?? '-' }}</td>
            </tr>
            <tr>
                <td>Jenis Cuti</td>
                <td>:</td>
                <td>{{ $pengajuan->jenis_cuti ?? 'Cuti Tahunan' }}</td>
            </tr>
            <tr>
                <td>Jumlah Hari</td>
                <td>:</td>
                <td>{{ $pengajuan->jumlah_hari }} Hari</td>
            </tr>
            <tr>
                <td>Periode Cuti</td>
                <td>:</td>
                <td>
                    {{ \Carbon\Carbon::parse($pengajuan->tanggal_mulai)->locale('id')->translatedFormat('d F Y') }}
                    s.d.
                    {{ \Carbon\Carbon::parse($pengajuan->tanggal_selesai)->locale('id')->translatedFormat('d F Y') }}
                </td>
            </tr>
        </table>


        <div class="status-box">
            Status Permohonan : DISETUJUI
        </div>


        <p>Pegawai yang bersangkutan diberikan izin untuk melaksanakan cuti pada periode tersebut sesuai ketentuan peraturan perundang-undangan.</p>
    </div>


    <!-- Tarik data L3 dan L4 langsung dari database untuk semua pengajuan -->
    @php
    $kasubag = \App\Models\Pegawai::where('jabatan', 'Kepala Subbagian Tata Usaha')->first();
    $kabiro = \App\Models\Pegawai::where('jabatan', 'Kepala Biro Perencanaan')->first();
    @endphp


    <table class="signature-table">
        <tr>
            <!-- Kolom Kiri: Atasan Langsung (L3 - Kasubag TU) -->
            <td>
                <br>
                Atasan Langsung,<br>
                Kepala Subbagian Tata Usaha


                <!-- AREA GAMBAR TANDA TANGAN L3 -->
                <div style="height: 70px; margin: 10px 0;">
                    @if($kasubag && !empty($kasubag->signature_path) && file_exists(public_path('storage/' . $kasubag->signature_path)))
                    <img src="{{ public_path('storage/' . $kasubag->signature_path) }}" style="height: 70px; width: 140px; object-fit: contain;">
                    @elseif(file_exists(public_path('storage/signatures/kasubag.png')))
                    <img src="{{ public_path('storage/signatures/kasubag.png') }}" style="height: 70px; width: 140px; object-fit: contain;">
                    @else
                    <!-- Jika file benar-benar tidak ada, kosongkan saja atau berikan teks pengganti -->
                    <span style="font-size: 10pt; color: #555; font-style: italic;">(Tanda Tangan)</span>
                    @endif
                </div>


                <!-- PERBAIKAN: strtoupper() dihapus supaya nama tampil apa
                     adanya (Title Case) sesuai data di database, bukan
                     dipaksa huruf kapital semua. Fallback string statis juga
                     disesuaikan formatnya. -->
                <div class="signature-name">
                    {{ $kasubag ? $kasubag->nama : 'Ignatius Agus Hendarto, S.E., M.M.' }}
                </div>
                NIP. {{ $kasubag ? $kasubag->nip : '777666555' }}
            </td>


            <!-- Kolom Kanan: Pejabat Berwenang (L4 - Kepala Biro Perencanaan) -->
            <td>
                <div class="signature-date">
                    Jakarta, {{ \Carbon\Carbon::now()->locale('id')->translatedFormat('d F Y') }}
                </div>
                Mengetahui,<br>
                Pejabat Yang Berwenang Memberikan Cuti<br>
                Kepala Biro Perencanaan


                <!-- AREA GAMBAR TANDA TANGAN L4 -->
                <div style="height: 70px; margin: 10px 0;">
                    @if($kabiro && file_exists(public_path('storage/' . $kabiro->signature_path)))
                    <!-- Jika ada gambar di database -->
                    <img src="{{ public_path('storage/' . $kabiro->signature_path) }}" style="height: 70px; width: 140px; object-fit: contain;">
                    @else
                    <!-- Fallback / Gambar Default Statis -->
                    <img src="{{ public_path('storage/signatures/kabiro.png') }}" style="height: 70px; width: 140px; object-fit: contain;">
                    @endif
                </div>


                <!-- PERBAIKAN: strtoupper() dihapus, sama seperti kolom kiri -->
                <div class="signature-name">
                    {{ $kabiro ? $kabiro->nama : 'Seta Rukmalasari Agustina, S.P., M.M.A., M.Sc.' }}
                </div>
                NIP. {{ $kabiro ? $kabiro->nip : '666555444' }}
            </td>
        </tr>
    </table>


</body>


</html>

