<?php

namespace App\Mcp\Tools;

use App\Actions\LogUpdate;
use App\Enums\Source;
use App\Enums\TaskStatus;
use App\Enums\UpdateItemType;
use App\Models\Document;
use App\Models\Task;
use App\Models\TaskCommit;
use App\Models\UpdateItem;
use App\Support\Badges\Badges;
use App\Support\Channels;
use App\Support\ContextDecisions;
use App\Support\Estimates;
use App\Support\Settings;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('log_update')]
#[Description('Saves the person\'s status update for a project and day: done, in progress, blocked, next steps and decisions. Call it only after last_update and after the person has seen the draft and confirmed it; several sessions on the same day add to the same update. To fix an update already sent use edit_update with a reason, never a second log_update; for past days rebuilt from git use log_history. Short, outcome-focused lines; never secrets, credentials, personal data or code. Returns the saved update.')]
class LogUpdateTool extends TeamTool
{
    protected bool $forClients = true;                          // și clientul (doar proiectele și lucrurile lui)

    protected bool $suggestsRepos = true;

    /** câmpul din unealtă → felul rândului */
    private const ITEMS = ['done' => UpdateItemType::Done, 'doing' => UpdateItemType::Doing, 'blocked' => UpdateItemType::Blocked, 'next' => UpdateItemType::Next, 'decisions' => UpdateItemType::Decision];

    public function handle(Request $request): Response|ResponseFactory
    {
        $rules = ['summary' => 'required|string|max:500', 'project' => 'nullable|string|max:200', 'repo' => 'nullable|string|max:2000',
            'minutes' => 'nullable|integer|min:0|max:1440', 'estimated_minutes' => 'nullable|integer|min:0|max:1440', 'started_at' => 'nullable|date', 'period_from' => 'nullable|date',
            'links' => 'nullable|array|max:20', 'links.*' => 'string|max:500', 'focus' => 'nullable|string|max:200', 'agent' => 'nullable|string|max:50', 'internal' => 'nullable|boolean', 'separate' => 'nullable|boolean', 'decisions_to_context' => 'nullable|boolean', 'next_done' => 'nullable|array|max:30', 'next_done.*' => 'integer', 'next_dropped' => 'nullable|array|max:30', 'blocked_resolved' => 'nullable|array|max:30', 'blocked_resolved.*' => 'integer', 'next_dropped.*' => 'integer',
            'notes' => 'nullable|array|max:5', 'notes.*' => 'string|max:1000',
            'untimed' => 'nullable|array|max:600', 'untimed.*.date' => 'required|date_format:Y-m-d', 'untimed.*.estimated_minutes' => 'required|integer|min:1|max:1440'];
        foreach (array_keys(self::ITEMS) as $field) {
            $rules[$field] = 'nullable|array|max:30';
            $rules["{$field}.*"] = 'string|max:500';
        }
        $rules += ['task_changes' => 'nullable|array|max:30', 'task_changes.*.id' => 'required|integer',
            'task_changes.*.status' => 'required|in:todo,doing,blocked,done,dropped', 'task_changes.*.reason' => 'nullable|string|max:300', 'task_changes.*.proof' => 'nullable|string|max:500',
            'new_tasks' => 'nullable|array|max:20', 'new_tasks.*' => 'string|max:200',
            'commits' => 'nullable|array|max:100', 'commits.*.hash' => 'required|string|regex:/^[0-9a-f]{7,40}$/i', 'commits.*.subject' => 'required|string|max:1000', 'commits.*.date' => 'nullable|string|max:40',
            'date' => 'nullable|date_format:Y-m-d', 'days' => 'nullable|array|max:'.max(1, (int) Settings::get('report_days')), 'days.*.date' => 'required|date_format:Y-m-d', 'days.*.summary' => 'required|string|max:500',
            'days.*.minutes' => 'nullable|integer|min:0|max:1440', 'days.*.estimated_minutes' => 'nullable|integer|min:0|max:1440', 'days.*.done' => 'nullable|array|max:30', 'days.*.done.*' => 'string|max:500', 'days.*.decisions' => 'nullable|array|max:30', 'days.*.decisions.*' => 'string|max:500'];
        foreach (['git', 'days.*.git'] as $git) {                                  // commit-urile și rândurile zilei, din git log --numstat
            $rules += [$git => 'nullable|array', "{$git}.commits" => 'required_with:'.$git.'|integer|min:0|max:1000',
                "{$git}.added" => 'nullable|integer|min:0|max:10000000', "{$git}.removed" => 'nullable|integer|min:0|max:10000000'];
        }
        $this->plainBooleans($request);                                  // „true" ca text, de la agenții cu definiția veche
        $data = $request->validate($rules);
        if ($request->user()->isClient()) {                                     // clientul: fără timp, fără intern, fără să scrie contextul proiectului
            unset($data['minutes'], $data['estimated_minutes'], $data['untimed'], $data['started_at'], $data['internal'], $data['decisions_to_context'], $data['git']);
            foreach (array_keys($data['days'] ?? []) as $i) {
                unset($data['days'][$i]['minutes'], $data['days'][$i]['estimated_minutes'], $data['days'][$i]['git']);
            }
        }

        $user = $request->user();
        $tz = $this->tz($user);
        [$project, $error] = $this->findProject($data['project'] ?? null, $data['repo'] ?? null, $request->user(), session: $request->get('session'));
        $this->record($request, $project);
        if ($error) {
            return Response::error($error);
        }

        // toate mișcările de sarcini se verifică înainte: un singur refuz oprește tot update-ul
        $ids = collect($data['task_changes'] ?? [])->pluck('id');
        $tasks = $ids->isEmpty() ? collect() : Task::visibleTo($user)->with(['project', 'assignee'])->whereIn('id', $ids)->get()->keyBy('id');   // una pe care n-o vede nu există pentru el
        $changes = collect($data['task_changes'] ?? [])->map(fn (array $c) => [
            'id' => $c['id'], 'task' => $tasks->get($c['id']), 'status' => TaskStatus::from($c['status']), 'reason' => $c['reason'] ?? null, 'proof' => $c['proof'] ?? null,
        ]);
        foreach ($changes as $c) {
            if (! $c['task']) {
                return Response::error("There is no task #{$c['id']}. Nothing was saved.");
            }
            if (! $user->can('move', [$c['task'], $c['status']])) {
                return Response::error($this->refusal($c['task']).' Nothing was saved.');
            }
        }
        $newTasks = array_values(array_filter(array_map('trim', $data['new_tasks'] ?? [])));
        $notes = array_values(array_filter(array_map('trim', $data['notes'] ?? [])));
        if ($notes && ! $project) {
            return Response::error('notes need a project: pass the repo or the project. Nothing was saved.');
        }
        if ($newTasks && ! $project) {
            return Response::error('new_tasks need a project: pass the repo or the project. Nothing was saved.');
        }

        // raportarea pe zile: zilele trecute în days (fiecare pe ziua ei), ultima zi în câmpurile principale (cu date, dacă nu e azi)
        $today = now($tz)->toDateString();
        $mainDate = $data['date'] ?? $today;
        $days = collect($data['days'] ?? [])->sortBy('date')->values();
        if ($days->isNotEmpty() || isset($data['date'])) {
            if (! $project) {
                return Response::error('days need a project (each day gets the time measured on it): pass the session, the repo or the project. Nothing was saved.');
            }
            $window = Settings::get('report_days');
            $oldest = now($tz)->subDays($window)->toDateString();
            if ($mainDate > $today || $mainDate < $oldest) {
                return Response::error("date must be today or one of the last {$window} days. Nothing was saved.");
            }
            foreach ($days as $day) {
                if ($day['date'] >= $mainDate) {
                    return Response::error("Day {$day['date']} must be before the main update ({$mainDate}): earlier days go in days, the latest day in the main fields. Nothing was saved.");
                }
                if ($day['date'] < $oldest) {
                    return Response::error("Day {$day['date']} is more than {$window} days ago. Nothing was saved.");
                }
            }
            if ($days->pluck('date')->duplicates()->isNotEmpty()) {
                return Response::error('A day appears twice in days. Nothing was saved.');
            }
        }

        // aceeași trimitere, reluată după un timeout de rețea: update-ul e deja salvat, nu se face al doilea
        $again = $user->updates()->where('project_id', $project?->id)->where('summary', trim($data['summary']))
            ->where(fn ($q) => $q->where('created_at', '>=', now()->subMinutes(2))->orWhere('reported_at', '>=', now()->subMinutes(2)))->latest()->first();   // și pentru o zi trecută
        if ($again) {
            $again->load(['items', 'project', 'user']);

            return Response::structured(['saved' => true, 'already_saved' => true, 'update' => $this->present($again, $user, withMinutes: $user->can('viewMinutes', $again)),
                'message' => 'This update was already saved a moment ago (a retry): nothing new was added. Link: '.route('filament.admin.resources.updates.view', $again)]);
        }

        // salvarea propriu-zisă (valorile implicite, sarcinile, rândurile, focusul) e aceeași ca pe web: LogUpdate
        $action = app(LogUpdate::class);
        $source = isset($data['agent']) ? Source::fromAgent($data['agent']) : Source::fromRequest(request());
        // o zi = un update pe proiect: dacă omul are deja unul în ziua aceea (din altă sesiune), lucrul se adaugă la el; separate = un update nou
        $join = $project && ! ($data['separate'] ?? false);
        $added = [];
        $mainFields = [
            'summary' => $data['summary'],
            'is_internal' => (bool) ($data['internal'] ?? false),
            'minutes' => $data['minutes'] ?? null,
            'links' => $data['links'] ?? [],
            'git' => $data['git'] ?? null,
            'started_at' => isset($data['started_at']) ? Carbon::parse($data['started_at'], $tz) : null,     // ajunge în UTC prin StoresTimesInUtc
            'period_from' => isset($data['period_from']) ? Carbon::parse($data['period_from'], $tz) : null,
        ];
        $mainLines = collect(self::ITEMS)->mapWithKeys(fn (UpdateItemType $type, string $field) => [$type->value => $data[$field] ?? []])->all();
        [$earlier, $update] = DB::transaction(function () use ($days, $action, $user, $project, $source, $join, &$added, $mainFields, $mainLines, $changes, $newTasks, $notes, $mainDate, $today) {
            $earlier = $days->map(function (array $day) use ($action, $user, $project, $source, $join, &$added) {
                $fields = ['summary' => $day['summary'], 'minutes' => $day['minutes'] ?? null, 'git' => $day['git'] ?? null];
                $lines = ['done' => $day['done'] ?? [], 'decision' => $day['decisions'] ?? []];
                if ($join && ($existing = $action->dayUpdate($user, $project, $day['date']))) {
                    $added[] = $existing->id;

                    return $action->addTo($existing, $user, $fields, $lines, source: $source);
                }

                return $action->create($user, $project, $fields, $lines, source: $source, day: $day['date']);
            })->all();
            if ($join && ($existing = $action->dayUpdate($user, $project, $mainDate))) {
                $added[] = $existing->id;

                return [$earlier, $action->addTo($existing, $user, $mainFields, $mainLines, $changes, $newTasks, $source, $notes)];
            }

            return [$earlier, $action->create($user, $project, $mainFields, $mainLines, $changes, $newTasks, $source, $notes, day: $mainDate < $today ? $mainDate : null)];
        });
        $action->updateFocus($user, $update, $data['focus'] ?? null);
        // orele estimate (din commit-uri), doar pentru zilele fără timp măsurat; separat de cele măsurate și adăugate
        // (și minutele corectate ale unei zile fără timp măsurat: altfel ar sta doar pe update, fără să se vadă la proiect)
        $estimates = collect($days->values()->all())->map(fn (array $day, int $i) => ['date' => $day['date'], 'minutes' => $day['estimated_minutes'] ?? $day['minutes'] ?? null, 'update' => $earlier[$i]])
            ->push(['date' => $mainDate, 'minutes' => $data['estimated_minutes'] ?? ($mainDate < $today ? ($data['minutes'] ?? null) : null), 'update' => $update])
            ->filter(fn (array $e) => $e['minutes'])
            ->map(fn (array $e) => ['date' => $e['date'], 'minutes' => $e['minutes'], 'result' => Estimates::record($user, $project, $e['date'], $e['minutes'], $e['update'])])->values();
        // zilele raportate demult fără timp (untimed_days din last_update): estimarea se leagă de update-ul omului din ziua aceea
        $untimed = $project ? collect($data['untimed'] ?? [])->map(fn (array $d) => Estimates::fill($user, $project, $d['date'], (int) $d['estimated_minutes'], $tz))
            ->filter(fn ($r) => $r === 'saved')->count() : 0;
        if (! in_array($update->id, $added, true)) {
            Channels::announceLater($update, count($earlier));                 // Slack / Telegram, după răspuns; un update completat nu se mai anunță o dată
        }

        // deciziile, în contextul proiectului (/ctx), dacă omul a spus da: câte un rând datat sub „Decisions", restul documentului rămâne
        $context = null;
        if ($project && ($data['decisions_to_context'] ?? false) && $user->can('create', Document::class)) {
            $decisions = collect($data['decisions'] ?? [])->map(fn (string $text) => ['date' => $mainDate, 'text' => $text])
                ->concat($days->flatMap(fn (array $day) => collect($day['decisions'] ?? [])->map(fn (string $text) => ['date' => $day['date'], 'text' => $text])))->values()->all();
            $context = $decisions ? (ContextDecisions::add($project, $user, $decisions, $source) ?? ['added' => 0, 'note' => 'the project has no context yet: offer to write it (project_context)']) : null;
        }

        $resolved = $this->resolveNext($user, $project, $update, $data['next_done'] ?? [], $data['next_dropped'] ?? []);
        $unblocked = $this->resolveBlockers($user, $project, $data['blocked_resolved'] ?? []);

        // commit-urile care pomenesc sarcini (#12): pe pagina lor; cele care spun „fixes #12" și n-au fost închise acum, de propus
        $commits = $project && ! empty($data['commits']) ? TaskCommit::link($project, $user, $data['commits']) : null;
        $toClose = $commits ? collect($commits['closes'])->reject(fn (Task $t) => $changes->contains(fn (array $c) => $c['id'] === $t->id))->values() : collect();

        $update->load(['items', 'project', 'user']);
        $counts = $update->items->countBy(fn ($item) => $item->type->value);
        $badges = Badges::forAgent($user, Badges::refresh($user, force: true));   // badge-urile noi și cât mai lipsește: o frază, spusă de agent

        return Response::structured(array_filter([
            'saved' => true,
            'update' => $this->present($update, $user, withMinutes: $user->can('viewMinutes', $update)),
            'counts' => $counts->all() ?: null,
            'work_started_today' => $this->iso($update->started_at, $user),
            'estimates' => $estimates->isNotEmpty() ? $estimates->map(fn (array $e) => ['date' => $e['date'], 'minutes' => $e['minutes'],
                'saved' => $e['result'] === 'saved', 'note' => $e['result'] === 'measured' ? 'not saved: that day already has measured time' : null])->map(fn (array $e) => array_filter($e, fn ($v) => $v !== null))->all() : null,
            'untimed_filled' => $untimed ?: null,
            'days' => $earlier ? collect($earlier)->map(fn ($u) => ['date' => $u->created_at->copy()->setTimezone($tz)->toDateString(), 'id' => $u->id, 'url' => route('filament.admin.resources.updates.view', $u)])->all() : null,
            'added_to_existing' => $added ? true : null,
            'unchecked_steps' => ($left = $changes->filter(fn (array $c) => $c['status'] === TaskStatus::Done && $c['task']->uncheckedSteps())
                ->map(fn (array $c) => "#{$c['id']}: ".$c['task']->uncheckedSteps().' not ticked')->values()->all()) ? $left : null,
            'context' => $context,
            'badges' => $badges,
            'next_resolved' => $resolved ?: null,
            'blockers_resolved' => $unblocked ?: null,
            'commits_linked' => ($commits['linked'] ?? 0) ?: null,
            'commits_close' => $toClose->isNotEmpty() ? [
                'tasks' => $toClose->map(fn (Task $t) => ['id' => $t->id, 'title' => $t->title])->all(),
                'note' => 'A commit says it fixes these tasks, but they are still open: ask the person whether to close them (update_task, with the commit as proof).',
            ] : null,
            'message' => ($added ? (in_array($update->id, $added, true) ? 'Added to the update already there for that day (from another session), not a new one' : 'Saved; some days were added to the updates already there')
                : (($earlier ? (count($earlier) + 1).' updates saved' : 'Update saved').($earlier ? ', each on its day' : ''))).($project ? " on {$project->name}" : '').'. Tell the person and give them the link.',
        ], fn ($value) => $value !== null));
    }

    /**
     * Liniile „urmează" din rapoartele de dinainte (open_next din last_update), închise la acest raport: cele făcute devin rânduri „făcut"
     * (în update-ul de azi își schimbă felul; dintr-o zi trecută se adaugă azi, cea veche rămâne cum era atunci), cele renunțate dispar
     * din update-ul de azi. Doar rândurile omului, de pe acest proiect.
     *
     * @return array{done?: int, dropped?: int}
     */
    /** Blocajele de dinainte (open_blockers din last_update) pe care omul le spune rezolvate: se marchează rezolvate, ies din Blocaje. */
    private function resolveBlockers($user, $project, array $ids): int
    {
        if (! $project || ! $ids) {
            return 0;
        }
        $items = UpdateItem::whereIn('id', $ids)->where('type', UpdateItemType::Blocked->value)->whereNull('resolved_at')
            ->whereHas('statusUpdate', fn ($q) => $q->where('user_id', $user->id)->where('project_id', $project->id))->get();
        $items->each(fn (UpdateItem $item) => $item->resolve($user, __('in the next update', [], $user->locale ?: config('app.locale'))));

        return $items->count();
    }

    private function resolveNext($user, $project, $update, array $done, array $dropped): array
    {
        if (! $project || (! $done && ! $dropped)) {
            return [];
        }
        $items = UpdateItem::with('statusUpdate')->whereIn('id', [...$done, ...$dropped])->where('type', UpdateItemType::Next->value)
            ->whereHas('statusUpdate', fn ($q) => $q->where('user_id', $user->id)->where('project_id', $project->id))->get()->keyBy('id');
        $counts = ['done' => 0, 'dropped' => 0];
        foreach ($done as $id) {
            if (! ($item = $items->get($id))) {
                continue;
            }
            if ($item->update_id === $update->id) {
                $item->forceFill(['type' => UpdateItemType::Done])->save();
            } else {
                $update->appendItems([UpdateItemType::Done->value => [$item->text]]);
                $item->resolve($user, __('done', [], $user->locale ?: config('app.locale')));          // în update-ul vechi: nu mai urmează
            }
            $counts['done']++;
        }
        foreach ($dropped as $id) {
            if (($item = $items->get($id)) && $item->update_id === $update->id) {
                $item->delete();
                $counts['dropped']++;
            } elseif ($item) {
                $item->resolve($user, __('dropped', [], $user->locale ?: config('app.locale')));
                $counts['dropped']++;
            }
        }

        return array_filter($counts);
    }

    public function schema(JsonSchema $schema): array
    {
        $lines = fn (string $what) => $schema->array()->items($schema->string())->description($what);
        $git = fn () => $schema->object([
            'commits' => $schema->integer()->min(0)->required()->description('The person\'s own commits that day (no merges).'),
            'added' => $schema->integer()->min(0)->description('Lines added in those commits, without generated files.'),
            'removed' => $schema->integer()->min(0)->description('Lines removed in those commits, without generated files.'),
        ])->description('That day\'s commits and lines, from git log --numstat (see next_steps). Only for work in a git repo.');

        return [
            'summary' => $schema->string()->max(500)->required()->description('One sentence a colleague understands without context: what changed for the project.'),
            'session' => $schema->string()->description(self::SESSION),
            'repo' => $schema->string()->description('The output of git remote -v in the working directory (all remotes; one URL also works). Preferred: it finds the project by itself.'),
            'project' => $schema->string()->description('Project slug or name, when there is no repo or it is not linked. Leave both empty for work outside any project.'),
            'done' => $lines('What was finished since the last update.'),
            'doing' => $lines('What is still in progress. The first line becomes the person\'s current focus.'),
            'blocked' => $lines('Optional: only what is stuck right now and on whom or what it waits; not risks or nice-to-haves.'),
            'next' => $lines('Optional: only what the person planned or said comes next, never suggestions or ideas of the agent.'),
            'decisions' => $lines('Decisions taken, worth remembering.'),
            'task_changes' => $schema->array()->items($schema->object([
                'id' => $schema->integer()->required()->description('Task number, e.g. 12.'),
                'status' => $schema->string()->enum(['todo', 'doing', 'blocked', 'done', 'dropped'])->required(),
                'reason' => $schema->string()->description('What a blocked task is waiting for.'),
                'proof' => $schema->string()->description('For done: the proof - the commit, the address where the change shows, or a short note. Kept on the task.'),
            ]))->description('Tasks this update moves (from my_open_tasks): done, still doing, blocked, dropped. Their lines are added automatically; do not repeat them in done / doing.'),
            'commits' => $schema->array()->items($schema->object([
                'hash' => $schema->string()->required()->description('The commit hash (short or full).'),
                'subject' => $schema->string()->required()->description('The commit message\'s first line, as written.'),
                'date' => $schema->string()->description('The commit date, YYYY-MM-DD.'),
            ]))->description('The commits in this update\'s git log whose message mentions a task number (#12, fixes #12): they show on that task\'s page. Only those; the server ignores numbers that are not tasks of this project.'),
            'new_tasks' => $lines('Titles of new tasks for the person, from "next" lines they agreed to turn into tasks. Needs a project.'),
            'notes' => $lines('What colleagues should know about this project (access, a convention, a gotcha; never secrets), if the person said so. Needs a project.'),
            'minutes' => $schema->integer()->min(0)->max(1440)->description('Only if the person corrected the measured time of the day.'),
            'estimated_minutes' => $schema->integer()->min(0)->max(1440)->description('Only for a day without measured time (see days_worked / unreported_days): the time estimated from the commit times, confirmed by the person. Kept apart from measured hours; ignored if the day has measured time.'),
            'started_at' => $schema->string()->description('When the work started today, ISO 8601. Optional: by default the server knows it from the first agent session of the day.'),
            'period_from' => $schema->string()->description('Start of the period covered, ISO 8601. Optional: by default the previous update.'),
            'links' => $lines('Commit hashes, pull request or page URLs.'),
            'focus' => $schema->string()->max(200)->description('What the person is focused on now, if different from the first "doing" line.'),
            'internal' => $schema->boolean()->description('true if the client must not see this update (only on projects visible to the client; see client_sees_updates in last_update).'),
            'next_done' => $schema->array()->items($schema->integer())->description('Ids from open_next (last_update) that this work finished: each becomes a done line.'),
            'next_dropped' => $schema->array()->items($schema->integer())->description('Ids from open_next that will not be done any more: they disappear from today\'s update.'),
            'blocked_resolved' => $schema->array()->items($schema->integer())->description('Ids from open_blockers (last_update) that are not blocked any more: they leave the blockers. Do not repeat them in blocked.'),
            'decisions_to_context' => $schema->boolean()->description('true if the person agreed to add this update\'s decisions to the project context (/ctx): each becomes a dated line under Decisions; the rest of the context is not touched, decisions already there are skipped.'),
            'separate' => $schema->boolean()->description('Only if the person explicitly wants a second, separate update for a day that already has one. By default, work on a day that already has an update (today_update in last_update, from another session) is added to it: new lines appended, summary replaced by yours (write it for the whole day).'),
            'agent' => $schema->string()->description('The agent writing this: claude-code, codex, cursor.'),
            'git' => $git(),
            'days' => $schema->array()->items($schema->object([
                'date' => $schema->string()->required()->description('The day, YYYY-MM-DD, in the person\'s timezone.'),
                'summary' => $schema->string()->required()->description('One sentence: what changed that day.'),
                'minutes' => $schema->integer()->description('Only if the person corrected the measured time of that day.'),
                'estimated_minutes' => $schema->integer()->description('Only for a day without measured time: estimated from the commit times, confirmed by the person.'),
                'done' => $schema->array()->items($schema->string())->description('What was finished that day.'),
                'decisions' => $schema->array()->items($schema->string())->description('Decisions taken that day.'),
                'git' => $git(),
            ]))->description('Earlier days, when the work since the last update spans several days (see days_worked, unreported_days and the commit dates): one entry per day, each saved on its day with the time measured for it, back to oldest_day (the window the admin set, often months), all in one call. The latest day goes in the main fields.'),
            'untimed' => $schema->array()->items($schema->object([
                'date' => $schema->string()->required()->description('A day from untimed_days (last_update), YYYY-MM-DD.'),
                'estimated_minutes' => $schema->integer()->required()->description('The time estimated for it (commit times, file modification times, or what the person said), confirmed by the person.'),
            ]))->description('Estimated time for days reported earlier with no time at all (untimed_days in last_update). Only the time is added, to that day\'s update; a day that already has time is skipped.'),
            'date' => $schema->string()->description('The day of the main update, YYYY-MM-DD, only if the latest day worked was not today. Any day from last_update\'s oldest_day on (the team\'s reporting window, often months), not only the last two weeks.'),
        ];
    }
}
