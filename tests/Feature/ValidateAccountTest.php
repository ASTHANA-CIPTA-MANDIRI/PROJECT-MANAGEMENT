<?php

namespace Tests\Feature;

use App\Http\Livewire\ValidateAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The admin-created activation form only used ->required()->confirmed(),
 * unlike every other password entry point which goes through
 * AppServiceProvider::configurePasswordPolicy(). A one-character password
 * used to pass here.
 */
class ValidateAccountTest extends TestCase
{
    use RefreshDatabase;

    private function pendingUser(): User
    {
        Notification::fake();

        return User::create([
            'name' => 'Jane',
            'email' => 'jane@example.com',
            'type' => 'db',
        ]);
    }

    public function test_a_weak_password_is_rejected(): void
    {
        $user = $this->pendingUser();

        Livewire::test(ValidateAccount::class, ['user' => $user])
            ->set('password', 'a')
            ->set('password_confirmation', 'a')
            ->call('validateAccount')
            ->assertHasErrors(['password']);

        $this->assertNotNull($user->fresh()->creation_token);
    }

    public function test_a_strong_password_activates_the_account(): void
    {
        $user = $this->pendingUser();

        Livewire::test(ValidateAccount::class, ['user' => $user])
            ->set('password', 'GoodPass123')
            ->set('password_confirmation', 'GoodPass123')
            ->call('validateAccount')
            ->assertHasNoErrors();

        $user->refresh();
        $this->assertNull($user->creation_token);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('GoodPass123', $user->password));
        $this->assertAuthenticatedAs($user);
    }
}
