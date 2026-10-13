<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\SalesIncentiveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SalesIncentiveController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        abort_unless($this->canView(), 403);

        $period = (string) $request->input('period', now()->format('Y-m'));

        return response()->json(
            SalesIncentiveService::periodSummary($period, auth()->user()),
        );
    }

    public function data(Request $request): JsonResponse
    {
        abort_unless($this->canView(), 403);

        $period = (string) $request->input('period', now()->format('Y-m'));

        return response()->json(
            SalesIncentiveService::monthlyMatrix($period, auth()->user())->values(),
        );
    }

    public function detail(int $salesId, string $period): JsonResponse
    {
        abort_unless($this->canView(), 403);
        $this->assertSalesAccess($salesId);

        return response()->json(
            SalesIncentiveService::periodDetail($salesId, $period),
        );
    }

    private function canView(): bool
    {
        return auth()->user()?->can('view sales incentive') ?? false;
    }

    private function assertSalesAccess(int $salesId): void
    {
        $user = auth()->user();
        if ($user === null) {
            abort(403);
        }

        if ($user->hasRole('sales') && ! $user->hasAnyRole(['super_admin', 'supervisor', 'finance', 'admin_cs'])) {
            abort_unless((int) $user->id === $salesId, 403);
        }
    }
}
