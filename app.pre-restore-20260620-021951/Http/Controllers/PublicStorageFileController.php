<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\PublicStorageUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class PublicStorageFileController extends Controller
{
    public function show(Request $request, ?string $path = null): BinaryFileResponse|Response
    {
        abort_unless(auth()->check(), 403);

        $path ??= $request->query('path', '');
        $normalized = PublicStorageUrl::normalizePath(is_string($path) ? $path : null);
        if ($normalized === null) {
            abort(404);
        }

        $absolute = PublicStorageUrl::absolutePath($normalized);
        if ($absolute === null) {
            abort(404);
        }

        return response()->file($absolute);
    }
}
