<?php


namespace App\Exports;


use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;


class RekapCutiExport implements FromArray, WithHeadings, WithStyles, ShouldAutoSize
{
    protected array $headings;
    protected array $rows;


    public function __construct(array $headings, array $rows)
    {
        $this->headings = $headings;
        $this->rows = $rows;
    }


    public function array(): array
    {
        return array_map(function ($row) {
            $row = array_values($row);


            if (isset($row[2])) {
                $row[2] = " " . trim($row[2]);
            }


            for ($i = 3; $i <= 15; $i++) {
                $nilai = isset($row[$i]) ? $row[$i] : 0;
                if (empty($nilai) || $nilai == 0) {
                    $row[$i] = '0';
                } else {
                    $row[$i] = (string) $nilai;
                }
            }


            return $row;
        }, $this->rows);
    }


    public function headings(): array
    {
        return $this->headings;
    }


    public function styles(Worksheet $sheet)
    {
        $highestRow = $sheet->getHighestRow();
        $highestColumn = $sheet->getHighestColumn();
        $cellRange = 'A1:' . $highestColumn . $highestRow;


        // --- 1. BORDERS & ALIGNMENT ---
        $sheet->getStyle($cellRange)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['argb' => 'FF000000'],
                ],
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);


        // --- 2. HEADER GELAP ---
        $sheet->getStyle('A1:' . $highestColumn . '1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FF1E293B'], // Biru/Abu sangat gelap
            ],
        ]);


        // --- 3. TEXT CENTER ---
        $sheet->getStyle('A2:A' . $highestRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('D2:' . $highestColumn . $highestRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);


        // --- 4. WARNA SUPER KONTRAS UNTUK ISI TABEL ---
        for ($row = 2; $row <= $highestRow; $row++) {


            $kolomBulan = ['D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O'];


            foreach ($kolomBulan as $col) {
                $cellValue = $sheet->getCell($col . $row)->getValue();


                if ((int)$cellValue > 0) {
                    // >> JIKA ADA CUTI: BIRU SOLID TERANG, TEKS PUTIH <<
                    $sheet->getStyle($col . $row)->applyFromArray([
                        'fill' => [
                            'fillType' => Fill::FILL_SOLID,
                            'startColor' => ['argb' => 'FF3B82F6'], // Biru Terang (Blue-500)
                        ],
                        'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']], // Putih
                    ]);
                } else {
                    // >> JIKA NOL (0): ABU-ABU SANGAT PUCAT, TEKS SAMAR <<
                    // Tujuannya agar fokus mata langsung tertuju pada warna biru
                    $sheet->getStyle($col . $row)->applyFromArray([
                        'fill' => [
                            'fillType' => Fill::FILL_SOLID,
                            'startColor' => ['argb' => 'FFF8FAFC'], // Hampir putih
                        ],
                        'font' => ['color' => ['argb' => 'FF94A3B8']], // Teks abu-abu pudar
                    ]);
                }
            }


            // >> KOLOM TOTAL CUTI: HIJAU SOLID TERANG, TEKS PUTIH <<
            $sheet->getStyle('P' . $row)->applyFromArray([
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FF22C55E'], // Hijau Terang (Green-500)
                ],
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']], // Putih
            ]);
        }
    }
}
