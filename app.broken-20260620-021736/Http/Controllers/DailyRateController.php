<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Support\DailyRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DailyRateController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(DailyRate::current());
    }

    public function update(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->can('edit rmb rate'), 403);

        $validated = $request->validate([
            'rate' => ['required', 'numeric', 'min:0'],
            'effective_date' => ['nullable', 'date'],
        ]);

        $old = DailyRate::current();

        $current = DailyRate::update(
            (float) $validated['rate'],
            $validated['effective_date'] ?? null,
        );

        ActivityLog::record('Daily rate updated', null, $old, $current);

        return response()->json([
            'success' => true,
            'message' => __('settings.daily_rate_saved'),
            'data' => $current,
        ]);
    }
}
