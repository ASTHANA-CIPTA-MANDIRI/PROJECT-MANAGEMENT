@php($record = $this->record)
<x-filament::page>

    <a href="{{ route('filament.pages.kanban/{project}', ['project' => $record->project->id]) }}"
       class="flex items-center gap-1 text-gray-500 hover:text-gray-700 font-medium text-xs">
        <x-heroicon-o-arrow-left class="w-4 h-4"/> {{ __('Back to kanban board') }}
    </a>

    <div class="w-full flex md:flex-row flex-col gap-5">

        <x-filament::card class="md:w-2/3 w-full flex flex-col gap-5">
            <div class="w-full flex flex-col gap-0">
                <div class="flex items-center gap-2">
                    <span class="flex items-center gap-1 text-sm text-primary-500 font-medium">
                        <x-heroicon-o-ticket class="w-4 h-4"/>
                        {{ $record->code }}
                    </span>
                    <span class="text-sm text-gray-400 font-light">|</span>
                    <span class="flex items-center gap-1 text-sm text-gray-500">
                        {{ $record->project->name }}
                    </span>
                </div>
                <span class="text-xl text-gray-700">
                    {{ $record->name }}
                </span>
            </div>
            <div class="w-full flex items-center gap-2">
                <div class="px-2 py-1 rounded flex items-center justify-center text-center text-xs text-white"
                     style="background-color: {{ \App\Support\Colors::safe($record->status->color) }};">
                    {{ $record->status->name }}
                </div>
                <div class="px-2 py-1 rounded flex items-center justify-center text-center text-xs text-white"
                     style="background-color: {{ \App\Support\Colors::safe($record->priority->color) }};">
                    {{ $record->priority->name }}
                </div>
                <div class="px-2 py-1 rounded flex items-center justify-center text-center text-xs text-white"
                     style="background-color: {{ \App\Support\Colors::safe($record->type->color) }};">
                    <x-icon class="h-3 text-white" name="{{ $record->type->icon }}"/>
                    <span class="ml-2">
                        {{ $record->type->name }}
                    </span>
                </div>
                @if($record->due_date)
                    <div @class([
                            'px-2 py-1 rounded flex items-center gap-1 text-center text-xs text-white',
                            'bg-danger-500' => $record->isOverdue,
                            'bg-gray-400' => ! $record->isOverdue,
                        ])>
                        <x-heroicon-o-calendar class="w-3 h-3"/>
                        {{ __('Due') }} {{ $record->due_date->format(__('Y-m-d')) }}
                        @if($record->isOverdue)
                            ({{ __('Overdue') }})
                        @endif
                    </div>
                @endif
            </div>
            @if($record->labels->count())
                <div class="w-full flex items-center flex-wrap gap-2">
                    @foreach($record->labels as $label)
                        <span class="px-2 py-1 rounded-full text-xs text-white"
                              style="background-color: {{ \App\Support\Colors::safe($label->color) }};">
                            {{ $label->name }}
                        </span>
                    @endforeach
                </div>
            @endif
            <div class="w-full flex flex-col gap-0 pt-5">
                <span class="text-gray-500 text-sm font-medium">
                    {{ __('Content') }}
                </span>
                <div class="w-full prose">
                    {!! \App\Support\HtmlSanitizer::clean($record->content) !!}
                </div>
            </div>
        </x-filament::card>

        <x-filament::card class="md:w-1/3 w-full flex flex-col">
            <div class="w-full flex flex-col gap-1" wire:ignore>
                <span class="text-gray-500 text-sm font-medium">
                    {{ __('Owner') }}
                </span>
                <div class="w-full flex items-center gap-1 text-gray-500">
                    <x-user-avatar :user="$record->owner"/>
                    {{ $record->owner->name }}
                </div>
            </div>

            <div class="w-full flex flex-col gap-1 pt-3" wire:ignore>
                <span class="text-gray-500 text-sm font-medium">
                    {{ __('Responsible') }}
                </span>
                <div class="w-full flex items-center gap-1 text-gray-500">
                    @if($record->responsible)
                        <x-user-avatar :user="$record->responsible"/>
                    @endif
                    {{ $record->responsible?->name ?? '-' }}
                </div>
            </div>

            @if($record->project->type === 'scrum')
                <div class="w-full flex flex-col gap-1 pt-3">
                    <span class="text-gray-500 text-sm font-medium">
                        {{ __('Sprint') }}
                    </span>
                    <div class="w-full flex flex-col justify-center gap-1 text-gray-500">
                        @if($record->sprint)
                            {{ $record->sprint->name }}
                            <span class="text-xs text-gray-400">
                                {{ __('Starts at:') }} {{ $record->sprint->starts_at->format(__('Y-m-d')) }} -
                                {{ __('Ends at:') }} {{ $record->sprint->ends_at->format(__('Y-m-d')) }}
                            </span>
                        @else
                            -
                        @endif
                    </div>
                </div>
            @else
                <div class="w-full flex flex-col gap-1 pt-3">
                    <span class="text-gray-500 text-sm font-medium">
                        {{ __('Epic') }}
                    </span>
                    <div class="w-full flex items-center gap-1 text-gray-500">
                        @if($record->epic)
                            {{ $record->epic->name }}
                        @else
                            -
                        @endif
                    </div>
                </div>
            @endif

            <div class="w-full flex flex-col gap-1 pt-3">
                <span class="text-gray-500 text-sm font-medium">
                    {{ __('Estimation') }}
                </span>
                <div class="w-full flex items-center gap-1 text-gray-500">
                    @if($record->estimation)
                        {{ $record->estimationForHumans }}
                    @else
                        -
                    @endif
                </div>
            </div>

            <div class="w-full flex flex-col gap-1 pt-3">
                <span class="text-gray-500 text-sm font-medium">
                    {{ __('Total time logged') }}
                </span>
                @if($record->hours()->count())
                    @if($record->estimation)
                        <div class="flex justify-between mb-1">
                            <span class="text-base font-medium
                                         text-{{ $record->estimationProgress > 100 ? 'danger' : 'primary' }}-700
                                         dark:text-white">
                                {{ $record->totalLoggedHours }}
                            </span>
                            <span class="text-sm font-medium
                                         text-{{ $record->estimationProgress > 100 ? 'danger' : 'primary' }}-700
                                         dark:text-white">
                            {{ round($record->estimationProgress) }}%
                        </span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2.5 dark:bg-gray-700">
                            <div class="bg-{{ $record->estimationProgress > 100 ? 'danger' : 'primary' }}-600
                                        h-2.5 rounded-full"
                                 style="width: {{ $record->estimationProgress > 100 ?
                                                    100
                                                    : $record->estimationProgress }}%">
                            </div>
                        </div>
                    @else
                        <div class="w-full flex items-center gap-1 text-gray-500">
                            {{ $record->totalLoggedHours }}
                        </div>
                    @endif
                @else
                    -
                @endif
            </div>

            <div class="w-full flex flex-col gap-1 pt-3">
                <span class="text-gray-500 text-sm font-medium">
                    {{ __('Subscribers') }}
                </span>
                <div class="w-full flex items-center gap-1 text-gray-500">
                    @if($record->subscribers->count())
                        @foreach($record->subscribers as $subscriber)
                            <x-user-avatar :user="$subscriber"/>
                        @endforeach
                    @else
                        {{ '-' }}
                    @endif
                </div>
            </div>

            <div class="w-full flex flex-col gap-1 pt-3">
                <span class="text-gray-500 text-sm font-medium">
                    {{ __('Creation date') }}
                </span>
                <div class="w-full text-gray-500">
                    {{ $record->created_at->format(__('Y-m-d g:i A')) }}
                    <span class="text-xs text-gray-400">
                        ({{ $record->created_at->diffForHumans() }})
                    </span>
                </div>
            </div>

            <div class="w-full flex flex-col gap-1 pt-3">
                <span class="text-gray-500 text-sm font-medium">
                    {{ __('Last update') }}
                </span>
                <div class="w-full text-gray-500">
                    {{ $record->updated_at->format(__('Y-m-d g:i A')) }}
                    <span class="text-xs text-gray-400">
                        ({{ $record->updated_at->diffForHumans() }})
                    </span>
                </div>
            </div>

            @if($record->relations->count())
                <div class="w-full flex flex-col gap-1 pt-3">
                    <span class="text-gray-500 text-sm font-medium">
                        {{ __('Ticket relations') }}
                    </span>
                    <div class="w-full text-gray-500">
                        @foreach($record->relations as $relation)
                            <div class="w-full flex items-center gap-1 text-xs">
                                <span class="rounded px-2 py-1 text-white
                                             bg-{{ config('system.tickets.relations.colors.' . $relation->type) }}-600">
                                    {{ __(config('system.tickets.relations.list.' . $relation->type)) }}
                                </span>
                                <a target="_blank" class="font-medium hover:underline"
                                   href="{{ route('filament.resources.tickets.share', $relation->relation->code) }}">
                                    {{ $relation->relation->code }}
                                </a>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </x-filament::card>

    </div>

    <div class="w-full flex md:flex-row flex-col gap-5">

        <x-filament::card class="md:w-2/3 w-full flex flex-col">
            <div class="w-full flex items-center gap-2">
                <button wire:click="selectTab('comments')"
                        class="md:text-xl text-sm p-3 border-b-2 border-transparent hover:border-primary-500 flex items-center
                        gap-1 @if($tab === 'comments') border-primary-500 text-primary-500 @else text-gray-700 @endif">
                    {{ __('Comments') }}
                </button>
                <button wire:click="selectTab('activities')"
                        class="md:text-xl text-sm p-3 border-b-2 border-transparent hover:border-primary-500
                        @if($tab === 'activities') border-primary-500 text-primary-500 @else text-gray-700 @endif">
                    {{ __('Activities') }}
                </button>
                <button wire:click="selectTab('time')"
                        class="md:text-xl text-sm p-3 border-b-2 border-transparent hover:border-primary-500
                        @if($tab === 'time') border-primary-500 text-primary-500 @else text-gray-700 @endif">
                    {{ __('Time logged') }}
                </button>
                <button wire:click="selectTab('attachments')"
                        class="md:text-xl text-sm p-3 border-b-2 border-transparent hover:border-primary-500
                        @if($tab === 'attachments') border-primary-500 text-primary-500 @else text-gray-700 @endif">
                    {{ __('Attachments') }}
                </button>
            </div>
            @if($tab === 'comments')
                <form wire:submit.prevent="submitComment" class="pb-5">
                    {{ $this->form }}
                    <button type="submit"
                            class="px-3 py-2 bg-primary-500 hover:bg-primary-600 text-white rounded mt-3">
                        {{ __($selectedCommentId ? 'Edit comment' : 'Add comment') }}
                    </button>
                    @if($selectedCommentId)
                        <button type="button" wire:click="cancelEditComment"
                                class="px-3 py-2 bg-warning-500 hover:bg-warning-600 text-white rounded mt-3">
                            {{ __('Cancel') }}
                        </button>
                    @endif
                </form>
                @foreach($record->comments->sortByDesc('created_at') as $comment)
                    <div
                        class="w-full flex flex-col gap-2 @if(!$loop->last) pb-5 mb-5 border-b border-gray-200 @endif ticket-comment">
                        <div class="w-full flex justify-between">
                            <span class="flex items-center gap-1 text-gray-500 text-sm">
                                <span class="font-medium flex items-center gap-1">
                                    <x-user-avatar :user="$comment->user"/>
                                    {{ $comment->user->name }}
                                </span>
                                <span class="text-gray-400 px-2">|</span>
                                {{ $comment->created_at->format('Y-m-d g:i A') }}
                                ({{ $comment->created_at->diffForHumans() }})
                            </span>
                            @if($this->isAdministrator() || $comment->user_id === auth()->user()->id)
                                <div class="actions flex items-center gap-2">
                                    <button type="button" wire:click="editComment({{ $comment->id }})"
                                            class="text-primary-500 text-xs hover:text-primary-600 hover:underline">
                                        {{ __('Edit') }}
                                    </button>
                                    <span class="text-gray-300">|</span>
                                    <button type="button" wire:click="deleteComment({{ $comment->id }})"
                                            class="text-danger-500 text-xs hover:text-danger-600 hover:underline">
                                        {{ __('Delete') }}
                                    </button>
                                </div>
                            @endif
                        </div>
                        <div class="w-full prose">
                            {!! \App\Support\Mentions::highlight(\App\Support\HtmlSanitizer::clean($comment->content), $record->watchers) !!}
                        </div>
                    </div>
                @endforeach
            @endif
            @if($tab === 'activities')
                <div class="w-full flex flex-col pt-5">
                    @if($record->activities->count())
                        @foreach($record->activities->sortByDesc('created_at') as $activity)
                            <div class="w-full flex flex-col gap-2
                                 @if(!$loop->last) pb-5 mb-5 border-b border-gray-200 @endif">
                                <span class="flex items-center gap-1 text-gray-500 text-sm">
                                    <span class="font-medium flex items-center gap-1">
                                        @if($activity->user)
                                            <x-user-avatar :user="$activity->user"/>
                                            {{ $activity->user->name }}
                                        @else
                                            {{ __('System') }}
                                        @endif
                                    </span>
                                    <span class="text-gray-400 px-2">|</span>
                                    {{ $activity->created_at->format('Y-m-d g:i A') }}
                                    ({{ $activity->created_at->diffForHumans() }})
                                </span>
                                <div class="w-full flex items-center gap-10">
                                    <span class="text-gray-400">{{ $activity->oldStatus->name }}</span>
                                    <x-heroicon-o-arrow-right class="w-6 h-6"/>
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

        <div class="md:w-1/3 w-full flex flex-col"></div>

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
