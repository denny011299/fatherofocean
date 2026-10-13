# TODO - Snapshot company loyalty amount per transaksi

- [x] Audit alur simpan `store` di `app/Http/Controllers/BarangController.php` untuk memastikan default `company_loyalty_amount` terisi saat create.
- [x] Pastikan update transaksi lama tidak overwrite `company_loyalty_amount` (field di-unset saat update).
- [x] Verifikasi kalkulasi profit pakai snapshot tersimpan via `SalesRevenueCalculator::resolveCompanyLoyaltyAmount()`.
- [x] Verifikasi fallback ke `CompanyRevenueSettings::fixedAmount()` hanya untuk data legacy yang `company_loyalty_amount` null/0.
