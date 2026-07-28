<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use JeffGreco13\FilamentBreezy\FilamentBreezy;
use Tests\TestCase;

/**
 * Every password entry point uses one strong policy (min 8, mixed case,
 * numbers). The HaveIBeenPwned check only runs in production, so these assert
 * the complexity rules without hitting the network.
 */
class PasswordPolicyTest extends TestCase
{
    private function fails(string $password): bool
    {
        return Validator::make(
            ['password' => $password],
            ['password' => Password::defaults()]
        )->fails();
    }

    public function test_weak_passwords_are_rejected(): void
    {
        $this->assertTrue($this->fails('Ab1'), 'too short');
        $this->assertTrue($this->fails('alllowercase123'), 'no uppercase');
        $this->assertTrue($this->fails('ALLUPPERCASE123'), 'no lowercase');
        $this->assertTrue($this->fails('NoNumbersHere'), 'no digits');
    }

    public function test_a_strong_password_passes(): void
    {
        $this->assertFalse($this->fails('GoodPass123'));
    }

    public function test_breezy_forms_use_the_shared_policy(): void
    {
        // Registration / My Profile / reset all read Breezy's password rules.
        $rules = FilamentBreezy::getPasswordRules();

        $this->assertInstanceOf(Password::class, $rules[0]);
    }
}
