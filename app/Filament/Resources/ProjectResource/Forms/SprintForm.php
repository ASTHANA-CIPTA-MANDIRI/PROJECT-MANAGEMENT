<?php

namespace App\Filament\Resources\ProjectResource\Forms;

use Carbon\Carbon;
use Closure;
use Filament\Forms;
use Illuminate\Support\HtmlString;

/**
 * The sprint create/edit form used by SprintsRelationManager, extracted for
 * readability — mirrors TicketForm / ProjectForm.
 */
class SprintForm
{
    /**
     * @return array<int, \Filament\Forms\Components\Component>
     */
    public static function schema(): array
    {
        return [
            self::creationNotice(),
            self::fields(),
        ];
    }

    /** Shown only on create: warns that a linked Epic will be created too. */
    private static function creationNotice(): Forms\Components\Grid
    {
        return Forms\Components\Grid::make()
            ->columns(1)
            ->visible(fn ($record) => ! $record)
            ->extraAttributes([
                'class' => 'text-danger-500 text-xs',
            ])
            ->schema([
                Forms\Components\Placeholder::make('information')
                    ->disableLabel()
                    ->content(new HtmlString(
                        '<span class="font-medium">'.__('Important:').'</span>'.' '.
                        __('The creation of a new Sprint will create a linked Epic into to the Road Map')
                    )),
            ]);
    }

    private static function fields(): Forms\Components\Grid
    {
        return Forms\Components\Grid::make()
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label(__('Sprint name'))
                    ->maxLength(255)
                    ->columnSpan(2)
                    ->required(),

                Forms\Components\DatePicker::make('starts_at')
                    ->label(__('Sprint start date'))
                    ->reactive()
                    ->afterStateUpdated(fn ($state, Closure $set) => $set('ends_at', Carbon::parse($state)->addWeek()->subDay()))
                    ->beforeOrEqual(fn (Closure $get) => $get('ends_at'))
                    ->required(),

                Forms\Components\DatePicker::make('ends_at')
                    ->label(__('Sprint end date'))
                    ->reactive()
                    ->afterOrEqual(fn (Closure $get) => $get('starts_at'))
                    ->required(),

                Forms\Components\RichEditor::make('description')
                    ->label(__('Sprint description'))
                    ->columnSpan(2),
            ]);
    }
}
