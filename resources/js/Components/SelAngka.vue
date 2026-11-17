<script setup>
// Kapsul angka. Mode tampil = tombol berwarna (klik untuk mulai edit).
// Mode edit = input yang ukurannya mengikuti kapsul, sehingga baris tidak melebar.
//
// 'sorot' = kelas warna sorotan rumus (target / sumber) dari halaman induk.
// Saat ada isinya, input memakai warna itu (bukan warna edit bawaan), sehingga
// kotak yang sedang diedit tetap berwarna selama rumusnya disorot.
defineProps({
    modelValue: [Number, String],
    nilai: [Number, String],
    field: String,
    editing: Boolean,
    kelas: String,
    sorot: { type: String, default: "" },
    label: String,
    max: Number,
    title: String,
    bintang: Boolean,
    // true = ukuran lebih besar untuk layar sentuh (kartu mobile)
    besar: Boolean,
});
defineEmits(["update:modelValue", "mulai", "simpan"]);
</script>

<template>
    <input
        v-if="editing"
        type="number"
        inputmode="numeric"
        min="0"
        :max="max"
        :value="modelValue"
        :data-edit-field="field"
        :aria-label="label"
        :title="title"
        @input="
            $emit(
                'update:modelValue',
                $event.target.value === '' ? '' : Number($event.target.value),
            )
        "
        @keydown.enter.prevent="$emit('simpan')"
        @focus="$event.target.select()"
        class="rounded-md border px-0 text-center font-bold outline-none [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
        :class="[
            besar ? 'h-10 w-16 text-base' : 'h-[26px] w-[40px] text-xs',
            sorot
                ? sorot
                : 'border-emerald-500 bg-white text-gray-800 ring-2 ring-emerald-200 focus:ring-emerald-400',
        ]"
    />
    <button
        v-else
        type="button"
        @click="$emit('mulai')"
        :title="title || 'Klik untuk mengubah'"
        class="inline-block cursor-pointer rounded-md border px-2 py-1 text-xs font-bold transition hover:ring-2 hover:ring-emerald-300"
        :class="[kelas, besar ? 'min-w-[36px]' : 'min-w-[40px]']"
    >
        {{ nilai }}<sup v-if="bintang" class="font-black text-amber-600">*</sup>
    </button>
</template>