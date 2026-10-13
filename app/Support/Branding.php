<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\SystemSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

final class Branding
{
    public const LOGO_SETTING_KEY = 'app_logo_path';

    public static function logoPath(): ?string
    {
        $path = SystemSetting::getValue(self::LOGO_SETTING_KEY);

        if ($path === null || $path === '') {
            return null;
        }

        if (! Storage::disk('public')->exists($path)) {
            return null;
        }

        return $path;
    }

    public static function logoUrl(): ?string
    {
        if (self::logoPath() === null) {
            return null;
        }

        return route('branding.logo');
    }

    public static function hasLogo(): bool
    {
        return self::logoPath() !== null;
    }

    public static function storeLogo(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'png');
        $path = 'branding/logo.' . $extension;

        if ($existingPath = SystemSetting::getValue(self::LOGO_SETTING_KEY)) {
            Storage::disk('public')->delete($existingPath);
        }

        Storage::disk('public')->makeDirectory('branding');
        Storage::disk('public')->putFileAs('branding', $file, 'logo.' . $extension);

        SystemSetting::setValue(self::LOGO_SETTING_KEY, $path);

        return $path;
    }

    public static function removeLogo(): void
    {
        if ($path = SystemSetting::getValue(self::LOGO_SETTING_KEY)) {
            Storage::disk('public')->delete($path);
        }

        SystemSetting::setValue(self::LOGO_SETTING_KEY, null);
    }

    /** Absolute filesystem path for PDF embeds and direct file responses. */
    public static function absoluteLogoPath(): ?string
    {
        $path = self::logoPath();
        if ($path === null) {
            return null;
        }

        $absolute = storage_path('app/public/'.ltrim(str_replace('\\', '/', $path), '/'));

        return is_file($absolute) ? $absolute : null;
    }
}
