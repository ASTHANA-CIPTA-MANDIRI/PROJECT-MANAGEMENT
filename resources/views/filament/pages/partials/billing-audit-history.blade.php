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
