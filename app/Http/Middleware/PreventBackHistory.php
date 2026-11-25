<?php
/**
 * =====================================================================
 * Project      : Sistem Informasi Cuti ASN - Portal Cuti Digital Terintegrasi
 * Description  : Sistem Informasi Manajemen Cuti Pegawai
 * Agency       : Kementerian Pertanian Republik Indonesia
 *                (Kelompok Substansi Subbagian Tata Usaha)
 * =====================================================================
 * Developer    : Ikmal Rizal (NIM: 0110124148) & Aisah Khoirunisa (NIM: 0110124066)
 * Role         : Web Development Intern
 * Institution  : STT Terpadu Nurul Fikri 
 * Year         : 2026
 * =====================================================================
 * Notice       : This software was developed for internal use by 
 *                Kementerian Pertanian RI. All rights reserved.
 * =====================================================================
 */
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PreventBackHistory
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Unduhan file (Excel, PDF, lampiran) dibiarkan apa adanya.
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return $response;
        }

        $response->headers->set('Cache-Control', 'no-cache, no-store, max-age=0, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', 'Sun, 02 Jan 1990 00:00:00 GMT');

        return $response;
    }
}
