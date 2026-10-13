private function publicStorageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $normalized = str_replace('\\', '/', ltrim($path, '/'));
        if ($normalized === '' || ! Storage::disk('public')->exists($normalized)) {
            return null;
        }

        return asset('storage/'.$normalized);
    }