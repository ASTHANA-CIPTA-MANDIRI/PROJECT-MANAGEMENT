<div class="kanban-record" data-id="{{ $record['id'] }}">
    <button type="button" class="handle">
        <x-heroicon-o-arrows-expand class="w-5 h-5" />
    </button>
    <div class="record-info">
        @if($this->isMultiProject())
            <span class="record-subtitle">
                {{ $record['project']->name }}
            </span>
        @endif
        <a href="{{ route('filament.resources.tickets.view', $record['id']) }}"
           target="_blank"
           class="record-title">
            <span class="code">{{ $record['code'] }}</span>
            <span class="title">{{ $record['title'] }}</span>
        </a>
    </div>
    @if($record['due_date'])
        <div class="record-due-date"
             style="display:inline-flex;align-items:center;gap:.25rem;margin:.35rem 0;padding:.125rem .5rem;
                    border-radius:9999px;font-size:.75rem;color:#fff;
                    background-color: {{ $record['is_overdue'] ? '#ef4444' : '#9ca3af' }};">
            <x-heroicon-o-calendar class="w-3 h-3" />
            {{ $record['due_date']->format('Y-m-d') }}
        </div>
    @endif
    @if($record['labels']?->count())
        <div class="record-labels" style="display:flex;flex-wrap:wrap;gap:.25rem;margin:.35rem 0;">
            @foreach($record['labels'] as $label)
                <span class="text-white text-xs px-2 py-0.5 rounded-full"
                      style="background-color: {{ \App\Support\Colors::safe($label->color) }};">
                    {{ $label->name }}
                </span>
            @endforeach
        </div>
    @endif
    <div class="record-footer">
        <div class="record-type-code">
            @php($epic = $record['epic'])
            @if($epic && $epic != "")
                <div class="px-2 py-1 rounded flex items-center justify-center text-center text-xs text-white
                            bg-purple-500" title="{{ __('Epic') }}">
                    {{ $epic->name }}
                </div>
            @endif
            <x-ticket-priority :priority="$record['priority']" />
            <x-ticket-type :type="$record['type']" />
        </div>
        @if($record['responsible'])
            <x-user-avatar :user="$record['responsible']" />
        @endif
    </div>
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
    @if($record['totalLoggedHours'])
        <div class="record-logged-hours">
            <x-heroicon-o-clock class="w-4 h-4" /> {{ $record['totalLoggedHours'] }}
        </div>
    @endif
</div>
