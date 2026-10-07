<?php


namespace App\Http\Requests\Auth;


use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;


class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }


    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Cukup gunakan required dan string
            'nip' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }


    /**
     * Pesan validasi dalam bahasa Indonesia.
     *
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
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();


        // ---> NIP tidak ditemukan: error ditampilkan di kolom NIP <---
        // Memakai provider login yang sama dengan Auth::attempt() (model &
        // tabel diatur di config/auth.php), jadi tidak bergantung pada tabel users.
        $akun = Auth::guard('web')->getProvider()->retrieveByCredentials([
            'nip' => $this->input('nip'),
        ]);


        if ($akun === null) {
            RateLimiter::hit($this->throttleKey());


            throw ValidationException::withMessages([
                'nip' => 'NIP tidak terdaftar.',
            ]);
        }


        // ---> NIP ada tetapi password salah: error ditampilkan di kolom Password <---
        if (! Auth::attempt($this->only('nip', 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());


            throw ValidationException::withMessages([
                'password' => 'Password yang Anda masukkan salah.',
            ]);
        }


        // ---> PENAMBAHAN: TOLAK LOGIN UNTUK PEGAWAI NON-AKTIF <---
        // Pengecekan status SENGAJA dilakukan SETELAH NIP & password terbukti
        // benar (bukan sebelum Auth::attempt), supaya orang yang menebak-nebak
        // NIP tidak bisa mengetahui status kepegawaian seseorang dari pesan
        // errornya. Status kosong (NULL) dianggap 'Aktif' supaya pegawai lama
        // tidak ikut terkunci.
        $status = Auth::user()->status_aktif ?: 'Aktif';


        if ($status !== 'Aktif') {
            // Batalkan sesi yang barusan dibuat oleh Auth::attempt()
            // (termasuk cookie "remember me" kalau tadi dicentang).
            Auth::guard('web')->logout();


            // Kredensial-nya benar, jadi ini bukan percobaan tebak password.
            RateLimiter::clear($this->throttleKey());


            throw ValidationException::withMessages([
                'nip' => "Akun Anda berstatus {$status} dan tidak dapat digunakan untuk login. Silakan hubungi Admin HR.",
            ]);
        }


        RateLimiter::clear($this->throttleKey());
    }


    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }


        event(new Lockout($this));


        $seconds = RateLimiter::availableIn($this->throttleKey());
        $menit = (int) ceil($seconds / 60);


        throw ValidationException::withMessages([
            'nip' => "Terlalu banyak percobaan login. Silakan coba lagi dalam {$seconds} detik ({$menit} menit).",
        ]);
    }


    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('nip')) . '|' . $this->ip());
    }
}
