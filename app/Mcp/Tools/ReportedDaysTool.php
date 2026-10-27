<?php

namespace App\Mcp\Tools;

use App\Mcp\HistoryFlow;
use App\Support\Estimates;
use App\Support\Settings;
use App\Support\WorkTime;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/** Începutul lui /history: ce zile a raportat deja omul pe proiect, de când se poate completa și pașii de urmat. */
#[Name('reported_days')]
#[Description('Call it first to rebuild a project\'s history from git (/history): the days the person already reported on the project, the oldest day that can still be filled (from), the days measured from agent sessions, the reported days still without any time (untimed_days), and next_steps (the exact steps to follow).')]
#[IsReadOnly]
class ReportedDaysTool extends TeamTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate(['project' => 'nullable|string|max:200', 'repo' => 'nullable|string|max:2000']);
        $user = $request->user();
        [$project, $error] = $this->findProject($data['project'] ?? null, $data['repo'] ?? null, $user, session: $request->get('session'));
        $this->record($request, $project);
        if (! $project) {
            return Response::error($error ?? 'Pass the session, the repo (git remote -v) or the project slug.');
        }

        $tz = $this->tz($user);
        $from = now($tz)->subDays(Settings::get('history_days'))->startOfDay();
        $reported = $user->updates()->where('project_id', $project->id)->where('created_at', '>=', $from->copy()->utc())->orderBy('created_at')->get(['created_at', 'summary'])
            ->groupBy(fn ($update) => $update->created_at->copy()->setTimezone($tz)->toDateString())
            ->map(fn ($updates, $date) => ['date' => $date, 'summary' => $updates->last()->summary])->values()->all();
        $worked = collect(WorkTime::projectDays($user, $project, $from, now()->subDay()->endOfDay()))
            ->map(fn (array $day) => ['date' => $day['date'], 'active' => WorkTime::format($day['active_minutes'])])->all();
        $untimed = Estimates::untimedDays($user, $project, $from->toDateString(), now($tz)->subDay()->toDateString(), $tz);   // raportate, dar fără niciun timp

        return Response::structured(array_filter([
            'project' => ['slug' => $project->slug, 'name' => $project->name],
            'from' => $from->toDateString(),
            'until' => now($tz)->subDay()->toDateString(),
            'reported_days' => $reported ?: null,
            'days_worked' => $worked ?: null,
            'untimed_days' => $untimed ?: null,
            'next_steps' => HistoryFlow::steps(),
        ], fn ($value) => $value !== null));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'session' => $schema->string()->description(self::SESSION),
            'repo' => $schema->string()->description('The output of git remote -v in the working directory.'),
            'project' => $schema->string()->description('Project slug or name, when there is no repo or it is not linked.'),
        ];
    }
}
