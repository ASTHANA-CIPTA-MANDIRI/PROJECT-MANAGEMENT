<style>
    .rk-bah { display: flex; flex-direction: column; gap: .5rem; max-height: 60vh; overflow-y: auto; }
    .rk-bah__row { border: 1px solid #e5e7eb; border-radius: 1rem; padding: .75rem 1rem; font-size: .875rem; color: #4b5563; }
    .rk-bah__head { display: flex; flex-wrap: wrap; justify-content: space-between; gap: .25rem .75rem; }
    .rk-bah__head b { color: #111827; font-weight: 600; }
    .rk-bah__meta { margin-top: .25rem; font-size: .75rem; color: #6b7280; }
    .rk-bah__reason { margin-top: .375rem; white-space: pre-wrap; overflow-wrap: anywhere; color: #374151; }
    .rk-bah__empty { padding: 1rem; text-align: center; font-size: .875rem; color: #6b7280; }
</style>

<div class="rk-bah">
    @forelse ($rows as $row)
        <div class="rk-bah__row">
            <div class="rk-bah__head">
                <b>{{ $row['action'] }} - {{ $row['result'] }}</b>
                <span>{{ $row['when'] }}</span>
            </div>
            <div class="rk-bah__meta">{{ $row['actor'] }} &middot; {{ __('Invoice status') }}: {{ $row['status'] }}</div>
            @if (filled($row['reason']))
                <div class="rk-bah__reason">{{ $row['reason'] }}</div>
            @endif
        </div>
    @empty
        <div class="rk-bah__empty">{{ __('No billing actions recorded for this invoice yet.') }}</div>
    @endforelse
</div>
