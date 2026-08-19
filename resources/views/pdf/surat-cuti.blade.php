<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Surat Persetujuan Cuti - {{ $pegawai->nama }}</title>
    <style>
        body {
            font-family: 'Times New Roman', Times, serif;
            /* Font standar dokumen resmi */
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

        /* Kolom Label */
        .table-info td:first-child {
            width: 25%;
        }

        /* Kolom Titik Dua */
        .table-info td:nth-child(2) {
            width: 3%;
            text-align: center;
        }

        .status-box {
            font-weight: bold;
            margin-top: 15px;
            margin-bottom: 15px;
        }

        /* Area Tanda Tangan */
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

        .signature-name {
            text-decoration: underline;
            font-weight: bold;
            margin-top: 80px;
            /* Ruang untuk tanda tangan */
        }
    </style>
</head>

<body>

    <!-- JUDUL SURAT -->
    <div class="title">LEMBAR PERSETUJUAN CUTI</div>

    <div class="content">
        <p>Berdasarkan permohonan cuti yang telah diajukan melalui sistem kepegawaian, dengan ini disampaikan bahwa:</p>

        <!-- DATA PEGAWAI & CUTI -->
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
                <!-- Jika jenis_cuti kosong, default ke Cuti Tahunan -->
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
                    {{ \Carbon\Carbon::parse($pengajuan->tanggal_mulai)->translatedFormat('d F Y') }}
                    s.d.
                    {{ \Carbon\Carbon::parse($pengajuan->tanggal_selesai)->translatedFormat('d F Y') }}
                </td>
            </tr>
        </table>

        <!-- STATUS PERSETUJUAN -->
        <div class="status-box">
            Status Permohonan : DISETUJUI
        </div>

        <p>Pegawai yang bersangkutan diberikan izin untuk melaksanakan cuti pada periode tersebut sesuai ketentuan peraturan perundang-undangan.</p>
    </div>

    <!-- AREA TANDA TANGAN -->
    <table class="signature-table">
        <tr>
            <!-- Kolom Kiri: Atasan Langsung (L3 - Kasubag TU) -->
            <td>
                <br>
                Atasan Langsung,

                <div class="signature-name">
                    {{ $pengajuan->atasanL3 ? $pengajuan->atasanL3->nama : '(_______________________)' }}
                </div>
                NIP. {{ $pengajuan->atasanL3 ? $pengajuan->atasanL3->nip : '_______________________' }}
            </td>

            <!-- Kolom Kanan: Pejabat Berwenang (L4 - Kepala Biro) -->
            <td>
                <div class="signature-date">
                    Jakarta, {{ \Carbon\Carbon::now()->translatedFormat('d F Y') }}
                </div>
                Mengetahui,<br>
                Pejabat Yang Berwenang Memberikan Cuti

                <div class="signature-name">
                    {{ $pengajuan->atasanL4 ? $pengajuan->atasanL4->nama : '(_______________________)' }}
                </div>
                NIP. {{ $pengajuan->atasanL4 ? $pengajuan->atasanL4->nip : '_______________________' }}
            </td>
        </tr>
    </table>

</body>

</html>