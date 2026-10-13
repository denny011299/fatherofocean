<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Marking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MarkingRelationPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
    }

    public function test_update_persists_related_marking_id_without_relation_mode(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        $hub = Marking::query()->create([
            'code' => 'EK/ON/SARA',
            'customer_name' => 'YENNY',
            'is_active' => true,
        ]);

        $child = Marking::query()->create([
            'code' => 'EK/ON/SONDAKH',
            'customer_name' => 'YENNY',
            'is_active' => true,
        ]);

        $this->actingAs($user)->putJson('/marking/' . $child->id, [
            'code' => 'EK/ON/SONDAKH',
            'customer_name' => 'YENNY',
            'related_marking_id' => $hub->id,
        ])->assertOk();

        $child->refresh();
        $this->assertSame($hub->id, $child->related_marking_id);
    }

    public function test_store_persists_related_marking_id(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        $hub = Marking::query()->create([
            'code' => 'EK/ON/SARA',
            'customer_name' => 'YENNY',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->postJson('/marking', [
            'code' => 'EK/ON/SONDAKH',
            'customer_name' => 'YENNY',
            'related_marking_id' => $hub->id,
        ])->assertOk();

        $createdId = (int) $response->json('data.id');
        $created = Marking::query()->findOrFail($createdId);

        $this->assertSame($hub->id, $created->related_marking_id);
    }

    public function test_data_endpoint_returns_saved_relation_for_edit_reload(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        $hub = Marking::query()->create([
            'code' => 'EK/ON/SARA',
            'customer_name' => 'YENNY',
            'is_active' => true,
        ]);

        $child = Marking::query()->create([
            'code' => 'EK/ON/SONDAKH',
            'customer_name' => 'YENNY',
            'related_marking_id' => $hub->id,
            'is_active' => true,
        ]);

        $payload = $this->actingAs($user)
            ->getJson('/marking/data')
            ->assertOk()
            ->json();

        $row = collect($payload)->firstWhere('id', $child->id);
        $this->assertNotNull($row);
        $this->assertSame($hub->id, $row['related_marking_id']);
        $this->assertNotEmpty($row['related_marking_label']);
    }

    public function test_update_response_includes_related_marking_fields(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        $hub = Marking::query()->create([
            'code' => 'EK/ON/SARA',
            'customer_name' => 'YENNY',
            'is_active' => true,
        ]);

        $child = Marking::query()->create([
            'code' => 'EK/ON/SONDAKH',
            'customer_name' => 'YENNY',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->putJson('/marking/' . $child->id, [
            'code' => 'EK/ON/SONDAKH',
            'customer_name' => 'YENNY',
            'related_marking_id' => $hub->id,
        ])->assertOk();

        $this->assertSame($hub->id, $response->json('data.related_marking_id'));
        $this->assertStringContainsString('SARA', (string) $response->json('data.related_marking_label'));
    }

    public function test_update_clears_related_marking_id_with_empty_string(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        $hub = Marking::query()->create([
            'code' => 'EK/ON/SARA',
            'customer_name' => 'YENNY',
            'is_active' => true,
        ]);

        $child = Marking::query()->create([
            'code' => 'EK/ON/SONDAKH',
            'customer_name' => 'YENNY',
            'related_marking_id' => $hub->id,
            'is_active' => true,
        ]);

        $this->actingAs($user)->putJson('/marking/' . $child->id, [
            'code' => 'EK/ON/SONDAKH',
            'customer_name' => 'YENNY',
            'related_marking_id' => '',
        ])->assertOk();

        $child->refresh();
        $this->assertNull($child->related_marking_id);
    }
}
