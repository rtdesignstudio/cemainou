<?php

namespace App\Mcp\Tools;

use App\Models\TimeEntry;
use App\Support\Settings;
use App\Support\WorkTime;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

/**
 * Timpul adăugat de om (add_time) sau estimat la /upd, corectat: mutat pe alt proiect, pe altă zi, micșorat / mărit, cu altă notă, sau șters.
 * Fără id: intrările omului din ultimele zile, ca să aleagă. Doar ale lui; timpul măsurat din sesiuni nu se schimbă de aici.
 */
#[Name('edit_time')]
#[Description('Corrects time the person added (add_time) or that was estimated at /upd: move it to another project or day, change the minutes or the note, or delete it. Without id it lists the person\'s added time of the last days, with ids, to choose from. Only the person\'s own entries; time measured from sessions cannot be changed here. Only when the person asks.')]
class EditTimeTool extends TeamTool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $this->plainBooleans($request);                                  // „true" ca text, de la agenții cu definiția veche
        $data = $request->validate([
            'id' => 'nullable|integer', 'project' => 'nullable|string|max:200', 'minutes' => 'nullable|integer|min:1|max:720',
            'date' => 'nullable|date_format:Y-m-d', 'note' => 'nullable|string|max:200', 'delete' => 'nullable|boolean', 'days' => 'nullable|integer|min:1|max:1000',
        ]);
        $user = $request->user();
        $tz = $this->tz($user);
        $this->record($request);

        if (! isset($data['id'])) {                                                // de ales: intrările omului din ultimele zile
            $since = now($tz)->subDays($data['days'] ?? 30)->toDateString();
            $entries = TimeEntry::with('project')->where('user_id', $user->id)->where('is_correction', false)->whereDate('date', '>=', $since)->latest('date')->latest('id')->limit(100)->get();

            return Response::structured([
                'entries' => $entries->map(fn (TimeEntry $e) => array_filter(['id' => $e->id, 'date' => $e->date->toDateString(), 'project' => $e->project?->name,
                    'minutes' => $e->minutes, 'time' => WorkTime::format($e->minutes), 'note' => $e->note, 'estimated' => $e->is_estimate ? true : null], fn ($v) => $v !== null))->all(),
                'message' => $entries->isEmpty() ? 'No added time in that period.' : 'Ask the person which entry to change, then call edit_time with its id.',
            ]);
        }

        $entry = TimeEntry::with('project')->where('user_id', $user->id)->where('is_correction', false)->find($data['id']);
        if (! $entry) {
            return Response::error("There is no added time #{$data['id']} of yours.");
        }
        $before = ($entry->project?->name ?? 'no project').', '.$entry->date->toDateString().', '.WorkTime::format($entry->minutes);
        if ($data['delete'] ?? false) {
            $entry->delete();

            return Response::structured(['deleted' => true, 'message' => "Deleted the added time ({$before})."]);
        }

        $changes = [];
        if (filled($data['project'] ?? null)) {
            [$project, $error] = $this->findProject($data['project'], null, $user);
            if (! $project) {
                return Response::error(($error ?? "No project matches \"{$data['project']}\".").' Nothing was changed.');
            }
            $changes['project_id'] = $project->id;
        }
        if (isset($data['date'])) {
            $window = Settings::get('report_days');
            if ($data['date'] > now($tz)->toDateString() || $data['date'] < now($tz)->subDays($window)->toDateString()) {
                return Response::error("date must be today or one of the last {$window} days. Nothing was changed.");
            }
            $changes['date'] = $data['date'];
        }
        if (isset($data['minutes'])) {
            $changes['minutes'] = $data['minutes'];
        }
        if (array_key_exists('note', $data)) {
            $changes['note'] = filled($data['note']) ? trim($data['note']) : null;
        }
        if (! $changes) {
            return Response::error('Say what to change: project, date, minutes, note, or delete: true. Nothing was changed.');
        }
        $entry->forceFill($changes)->save();
        $entry->refresh()->load('project');

        return Response::structured([
            'saved' => true,
            'entry' => array_filter(['id' => $entry->id, 'date' => $entry->date->toDateString(), 'project' => $entry->project?->name, 'minutes' => $entry->minutes, 'note' => $entry->note]),
            'message' => "Changed: {$before} → ".($entry->project?->name ?? 'no project').', '.$entry->date->toDateString().', '.WorkTime::format($entry->minutes).'.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('The added time to change (from add_time or from the list edit_time gives without id).'),
            'project' => $schema->string()->description('Move it to this project (slug or name).'),
            'minutes' => $schema->integer()->min(1)->max(720)->description('The new length, in minutes (smaller or larger).'),
            'date' => $schema->string()->description('Move it to this day, YYYY-MM-DD.'),
            'note' => $schema->string()->description('A new note; empty to remove it.'),
            'delete' => $schema->boolean()->description('true deletes it.'),
            'days' => $schema->integer()->description('Without id: how many days back to list (default 30).'),
        ];
    }
}
