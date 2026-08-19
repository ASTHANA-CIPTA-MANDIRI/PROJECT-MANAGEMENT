<?php

namespace App\Filament\Resources\ProjectResource\Forms;

use App\Models\Project;
use App\Models\ProjectStatus;
use App\Support\Colors;
use App\Support\UserOptions;
use Filament\Forms;

/**
 * The ProjectResource create/edit form schema, extracted from the resource to
 * keep it readable. Each section is a small builder method composed by
 * schema() — mirrors TicketForm.
 */
class ProjectForm
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
                        ->columns(3)
                        ->schema([
                            self::cover(),
                            self::detailsGrid(),
                            self::description(),
                            self::typeSelect(),
                            self::statusTypeSelect(),
                        ]),
                ]),
        ];
    }

    private static function cover(): Forms\Components\SpatieMediaLibraryFileUpload
    {
        return Forms\Components\SpatieMediaLibraryFileUpload::make('cover')
            ->label(__('Cover image'))
            ->image()
            ->maxSize(config('system.max_file_size'))
            ->helperText(
                __('If not selected, an image will be generated based on the project name')
            )
            ->columnSpan(1);
    }

    private static function detailsGrid(): Forms\Components\Grid
    {
        return Forms\Components\Grid::make()
            ->columnSpan(2)
            ->schema([
                Forms\Components\Grid::make()
                    ->columnSpan(2)
                    ->columns(12)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label(__('Project name'))
                            ->required()
                            ->columnSpan(10)
                            ->maxLength(255),

                        Forms\Components\TextInput::make('ticket_prefix')
                            ->label(__('Ticket prefix'))
                            ->maxLength(3)
                            ->columnSpan(2)
                            ->unique(Project::class, column: 'ticket_prefix', ignoreRecord: true)
                            ->disabled(
                                fn ($record) => $record && $record->tickets()->count() != 0
                            )
                            ->required(),
                    ]),

                Forms\Components\Select::make('owner_id')
                    ->label(__('Project owner'))
                    ->searchable()
                    ->options(fn () => UserOptions::visible())
                    ->default(fn () => auth()->user()->id)
                    ->required(),

                Forms\Components\Select::make('status_id')
                    ->label(__('Project status'))
                    ->searchable()
                    ->options(fn () => ProjectStatus::all()->pluck('name', 'id')->toArray())
                    ->default(fn () => ProjectStatus::where('is_default', true)->first()?->id)
                    ->required()
                    // Let users add a status inline instead of leaving the form.
                    ->createOptionForm([
                        Forms\Components\TextInput::make('name')
                            ->label(__('Status name'))
                            ->required()
                            ->maxLength(255),
                        Forms\Components\ColorPicker::make('color')
                            ->label(__('Color'))
                            ->default(Colors::DEFAULT)
                            ->required()
                            ->regex(Colors::HEX_PATTERN),
                    ])
                    ->createOptionUsing(fn (array $data) => ProjectStatus::create($data)->getKey()),
            ]);
    }

    private static function description(): Forms\Components\RichEditor
    {
        return Forms\Components\RichEditor::make('description')
            ->label(__('Project description'))
            ->columnSpan(3);
    }

    private static function typeSelect(): Forms\Components\Select
    {
        return Forms\Components\Select::make('type')
            ->label(__('Project type'))
            ->searchable()
            ->options([
                'kanban' => __('Kanban'),
                'scrum' => __('Scrum'),
            ])
            ->reactive()
            ->default(fn () => 'kanban')
            ->helperText(function ($state) {
                if ($state === 'kanban') {
                    return __('Display and move your project forward with issues on a powerful board.');
                } elseif ($state === 'scrum') {
                    return __('Achieve your project goals with a board, backlog, and roadmap.');
                }

                return '';
            })
            ->required();
    }

    private static function statusTypeSelect(): Forms\Components\Select
    {
        return Forms\Components\Select::make('status_type')
            ->label(__('Statuses configuration'))
            ->helperText(
                __('If custom type selected, you need to configure project specific statuses')
            )
            ->searchable()
            ->options([
                'default' => __('Default'),
                'custom' => __('Custom configuration'),
            ])
            ->default(fn () => 'default')
            ->disabled(fn ($record) => $record && $record->tickets()->count())
            ->required();
    }
}
