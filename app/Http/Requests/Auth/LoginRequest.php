<?php

namespace App\Http\Requests\Auth;

use App\Models\Pegawai;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nip' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nip.required' => 'NIP wajib diisi.',
            'nip.string' => 'NIP tidak valid.',
            'password.required' => 'Password wajib diisi.',
            'password.string' => 'Password tidak valid.',
        ];
    }

    /**
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $akun = Pegawai::where('nip', $this->input('nip'))->first();

        if ($akun === null) {
            RateLimiter::hit($this->throttleKey(), 300);

            throw ValidationException::withMessages([
                'nip' => 'NIP tidak terdaftar.',
            ]);
        }

        if (! Auth::attempt($this->only('nip', 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey(), 300);

            throw ValidationException::withMessages([
                'password' => 'Password yang Anda masukkan salah.',
            ]);
        }

        $status = Auth::user()->status_aktif ?: 'Aktif';

        if ($status !== 'Aktif') {
            Auth::guard('web')->logout();
            RateLimiter::clear($this->throttleKey());

            throw ValidationException::withMessages([
                'nip' => "Akun Anda berstatus {$status} dan tidak dapat digunakan untuk login. Silakan hubungi Admin HR.",
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        $pesan = $seconds < 60
            ? "Terlalu banyak percobaan login. Silakan coba lagi dalam {$seconds} detik."
            : 'Terlalu banyak percobaan login. Silakan coba lagi dalam ' . (int) ceil($seconds / 60) . ' menit.';

        throw ValidationException::withMessages([
            'nip' => $pesan,
        ]);
    }

    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('nip')) . '|' . $this->ip());
    }
}
