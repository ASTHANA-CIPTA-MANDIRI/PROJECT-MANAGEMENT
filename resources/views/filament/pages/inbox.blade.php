<x-filament::page>
    @php
        $me = \Filament\Facades\Filament::auth()->user();
        $chats = $this->getChats();
        $activeUser = $this->getActiveUser();
        [$messages, $hasOlder] = $this->getMessages();
        $unreadNotifications = $me->notifications()->whereNull('read_at')->count();
        $notifications = $tab === 'notifications' ? $this->getNotifications() : collect();
    @endphp

    <div class="rk-ib" wire:poll.10s.visible="refreshChat" data-open="{{ $activeUser || $tab === 'notifications' ? 'chat' : 'list' }}">
        {{-- Left: me, search, tabs, conversations --}}
        <aside class="rk-ib__side">
            <div class="rk-ib__me">
                <img src="{{ \Filament\Facades\Filament::getUserAvatarUrl($me) }}" alt="" class="rk-ib__avatar rk-ib__avatar--lg">
                <div><b>{{ $me->name }}</b><small>{{ $me->getRoleNames()->first() }}</small></div>
            </div>

            <label class="rk-ib__search">
                <input type="search" wire:model.debounce.400ms="search" placeholder="{{ __('Search contacts') }}">
                <x-heroicon-o-search class="h-5 w-5" />
            </label>

            <div class="rk-ib__tabs">
                <button type="button" class="{{ $tab === 'chat' ? 'is-active' : '' }}" wire:click="$set('tab', 'chat')">{{ __('Chats') }}</button>
                <button type="button" class="{{ $tab === 'notifications' ? 'is-active' : '' }}" wire:click="$set('tab', 'notifications')">
                    {{ __('Notifications') }} @if ($unreadNotifications)<span class="rk-ib__badge">{{ $unreadNotifications }}</span>@endif
                </button>
            </div>

            @if ($tab === 'chat')
                <div class="rk-ib__list">
                    @forelse ($chats as $chat)
                        @php($other = $chat['user'])
                        <button type="button" wire:key="chat-{{ $other->id }}" wire:click="openChat({{ $other->id }})"
                                class="rk-ib__row {{ $activeUser?->id === $other->id ? 'is-active' : '' }}">
                            <img src="{{ \Filament\Facades\Filament::getUserAvatarUrl($other) }}" alt="" class="rk-ib__avatar">
                            <span class="rk-ib__row-body">
                                <span class="rk-ib__row-top">
                                    <b>{{ $other->name }}</b>
                                    @if ($chat['conversation']?->last_message_at)<small>{{ $chat['conversation']->last_message_at->diffForHumans(null, true, true) }}</small>@endif
                                </span>
                                <span class="rk-ib__row-bottom">
                                    <small class="{{ $chat['unread'] ? 'is-unread' : '' }}">
                                        @if ($chat['conversation']?->last_message_at)
                                            {{ $chat['conversation']->last_sender_id === $me->id ? __('You').': ' : '' }}{{ $chat['conversation']->last_message_preview }}
                                        @else
                                            {{ $other->email }}
                                        @endif
                                    </small>
                                    @if ($chat['unread'])<i class="rk-ib__dot"></i>@endif
                                </span>
                            </span>
                        </button>
                    @empty
                        <p class="rk-ib__none">{{ \App\Support\ChatDirectory::isLocked($me) ? __('Your organization\'s trial has ended. Contact the organization Owner to subscribe.') : __('No contacts found') }}</p>
                    @endforelse
                </div>
            @else
                <div class="rk-ib__list">
                    <p class="rk-ib__none">{{ $unreadNotifications ? trans_choice(':count unread notification|:count unread notifications', $unreadNotifications, ['count' => $unreadNotifications]) : __('All caught up') }}</p>
                </div>
            @endif
        </aside>

        {{-- Right: conversation or notifications --}}
        <section class="rk-ib__main">
            @if ($tab === 'notifications')
                <div class="rk-ib__head">
                    <button type="button" class="rk-ib__back" wire:click="$set('tab', 'chat')"><x-heroicon-o-arrow-left class="h-5 w-5" /></button>
                    <div><b>{{ __('Notifications') }}</b><small>{{ __('Messages & emails') }}</small></div>
                    @if ($unreadNotifications)
                        <button type="button" class="rk-ib__link" wire:click="markAllAsRead">{{ __('Mark all as read') }}</button>
                    @endif
                </div>
                <div class="rk-ib__feed">
                    @forelse ($notifications as $notification)
                        @php($data = $notification->data)
                        @php($url = $data['actions'][0]['url'] ?? null)
                        <div @class(['rk-inbox__item', 'is-unread' => $notification->read_at === null]) wire:key="inbox-{{ $notification->id }}">
                            <span class="rk-inbox__dot"></span>
                            <div class="rk-inbox__body">
                                @if ($url)
                                    <a href="{{ $url }}" wire:click="markAsRead('{{ $notification->id }}')"><b>{{ $data['title'] ?? '' }}</b></a>
                                @else
                                    <b>{{ $data['title'] ?? '' }}</b>
                                @endif
                                @if (! empty($data['body']))<small>{{ strip_tags($data['body']) }}</small>@endif
                                <small>{{ $notification->created_at->diffForHumans() }}</small>
                            </div>
                            @if ($notification->read_at === null)
                                <button type="button" class="rk-inbox__read" wire:click="markAsRead('{{ $notification->id }}')">{{ __('Mark as read') }}</button>
                            @endif
                        </div>
                    @empty
                        <p class="rk-inbox__empty">{{ __('No data found') }}</p>
                    @endforelse
                </div>
            @elseif ($activeUser)
                @php($panel = $this->getPanel())
                <div class="rk-ib__chat" x-data="{
                    panel: (() => { try { return localStorage.getItem('rk-chat-panel') !== '0'; } catch (e) { return true; } })(),
                    toggle() { this.panel = ! this.panel; try { localStorage.setItem('rk-chat-panel', this.panel ? '1' : '0'); } catch (e) {} },
                }">
                    <div class="rk-ib__head">
                        <button type="button" class="rk-ib__back" wire:click="closeChat"><x-heroicon-o-arrow-left class="h-5 w-5" /></button>
                        <img src="{{ \Filament\Facades\Filament::getUserAvatarUrl($activeUser) }}" alt="" class="rk-ib__avatar">
                        <div><b>{{ $activeUser->name }}</b><small>{{ $activeUser->email }}</small></div>
                        <button type="button" class="rk-ib__kebab" :class="{ 'is-on': panel }" @click="toggle()" title="{{ __('Show or hide media and files') }}">
                            <x-heroicon-o-dots-vertical class="h-5 w-5" />
                        </button>
                    </div>

                    <div class="rk-ib__body">
                        <div class="rk-ib__feed rk-ib__thread"
                             x-data="{ stick: true, down() { this.$nextTick(() => this.$el.scrollTop = this.$el.scrollHeight); this.stick = true; setTimeout(() => this.stick = false, 2500); } }"
                             x-init="down()"
                             @chat-scroll.window="down()"
                             @chat-sent.window="down()"
                             @load.capture="if (stick) $el.scrollTop = $el.scrollHeight">
                            @if ($hasOlder)
                                <button type="button" class="rk-ib__older" wire:click="loadOlder">{{ __('Load earlier messages') }}</button>
                            @endif
                            @forelse ($messages as $msg)
                                @php($mine = $msg->user_id === $me->id)
                                <div class="rk-ib__msg {{ $mine ? 'is-mine' : '' }}" wire:key="msg-{{ $msg->id }}">
                                    @unless ($mine)
                                        <img src="{{ \Filament\Facades\Filament::getUserAvatarUrl($activeUser) }}" alt="" class="rk-ib__avatar">
                                    @endunless
                                    <div>
                                        <small>{{ $mine ? '' : $activeUser->name.', ' }}{{ $msg->created_at->diffForHumans() }}</small>
                                        @foreach ($msg->attachments as $file)
                                            @if (! $file->isAvailable())
                                                <div class="rk-ib__bubble rk-ib__gone"><x-heroicon-o-clock class="h-4 w-4" /> {{ __('Attachment expired') }}</div>
                                            @elseif ($file->is_image)
                                                <a href="{{ $file->url() }}" target="_blank" rel="noopener" class="rk-ib__img"><img src="{{ $file->url() }}" alt="{{ $file->original_name }}" loading="lazy"></a>
                                            @else
                                                <a href="{{ $file->url() }}" class="rk-ib__file">
                                                    <span class="rk-ib__ext rk-ib__ext--{{ strtolower($file->extensionLabel()) }}">{{ \Illuminate\Support\Str::limit($file->extensionLabel(), 4, '') }}</span>
                                                    <span><b>{{ $file->original_name }}</b><small>{{ $file->humanSize() }}</small></span>
                                                </a>
                                            @endif
                                        @endforeach
                                        @if ($msg->body !== '')
                                            <div class="rk-ib__bubble">{{ \App\Models\ProfilePost::format($msg->body) }}</div>
                                        @endif
                                    </div>
                                </div>
                            @empty
                                <p class="rk-inbox__empty">{{ __('Say hello to :name', ['name' => $activeUser->name]) }}</p>
                            @endforelse
                        </div>

                        <aside class="rk-ib__panel" x-show="panel" x-cloak>
                            <h4>{{ __('Media') }} ({{ $panel['imageCount'] }})</h4>
                            <div class="rk-ib__thumbs">
                                @forelse ($panel['images'] as $image)
                                    <a href="{{ $image->url() }}" target="_blank" rel="noopener" wire:key="thumb-{{ $image->id }}"><img src="{{ $image->url() }}" alt="{{ $image->original_name }}" loading="lazy"></a>
                                @empty
                                    <p>{{ __('No media yet') }}</p>
                                @endforelse
                            </div>

                            <h4>{{ __('Attachments') }} ({{ $panel['fileCount'] }})</h4>
                            <div class="rk-ib__files">
                                @forelse ($panel['files'] as $file)
                                    <a href="{{ $file->url() }}" class="rk-ib__file" wire:key="file-{{ $file->id }}">
                                        <span class="rk-ib__ext rk-ib__ext--{{ strtolower($file->extensionLabel()) }}">{{ \Illuminate\Support\Str::limit($file->extensionLabel(), 4, '') }}</span>
                                        <span><b>{{ $file->original_name }}</b><small>{{ $file->humanSize() }}</small></span>
                                    </a>
                                @empty
                                    <p>{{ __('No files yet') }}</p>
                                @endforelse
                            </div>

                            @if (config('system.chat.attachment_days') > 0)
                                <p class="rk-ib__note">{{ __('Files and images are deleted automatically :days days after they are sent.', ['days' => config('system.chat.attachment_days')]) }}</p>
                            @endif
                        </aside>
                    </div>

                    @if ($upload)
                        <div class="rk-ib__chip">
                            <x-heroicon-o-paper-clip class="h-4 w-4" /> <span>{{ $upload->getClientOriginalName() }}</span>
                            <button type="button" wire:click="$set('upload', null)" title="{{ __('Remove') }}"><x-heroicon-o-x class="h-4 w-4" /></button>
                        </div>
                    @endif
                    <div wire:loading wire:target="upload"><span class="rk-ib__chip">{{ __('Uploading...') }}</span></div>

                    <form wire:submit.prevent="sendMessage" class="rk-ib__compose" x-data @chat-sent.window="$refs.img.value = ''; $refs.doc.value = ''">
                        <input type="text" wire:model.defer="draft" maxlength="2000" autocomplete="off" placeholder="{{ __('Type a message') }}">
                        <input type="file" x-ref="img" wire:model="upload" accept="image/jpeg,image/png,image/webp,image/gif" class="hidden">
                        <input type="file" x-ref="doc" wire:model="upload" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv" class="hidden">
                        <button type="button" title="{{ __('Send a photo') }}" @click="$refs.img.click()"><x-heroicon-o-photograph class="h-5 w-5" /></button>
                        <button type="button" title="{{ __('Attach a file') }}" @click="$refs.doc.click()"><x-heroicon-o-paper-clip class="h-5 w-5" /></button>
                        <button type="submit" title="{{ __('Send') }}" wire:loading.attr="disabled" wire:target="sendMessage,upload"><x-heroicon-o-paper-airplane class="h-5 w-5" /></button>
                    </form>
                    @error('draft')<p class="rk-pf__error px-6 pb-3">{{ $message }}</p>@enderror
                    @error('upload')<p class="rk-pf__error px-6 pb-3">{{ $message }}</p>@enderror
                </div>
            @else
                <div class="rk-ib__blank">
                    <x-heroicon-o-chat-alt-2 class="h-14 w-14" />
                    <b>{{ __('Pick a conversation') }}</b>
                    <span>{{ __('Choose someone on the left to start chatting.') }}</span>
                </div>
            @endif
        </section>
    </div>
</x-filament::page>
