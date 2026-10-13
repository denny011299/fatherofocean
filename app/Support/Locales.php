<?php

declare(strict_types=1);

namespace App\Support;

final class Locales
{
    /** @var list<string> */
    public const AVAILABLE = ['id', 'en', 'zh'];

    /** @var array<string, string> */
    public const NAMES = [
        'id' => 'Bahasa Indonesia',
        'en' => 'English',
        'zh' => '中文',
    ];

    /** @var array<string, string> */
    public const HTML_LANG = [
        'id' => 'id',
        'en' => 'en',
        'zh' => 'zh-CN',
    ];

    /**
     * @return list<string>
     */
    public static function available(): array
    {
        return self::AVAILABLE;
    }

    /**
     * @return array<string, string>
     */
    public static function names(): array
    {
        return self::NAMES;
    }

    public static function name(string $locale): string
    {
        return self::NAMES[$locale] ?? $locale;
    }

    public static function isSupported(string $locale): bool
    {
        return in_array($locale, self::AVAILABLE, true);
    }

    public static function routePattern(): string
    {
        return implode('|', self::AVAILABLE);
    }
}
