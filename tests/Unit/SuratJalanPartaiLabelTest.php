<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\SuratJalanPdfData;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SuratJalanPartaiLabelTest extends TestCase
{
    #[DataProvider('labelProvider')]
    public function test_format_partai_container_label(string $partai, string $containerNo, string $expected): void
    {
        $this->assertSame(
            $expected,
            SuratJalanPdfData::formatPartaiContainerLabel($partai, $containerNo),
        );
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function labelProvider(): array
    {
        return [
            'partai and container' => ['601', '8', 'partai 601 - K.8'],
            'container already has K.' => ['601', 'K.8', 'partai 601 - K.8'],
            'container already has K' => ['601', 'K8', 'partai 601 - K.8'],
            'partai only' => ['601', '', 'partai 601'],
            'container only' => ['', '8', 'K.8'],
            'both empty' => ['', '', ''],
        ];
    }
}
