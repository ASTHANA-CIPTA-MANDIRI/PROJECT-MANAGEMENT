{{-- Permission checklist grouped by module (Tickets, Projects, ...). Same contract as
     Filament's own checkbox list: every box is `wire:model` on the field's state path
     with the permission id as its value, so saving, validation and the relationship
     sync are untouched. Only the layout, the search and the per-group toggle are new. --}}
@php
    $verbs = ['List', 'View', 'Create', 'Update', 'Delete', 'Manage', 'Import from'];
    $special = ['analytics' => 'Analytics', 'timesheet data' => 'Timesheet', 'timesheet dashboard' => 'Timesheet', 'jira' => 'Jira', 'general settings' => 'Settings', 'super admin settings' => 'Settings'];

    $groups = [];
    foreach ($getOptions() as $id => $name) {
        $verb = collect($verbs)->first(fn ($v) => str_starts_with($name, $v.' '));
        $rest = $verb ? trim(substr($name, strlen($verb))) : $name;
        $key = $special[strtolower($rest)] ?? ucfirst(\Illuminate\Support\Str::singular($rest));
        $groups[$key][] = ['id' => (string) $id, 'name' => $name, 'verb' => $verb ?? ''];
    }
    ksort($groups);
@endphp

<x-dynamic-component
    :component="$getFieldWrapperView()"
    :id="$getId()"
    :label="$getLabel()"
    :label-sr-only="$isLabelHidden()"
    has-nested-recursive-validation-rules
    :helper-text="$getHelperText()"
    :hint="$getHint()"
    :hint-action="$getHintAction()"
    :hint-color="$getHintColor()"
    :required="$isRequired()"
    :state-path="$getStatePath()"
>
    <div
        class="rk-pg"
        x-data="{
            search: '',
            path: @js($getStatePath()),
            groups: @js(collect($groups)->map(fn ($rows) => collect($rows)->pluck('id')->all())->all()),
            selected: [],
            sync() { this.selected = Array.from(this.$root.querySelectorAll('input[type=checkbox]:checked')).map((el) => el.value) },
            current() { return this.selected },
            count(key) { return this.groups[key].filter((id) => this.selected.includes(id)).length },
            all(key) { return this.count(key) === this.groups[key].length },
            toggle(key) {
                const c = this.current();
                const ids = this.groups[key];
                this.push(this.all(key) ? c.filter((id) => ! ids.includes(id)) : [...new Set([...c, ...ids])]);
            },
            push(next) { this.selected = next; this.$wire.set(this.path, next) },
            toggleAll() {
                const every = Object.keys(this.groups).every((key) => this.all(key));
                this.push(every ? [] : Object.values(this.groups).flat());
            },
            hit(text) { return text.toLowerCase().includes(this.search.toLowerCase()) },
        }"
        x-init="sync(); Livewire.hook('message.processed', () => $nextTick(() => sync()))"
        x-on:change="sync()"
    >
        @unless ($isDisabled())
            <div class="rk-pg__bar">
                <input type="search" x-model.debounce.150ms="search" placeholder="{{ __('Search') }}" />
                <label class="rk-pg__check">
                    <input type="checkbox" x-ref="master" x-bind:checked="Object.keys(groups).every((key) => all(key))" x-effect="$refs.master.indeterminate = selected.length > 0 && ! Object.keys(groups).every((key) => all(key))" x-on:click.prevent="toggleAll()" />
                    <span>{{ __('Check all') }}</span>
                    <small x-text="selected.length + ' / ' + Object.values(groups).flat().length"></small>
                </label>
            </div>
        @endunless

        <div class="rk-pg__grid">
            @foreach ($groups as $key => $rows)
                <section
                    class="rk-pg__group"
                    wire:key="{{ $this->id }}.{{ $getStatePath() }}.group.{{ $key }}"
                    x-show="hit(@js(strtolower(__($key)).' '.strtolower($key))) || @js(collect($rows)->pluck('name')->map('strtolower')->all()).some((n) => hit(n))"
                >
                    <header>
                        @unless ($isDisabled())
                            <input type="checkbox" class="rk-pg__gcheck" x-bind:checked="all(@js($key))" x-effect="$el.indeterminate = count(@js($key)) > 0 && ! all(@js($key))" x-on:click.prevent="toggle(@js($key))" title="{{ __('Check all') }}" />
                        @endunless
                        <b>{{ __($key) }}</b>
                        <span class="rk-pg__count" x-text="count(@js($key)) + '/' + groups[@js($key)].length"></span>
                    </header>

                    <div class="rk-pg__rows">
                        @foreach ($rows as $row)
                            <label
                                class="rk-pg__row"
                                wire:key="{{ $this->id }}.{{ $getStatePath() }}.option.{{ $row['id'] }}"
                                x-show="hit(@js(strtolower($row['name']).' '.strtolower(__($key))))"
                            >
                                <input
                                    type="checkbox"
                                    value="{{ $row['id'] }}"
                                    wire:loading.attr="disabled"
                                    {{ $applyStateBindingModifiers('wire:model') }}="{{ $getStatePath() }}"
                                    @disabled($isDisabled())
                                    class="rounded border-gray-300 text-primary-600 shadow-sm focus:border-primary-500 focus:ring-2 focus:ring-primary-500 disabled:opacity-70"
                                />
                                <span title="{{ $row['name'] }}">{{ in_array($row['verb'], ['', 'Manage', 'Import from'], true) ? $row['name'] : __($row['verb']) }}</span>
                            </label>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>

        <p class="rk-pg__empty" x-cloak x-show="search !== '' && ! Array.from($root.querySelectorAll('.rk-pg__group')).some((el) => el.style.display !== 'none')">{{ __('No results') }}</p>
    </div>
</x-dynamic-component>
