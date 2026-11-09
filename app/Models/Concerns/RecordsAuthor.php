<?php

namespace App\Models\Concerns;

use App\Models\AgentToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who wrote it down (user_id: the account signed in when it was created;
 * null without one, as for monitoring and imports), and through which agent
 * (recorded_via: the name of its token, kept after the token is revoked).
 */
trait RecordsAuthor
{
    public static function bootRecordsAuthor(): void
    {
        static::creating(function (self $record): void {
            $record->user_id ??= auth()->id();
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
