<?php

declare(strict_types=1);

use App\Support\Locales;

return [

    /*
    |--------------------------------------------------------------------------
    | Available Application Locales
    |--------------------------------------------------------------------------
    |
    | Keys are locale codes used in session/cookie and App::setLocale().
    | Values are display labels for the language switcher (native names).
    |
    | Canonical list lives in App\Support\Locales (used by UI + routes).
    |
    */

    'available' => Locales::AVAILABLE,

    'names' => Locales::NAMES,

    'html_lang' => Locales::HTML_LANG,

];
