<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Marking;
use App\Models\RepricingRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PengajuanController extends Controller
{
    public function index(): \Illuminate\Http\RedirectResponse
    {
        abort_unless($this->canViewPengajuan(), 403);

        return redirect()->route('finance.pricing', ['tab' => 'pengajuan']);
    }

    public function data(Request $request): JsonResponse
    {
        abort_unless($this->canViewPengajuan(), 403);

        $status = $request->string('status')->toString();

        $query = RepricingRequest::query()
            ->with(['barang:id,no_index,marking,total_cbm,total_gross_weight,weight_threshold_kg,price_per_cbm,weight_price,extra_charge', 'submitter:id,name', 'reviewer:id,name'])
            ->latest('submitted_at');

        if ($status !== '' && in_array($status, [
            RepricingRequest::STATUS_PENDING,
            RepricingRequest::STATUS_APPROVED,
            RepricingRequest::STATUS_REJECTED,
        ], true)) {
            $query->where('status', $status);
        }

        $user = auth()->user();

        if (
            ! $user?->can('approve repricing')
            && ($user?->can('submit repricing') || $user?->can('change price'))
        ) {
            $query->where('submitted_by', $user->id);
        }

        if (Marking::userHasTeamMarkingScope($user)) {
            $query->whereHas(
                'barang',
                static fn (Builder $barangQuery) => Marking::restrictBarangsQuery($barangQuery, $user),
            );
        }

        $rows = $query->limit(500)->get()->map(static function (RepricingRequest $row): array {
            $barang = $row->barang;

            return [
                'id' => $row->id,
                'barang_id' => $row->barang_id,
                'no_index' => $barang?->no_index,
                'marking' => $barang?->marking,
                'total_cbm' => $barang?->total_cbm,
                'total_gross_weight' => $barang?->total_gross_weight,
                'weight_threshold_kg' => $barang?->weight_threshold_kg,
                'status' => $row->status,
                'pricing_mode' => $row->pricing_mode,
                'old_price_per_cbm' => $row->old_price_per_cbm,
                'new_price_per_cbm' => $row->new_price_per_cbm,
                'old_weight_price' => $row->old_weight_price,
                'new_weight_price' => $row->new_weight_price,
                'old_extra_charge' => $row->old_extra_charge,
                'new_extra_charge' => $row->new_extra_charge,
                'reason' => $row->reason,
                'rejection_note' => $row->rejection_note,
                'proof_url' => \App\Support\PublicStorageUrl::url($row->proof_path),
                'submitted_by' => $row->submitter?->name,
                'reviewed_by' => $row->reviewer?->name,
                'submitted_at' => $row->submitted_at?->toIso8601String(),
                'reviewed_at' => $row->reviewed_at?->toIso8601String(),
            ];
        });

        return response()->json($rows);
    }

    public function badgeCount(): JsonResponse
    {
        if (! auth()->check()) {
            return response()->json(['pending' => 0]);
        }

        $pending = 0;
        $user = auth()->user();
        if ($user?->can('approve repricing')) {
            $badgeQuery = RepricingRequest::query()
                ->where('status', RepricingRequest::STATUS_PENDING);
            if (Marking::userHasTeamMarkingScope($user)) {
                $badgeQuery->whereHas(
                    'barang',
                    static fn (Builder $barangQuery) => Marking::restrictBarangsQuery($barangQuery, $user),
                );
            }
            $pending = $badgeQuery->count();
        } elseif ($user?->can('submit repricing') || $user?->can('change price')) {
            $pending = RepricingRequest::query()
                ->where('status', RepricingRequest::STATUS_PENDING)
                ->where('submitted_by', $user->id)
                ->count();
        }

        return response()->json(['pending' => $pending]);
    }

    private function canViewPengajuan(): bool
    {
        $user = auth()->user();

        return $user !== null && (
            $user->can('view pengajuan')
            || $user->can('view pricing')
            || $user->can('submit repricing')
            || $user->can('approve repricing')
            || $user->can('change price')
        );
    }
}
