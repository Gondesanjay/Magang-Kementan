import { test, expect } from '@playwright/test';

test('Karyawan berhasil mengajukan cuti tahunan', async ({ page }) => {
  await page.goto('http://127.0.0.1:8000/login');

  await page.locator('#nip').fill('199004042015041004');
  await page.locator('#password').fill('ikmal123');
  await page.getByRole('button', { name: 'MASUK', exact: true }).click();
  await expect(page).toHaveURL(/.*dashboard/);

  await page.locator('a[href="http://127.0.0.1:8000/karyawan/ajukan-cuti"]').first().click();
  await expect(page).toHaveURL(/.*karyawan\/ajukan-cuti/);

  await page.locator('select').selectOption('Cuti Tahunan');

  // PERBAIKAN 1: Kita ajukan 1 hari saja (27 Okt - 27 Okt) agar tidak melebihi sisa cuti dummy
  // Dan tambahkan .press('Tab') agar Vue langsung membaca ketikan Playwright
  await page.locator('input[type="date"]').nth(0).fill('2026-10-27');
  await page.locator('input[type="date"]').nth(0).press('Tab');

  await page.locator('input[type="date"]').nth(1).fill('2026-10-27');
  await page.locator('input[type="date"]').nth(1).press('Tab');

  await page.getByPlaceholder('Jelaskan alasan atau keperluan cuti Anda secara detail...').fill('Acara keluarga di luar kota');
  await page.getByPlaceholder('Contoh: Jl. Merdeka No. 10...').fill('Jl. Sudirman No 1');
  await page.getByPlaceholder('Contoh: 081234567890').fill('081234567890');

  // Pastikan tombol sudah terbuka (enabled) sebelum diklik
  await expect(page.getByRole('button', { name: 'Ajukan Cuti', exact: true })).toBeEnabled();
  await page.getByRole('button', { name: 'Ajukan Cuti', exact: true }).click();

  await expect(page.locator('text=Pengajuan Berhasil!')).toBeVisible();
});


test('Sistem memblokir pengajuan cuti yang melebihi sisa saldo', async ({ page }) => {
  await page.goto('http://127.0.0.1:8000/login');

  await page.locator('#nip').fill('199004042015041004');
  await page.locator('#password').fill('ikmal123');
  await page.getByRole('button', { name: 'MASUK', exact: true }).click();
  await expect(page).toHaveURL(/.*dashboard/);

  await page.locator('a[href="http://127.0.0.1:8000/karyawan/ajukan-cuti"]').first().click();
  await expect(page).toHaveURL(/.*karyawan\/ajukan-cuti/);

  await page.locator('select').selectOption('Cuti Tahunan');

  await page.locator('input[type="date"]').nth(0).fill('2026-11-01');
  await page.locator('input[type="date"]').nth(0).press('Tab');

  await page.locator('input[type="date"]').nth(1).fill('2026-11-30');
  await page.locator('input[type="date"]').nth(1).press('Tab');

  await page.getByPlaceholder('Jelaskan alasan atau keperluan cuti Anda secara detail...').fill('Liburan panjang keliling Eropa');
  await page.getByPlaceholder('Contoh: Jl. Merdeka No. 10...').fill('Jl. Merdeka No 2');
  await page.getByPlaceholder('Contoh: 081234567890').fill('081234567890');

  await expect(page.locator('.text-red-600').filter({ hasText: /Hari/ })).toBeVisible();

  const btnAjukan = page.getByRole('button', { name: 'Ajukan Cuti', exact: true });
  await expect(btnAjukan).toBeDisabled();

  // Hacker membongkar gembok 'disabled'
  await btnAjukan.evaluate((node) => node.removeAttribute('disabled'));
  await btnAjukan.click();

  // PERBAIKAN 2: Kita cari kotak error yang berlatar merah (.bg-red-50), bukan tulisan saldo biasa
  await expect(page.locator('.bg-red-50').filter({ hasText: 'Saldo' })).toBeVisible({ timeout: 15000 });
});