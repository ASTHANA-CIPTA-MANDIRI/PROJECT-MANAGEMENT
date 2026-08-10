<?php

namespace App\Filament\Pages;

use App\Models\Project;
use Filament\Facades\Filament;
use Filament\Forms\Components\Card;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Illuminate\Contracts\Support\Htmlable;

/**
 * No page permission: the project picker only ever offers projects the user
 * owns or belongs to (`Project::accessibleBy`).
 */
class Board extends AuthorizedPage implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-view-boards';

    protected static string $view = 'filament.pages.board';

    protected static ?string $slug = 'board';

    protected static ?int $navigationSort = 4;

    protected function getSubheading(): string|Htmlable|null
    {
        return __("In this section you can choose one of your projects to show it's Scrum or Kanban board");
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    protected static function getNavigationLabel(): string
    {
        return __('Board');
    }

    protected static function getNavigationGroup(): ?string
    {
        return __('Management');
    }

    protected function getFormSchema(): array
    {
        return [
            Card::make()
                ->schema([
                    Grid::make()
                        ->columns(1)
                        ->schema([
                            Select::make('project')
                                ->label(__('Project'))
                                ->required()
                                ->searchable()
                                ->reactive()
                                ->afterStateUpdated(fn () => $this->search())
                                ->helperText(__("Choose a project to show it's board"))
                                ->options(fn () => Project::accessibleBy(auth()->user())->pluck('name', 'id')->toArray()),
                        ]),
                ]),
        ];
    }

    public function search(): void
    {
        $data = $this->form->getState();

        // The select only offers accessible projects, but the id arrives from
        // the browser: re-scope it instead of trusting the payload.
        $project = Project::accessibleBy(auth()->user())->find($data['project']);

        if (! $project) {
            Filament::notify('danger', __('This project is not available'));

            return;
        }

        if ($project->type === 'scrum') {
            $this->redirect(route('filament.pages.scrum/{project}', ['project' => $project]));
        } else {
            $this->redirect(route('filament.pages.kanban/{project}', ['project' => $project]));
        }
    }
}
