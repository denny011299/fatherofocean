<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\File;
use InvalidArgumentException;

final class BarangOriginalPackingList
{
    private const STORAGE_DIR = 'invoices/original-pl';

    /** @var list<string> */
    public const ALLOWED_EXTENSIONS = [
        'xls',
        'xlsx',
        'pdf',
        'csv',
        'doc',
        'docx',
        'png',
        'jpg',
        'jpeg',
        'webp',
    ];

    public static function filename(string $noIndex, string $extension = 'xlsx'): string
    {
        $safeIndex = preg_replace('/[^A-Za-z0-9_-]+/', '', $noIndex);
        if ($safeIndex === '') {
            $safeIndex = 'INDEX';
        }

        return 'ORIGNAL_PL_'.$safeIndex.'.'.self::normalizeExtension($extension);
    }

    public static function store(UploadedFile $file, string $noIndex): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: '');
        if (! self::isAllowedExtension($extension)) {
            throw new InvalidArgumentException('Unsupported packing list file type.');
        }

        $filename = self::filename($noIndex, $extension);
        $directory = self::STORAGE_DIR;

        $disk = Storage::disk('public');
        $target = $directory.'/'.$filename;
        if ($disk->exists($target)) {
            $disk->delete($target);
        }

        return $file->storeAs($directory, $filename, 'public');
    }

    public static function isAllowedExtension(string $extension): bool
    {
        $ext = strtolower(ltrim($extension, '.'));

        return in_array($ext, self::ALLOWED_EXTENSIONS, true);
    }

    public static function normalizeExtension(string $extension): string
    {
        $ext = strtolower(ltrim($extension, '.'));

        return in_array($ext, self::ALLOWED_EXTENSIONS, true) ? $ext : 'pdf';
    }

    public static function isExcelPath(?string $path): bool
    {
        if ($path === null || $path === '') {
            return false;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($ext, ['xls', 'xlsx'], true);
    }

    public static function isAllowedPath(?string $path): bool
    {
        if ($path === null || $path === '') {
            return false;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return self::isAllowedExtension($ext);
    }

    /** @return string Comma-separated accept list for HTML file inputs. */
    public static function acceptAttribute(): string
    {
        $extensions = array_map(static fn (string $ext): string => '.'.$ext, self::ALLOWED_EXTENSIONS);

        return implode(',', $extensions);
    }

    /** @return string Laravel mimes validation rule fragment. */
    public static function mimesRule(): string
    {
        return implode(',', self::ALLOWED_EXTENSIONS);
    }

    /**
     * Validate uploads by client extension, not guessed MIME type.
     * Office Open XML files (.xlsx, .docx) are often detected as application/zip.
     */
    public static function uploadValidationRule(): File
    {
        return File::default()
            ->extensions(self::ALLOWED_EXTENSIONS)
            ->max(10240);
    }
}
