<div class="rk-bah">
    @forelse ($rows as $row)
        <div class="rk-bah__row">
            <div class="rk-bah__head">
                <b>{{ $row['action'] }}</b>
                <span>{{ $row['when'] }}</span>
            </div>
            <div class="rk-bah__meta">{{ $row['actor'] }}</div>
            @if ($row['changes'] !== [])
                <div class="rk-bah__changes">
                    @foreach ($row['changes'] as $line)
                        <span>{{ $line }}</span>
                    @endforeach
                </div>
            @endif
            @if (filled($row['reason']))
                <div class="rk-bah__reason">{{ $row['reason'] }}</div>
            @endif
        </div>
    @empty
        <div class="rk-bah__empty">{{ __('No changes recorded for this plan yet.') }}</div>
    @endforelse
</div>
