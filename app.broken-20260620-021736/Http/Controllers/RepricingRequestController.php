<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Barang;
use App\Models\RepricingRequest;
use App\Support\BarangShippingPricing;
use App\Support\UploadedImageCompressor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;


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
            'reason' => 'required|string|max:1000',
            'proof_image' => 'required|file|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        $newExtra = (float) ($data['extra_charge'] ?? 0);
        $oldPrice = (float) $barang->price_per_cbm;
        $oldExtra = (float) $barang->extra_charge;
        $oldWeightPrice = (float) $barang->weight_price;
        $newPrice = (float) $data['price_per_cbm'];
        $newWeightPrice = (float) $data['weight_price'];

        $preview = BarangShippingPricing::preview(
            (float) $barang->total_cbm,
            (float) $barang->total_gross_weight,
            (float) $barang->weight_threshold_kg,
            $newPrice,
            $newWeightPrice,
            BarangShippingPricing::resolveMinimumChargeCbm($barang),
        );

        $mode = match ($preview['method']) {
            BarangShippingPricing::METHOD_WEIGHT => RepricingRequest::MODE_WEIGHT,
            BarangShippingPricing::METHOD_SPLIT => RepricingRequest::MODE_SPLIT,
            default => RepricingRequest::MODE_CBM,
        };

        if ($oldPrice === $newPrice && $oldWeightPrice === $newWeightPrice && $oldExtra === $newExtra) {
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
            $newPrice,
            $newExtra,
            $newWeightPrice,
            $data,
            $proofPath,
        ) {
            $repricing = RepricingRequest::query()->create([
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
            ]);

            $oldValues = [
                'price_per_cbm' => $oldPrice,
                'extra_charge' => $oldExtra,
                'weight_price' => $oldWeightPrice,
            ];

            $newValues = [
                'pricing_mode' => $mode,
                'price_per_cbm' => $newPrice,
                'extra_charge' => $newExtra,
                'weight_price' => $newWeightPrice,
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
            $old = $barang->only(['price_per_cbm', 'extra_charge', 'weight_price']);

            $barang->update([
                'price_per_cbm' => $repricingRequest->new_price_per_cbm,
                'weight_price' => $repricingRequest->new_weight_price ?? $barang->weight_price,
                'extra_charge' => $repricingRequest->new_extra_charge,
            ]);

            $repricingRequest->update([
                'status' => RepricingRequest::STATUS_APPROVED,
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
            ]);

            ActivityLog::record(
                'Repricing approved',
                $barang,
                $old,
                $barang->only(['price_per_cbm', 'extra_charge', 'weight_price']) + [
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

}
