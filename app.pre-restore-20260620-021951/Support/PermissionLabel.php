<?php

declare(strict_types=1);

namespace App\Support;

final class PermissionLabel
{
    public static function for(string $permissionName): string
    {
        $key = 'permissions.'.$permissionName;
        $label = __($key);

        if ($label !== $key) {
            return $label;
        }

        return ucwords(str_replace('_', ' ', $permissionName));
    }
}
