<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\PublicStorageUrl;
use PHPUnit\Framework\TestCase;

final class PublicStorageUrlTest extends TestCase
{
    public function test_normalize_strips_storage_prefix(): void
    {
        $this->assertSame('barang-products/a.jpg', PublicStorageUrl::normalizePath('storage/barang-products/a.jpg'));
    }

}
