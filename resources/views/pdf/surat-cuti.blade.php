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

        .signature-name {
            text-decoration: underline;
            font-weight: bold;
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
            <!-- Kolom Kiri: Atasan Langsung (TERKUNCI UNTUK L3) -->
            <td>
                <br>
                Atasan Langsung,<br>
                Kepala Subbagian Tata Usaha

                <div class="signature-name">
                    <!-- PERBAIKAN: Ditambahkan tanda $ pada kasubag -->
                    {{ $kasubag ? strtoupper($kasubag->nama) : 'IGNATIUS AGUS HENDARTO, S.E., M.M.' }}
                </div>
                NIP. {{ $kasubag ? $kasubag->nip : '777666555' }}
            </td>

            <!-- Kolom Kanan: Pejabat Berwenang (TERKUNCI UNTUK L4) -->
            <td>
                <div class="signature-date">
                    Jakarta, {{ \Carbon\Carbon::now()->locale('id')->translatedFormat('d F Y') }}
                </div>
                Mengetahui,<br>
                Pejabat Yang Berwenang Memberikan Cuti<br>
                Kepala Biro Perencanaan

                <div class="signature-name">
                    <!-- PERBAIKAN: Ditambahkan tanda $ pada kabiro -->
                    {{ $kabiro ? strtoupper($kabiro->nama) : 'SETA RUKMALASARI AGUSTINA, S.P., M.M.A., M.Sc.' }}
                </div>
                NIP. {{ $kabiro ? $kabiro->nip : '666555444' }}
            </td>
        </tr>
    </table>

</body>

</html>