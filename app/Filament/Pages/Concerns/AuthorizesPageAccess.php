<?php

namespace App\Filament\Pages\Concerns;

/**
 * Server-side authorization for custom Filament pages.
 *
 * `shouldRegisterNavigation()` only hides a menu entry — the page route stays
 * registered and its Livewire component stays mountable, so it is a UX helper
 * and never a security control. This concern turns a declared permission into a
 * real check that runs in Livewire's `boot()` hook, which fires on the initial
 * page load *and* on every subsequent Livewire update/action request. A crafted
 * request therefore cannot reach a page method by skipping `mount()`.
 *
 * Pages extending `Filament\Pages\Page` should extend
 * {@see \App\Filament\Pages\AuthorizedPage} instead of wiring this up by hand;
 * pages built on another base class (e.g. `SettingsPage`) use this concern
 * directly.
 */
trait AuthorizesPageAccess
{
    /**
     * Permission required to use this page. The composing class declares
     * `protected static ?string $permission` (a trait cannot, or subclasses
     * could not override the default). `null` means "any panel user", which is
     * only appropriate for pages that scope every record they show by
     * ownership/membership themselves.
     */
    public static function requiredPermission(): ?string
    {
        return static::$permission;
    }

    /**
     * Runs on every Livewire request for this component, not just on mount.
     */
    public function boot(): void
    {
        abort_unless(static::userCanAccessPage(), 403);
    }

    public static function userCanAccessPage(): bool
    {
        $permission = static::requiredPermission();

        if ($permission === null) {
            return true;
        }

        return (bool) auth()->user()?->can($permission);
    }

    /**
     * Kept for UX only: the menu entry disappears for users who would get a 403.
     */
    protected static function shouldRegisterNavigation(): bool
    {
        return parent::shouldRegisterNavigation() && static::userCanAccessPage();
    }
}
