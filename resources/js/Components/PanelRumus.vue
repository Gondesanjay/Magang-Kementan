<script setup>
// Panel kecil yang menjelaskan cara menghitung satu angka (Bawaan Eligible,
// Total Tersedia, atau Saldo Akhir). Murni tampilan: semua angka datang dari
// prop 'rincian' yang disusun halaman Rekap Kuota Detail.
//
// Bentuk 'rincian':
// {
//   judul:   'Total Tersedia 2026',
//   rumus:   [{ label, nilai } | { op }],   // token rumus, urut kiri ke kanan
//   hasil:   20,
//   catatan: ['...'],                       // opsional
//   tautan:  { kolom: 'bawaan', teks: '...' } // opsional, telusuri asal angka
// }
defineProps({ rincian: { type: Object, required: true } });
defineEmits(["tutup", "pindah"]);
</script>


<template>
    <div
        role="region"
        :aria-label="`Cara menghitung ${rincian.judul}`"
        class="rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-xs shadow-sm"
    >
        <div class="flex items-start justify-between gap-2">
            <p class="font-bold text-slate-800">
                Cara menghitung {{ rincian.judul }}
            </p>
            <button
                type="button"
                @click="$emit('tutup')"
                aria-label="Tutup rumus"
                class="shrink-0 px-1 text-slate-400 hover:text-slate-600 cursor-pointer"
            >
                ✕
            </button>
        </div>


        <div class="mt-2 flex flex-wrap items-center gap-1.5">
            <template v-for="(t, i) in rincian.rumus" :key="i">
                <span
                    v-if="t.label"
                    class="inline-flex items-center gap-1 rounded-md border border-slate-200 bg-slate-50 px-2 py-1"
                >
                    <span class="text-slate-500">{{ t.label }}</span>
                    <b class="text-slate-800">{{ t.nilai }}</b>
                </span>
                <span v-else class="font-semibold text-slate-500">{{
                    t.op
                }}</span>
            </template>
            <span class="font-semibold text-slate-500">=</span>
            <span
                class="rounded-md bg-slate-800 px-2 py-1 font-extrabold text-white"
                >{{ rincian.hasil }}</span
            >
        </div>


        <ul
            v-if="rincian.catatan?.length"
            class="mt-2 list-disc space-y-0.5 pl-4 text-[11px] text-slate-500"
        >
            <li v-for="(c, i) in rincian.catatan" :key="i">{{ c }}</li>
        </ul>


        <button
            v-if="rincian.tautan"
            type="button"
            @click="$emit('pindah', rincian.tautan.kolom)"
            class="mt-2 text-[11px] font-semibold text-indigo-600 underline cursor-pointer"
        >
            {{ rincian.tautan.teks }}
        </button>
    </div>
</template>

