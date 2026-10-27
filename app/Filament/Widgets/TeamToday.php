<?php

namespace App\Filament\Widgets;

use App\Enums\TaskStatus;
use App\Enums\UpdateItemType;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Resources\Updates\UpdateResource;
use App\Models\Absence;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\MyDay;
use App\Support\PageCache;
use App\Support\SetupSteps;
use App\Support\TeamSetup;
use App\Support\WorkTime;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/** Echipa azi: un card pe om. Ora de început și timpul activ le vede doar adminul sau omul însuși. */
class TeamToday extends Widget
{
    /** Echipa, nu clientul (el vede doar proiectele lui). */
    public static function canView(): bool
    {
        return auth()->check() && auth()->user()->seesWholeTeam();                 // echipa întreagă: nu clientul, nici colaboratorul
    }

    protected string $view = 'filament.widgets.team-today';

    protected static bool $isLazy = false;                       // interogări mici: pagina se desenează odată, fără salturi

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 1;

    protected function getViewData(): array
    {
        $viewer = auth()->user();
        $users = User::query()->team()
            ->with(['updates' => fn ($q) => $q->visibleTo($viewer)->with('project.client')
                ->withCount(['items as blocked_count' => fn ($q) => $q->where('type', UpdateItemType::Blocked->value)->whereNull('resolved_at')])->latest()->limit(1)])
            ->withCount(['assignedTasks as doing_count' => fn ($q) => $q->where('status', TaskStatus::Doing->value)])
            ->orderByDesc('last_seen_at')->orderBy('name')->get();

        // proiectul în care e fiecare acum: ultimul semnal cu proiect din ultimele 15 minute (hook-urile trimit remote-ul folderului)
        $nowIn = Activity::query()->whereIn('user_id', $users->pluck('id'))->whereNotNull('project_id')
            ->where('occurred_at', '>', now()->subMinutes(WorkTime::gap()))->whereIn('project_id', Project::visibleTo($viewer)->select('id'))->with('project.client')
            ->orderByDesc('occurred_at')->get()->unique('user_id')->keyBy('user_id');

        // sarcinile în lucru ale fiecăruia: pentru un „Lucrează la" fără sursă, duce la sarcină când e una singură (pe proiectul de acum, dacă are mai multe)
        $doing = Task::visibleTo($viewer)->whereIn('assignee_id', $users->pluck('id'))->where('status', TaskStatus::Doing->value)->get(['id', 'assignee_id', 'project_id'])->groupBy('assignee_id');

        $times = WorkTime::forUsers($users);                                           // trei interogări pentru toți, nu trei pe om
        // timpul adăugat de mână azi (ședințe, telefoane) și corecțiile: intră în timpul de pe card, ca pe pagina omului (feedback din echipă)
        $added = TimeEntry::whereIn('user_id', $users->pluck('id'))->where('is_estimate', false)
            ->whereDate('date', '>=', now()->subDay()->toDateString())->get(['user_id', 'date', 'minutes'])
            ->filter(fn ($e) => $e->date->toDateString() === now($users->firstWhere('id', $e->user_id)?->tz())->toDateString())
            ->groupBy('user_id')->map(fn ($entries) => (int) $entries->sum('minutes'));
        // ziua pe ore, pe card (bare mici): măsurat plin, adăugat de mână hașurat; doar la cine are voie să-i vadă orele
        $timed = $users->filter(fn (User $user) => $viewer->can('viewWorkTime', $user));
        $hourly = WorkTime::hourlyForUsers($timed);
        $entries = TimeEntry::whereIn('user_id', $timed->pluck('id'))->where('is_correction', false)->whereDate('date', '>=', now()->subDay()->toDateString())
            ->get(['user_id', 'date', 'minutes', 'start_minute'])->filter(fn ($e) => $e->date->toDateString() === now($users->firstWhere('id', $e->user_id)?->tz())->toDateString())->groupBy('user_id');
        $hours = $timed->mapWithKeys(function (User $user) use ($hourly, $entries) {
            $measured = $hourly[$user->id] ?? array_fill(0, 24, 0);
            $placed = MyDay::placeAdded($entries->get($user->id, collect()), $measured, (int) now($user->tz())->format('G'));

            return [$user->id => ['measured' => $measured, 'added' => $placed['hours'], 'now' => (int) now($user->tz())->format('G')]];
        });
        // uneltele fiecăruia (agent, timp măsurat, /upd...): adminul le vede la toți, fiecare la el
        $whose = $viewer->isAdmin() ? $users : $users->where('id', $viewer->id);
        $setup = PageCache::data('team-setup', $viewer, [$whose->pluck('id')->values()->all()], fn () => TeamSetup::for($whose));   // 10 minute: cheile, hook-urile, telefonul nu se schimbă de la un minut la altul
        $people = $users->map(function (User $user) use ($viewer, $nowIn, $times, $doing, $setup, $added, $hours) {
            $time = $viewer->can('viewWorkTime', $user) ? $times[$user->id] : null;
            $lastSeen = $user->last_seen_at;
            $active = $lastSeen && $lastSeen->gt(now()->subMinutes(WorkTime::gap()));
            $here = $nowIn->get($user->id)?->project;
            $last = $user->updates->first();

            return [
                'user' => $user,
                'doing' => $user->doing_count,
                'active' => $active,
                'now_in' => $here,
                // „Acum în": doar cu o sesiune activă pe alt proiect decât cel al ultimului update (acolo se vede deja)
                'elsewhere' => $active && $here && $here->id !== $last?->project_id ? $here : null,
                // „Azi:": ce a spus omul azi că face sau sarcina luată azi; nu rândurile „în lucru" din update-uri, care rămân agățate
                'today' => $user->focusSaidToday() ? ['text' => $user->focus_text, 'url' => $this->focusUrl($user, $doing->get($user->id) ?? collect())] : null,
                'last_seen' => $lastSeen,
                'time' => $time,
                'hours' => ($h = $hours->get($user->id)) && array_sum($h['measured']) + array_sum($h['added']) > 0 ? $h : null,
                'added' => $time !== null ? (int) ($added[$user->id] ?? 0) : 0,
                'total' => $time !== null ? max(0, $time['active_minutes'] + (int) ($added[$user->id] ?? 0)) : 0,
                'start_local' => $time && $time['start'] ? $time['start']->copy()->setTimezone($user->tz())->format('H:i') : null,
                'last_update' => $last,
                'setup' => $setup[$user->id] ?? null,
                'self' => $user->is($viewer),
                // „Trimite-i pașii": adminul, la un coleg cu unelte gri sau rămase în urmă (o dată pe zi)
                'steps' => $viewer->isAdmin() && ! $user->is($viewer) && collect($setup[$user->id] ?? [])->contains(fn (array $s) => $s['state'] !== 'ok')
                    ? (SetupSteps::sentToday($user) ? 'sent' : 'send') : null,
            ];
        });

        $today = now(config('app.timezone'))->toDateString();
        $away = Absence::whereIn('user_id', $users->pluck('id'))->whereDate('from', '<=', $today)->whereDate('to', '>=', $today)
            ->get()->keyBy('user_id');                                   // cine e în concediu azi: „În concediu până pe ..." pe cardul lui

        return ['people' => $people, 'away' => $away, 'problems' => $viewer->isAdmin() ? TeamSetup::problems($setup) : [], 'profile' => filament()->getProfileUrl()];
    }

    /** Pașii care îi lipsesc colegului, pe Telegram sau pe email (App\Support\SetupSteps). */
    public function sendSteps(int $userId): void
    {
        $viewer = auth()->user();
        abort_unless($viewer->isAdmin(), 403);
        $person = User::query()->team()->whereKeyNot($viewer->id)->findOrFail($userId);
        $channel = SetupSteps::sentToday($person) ? null : SetupSteps::send($person, $viewer, TeamSetup::for(collect([$person]))[$person->id] ?? []);
        $first = explode(' ', $person->name)[0];
        Notification::make()->title(match ($channel) {
            'telegram' => __('The steps went to :name on Telegram.', ['name' => $first]),
            'email' => __('The steps went to :name by email.', ['name' => $first]),
            'bell' => __('The steps are in :name\'s bell (no email or Telegram set up).', ['name' => $first]),
            default => __('Nothing to send: :name has everything set up, or got the steps today.', ['name' => $first]),
        })->success()->send();
    }

    /**
     * Unde duce „Lucrează la": la sarcina sau la update-ul din care vine textul; pentru unul spus direct (set_focus), la singura sarcină în lucru
     * pe proiectul de acum (sau la singura în lucru); altfel nicăieri.
     */
    private function focusUrl(User $user, Collection $doing): ?string
    {
        if ($user->focus_task_id) {
            return TaskResource::getUrl('view', ['record' => $user->focus_task_id]);
        }
        if ($user->focus_update_id) {
            return UpdateResource::getUrl('view', ['record' => $user->focus_update_id]);
        }
        $here = $doing->where('project_id', $user->focus_project_id);
        $task = $here->count() === 1 ? $here->first() : ($doing->count() === 1 ? $doing->first() : null);

        return $task ? TaskResource::getUrl('view', ['record' => $task]) : null;
    }
}
