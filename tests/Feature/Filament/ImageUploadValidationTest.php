<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\ManageGeneralSettings;
use App\Filament\Resources\ProjectResource\Forms\ProjectForm;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use ReflectionMethod;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The project cover and site logo used ->image(), which accepts image/*,
 * including image/svg+xml. An SVG can embed <script>, and both are served
 * without ever being re-encoded: the cover streams through MediaController
 * with its original Content-Type, and the site logo is written straight to
 * the public disk with no authorization check at all - so an uploaded SVG
 * would execute on the app's origin for anyone who opened its URL directly.
 */
class ImageUploadValidationTest extends TestCase
{
    use RefreshDatabase;

    private function actingWith(array $permissions): User
    {
        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }
        $role = Role::create(['name' => 'role_'.uniqid()]);
        $role->syncPermissions($permissions);

        $user = User::factory()->create();
        $user->syncRoles([$role]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($user->fresh());

        return $user;
    }

    // ------------------------------------------------------- project cover

    /**
     * The Livewire testing harness does not faithfully simulate a full
     * upload → validate → store round trip for a SpatieMediaLibraryFileUpload
     * nested inside a Resource's CreateRecord page (confirmed against
     * site_logo below, a plain FileUpload on a settings page, which does
     * round-trip correctly) - fillForm()->call('create') with a fake SVG
     * neither raises a validation error nor attaches any media, so it cannot
     * prove anything here either way.
     *
     * cover() is private, so its accepted types are read directly off the
     * built component instead: this proves the exact same shared config
     * (system.images.accepted_mime_types, excludes image/svg+xml) is wired
     * into the field, which the site_logo tests below prove end-to-end.
     */
    public function test_the_project_cover_field_only_accepts_raster_images(): void
    {
        $cover = (new ReflectionMethod(ProjectForm::class, 'cover'))->invoke(null);

        $this->assertSame(config('system.images.accepted_mime_types'), $cover->getAcceptedFileTypes());
        $this->assertNotContains('image/svg+xml', $cover->getAcceptedFileTypes());
    }

    // -------------------------------------------------------- site logo

    public function test_the_site_logo_rejects_an_svg_upload(): void
    {
        Storage::fake('public');
        $this->actingWith(['Manage general settings']);

        Livewire::test(ManageGeneralSettings::class)
            ->fillForm(['site_logo' => [UploadedFile::fake()->create('evil.svg', 10, 'image/svg+xml')]])
            ->call('save')
            ->assertHasFormErrors(['site_logo']);
    }

    public function test_the_site_logo_accepts_a_png_upload(): void
    {
        Storage::fake('public');
        $this->actingWith(['Manage general settings']);

        Livewire::test(ManageGeneralSettings::class)
            ->fillForm(['site_logo' => [UploadedFile::fake()->image('logo.png')]])
            ->call('save')
            ->assertHasNoFormErrors();
    }
}
