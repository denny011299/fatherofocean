<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Barang;
use App\Models\Marking;
use App\Models\RepricingRequest;
use App\Support\BarangShippingPricing;
use App\Support\UploadedImageCompressor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class RepricingRequestController extends Controller
{
    public function store(Request $request, Barang $barang): JsonResponse
    {
        abort_unless(
            auth()->user()?->can('submit repricing') || auth()->user()?->can('change price'),
            403,
        );

        if ($barang->pendingRepricingRequest()->exists()) {
            return response()->json([
                'success' => false,
                'message' => __('finance.repricing_pending_exists'),
            ], 422);
        }

        $data = $request->validate([
            'price_per_cbm' => 'required|numeric|min:0',
            'weight_price' => 'required|numeric|min:0',
            'extra_charge' => 'nullable|numeric|min:0',
            'minimum_charge_cbm' => 'nullable|numeric|min:0',
            'weight_threshold_kg' => 'nullable|numeric|min:0',
            'reason' => 'required|string|max:1000',
            'proof_image' => 'required|file|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        $newExtra = (float) ($data['extra_charge'] ?? 0);
        $oldPrice = (float) $barang->price_per_cbm;
        $oldExtra = (float) $barang->extra_charge;
        $oldWeightPrice = (float) $barang->weight_price;
        $oldMinCharge = BarangShippingPricing::resolveMinimumChargeCbm($barang);
        $oldThreshold = BarangShippingPricing::normalizeThresholdPerCbmKg(
            (float) $barang->weight_threshold_kg,
        );
        $newPrice = (float) $data['price_per_cbm'];
        $newWeightPrice = (float) $data['weight_price'];
        $newMinCharge = array_key_exists('minimum_charge_cbm', $data) && $data['minimum_charge_cbm'] !== null
            ? max(0, (float) $data['minimum_charge_cbm'])
            : $oldMinCharge;
        $newThreshold = array_key_exists('weight_threshold_kg', $data) && $data['weight_threshold_kg'] !== null
            ? BarangShippingPricing::normalizeThresholdPerCbmKg((float) $data['weight_threshold_kg'])
            : $oldThreshold;

        $preview = BarangShippingPricing::preview(
            (float) $barang->total_cbm,
            (float) $barang->total_gross_weight,
            $newThreshold,
            $newPrice,
            $newWeightPrice,
            $newMinCharge,
        );

        $mode = match ($preview['method']) {
            BarangShippingPricing::METHOD_WEIGHT => RepricingRequest::MODE_WEIGHT,
            BarangShippingPricing::METHOD_SPLIT => RepricingRequest::MODE_SPLIT,
            default => RepricingRequest::MODE_CBM,
        };

        if (
            abs($oldThreshold - $newThreshold) >= 0.00001
            && ! Marking::hasColumnCached('repricing_requests', 'old_weight_threshold_kg')
        ) {
            return response()->json([
                'success' => false,
                'message' => __('finance.repricing_threshold_setup_required'),
            ], 422);
        }

        if (
            $oldPrice === $newPrice
            && $oldWeightPrice === $newWeightPrice
            && $oldExtra === $newExtra
            && abs($oldMinCharge - $newMinCharge) < 0.00001
            && abs($oldThreshold - $newThreshold) < 0.00001
        ) {
            return response()->json([
                'success' => false,
                'message' => __('finance.repricing_no_change'),
            ], 422);
        }

        $proofPath = UploadedImageCompressor::store(
            $request->file('proof_image'),
            'repricing-proofs',
            'public',
        );

        $repricing = DB::transaction(function () use (
            $barang,
            $mode,
            $oldPrice,
            $oldExtra,
            $oldWeightPrice,
            $oldMinCharge,
            $oldThreshold,
            $newPrice,
            $newExtra,
            $newWeightPrice,
            $newMinCharge,
            $newThreshold,
            $data,
            $proofPath,
        ) {
            $payload = [
                'barang_id' => $barang->id,
                'submitted_by' => auth()->id(),
                'status' => RepricingRequest::STATUS_PENDING,
                'pricing_mode' => $mode,
                'old_price_per_cbm' => $oldPrice,
                'old_extra_charge' => $oldExtra,
                'old_weight_price' => $oldWeightPrice,
                'new_price_per_cbm' => $newPrice,
                'new_extra_charge' => $newExtra,
                'new_weight_price' => $newWeightPrice,
                'reason' => trim($data['reason']),
                'proof_path' => $proofPath,
                'submitted_at' => now(),
            ];

            if (Marking::hasColumnCached('repricing_requests', 'old_minimum_charge_cbm')) {
                $payload['old_minimum_charge_cbm'] = $oldMinCharge;
                $payload['new_minimum_charge_cbm'] = $newMinCharge;
            }

            if (Marking::hasColumnCached('repricing_requests', 'old_weight_threshold_kg')) {
                $payload['old_weight_threshold_kg'] = $oldThreshold;
                $payload['new_weight_threshold_kg'] = $newThreshold;
            }

            $repricing = RepricingRequest::query()->create($payload);

            $oldValues = [
                'price_per_cbm' => $oldPrice,
                'extra_charge' => $oldExtra,
                'weight_price' => $oldWeightPrice,
                'minimum_charge_cbm' => $oldMinCharge,
                'weight_threshold_kg' => $oldThreshold,
            ];

            $newValues = [
                'pricing_mode' => $mode,
                'price_per_cbm' => $newPrice,
                'extra_charge' => $newExtra,
                'weight_price' => $newWeightPrice,
                'minimum_charge_cbm' => $newMinCharge,
                'weight_threshold_kg' => $newThreshold,
                'repricing_request_id' => $repricing->id,
                'reason' => $repricing->reason,
            ];

            ActivityLog::record(
                'Repricing submitted',
                $barang,
                $oldValues,
                $newValues,
            );

            return $repricing;
        });

        \App\Support\ShipmentNotificationService::notifyRepricingSubmitted($repricing, auth()->id());

        return response()->json([
            'success' => true,
            'message' => __('finance.repricing_submitted'),
            'data' => ['repricing_request_id' => $repricing->id],
        ]);
    }

    public function approve(RepricingRequest $repricingRequest): JsonResponse
    {
        abort_unless(auth()->user()?->can('approve repricing'), 403);

        if (! $repricingRequest->isPending()) {
            return response()->json([
                'success' => false,
                'message' => __('finance.repricing_already_processed'),
            ], 422);
        }

        if ($repricingRequest->submitted_by === auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => __('finance.repricing_cannot_approve_own'),
            ], 422);
        }

        DB::transaction(function () use ($repricingRequest) {
            $barang = $repricingRequest->barang;
            $old = $barang->only([
                'price_per_cbm',
                'extra_charge',
                'weight_price',
                'minimum_charge_cbm',
                'weight_threshold_kg',
            ]);

            $updates = [
                'price_per_cbm' => $repricingRequest->new_price_per_cbm,
                'weight_price' => $repricingRequest->new_weight_price ?? $barang->weight_price,
                'extra_charge' => $repricingRequest->new_extra_charge,
            ];

            if (
                Marking::hasColumnCached('barangs', 'minimum_charge_cbm')
                && $repricingRequest->new_minimum_charge_cbm !== null
            ) {
                $updates['minimum_charge_cbm'] = $repricingRequest->new_minimum_charge_cbm;
            }

            if (
                Marking::hasColumnCached('barangs', 'weight_threshold_kg')
                && $repricingRequest->new_weight_threshold_kg !== null
            ) {
                $updates['weight_threshold_kg'] = $repricingRequest->new_weight_threshold_kg;
            }

            $barang->update($updates);

            $repricingRequest->update([
                'status' => RepricingRequest::STATUS_APPROVED,
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
            ]);

            ActivityLog::record(
                'Repricing approved',
                $barang,
                $old,
                $barang->only([
                    'price_per_cbm',
                    'extra_charge',
                    'weight_price',
                    'minimum_charge_cbm',
                    'weight_threshold_kg',
                ]) + [
                    'repricing_request_id' => $repricingRequest->id,
                ],
            );
        });

        return response()->json([
            'success' => true,
            'message' => __('finance.repricing_approved'),
        ]);
    }

    public function reject(Request $request, RepricingRequest $repricingRequest): JsonResponse
    {
        abort_unless(auth()->user()?->can('approve repricing'), 403);

        if (! $repricingRequest->isPending()) {
            return response()->json([
                'success' => false,
                'message' => __('finance.repricing_already_processed'),
            ], 422);
        }

        $data = $request->validate([
            'rejection_note' => 'required|string|max:500',
        ]);

        DB::transaction(function () use ($repricingRequest, $data) {
            $repricingRequest->update([
                'status' => RepricingRequest::STATUS_REJECTED,
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
                'rejection_note' => $data['rejection_note'],
            ]);

            ActivityLog::record(
                'Repricing rejected',
                $repricingRequest->barang,
                $repricingRequest->oldPricingLogContext(),
                $repricingRequest->pricingLogContext() + [
                    'rejection_note' => $data['rejection_note'],
                ],
            );
        });

        return response()->json([
            'success' => true,
            'message' => __('finance.repricing_rejected'),
        ]);
    }

    /**
     * Prefer opening this URL on production instead of running artisan migrate.
     */
    public function setupWeightThresholdColumns(Request $request): JsonResponse|RedirectResponse
    {
        abort_unless(
            auth()->user()?->can('approve repricing')
            || auth()->user()?->can('submit repricing')
            || auth()->user()?->can('change price')
            || auth()->user()?->hasRole('super_admin'),
            403,
        );

        $table = 'repricing_requests';
        $addedColumns = [];

        if (! Schema::hasTable($table)) {
            $message = "Tabel {$table} belum ada.";

            if ($request->expectsJson() || $request->boolean('json')) {
                return response()->json([
                    'success' => false,
                    'message' => $message,
                ], 422);
            }

            return redirect()
                ->to($request->query('redirect', route('finance.pricing')))
                ->with('error', $message);
        }

        if (! Schema::hasColumn($table, 'old_weight_threshold_kg')) {
            Schema::table($table, static function (Blueprint $blueprint): void {
                $blueprint->decimal('old_weight_threshold_kg', 15, 2)->nullable()->after('old_minimum_charge_cbm');
            });
            $addedColumns[] = 'old_weight_threshold_kg';
        }

        if (! Schema::hasColumn($table, 'new_weight_threshold_kg')) {
            Schema::table($table, static function (Blueprint $blueprint): void {
                $blueprint->decimal('new_weight_threshold_kg', 15, 2)->nullable()->after('new_minimum_charge_cbm');
            });
            $addedColumns[] = 'new_weight_threshold_kg';
        }

        Marking::forgetSchemaColumnCache();

        $parts = $addedColumns !== []
            ? 'kolom ditambahkan: '.implode(', ', $addedColumns)
            : "tabel {$table} sudah siap (tidak ada perubahan)";

        $message = 'Setup repricing weight threshold: '.$parts.'.';

        if ($request->expectsJson() || $request->boolean('json')) {
            return response()->json([
                'success' => true,
                'added_columns' => $addedColumns,
                'table' => $table,
                'message' => $message,
            ]);
        }

        return redirect()
            ->to($request->query('redirect', route('finance.pricing')))
            ->with('success', $message);
    }
}
