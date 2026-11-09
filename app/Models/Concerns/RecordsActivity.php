<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Every change to a fillable attribute ends up in the activity log, so
 * "who changed the IP of this server, and when?" has an answer.
 */
trait RecordsActivity
{
    use LogsActivity {
        shouldSkipEmptyLog as private skipsEmptyLog;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logExcept($this->attributesNotLogged())
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * Called by the activity log before it saves an entry about this model:
     * stores activityContext() with it, so the entry also shows up on the
     * page of what this model belongs to, even after it was deleted; which
     * long texts changed ("texts"); and the agent that acted for the causer
     * ("via", "agent_token_id": its name may be reused once it is revoked).
     * A long text an agent replaced is kept ("previous_texts") when the
     * model says so (keepsReplacedTexts()): a misled agent's change can then
     * be undone from the log. Only for texts the agent tools write over
     * whole, which they read whole: so what is kept stays short.
     */
    public function beforeActivityLogged(Model $activity, string $eventName): void
    {
        $context = $this->activityContext();

        if ($activity->causer instanceof User && ($agent = $activity->causer->currentAgentToken()) !== null) {
            $context['via'] = $agent->name;
            $context['agent_token_id'] = $agent->id;
        }

        if ($eventName === 'updated' && ($texts = $this->changedTextsNotLogged()) !== []) {
            $context['texts'] = $texts;

            if (isset($context['via']) && $this->keepsReplacedTexts() && ($previous = array_filter(array_intersect_key($this->getRawOriginal(), array_flip($texts)), filled(...))) !== []) {
                $context['previous_texts'] = $previous;
            }
        }

        if ($context !== []) {
            $activity->properties = collect($activity->properties)->merge($context);
        }
    }

    /**
     * The last logged change to each long text (attributesNotLogged(); null:
     * none), from the activity log, which records who changed a text and
     * through which agent: so a plan or report someone rewrote doesn't pass
     * for its author's. Changes from before Herdbook logged them ("texts")
     * aren't there.
     *
     * @return array<string, ?Activity>
     */
    public function lastTextChanges(): array
    {
        $changes = [];

        foreach ($this->attributesNotLogged() as $text) {
            $changes[$text] = $this->activitiesAsSubject()
                ->where('event', 'updated')
                ->whereJsonContains('properties->texts', $text)
                ->with('causer')
                ->latest('id')
                ->first();
        }

        return $changes;
    }

    /**
     * Also when only a long text changed: the text stays out of the log, but
     * that it changed, and who changed it, goes in ("texts").
     *
     * @param  array<string, mixed>  $changes
     */
    protected function shouldSkipEmptyLog(array $changes): bool
    {
        return $this->changedTextsNotLogged() === [] && $this->skipsEmptyLog($changes);
    }

    /**
     * Which of attributesNotLogged() this save changes (still dirty while
     * the "updated" event runs).
     *
     * @return list<string>
     */
    private function changedTextsNotLogged(): array
    {
        return array_values(array_intersect($this->attributesNotLogged(), array_keys($this->getDirty())));
    }

    /**
     * The ids of what this model belongs to (project_id, server_id, ...) and
     * a "label" that still reads after a delete.
     *
     * @return array<string, mixed>
     */
    protected function activityContext(): array
    {
        return [];
    }

    /**
     * Whether the log keeps the long texts an agent replaced: only for a
     * model whose agent tools refuse to write over a text longer than they
     * read (ChangesMaintenance::checkTexts()).
     */
    protected function keepsReplacedTexts(): bool
    {
        return false;
    }

    /**
     * Long texts that would bloat the log (documents keep revisions instead):
     * a change to one is logged by its name only.
     *
     * @return list<string>
     */
    protected function attributesNotLogged(): array
    {
        return [];
    }
}
