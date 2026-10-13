<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ActivityLog;
use App\Models\Barang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private function makeBarang(array $overrides = []): Barang
    {
        return Barang::query()->create(array_merge([
            'no_index' => 'IDX-' . uniqid(),
            'marking' => 'MARK-DEFAULT',
            'customer_name' => 'PT Contoh',
            'status' => Barang::STATUS_STUFFING,
            'warehouse_origin' => 'guangzhou',
        ], $overrides));
    }

    public function test_record_captures_snapshot_on_delete(): void
    {
        $barang = $this->makeBarang([
            'marking' => 'ABC-01',
            'no_index' => 'IDX-99',
            'customer_name' => 'PT Contoh',
        ]);

        $log = ActivityLog::record('Barang deleted', $barang);

        $this->assertSame('ABC-01', $log->old_values['marking'] ?? null);
        $this->assertSame('IDX-99', $log->old_values['no_index'] ?? null);
        $this->assertStringContainsString('ABC-01', $log->detailSummary());
        $this->assertStringContainsString('IDX-99', $log->detailSummary());
        $this->assertStringContainsString('PT Contoh', $log->detailSummary());
    }

    public function test_record_stores_only_changed_fields_on_update(): void
    {
        $barang = $this->makeBarang([
            'marking' => 'OLD-MARK',
            'status' => Barang::STATUS_STUFFING,
        ]);

        $old = $barang->toArray();
        $barang->update([
            'marking' => 'NEW-MARK',
            'status' => Barang::STATUS_STUFFING,
        ]);
        $new = $barang->fresh()->toArray();

        $log = ActivityLog::record('Barang updated', $barang, $old, $new);

        $this->assertArrayHasKey('marking', $log->old_values ?? []);
        $this->assertArrayHasKey('marking', $log->new_values ?? []);
        $this->assertSame('OLD-MARK', $log->old_values['marking']);
        $this->assertSame('NEW-MARK', $log->new_values['marking']);
        $this->assertStringContainsString('OLD-MARK', $log->detailSummary());
        $this->assertStringContainsString('NEW-MARK', $log->detailSummary());
    }

    public function test_logo_updated_has_readable_detail(): void
    {
        $log = ActivityLog::record('App logo updated');

        $this->assertNotSame('', $log->detailSummary());
        $this->assertTrue($log->hasDetail());
    }

    public function test_repricing_submitted_logs_price_changes(): void
    {
        $barang = $this->makeBarang([
            'marking' => 'ABC-01',
            'no_index' => 'IDX-77',
        ]);

        $log = ActivityLog::record(
            'Repricing submitted',
            $barang,
            ['price_per_cbm' => 1000, 'extra_charge' => 0],
            ['price_per_cbm' => 1500, 'extra_charge' => 50],
        );

        $summary = $log->detailSummary();
        $this->assertStringContainsString('1000', $summary);
        $this->assertStringContainsString('1500', $summary);
        $this->assertStringContainsString('ABC-01', $summary);
    }

    public function test_detail_lines_for_tracking_status_change(): void
    {
        $barang = $this->makeBarang(['status' => Barang::STATUS_STUFFING]);

        $log = ActivityLog::record(
            'Tracking updated',
            $barang,
            ['status' => Barang::STATUS_STUFFING],
            ['status' => Barang::STATUS_ETD],
        );

        $summary = $log->detailSummary();
        $this->assertStringContainsString(Barang::STATUS_STUFFING, $summary);
        $this->assertStringContainsString(Barang::STATUS_ETD, $summary);
    }
}
