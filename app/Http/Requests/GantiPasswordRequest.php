<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// Validasi ganti password pertama kali (routes/web.php: password.change.store).
class GantiPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // rute sudah dilindungi middleware 'auth'
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'confirmed', 'min:8'],
        ];
    }

    public function messages(): array
    {
        return [
            'password.required'  => 'Password baru wajib diisi.',
            'password.confirmed' => 'Konfirmasi password tidak cocok.',
            'password.min'       => 'Password minimal 8 karakter.',
        ];
    }
}
