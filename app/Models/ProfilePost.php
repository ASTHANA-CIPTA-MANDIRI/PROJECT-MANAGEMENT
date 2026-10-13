<?php

namespace App\Models;

use App\Support\OrganizationContext;
use App\Support\TrialGate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\HtmlString;

class ProfilePost extends Model
{
    use MassPrunable;

    protected $fillable = ['user_id', 'organization_id', 'body'];

    public function user(): BelongsTo
    {
        // withTrashed: a removed account's posts stay readable instead of breaking the page.
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ProfilePostComment::class);
    }

    public function likes(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'profile_post_likes')->withTimestamps();
    }

    /**
     * Posts $user may see: the feed of the Organization they are currently
     * acting as (ADR 0001 context, re-verified by OrganizationContext), or just
     * their own posts when they belong to none.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $organization = OrganizationContext::current($user);

        if ($organization && ! TrialGate::active($organization)) {
            // Trial over: the feed is locked like Projects/Tickets are, for
            // reading and writing alike (every Profile action resolves its
            // post through this scope).
            return $query->whereRaw('1 = 0');
        }

        return $organization
            ? $query->where('organization_id', $organization->id)
            : $query->whereNull('organization_id')->where('user_id', $user->id);
    }

    /**
     * True when the user's current Organization exists but its trial has ended.
     */
    public static function isLockedFor(User $user): bool
    {
        $organization = OrganizationContext::current($user);

        return $organization !== null && ! TrialGate::active($organization);
    }

    /**
     * Retention is opt-in (PROFILE_POST_RETENTION_DAYS): by default nothing is
     * ever deleted, so enabling it is a deliberate product decision. The
     * daily `model:prune` already in the schedule picks this up; comments and
     * likes go with the post through their cascading foreign keys.
     */
    public function prunable(): Builder
    {
        $days = (int) config('app.profile_post_retention_days');

        return $days > 0
            ? static::query()->where('created_at', '<', now()->subDays($days))
            : static::query()->whereRaw('1 = 0');
    }

    /**
     * Renders a post/comment body for display. The text is HTML-escaped FIRST,
     * then a few markers are turned into tags, so nothing a user types can
     * inject markup: **bold**, *italic*, __underline__, bare http(s) links, and
     * lines starting with "- " (bullets) or "1. " (numbered).
     */
    public static function format(string $text): HtmlString
    {
        $inline = function (string $line): string {
            $line = e($line);
            $line = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $line);
            $line = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/s', '<em>$1</em>', $line);
            $line = preg_replace('/__(.+?)__/s', '<u>$1</u>', $line);

            return preg_replace(
                '~(?<![">])(https?://[^\s<]+)~',
                '<a href="$1" target="_blank" rel="noopener nofollow ugc">$1</a>',
                $line
            );
        };

        $html = '';
        $list = null;

        foreach (preg_split('/\R/', trim($text)) as $line) {
            $type = match (true) {
                (bool) preg_match('/^- /', $line) => 'ul',
                (bool) preg_match('/^\d+\. /', $line) => 'ol',
                default => null,
            };

            if ($list && $list !== $type) {
                $html .= "</$list>";
                $list = null;
            }

            if ($type) {
                if (! $list) {
                    $html .= "<$type>";
                    $list = $type;
                }
                $html .= '<li>'.$inline(preg_replace('/^(- |\d+\. )/', '', $line)).'</li>';

                continue;
            }

            $html .= $inline($line).'<br>';
        }

        return new HtmlString($html.($list ? "</$list>" : ''));
    }
}
