<?php

namespace App\Mcp\Tools;

use App\Mcp\Serializer;
use App\Mcp\Tools\Concerns\WritesIncidents;
use App\Models\Incident;
use App\Models\IncidentAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

/**
 * A follow-up of an incident; its author and agent come from RecordsAuthor.
 */
#[Name('add_incident_action')]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class AddIncidentAction extends Tool
{
    use WritesIncidents;

    public function description(): string
    {
        return 'Add an action item to an incident: what has to change so it doesn\'t happen again, such as "Alert on NFS latency", with a due date if there is one. '
            .'Check get_incident first: it may be there already, and an agent can\'t delete one. '
            .'Plain text of at most 255 characters; it refuses passwords, tokens, private keys and masks such as [address of app-1]. '
            .self::repeatSentence('description and due_on, while the first is open').self::answerSentence('in "action" the action item');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $this->idSchema($schema),
            'description' => $schema->string()->max(255)->required()->description('What has to change.'),
            'due_on' => $schema->string()->format('date')->description('YYYY-MM-DD, if it has a due date.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $input = $this->validated($request, [
            'id' => $this->idRules(),
            'description' => ['required', ...$this->plainTextRules(255)],
            'due_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1970-01-01', 'before_or_equal:2038-01-18'],
        ]);

        $incident = $this->findIncident((int) $input['id']);

        if (! $incident instanceof Incident) {
            return $incident;
        }

        $dueOn = $input['due_on'] ?? null;

        return self::once(
            IncidentAction::query()->where('incident_id', $incident->id)->where('description', $input['description'])->open(),
            fn (IncidentAction $action): bool => $action->description === $input['description'] && $action->due_on?->toDateString() === $dueOn,
            fn (): IncidentAction => $incident->actions()->create(['description' => $input['description'], 'due_on' => $dueOn]),
            fn (IncidentAction $action, bool $unchanged) => $this->answer($incident, ['action' => Serializer::incidentAction($action->load(['user', 'completedBy']))], $unchanged),
        );
    }
}
