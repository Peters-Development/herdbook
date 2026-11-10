<?php

namespace App\Models\Concerns;

use BackedEnum;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * No lost updates between people, agents and monitoring: version() is a
 * fingerprint of everything a person or an agent can change, its links
 * included, compared on the locked row (locked()) before saving over it
 * (saveWhile()), so a change made meanwhile (an agent, another tab) isn't
 * undone. The edit forms do the same (GuardsConcurrentEdits).
 */
trait GuardsChanges
{
    /**
     * The attributes a person or an agent can change: what version() covers.
     *
     * @return list<string>
     */
    abstract public static function versionedAttributes(): array;

    /**
     * The links a person or an agent can change (relations).
     *
     * @return list<string>
     */
    abstract public static function versionedRelations(): array;

    /**
     * The row and its links, locked: what a save over it compares its
     * version() with. Inside a transaction. The links with a locking read
     * too: MySQL's plain reads see the transaction's first snapshot, which
     * an earlier query in it (a form's options) may have fixed already. Its
     * gap locks can make two saves that both add links deadlock: one fails
     * whole, which beats a link change undone in silence; the agent tools
     * try again, and the edit forms say so.
     */
    public static function locked(int|string $id): ?static
    {
        return static::query()
            ->lockForUpdate()
            ->with(array_fill_keys(static::versionedRelations(), fn ($query) => $query->sharedLock()))
            ->find($id);
    }

    /**
     * Not updated_at: that counts in seconds, and linking a server doesn't
     * touch it. Keyed, as it covers the whole of a text an agent only reads
     * the start of. Loads the links if they aren't.
     */
    public function version(): string
    {
        $this->loadMissing(static::versionedRelations());

        $state = [];

        foreach (static::versionedAttributes() as $attribute) {
            $value = $this->getAttribute($attribute);
            $state[] = match (true) {
                $value instanceof BackedEnum => $value->value,
                $value instanceof CarbonInterface => $value->toJSON(),
                default => $value,
            };
        }

        foreach (static::versionedRelations() as $relation) {
            $state[] = collect($this->getRelation($relation)->modelKeys())->sort()->values()->all();
        }

        return substr(hash_hmac('sha256', json_encode($state, JSON_THROW_ON_ERROR), (string) config('app.key')), 0, 16);
    }

    /**
     * Saves $attributes only while $still holds and it still is the $version
     * that was read (if given), on the row as it is now: the row is locked
     * and read first, so a change someone else made meanwhile is neither
     * overwritten nor logged as this one's. Through Eloquent, so the model's
     * hooks and the activity log run. Either way the model holds the row as
     * it is afterwards; a row that is gone leaves it not existing.
     *
     * @param  Closure(static): bool  $still  asked about the current row
     * @param  array<string, mixed>|(Closure(): array<string, mixed>)  $attributes  a closure sees the current row
     */
    public function saveWhile(Closure $still, array|Closure $attributes, ?string $version = null): bool
    {
        // Again on a deadlock (see locked()), when it is the outermost transaction.
        return DB::transaction(function () use ($still, $attributes, $version): bool {
            $current = static::locked($this->getKey());

            if ($current === null) {
                $this->exists = false;

                return false;
            }

            $this->setRawAttributes($current->getAttributes(), sync: true);
            $this->setRelations($current->getRelations());

            if (! $still($this) || ($version !== null && $this->version() !== $version)) {
                return false;
            }

            return $this->fill(value($attributes))->save();
        }, attempts: 3);
    }
}
