<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Locales;
use Illuminate\Console\Command;
final class TranslationsHealthCommand extends Command
{
    protected $signature = 'translations:health {--locale=id : Locale to probe}';

    protected $description = 'Verify lang/ files exist and translations resolve (run after deploy)';

    public function handle(): int
    {
        $langPath = base_path('lang');
        $locale = (string) $this->option('locale');
        $available = Locales::available();

        $this->line('Lang path: '.$langPath);
        $this->line('App langPath(): '.lang_path());
        $this->line('Locale: '.$locale.' (available: '.implode(', ', $available).')');

        if (! is_dir($langPath)) {
            $this->error('Missing directory: lang/ — translations will show raw keys on the site.');
            $this->line('Deploy must include the lang/ folder (git add lang && git push).');

            return self::FAILURE;
        }

        if (lang_path() !== $langPath) {
            $this->error('lang_path() does not point to project lang/. Check AppServiceProvider and bootstrap/app.php.');
        }

        $requiredGroups = ['nav', 'dashboard', 'common', 'auth'];
        $missingFiles = [];

        foreach ($available as $code) {
            foreach ($requiredGroups as $group) {
                $file = $langPath.DIRECTORY_SEPARATOR.$code.DIRECTORY_SEPARATOR.$group.'.php';
                if (! is_file($file)) {
                    $missingFiles[] = str_replace($langPath.DIRECTORY_SEPARATOR, '', $file);
                }
            }
        }

        if ($missingFiles !== []) {
            $this->error('Missing translation files:');
            foreach ($missingFiles as $file) {
                $this->line('  - '.$file);
            }

            return self::FAILURE;
        }

        $resourcesLang = resource_path('lang');
        if (is_dir($resourcesLang)) {
            $this->warn('resources/lang exists — remove on server if you use project-root lang/ only.');
            $this->line('  rm -rf resources/lang');
        }

        app()->setLocale($locale);
        $probeKey = 'nav.dashboard';
        $resolved = __($probeKey);

        if ($resolved === $probeKey) {
            $this->error("Translation failed: __({$probeKey}) returned the key (files not loaded).");

            return self::FAILURE;
        }

        $this->info("OK: __({$probeKey}) => {$resolved}");

        foreach (['en', 'zh'] as $alt) {
            if (! in_array($alt, $available, true)) {
                continue;
            }
            app()->setLocale($alt);
            $altResolved = __($probeKey);
            if ($altResolved === $probeKey) {
                $this->error("Translation failed for locale [{$alt}].");

                return self::FAILURE;
            }
            $this->line("  [{$alt}] {$probeKey} => {$altResolved}");
        }

        $this->newLine();
        $this->info('Translations healthy.');

        return self::SUCCESS;
    }
}
