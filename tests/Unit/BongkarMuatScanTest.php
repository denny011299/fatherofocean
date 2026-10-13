<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\BongkarMuatScan;
use Tests\TestCase;

final class BongkarMuatScanTest extends TestCase
{
    public function test_integer_fields_are_cast_properly(): void
    {
        $scan = new BongkarMuatScan([
            'bongkar_muat_session_id' => '1',
            'barang_id' => '2',
            'expected_ctns' => '3',
            'confirmed_ctns' => '4',
            'scanned_by' => '5',
        ]);

        $this->assertSame(1, $scan->bongkar_muat_session_id);
        $this->assertSame(2, $scan->barang_id);
        $this->assertSame(3, $scan->expected_ctns);
        $this->assertSame(4, $scan->confirmed_ctns);
        $this->assertSame(5, $scan->scanned_by);
    }
}
