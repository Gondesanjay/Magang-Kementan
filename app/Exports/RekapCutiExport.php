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


    // --- FUNGSI PENCEGAH FORMULA INJECTION (METODE WRAPPER OWASP) ---
    private function sanitizeFormulaInjection(mixed $value): string
    {
        $value = (string) $value;


        // Jika karakter pertama adalah simbol rumus berbahaya (=, +, -, @)
        if (in_array(substr(ltrim($value), 0, 1), ['=', '+', '-', '@'], true)) {
            // Trik "Safe Formula Wrapper" (Bungkus sebagai rumus pencetak teks)
            // Ini akan mengubah input =SUM(1+1) menjadi ="=SUM(1+1)"
            // WPS akan mengeksekusi bungkusan ini, dan hasil akhirnya di layar
            // adalah teks "=SUM(1+1)" secara utuh, bukan angka 2!


            // Bersihkan tanda kutip ganda di dalam teks agar tidak merusak bungkusan
            $escapedValue = str_replace('"', '""', $value);


            return '="' . $escapedValue . '"';
        }


        return $value;
    }


    public function array(): array
    {
        return array_map(function ($row) {
            $row = array_values($row);


            // 1. Sanitasi Nama Pegawai (Indeks 1)
            if (isset($row[1])) {
                $row[1] = $this->sanitizeFormulaInjection($row[1]);
            }


            // 2. Format NIP atau kolom identitas lainnya
            if (isset($row[2])) {
                $row[2] = ' ' . trim((string) $row[2]);
            }


            // 3. Format nilai bulan dan total
            for ($i = 3; $i <= 15; $i++) {
                $nilai = $row[$i] ?? 0;
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


        $sheet->getStyle('A1:' . $highestColumn . '1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FF1E293B'],
            ],
        ]);


        $sheet->getStyle('A2:A' . $highestRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('D2:' . $highestColumn . $highestRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);


        for ($row = 2; $row <= $highestRow; $row++) {
            $kolomBulan = ['D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O'];


            foreach ($kolomBulan as $col) {
                $cellValue = $sheet->getCell($col . $row)->getValue();


                if ((int) $cellValue > 0) {
                    $sheet->getStyle($col . $row)->applyFromArray([
                        'fill' => [
                            'fillType' => Fill::FILL_SOLID,
                            'startColor' => ['argb' => 'FF3B82F6'],
                        ],
                        'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                    ]);
                } else {
                    $sheet->getStyle($col . $row)->applyFromArray([
                        'fill' => [
                            'fillType' => Fill::FILL_SOLID,
                            'startColor' => ['argb' => 'FFF8FAFC'],
                        ],
                        'font' => ['color' => ['argb' => 'FF94A3B8']],
                    ]);
                }
            }


            $sheet->getStyle('P' . $row)->applyFromArray([
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FF22C55E'],
                ],
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            ]);
        }
    }
}
