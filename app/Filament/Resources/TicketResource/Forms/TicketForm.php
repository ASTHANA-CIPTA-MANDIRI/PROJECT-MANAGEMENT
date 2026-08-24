<?php

namespace App\Filament\Resources\TicketResource\Forms;

use App\Models\Epic;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketPriority;
use App\Models\TicketRelation;
use App\Models\TicketStatus;
use App\Models\TicketType;
use App\Support\Colors;
use App\Support\UserOptions;
use Filament\Forms;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\EditRecord;

/**
 * The TicketResource create/edit form schema, extracted from the resource to
 * keep it readable. Each section is a small builder method composed by
 * schema().
 */
class TicketForm
{
    /**
     * @return array<int, \Filament\Forms\Components\Component>
     */
    public static function schema(): array
    {
        return [
            Forms\Components\Card::make()
                ->schema([
                    Forms\Components\Grid::make()
                        ->schema([
                            self::projectSelect(),
                            self::epicSelect(),
                            self::codeAndName(),
                            self::ownerSelect(),
                            self::responsibleSelect(),
                            self::statusTypePriority(),
                        ]),
                    self::content(),
                    self::labelsSelect(),
                    self::estimationAndDueDate(),
                    self::relationsRepeater(),
                ]),
        ];
    }

    private static function projectSelect(): Forms\Components\Select
    {
        return Forms\Components\Select::make('project_id')
            ->label(__('Project'))
            ->searchable()
            ->reactive()
            ->afterStateUpdated(function ($get, $set) {
                $project = Project::where('id', $get('project_id'))->first();
                $set('status_id', self::defaultStatusId($project));
            })
            ->options(fn () => Project::accessibleBy(auth()->user())->pluck('name', 'id')->toArray())
            ->default(fn () => request()->get('project'))
            ->required();
    }

    private static function epicSelect(): Forms\Components\Select
    {
        return Forms\Components\Select::make('epic_id')
            ->label(__('Epic'))
            ->searchable()
            ->reactive()
            ->options(function ($get, $set) {
                return Epic::where('project_id', $get('project_id'))->pluck('name', 'id')->toArray();
            });
    }

    private static function codeAndName(): Forms\Components\Grid
    {
        return Forms\Components\Grid::make()
            ->columns(12)
            ->columnSpan(2)
            ->schema([
                Forms\Components\TextInput::make('code')
                    ->label(__('Ticket code'))
                    ->visible(fn ($livewire) => ! ($livewire instanceof CreateRecord))
                    ->columnSpan(2)
                    ->disabled(),

                Forms\Components\TextInput::make('name')
                    ->label(__('Ticket name'))
                    ->required()
                    ->columnSpan(
                        fn ($livewire) => ! ($livewire instanceof CreateRecord) ? 10 : 12
                    )
                    ->maxLength(255),
            ]);
    }

    private static function ownerSelect(): Forms\Components\Select
    {
        return Forms\Components\Select::make('owner_id')
            ->label(__('Ticket owner'))
            ->searchable()
            ->options(fn ($get, $record) => UserOptions::forProjectId($get('project_id'), $record?->owner_id))
            ->default(fn () => auth()->user()->id)
            ->required();
    }

    private static function responsibleSelect(): Forms\Components\Select
    {
        return Forms\Components\Select::make('responsible_id')
            ->label(__('Ticket responsible'))
            ->searchable()
            ->options(fn ($get, $record) => UserOptions::forProjectId($get('project_id'), $record?->responsible_id));
    }

    private static function statusTypePriority(): Forms\Components\Grid
    {
        return Forms\Components\Grid::make()
            ->columns(3)
            ->columnSpan(2)
            ->schema([
                Forms\Components\Select::make('status_id')
                    ->label(__('Ticket status'))
                    ->searchable()
                    ->options(function ($get) {
                        $project = Project::where('id', $get('project_id'))->first();

                        return self::statusesFor($project)->pluck('name', 'id')->toArray();
                    })
                    ->default(function ($get) {
                        $project = Project::where('id', $get('project_id'))->first();

                        return self::defaultStatusId($project);
                    })
                    ->required(),

                Forms\Components\Select::make('type_id')
                    ->label(__('Ticket type'))
                    ->searchable()
                    ->options(fn () => TicketType::query()->pluck('name', 'id')->toArray())
                    ->default(fn () => TicketType::where('is_default', true)->first()?->id)
                    ->required(),

                Forms\Components\Select::make('priority_id')
                    ->label(__('Ticket priority'))
                    ->searchable()
                    ->options(fn () => TicketPriority::query()->pluck('name', 'id')->toArray())
                    ->default(fn () => TicketPriority::where('is_default', true)->first()?->id)
                    ->required(),
            ]);
    }

    private static function content(): Forms\Components\RichEditor
    {
        return Forms\Components\RichEditor::make('content')
            ->label(__('Ticket content'))
            ->required()
            ->columnSpan(2);
    }

    private static function labelsSelect(): Forms\Components\Select
    {
        return Forms\Components\Select::make('labels')
            ->label(__('Labels'))
            ->multiple()
            ->relationship('labels', 'name')
            ->preload()
            ->searchable()
            ->columnSpan(2)
            // Create a new label inline (name + color) without leaving the form.
            ->createOptionForm([
                Forms\Components\TextInput::make('name')
                    ->label(__('Label name'))
                    ->required()
                    ->maxLength(255),
                Forms\Components\ColorPicker::make('color')
                    ->label(__('Color'))
                    ->default('#3b82f6')
                    ->required()
                    ->regex(Colors::HEX_PATTERN),
            ]);
    }

    private static function estimationAndDueDate(): Forms\Components\Grid
    {
        return Forms\Components\Grid::make()
            ->columnSpan(2)
            ->columns(12)
            ->schema([
                Forms\Components\TextInput::make('estimation')
                    ->label(__('Estimation time'))
                    ->numeric()
                    ->columnSpan(2),

                Forms\Components\DatePicker::make('due_date')
                    ->label(__('Due date'))
                    ->columnSpan(2),
            ]);
    }

    private static function relationsRepeater(): Forms\Components\Repeater
    {
        return Forms\Components\Repeater::make('relations')
            ->itemLabel(function (array $state) {
                $ticketRelation = TicketRelation::find($state['id'] ?? 0);
                if ($ticketRelation) {
                    return __(config('system.tickets.relations.list.'.$ticketRelation->type))
                        .' '
                        .$ticketRelation->relation->name
                        .' ('.$ticketRelation->relation->code.')';
                }

                return null;
            })
            ->relationship()
            ->collapsible()
            ->collapsed()
            ->orderable()
            ->defaultItems(0)
            ->schema([
                Forms\Components\Grid::make()
                    ->columns(3)
                    ->schema([
                        Forms\Components\Select::make('type')
                            ->label(__('Relation type'))
                            ->required()
                            ->searchable()
                            ->options(config('system.tickets.relations.list'))
                            ->default(fn () => config('system.tickets.relations.default')),

                        Forms\Components\Select::make('relation_id')
                            ->label(__('Related ticket'))
                            ->required()
                            ->searchable()
                            ->columnSpan(2)
                            ->options(function ($livewire) {
                                $query = Ticket::query();
                                if ($livewire instanceof EditRecord && $livewire->record) {
                                    $query->where('id', '<>', $livewire->record->id);
                                }

                                return $query->get()->pluck('name', 'id')->toArray();
                            }),
                    ]),
            ]);
    }

    /**
     * Statuses available for a project: its own custom set, or the global
     * (project-less) statuses. Centralises the branch the status field and the
     * default both need.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, TicketStatus>
     */
    private static function statusesFor(?Project $project): \Illuminate\Database\Eloquent\Collection
    {
        $query = $project?->status_type === 'custom'
            ? TicketStatus::where('project_id', $project->id)
            : TicketStatus::whereNull('project_id');

        return $query->get();
    }

    private static function defaultStatusId(?Project $project): ?int
    {
        return self::statusesFor($project)->firstWhere('is_default', true)?->id;
    }
}
