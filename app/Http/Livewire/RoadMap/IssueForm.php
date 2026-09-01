<?php

namespace App\Http\Livewire\RoadMap;

use App\Http\Requests\TicketRequest;
use App\Models\Epic;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Ticket;
use App\Models\TicketPriority;
use App\Models\TicketStatus;
use App\Models\TicketType;
use App\Support\UserOptions;
use Closure;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Validator;
use Livewire\Component;

class IssueForm extends Component implements HasForms
{
    use AuthorizesRequests, InteractsWithForms;

    public ?Project $project = null;

    public array $epics;

    public array $sprints;

    public function mount()
    {
        $this->initProject($this->project?->id);
        $defaultStatus = $this->statusesQuery($this->project)
            ->where('is_default', true)
            ->first()
            ?->id;
        $this->form->fill([
            'project_id' => $this->project?->id ?? null,
            'owner_id' => auth()->user()->id,
            'status_id' => $defaultStatus,
            'type_id' => TicketType::where('is_default', true)->first()?->id,
            'priority_id' => TicketPriority::where('is_default', true)->first()?->id,
        ]);
    }

    public function render()
    {
        return view('livewire.road-map.issue-form');
    }

    private function initProject($projectId): void
    {
        if ($projectId) {
            // The id comes from Livewire state, so resolve it through the
            // access scope: everything derived below (epics, sprints and the
            // owner/responsible options) would otherwise expose another
            // project's data.
            $this->project = Project::accessibleBy(auth()->user())->whereKey($projectId)->first();
        } else {
            $this->project = null;
        }
        $this->epics = $this->project ? $this->project->epics->pluck('name', 'id')->toArray() : [];
        $this->sprints = $this->project ? $this->project->sprints->pluck('name', 'id')->toArray() : [];
    }

    /**
     * Ticket statuses are either global (shared by every "default" project)
     * or scoped to one "custom" project, never both.
     */
    private function statusesQuery(?Project $project): Builder
    {
        return $project?->status_type === 'custom'
            ? TicketStatus::where('project_id', $project->id)
            : TicketStatus::whereNull('project_id');
    }

    protected function getFormSchema(): array
    {
        return [
            Forms\Components\Grid::make()
                ->schema([
                    Forms\Components\Grid::make(4)
                        ->schema([
                            Forms\Components\Select::make('project_id')
                                ->label(__('Project'))
                                ->searchable()
                                ->reactive()
                                ->disabled($this->project != null)
                                ->columnSpan(2)
                                ->options(fn () => Project::accessibleBy(auth()->user())->pluck('name', 'id')->toArray())
                                ->afterStateUpdated(fn (Closure $get) => $this->initProject($get('project_id')))
                                ->required(),

                            Forms\Components\Select::make('sprint_id')
                                ->label(__('Sprint'))
                                ->searchable()
                                ->reactive()
                                ->visible(fn () => $this->project && $this->project->type === 'scrum')
                                ->columnSpan(2)
                                ->options(fn () => $this->sprints),

                            Forms\Components\Select::make('epic_id')
                                ->label(__('Epic'))
                                ->searchable()
                                ->reactive()
                                ->columnSpan(2)
                                ->required()
                                ->visible(fn () => $this->project && $this->project->type !== 'scrum')
                                ->options(fn () => $this->epics),

                            Forms\Components\TextInput::make('name')
                                ->label(__('Ticket name'))
                                ->required()
                                ->columnSpan(4)
                                ->maxLength(255),
                        ]),

                    Forms\Components\Select::make('owner_id')
                        ->label(__('Ticket owner'))
                        ->searchable()
                        ->options(fn () => UserOptions::forProject($this->project))
                        ->required(),

                    Forms\Components\Select::make('responsible_id')
                        ->label(__('Ticket responsible'))
                        ->searchable()
                        ->options(fn () => UserOptions::forProject($this->project)),

                    Forms\Components\Grid::make()
                        ->columns(3)
                        ->columnSpan(2)
                        ->schema([
                            Forms\Components\Select::make('status_id')
                                ->label(__('Ticket status'))
                                ->searchable()
                                ->options(fn () => $this->statusesQuery($this->project)
                                    ->get()
                                    ->pluck('name', 'id')
                                    ->toArray())
                                ->required(),

                            Forms\Components\Select::make('type_id')
                                ->label(__('Ticket type'))
                                ->searchable()
                                ->options(fn () => TicketType::query()->pluck('name', 'id')->toArray())
                                ->required(),

                            Forms\Components\Select::make('priority_id')
                                ->label(__('Ticket priority'))
                                ->searchable()
                                ->options(fn () => TicketPriority::query()->pluck('name', 'id')->toArray())
                                ->required(),
                        ]),
                ]),

            Forms\Components\RichEditor::make('content')
                ->label(__('Ticket content'))
                ->required()
                ->columnSpan(2),

            Forms\Components\Grid::make()
                ->columnSpan(2)
                ->columns(12)
                ->schema([
                    Forms\Components\TextInput::make('estimation')
                        ->label(__('Estimation time'))
                        ->numeric()
                        ->columnSpan(4),
                ]),
        ];
    }

    public function submit(): void
    {
        $this->authorize('create', Ticket::class);

        $data = $this->form->getState();

        // The select's options are scoped to the user's accessible projects,
        // but the posted state is client-controlled: resolve it through the
        // same access scope instead of trusting it as-is.
        $project = Project::accessibleBy(auth()->user())
            ->whereKey($data['project_id'] ?? null)
            ->firstOrFail();
        $data['project_id'] = $project->id;

        // Same for status/epic/sprint: their options are scoped to $this->project
        // client-side, but nothing stops the posted id from belonging to a
        // different project.
        $data['status_id'] = $this->statusesQuery($project)->whereKey($data['status_id'] ?? null)->value('id');
        $data['epic_id'] = ($data['epic_id'] ?? null)
            ? Epic::where('project_id', $project->id)->whereKey($data['epic_id'])->value('id')
            : null;
        $data['sprint_id'] = ($data['sprint_id'] ?? null)
            ? Sprint::where('project_id', $project->id)->whereKey($data['sprint_id'])->value('id')
            : null;

        // TicketRequest is the single source of truth for what a valid ticket
        // looks like, shared with the API's TicketController. Its rules are
        // scoped to the project, so they need the payload to build against.
        $data = Validator::make($data, TicketRequest::rulesFor($data), (new TicketRequest)->messages())
            ->validate();

        Ticket::create($data);
        Filament::notify('success', __('Ticket successfully saved'));
        $this->cancel(true);
    }

    public function cancel($refresh = false): void
    {
        $this->emit('closeTicketDialog', $refresh);
    }
}
