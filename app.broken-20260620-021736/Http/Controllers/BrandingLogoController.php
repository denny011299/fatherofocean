<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Branding;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class BrandingLogoController extends Controller
{
    public function show(): BinaryFileResponse
    {
        $absolute = Branding::absoluteLogoPath();

        if ($absolute === null) {
            abort(404);
        }

        $mime = mime_content_type($absolute);

        return response()->file($absolute, [
            'Content-Type' => is_string($mime) && $mime !== '' ? $mime : 'image/png',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
