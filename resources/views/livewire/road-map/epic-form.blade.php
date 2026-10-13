<form wire:submit.prevent="submit">
    {{ $this->form }}

    <div class="dialog-buttons">
        <button type="submit" wire:loading.attr="disabled">
            {{ __('Save') }}
        </button>
        <button type="button" wire:click="cancel" wire:loading.attr="disabled">
            {{ __('Cancel') }}
        </button>
        @if($epic?->id)
            <button class="delete" type="button" wire:click="delete" wire:loading.attr="disabled"
                    data-swal-confirm="{{ __('Delete this epic? Its tickets are kept but no longer linked to it.') }}" data-swal-severity="danger">
                {{ __('Delete') }}
            </button>
        @endif
    </div>
</form>
