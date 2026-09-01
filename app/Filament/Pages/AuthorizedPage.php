<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\AuthorizesPageAccess;
use Filament\Pages\Page;

/**
 * Base class for every custom page in this panel.
 *
 * Declare the permission a page needs with `protected static ?string $permission`
 * and {@see AuthorizesPageAccess} enforces it server-side on each Livewire
 * request. Leaving it `null` is a deliberate choice for pages that authorize
 * per record instead (e.g. the boards check project membership in `mount()`).
 */
abstract class AuthorizedPage extends Page
{
    use AuthorizesPageAccess;

    protected static ?string $permission = null;
}
