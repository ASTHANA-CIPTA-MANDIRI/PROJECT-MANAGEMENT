<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Pages\Actions;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    /**
     * These two columns used to be toggleable (hidden by default) and Filament keeps
     * that choice in the session. They are always visible now, so a stale "hidden"
     * flag from an older session must not hide them.
     */
    public function isTableColumnToggledHidden(string $name): bool
    {
        if (in_array($name, ['email_verified_at', 'socials'], true)) {
            return false;
        }

        return parent::isTableColumnToggledHidden($name);
    }

    protected function getActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
