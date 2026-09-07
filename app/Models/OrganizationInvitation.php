<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Phase 5.4 — a pending invitation for an email address to join one
 * Organization at one role. NOT a membership: no `organization_users` row
 * exists for this invitation's target until it is accepted, and this model
 * never writes to `organization_users.role` itself — only the accept flow
 * (App\Http\Livewire\AcceptOrganizationInvitation) does, after every check
 * here passes.
 *
 * State is derived, not a stored enum column (the brief's own preference,
 * matching how Project::isWithinOrganizationContext() already treats a
 * null organization_id as a derived condition rather than a stored flag):
 * PENDING = neither accepted_at nor revoked_at set, and not yet expired.
 * ACCEPTED/REVOKED/EXPIRED are all just different reasons isPending() is
 * false — see isPending()/isExpired()/isAccepted()/isRevoked() below.
 *
 * `name` (Phase 5.4.2) is nullable for backward compatibility with
 * invitations created before this column existed — see
 * App\Http\Livewire\AcceptOrganizationInvitation for what a null name
 * means at acceptance time (nothing is overwritten).
 */
class OrganizationInvitation extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'email',
        'role',
        'token_hash',
        'expires_at',
        'created_by',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /**
     * How long a freshly created invitation stays acceptable. Not
     * configurable per-invitation from the client — every invitation this
     * app creates uses exactly this window.
     */
    public const LIFETIME_DAYS = 7;

    /**
     * How many bytes of entropy the plain token carries before encoding —
     * comfortably beyond brute-force range, matching the "cryptographically
     * secure, sufficiently long" requirement. Str::random() draws from
     * random_bytes() under the hood.
     */
    private const TOKEN_LENGTH = 48;

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id', 'id');
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'id');
    }

    /**
     * Generates a new plain-text token. The caller is responsible for
     * hashing it (see hashToken()) before persisting and for mailing the
     * plain value — it is never stored anywhere.
     */
    public static function generateToken(): string
    {
        return Str::random(self::TOKEN_LENGTH);
    }

    /**
     * The digest stored in `token_hash` / looked up against. A plain
     * sha256 hash (not bcrypt/argon2id): this is a high-entropy random
     * token, not a low-entropy user-chosen secret like a password — there
     * is nothing for a rainbow table or brute force to exploit, so a fast,
     * deterministic hash (allowing an indexed equality lookup, which a
     * salted password hash could never support) is the correct tool, the
     * same reasoning Laravel's own DatabaseTokenRepository and Sanctum's
     * personal access tokens both apply.
     */
    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /**
     * Resolves an invitation from the plain token a recipient submits
     * (route parameter / form field) — never a database id, so an
     * invitation can never be enumerated or guessed by iterating ids.
     */
    public static function findByToken(string $plainToken): ?self
    {
        return static::where('token_hash', self::hashToken($plainToken))->first();
    }

    public function isExpired(): bool
    {
        return Carbon::now()->greaterThanOrEqualTo($this->expires_at);
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isPending(): bool
    {
        return ! $this->isAccepted() && ! $this->isRevoked() && ! $this->isExpired();
    }

    /**
     * Pending invitations only — neither accepted nor revoked nor expired —
     * for the Organization Settings "Invitations" list, which must never
     * show a stale/consumed row as if it were still actionable.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', Carbon::now());
    }
}
