<?php

namespace App\Mcp\Tools;

use App\Actions\EditUpdate;
use App\Enums\Source;
use App\Models\Update;
use App\Support\Settings;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

/** Corectarea unui update deja trimis, cu motivul obligatoriu: totul rămâne în istoria lui (pagina update-ului, „Changes"). */
#[Name('edit_update')]
#[Description('Corrects an update already sent (or deletes one sent twice or by mistake, with delete): its summary, its lines (done, doing, blocked, next, decisions), its time, its day, or moves some lines to the day they were really done (split). Every change is kept in the update\'s history with your reason, so give a real one (e.g. "the gallery was done on 6 Oct, per the commits"). Only the author or an admin; only after the person agreed to the correction. Never send a second update to fix one: correct it.')]
class EditUpdateTool extends TeamTool
{
    protected bool $forClients = true;                          // și clientul (doar proiectele și lucrurile lui)

    protected bool $suggestsRepos = true;

    private const LINES = ['done' => 'done', 'doing' => 'doing', 'blocked' => 'blocked', 'next' => 'next', 'decisions' => 'decision'];

    public function handle(Request $request): Response|ResponseFactory
    {
        $rules = ['update' => 'nullable|integer', 'reason' => 'required|string|max:300', 'summary' => 'nullable|string|max:500', 'minutes' => 'nullable|integer|min:0|max:1440',
            'date' => 'nullable|date_format:Y-m-d', 'move_to_project' => 'nullable|string|max:200', 'split' => 'nullable|array|max:20', 'split.*.date' => 'required|date_format:Y-m-d', 'split.*.summary' => 'required|string|max:500',
            'split.*.lines' => 'required|array|min:1', 'split.*.lines.*' => 'string|max:500', 'project' => 'nullable|string|max:200', 'repo' => 'nullable|string|max:2000', 'agent' => 'nullable|string|max:50', 'delete' => 'nullable|boolean'];
        foreach (array_keys(self::LINES) as $field) {
            $rules[$field] = 'nullable|array|max:30';
            $rules["{$field}.*"] = 'string|max:500';
        }
        $this->plainBooleans($request);
        $data = $request->validate($rules);
        if ($request->user()->isClient()) {                                     // clientul: fără timp
            unset($data['minutes']);
        }
        $user = $request->user();

        if (isset($data['update'])) {
            $update = Update::with(['project', 'user'])->find($data['update']);
            if ($update && ! $user->can('view', $update)) {                     // unul pe care nu-l vede nu există pentru el (nici al cui e)
                $update = null;
            }
            $this->record($request, $update?->project);
            if (! $update) {
                return Response::error("There is no update #{$data['update']}. Nothing was changed.");
            }
        } else {
            [$project, $error] = $this->findProject($data['project'] ?? null, $data['repo'] ?? null, $user, session: $request->get('session'));
            $this->record($request, $project);
            $update = $user->updates()->with(['project', 'user'])->when($project, fn ($q) => $q->where('project_id', $project->id))->latest()->first();
            if (! $update) {
                return Response::error(($error ?? 'You have no update here yet.').' Pass update (its number, from last_update or project_timeline). Nothing was changed.');
            }
        }
        if (! $user->can('update', $update)) {
            return Response::error("Update #{$update->id} is {$update->user->name}'s: only its author or an admin can correct it. Nothing was changed.");
        }
        // ștergerea (o raportare trimisă de două ori, una pe proiectul greșit care nu se mută): ca „Șterge" de pe web, autorul sau adminul
        if ($data['delete'] ?? false) {
            if (! $user->can('delete', $update)) {
                return Response::error("Only the author or an admin can delete update #{$update->id}. Nothing was deleted.");
            }
            $label = "#{$update->id} ({$update->created_at->setTimezone($update->user->tz())->toDateString()}".($update->project ? ", {$update->project->name}" : '').')';
            $update->delete();

            return Response::text("Update {$label} deleted, with its lines and its time. Reason: {$data['reason']}");
        }

        $tz = $update->user->tz();
        $oldest = now($tz)->subDays(Settings::get('history_days'))->toDateString();
        $today = now($tz)->toDateString();
        foreach (array_merge(isset($data['date']) ? [$data['date']] : [], array_column($data['split'] ?? [], 'date')) as $date) {
            if ($date < $oldest || $date > $today) {
                return Response::error("Day {$date} is outside {$oldest} to {$today}. Nothing was changed.");
            }
        }
        if (! empty($data['split']) && ! $update->project_id) {
            return Response::error('Only an update on a project can be split by day. Nothing was changed.');
        }

        $target = null;
        if (filled($data['move_to_project'] ?? null)) {                       // pus pe proiectul greșit: alt proiect (cu motivul, în istorie)
            [$target, $error] = $this->findProject($data['move_to_project'], null, $user);
            if (! $target) {
                return Response::error(($error ?? "No project matches \"{$data['move_to_project']}\".").' Nothing was changed.');
            }
        }
        $changes = array_filter([
            'summary' => $data['summary'] ?? null,
            'project' => $target,
            'date' => $data['date'] ?? null,
            'lines' => collect(self::LINES)->filter(fn ($type, $field) => isset($data[$field]))->mapWithKeys(fn ($type, $field) => [$type => $data[$field]])->all() ?: null,
            'split' => $data['split'] ?? null,
        ], fn ($value) => $value !== null) + (array_key_exists('minutes', $data) && $data['minutes'] !== null ? ['minutes' => $data['minutes']] : []);
        if (! $changes) {
            return Response::error('Say what to correct: summary, lines, minutes, date, split or move_to_project. Nothing was changed.');
        }

        $source = isset($data['agent']) ? Source::fromAgent($data['agent']) : Source::fromRequest(request());
        try {
            $touched = app(EditUpdate::class)->apply($user, $update, $changes, $data['reason'], $source);
        } catch (InvalidArgumentException $e) {
            return Response::error($e->getMessage().' Nothing was changed.');
        }

        return Response::structured([
            'corrected' => collect($touched)->map(fn (Update $u) => ['id' => $u->id, 'date' => $u->fresh()->created_at->copy()->setTimezone($tz)->toDateString(), 'summary' => $u->fresh()->summary,
                'url' => route('filament.admin.resources.updates.view', $u)])->all(),
            'message' => 'Corrected; the change and its reason are in the update\'s history. Tell the person and give them the link.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        $lines = fn (string $what) => $schema->array()->items($schema->string())->description($what.' Replaces the lines of this kind written without a task; lines tied to tasks stay.');

        return [
            'reason' => $schema->string()->max(300)->required()->description('Why, in one sentence the team understands, e.g. "date correction: the work was done on 6 Oct, per the commits".'),
            'update' => $schema->integer()->description('The update\'s number. Leave it out for your last update on this session\'s project.'),
            'delete' => $schema->boolean()->description('Deletes the whole update, with its lines and time: only for one sent twice or by mistake, never to correct it (correct it instead). Only after the person confirmed.'),
            'summary' => $schema->string()->max(500)->description('A corrected summary.'),
            'done' => $lines('Corrected done lines.'),
            'doing' => $lines('Corrected in-progress lines.'),
            'blocked' => $lines('Corrected blocked lines.'),
            'next' => $lines('Corrected next lines.'),
            'decisions' => $lines('Corrected decisions.'),
            'minutes' => $schema->integer()->min(0)->max(1440)->description('Corrected time, only if the person gives it.'),
            'date' => $schema->string()->description('Move the whole update to this day (YYYY-MM-DD); its time becomes what was measured that day.'),
            'move_to_project' => $schema->string()->description('The project (slug or name) the update really belongs to, when it was reported on the wrong one. Its notes and estimated time go with it; lines linked to tasks of the old project stay as text.'),
            'split' => $schema->array()->items($schema->object([
                'date' => $schema->string()->required()->description('The day those lines were really done, YYYY-MM-DD.'),
                'summary' => $schema->string()->required()->description('A one-sentence summary, used if that day has no update yet.'),
                'lines' => $schema->array()->items($schema->string())->required()->description('The lines to move, exactly as they are in the update.'),
            ]))->description('Move some lines to the day they were done: into that day\'s update, created if missing.'),
            'session' => $schema->string()->description(self::SESSION),
            'repo' => $schema->string()->description('The git remotes of the working directory, to find your last update here.'),
            'project' => $schema->string()->description('Project slug or name, if not this session\'s.'),
            'agent' => $schema->string()->description('The agent writing this: claude-code, codex, cursor.'),
        ];
    }
}
