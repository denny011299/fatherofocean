<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Marking;
use App\Models\User;
use App\Support\ViteAssets;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->useLangPath(base_path('lang'));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ViteAssets::removeStaleHotFile();

        $this->app->useLangPath(base_path('lang'));

        Gate::before(static function (?User $user, string $ability): ?bool {
            return $user?->hasAnyRole(['super_admin', 'supervisor']) === true ? true : null;
        });

        Route::bind('marking', fn (string $value) => Marking::query()
            ->visibleToUser()
            ->findOrFail($value));

        if ($this->app->environment('local')) {
            Lang::handleMissingKeysUsing(function (string $key, array $replace, ?string $locale): ?string {
                Log::debug('Missing translation', [
                    'key' => $key,
                    'locale' => $locale ?? app()->getLocale(),
                ]);

                return null;
            });
        }
    }
}
