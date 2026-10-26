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


        /* Nama penandatangan 10pt supaya nama bergelar panjang muat satu baris
           di lebar kolom tanda tangan (50% halaman). */
        .signature-name {
            text-decoration: underline;
            font-weight: bold;
            font-size: 10pt;
            white-space: nowrap;
            margin-top: 10px;
        }
    </style>
</head>


<body>

    {{--
        Helper path tanda tangan (dipakai blok L3 dan L4).
        Mengembalikan path file lokal untuk DomPDF, atau null kalau tidak ada.
        Urutan: 1) signature_path milik pejabat, 2) gambar default, 3) null.
        is_file() (bukan file_exists) supaya path kosong yang menunjuk ke
        folder "storage/" tidak dianggap sebagai file gambar.
    --}}
    @php
    $cariTandaTangan = function ($pejabat, string $fileDefault) {
    if ($pejabat && !empty($pejabat->signature_path)) {
    $pathPejabat = public_path('storage/' . ltrim($pejabat->signature_path, '/'));
    if (is_file($pathPejabat)) {
    return $pathPejabat;
    }
    }

    $pathDefault = public_path('storage/signatures/' . $fileDefault);

    return is_file($pathDefault) ? $pathDefault : null;
    };

    $ttdKasubag = $cariTandaTangan($kasubag ?? null, 'kasubag.png');
    $ttdKabiro = $cariTandaTangan($kabiro ?? null, 'kabiro.png');
    @endphp


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
                <td>Kelompok Substansi</td>
                <td>:</td>
                <td>{{ $pegawai->kelompok_substansi ?: ((int) $pegawai->role_id === 6 ? 'Biro Perencanaan' : '-') }}</td>
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


    @if(!empty($pemohonKabiro))
    {{-- Pemohon = Kepala Biro (L4): tanpa blok tanda tangan L3/L4 --}}
    <div style="margin-top: 50px; text-align: right;">
        <div style="display: inline-block; text-align: center;">
            Jakarta, {{ \Carbon\Carbon::now()->locale('id')->translatedFormat('d F Y') }}<br>
            Kepala Biro Perencanaan
            <div class="signature-name">{{ $pegawai->nama }}</div>
            NIP. {{ $pegawai->nip ?? '-' }}
        </div>
    </div>

    <p style="margin-top: 30px; font-size: 10pt; font-style: italic;">
        Cuti ini disetujui otomatis oleh sistem karena pemohon adalah Kepala Biro Perencanaan selaku pejabat yang berwenang memberikan cuti.
    </p>

    @elseif(!empty($pemohonKasubag))
    {{-- Pemohon = Kasubag TU (L3): rantai approval hanya L4, jadi hanya blok Kepala Biro --}}
    <table class="signature-table">
        <tr>
            <td></td>
            <td>
                <div class="signature-date">
                    Jakarta, {{ \Carbon\Carbon::now()->locale('id')->translatedFormat('d F Y') }}
                </div>
                Mengetahui,<br>
                Pejabat Yang Berwenang Memberikan Cuti<br>
                Kepala Biro Perencanaan

                <div style="height: 70px; margin: 10px 0;">
                    @if($ttdKabiro)
                    <img src="{{ $ttdKabiro }}" style="height: 70px;">
                    @else
                    <span style="font-size: 10pt; color: #555; font-style: italic;">(Tanda Tangan)</span>
                    @endif
                </div>

                <div class="signature-name" style="margin-top: 10px;">
                    {{ $kabiro ? $kabiro->nama : '(Kepala Biro belum ditentukan)' }}
                </div>
                @if($kabiro)
                NIP. {{ $kabiro->nip }}
                @endif
            </td>
        </tr>
    </table>

    @else
    <table class="signature-table">
        <tr>
            <!-- Kolom Kiri: Atasan Langsung (L3 - Kasubag TU) -->
            <td>
                <br>
                Atasan Langsung,<br>
                Kepala Subbagian Tata Usaha

                <div style="height: 70px; margin: 10px 0;">
                    @if($ttdKasubag)
                    <img src="{{ $ttdKasubag }}" style="height: 70px;">
                    @else
                    <span style="font-size: 10pt; color: #555; font-style: italic;">(Tanda Tangan)</span>
                    @endif
                </div>

                <div class="signature-name" style="margin-top: 10px;">
                    {{ $kasubag ? $kasubag->nama : '(Kasubag TU belum ditentukan)' }}
                </div>
                @if($kasubag)
                NIP. {{ $kasubag->nip }}
                @endif
            </td>

            <!-- Kolom Kanan: Pejabat Berwenang (L4 - Kepala Biro Perencanaan) -->
            <td>
                <div class="signature-date">
                    Jakarta, {{ \Carbon\Carbon::now()->locale('id')->translatedFormat('d F Y') }}
                </div>
                Mengetahui,<br>
                Pejabat Yang Berwenang Memberikan Cuti<br>
                Kepala Biro Perencanaan

                <div style="height: 70px; margin: 10px 0;">
                    @if($ttdKabiro)
                    <img src="{{ $ttdKabiro }}" style="height: 70px;">
                    @else
                    <span style="font-size: 10pt; color: #555; font-style: italic;">(Tanda Tangan)</span>
                    @endif
                </div>

                <div class="signature-name" style="margin-top: 10px;">
                    {{ $kabiro ? $kabiro->nama : '(Kepala Biro belum ditentukan)' }}
                </div>
                @if($kabiro)
                NIP. {{ $kabiro->nip }}
                @endif
            </td>
        </tr>
    </table>
    @endif


</body>


</html>