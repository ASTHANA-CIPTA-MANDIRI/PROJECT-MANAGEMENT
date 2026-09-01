<?php

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Filament\Resources\ProjectResource;
use App\Models\Project;
use Filament\Pages\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;

class EditProject extends EditRecord
{
    protected static string $resource = ProjectResource::class;

    protected function getActions(): array
    {
        return [
            Actions\Action::make('kanban')
                ->label(
                    fn () => ($this->record->type === 'scrum' ? __('Scrum board') : __('Kanban board'))
                )
                ->icon('heroicon-o-view-boards')
                ->color('secondary')
                ->url(function () {
                    if ($this->record->type === 'scrum') {
                        return route('filament.pages.scrum/{project}', ['project' => $this->record->id]);
                    } else {
                        return route('filament.pages.kanban/{project}', ['project' => $this->record->id]);
                    }
                }),

            Actions\ViewAction::make(),
            // Unlike the API's destroy() (ProjectController), Filament's own
            // DeleteAction does not wrap the record delete in a transaction,
            // so ProjectObserver's ticket/sprint/epic cascade would not be
            // atomic with the project's own row here without this.
            Actions\DeleteAction::make()
                ->using(fn (Project $record) => DB::transaction(fn () => $record->delete())),
        ];
    }
}
