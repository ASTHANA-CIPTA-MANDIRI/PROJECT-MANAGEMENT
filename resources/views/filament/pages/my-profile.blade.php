<x-filament::page>
    @php
        $me = \Filament\Facades\Filament::auth()->user();
        $organization = \App\Support\OrganizationContext::current($me);
        $feedLocked = \App\Models\ProfilePost::isLockedFor($me);
        $feed = $this->getFeed();
        $team = $this->getTeam();
        $projects = $this->getMyProjects();
        $myRole = $me->getRoleNames()->first();
        $tints = ['blue', 'green', 'red', 'yellow'];
    @endphp

    <div class="rk-pf"
         x-data="{
             tab: 'profile',
             sub: 'feed',
             tabs: { '#tim': 'team', '#proyek': 'projects', '#pengaturan': 'settings' },
             hashes: { team: '#tim', projects: '#proyek', settings: '#pengaturan' },
             init() {
                 this.tab = this.tabs[location.hash] ?? 'profile';
                 if (location.hash.startsWith('#post-')) { this.$nextTick(() => document.querySelector(location.hash)?.scrollIntoView({ block: 'center' })); }
             },
             go(t) { this.tab = t; history.replaceState(null, '', this.hashes[t] ?? location.pathname); },
             share(id) {
                 navigator.clipboard?.writeText(location.origin + location.pathname + '#post-' + id);
                 window.RencanakanAlert.toast({ severity: 'success', title: @js(__('Link copied')), timer: 2500 });
             },
             confirmDelete(kind, id) {
                 const t = @js([
                     'post' => ['title' => __('Delete this post?'), 'text' => __('The post, its comments and likes will be removed for good.')],
                     'comment' => ['title' => __('Delete this comment?'), 'text' => __('This comment will be removed for good.')],
                     'yes' => __('Yes, delete'),
                     'no' => __('Cancel'),
                 ]);
                 window.RencanakanAlert.confirm({ severity: 'danger', title: t[kind].title, text: t[kind].text, confirmText: t.yes, cancelText: t.no })
                     .then((ok) => { if (ok) { kind === 'post' ? this.$wire.deletePost(id) : this.$wire.deleteComment(id); } });
             },
         }">
        <div class="rk-pf__banner"></div>
        <div class="rk-pf__card">
            <img src="{{ \Filament\Facades\Filament::getUserAvatarUrl($me) }}" alt="{{ $me->name }}" class="rk-pf__avatar">
            <div class="rk-pf__who">
                <h2>{{ $me->name }} @if ($myRole)<span class="rk-pf__badge">{{ $myRole }}</span>@endif</h2>
                <p><x-heroicon-o-mail class="h-4 w-4" /> {{ $me->email }}</p>
                <p class="rk-pf__status"><i></i> {{ __('Active') }}</p>
            </div>
            <button type="button" class="rk-pf__edit" @click="go('settings'); window.scrollTo({ top: 0 })">{{ __('Edit profile') }}</button>
            <nav class="rk-pf__tabs">
                <button type="button" class="rk-pf__tab" :class="{ 'is-active': tab === 'profile' }" @click="go('profile')"><x-heroicon-o-user-circle class="h-5 w-5" /> {{ __('My profile') }}</button>
                <button type="button" class="rk-pf__tab" :class="{ 'is-active': tab === 'team' }" @click="go('team')"><x-heroicon-o-users class="h-5 w-5" /> {{ __('Team') }}</button>
                <button type="button" class="rk-pf__tab" :class="{ 'is-active': tab === 'projects' }" @click="go('projects')"><x-heroicon-o-collection class="h-5 w-5" /> {{ __('Projects') }}</button>
                <button type="button" class="rk-pf__tab" :class="{ 'is-active': tab === 'settings' }" @click="go('settings')"><x-heroicon-o-cog class="h-5 w-5" /> {{ __('Account settings') }}</button>
            </nav>
        </div>

        {{-- Tab: My profile --}}
        <div class="rk-pf__grid" x-show="tab === 'profile'" :data-sub="sub">
            {{-- Phones only: About/Contact/Team and the feed become two tabs. Desktop shows both columns. --}}
            <div class="rk-pf__subtabs">
                <button type="button" :class="{ 'is-active': sub === 'feed' }" @click="sub = 'feed'"><x-heroicon-o-chat-alt-2 class="h-5 w-5" /> {{ __('Posts') }}</button>
                <button type="button" :class="{ 'is-active': sub === 'about' }" @click="sub = 'about'"><x-heroicon-o-identification class="h-5 w-5" /> {{ __('About me') }}</button>
            </div>
            <div class="rk-pf__col rk-pf__col--about">
                <section class="rk-pf__panel">
                    <h3>{{ __('About me') }}</h3>
                    <p class="rk-pf__about">{{ __('Hello, I\'m :name. I work as :role in :org.', ['name' => $me->name, 'role' => $myRole ?: __('a member'), 'org' => $organization?->name ?? config('app.name')]) }}</p>
                    <hr>
                    <h4>{{ __('Contact') }}</h4>
                    <div class="rk-pf__row"><span class="rk-pf__tile rk-pf__tile--green"><x-heroicon-o-mail class="h-5 w-5" /></span><div><b>{{ __('Email') }}</b><small>{{ $me->email }}</small></div></div>
                    <hr>
                    <h4>{{ __('Other') }}</h4>
                    <div class="rk-pf__row"><span class="rk-pf__tile rk-pf__tile--blue"><x-heroicon-o-office-building class="h-5 w-5" /></span><div><b>{{ __('Organization') }}</b><small>{{ $organization?->name ?? '-' }}</small></div></div>
                    <div class="rk-pf__row"><span class="rk-pf__tile rk-pf__tile--yellow"><x-heroicon-o-calendar class="h-5 w-5" /></span><div><b>{{ __('Joined') }}</b><small>{{ $me->created_at?->translatedFormat('d F Y') }}</small></div></div>
                    <div class="rk-pf__row"><span class="rk-pf__tile rk-pf__tile--gray"><x-heroicon-o-translate class="h-5 w-5" /></span><div><b>{{ __('Language') }}</b><small>{{ \Locale::getDisplayName(app()->getLocale(), app()->getLocale()) ?: app()->getLocale() }}</small></div></div>
                </section>

                <section class="rk-pf__panel">
                    <h3>{{ __('Team') }}</h3>
                    @foreach ($team->take(4) as $member)
                        <div class="rk-pf__row">
                            <img src="{{ \Filament\Facades\Filament::getUserAvatarUrl($member) }}" alt="" class="rk-pf__mini">
                            <div><b>{{ $member->name }}</b><small>{{ $member->email }}</small></div>
                        </div>
                    @endforeach
                    <button type="button" class="rk-pf__more" @click="go('team')">{{ __('View all') }}</button>
                </section>
            </div>

            <div class="rk-pf__col rk-pf__col--feed">
                <div class="rk-pf__stats">
                    <div class="rk-pf__stat rk-pf__stat--blue"><span><x-heroicon-o-collection class="h-6 w-6" /></span><div><b>{{ $me->projects_count }}</b><small>{{ __('Projects') }}</small></div></div>
                    <div class="rk-pf__stat rk-pf__stat--green"><span><x-heroicon-o-clipboard-check class="h-6 w-6" /></span><div><b>{{ $me->tickets_count }}</b><small>{{ __('Tickets') }}</small></div></div>
                    <div class="rk-pf__stat rk-pf__stat--red"><span><x-heroicon-o-clock class="h-6 w-6" /></span><div><b>{{ $me->total_logged_in_hours }}</b><small>{{ __('Logged hours') }}</small></div></div>
                </div>

                @if ($feedLocked)
                    <div class="rk-notice rk-notice--warning">
                        <span class="font-normal">{{ __('Your organization\'s trial has ended. Contact the organization Owner to subscribe.') }}</span>
                    </div>
                @else
                {{-- Composer --}}
                <section class="rk-pf__panel">
                    <div class="rk-pf__feedtabs">
                        <button type="button" class="{{ $feedFilter === 'feeds' ? 'is-active' : '' }}" wire:click="$set('feedFilter', 'feeds')">{{ __('Feeds') }}</button>
                        <button type="button" class="{{ $feedFilter === 'mine' ? 'is-active' : '' }}" wire:click="$set('feedFilter', 'mine')">{{ __('Timeline') }}</button>
                    </div>
                    <form wire:submit.prevent="createPost" class="rk-pf__composer" x-data="{
                        emoji: false,
                        box() { return this.$refs.body; },
                        sync() { this.box().dispatchEvent(new Event('input', { bubbles: true })); this.box().focus(); },
                        wrap(a, b) {
                            const t = this.box(), s = t.selectionStart, e = t.selectionEnd;
                            t.setRangeText(a + t.value.slice(s, e) + b, s, e, 'end');
                            this.sync();
                        },
                        prefix(p) {
                            const t = this.box(), e = t.selectionEnd, s = t.value.lastIndexOf('\n', t.selectionStart - 1) + 1;
                            const lines = t.value.slice(s, e).split('\n').map((l, i) => p.replace('#', i + 1) + l);
                            t.setRangeText(lines.join('\n'), s, e, 'end');
                            this.sync();
                        },
                        link() {
                            const box = this.box(), s = box.selectionStart, e = box.selectionEnd;
                            window.RencanakanAlert.prompt({
                                severity: 'info',
                                input: 'text',
                                title: @js(__('Link')),
                                label: 'URL',
                                placeholder: 'https://...',
                                confirmText: @js(__('Add link')),
                                cancelText: @js(__('No, cancel')),
                                maxLength: 500,
                                validate: (v) => /^https?:\/\//i.test(v) ? undefined : @js(__('Enter a URL starting with http:// or https://')),
                            }).then((u) => { if (u) { box.setRangeText(u, s, e, 'end'); this.sync(); } });
                        },
                        put(c) { this.box().setRangeText(c, this.box().selectionStart, this.box().selectionEnd, 'end'); this.emoji = false; this.sync(); },
                    }">
                        <div class="rk-pf__box">
                            <div class="rk-pf__author">
                                <img src="{{ \Filament\Facades\Filament::getUserAvatarUrl($me) }}" alt="" class="rk-pf__mini">
                                <b>{{ $me->name }}</b>
                            </div>
                            <textarea x-ref="body" wire:model.defer="postBody" rows="2" maxlength="2000" placeholder="{{ __('What\'s on your mind?') }}"></textarea>
                            @error('postBody')<p class="rk-pf__error">{{ $message }}</p>@enderror
                            <div class="rk-pf__toolbar">
                                <button type="button" title="{{ __('Bold') }}" @click="wrap('**', '**')"><b>B</b></button>
                                <button type="button" title="{{ __('Italic') }}" @click="wrap('*', '*')"><i>I</i></button>
                                <button type="button" title="{{ __('Underline') }}" @click="wrap('__', '__')"><u>U</u></button>
                                <button type="button" title="{{ __('Link') }}" @click="link()"><x-heroicon-o-link class="h-5 w-5" /></button>
                                <button type="button" title="{{ __('Bullet list') }}" @click="prefix('- ')"><x-heroicon-o-view-list class="h-5 w-5" /></button>
                                <button type="button" title="{{ __('Numbered list') }}" @click="prefix('#. ')"><span class="text-xs font-semibold">1.</span></button>
                            </div>
                            <div class="rk-pf__composer-foot">
                                <button type="button" class="rk-pf__ghost" @click="link()"><x-heroicon-o-link class="h-5 w-5" /> {{ __('Link') }}</button>
                                <span class="rk-pf__emoji" @click.away="emoji = false">
                                    <button type="button" class="rk-pf__ghost" @click="emoji = ! emoji"><x-heroicon-o-emoji-happy class="h-5 w-5" /> {{ __('Emoji') }}</button>
                                    <span class="rk-pf__emoji-pop" x-show="emoji" x-cloak>
                                        @foreach (['😀', '😂', '😍', '👍', '🙏', '🎉', '🔥', '💡', '✅', '❤️', '😢', '🤔'] as $emoji)
                                            <button type="button" @click="put('{{ $emoji }}')">{{ $emoji }}</button>
                                        @endforeach
                                    </span>
                                </span>
                                <small>{{ __('Shared with :org', ['org' => $organization?->name ?? __('only you')]) }}</small>
                                <button type="submit" class="rk-pf__post" wire:loading.attr="disabled" wire:target="createPost">{{ __('Post') }}</button>
                            </div>
                        </div>
                    </form>
                </section>
                @endif

                {{-- Feed --}}
                @forelse ($feed as $post)
                    <section class="rk-pf__panel rk-pf__post-card" id="post-{{ $post->id }}" wire:key="post-{{ $post->id }}" x-data="{ open: false }">
                        <header>
                            <img src="{{ \Filament\Facades\Filament::getUserAvatarUrl($post->user) }}" alt="" class="rk-pf__mini rk-pf__mini--lg">
                            <div><b>{{ $post->user->name }}</b><small>{{ $post->created_at->diffForHumans() }}</small></div>
                            @if ($post->user_id === $me->id)
                                <button type="button" class="rk-pf__icon" title="{{ __('Delete') }}" @click="confirmDelete('post', {{ $post->id }})"><x-heroicon-o-trash class="h-5 w-5" /></button>
                            @endif
                        </header>
                        <div class="rk-pf__text">{{ \App\Models\ProfilePost::format($post->body) }}</div>
                        <footer>
                            <button type="button" class="rk-pf__act {{ $post->liked ? 'is-on' : '' }}" wire:click="toggleLike({{ $post->id }})">
                                @if ($post->liked)<x-heroicon-s-thumb-up class="h-5 w-5" />@else<x-heroicon-o-thumb-up class="h-5 w-5" />@endif
                                {{ $post->likes_count }}
                            </button>
                            <button type="button" class="rk-pf__act" @click="open = ! open"><x-heroicon-o-chat-alt class="h-5 w-5" /> {{ $post->comments->count() }}</button>
                            <button type="button" class="rk-pf__act rk-pf__act--end" @click="share({{ $post->id }})">
                                <x-heroicon-o-share class="h-5 w-5" />
                            </button>
                        </footer>

                        <div class="rk-pf__comments" x-show="open" x-cloak>
                            @foreach ($post->comments as $comment)
                                <div class="rk-pf__comment" wire:key="comment-{{ $comment->id }}">
                                    <img src="{{ \Filament\Facades\Filament::getUserAvatarUrl($comment->user) }}" alt="" class="rk-pf__mini">
                                    <div>
                                        <b>{{ $comment->user->name }} <small>{{ $comment->created_at->diffForHumans() }}</small></b>
                                        <div class="rk-pf__ctext">{{ \App\Models\ProfilePost::format($comment->body) }}</div>
                                    </div>
                                    @if ($comment->user_id === $me->id)
                                        <button type="button" class="rk-pf__icon" title="{{ __('Delete') }}" @click="confirmDelete('comment', {{ $comment->id }})"><x-heroicon-o-x class="h-4 w-4" /></button>
                                    @endif
                                </div>
                            @endforeach
                            <form wire:submit.prevent="addComment({{ $post->id }})" class="rk-pf__reply">
                                <img src="{{ \Filament\Facades\Filament::getUserAvatarUrl($me) }}" alt="" class="rk-pf__mini">
                                <input type="text" wire:model.defer="commentDrafts.{{ $post->id }}" maxlength="1000" placeholder="{{ __('Comment') }}">
                                <button type="submit" class="rk-pf__post">{{ __('Comment') }}</button>
                            </form>
                            @error("commentDrafts.$post->id")<p class="rk-pf__error">{{ $message }}</p>@enderror
                        </div>
                    </section>
                @empty
                    @unless ($feedLocked)
                        <section class="rk-pf__panel"><p class="rk-pf__empty">{{ __('No posts yet') }}</p></section>
                    @endunless
                @endforelse
            </div>
        </div>

        {{-- Tab: Team --}}
        <div class="rk-pf__tab-body" x-show="tab === 'team'" x-cloak x-data="{ q: '' }">
            <div class="rk-pf__bar">
                <h3>{{ __('Team') }} <span class="rk-pf__count">{{ $team->count() }}</span></h3>
                <label class="rk-pf__search"><x-heroicon-o-search class="h-4 w-4" /><input type="search" x-model="q" placeholder="{{ __('Search team') }}"></label>
            </div>
            <div class="rk-pf__cards">
                @foreach ($team as $member)
                    <div class="rk-pf__member" data-name="{{ \Illuminate\Support\Str::lower($member->name.' '.$member->email) }}" x-show="$el.dataset.name.includes(q.toLowerCase())">
                        <img src="{{ \Filament\Facades\Filament::getUserAvatarUrl($member) }}" alt="" class="rk-pf__mini rk-pf__mini--xl">
                        <div><b>{{ $member->name }}</b><small>{{ $member->email }}</small></div>
                        @if ($member->pivot?->role ?? null)<span class="rk-pf__chip" style="--c: #3b82d6">{{ __(ucfirst($member->pivot->role)) }}</span>@endif
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Tab: Projects --}}
        <div class="rk-pf__tab-body" x-show="tab === 'projects'" x-cloak x-data="{ q: '' }">
            <div class="rk-pf__bar">
                <h3>{{ __('Projects') }} <span class="rk-pf__count">{{ $projects->count() }}</span></h3>
                <label class="rk-pf__search"><x-heroicon-o-search class="h-4 w-4" /><input type="search" x-model="q" placeholder="{{ __('Search projects') }}"></label>
            </div>
            <div class="rk-pf__cards rk-pf__cards--projects">
                @forelse ($projects as $project)
                    <a href="{{ route('filament.resources.projects.view', $project) }}" class="rk-pf__project" data-name="{{ \Illuminate\Support\Str::lower($project->name) }}" x-show="$el.dataset.name.includes(q.toLowerCase())">
                        <span class="rk-pf__initial rk-pf__tile--{{ $tints[$project->id % 4] }}">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($project->name, 0, 1)) }}</span>
                        <b>{{ $project->name }}</b>
                        <small>{{ $project->owner?->name }}</small>
                        <span class="rk-pf__project-foot">
                            <span><x-heroicon-o-users class="h-4 w-4" /> {{ $project->users_count }}</span>
                            <span><x-heroicon-o-ticket class="h-4 w-4" /> {{ $project->tickets_count }}</span>
                        </span>
                    </a>
                @empty
                    <p class="rk-pf__empty">{{ __('No data found') }}</p>
                @endforelse
            </div>
        </div>

        {{-- Tab: Account settings (Breezy's own sections) --}}
        <div x-show="tab === 'settings'" x-cloak>
            <x-filament-breezy::sections.personal-info/>
            <x-filament::hr />
            <x-filament-breezy::sections.passwords/>
            @if (config('filament-breezy.enable_2fa'))
                <x-filament::hr />
                <x-filament-breezy::sections.2fa/>
            @endif
        </div>
    </div>
</x-filament::page>
