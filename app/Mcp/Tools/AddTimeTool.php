<?php

namespace App\Mcp\Tools;

use App\Enums\WorkCategory;
use App\Models\TimeEntry;
use App\Support\Settings;
use App\Support\WorkTime;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

/** Timpul pe care hook-urile nu-l văd (o ședință, un telefon, lucru departe de calculator), spus de om: se adună la ziua lui. */
#[Name('add_time')]
#[Description('Adds time the agent could not measure: a meeting, a call, work away from the computer. Only when the person says so. Goes on the session\'s project (or the one named) and on today, unless a date is given (any day back to the team\'s reporting window, set by the admin and often months: last_update gives it as oldest_day).')]
class AddTimeTool extends TeamTool
{
    protected bool $suggestsRepos = true;

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'minutes' => 'required|integer|min:1|max:720', 'date' => 'nullable|date_format:Y-m-d', 'start' => 'nullable|date_format:H:i', 'note' => 'nullable|string|max:200',
            'category' => 'nullable|in:'.implode(',', array_column(WorkCategory::cases(), 'value')),
            'project' => 'nullable|string|max:200', 'repo' => 'nullable|string|max:2000',
        ]);
        $user = $request->user();
        $tz = $this->tz($user);
        [$project, $error] = $this->findProject($data['project'] ?? null, $data['repo'] ?? null, $user, session: $request->get('session'));
        $this->record($request, $project);
        if ($error) {
            return Response::error($error);
        }

        $date = $data['date'] ?? now($tz)->toDateString();
        $window = Settings::get('report_days');
        if ($date > now($tz)->toDateString() || $date < now($tz)->subDays($window)->toDateString()) {
            return Response::error("date must be today or one of the last {$window} days. Nothing was saved.");
        }

        $entry = TimeEntry::create([
            'user_id' => $user->id, 'project_id' => $project?->id, 'date' => $date, 'minutes' => $data['minutes'],
            'category' => $data['category'] ?? null, 'note' => $data['note'] ?? null,
            'start_minute' => isset($data['start']) ? (int) substr($data['start'], 0, 2) * 60 + (int) substr($data['start'], 3, 2) : null,
        ]);

        return Response::structured([
            'saved' => true,
            'id' => $entry->id,                                                        // pentru edit_time, dacă trebuie mutat sau corectat
            'message' => 'Added '.WorkTime::format($entry->minutes).($project ? " on {$project->name}" : '').' for '.Carbon::parse($date)->locale('en')->isoFormat('dddd D MMMM').'.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'minutes' => $schema->integer()->min(1)->max(720)->required()->description('How long, in minutes.'),
            'note' => $schema->string()->description('What it was, e.g. "meeting with the client".'),
            'category' => $schema->string()->enum(array_column(WorkCategory::cases(), 'value'))->description('Optional kind of work (meeting, design, ...).'),
            'date' => $schema->string()->description('The day, YYYY-MM-DD, if not today.'),
            'start' => $schema->string()->description('When it started, HH:MM in the person\'s timezone, if known (e.g. the meeting at 14:00).'),
            'session' => $schema->string()->description(self::SESSION),
            'repo' => $schema->string()->description('The output of git remote -v in the working directory.'),
            'project' => $schema->string()->description('Project slug or name, if not this session\'s.'),
        ];
    }
}
