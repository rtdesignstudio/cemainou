<?php

namespace App\Mcp\Tools;

use App\Actions\LogUpdate;
use App\Enums\Source;
use App\Support\Estimates;
use App\Support\Settings;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

/**
 * /history: câte un update pe fiecare zi trecută, din ce a reconstruit agentul din git, după ce omul a văzut ciorna. Zilele deja raportate se sar;
 * timpul fiecărei zile vine din ce au măsurat sesiunile; nimic nu pleacă în Slack / Telegram (ar fi zeci de mesaje vechi).
 */
#[Name('log_history')]
#[Description('Saves the rebuilt history of a project: one update per past day (date, summary, done, decisions, git), each on its day. Call it ONLY after the person has seen the draft and confirmed it (see reported_days and its next_steps). Days already reported are not rewritten, but a reported day without any time can get its estimated_minutes (date + estimated_minutes only); today is not accepted (use log_update). Never secrets, credentials, personal data or code.')]
class LogHistoryTool extends TeamTool
{
    protected bool $suggestsRepos = true;

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'project' => 'nullable|string|max:200', 'repo' => 'nullable|string|max:2000', 'agent' => 'nullable|string|max:50',
            'days' => 'required|array|min:1|max:40', 'days.*.date' => 'required|date_format:Y-m-d', 'days.*.summary' => 'nullable|string|max:500',
            'days.*.done' => 'nullable|array|max:30', 'days.*.done.*' => 'string|max:500', 'days.*.decisions' => 'nullable|array|max:30', 'days.*.decisions.*' => 'string|max:500',
            'days.*.git' => 'nullable|array', 'days.*.git.commits' => 'required_with:days.*.git|integer|min:0|max:1000',
            'days.*.git.added' => 'nullable|integer|min:0|max:10000000', 'days.*.git.removed' => 'nullable|integer|min:0|max:10000000',
            'days.*.estimated_minutes' => 'nullable|integer|min:0|max:1440',
        ]);
        $user = $request->user();
        [$project, $error] = $this->findProject($data['project'] ?? null, $data['repo'] ?? null, $user, session: $request->get('session'));
        $this->record($request, $project);
        if (! $project) {
            return Response::error($error ?? 'History belongs to a project: pass the session, the repo (git remote -v) or the project slug. Nothing was saved.');
        }

        $tz = $this->tz($user);
        $from = now($tz)->subDays(Settings::get('history_days'))->toDateString();
        $yesterday = now($tz)->subDay()->toDateString();
        $days = collect($data['days'])->sortBy('date')->values();
        if ($days->pluck('date')->duplicates()->isNotEmpty()) {
            return Response::error('A day appears twice in days: one entry per day. Nothing was saved.');
        }
        if ($bad = $days->first(fn (array $day) => $day['date'] < $from || $day['date'] > $yesterday)) {
            return Response::error("Day {$bad['date']} is outside the history ({$from} to {$yesterday}; today goes in log_update). Nothing was saved.");
        }

        $taken = $user->updates()->where('project_id', $project->id)->where('created_at', '>=', now($tz)->subDays(Settings::get('history_days'))->startOfDay()->utc())->pluck('created_at')
            ->map(fn ($at) => $at->copy()->setTimezone($tz)->toDateString())->unique()->all();
        [$skip, $save] = $days->partition(fn (array $day) => in_array($day['date'], $taken, true));
        if ($bad = $save->first(fn (array $day) => blank($day['summary'] ?? null))) {
            return Response::error("Day {$bad['date']} is new and needs a summary (only days already reported can come with just estimated_minutes). Nothing was saved.");
        }
        $source = isset($data['agent']) ? Source::fromAgent($data['agent']) : Source::fromRequest(request());
        $action = app(LogUpdate::class);
        $saved = DB::transaction(fn () => $save->map(fn (array $day) => $action->create($user, $project,
            ['summary' => $day['summary'], 'git' => $day['git'] ?? null],
            ['done' => $day['done'] ?? [], 'decision' => $day['decisions'] ?? []], source: $source, day: $day['date']))->all());
        // orele estimate din commit-uri, doar pentru zilele fără timp măsurat; separat de cele măsurate (nu la facturare)
        $estimated = collect($save->values()->all())->map(fn (array $day, int $i) => ($day['estimated_minutes'] ?? 0) > 0
            ? Estimates::record($user, $project, $day['date'], (int) $day['estimated_minutes'], $saved[$i]) : null)->filter(fn ($r) => $r === 'saved')->count();
        // zilele deja raportate, dar fără timp (untimed_days): estimarea se leagă de update-ul omului din ziua aceea, dacă ziua n-are încă niciun timp
        $filled = $skip->filter(fn (array $day) => ($day['estimated_minutes'] ?? 0) > 0)
            ->map(fn (array $day) => Estimates::fill($user, $project, $day['date'], (int) $day['estimated_minutes'], $tz))->filter(fn ($r) => $r === 'saved')->count();
        $estimated += $filled;

        return Response::structured(array_filter([
            'saved' => count($saved),
            'days' => collect($saved)->map(fn ($update) => ['date' => $update->created_at->copy()->setTimezone($tz)->toDateString(), 'url' => route('filament.admin.resources.updates.view', $update)])->all() ?: null,
            'skipped' => $skip->pluck('date')->values()->all() ?: null,
            'estimated_days' => $estimated ?: null,
            'project_url' => route('filament.admin.resources.projects.view', $project),
            'message' => count($saved).' day(s) added to '.$project->name.' history'.($skip->isNotEmpty() ? ', '.$skip->count().' already reported (not rewritten)' : '').($filled ? ", estimated time added to {$filled} day(s) reported earlier without time" : '').'. Tell the person and give them the project link.',
        ], fn ($value) => $value !== null));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'session' => $schema->string()->description(self::SESSION),
            'repo' => $schema->string()->description('The output of git remote -v in the working directory.'),
            'project' => $schema->string()->description('Project slug or name, when there is no repo or it is not linked.'),
            'agent' => $schema->string()->description('The agent writing this: claude-code, codex, cursor.'),
            'days' => $schema->array()->items($schema->object([
                'date' => $schema->string()->required()->description('The day, YYYY-MM-DD, in the person\'s timezone.'),
                'summary' => $schema->string()->description('One sentence: what changed that day. Required for a new day; leave it out for a day from untimed_days that only gets its estimated_minutes.'),
                'done' => $schema->array()->items($schema->string())->description('Up to 5 short outcome lines (grouped commits, not one per commit).'),
                'decisions' => $schema->array()->items($schema->string())->description('Decisions taken that day, if any.'),
                'git' => $schema->object([
                    'commits' => $schema->integer()->min(0)->required()->description('The person\'s own commits that day (no merges).'),
                    'added' => $schema->integer()->min(0)->description('Lines added, without generated files.'),
                    'removed' => $schema->integer()->min(0)->description('Lines removed, without generated files.'),
                ])->description('That day\'s commits and lines, from git log --numstat.'),
                'estimated_minutes' => $schema->integer()->description('Only for a day without measured time (also a day from untimed_days): the time estimated from that day\'s commit times or file modification times (from 30 minutes before the first to the last, without gaps longer than 2 hours), or the hours the person gave, shown with ~ and confirmed. Kept apart from measured hours.'),
            ]))->required()->description('One entry per past day with work, oldest first; at most 40 per call.'),
        ];
    }
}
