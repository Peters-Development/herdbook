<?php

namespace App\Models;

use App\Enums\DocumentKind;
use App\Models\Concerns\LinksToInventory;
use App\Models\Concerns\RecordsActivity;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A runbook, manual, architecture note: markdown, with every previous
 * version kept as a revision.
 */
#[Fillable(['title', 'kind', 'body'])]
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory, LinksToInventory, RecordsActivity;

    protected static function booted(): void
    {
        static::updating(function (Document $document): void {
            if ($document->isDirty('body')) {
                $document->revisions()->create([
                    'body' => $document->getOriginal('body'),
                    'user_id' => auth()->id(),
                ]);
            }
        });
    }

    /** @return HasMany<DocumentRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(DocumentRevision::class)->latest('id');
    }

    protected function linkMorphName(): string
    {
        return 'documentable';
    }

    /** @return list<string> */
    protected function attributesNotLogged(): array
    {
        return ['body'];
    }

    protected function casts(): array
    {
        return [
            'kind' => DocumentKind::class,
        ];
    }
}
