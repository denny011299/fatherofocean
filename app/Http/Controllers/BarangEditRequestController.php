<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\BarangEditRequest;
use App\Models\Marking;
use App\Support\BarangEditRequestService;
use App\Support\BarangUpdateExecutor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BarangEditRequestController extends Controller
{
    public function __construct(
        private readonly BarangEditRequestService $editRequestService,
        private readonly BarangUpdateExecutor $updateExecutor,
    ) {}

    public function data(Request $request): JsonResponse
    {
        abort_unless($this->canViewEditPengajuan(), 403);

        $status = $request->string('status')->toString();

        $query = BarangEditRequest::query()
            ->with([
                'barang:id,no_index,marking,total_cbm,total_gross_weight,status',
                'submitter:id,name',
                'reviewer:id,name',
            ])
            ->orderForReviewQueue();

        if ($status !== '' && in_array($status, [
            BarangEditRequest::STATUS_PENDING,
            BarangEditRequest::STATUS_APPROVED,
            BarangEditRequest::STATUS_REJECTED,
        ], true)) {
            $query->where('status', $status);
        }

        $user = auth()->user();

        if (! $user?->can('approve barang')) {
            $query->where('submitted_by', $user?->id);
        }

        if (Marking::userHasSalesMarkingScope($user)) {
            $query->whereHas(
                'barang',
                static fn (Builder $barangQuery) => Marking::restrictBarangsQuery($barangQuery, $user),
            );
        }

        $rows = $query->limit(500)->get()->map(function (BarangEditRequest $row): array {
            $barang = $row->barang;
            $diffCount = count($this->editRequestService->diffRows($row));

            return [
                'id' => $row->id,
                'barang_id' => $row->barang_id,
                'no_index' => $barang?->no_index,
                'marking' => $barang?->marking,
                'status' => $row->status,
                'barang_status' => $barang?->status,
                'total_cbm' => $barang?->total_cbm,
                'total_gross_weight' => $barang?->total_gross_weight,
                'diff_count' => $diffCount,
                'rejection_note' => $row->rejection_note,
                'submitted_by' => $row->submitter?->name,
                'reviewed_by' => $row->reviewer?->name,
                'submitted_at' => $row->submitted_at?->toIso8601String(),
                'reviewed_at' => $row->reviewed_at?->toIso8601String(),
            ];
        });

        return response()->json($rows);
    }

    public function show(BarangEditRequest $barangEditRequest): JsonResponse
    {
        abort_unless($this->canViewEditPengajuan(), 403);
        $this->assertRequestVisible($barangEditRequest);

        $barangEditRequest->load(['barang', 'submitter:id,name', 'reviewer:id,name']);

        return response()->json([
            'id' => $barangEditRequest->id,
            'barang_id' => $barangEditRequest->barang_id,
            'no_index' => $barangEditRequest->barang?->no_index,
            'marking' => $barangEditRequest->barang?->marking,
            'status' => $barangEditRequest->status,
            'submitted_by' => $barangEditRequest->submitter?->name,
            'submitted_at' => $barangEditRequest->submitted_at?->toIso8601String(),
            'reviewed_by' => $barangEditRequest->reviewer?->name,
            'reviewed_at' => $barangEditRequest->reviewed_at?->toIso8601String(),
            'rejection_note' => $barangEditRequest->rejection_note,
            'diff' => $this->editRequestService->diffRows($barangEditRequest),
            'products' => $barangEditRequest->proposed_payload['products'] ?? [],
            'current_products' => $barangEditRequest->current_snapshot['products'] ?? [],
        ]);
    }

    public function approve(BarangEditRequest $barangEditRequest): JsonResponse
    {
        abort_unless(auth()->user()?->can('approve barang'), 403);
        $this->assertRequestVisible($barangEditRequest);

        try {
            $this->editRequestService->apply(
                $barangEditRequest,
                fn ($barang, $request) => $this->updateExecutor->execute($barang, $request),
            );
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('barang.edit_request_approved'),
        ]);
    }

    public function reject(Request $request, BarangEditRequest $barangEditRequest): JsonResponse
    {
        abort_unless(auth()->user()?->can('reject barang'), 403);
        $this->assertRequestVisible($barangEditRequest);

        $data = $request->validate([
            'rejection_note' => 'required|string|max:500',
        ]);

        try {
            $this->editRequestService->reject($barangEditRequest, trim($data['rejection_note']));
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('barang.edit_request_rejected'),
        ]);
    }

    public function badgeCount(): JsonResponse
    {
        if (! auth()->check()) {
            return response()->json(['pending' => 0]);
        }

        $user = auth()->user();
        $pending = 0;

        if ($user?->can('approve barang')) {
            $pending = BarangEditRequest::query()
                ->where('status', BarangEditRequest::STATUS_PENDING)
                ->count();
        } elseif ($user?->can('edit barang')) {
            $pending = BarangEditRequest::query()
                ->where('status', BarangEditRequest::STATUS_PENDING)
                ->where('submitted_by', $user->id)
                ->count();
        }

        return response()->json(['pending' => $pending]);
    }

    private function canViewEditPengajuan(): bool
    {
        $user = auth()->user();

        return $user !== null && (
            $user->can('approve barang')
            || $user->can('edit barang')
        );
    }

    private function assertRequestVisible(BarangEditRequest $barangEditRequest): void
    {
        $user = auth()->user();
        abort_unless($user !== null, 403);

        if (
            ! $user->can('approve barang')
            && (int) $barangEditRequest->submitted_by !== (int) $user->id
        ) {
            abort(403);
        }

        $barang = $barangEditRequest->barang;
        if ($barang !== null) {
            abort_unless(Marking::barangVisibleToUser($barang), 403);
        }
    }
}
