<?php

namespace App\Filament\Resources\SubscriptionPlanResource\Pages;

use App\Filament\Resources\SubscriptionPlanResource;
use App\Models\SubscriptionPlan;
use App\Services\PlanChangeException;
use App\Services\PlanManager;
use App\Support\PlanHistory;
use Filament\Notifications\Notification;
use Filament\Pages\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * No Delete action: a plan is only ever deactivated.
 */
class EditSubscriptionPlan extends EditRecord
{
    protected static string $resource = SubscriptionPlanResource::class;

    protected function getActions(): array
    {
        return [
            Actions\Action::make('back')
                ->label(__('Back to plans'))
                ->icon('heroicon-o-arrow-left')
                ->color('secondary')
                ->extraAttributes(['class' => 'rk-back-action'])
                ->url(SubscriptionPlanResource::getUrl('index')),
        ];
    }

    /**
     * Filament never writes the model itself: PlanManager updates the plan and
     * writes its audit row (old -> new, who, optional reason) in one transaction.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof SubscriptionPlan && SubscriptionPlanResource::canEdit($record), 403);

        try {
            app(PlanManager::class)->update(auth()->user(), $record, $data, $data['reason'] ?? null);
        } catch (PlanChangeException $e) {
            Notification::make()->title(__('Plan not changed'))->body($e->getMessage())->danger()->persistent()->send();
            $this->halt();
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(
                collect($e->errors())->mapWithKeys(fn (array $messages, string $field) => ['data.'.$field => $messages])->all()
            );
        }

        return $record->refresh();
    }

    /** Show the saved values again and clear the one-off reason. */
    protected function afterSave(): void
    {
        $this->fillForm();
    }

    /**
     * Audit history of this plan under the form. Computed on every render and
     * handed to the view as plain strings - never a public Livewire property.
     */
    protected function getFooter(): ?View
    {
        return view('filament.resources.subscription-plans.history', [
            'rows' => PlanHistory::rows($this->record),
        ]);
    }
}
