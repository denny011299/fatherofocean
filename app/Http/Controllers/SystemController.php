<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\MasterPricingItem;
use App\Support\AppBackup;
use App\Support\Branding;
use App\Support\CompanyRevenueSettings;
use App\Support\InvoicePaymentSettings;
use App\Support\SalesCommissionSettings;
use App\Support\UploadedImageCompressor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class SystemController extends Controller
{
    public function index(): View
    {
        return view('system.index', [
            'appLogoUrl' => Branding::logoUrl(),
            'companyRevenueFixed' => CompanyRevenueSettings::fixedAmount(),
            'commissionRates' => SalesCommissionSettings::current(),
            'invoicePaymentSettings' => InvoicePaymentSettings::current(),
            'companyItemModalRates' => CompanyRevenueSettings::itemModalRatesForForm(),
            'pricingItems' => MasterPricingItem::active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function updateSalesCommissionRates(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->can('manage system'), 403);

        $data = $request->validate([
            'default_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'company_revenue_fixed' => ['required', 'numeric', 'min:0'],
            'invoice_payment_bank' => ['nullable', 'string', 'max:120'],
            'invoice_payment_account_name' => ['nullable', 'string', 'max:160'],
            'invoice_payment_account_number' => ['nullable', 'string', 'max:120'],
        ]);

        SalesCommissionSettings::update((float) $data['default_rate']);
        CompanyRevenueSettings::update((float) $data['company_revenue_fixed']);
        InvoicePaymentSettings::update(
            (string) ($data['invoice_payment_bank'] ?? ''),
            (string) ($data['invoice_payment_account_name'] ?? ''),
            (string) ($data['invoice_payment_account_number'] ?? ''),
        );

        ActivityLog::record('System general settings updated');

        return response()->json([
            'success' => true,
            'message' => __('system.general_settings_saved'),
        ]);
    }

    public function updateItemModalRates(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->can('edit harga modal'), 403);

        $data = $request->validate([
            'rows' => ['array'],
            'rows.*.pricing_item_id' => ['required', 'integer', 'exists:master_pricing_items,id'],
            'rows.*.cost_volume_price' => ['nullable', 'numeric', 'min:0'],
            'rows.*.cost_gw_price' => ['nullable', 'numeric', 'min:0'],
            'rows.*.company_profit_per_shipment' => ['nullable', 'numeric', 'min:0'],
        ]);

        CompanyRevenueSettings::syncItemModals($data['rows'] ?? []);

        ActivityLog::record('Item modal rates updated');

        return response()->json([
            'success' => true,
            'message' => __('system.item_modal_rates_saved'),
        ]);
    }

    public function activityIndex(Request $request): View
    {
        $logs = ActivityLog::with('user')
            ->when($request->get('action'), fn ($q, $a) => $q->where('action', 'like', "%{$a}%"))
            ->when($request->get('user_id'), fn ($q, $u) => $q->where('user_id', $u))
            ->latest()
            ->paginate(30);

        return view('system.activity', compact('logs'));
    }

    public function exportDatabase(): BinaryFileResponse|JsonResponse
    {
        abort_unless(auth()->user()?->can('manage system'), 403);

        @set_time_limit(0);

        try {
            $zipFile = app(AppBackup::class)->createDatabaseDownloadZip();

            ActivityLog::record('Database exported', null, null, ['file' => basename($zipFile)]);

            return response()->download($zipFile, basename($zipFile))->deleteFileAfterSend(false);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Gagal export database: '.$e->getMessage(),
            ], 500);
        }
    }

    public function shutdown(Request $request): JsonResponse
    {
        $request->validate([
            'secret' => 'required|string',
        ]);

        ActivityLog::record('Emergency shutdown triggered');

        Artisan::call('down', [
            '--secret' => $request->secret,
        ]);

        return response()->json(['success' => true, 'message' => 'Sistem dalam mode maintenance. Bypass: /' . $request->secret]);
    }

    public function bringUp(): JsonResponse
    {
        Artisan::call('up');
        ActivityLog::record('System brought back online');

        return response()->json(['success' => true, 'message' => 'Sistem kembali online.']);
    }

    public function updateLogo(Request $request): RedirectResponse
    {
        $request->validate([
            'logo' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
        ]);

        Branding::storeLogo($request->file('logo'));
        ActivityLog::record('App logo updated');

        return redirect()
            ->route('system.index')
            ->with('status', 'Logo berhasil diunggah.');
    }

    public function removeLogo(): RedirectResponse
    {
        Branding::removeLogo();
        ActivityLog::record('App logo removed');

        return redirect()
            ->route('system.index')
            ->with('status', 'Logo dikembalikan ke default.');
    }

    public function compressStoredImages(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->can('manage system'), 403);

        $batch = max(10, min(300, (int) $request->integer('batch', 120)));
        $offset = max(0, (int) $request->integer('offset', 0));

        $directories = [
            'barang-products',
            'payment-proofs',
            'salary-proofs',
            'company-payout-proofs',
            'claims',
            'branding',
            'markings-ktp',
        ];

        $files = collect($directories)
            ->flatMap(static fn (string $dir) => Storage::disk('public')->allFiles($dir))
            ->filter(static fn (string $path) => preg_match('/\.(jpe?g|png|webp)$/i', $path) === 1)
            ->unique()
            ->values();

        $total = $files->count();
        if ($total === 0) {
            return response()->json([
                'success' => true,
                'done' => true,
                'message' => 'Tidak ada file gambar yang bisa diproses.',
                'total' => 0,
                'processed' => 0,
                'compressed' => 0,
                'saved_bytes' => 0,
                'saved_mb' => 0.0,
            ]);
        }

        $slice = $files->slice($offset, $batch)->values();
        $processed = 0;
        $compressed = 0;
        $savedBytes = 0;

        foreach ($slice as $path) {
            $result = UploadedImageCompressor::compressStoredFile($path, 'public');
            if (! $result['processed']) {
                continue;
            }

            $processed++;
            if ($result['compressed']) {
                $compressed++;
                $savedBytes += max(0, $result['bytes_before'] - $result['bytes_after']);
            }
        }

        $nextOffset = $offset + $slice->count();
        $done = $nextOffset >= $total;

        if ($done) {
            ActivityLog::record('Stored images compressed', null, null, [
                'total_images' => $total,
                'compressed_images' => $compressed,
                'saved_bytes' => $savedBytes,
            ]);
        }

        return response()->json([
            'success' => true,
            'done' => $done,
            'total' => $total,
            'processed_batch' => $processed,
            'compressed_batch' => $compressed,
            'saved_bytes_batch' => $savedBytes,
            'saved_mb_batch' => round($savedBytes / 1048576, 3),
            'offset' => $offset,
            'next_offset' => $done ? null : $nextOffset,
            'next_url' => $done
                ? null
                : route('system.images.compress', ['offset' => $nextOffset, 'batch' => $batch]),
            'message' => $done
                ? 'Kompresi selesai.'
                : 'Batch kompres selesai, lanjutkan ke next_url.',
        ]);
    }
}
