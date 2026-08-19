<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\LabelResource\Pages\CreateLabel;
use App\Models\Label;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Filament 2's ColorPicker never validated its value server-side - it was a
 * free-text string once it reached the request, and every `color` column
 * (project statuses, ticket statuses, types, priorities, labels) is
 * interpolated straight into a style="background-color: ..." attribute
 * wherever it's shown. This proves the ->regex() rule added to every
 * ColorPicker actually blocks a bad value through Filament's real save()
 * path, not just as an isolated rule expression.
 *
 * Labels stand in for all five - every ColorPicker in the app carries the
 * exact same App\Support\Colors::HEX_PATTERN rule.
 */
class ColorPickerValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Create label', 'List labels'] as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }
        $role = Role::create(['name' => 'Label manager']);
        $role->syncPermissions(['Create label', 'List labels']);

        $user = User::factory()->create();
        $user->syncRoles([$role]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($user->fresh());
    }

    public function test_a_css_injection_payload_is_rejected(): void
    {
        Livewire::test(CreateLabel::class)
            ->fillForm([
                'name' => 'Urgent',
                'color' => '#fff; position:fixed; inset:0; background:url(https://evil.example/log)',
            ])
            ->call('create')
            ->assertHasFormErrors(['color']);

        $this->assertSame(0, Label::count());
    }

    public function test_a_named_css_color_is_rejected(): void
    {
        Livewire::test(CreateLabel::class)
            ->fillForm(['name' => 'Urgent', 'color' => 'red'])
            ->call('create')
            ->assertHasFormErrors(['color']);
    }

    public function test_a_well_formed_hex_color_is_accepted(): void
    {
        Livewire::test(CreateLabel::class)
            ->fillForm(['name' => 'Urgent', 'color' => '#1a2b3c'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('#1a2b3c', Label::sole()->color);
    }
}
