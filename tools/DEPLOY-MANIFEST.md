# Deploy Manifest — Ocean Link / Pelayaran

**Last verified:** 2026-06-20 — `php tools/verify-deploy.php` **PASS** (168 PHP files in `app/`)

**Never deploy partial stub files.** Upload every file in a bundle together, then run `php tools/verify-deploy.php` on the server (or locally before upload).

## Pre-deploy command

```bash
php tools/verify-deploy.php
php artisan test --parallel   # optional but recommended (needs sqlite or mysql driver)
npm run build                 # if frontend changed; delete public/hot if not using Vite dev server
```

**Local verification result (2026-06-20):**
- Syntax: 168/168 PHP files in `app/` — no parse errors
- verify-deploy: PASS (53 Support static refs verified)
- Pure unit tests pass: CbmFormat, RmbFormat, ExampleTest
- DB tests require sqlite/mysql driver on the machine running tests

---

## Full `app/` file list (168 PHP files — upload entire folder)

See `DEPLOY-SEKARANG.md` for FileZilla steps. Summary by directory:

| Directory | Count | Notes |
|-----------|-------|-------|
| `app/Support/` | 45 | All Support classes — never partial |
| `app/Models/` | 47 | Including Marking, Invoice, User, Barang |
| `app/Http/Controllers/` | 31 | Full controllers, not stubs |
| `app/Exports/` | 14 | |
| `app/Services/` | 7 | |
| `app/Jobs/` | 3 | |
| `app/Console/Commands/` | 4 | |
| `app/Http/Middleware/` | 4 | |
| `app/Livewire/` | 3 | |
| `app/Imports/` | 1 | |
| `app/Providers/` | 1 | AppServiceProvider |
| `app/helpers.php` | 1 | Autoloaded via composer.json |

**Also upload:** `tools/verify-deploy.php` (updated scope detection)

**Removed from app/ (do not restore):** debug_packing_*.php, inspect-*.php, test-logo-upload.php, generate-test-terima.php, device-manager.blade.php, SalesRevenueCalculator_commission_detail.php

---

## Recently fixed methods (2026-06-20 recovery)

| Class | Method | Status |
|-------|--------|--------|
| `MarkingPricingResolver` | `modalRatesFromPricingRow()` | ✅ Added |
| `Branding` | `absoluteLogoPath()` | ✅ Added |
| `User` | `commissionRateLabel()` | ✅ Added |
| `Marking` | `visibleToUser()` | ✅ scopeVisibleToUser (was false positive) |
| `Marking` | `whereBarangMarkingValue()` | ✅ scopeWhereBarangMarkingValue |
| `Invoice` | `fullyPaid()` | ✅ scopeFullyPaid |
| `BarangProduct` | `normalizedPhotoPath()` | ✅ Already present |
| `BarangController` | `update()` + requiresApproval | ✅ Already wired |

---

## Bundle A — Omset export (minimum set)

These files depend on each other. A partial deploy causes `Locales::name()`, omset SQL HY093, or missing builder errors.

| File | Role |
|------|------|
| `app/Support/Locales.php` | `name()`, `names()` for language switcher + export views |
| `app/Support/OmsetInvoiceQuery.php` | Period SQL, payment status |
| `app/Support/OmsetExportDataBuilder.php` | Preview + Excel payload |
| `app/Http/Controllers/ExportController.php` | omsetIndex, omsetData, omsetExcel, omsetPdf |
| `app/Models/Marking.php` | Team scope for omset (`userHasTeamMarkingScope`, `restrictInvoicesQuery`) |
| `app/Models/Invoice.php` | `fullyPaid` scope for payment status label |
| `app/Models/User.php` | `subordinates`, `portalScopeUserIds`, `earnsCapitalGainOnly`, commission helpers |
| `app/Exports/OmsetExport.php` | Excel export class |
| `app/Services/Exports/OmsetSpreadsheetBuilder.php` | Spreadsheet layout |
| `resources/views/exports/omset-report.blade.php` | UI |
| `resources/views/exports/partials/omset-results.blade.php` | AJAX partial |
| `resources/views/exports/omset-report-pdf.blade.php` | PDF template |
| `resources/views/components/language-switcher.blade.php` | Uses `Locales::name()` |
| `routes/web.php` | Export routes (if changed) |

---

## Bundle B — Marking / pricing (Barang form)

| File | Role |
|------|------|
| `app/Support/MarkingPricingResolver.php` | `normalizeKey`, `legacyCbmTypeAlias`, `pickRow`, `modalRatesFromPricingRow` |
| `app/Support/MarkingBarangPricing.php` | Marking price snapshot |
| `app/Support/CompanyRevenueSettings.php` | Uses `MarkingPricingResolver` |
| `app/Support/BarangShippingPricing.php` | Uses `Marking::visibleToUser` |
| `app/Support/BarangCostPricing.php` | Modal rates |
| `app/Models/Marking.php` | Full model — **never deploy stub** |
| `app/Models/MarkingPricing.php` | Pricing rows |
| `app/Models/MasterPricingItem.php` | `active()` scope |
| `app/Http/Controllers/BarangController.php` | Uses resolver + ShippingLabelPdfData |

---

## Bundle C — PDF / labels

| File | Role |
|------|------|
| `app/Support/ShippingLabelPdfData.php` | Namespace **must** be `App\Support` |
| `app/Support/TerimaBarangPdfData.php` | Terima barang PDF payload |
| `app/Support/TerimaBarangPdfFonts.php` | CJK font helpers |
| `app/Support/TerimaBarangPdfImages.php` | Image embed helpers |
| `app/Support/TerimaBarangPdfData.php` | Receipt PDF |
| `app/Support/SuratJalanPdfData.php` | Surat jalan |
| `app/Support/InvoicePdfData.php` | Invoice PDF |
| `app/Support/Branding.php` | Logo paths |

---

## Bundle D — Sales / commission (if dashboard or revenue touched)

| File | Role |
|------|------|
| `app/Support/SalesRevenueCalculator.php` | Commission + `applyCommissionBarangScope` |
| `app/Support/SalesCommissionSettings.php` | Settings |
| `app/Support/ShipmentEarningsSettlement.php` | Partai / paid checks |
| `app/Support/SalesIncentiveCalculator.php` | Incentive math |
| `app/Support/SalesSalaryAggregator.php` | Salary aggregation |

---

## Bundle E — Shared infrastructure

Always safe to include when any Support class changed:

```
app/Support/*.php          (all 45 files — see app/Support/)
app/Models/Marking.php
app/Models/Invoice.php
app/Models/User.php
app/Models/Barang.php
app/Models/MasterPricingItem.php
composer.json / composer.lock   (if dependencies changed)
```

---

## Known partial-stub symptoms

| Error | Missing / stale file |
|-------|----------------------|
| `Locales::name()` undefined | `app/Support/Locales.php` |
| `MasterPricingItem::active()` | `app/Models/MasterPricingItem.php` (scope) |
| `MarkingPricingResolver::normalizeKey()` | `app/Support/MarkingPricingResolver.php` |
| `Marking::visibleToUser()` | `app/Models/Marking.php` (stub deployed) |
| `Class "App\Support\ShippingLabelPdfData" not found` | Wrong path or namespace in `app/Support/ShippingLabelPdfData.php` |
| SQLSTATE HY093 on omset | `app/Support/OmsetInvoiceQuery.php` (mismatched WEEK bind) |
| Omset export 500 | `ExportController.php` + `OmsetExportDataBuilder.php` together |
| `User::earnsCapitalGainOnly()` undefined | `app/Models/User.php` (stub deployed) |
| `User::commissionRateOverride()` undefined | `app/Models/User.php` (stub deployed) |

---

## Post-deploy

1. `php artisan config:clear && php artisan view:clear`
2. `php tools/verify-deploy.php`
3. Smoke-test: `/export/omset`, language switcher, barang label PDF, marking dropdown
