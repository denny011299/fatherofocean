<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exports\BarangListProductsExport;
use App\Models\MasterPricingItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BarangListProductsExportNotesKategoriTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_barang_notes_trims_whitespace(): void
    {
        $this->assertSame('Fragile, handle with care', BarangListProductsExport::resolveBarangNotes('  Fragile, handle with care  '));
        $this->assertSame('', BarangListProductsExport::resolveBarangNotes(null));
    }

    public function test_resolve_kategori_label_from_pricing_item_id(): void
    {
        $item = MasterPricingItem::query()->create([
            'name' => 'Tas Sepatu',
            'volume_price' => 4_700_000,
            'gw_price' => 9_000,
            'is_active' => true,
        ]);

        $this->assertSame('Tas Sepatu', BarangListProductsExport::resolveKategoriLabel((string) $item->id));
    }

    public function test_resolve_kategori_label_from_legacy_name_string(): void
    {
        $this->assertSame('Garment dan Textile', BarangListProductsExport::resolveKategoriLabel('Garment dan Textile'));
    }

    /**
     * @dataProvider kategoriColorProvider
     */
    public function test_resolve_kategori_fill_color(string $label, ?string $expectedColor): void
    {
        $this->assertSame($expectedColor, BarangListProductsExport::resolveKategoriFillColor($label));
    }

    /**
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function kategoriColorProvider(): array
    {
        return [
            'umum' => ['Umum', 'C6EFCE'],
            'lartas ringan' => ['Lartas Ringan', 'FFF2CC'],
            'lartas berat' => ['Lartas Berat', 'FFC7CE'],
            'lartas umum' => ['Lartas Umum', 'FFF2CC'],
            'garment textile' => ['Garment dan Textile', 'E1BEE7'],
            'tas sepatu' => ['Tas Sepatu', 'BDD7EE'],
            'unknown' => ['FCL', null],
        ];
    }

    public function test_export_filename_uses_daftar_kirim_produk_prefix(): void
    {
        $filename = BarangListProductsExport::filename();

        $this->assertStringStartsWith('daftar-kirim-produk-', $filename);
        $this->assertStringEndsWith('.xlsx', $filename);
    }
}
