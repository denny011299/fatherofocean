<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\BongkarMuatSession;
use Tests\TestCase;

final class BongkarMuatSessionTest extends TestCase
{
    public function test_container_id_is_cast_to_integer(): void
    {
        $session = new BongkarMuatSession([
            'container_id' => '1',
            'started_by' => '2',
            'completed_by' => '3',
        ]);

        $this->assertSame(1, $session->container_id);
        $this->assertSame(2, $session->started_by);
        $this->assertSame(3, $session->completed_by);
    }
}
