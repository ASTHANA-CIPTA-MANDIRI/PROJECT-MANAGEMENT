<div class="kanban-record" data-id="{{ $record['id'] }}" wire:key="record-{{ $record['id'] }}">
    <div class="record-info">
        @if($this->isMultiProject())
            <span class="record-subtitle">
                {{ $record['project']->name }}
            </span>
        @endif
        <a href="{{ route('filament.resources.tickets.view', $record['id']) }}"
           target="_blank" draggable="false"
           class="record-title">
            <span class="code">{{ $record['code'] }}</span>
            <span class="title">{{ $record['title'] }}</span>
        </a>
    </div>
    @if($record['cover'])
        <a href="{{ route('filament.resources.tickets.view', $record['id']) }}" target="_blank" draggable="false"
           class="record-cover">
            <img src="{{ $record['cover'] }}" alt="" loading="lazy" draggable="false">
        </a>
    @endif
    @if($record['excerpt'])
        <p class="record-excerpt">{{ $record['excerpt'] }}</p>
    @endif
    @php($epic = $record['epic'])
    @if(($epic && $epic != "") || $record['labels']?->count() || $record['priority'] || $record['type'])
        <div class="record-tags">
            @if($epic && $epic != "")
                <span class="record-epic" title="{{ __('Epic') }}">{{ $epic->name }}</span>
            @endif
            @foreach($record['labels'] ?? [] as $label)
                <x-color-badge pill :color="$label->color" :label="$label->name" />
            @endforeach
            <x-color-badge pill :color="$record['priority']->color" :label="$record['priority']->name" />
            <x-color-badge pill :color="$record['type']->color" :label="$record['type']->name"
                           :icon="$record['type']->icon" />
        </div>
    @endif
    @if($record['due_date'] || $record['responsible'] || $record['totalLoggedHours'])
        <div class="record-footer">
            <div class="record-meta">
                @if($record['due_date'])
                    <span @class(['record-due-date', 'is-overdue' => $record['is_overdue']])>
                        <x-heroicon-o-calendar class="w-5 h-5" />
                        {{ $record['due_date']->translatedFormat($record['due_date']->isCurrentYear() ? 'd M' : 'd M Y') }}
                    </span>
                @endif
                @if($record['totalLoggedHours'])
                    <span class="record-logged-hours">
                        <x-heroicon-o-clock class="w-4 h-4" /> {{ $record['totalLoggedHours'] }}
                    </span>
                @endif
            </div>
            @if($record['responsible'])
                <x-user-avatar :user="$record['responsible']" />
            @endif
        </div>
    @endif
    @if($record['relations']?->count())
        <div class="record-relations">
            @foreach($record['relations'] as $relation)
                <div>
                    <span class="type text-{{ config('system.tickets.relations.colors.' . $relation->type) }}-600">
                        {{ __(config('system.tickets.relations.list.' . $relation->type)) }}
                    </span>
                    <a target="_blank" class="relation"
                        href="{{ route('filament.resources.tickets.share', $relation->relation->code) }}">
                        {{ $relation->relation->code }}
                    </a>
                </div>
            @endforeach
        </div>
    @endif
</div>
