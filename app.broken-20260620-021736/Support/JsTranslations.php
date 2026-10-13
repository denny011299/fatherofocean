<?php

declare(strict_types=1);

namespace App\Support;

final class JsTranslations
{
    /**
     * Load one PHP translation group (e.g. lang/{locale}/common.php).
     *
     * @return array<string, string>
     */
    public static function group(string $group, ?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $lines = app('translator')->getLoader()->load($locale, $group, '*');

        return is_array($lines) ? $lines : [];
    }

    /**
     * Merge multiple translation groups into one flat array for window.__t.
     *
     * @param  list<string>  $groups
     * @return array<string, string>
     */
    public static function merge(array $groups, ?string $locale = null): array
    {
        $merged = [];

        foreach ($groups as $group) {
            $merged = array_merge($merged, self::group($group, $locale));
        }

        return $merged;
    }
}
