<?php

declare(strict_types=1);

if (! function_exists('translation_group')) {
    /**
     * Load all lines from a PHP translation group file (e.g. lang/{locale}/common.php).
     *
     * @return array<string, string>
     */
    function translation_group(string $group, ?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $lines = app('translator')->getLoader()->load($locale, $group, '*');

        return is_array($lines) ? $lines : [];
    }
}

if (! function_exists('translation_groups')) {
    /**
     * Merge multiple PHP translation group files into one flat array for JS.
     *
     * @param  list<string>  $groups
     * @return array<string, string>
     */
    function translation_groups(array $groups, ?string $locale = null): array
    {
        $merged = [];

        foreach ($groups as $group) {
            $merged = array_merge($merged, translation_group($group, $locale));
        }

        return $merged;
    }
}
