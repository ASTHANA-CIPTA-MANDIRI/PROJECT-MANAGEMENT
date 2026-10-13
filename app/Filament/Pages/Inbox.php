<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Support\ChatDirectory;
use Filament\Facades\Filament;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Spatie\Image\Image;
use Spatie\Image\Manipulations;

/**
 * The signed-in user's Inbox: direct messages with the people they may reach
 * (see ChatDirectory) and the same database notifications the topbar bell shows.
 */
class Inbox extends AuthorizedPage
{
    use WithFileUploads;

    /**
     * Deliberately null: chat is gated per contact by ChatDirectory (the same
     * check runs on every open/send), and notifications always go through the
     * user's own notifications() relation.
     */
    protected static ?string $permission = null;

    protected static ?string $slug = 'inbox';

    protected static string $view = 'filament.pages.inbox';

    protected static bool $shouldRegisterNavigation = false;

    private const PAGE = 30;

    private const MAX_MESSAGES = 300;

    /** 'chat' or 'notifications'. */
    public string $tab = 'chat';

    public ?int $activeUserId = null;

    public string $search = '';

    public string $draft = '';

    public int $messageLimit = self::PAGE;

    /** @var TemporaryUploadedFile|null */
    public $upload;

    protected function getTitle(): string
    {
        return __('My inbox');
    }

    // ---- Notifications (the bell's data) -------------------------------

    public function getNotifications(): DatabaseNotificationCollection
    {
        return Filament::auth()->user()->notifications()->latest()->limit(50)->get();
    }

    public function markAsRead(string $id): void
    {
        Filament::auth()->user()->notifications()->whereKey($id)->first()?->markAsRead();
    }

    public function markAllAsRead(): void
    {
        Filament::auth()->user()->notifications()->whereNull('read_at')->update(['read_at' => now()]);
    }

    // ---- Chat ----------------------------------------------------------

    /**
     * Rows for the left list: recent conversations, or - while searching, or
     * when there is no history yet - people the user may message.
     *
     * @return Collection<int, array{user: User, conversation: ?Conversation, unread: bool}>
     */
    public function getChats(): Collection
    {
        $me = Filament::auth()->user();
        $search = trim($this->search);
        $contacts = ChatDirectory::contacts($me);

        $conversations = ChatDirectory::conversations($me)
            ->whereNotNull('last_message_at')
            ->orderByDesc('last_message_at')
            ->limit(50)
            ->get()
            ->keyBy(fn (Conversation $conversation) => $conversation->otherUserId($me));

        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $users = $contacts->where(fn ($query) => $query->where('name', 'like', $like)->orWhere('email', 'like', $like))
                ->orderBy('name')->limit(20)->get();
        } elseif ($conversations->isNotEmpty()) {
            $users = $contacts->whereIn('id', $conversations->keys())->get()
                ->sortBy(fn (User $user) => -($conversations[$user->id]->last_message_at?->getTimestamp() ?? 0))
                ->values();
        } else {
            $users = $contacts->orderBy('name')->limit(20)->get();
        }

        return $users->map(fn (User $user) => [
            'user' => $user,
            'conversation' => $conversations->get($user->id),
            'unread' => (bool) $conversations->get($user->id)?->isUnreadFor($me),
        ]);
    }

    public function getActiveUser(): ?User
    {
        $me = Filament::auth()->user();

        return $this->activeUserId && ChatDirectory::canMessage($me, $this->activeUserId)
            ? User::find($this->activeUserId)
            : null;
    }

    /**
     * Oldest-first slice of the open conversation, newest $messageLimit
     * messages. The bool says whether older ones exist.
     *
     * @return array{0: Collection<int, Message>, 1: bool}
     */
    public function getMessages(): array
    {
        $me = Filament::auth()->user();
        $conversation = $this->activeUserId ? Conversation::findBetween($me->id, $this->activeUserId) : null;

        if (! $conversation) {
            return [collect(), false];
        }

        // messageLimit is a public property, so it is clamped server-side.
        $limit = max(self::PAGE, min($this->messageLimit, self::MAX_MESSAGES));
        $messages = $conversation->messages()->with('attachments')->latest('id')->limit($limit + 1)->get();

        return [$messages->take($limit)->reverse()->values(), $messages->count() > $limit];
    }

    /**
     * Right-hand panel: images and files still on disk for the open chat.
     *
     * @return array{images: Collection<int, MessageAttachment>, files: Collection<int, MessageAttachment>, imageCount: int, fileCount: int}
     */
    public function getPanel(): array
    {
        $me = Filament::auth()->user();
        $conversation = $this->activeUserId ? Conversation::findBetween($me->id, $this->activeUserId) : null;

        if (! $conversation) {
            return ['images' => collect(), 'files' => collect(), 'imageCount' => 0, 'fileCount' => 0];
        }

        $base = fn (bool $image) => MessageAttachment::query()
            ->where('conversation_id', $conversation->id)
            ->whereNull('purged_at')
            ->where('is_image', $image);

        return [
            'images' => $base(true)->latest('id')->limit(12)->get(),
            'files' => $base(false)->latest('id')->limit(20)->get(),
            'imageCount' => $base(true)->count(),
            'fileCount' => $base(false)->count(),
        ];
    }

    public function openChat(int $userId): void
    {
        $me = Filament::auth()->user();

        // The posted id is never trusted: it must be someone ChatDirectory allows.
        if (! ChatDirectory::canMessage($me, $userId)) {
            return;
        }

        $this->activeUserId = $userId;
        $this->messageLimit = self::PAGE;
        $this->draft = '';
        $this->upload = null;
        $this->markActiveRead();
        $this->dispatchBrowserEvent('chat-scroll');
    }

    public function closeChat(): void
    {
        $this->activeUserId = null;
    }

    public function sendMessage(): void
    {
        $limits = config('system.chat');

        $this->validate([
            'draft' => ['nullable', 'string', 'max:2000'],
            'upload' => ['nullable', 'file', 'mimetypes:'.implode(',', $limits['accepted_mime_types']), 'max:'.$limits['max_file_kb']],
        ]);

        $me = Filament::auth()->user();
        $body = trim($this->draft);

        if ($body === '' && ! $this->upload) {
            $this->addError('draft', __('Write a message or attach a file'));

            return;
        }

        if (! $this->activeUserId || ! ChatDirectory::canMessage($me, $this->activeUserId)) {
            return;
        }

        $isImage = $this->upload && in_array($this->upload->getMimeType(), config('system.images.accepted_mime_types'), true);

        if ($isImage) {
            // A tiny file can still decode to a gigantic bitmap (pixel flood) or be
            // corrupt: look at the header before GD ever decodes it.
            $size = @getimagesize($this->upload->getRealPath());

            if (! is_array($size)) {
                $this->addError('upload', __('This image could not be read'));

                return;
            }

            if ((int) $limits['image_max_pixels'] < $size[0] * $size[1]) {
                $this->addError('upload', __('This image is too large in dimensions'));

                return;
            }
        }

        if ($isImage && $this->upload->getSize() > $limits['max_image_kb'] * 1024) {
            $this->addError('upload', __('Images can be at most :size MB', ['size' => $limits['max_image_kb'] / 1024]));

            return;
        }

        // Caps spam growth: 30 messages per minute, 20 uploads per hour per user.
        // Both buckets are checked before either is spent.
        if (RateLimiter::tooManyAttempts('chat-send:'.$me->id, 30)
            || ($this->upload && RateLimiter::tooManyAttempts('chat-upload:'.$me->id, 20))) {
            $this->notify('warning', __('Too many messages, slow down a little'));

            return;
        }

        RateLimiter::hit('chat-send:'.$me->id, 60);

        if ($this->upload) {
            RateLimiter::hit('chat-upload:'.$me->id, 3600);
        }

        $conversation = Conversation::between($me->id, $this->activeUserId);
        $stored = null;

        try {
            $stored = $this->upload ? $this->storeUpload($this->upload, $conversation, $isImage) : null;

            DB::transaction(function () use ($conversation, $me, $body, $stored) {
                $message = Message::create(['conversation_id' => $conversation->id, 'user_id' => $me->id, 'body' => $body]);

                if ($stored) {
                    $message->attachments()->create($stored + ['conversation_id' => $conversation->id]);
                }

                $preview = $body !== ''
                    ? $body
                    : ($stored['is_image'] ? __('Photo') : $stored['original_name']);

                $conversation->forceFill([
                    'last_message_at' => now(),
                    'last_sender_id' => $me->id,
                    'last_message_preview' => mb_strimwidth($preview, 0, 100, '…'),
                    ($conversation->user_a_id === $me->id ? 'a_read_at' : 'b_read_at') => now(),
                ])->saveQuietly();
            });
        } catch (\Throwable $e) {
            // Do not leave an orphaned file behind a failed insert.
            if ($stored) {
                Storage::disk(MessageAttachment::DISK)->delete($stored['path']);
            }

            throw $e;
        }

        $this->draft = '';
        $this->upload = null;
        $this->dispatchBrowserEvent('chat-sent');
    }

    /**
     * Stores the upload on the private disk. Large JPEG/PNG/WebP images are
     * shrunk to fit image_max_side and recompressed first (GIFs are kept as
     * they are, to stay animated).
     *
     * @return array{path: string, original_name: string, mime: string, size: int, is_image: bool}
     */
    private function storeUpload(TemporaryUploadedFile $file, Conversation $conversation, bool $isImage): array
    {
        $mime = (string) $file->getMimeType();
        $extension = (new \Symfony\Component\Mime\MimeTypes)->getExtensions($mime)[0] ?? 'bin';

        if ($isImage && $mime !== 'image/gif') {
            $side = (int) config('system.chat.image_max_side');
            try {
                $image = Image::load($file->getRealPath());
                $image->fit(Manipulations::FIT_MAX, $side, $side);
                $image->quality(82);
                $image->save();
            } catch (\Throwable) {
                // Unsupported by this GD build: keep the original (still within the size cap).
            }
        }

        $path = Storage::disk(MessageAttachment::DISK)->putFileAs(
            'chat/'.$conversation->id,
            $file,
            Str::uuid().'.'.$extension
        );

        $name = Str::limit(trim((string) preg_replace('/[^\pL\pN\s._()-]/u', '', basename($file->getClientOriginalName()))), 100, '') ?: 'file.'.$extension;

        return [
            'path' => $path,
            'original_name' => $name,
            'mime' => $mime,
            'size' => (int) Storage::disk(MessageAttachment::DISK)->size($path),
            'is_image' => $isImage,
        ];
    }

    public function loadOlder(): void
    {
        $this->messageLimit = min($this->messageLimit + self::PAGE, self::MAX_MESSAGES);
    }

    /** Called by the page's poll: keeps the open chat marked as read. */
    public function refreshChat(): void
    {
        $this->markActiveRead();
    }

    private function markActiveRead(): void
    {
        $me = Filament::auth()->user();

        if ($this->activeUserId && ChatDirectory::canMessage($me, $this->activeUserId)) {
            Conversation::findBetween($me->id, $this->activeUserId)?->markReadFor($me);
        }
    }
}
