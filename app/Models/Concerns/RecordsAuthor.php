<?php

namespace App\Models\Concerns;

use App\Models\AgentToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who wrote it down (user_id: the account signed in when it was created;
 * null without one, as for monitoring and imports), and through which agent
 * (recorded_via: the name of the token that account came in with, kept
 * after the token is revoked; null in the panel).
 */
trait RecordsAuthor
{
    public static function bootRecordsAuthor(): void
    {
        static::creating(function (self $record): void {
            if (Authorship::paused()) {
                return;
            }

            $user = auth()->user();
            $record->user_id ??= $user?->getAuthIdentifier();
            $record->recorded_via ??= $user instanceof User ? $user->currentAgentToken()?->name : null;
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * "Ada via deploy-agent". Eager-load: user.
     */
    public function recordedBy(): ?string
    {
        return AgentToken::byline($this->user?->name, $this->recorded_via);
    }
}
