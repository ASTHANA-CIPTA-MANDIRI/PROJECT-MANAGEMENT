<?php

namespace App\Http\Livewire;

use App\Models\ProfilePost;
use App\Models\ProfilePostComment;
use App\Models\Project;
use App\Support\OrganizationContext;
use Filament\Facades\Filament;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\HtmlString;
use JeffGreco13\FilamentBreezy\Pages\MyProfile as BaseProfile;

class Profile extends BaseProfile
{
    protected static ?string $slug = 'my-profile';

    protected static string $view = 'filament.pages.my-profile';

    public string $postBody = '';

    /** 'feeds' = the Organization's feed, 'mine' = only my own posts (Timeline). */
    public string $feedFilter = 'feeds';

    /** @var array<int|string, string> */
    public array $commentDrafts = [];

    protected function getUpdateProfileFormSchema(): array
    {
        $fields = parent::getUpdateProfileFormSchema();
        $fields[1]->helperText(function () {
            $pendingEmail = $this->user->getPendingEmail();
            if ($pendingEmail) {
                return new HtmlString(
                    '<span>'.
                    __('You have a pending email verification for :email.', [
                        'email' => $pendingEmail,
                    ])
                    .'</span> <a wire:click="resendPending"
                                   class="hover:cursor-pointer hover:text-primary-500 hover:underline">
                    '.__('Click here to resend').'
                </a>'
                );
            } else {
                return '';
            }
        });

        return $fields;
    }

    public function updateProfile()
    {
        $data = $this->updateProfileForm->getState();
        $loginColumnValue = $data[$this->loginColumn];
        unset($data[$this->loginColumn]);
        $this->user->update($data);
        $this->user->refresh();
        $this->updateProfileForm->fill([
            'name' => $this->user->name,
            'email' => $this->user->email,
        ]);
        if ($loginColumnValue != $this->user->{$this->loginColumn}) {
            $this->user->newEmail($loginColumnValue);
        }
        $this->notify('success', __('filament-breezy::default.profile.personal_info.notify'));
    }

    public function resendPending(): void
    {
        $this->user->resendPendingEmailVerificationMail();
        $this->notify('success', __('Email verification sent'));
    }

    /**
     * Posts of the current Organization, newest first, with like/comment data
     * for the signed-in user. Visibility is decided by ProfilePost::visibleTo().
     *
     * @return Collection<int, ProfilePost>
     */
    public function getFeed(): Collection
    {
        $user = Filament::auth()->user();

        return ProfilePost::query()
            ->visibleTo($user)
            ->when($this->feedFilter === 'mine', fn ($query) => $query->where('user_id', $user->id))
            ->with(['user', 'comments' => fn ($query) => $query->with('user')->oldest('id')->limit(50)])
            ->withCount('likes')
            ->withExists(['likes as liked' => fn ($query) => $query->where('users.id', $user->id)])
            ->latest('id')
            ->limit(20)
            ->get();
    }

    /**
     * Everyone in the user's current Organization (just themselves when they
     * have none).
     *
     * @return Collection<int, \App\Models\User>
     */
    public function getTeam(): Collection
    {
        $user = Filament::auth()->user();
        $organization = OrganizationContext::current($user);

        return $organization
            ? $organization->users()->orderBy('name')->limit(100)->get()
            : collect([$user]);
    }

    /**
     * @return Collection<int, Project>
     */
    public function getMyProjects(): Collection
    {
        return Project::accessibleBy(Filament::auth()->user())
            ->with('owner')
            ->withCount(['users', 'tickets'])
            ->orderBy('name')
            ->limit(100)
            ->get();
    }

    public function createPost(): void
    {
        $this->validate(['postBody' => ['required', 'string', 'max:2000']]);

        $user = Filament::auth()->user();

        if (ProfilePost::isLockedFor($user)) {
            $this->notify('danger', __('Your organization\'s trial has ended. Contact the organization Owner to subscribe.'));

            return;
        }

        // Caps spam growth: 20 posts per hour per user.
        if (! RateLimiter::attempt('profile-post:'.$user->id, 20, fn () => true, 3600)) {
            $this->notify('warning', __('Too many posts, try again later'));

            return;
        }

        ProfilePost::create([
            'user_id' => $user->id,
            'organization_id' => OrganizationContext::current($user)?->id,
            'body' => trim($this->postBody),
        ]);

        $this->postBody = '';
        $this->notify('success', __('Your post is live'));
    }

    public function toggleLike(int $postId): void
    {
        $user = Filament::auth()->user();

        // Re-resolved through the visibility scope: a posted id is never trusted.
        ProfilePost::query()->visibleTo($user)->whereKey($postId)->first()
            ?->likes()->toggle($user->id);
    }

    public function addComment(int $postId): void
    {
        $this->validate(["commentDrafts.$postId" => ['required', 'string', 'max:1000']]);

        $user = Filament::auth()->user();
        $post = ProfilePost::query()->visibleTo($user)->whereKey($postId)->first();

        if (! $post) {
            return;
        }

        if (! RateLimiter::attempt('profile-comment:'.$user->id, 60, fn () => true, 3600)) {
            $this->notify('warning', __('Too many posts, try again later'));

            return;
        }

        ProfilePostComment::create([
            'profile_post_id' => $post->id,
            'user_id' => $user->id,
            'body' => trim($this->commentDrafts[$postId]),
        ]);

        unset($this->commentDrafts[$postId]);
        $this->notify('success', __('Comment sent'));
    }

    public function deletePost(int $postId): void
    {
        $user = Filament::auth()->user();

        $post = ProfilePost::query()->visibleTo($user)->where('user_id', $user->id)->whereKey($postId)->first();

        if ($post) {
            $post->delete();
            $this->notify('success', __('Post deleted'));
        }
    }

    public function deleteComment(int $commentId): void
    {
        $user = Filament::auth()->user();

        $comment = ProfilePostComment::query()
            ->where('user_id', $user->id)
            ->whereKey($commentId)
            ->whereHas('post', fn ($query) => $query->visibleTo($user))
            ->first();

        if ($comment) {
            $comment->delete();
            $this->notify('success', __('Comment deleted'));
        }
    }
}
