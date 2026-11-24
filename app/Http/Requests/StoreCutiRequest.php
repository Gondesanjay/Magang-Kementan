<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// Validasi form pengajuan cuti (CutiController@store).
// Aturan bisnis lain (rentang tanggal, saldo, bentrok, dsb.) tetap di controller.
class StoreCutiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // rute sudah dilindungi middleware 'auth'
    }

    public function rules(): array
    {
        return [
            'jenis_cuti' => ['required', 'string', 'in:Cuti Tahunan,Cuti Melahirkan,Cuti Besar,Cuti Alasan Penting'],
            'anak_ke' => ['nullable', 'required_if:jenis_cuti,Cuti Melahirkan', 'integer', 'min:1', 'max:20'],
            // Tanggal mulai boleh yang sudah lewat (backdate).
            'tanggal_mulai' => ['required', 'date'],
            'tanggal_selesai' => ['required', 'date', 'after_or_equal:tanggal_mulai'],
            'keterangan' => ['required', 'string'],
            'alamat_cuti' => ['required', 'string'],
            'no_telp' => ['required', 'string', 'max:20'],
            'lampiran' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'jenis_cuti.required' => 'Jenis cuti wajib dipilih.',
            'jenis_cuti.in'       => 'Jenis cuti tidak valid.',
            'anak_ke.required_if' => 'Kolom anak ke- wajib diisi untuk Cuti Melahirkan.',
            'anak_ke.integer'     => 'Kolom anak ke- harus berupa angka.',
            'anak_ke.min'         => 'Kolom anak ke- minimal 1.',
            'anak_ke.max'         => 'Kolom anak ke- maksimal 20.',

            'tanggal_mulai.required'         => 'Tanggal mulai wajib diisi.',
            'tanggal_mulai.date'             => 'Tanggal mulai tidak valid.',
            'tanggal_selesai.required'       => 'Tanggal selesai wajib diisi.',
            'tanggal_selesai.date'           => 'Tanggal selesai tidak valid.',
            'tanggal_selesai.after_or_equal' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',

            'keterangan.required'  => 'Keterangan / alasan cuti wajib diisi.',
            'alamat_cuti.required' => 'Alamat selama cuti wajib diisi.',
            'no_telp.required'     => 'Nomor telepon wajib diisi.',
            'no_telp.max'          => 'Nomor telepon maksimal 20 karakter.',

            'lampiran.file'     => 'Lampiran harus berupa file.',
            'lampiran.mimes'    => 'Format lampiran harus PDF, JPG, atau PNG.',
            'lampiran.max'      => 'Ukuran lampiran maksimal 2 MB.',
            'lampiran.uploaded' => 'Lampiran gagal diunggah. Pastikan ukurannya tidak lebih dari 2 MB.',
        ];
    }
}
