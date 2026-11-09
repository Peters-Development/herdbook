<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
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
        }

        if ($context !== []) {
            $activity->properties = collect($activity->properties)->merge($context);
        }
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
