<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\Branding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class SystemLogoUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Storage::fake('public');
    }

    public function test_super_admin_can_upload_jpeg_logo(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        $file = UploadedFile::fake()->image('WhatsApp Image 2026-05-16 at 08.47.29.jpeg', 100, 100);

        $response = $this->actingAs($user)->post(route('system.logo.update'), [
            'logo' => $file,
        ]);

        $response->assertRedirect(route('system.index'));
        $response->assertSessionHas('status');

        $path = Branding::logoPath();
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);

        $logoUrl = Branding::logoUrl();
        $this->assertNotNull($logoUrl);
        $this->assertStringContainsString('/branding/logo', $logoUrl);

        $this->get($logoUrl)->assertOk();
    }

    public function test_invalid_file_shows_validation_error(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        $file = UploadedFile::fake()->create('fake.jpeg', 100, 'application/octet-stream');

        $response = $this->actingAs($user)->from(route('system.index'))->post(route('system.logo.update'), [
            'logo' => $file,
        ]);

        $response->assertRedirect(route('system.index'));
        $response->assertSessionHasErrors('logo');
    }
}
