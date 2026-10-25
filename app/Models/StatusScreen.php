<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A wall display that may read the status screen (/screen). It
 * proves that with a token in the link; the database only knows its hash,
 * so a leaked dump opens no screen. Deleting the row revokes it.
 */
#[Fillable(['name'])]
class StatusScreen extends Model
{
    use RecordsActivity;

    /**
     * last_seen_at is written at most this often: a screen asks every 20 s.
     */
    public const int SEEN_EVERY_SECONDS = 60;

    /**
     * @return array{0: self, 1: string} the screen and its token (shown once, never stored)
     */
    public static function issue(string $name): array
    {
        $token = Str::random(48);

        $screen = new self(['name' => $name]);
        $screen->forceFill(['token_hash' => self::hash($token)])->save();

        return [$screen, $token];
    }

    public static function findByToken(string $token): ?self
    {
        return self::query()->where('token_hash', self::hash($token))->first();
    }

    /**
     * Without an event: being seen is no change worth logging.
     */
    public function markSeen(): void
    {
        if ($this->last_seen_at !== null && $this->last_seen_at->gt(now()->subSeconds(self::SEEN_EVERY_SECONDS))) {
            return;
        }

        self::query()->whereKey($this->id)->toBase()->update(['last_seen_at' => now()]);
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }
}
