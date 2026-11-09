<?php

namespace App\Mcp\Tools;

use App\Mcp\Serializer;
use App\Mcp\Tools\Concerns\WritesIncidents;
use App\Models\IncidentAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

/**
 * An action item done, as "Complete" in the panel: only if it isn't yet.
 */
#[Name('complete_incident_action')]
#[IsDestructive]
#[IsIdempotent]
#[IsOpenWorld(false)]
class CompleteIncidentAction extends Tool
{
    use WritesIncidents;

    public function description(): string
    {
        return 'Mark an action item of an incident as done. Its id comes from get_incident ("actions") or add_incident_action. '
            .'Called again on one that is done, it returns it with "unchanged": true (it may have been done by someone else). A person can reopen it in Herdbook. '
            .self::answerSentence('in "action" the action item');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $this->idSchemaFrom($schema, 'get_incident ("actions")'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $id = (int) $this->validated($request, ['id' => $this->idRules()])['id'];
        $action = IncidentAction::query()->find($id);

        if ($action === null) {
            return Response::error(__('There is no action item with id :id. get_incident lists them.', ['id' => $id]));
        }

        $done = $action->complete();

        return $this->answer($action->incident, ['action' => Serializer::incidentAction($action->load(['user', 'completedBy']))], unchanged: ! $done);
    }
}
