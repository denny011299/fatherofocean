<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

final class NamaBarangOptionsSplitTest extends TestCase
{
    public function test_splits_marking_item_name_by_newlines(): void
    {
        $raw = "ATK dan teman temannya\nasasa\r\nasasa\n\nasa\n";
        $parts = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $options = array_values(array_unique(array_filter(
            array_map(static fn (string $part): string => trim($part), $parts),
            static fn (string $part): bool => $part !== '',
        )));

        $this->assertSame([
            'ATK dan teman temannya',
            'asasa',
            'asa',
        ], $options);
    }
}
