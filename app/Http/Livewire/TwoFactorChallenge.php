<?php

namespace App\Http\Livewire;

use App\Http\Controllers\Auth\SocialiteLoginController;
use App\Models\User;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use DutchCodingCompany\FilamentSocialite\Events;
use DutchCodingCompany\FilamentSocialite\Models\SocialiteUser;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Illuminate\Contracts\View\View;
use JeffGreco13\FilamentBreezy\Events\LoginSuccess;
use JeffGreco13\FilamentBreezy\FilamentBreezy;
use Livewire\Component;

/**
 * Second factor for a social login that is parked by
 * {@see SocialiteLoginController}. Mirrors the code check Breezy's login form
 * performs (TOTP or a recovery code) so both entry points enforce the same
 * thing; nobody is authenticated until a code verifies.
 */
class TwoFactorChallenge extends Component implements HasForms
{
    use InteractsWithForms, WithRateLimiting;

    public ?string $code = null;

    public bool $usingRecoveryCode = false;

    public function mount(): void
    {
        // Reaching this page without a parked login means there is nothing to
        // confirm — send them back to a normal login rather than 404.
        if (! $this->pendingUser()) {
            redirect()->route('filament.auth.login');

            return;
        }

        $this->form->fill();
    }

    public function render(): View
    {
        return view('livewire.two-factor-challenge');
    }

    public function toggleRecoveryCode(): void
    {
        $this->resetErrorBag('code');
        $this->code = null;
        $this->usingRecoveryCode = ! $this->usingRecoveryCode;
    }

    protected function getFormSchema(): array
    {
        return [
            TextInput::make('code')
                ->label($this->usingRecoveryCode
                    ? __('filament-breezy::default.fields.2fa_recovery_code')
                    : __('filament-breezy::default.fields.2fa_code'))
                ->extraInputAttributes(['autocomplete' => $this->usingRecoveryCode ? 'off' : 'one-time-code'])
                ->required(),
        ];
    }

    public function authenticate(): void
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->addError('code', __('filament::login.messages.throttled', [
                'seconds' => $exception->secondsUntilAvailable,
                'minutes' => ceil($exception->secondsUntilAvailable / 60),
            ]));

            return;
        }

        $user = $this->pendingUser();

        if (! $user) {
            redirect()->route('filament.auth.login');

            return;
        }

        $code = $this->form->getState()['code'] ?? null;

        if (! $this->hasValidCode($user, $code)) {
            $this->addError('code', __('filament-breezy::default.profile.2fa.confirmation.invalid_code'));

            return;
        }

        // Read before clearing: the parked payload is what identifies the
        // social account this login belongs to.
        $socialiteUser = $this->pendingSocialiteUser();

        // Only now does the session become authenticated. Regenerating again
        // separates the pre-challenge session from the logged-in one.
        session()->forget(SocialiteLoginController::PENDING_SESSION_KEY);
        session()->regenerate();

        auth()->guard(config('filament.auth.guard'))->login($user);

        if ($socialiteUser) {
            Events\Login::dispatch($socialiteUser);
        }

        event(new LoginSuccess($user));

        redirect()->intended(route('filament.pages.dashboard'));
    }

    private function hasValidCode(User $user, ?string $code): bool
    {
        if (blank($code)) {
            return false;
        }

        if ($this->usingRecoveryCode) {
            return collect($user->recoveryCodes())
                ->contains(fn ($recoveryCode) => hash_equals($recoveryCode, $code));
        }

        return (bool) $user->verifyTwoFactor($code, app(FilamentBreezy::class));
    }

    private function pendingUser(): ?User
    {
        $pending = session(SocialiteLoginController::PENDING_SESSION_KEY);

        if (! is_array($pending) || ! isset($pending['user_id'])) {
            return null;
        }

        $user = User::find($pending['user_id']);

        // A user who turned 2FA off between the redirect and this page has no
        // second factor to present; treat the parked login as stale.
        return $user?->has_confirmed_two_factor ? $user : null;
    }

    private function pendingSocialiteUser(): ?SocialiteUser
    {
        $pending = session(SocialiteLoginController::PENDING_SESSION_KEY);

        return isset($pending['socialite_user_id'])
            ? SocialiteUser::find($pending['socialite_user_id'])
            : null;
    }
}
