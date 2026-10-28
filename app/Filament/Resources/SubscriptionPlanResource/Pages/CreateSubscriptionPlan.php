<?php

namespace App\Filament\Resources\SubscriptionPlanResource\Pages;

use App\Filament\Resources\SubscriptionPlanResource;
use App\Services\PlanChangeException;
use App\Services\PlanManager;
use Filament\Notifications\Notification;
use Filament\Pages\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateSubscriptionPlan extends CreateRecord
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
     * Filament never writes the model itself: PlanManager creates the plan and
     * its audit row in one transaction.
     */
    protected function handleRecordCreation(array $data): Model
    {
        abort_unless(SubscriptionPlanResource::canCreate(), 403);

        try {
            return app(PlanManager::class)->create(auth()->user(), $data, $data['reason'] ?? null);
        } catch (PlanChangeException $e) {
            Notification::make()->title(__('Plan not changed'))->body($e->getMessage())->danger()->persistent()->send();
            $this->halt();
        } catch (ValidationException $e) {
            // The form's state path is "data", so field errors need that prefix to show up.
            throw ValidationException::withMessages(
                collect($e->errors())->mapWithKeys(fn (array $messages, string $field) => ['data.'.$field => $messages])->all()
            );
        }

        // Only reachable if halt() above did not throw, which it always does.
        throw new \LogicException('Plan creation neither returned a plan nor halted.');
    }
}
