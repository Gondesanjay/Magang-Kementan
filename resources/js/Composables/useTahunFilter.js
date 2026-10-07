import { computed } from "vue";

/**
 * useTahunFilter
 * ------------------------------------------------------------------
 * Composable untuk menghasilkan daftar tahun secara DINAMIS (bukan
 * hardcode), dipakai di semua filter tahun (Rekap Laporan, Rekap Kuota
 * Detail, Monitoring Cuti, dsb) supaya:
 *   1. Tidak perlu edit kode tiap pergantian tahun.
 *   2. Semua halaman otomatis konsisten (satu sumber logika saja).
 *
 * @param {number} rentangBelakang  Berapa tahun ke belakang dari tahun
 *                                  berjalan yang ikut ditampilkan.
 *                                  Default: 2 (mis. 2026 -> 2024, 2025, 2026)
 * @param {number} rentangDepan     Berapa tahun ke depan dari tahun
 *                                  berjalan yang ikut ditampilkan.
 *                                  Default: 1 (mis. 2026 -> sampai 2027)
 *
 * @returns {ComputedRef<number[]>} Daftar tahun, urut dari TERBARU ke
 *                                  TERLAMA (mis. [2027, 2026, 2025, 2024]),
 *                                  supaya pilihan tahun berjalan/terbaru
 *                                  ada di paling atas dropdown.
 *
 * ------------------------------------------------------------------
 * Contoh pemakaian di komponen .vue:
 *
 *   import { useTahunFilter } from "@/composables/useTahunFilter";
 *   const daftarTahunFilter = useTahunFilter();
 *
 *   <select v-model="filterTahun">
 *       <option
 *           v-for="tahun in daftarTahunFilter"
 *           :key="tahun"
 *           :value="tahun.toString()"
 *       >
 *           {{ tahun }}
 *       </option>
 *   </select>
 * ------------------------------------------------------------------
 */
export function useTahunFilter(rentangBelakang = 2, rentangDepan = 1) {
    const currentYear = new Date().getFullYear();

    return computed(() => {
        const years = [];
        for (let i = rentangDepan; i >= -rentangBelakang; i--) {
            years.push(currentYear + i);
        }
        return years;
    });
}