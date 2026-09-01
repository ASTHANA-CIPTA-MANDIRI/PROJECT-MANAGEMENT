<?php

namespace App\Filament\Pages;

use App\Helpers\KanbanScrumHelper;
use App\Models\Project;
use Filament\Facades\Filament;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Actions\Action;
use Illuminate\Contracts\Support\Htmlable;

/**
 * No page permission: access is decided per project in `mount()` (owner or
 * member only).
 */
class Kanban extends AuthorizedPage implements HasForms
{
    use InteractsWithForms, KanbanScrumHelper;

    protected static ?string $navigationIcon = 'heroicon-o-view-boards';

    protected static ?string $slug = 'kanban/{project}';

    protected static string $view = 'filament.pages.kanban';

    protected static bool $shouldRegisterNavigation = false;

    public function mount(Project $project)
    {
        $this->project = $project;

        // Checked before the board-type redirect: sending a stranger over to
        // the Scrum board would only move the same 403 one request further on.
        abort_unless($this->project->isAccessibleBy(auth()->user()), 403);

        if ($this->project->type === 'scrum') {
            // Returned, not just called: without it mount() carried on and
            // filled the form of a board this project does not even use.
            return $this->redirect(route('filament.pages.scrum/{project}', ['project' => $project]));
        }

        $this->form->fill();
    }

    protected function getActions(): array
    {
        return [
            Action::make('refresh')
                ->button()
                ->label(__('Refresh'))
                ->color('secondary')
                ->action(function () {
                    $this->getRecords();
                    Filament::notify('success', __('Kanban board updated'));
                }),
        ];
    }

    protected function getHeading(): string|Htmlable
    {
        return $this->kanbanHeading();
    }

    protected function getFormSchema(): array
    {
        return $this->formSchema();
    }
}
