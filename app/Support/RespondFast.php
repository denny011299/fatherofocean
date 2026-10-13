<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Send JSON to the client immediately, then run follow-up work (invoice, logs, etc.).
 */
final class RespondFast
{
    /** @param array<string, mixed> $payload */
    public static function json(array $payload, int $status = 200, ?callable $after = null): JsonResponse
    {
        $response = response()->json($payload, $status);

        if ($after === null) {
            return $response;
        }

        $response->send();

        if (app()->bound('session.store')) {
            session()->save();
        }

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        try {
            $after();
        } catch (Throwable $e) {
            report($e);
        }

        exit;
    }
}
