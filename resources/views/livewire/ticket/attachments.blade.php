<div class="w-full flex flex-col gap-5">
    @if($this->canManageAttachments())
        <form wire:submit.prevent="perform" class="w-full">
            {{ $this->form }}

            <button type="submit" wire:loading.prop="disabled"
                    class="rk-td__submit mt-3">
                {{ __('Upload') }}
            </button>
        </form>
    @endif

    <div class="w-full flex flex-col gap-1 pt-3">
        <div class="w-full">
            {{ $this->table }}
        </div>
    </div>
</div>
