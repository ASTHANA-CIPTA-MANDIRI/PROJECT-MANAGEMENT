@php($record = $this->record)
<x-filament::page class="filament-resources-view-record-page filament-resources-tickets">

    <div class="rk-td__bar">
        <a href="{{ route('filament.pages.kanban/{project}', ['project' => $record->project->id]) }}"
           class="rk-td__back">
            <x-heroicon-o-arrow-left class="w-4 h-4"/> {{ __('Back to kanban board') }}
        </a>
        <x-filament::pages.actions :actions="$this->getCachedActions()"/>
    </div>

    {{-- Presentation only: every value, relation, action and permission below
         is the same as before; only the arrangement changed. Styles are scoped
         to .rk-td in resources/css/filament.scss. --}}
    <div class="rk-td">

        <div class="rk-td__main">

            <header class="rk-td__header">
                <div class="flex items-center flex-wrap gap-2">
                    <span class="rk-td__code">
                        {{ $record->code }}
                    </span>
                    <span class="text-sm text-gray-300">|</span>
                    <span class="text-sm text-gray-500">
                        {{ $record->project->name }}
                    </span>
                </div>
                <h2 class="rk-td__title">
                    {{ $record->name }}
                </h2>
                <div class="flex items-center flex-wrap gap-2">
                    <x-color-badge pill :color="$record->status->color" :label="$record->status->name"/>
                    <x-color-badge pill :color="$record->priority->color" :label="$record->priority->name"/>
                    <x-color-badge pill :color="$record->type->color" :label="$record->type->name"
                                   :icon="$record->type->icon"/>
                    @foreach($record->labels as $label)
                        <x-color-badge pill :color="$label->color" :label="$label->name"/>
                    @endforeach
                    @if($record->due_date)
                        <span @class([
                                'rk-td__due',
                                'is-overdue' => $record->isOverdue,
                            ])>
                            <x-heroicon-o-calendar class="w-3.5 h-3.5"/>
                            {{ __('Due') }} {{ $record->due_date->format(__('Y-m-d')) }}
                            @if($record->isOverdue)
                                ({{ __('Overdue') }})
                            @endif
                        </span>
                    @endif
                </div>
            </header>

            <x-filament::card class="rk-td__card">
                <h3 class="rk-td__card-title">
                    <x-heroicon-o-document-text class="w-5 h-5 text-gray-400"/>
                    {{ __('Content') }}
                </h3>
                <div class="w-full prose max-w-none">
                    {!! \App\Support\HtmlSanitizer::clean($record->content) !!}
                </div>
            </x-filament::card>

            <x-filament::card class="rk-td__card">
                <div class="rk-tabs">
                    <button type="button" wire:click="selectTab('comments')"
                            class="rk-tabs__tab @if($tab === 'comments') is-active @endif">
                        {{ __('Comments') }}
                    </button>
                    <button type="button" wire:click="selectTab('activities')"
                            class="rk-tabs__tab @if($tab === 'activities') is-active @endif">
                        {{ __('Activities') }}
                    </button>
                    <button type="button" wire:click="selectTab('time')"
                            class="rk-tabs__tab @if($tab === 'time') is-active @endif">
                        {{ __('Time logged') }}
                    </button>
                    <button type="button" wire:click="selectTab('attachments')"
                            class="rk-tabs__tab @if($tab === 'attachments') is-active @endif">
                        {{ __('Attachments') }}
                    </button>
                </div>
                @if($tab === 'comments')
                    <form wire:submit.prevent="submitComment" class="pb-5">
                        {{ $this->form }}
                        <button type="submit"
                                class="rk-td__submit mt-3">
                            {{ __($selectedCommentId ? 'Edit comment' : 'Add comment') }}
                        </button>
                        @if($selectedCommentId)
                            <button type="button" wire:click="cancelEditComment"
                                    class="rk-td__submit rk-td__submit--warning mt-3">
                                {{ __('Cancel') }}
                            </button>
                        @endif
                    </form>
                    @foreach($record->comments->sortByDesc('created_at') as $comment)
                        <div
                            class="w-full flex gap-3 @if(!$loop->last) pb-5 mb-5 border-b border-gray-100 @endif ticket-comment">
                            <div class="rk-avatar-lg shrink-0">
                                <x-user-avatar :user="$comment->user"/>
                            </div>
                            <div class="min-w-0 flex-1 flex flex-col gap-2">
                                <div class="w-full flex justify-between gap-2">
                                    <span class="flex items-center flex-wrap gap-x-2 text-gray-500 text-sm">
                                        <span class="font-semibold text-gray-900">
                                            {{ $comment->user->name }}
                                        </span>
                                        <span class="text-xs text-gray-400">
                                            {{ $comment->created_at->format('Y-m-d g:i A') }}
                                            ({{ $comment->created_at->diffForHumans() }})
                                        </span>
                                    </span>
                                    @if($this->isAdministrator() || $comment->user_id === auth()->user()->id)
                                        <div class="actions flex items-center gap-2 shrink-0">
                                            <button type="button" wire:click="editComment({{ $comment->id }})"
                                                    class="text-primary-500 text-xs hover:text-primary-600 hover:underline">
                                                {{ __('Edit') }}
                                            </button>
                                            <span class="text-gray-300">|</span>
                                            <button type="button" wire:click="doDeleteComment({{ $comment->id }})"
                                                    data-swal-confirm="{{ __('Are you sure you want to delete this comment?') }}" data-swal-severity="danger"
                                                    class="text-danger-500 text-xs hover:text-danger-600 hover:underline">
                                                {{ __('Delete') }}
                                            </button>
                                        </div>
                                    @endif
                                </div>
                                <div class="w-full prose max-w-none">
                                    {!! \App\Support\Mentions::highlight(\App\Support\HtmlSanitizer::clean($comment->content), $record->watchers) !!}
                                </div>
                            </div>
                        </div>
                    @endforeach
                @endif
                @if($tab === 'activities')
                    <div class="w-full flex flex-col pt-2">
                        @if($record->activities->count())
                            @foreach($record->activities->sortByDesc('created_at') as $activity)
                                <div class="w-full flex flex-col gap-2
                                     @if(!$loop->last) pb-5 mb-5 border-b border-gray-100 @endif">
                                    <span class="flex items-center flex-wrap gap-x-2 text-gray-500 text-sm">
                                        <span class="font-semibold text-gray-900 flex items-center gap-1.5">
                                            @if($activity->user)
                                                <x-user-avatar :user="$activity->user"/>
                                                {{ $activity->user->name }}
                                            @else
                                                {{ __('System') }}
                                            @endif
                                        </span>
                                        <span class="text-xs text-gray-400">
                                            {{ $activity->created_at->format('Y-m-d g:i A') }}
                                            ({{ $activity->created_at->diffForHumans() }})
                                        </span>
                                    </span>
                                    <div class="w-full flex items-center gap-4 text-sm font-medium">
                                        <span class="text-gray-400">{{ $activity->oldStatus->name }}</span>
                                        <x-heroicon-o-arrow-right class="w-4 h-4 text-gray-400"/>
                                        <span style="color: {{ \App\Support\Colors::safe($activity->newStatus->color) }}">
                                            {{ $activity->newStatus->name }}
                                        </span>
                                    </div>
                                </div>
                            @endforeach
                        @else
                            <span class="text-gray-400 text-sm font-medium">
                                {{ __('No activities yet!') }}
                            </span>
                        @endif
                    </div>
                @endif
                @if($tab === 'time')
                    <livewire:timesheet.time-logged :ticket="$record" />
                @endif
                @if($tab === 'attachments')
                    <livewire:ticket.attachments :ticket="$record" />
                @endif
            </x-filament::card>

        </div>

        <aside class="rk-td__side">

            <x-filament::card class="rk-td__card rk-td__details">
                <h3 class="rk-td__card-title">
                    <x-heroicon-o-clipboard-list class="w-5 h-5 text-gray-400"/>
                    {{ __('Ticket details') }}
                </h3>

                <section class="rk-td__group">
                    <h4 class="rk-td__group-title">
                        <x-heroicon-o-users class="w-4 h-4"/> {{ __('Assignment') }}
                    </h4>
                    <div class="rk-td__person" wire:ignore>
                        <span class="rk-td__label">{{ __('Owner') }}</span>
                        <div class="flex items-center gap-2 text-sm text-gray-700">
                            <x-user-avatar :user="$record->owner"/>
                            {{ $record->owner->name }}
                        </div>
                    </div>
                    <div class="rk-td__person" wire:ignore>
                        <span class="rk-td__label">{{ __('Responsible') }}</span>
                        <div class="flex items-center gap-2 text-sm text-gray-700">
                            @if($record->responsible)
                                <x-user-avatar :user="$record->responsible"/>
                            @endif
                            {{ $record->responsible?->name ?? '-' }}
                        </div>
                    </div>
                </section>

                <section class="rk-td__group">
                    <h4 class="rk-td__group-title">
                        <x-heroicon-o-view-boards class="w-4 h-4"/> {{ __('Work') }}
                    </h4>
                    <div class="rk-td__row">
                        <span class="rk-td__label">{{ __('Status') }}</span>
                        <span class="rk-td__value">
                            <x-color-badge pill :color="$record->status->color" :label="$record->status->name"/>
                        </span>
                    </div>
                    <div class="rk-td__row">
                        <span class="rk-td__label">{{ __('Priority') }}</span>
                        <span class="rk-td__value">
                            <x-color-badge pill :color="$record->priority->color" :label="$record->priority->name"/>
                        </span>
                    </div>
                    <div class="rk-td__row">
                        <span class="rk-td__label">{{ __('Project') }}</span>
                        <span class="rk-td__value">{{ $record->project->name }}</span>
                    </div>
                    <div class="rk-td__row">
                        <span class="rk-td__label">{{ __('Type') }}</span>
                        <span class="rk-td__value">
                            <x-color-badge pill :color="$record->type->color" :label="$record->type->name"
                                           :icon="$record->type->icon"/>
                        </span>
                    </div>
                    @if($record->project->type === 'scrum')
                        <div class="rk-td__row">
                            <span class="rk-td__label">{{ __('Sprint') }}</span>
                            <span class="rk-td__value">
                                @if($record->sprint)
                                    <span class="flex flex-col items-end">
                                        {{ $record->sprint->name }}
                                        <span class="text-xs font-normal text-gray-400">
                                            {{ __('Starts at:') }} {{ $record->sprint->starts_at->format(__('Y-m-d')) }} -
                                            {{ __('Ends at:') }} {{ $record->sprint->ends_at->format(__('Y-m-d')) }}
                                        </span>
                                    </span>
                                @else
                                    -
                                @endif
                            </span>
                        </div>
                    @else
                        <div class="rk-td__row">
                            <span class="rk-td__label">{{ __('Epic') }}</span>
                            <span class="rk-td__value">
                                @if($record->epic)
                                    {{ $record->epic->name }}
                                @else
                                    -
                                @endif
                            </span>
                        </div>
                    @endif
                </section>

                <section class="rk-td__group">
                    <h4 class="rk-td__group-title">
                        <x-heroicon-o-calendar class="w-4 h-4"/> {{ __('Schedule') }}
                    </h4>
                    <div class="rk-td__row">
                        <span class="rk-td__label">{{ __('Due date') }}</span>
                        <span @class(['rk-td__value', 'text-danger-600' => $record->due_date && $record->isOverdue])>
                            {{ $record->due_date ? $record->due_date->format(__('Y-m-d')) : '-' }}
                        </span>
                    </div>
                    <div class="rk-td__row">
                        <span class="rk-td__label">{{ __('Estimation') }}</span>
                        <span class="rk-td__value">
                            @if($record->estimation)
                                {{ $record->estimationForHumans }}
                            @else
                                -
                            @endif
                        </span>
                    </div>
                    @php($hasHours = $record->hours()->count())
                    <div class="flex flex-col gap-1.5">
                        <div class="rk-td__row">
                            <span class="rk-td__label">{{ __('Total time logged') }}</span>
                            @if($hasHours)
                                <span @class([
                                        'rk-td__value',
                                        'text-danger-600' => $record->estimation && $record->estimationProgress > 100,
                                    ])>
                                    {{ $record->totalLoggedHours }}
                                    @if($record->estimation)
                                        ({{ round($record->estimationProgress) }}%)
                                    @endif
                                </span>
                            @else
                                <span class="rk-td__value">-</span>
                            @endif
                        </div>
                        @if($hasHours && $record->estimation)
                            <div class="w-full bg-gray-100 rounded-full h-2">
                                <div @class([
                                        'h-2 rounded-full',
                                        'bg-danger-600' => $record->estimationProgress > 100,
                                        'bg-primary-600' => $record->estimationProgress <= 100,
                                    ])
                                     style="width: {{ $record->estimationProgress > 100 ?
                                                        100
                                                        : $record->estimationProgress }}%">
                                </div>
                            </div>
                        @endif
                    </div>
                </section>

                @if($record->labels->count())
                    <section class="rk-td__group">
                        <h4 class="rk-td__group-title">
                            <x-heroicon-o-tag class="w-4 h-4"/> {{ __('Labels') }}
                        </h4>
                        <div class="flex items-center flex-wrap gap-2">
                            @foreach($record->labels as $label)
                                <x-color-badge pill :color="$label->color" :label="$label->name"/>
                            @endforeach
                        </div>
                    </section>
                @endif

                <section class="rk-td__group">
                    <h4 class="rk-td__group-title">
                        <x-heroicon-o-information-circle class="w-4 h-4"/> {{ __('Other') }}
                    </h4>
                    <div class="rk-td__row">
                        <span class="rk-td__label">{{ __('Subscribers') }}</span>
                        <span class="rk-td__value flex items-center gap-1">
                            @if($record->subscribers->count())
                                @foreach($record->subscribers as $subscriber)
                                    <x-user-avatar :user="$subscriber"/>
                                @endforeach
                            @else
                                {{ '-' }}
                            @endif
                        </span>
                    </div>
                    <div class="rk-td__row">
                        <span class="rk-td__label">{{ __('Creation date') }}</span>
                        <span class="rk-td__value">
                            {{ $record->created_at->format(__('Y-m-d g:i A')) }}
                            <span class="block text-xs font-normal text-gray-400">
                                ({{ $record->created_at->diffForHumans() }})
                            </span>
                        </span>
                    </div>
                    <div class="rk-td__row">
                        <span class="rk-td__label">{{ __('Last update') }}</span>
                        <span class="rk-td__value">
                            {{ $record->updated_at->format(__('Y-m-d g:i A')) }}
                            <span class="block text-xs font-normal text-gray-400">
                                ({{ $record->updated_at->diffForHumans() }})
                            </span>
                        </span>
                    </div>
                </section>
            </x-filament::card>

            @if($record->relations->count())
                <x-filament::card class="rk-td__card">
                    <h3 class="rk-td__card-title">
                        <x-heroicon-o-link class="w-5 h-5 text-gray-400"/>
                        {{ __('Ticket relations') }}
                    </h3>
                    <div class="w-full flex flex-col gap-2 text-gray-500">
                        @foreach($record->relations as $relation)
                            <div class="w-full flex items-center gap-2 text-xs">
                                <span class="rounded-full px-2.5 py-1 text-white
                                             bg-{{ config('system.tickets.relations.colors.' . $relation->type) }}-600">
                                    {{ __(config('system.tickets.relations.list.' . $relation->type)) }}
                                </span>
                                <a target="_blank" class="text-sm font-medium text-primary-600 hover:underline"
                                   href="{{ route('filament.resources.tickets.share', $relation->relation->code) }}">
                                    {{ $relation->relation->code }}
                                </a>
                            </div>
                        @endforeach
                    </div>
                </x-filament::card>
            @endif

        </aside>

    </div>

</x-filament::page>

@push('scripts')
    <script>
        window.addEventListener('shareTicket', (e) => {
            const text = e.detail.url;
            const textArea = document.createElement("textarea");
            textArea.value = text;
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();
            try {
                document.execCommand('copy');
            } catch (err) {
                console.error('Unable to copy to clipboard', err);
            }
            document.body.removeChild(textArea);
            new Notification()
                .success()
                .title('{{ __('Url copied to clipboard') }}')
                .duration(6000)
                .send()
        });
    </script>

    {{-- @mention autocomplete for the comment editor (Trix). Additive and
         self-contained: it degrades to a plain editor if anything is missing. --}}
    <script>
        (function () {
            // Each member carries its id so a pick is resolved unambiguously
            // even when two members share the exact same display name.
            const members = @json($record->watchers->filter(fn ($u) => $u->name)->unique('id')->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values());
            if (! members.length) return;

            let box = null, items = [], sel = 0, active = null;

            function close() {
                if (box) { box.remove(); box = null; }
                items = []; sel = 0;
            }

            function query(editor) {
                const range = editor.getSelectedRange();
                if (! range || range[0] !== range[1]) return null;
                const caret = range[0];
                const before = editor.getDocument().toString().slice(0, caret);
                const m = before.match(/@([^@\n]*)$/);
                if (! m) return null;
                return { text: m[1], at: caret - m[1].length - 1, caret: caret };
            }

            function paint() {
                if (! box) return;
                Array.from(box.children).forEach((c, i) => {
                    c.style.background = i === sel ? '#eef2ff' : 'transparent';
                });
            }

            function pick(editor, q, member) {
                editor.setSelectedRange([q.at, q.caret]);
                // The "#id" suffix makes this pick resolve unambiguously
                // server-side even if another member shares the same name;
                // it is never shown to users (stripped on display and on edit).
                editor.insertString('@' + member.name + '#' + member.id + ' ');
                close();
            }

            function open(editor, q) {
                const needle = q.text.toLowerCase();
                items = members.filter(m => m.name.toLowerCase().startsWith(needle)).slice(0, 8);
                if (! items.length) { close(); return; }
                sel = 0;

                if (! box) {
                    box = document.createElement('div');
                    box.style.cssText = 'position:absolute;z-index:9999;min-width:180px;max-height:220px;overflow:auto;background:#fff;border:1px solid #e5e7eb;border-radius:.5rem;box-shadow:0 10px 25px rgba(0,0,0,.12);padding:.25rem;';
                    document.body.appendChild(box);
                }
                box.innerHTML = '';
                items.forEach((member) => {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.textContent = '@' + member.name;
                    btn.style.cssText = 'display:block;width:100%;text-align:left;padding:.375rem .5rem;border-radius:.375rem;font-size:.875rem;cursor:pointer;background:transparent;border:none;color:#111827;';
                    btn.addEventListener('mousedown', (ev) => { ev.preventDefault(); pick(editor, q, member); });
                    box.appendChild(btn);
                });
                paint();

                const rect = editor.getClientRectAtPosition(q.caret) || active.getBoundingClientRect();
                box.style.left = (window.scrollX + rect.left) + 'px';
                box.style.top = (window.scrollY + rect.bottom + 4) + 'px';
            }

            document.addEventListener('input', (e) => {
                const el = e.target.closest && e.target.closest('trix-editor');
                if (! el || ! el.editor) return;
                active = el;
                const q = query(el.editor);
                q ? open(el.editor, q) : close();
            });

            document.addEventListener('keydown', (e) => {
                if (! box || ! items.length) return;
                const el = e.target.closest && e.target.closest('trix-editor');
                if (! el || ! el.editor) return;
                if (e.key === 'ArrowDown') { e.preventDefault(); sel = (sel + 1) % items.length; paint(); }
                else if (e.key === 'ArrowUp') { e.preventDefault(); sel = (sel - 1 + items.length) % items.length; paint(); }
                else if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); pick(el.editor, query(el.editor), items[sel]); }
                else if (e.key === 'Escape') { close(); }
            });

            document.addEventListener('click', (e) => {
                if (box && ! box.contains(e.target)) close();
            });
        })();
    </script>
@endpush
