<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Depends on": server_id needs depends_on_id (kind: NFS, WireGuard, ...).
 */
#[Fillable(['server_id', 'depends_on_id', 'kind', 'notes'])]
class ServerDependency extends Model
{
    use RecordsActivity;

    public $timestamps = false;

    /** @return array<string, mixed> */
    protected function activityContext(): array
    {
        // Explicit queries: saved or deleted inside a repeater collection,
        // where lazy loading is not allowed (strict mode).
        $names = Server::query()->whereKey([$this->server_id, $this->depends_on_id])->pluck('name', 'id');

        return [
            'server_id' => $this->server_id,
            'depends_on_id' => $this->depends_on_id,
            'label' => "{$names[$this->server_id]} → {$this->kind} → {$names[$this->depends_on_id]}",
        ];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<Server, $this> */
    public function dependsOn(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'depends_on_id');
    }
}
