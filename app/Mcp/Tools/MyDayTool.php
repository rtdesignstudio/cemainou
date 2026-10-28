<?php

namespace App\Mcp\Tools;

use App\Support\AgentCharts;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/** „Ce am lucrat azi / marți?": ziua unui om, pe ore și pe proiecte, din care agentul poate desena o bandă de 24 de ore (alb-negru). */
#[Name('my_day')]
#[Description('One person\'s day: minutes worked, from when, minutes per hour (0-23) and per project, tasks done that day, and today also tasks in progress and focus. Today by default; date for an earlier day; person for a colleague whose hours you may see. For "what did I do today / on Tuesday?". To report the day, use last_update instead.')]
#[IsReadOnly]
class MyDayTool extends TeamTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate(['date' => 'nullable|date_format:Y-m-d', 'person' => 'nullable|string|max:100']);
        $viewer = $request->user();
        $this->record($request);
        $person = $viewer;
        if (filled($data['person'] ?? null)) {
            [$person, $error] = $this->findPerson($data['person'], $viewer);      // ca peste tot: e-mailul întreg sau o parte din nume; la mai mulți, întreabă
            if ($error) {
                return Response::error($error);
            }
            if (! $viewer->can('viewWorkTime', $person)) {
                return Response::error('No one by that name whose hours you can see.');
            }
        }
        $day = filled($data['date'] ?? null) ? Carbon::parse($data['date'], $person->tz()) : null;
        if ($day?->isFuture()) {
            return Response::error('That day has not come yet.');
        }

        return Response::structured(AgentCharts::myDay($person, $day, $viewer));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'date' => $schema->string()->description('A day, YYYY-MM-DD (default today).'),
            'person' => $schema->string()->description('A colleague (part of the name or email), when you may see their hours; default you.'),
        ];
    }
}
