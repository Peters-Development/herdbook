<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Every change to a fillable attribute ends up in the activity log, so
 * "who changed the IP of this server, and when?" has an answer.
 */
trait RecordsActivity
{
    use LogsActivity;

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
     * page of what this model belongs to, even after it was deleted.
     */
    public function beforeActivityLogged(Model $activity, string $eventName): void
    {
        $context = $this->activityContext();

        if ($context !== []) {
            $activity->properties = collect($activity->properties)->merge($context);
        }
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
     * Long texts that would bloat the log (documents keep revisions instead).
     *
     * @return list<string>
     */
    protected function attributesNotLogged(): array
    {
        return [];
    }
}
