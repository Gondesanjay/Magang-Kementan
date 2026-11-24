<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// Validasi batal mandiri cuti yang sudah disetujui (CutiController@batalkanMandiri).
class BatalkanMandiriRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // kepemilikan dicek di controller (di dalam transaksi)
    }

    public function rules(): array
    {
        return [
            'alasan_pembatalan' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'alasan_pembatalan.required' => 'Alasan pembatalan wajib diisi.',
        ];
    }
}
