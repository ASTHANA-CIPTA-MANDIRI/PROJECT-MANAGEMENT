<x-filament::page class="rk-board-pick-page">

    <div class="rk-pick">
        <x-filament::card class="rk-pick__card">
            <div class="rk-pick__intro">
                <span class="rk-pick__badge">
                    <x-heroicon-o-view-boards class="h-7 w-7" />
                </span>
                <div>
                    <h2 class="rk-pick__title">{{ __('Choose a project') }}</h2>
                    <p class="rk-pick__text">
                        {{ __("In this section you can choose one of your projects to show it's Scrum or Kanban board") }}
                    </p>
                </div>
            </div>

            <form wire:submit.prevent="search" class="rk-pick__form">
                {{ $this->form }}
            </form>
        </x-filament::card>

        <div class="rk-pick__art">
            <img src="{{ asset('img/scrum-kanban.png') }}" alt="Scrum board VS Kanban board"/>
        </div>
    </div>

</x-filament::page>
