<?php

namespace App\Models;

use App\Enums\AgentAccess;
use App\Models\Concerns\RecordsActivity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * An agent (Claude Code and the like) that may use the MCP server (/mcp) for
 * an account. Like a status screen it proves that with a token, of which the
 * database only knows the hash, so a leaked dump opens nothing. It acts
 * without the account's second factor, so it only works while the account
 * has one, and it expires. Deleting the row revokes it.
 */
#[Fillable(['name', 'access', 'expires_at'])]
#[Hidden(['token_hash'])]
class AgentToken extends Model
{
    use Prunable, RecordsActivity;

    /**
     * In front of every token, so a leaked one is recognisable (a gitleaks
     * rule, a search through dotfiles).
     */
    public const string PREFIX = 'hb_';

    /**
     * last_used_at is written at most this often: an agent asks in bursts.
     */
    public const int USED_EVERY_SECONDS = 60;

    /**
     * An expired token stays this long, so agents:list can still show it.
     */
    public const int PRUNE_AFTER_DAYS = 7;

    /**
     * @return array{0: self, 1: string} the agent and its token (shown once, never stored)
     */
    public static function issue(User $user, string $name, AgentAccess $access, CarbonImmutable $expiresAt): array
    {
        $token = self::PREFIX.Str::random(48);

        $agent = new self(['name' => $name, 'access' => $access, 'expires_at' => $expiresAt]);
        $agent->forceFill(['user_id' => $user->id, 'token_hash' => self::hash($token)])->save();

        return [$agent, $token];
    }

    /**
     * Revokes them one by one, so the activity log records each revoke.
     *
     * @param  iterable<self>  $agents
     * @return int how many were revoked
     */
    public static function revoke(iterable $agents): int
    {
        $revoked = 0;

        foreach ($agents as $agent) {
            $revoked += (int) $agent->delete();
        }

        return $revoked;
    }

    /**
     * "Ada via deploy-agent": a person and the agent that acted for them;
     * "via deploy-agent" once the account is gone; the person alone without
     * an agent.
     */
    public static function byline(?string $person, ?string $agent): ?string
    {
        return match (true) {
            $agent === null => $person,
            $person === null => __('via :agent', ['agent' => $agent]),
            default => __(':name via :agent', ['name' => $person, 'agent' => $agent]),
        };
    }

    public static function findByToken(string $token): ?self
    {
        return self::query()->with('user')->where('token_hash', self::hash($token))->first();
    }

    /**
     * The account the agent acts for.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function canWrite(): bool
    {
        return $this->access === AgentAccess::Write;
    }

    /**
     * Without an event: being used is no change worth logging.
     */
    public function markUsed(): void
    {
        if ($this->last_used_at !== null && $this->last_used_at->gt(now()->subSeconds(self::USED_EVERY_SECONDS))) {
            return;
        }

        self::query()->whereKey($this->id)->toBase()->update(['last_used_at' => now()]);
    }

    /**
     * Expired a while ago (model:prune, daily).
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return self::query()->where('expires_at', '<', now()->subDays(self::PRUNE_AFTER_DAYS));
    }

    /**
     * Whose agent it was, still readable after a revoke.
     *
     * @return array<string, mixed>
     */
    protected function activityContext(): array
    {
        return ['user_id' => $this->user_id, 'label' => $this->name];
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    protected function casts(): array
    {
        return [
            'access' => AgentAccess::class,
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }
}
