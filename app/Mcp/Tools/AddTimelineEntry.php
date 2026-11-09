<?php

namespace App\Mcp\Tools;

use App\Mcp\Serializer;
use App\Mcp\Tools\Concerns\WritesIncidents;
use App\Models\Incident;
use App\Models\IncidentTimelineEntry;
use App\Rules\Moment;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

/**
 * An entry on the timeline of an incident: only added, as monitoring adds
 * its own; its author and agent come from RecordsAuthor.
 */
#[Name('add_timeline_entry')]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class AddTimelineEntry extends Tool
{
    use WritesIncidents;

    public function description(): string
    {
        return 'Add an entry to the timeline of an incident: what was tried, found or done, and when. Write them as it happens. '
            .'Entries are only added: an agent can\'t change or delete one, so a correction is a new entry. An entry may be added to a resolved incident too. '
            .'The note is plain text of at most '.number_format(Serializer::MAX_NOTE).' characters, and refuses passwords, tokens, private keys and masks such as [address of app-1]. '
            .self::repeatSentence('note (and occurred_at, if you gave it)', 'to write the same note again, give it its own occurred_at').self::answerSentence('in "entry" the entry');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $this->idSchema($schema),
            'note' => $schema->string()->max(Serializer::MAX_NOTE)->required()->description('What happened, such as "Restarted php-fpm: no effect".'),
            'occurred_at' => $schema->string()->description('When; default now. Not in the future. '.self::momentFormat()),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $input = $this->validated($request, [
            'id' => $this->idRules(),
            'note' => ['required', ...$this->plainTextRules(Serializer::MAX_NOTE)],
            'occurred_at' => ['nullable', new Moment],
        ], after: fn (Validator $validator) => $this->checkMoments($validator, ['occurred_at' => self::moment($validator->getData()['occurred_at'] ?? null)]));

        $incident = $this->findIncident((int) $input['id']);

        if (! $incident instanceof Incident) {
            return $incident;
        }

        $occurredAt = self::moment($input['occurred_at'] ?? null);

        return self::once(
            IncidentTimelineEntry::query()->where('incident_id', $incident->id)->where('note', $input['note']),
            fn (IncidentTimelineEntry $entry): bool => $entry->note === $input['note'] && ($occurredAt === null || $occurredAt->eq($entry->occurred_at)),
            fn (): IncidentTimelineEntry => $incident->timelineEntries()->create([
                'occurred_at' => $occurredAt ?? CarbonImmutable::now()->startOfMinute(),
                'note' => $input['note'],
            ]),
            fn (IncidentTimelineEntry $entry, bool $unchanged) => $this->answer($incident, ['entry' => Serializer::timelineEntry($entry->load('user'))], $unchanged),
        );
    }
}
