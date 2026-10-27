<?php

namespace App\Support;

use App\Enums\Role;
use App\Enums\TaskStatus;
use App\Models\Attachment;
use App\Models\Document;
use App\Models\Project;
use App\Models\PushSubscription;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\UpdateComment;
use App\Models\User;
use App\Models\WidgetDevice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Laravel\Passport\Token as OAuthToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Oamenii clienților, pentru echipă (secțiunea „Clienții" de pe Azi, 18 oct 2026): doar ai firmelor ale căror proiecte le vede omul.
 * Pentru fiecare: cine e și unde stă (vizita, invitația), ce a făcut (ultima acțiune, zilele active din 7), ce cere reacție (ce așteaptă
 * de la noi, ce așteaptă de la el) și cât de pornit e pe cemainou (primii pași). Totul doar din ce vede omul care se uită.
 */
class ClientPeople
{
    /** @return Collection<int, array<string, mixed>> cei cu ceva care așteaptă de la noi primii, apoi activii, apoi cei care n-au mai intrat */
    public static function for(User $viewer): Collection
    {
        $projects = Project::visibleTo($viewer)->where('is_archived', false)->whereNotNull('client_id')
            ->when(! $viewer->seesWholeTeam(), fn ($q) => $q->where('client_visible', true))   // colaboratorul: oamenii clientului doar pe proiectele vizibile clientului
            ->get(['id', 'name', 'client_id', 'client_visible']);
        if ($projects->isEmpty()) {
            return collect();
        }
        $people = User::with('client')->where('role', Role::Client->value)->where('is_active', true)->whereIn('client_id', $projects->pluck('client_id')->unique())->orderBy('name')->get();
        if ($people->isEmpty()) {
            return collect();
        }
        $ids = $people->pluck('id');
        $since = now()->subDays(7);
        $tz = $viewer->tz();
        $visible = fn () => Task::visibleTo($viewer);

        // ce au făcut, în 7 zile: cereri, comentarii (la sarcini și update-uri), fișiere; doar ce vede omul care se uită
        $tasks = $visible()->with('project')->whereIn('created_by', $ids)->where('created_at', '>=', $since)->get(['id', 'title', 'project_id', 'created_by', 'created_at', 'site_context']);
        $taskComments = TaskComment::with('task')->whereIn('user_id', $ids)->where('created_at', '>=', $since)
            ->whereHas('task', fn ($q) => $q->visibleTo($viewer))->get(['id', 'task_id', 'user_id', 'created_at']);
        $updateComments = UpdateComment::with('statusUpdate')->whereIn('user_id', $ids)->where('created_at', '>=', $since)
            ->whereHas('statusUpdate', fn ($q) => $q->visibleTo($viewer))->get(['id', 'update_id', 'user_id', 'created_at']);
        $files = Attachment::whereIn('user_id', $ids)->whereIn('project_id', $projects->pluck('id'))->where('created_at', '>=', $since)->get(['id', 'title', 'task_id', 'project_id', 'user_id', 'created_at']);

        // ce cere reacție: cererile lor deschise unde ultimul cuvânt e al lor, și sarcinile date lor
        $openFromThem = $visible()->with(['comments.author'])->open()->where(fn ($q) => $q->whereIn('created_by', $ids)->orWhereHas('comments', fn ($c) => $c->whereIn('user_id', $ids)))->get();
        $onThem = $visible()->open()->whereIn('assignee_id', $ids)->get(['id', 'title', 'assignee_id', 'created_at', 'updated_at']);

        // primii pași
        $pushed = PushSubscription::whereIn('user_id', $ids)->distinct()->pluck('user_id')->flip();
        $agents = OAuthToken::whereIn('user_id', $ids)->where('revoked', false)->distinct()->pluck('user_id')->merge(
            PersonalAccessToken::where('tokenable_type', User::class)->whereIn('tokenable_id', $ids)->distinct()->pluck('tokenable_id'))->flip();
        $widget = WidgetDevice::whereIn('user_id', $ids)->whereNull('revoked_at')->distinct()->pluck('user_id')->flip();
        $everAsked = Task::whereIn('created_by', $ids)->distinct()->pluck('created_by')->flip();
        $everFile = Attachment::whereIn('user_id', $ids)->distinct()->pluck('user_id')->merge(Document::whereIn('user_id', $ids)->distinct()->pluck('user_id'))->flip();

        return $people->map(function (User $person) use ($projects, $tasks, $taskComments, $updateComments, $files, $openFromThem, $onThem, $pushed, $agents, $widget, $everAsked, $everFile, $tz) {
            $id = $person->id;
            $actions = collect()
                ->concat($tasks->where('created_by', $id)->map(fn (Task $t) => ['at' => $t->created_at, 'text' => $t->site_context ? __('sent feedback from the site: #:id', ['id' => $t->id]) : __('sent the request #:id', ['id' => $t->id]), 'title' => $t->title, 'url' => route('filament.admin.resources.tasks.view', $t)]))
                ->concat($taskComments->where('user_id', $id)->map(fn (TaskComment $c) => ['at' => $c->created_at, 'text' => __('commented on #:id', ['id' => $c->task_id]), 'title' => $c->task?->title, 'url' => route('filament.admin.resources.tasks.view', $c->task_id).'#comments']))
                ->concat($updateComments->where('user_id', $id)->map(fn (UpdateComment $c) => ['at' => $c->created_at, 'text' => __('commented on an update'), 'title' => $c->statusUpdate?->summary, 'url' => route('filament.admin.resources.updates.view', $c->update_id)]))
                ->concat($files->where('user_id', $id)->map(fn (Attachment $a) => ['at' => $a->created_at, 'text' => __('added a file'), 'title' => $a->title, 'url' => $a->task_id ? route('filament.admin.resources.tasks.view', $a->task_id) : route('filament.admin.resources.projects.view', $a->project_id)]))
                ->sortByDesc('at')->values();
            $days = $actions->map(fn (array $a) => $a['at']->copy()->setTimezone($tz)->toDateString());
            if ($person->visited_at) {
                $days->push($person->visited_at->copy()->setTimezone($tz)->toDateString());
            }
            $week = collect(range(6, 0))->map(fn (int $back) => ['date' => ($d = now($tz)->subDays($back))->toDateString(), 'label' => $d->locale(app()->getLocale())->isoFormat('dd D'),
                'on' => $days->contains($d->toDateString())])->all();

            // așteaptă de la noi: deschise unde ultimul cuvânt e al lui (sau cererea lui, încă neîncepută, fără răspuns)
            $waiting = $openFromThem->filter(function (Task $t) use ($id) {
                $last = $t->comments->last();

                return $last ? $last->user_id === $id : ($t->created_by === $id && $t->status === TaskStatus::Todo);
            })->map(fn (Task $t) => ['task' => $t, 'since' => $t->comments->last()?->created_at ?? $t->created_at])->sortBy('since')->values();
            $mine = $onThem->where('assignee_id', $id)->sortBy('created_at')->values();

            $phone = $person->app_installed_at !== null || $pushed->has($id);
            $steps = [
                ['label' => __('Request'), 'done' => $everAsked->has($id)],
                ['label' => __('File'), 'done' => $everFile->has($id)],
                ['label' => __('Phone'), 'done' => $phone],
                ['label' => __('Notifications'), 'done' => $pushed->has($id)],
                ['label' => __('Agent'), 'done' => $agents->has($id)],
                ['label' => __('Site widget'), 'done' => $widget->has($id)],
            ];
            $neverIn = $person->visited_at === null && Invitations::pending($person);

            return [
                'user' => $person,
                'company' => $person->client?->name,
                'projects' => $projects->where('client_id', $person->client_id)->filter(fn (Project $p) => $p->client_visible)->pluck('name')->values()->all(),
                'now' => $person->visited_at && $person->visited_at->gt(now()->subMinutes(WorkTime::gap())),
                'seen' => $person->visited_at,
                'never' => $neverIn,
                'last' => $actions->first(),
                'week' => $week,
                'waiting' => $waiting,
                'on_them' => $mine,
                'steps' => $steps,
                'steps_done' => collect($steps)->where('done', true)->count(),
                'rank' => [$waiting->isNotEmpty() ? 0 : 1, $person->visited_at && $person->visited_at->isToday() ? 0 : 1, $neverIn ? 1 : 0, -($person->visited_at?->getTimestamp() ?? 0)],
            ];
        })->sortBy('rank')->values();
    }

    /** Câte zile de când (pentru „de 3 zile"). */
    public static function days(Carbon $since): int
    {
        return (int) $since->diffInDays(now(), true);
    }
}
