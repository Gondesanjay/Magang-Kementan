<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Formulir Cuti - {{ $pegawai->nama }}</title>
    <style>
        body {
            font-family: 'Times New Roman', Times, serif;
            font-size: 11pt;
            line-height: 1.3;
        }

        .header {
            text-align: right;
            margin-bottom: 20px;
        }

        .title {
            text-align: center;
            font-weight: bold;
            text-decoration: underline;
            margin-bottom: 15px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }

        th,
        td {
            border: 1px solid black;
            padding: 4px 6px;
            vertical-align: top;
        }

        .text-center {
            text-align: center;
        }

        .text-right {
            text-align: right;
        }

        .font-bold {
            font-weight: bold;
        }
    </style>
</head>

<body>

    <div class="header">
        Jakarta, {{ \Carbon\Carbon::parse($pengajuan->created_at)->translatedFormat('d F Y') }}<br>
        <br>
        KEPADA<br>
        Yth. Kepala Biro Organisasi dan SDM Aparatur<br>
        Sekretariat Jenderal Kementerian Pertanian<br>
        di Tempat
    </div>

    <div class="title">FORMULIR PERMINTAAN DAN PEMBERIAN CUTI</div>

    <!-- I. DATA PEGAWAI -->
    <table>
        <tr>
            <td colspan="4" class="font-bold">I. DATA PEGAWAI</td>
        </tr>
        <tr>
            <td width="15%">NAMA</td>
            <td width="35%">{{ $pegawai->nama }}</td>
            <td width="15%">NIP</td>
            <td width="35%">{{ $pegawai->nip }}</td>
        </tr>
        <tr>
            <td>JABATAN</td>
            <td>{{ $pegawai->jabatan ?? '-' }}</td>
            <td>MASA KERJA</td>
            <td>{{ $masaKerja }}</td>
        </tr>
        <tr>
            <td>UNIT KERJA</td>
            <td colspan="3">{{ $pegawai->departemen }}</td>
        </tr>
    </table>

    <!-- II. JENIS CUTI -->
    <table>
        <tr>
            <td colspan="4" class="font-bold">II. JENIS CUTI YANG DIAMBIL**</td>
        </tr>
        <tr>
            <td width="45%">1. Cuti Tahunan</td>
            <td width="5%" class="text-center">V</td>
            <td width="45%">2. Cuti Besar</td>
            <td width="5%" class="text-center"></td>
        </tr>
        <tr>
            <td>3. Cuti Sakit</td>
            <td class="text-center"></td>
            <td>4. Cuti Melahirkan</td>
            <td class="text-center"></td>
        </tr>
        <tr>
            <td>5. Cuti Karena Alasan Penting</td>
            <td class="text-center"></td>
            <td>6. Cuti di Luar Tanggungan Negara</td>
            <td class="text-center"></td>
        </tr>
    </table>

    <!-- III. ALASAN CUTI -->
    <table>
        <tr>
            <td class="font-bold">III. ALASAN CUTI</td>
        </tr>
        <tr>
            <td>{{ $pengajuan->keterangan }}</td>
        </tr>
    </table>

    <!-- IV. LAMANYA CUTI -->
    <table>
        <tr>
            <td colspan="6" class="font-bold">IV. LAMANYA CUTI</td>
        </tr>
        <tr>
            <td width="10%">Selama</td>
            <td width="20%" class="text-center">{{ $pengajuan->jumlah_hari }} Hari</td>
            <td width="15%">Mulai Tanggal</td>
            <td width="20%" class="text-center">{{ \Carbon\Carbon::parse($pengajuan->tanggal_mulai)->translatedFormat('d M Y') }}</td>
            <td width="5%" class="text-center">s/d</td>
            <td width="30%" class="text-center">{{ \Carbon\Carbon::parse($pengajuan->tanggal_selesai)->translatedFormat('d M Y') }}</td>
        </tr>
    </table>

    <!-- V. CATATAN CUTI -->
    <table>
        <tr>
            <td colspan="5" class="font-bold">V. CATATAN CUTI***</td>
        </tr>
        <tr>
            <td colspan="3" width="50%">1. CUTI TAHUNAN</td>
            <td width="45%">2. CUTI BESAR</td>
            <td width="5%"></td>
        </tr>
        <tr>
            <td width="15%" class="text-center">Tahunan</td>
            <td width="10%" class="text-center">Sisa</td>
            <td width="25%" class="text-center">Keterangan</td>
            <td>3. CUTI SAKIT</td>
            <td></td>
        </tr>
        <tr>
            <td class="text-center">N-2</td>
            <td></td>
            <td></td>
            <td>4. CUTI MELAHIRKAN</td>
            <td></td>
        </tr>
        <tr>
            <td class="text-center">N-1</td>
            <td></td>
            <td></td>
            <td>5. CUTI KARENA ALASAN PENTING</td>
            <td></td>
        </tr>
        <tr>
            <td class="text-center">N</td>
            <td class="text-center">{{ $sisaCuti }}</td>
            <td class="text-center">Hari</td>
            <td>6. CUTI DI LUAR TANGGUNGAN NEGARA</td>
            <td></td>
        </tr>
    </table>

    <!-- VI. ALAMAT -->
    <table>
        <tr>
            <td colspan="3" class="font-bold">VI. ALAMAT SELAMA MENJALANKAN CUTI</td>
        </tr>
        <tr>
            <!-- Kolom kosong di sebelah kiri -->
            <td width="35%" style="height: 15px;"></td>
            <!-- Kolom TELP di tengah -->
            <td width="25%" style="height: 15px;">TELP : {{ $pengajuan->no_telp }}</td>
            <!-- Kolom kosong di sebelah kanan -->
            <td width="40%" style="height: 15px;"></td>
        </tr>
        <tr>
            <!-- Kolom Alamat (gabungan 2 kolom kiri) -->
            <td colspan="2" style="height: 75px; vertical-align: top;">
                {{ $pengajuan->alamat_cuti }}
            </td>
            <!-- Kolom Tanda Tangan -->
            <td class="text-center" style="vertical-align: top;">
                Hormat saya,<br><br><br><br>
                ({{ $pegawai->nama }})<br>
                NIP. {{ $pegawai->nip }}
            </td>
        </tr>
    </table>

    <!-- VII. PERTIMBANGAN ATASAN -->
    <table>
        <tr>
            <td colspan="4" class="font-bold">VII. PERTIMBANGAN ATASAN LANGSUNG**</td>
        </tr>
        <tr>
            <td width="15%" class="text-center">DI SETUJUI</td>
            <td width="20%" class="text-center">PERUBAHAN****</td>
            <td width="25%" class="text-center">DI TANGGUHKAN****</td>
            <td width="40%" class="text-center">TIDAK DI SETUJUI****</td>
        </tr>
        <tr>
            <td class="text-center font-bold">V</td>
            <td></td>
            <td></td>
            <td></td>
        </tr>
        <tr>
            <td colspan="3"></td>
            <td class="text-center" style="padding-top: 15px; padding-bottom: 15px;">
                {{ $pengajuan->atasanL1->jabatan ?? 'Atasan Langsung' }}<br>
                {{ $pengajuan->atasanL1->departemen ?? '' }}<br><br><br><br>
                ({{ $pengajuan->atasanL1->nama ?? '.........................................' }})<br>
                NIP. {{ $pengajuan->atasanL1->nip ?? '.....................................' }}
            </td>
        </tr>
    </table>

    <!-- VIII. KEPUTUSAN PEJABAT -->
    <table>
        <tr>
            <td colspan="4" class="font-bold">VIII. KEPUTUSAN PEJABAT YANG BERWENANG MEMBERIKAN CUTI**</td>
        </tr>
        <tr>
            <td width="15%" class="text-center">DI SETUJUI</td>
            <td width="20%" class="text-center">PERUBAHAN****</td>
            <td width="25%" class="text-center">DI TANGGUHKAN****</td>
            <td width="40%" class="text-center">TIDAK DI SETUJUI****</td>
        </tr>
        <tr>
            <td class="text-center font-bold">V</td>
            <td></td>
            <td></td>
            <td></td>
        </tr>
        <tr>
            <td colspan="3"></td>
            <td class="text-center" style="padding-top: 15px; padding-bottom: 15px;">
                {{ $pengajuan->atasanL3->jabatan ?? 'Pejabat Berwenang' }}<br>
                {{ $pengajuan->atasanL3->departemen ?? '' }}<br><br><br><br>
                ({{ $pengajuan->atasanL3->nama ?? '.........................................' }})<br>
                NIP. {{ $pengajuan->atasanL3->nip ?? '.....................................' }}
            </td>
        </tr>
    </table>
</body>

</html>