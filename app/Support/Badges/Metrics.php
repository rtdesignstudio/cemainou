<?php

namespace App\Support\Badges;

use App\Enums\Role;
use App\Enums\TaskEventType;
use App\Enums\TaskStatus;
use App\Enums\UpdateItemType;
use App\Models\Absence;
use App\Models\Activity;
use App\Models\Badge;
use App\Models\DocumentVersion;
use App\Models\Feedback;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskCommit;
use App\Models\TaskEvent;
use App\Models\Update;
use App\Models\UpdateItem;
use App\Models\User;
use App\Models\WorkDay;
use App\Models\WorkSession;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Valorile badge-urilor unui om, din istoric (update-uri, evenimentele sarcinilor, zilele de lucru, comentarii, commit-uri): câte zile la rând,
 * câte sarcini, câte weekenduri libere... Treapta o dă Catalog::tierFor. Zilele sunt ale omului (fusul lui); zilele de lucru sunt luni-vineri.
 * Fiecare grup se citește o singură dată pe calcul.
 */
class Metrics
{
    private string $tz;

    private string $today;

    private ?Collection $updates = null;

    private ?Collection $done = null;

    private ?Collection $workDays = null;

    /** @var array<string, true>|null zilele de concediu marcate de om */
    private ?array $away = null;

    private function away(): array
    {
        return $this->away ??= Absence::daysOf($this->user);
    }

    /** O zi de lucru care se cere raportată: luni-vineri, fără concediu. */
    private function workday(Carbon $day): bool
    {
        return $day->isWeekday() && ! isset($this->away()[$day->toDateString()]);
    }

    public function __construct(private User $user)
    {
        $this->tz = $user->tz();
        $this->today = now($this->tz)->toDateString();
    }

    /** @return array<string, int> cheia badge-ului → valoarea de acum */
    public function all(): array
    {
        $values = [];
        foreach (array_keys(Catalog::all()) as $key) {
            if ($key !== 'triple') {                                          // „Triplă" se socotește după ce s-au salvat celelalte
                $values[$key] = $this->value($key);
            }
        }

        return $values;
    }

    public function value(string $key): int
    {
        return match ($key) {
            'first_day' => min(1, $this->updates()->count()),
            'streak' => $this->streak(),
            'days_reported' => $this->reportedDays()->count(),
            'full_week' => $this->fullWeeksInARow(),
            'on_time' => $this->updates()->filter(fn ($u) => $u->same_day)->pluck('day')->unique()->count(),
            'first_task' => min(1, $this->done()->count()),
            'stack' => $this->done()->where('qualifies', true)->count(),
            'proof' => $this->done()->filter(fn ($d) => filled($d->proof))->count(),
            'on_deadline' => $this->done()->filter(fn ($d) => $d->due_on && $d->day <= $d->due_on)->count(),
            'cleanup' => $this->cleanup(),
            'full_day' => $this->done()->where('qualifies', true)->countBy('day')->filter(fn (int $n) => $n >= 5)->count(),
            'unblocker' => $this->unblocks(),
            'quick_reply' => $this->quickReplies(),
            'take_free' => $this->tookFree(),
            'welcome' => User::where('invited_by', $this->user->id)->whereHas('updates')->count(),
            'client_eye' => $this->done()->where('from_client', true)->count(),
            'decisions' => $this->decisions(),
            'context' => DocumentVersion::where('user_id', $this->user->id)->distinct()->count('document_id'),
            'history' => $this->updates()->filter(fn ($u) => $u->late_days >= 2)->pluck('project_id')->filter()->unique()->count(),
            'linked' => TaskCommit::where('user_id', $this->user->id)->count(),
            'zero_backlog' => DB::table('badge_marks')->where('user_id', $this->user->id)->where('key', 'zero_backlog')->count(),
            'connected' => (int) (Activity::where('user_id', $this->user->id)->where('kind', 'session_start')->exists()
                || WorkSession::where('user_id', $this->user->id)->exists() || WorkDay::where('user_id', $this->user->id)->where('agent_minutes', '>', 0)->exists()),
            'two_agents' => $this->tools(),
            'updates_total' => $this->updates()->count(),
            'weekend_off' => $this->weekendsOff(),
            'evening' => $this->evenings(),
            'healthy' => $this->healthyMonths(),
            'comeback' => $this->comebacks(),
            'whole_team' => $this->wholeTeamDays(),
            'project_streak' => $this->projectStreak(),
            'all_clear' => (int) $this->unblockDays()->countBy(fn (string $d) => Carbon::parse($d)->startOfWeek()->toDateString())->contains(fn (int $n) => $n >= 3),
            'quiet_week' => (int) DB::table('badge_marks')->where('user_id', $this->user->id)->where('key', 'zero_backlog')->pluck('date')
                ->map(fn ($d) => Carbon::parse($d))->filter(fn (Carbon $d) => $d->isWeekday())->countBy(fn (Carbon $d) => $d->copy()->startOfWeek()->toDateString())->contains(fn (int $n) => $n >= 5),
            'helper_week' => (int) collect($this->replyDays())->unique()->countBy(fn (string $d) => Carbon::parse($d)->startOfWeek()->toDateString())->contains(fn (int $n) => $n >= 5),
            'no_blockers' => $this->weeksWithoutBlockers(),
            'stage_on_time' => $this->stages()['on_time'],
            'launch' => $this->stages()['ended'],
            'project_year' => $this->projectYears(),
            'bug_hunter' => Feedback::where('user_id', $this->user->id)->where('status', 'resolved')->count(),
            'one_year' => ($first = $this->updates()->min('day')) ? (int) Carbon::parse($first, $this->tz)->diffInYears(Carbon::parse($this->today, $this->tz)) : 0,
            'new_year' => (int) $this->updates()->contains(fn ($u) => substr($u->day, 5, 2) === '01' && (int) substr($u->day, 8, 2) <= 7),
            'triple' => (int) Badge::where('user_id', $this->user->id)->where('backfilled', false)->where('key', '!=', 'triple')->get(['earned_at'])
                ->countBy(fn (Badge $b) => $b->earned_at->copy()->setTimezone($this->tz)->toDateString())->contains(fn (int $n) => $n >= 3),
            default => 0,
        };
    }

    /** Update-urile omului, cu ziua lor (fusul omului), dacă au fost trimise în aceeași zi și cu câte zile mai târziu. */
    private function updates(): Collection
    {
        return $this->updates ??= DB::table('updates')->where('user_id', $this->user->id)->get(['project_id', 'created_at', 'reported_at', 'source'])   // direct: o singură citire, sursa ca text
            ->map(function ($u) {
                $at = Carbon::parse($u->created_at, 'UTC');
                $day = $at->copy()->setTimezone($this->tz)->toDateString();
                $sent = Carbon::parse($u->reported_at ?? $u->created_at, 'UTC')->setTimezone($this->tz)->toDateString();

                return (object) ['project_id' => $u->project_id ? (int) $u->project_id : null, 'day' => $day, 'same_day' => $sent === $day, 'source' => (string) $u->source,
                    'late_days' => (int) Carbon::parse($day)->diffInDays(Carbon::parse($sent)), 'at' => $at];
            });
    }

    /** @return Collection<int, string> zilele cu update, sortate */
    private function reportedDays(): Collection
    {
        return $this->updates()->pluck('day')->unique()->sort()->values();
    }

    /** Zile de lucru la rând cu update; o zi ratată pe lună e iertată („joker"); azi nu rupe seria cât încă se poate raporta. Cea mai lungă serie. */
    private function streak(): int
    {
        $days = $this->reportedDays()->flip();
        if ($days->isEmpty()) {
            return 0;
        }
        $best = $run = 0;
        $jokers = [];
        for ($d = Carbon::parse($days->keys()->first(), $this->tz); $d->toDateString() <= $this->today; $d->addDay()) {
            if (! $this->workday($d) && ! $days->has($d->toDateString())) {   // weekend sau concediu: nici nu rupe, nici nu adaugă (doar dacă a lucrat totuși)
                continue;
            }
            $date = $d->toDateString();
            if ($days->has($date)) {
                $best = max($best, ++$run);
            } elseif ($date === $this->today) {
                break;
            } elseif (empty($jokers[$d->format('Y-m')]) && $run > 0) {
                $jokers[$d->format('Y-m')] = true;
            } else {
                $run = 0;
            }
        }

        return $best;
    }

    /** Săptămâni la rând cu toate zilele de lucru raportate, fiecare în ziua ei. */
    private function fullWeeksInARow(): int
    {
        $onTime = $this->updates()->filter(fn ($u) => $u->same_day)->pluck('day')->unique()->flip();
        $best = $run = 0;
        foreach ($this->weeks() as $monday) {
            $days = collect(range(0, 4))->map(fn (int $i) => $monday->copy()->addDays($i));
            $full = $days->every(fn (Carbon $d) => $onTime->has($d->toDateString()) || ! $this->workday($d)) && $days->contains(fn (Carbon $d) => $onTime->has($d->toDateString()));
            $run = $full ? $run + 1 : 0;
            $best = max($best, $run);
        }

        return $best;
    }

    /** Săptămânile încheiate (luni), de la primul update până la cea trecută. @return list<Carbon> */
    private function weeks(): array
    {
        $first = $this->updates()->min('day');
        if (! $first) {
            return [];
        }
        $weeks = [];
        $end = Carbon::parse($this->today, $this->tz)->startOfWeek();
        for ($m = Carbon::parse($first, $this->tz)->startOfWeek(); $m->lt($end); $m->addWeek()) {
            $weeks[] = $m->copy();
        }

        return $weeks;
    }

    /** O săptămână întreagă raportată, apoi nimic sâmbătă și duminică. */
    private function weekendsOff(): int
    {
        $reported = $this->reportedDays()->flip();
        $worked = $this->workDays()->where('active_minutes', '>', 0)->pluck('date')->flip();

        return collect($this->weeks())->filter(function (Carbon $monday) use ($reported, $worked) {
            $days = collect(range(0, 4))->map(fn (int $i) => $monday->copy()->addDays($i));
            $full = $days->every(fn (Carbon $d) => $reported->has($d->toDateString()) || ! $this->workday($d)) && $days->contains(fn (Carbon $d) => $reported->has($d->toDateString()));
            $weekend = collect([5, 6])->map(fn (int $i) => $monday->copy()->addDays($i)->toDateString());

            return $full && $weekend->every(fn (string $day) => ! $reported->has($day) && ! $worked->has($day));
        })->count();
    }

    private function workDays(): Collection
    {
        return $this->workDays ??= WorkDay::where('user_id', $this->user->id)->get(['date', 'ended_at', 'active_minutes'])
            ->map(fn (WorkDay $w) => (object) ['date' => Carbon::parse($w->date)->toDateString(), 'ended_at' => $w->ended_at, 'active_minutes' => (int) $w->active_minutes]);
    }

    /** Zile de cel puțin o oră încheiate înainte de ora de seară a omului (implicit 19:00). */
    private function evenings(): int
    {
        $hour = (int) ($this->user->evening_hour ?: 19);

        return $this->workDays()->filter(fn ($w) => $w->active_minutes >= 60 && $w->ended_at && $w->date < $this->today
            && Carbon::parse($w->ended_at)->setTimezone($this->tz)->hour < $hour)->count();
    }

    /** Luni încheiate, cu cel puțin 10 zile lucrate, fără nicio zi de peste 10 ore active. */
    private function healthyMonths(): int
    {
        $current = substr($this->today, 0, 7);

        return $this->workDays()->where('active_minutes', '>', 0)->groupBy(fn ($w) => substr($w->date, 0, 7))
            ->filter(fn (Collection $days, string $month) => $month < $current && $days->count() >= 10 && $days->max('active_minutes') <= 600)->count();
    }

    /** Revenirea după cel puțin 5 zile lucrătoare fără nicio activitate (concediu), cu un update. */
    private function comebacks(): int
    {
        $reported = $this->reportedDays();
        $active = $reported->merge($this->workDays()->where('active_minutes', '>', 0)->pluck('date'))->unique()->sort()->values();
        $count = 0;
        foreach ($active as $i => $day) {
            if ($i === 0 || ! $reported->contains($day)) {
                continue;
            }
            $gap = $away = 0;
            for ($d = Carbon::parse($active[$i - 1])->addDay(); $d->toDateString() < $day; $d->addDay()) {
                $gap += $d->isWeekday() ? 1 : 0;
                $away += $d->isWeekday() && isset($this->away()[$d->toDateString()]) ? 1 : 0;
            }
            $count += $gap >= 5 || $away >= 3 ? 1 : 0;
        }

        return $count;
    }

    /**
     * Sarcinile închise de om (prima trecere la „gata"), câte o dată fiecare: ziua, termenul, dovada, dacă vin de la client sau din widget și
     * dacă se numără la „Teanc" (deschisă de cel puțin o oră sau dată de altcineva: o sarcină scrisă și închisă pe loc nu umflă nimic).
     */
    private function done(): Collection
    {
        return $this->done ??= TaskEvent::query()->join('tasks', 'tasks.id', '=', 'task_events.task_id')
            ->leftJoin('users as creators', 'creators.id', '=', 'tasks.created_by')
            ->where('task_events.user_id', $this->user->id)->where('task_events.type', TaskEventType::Status->value)->where('task_events.to', TaskStatus::Done->value)
            ->orderBy('task_events.id')
            ->get(['task_events.task_id', 'task_events.created_at as at', 'tasks.created_at as task_created_at', 'tasks.created_by', 'tasks.due_on',
                'tasks.proof', 'tasks.site_context', 'creators.role as creator_role'])
            ->unique('task_id')
            ->map(function ($e) {
                $at = Carbon::parse($e->at, 'UTC');

                return (object) [
                    'task_id' => $e->task_id, 'day' => $at->copy()->setTimezone($this->tz)->toDateString(), 'proof' => $e->proof,
                    'due_on' => $e->due_on ? Carbon::parse($e->due_on)->toDateString() : null,
                    'qualifies' => (int) $e->created_by !== $this->user->id || Carbon::parse($e->task_created_at, 'UTC')->diffInMinutes($at) >= 60,
                    'from_client' => $e->creator_role === Role::Client->value || filled($e->site_context),
                ];
            })->values();
    }

    /** Sarcini vechi (deschise de peste 30 de zile) închise sau abandonate de om. */
    private function cleanup(): int
    {
        return TaskEvent::query()->join('tasks', 'tasks.id', '=', 'task_events.task_id')
            ->where('task_events.user_id', $this->user->id)->where('task_events.type', TaskEventType::Status->value)
            ->whereIn('task_events.to', [TaskStatus::Done->value, TaskStatus::Dropped->value])
            ->get(['task_events.task_id', 'task_events.created_at as at', 'tasks.created_at as task_created_at'])
            ->filter(fn ($e) => Carbon::parse($e->task_created_at, 'UTC')->diffInDays(Carbon::parse($e->at, 'UTC')) > 30)
            ->unique('task_id')->count();
    }

    /** @return Collection<int, string> zilele în care omul a rezolvat blocajul altcuiva, câte una pe blocaj */
    private function unblockDays(): Collection
    {
        $day = fn ($at) => Carbon::parse($at, 'UTC')->setTimezone($this->tz)->toDateString();
        $lines = UpdateItem::query()->join('updates', 'updates.id', '=', 'update_items.update_id')
            ->where('update_items.type', UpdateItemType::Blocked->value)->where('update_items.resolved_by', $this->user->id)
            ->where('updates.user_id', '!=', $this->user->id)->whereNotNull('update_items.resolved_at')->pluck('update_items.resolved_at')->map($day);
        $tasks = TaskEvent::query()->join('tasks', 'tasks.id', '=', 'task_events.task_id')
            ->where('task_events.user_id', $this->user->id)->where('task_events.type', TaskEventType::Status->value)
            ->where('task_events.from', TaskStatus::Blocked->value)->whereNotNull('tasks.assignee_id')->where('tasks.assignee_id', '!=', $this->user->id)
            ->pluck('task_events.created_at')->map($day);

        return $lines->merge($tasks)->values();
    }

    /** Blocaje rezolvate pentru alții: un rând „blocat" din update-ul unui coleg, rezolvat de om; o sarcină blocată a unui coleg, deblocată de om. */
    private function unblocks(): int
    {
        $lines = UpdateItem::query()->join('updates', 'updates.id', '=', 'update_items.update_id')
            ->where('update_items.type', UpdateItemType::Blocked->value)->where('update_items.resolved_by', $this->user->id)
            ->where('updates.user_id', '!=', $this->user->id)->count();
        $tasks = TaskEvent::query()->join('tasks', 'tasks.id', '=', 'task_events.task_id')
            ->where('task_events.user_id', $this->user->id)->where('task_events.type', TaskEventType::Status->value)
            ->where('task_events.from', TaskStatus::Blocked->value)->whereNotNull('tasks.assignee_id')->where('tasks.assignee_id', '!=', $this->user->id)
            ->distinct()->count('task_events.task_id');

        return $lines + $tasks;
    }

    /** Răspunsuri în mai puțin de o oră, când un coleg a scris pe o sarcină a omului (a lui sau scrisă de el). */
    private function quickReplies(): int
    {
        return count($this->replyDays());
    }

    private ?array $replyDays = null;

    /** @return list<string> ziua fiecărui răspuns dat în mai puțin de o oră */
    private function replyDays(): array
    {
        if ($this->replyDays !== null) {
            return $this->replyDays;
        }
        $tasks = Task::where(fn ($q) => $q->where('assignee_id', $this->user->id)->orWhere('created_by', $this->user->id))->pluck('id');
        $days = [];
        if ($tasks->isNotEmpty()) {
            foreach (TaskComment::whereIn('task_id', $tasks)->orderBy('created_at')->get(['task_id', 'user_id', 'created_at'])->groupBy('task_id') as $comments) {
                $waiting = null;                                              // primul comentariu al unui coleg, încă fără răspuns
                foreach ($comments as $c) {
                    if ((int) $c->user_id !== $this->user->id) {
                        $waiting ??= $c->created_at;
                    } elseif ($waiting) {
                        if ($waiting->diffInMinutes($c->created_at) <= 60) {
                            $days[] = $c->created_at->copy()->setTimezone($this->tz)->toDateString();
                        }
                        $waiting = null;
                    }
                }
            }
        }

        return $this->replyDays = $days;
    }

    /** Sarcini libere luate singur și terminate. */
    private function tookFree(): int
    {
        $taken = TaskEvent::where('user_id', $this->user->id)->where('type', TaskEventType::Taken->value)->distinct()->pluck('task_id');

        return $this->done()->whereIn('task_id', $taken)->count();
    }

    /** Decizii scrise în update-uri, fără cele foarte scurte sau repetate. */
    private function decisions(): int
    {
        return UpdateItem::query()->join('updates', 'updates.id', '=', 'update_items.update_id')
            ->where('updates.user_id', $this->user->id)->where('update_items.type', UpdateItemType::Decision->value)
            ->pluck('update_items.text')->map(fn (string $t) => mb_strtolower(trim($t)))->filter(fn (string $t) => mb_strlen($t) >= 8)->unique()->count();
    }

    /** Uneltele cu care a lucrat: agenții din sesiuni și aplicațiile din care a raportat (nu webul, emailul sau Telegram). */
    private function tools(): int
    {
        $skip = ['web', 'email', 'telegram', 'other', 'app', 'mcp'];

        return DB::table('activities')->where('user_id', $this->user->id)->distinct()->pluck('source')   // tabelul direct: nu orice sursă e în enum
            ->merge($this->updates()->pluck('source'))->reject(fn ($s) => in_array($s, $skip, true) || $s === '')->unique()->count();
    }

    /**
     * Zilele de lucru (ultimul an) în care toată echipa a trimis update: echipa = oamenii activi, nu clienți, care au raportat în ultimele 30 de zile
     * și erau deja în aplicație în ziua aceea. Aceleași zile pentru toți: se calculează o dată, în fusul echipei.
     */
    private function wholeTeamDays(): int
    {
        $data = Cache::remember('badges.whole-team.'.now()->format('Y-m-d-H'), 3600, function () {
            $tz = (string) Settings::get('timezone');
            $members = User::team()->whereHas('updates', fn ($q) => $q->where('created_at', '>=', now()->subDays(30)))->get(['id']);
            if ($members->count() < 2) {
                return ['members' => [], 'days' => []];
            }
            $since = now($tz)->subYear()->startOfDay();
            $byDay = Update::whereIn('user_id', $members->pluck('id'))->where('created_at', '>=', $since->copy()->utc())->get(['user_id', 'created_at'])
                ->groupBy(fn (Update $u) => $u->created_at->copy()->setTimezone($tz)->toDateString())->map(fn (Collection $g) => $g->pluck('user_id')->unique());
            $firsts = Update::whereIn('user_id', $members->pluck('id'))->groupBy('user_id')->selectRaw('user_id, min(created_at) as first')->pluck('first', 'user_id')
                ->map(fn ($f) => Carbon::parse($f, 'UTC')->setTimezone($tz)->toDateString());
            $away = Absence::whereIn('user_id', $members->pluck('id'))->whereDate('to', '>=', $since->toDateString())->get()
                ->flatMap(fn (Absence $a) => collect(range(0, (int) $a->from->diffInDays($a->to)))->map(fn (int $i) => $a->user_id.'@'.$a->from->copy()->addDays($i)->toDateString()))->flip();
            $days = $byDay->filter(function (Collection $who, string $day) use ($members, $firsts, $away) {
                $expected = $members->pluck('id')->filter(fn (int $id) => ($firsts[$id] ?? '9999') <= $day && ! $away->has($id.'@'.$day));

                return Carbon::parse($day)->isWeekday() && $expected->count() >= 2 && $expected->diff($who)->isEmpty();
            })->keys()->all();

            return ['members' => $members->pluck('id')->all(), 'days' => $days];
        });

        return in_array($this->user->id, $data['members'], true) ? count($data['days']) : 0;
    }

    /** Săptămâni încheiate (ultimul an, de când omul raportează) în care nimeni din echipă n-a avut o sarcină sau un rând „blocat". */
    private function weeksWithoutBlockers(): int
    {
        $weeks = collect($this->weeks())->filter(fn (Carbon $m) => $m->gte(now($this->tz)->subYear()));
        if ($weeks->isEmpty()) {
            return 0;
        }
        $data = Cache::remember('badges.blocked-weeks.'.now()->format('Y-m-d-H'), 3600, function () {
            $tz = (string) Settings::get('timezone');
            $key = fn ($at) => Carbon::parse($at, 'UTC')->setTimezone($tz)->startOfWeek()->toDateString();
            $since = now()->subYears(1)->subWeek();
            $blocked = TaskEvent::where('type', TaskEventType::Status->value)->where('to', TaskStatus::Blocked->value)->where('created_at', '>=', $since)->pluck('created_at')->map($key)
                ->merge(UpdateItem::query()->join('updates', 'updates.id', '=', 'update_items.update_id')->where('update_items.type', UpdateItemType::Blocked->value)
                    ->where('updates.created_at', '>=', $since)->pluck('updates.created_at')->map($key))->unique()->flip();
            $busy = Update::where('created_at', '>=', $since)->pluck('created_at')->map($key)->countBy()->filter(fn (int $n) => $n >= 3);

            return ['blocked' => $blocked->keys()->all(), 'busy' => $busy->keys()->all()];
        });

        return $weeks->filter(fn (Carbon $m) => in_array($m->toDateString(), $data['busy'], true) && ! in_array($m->toDateString(), $data['blocked'], true))->count();
    }

    /** Etapele încheiate ale proiectelor la care omul a lucrat în timpul lor; și cele în care toate sarcinile cu termen au fost gata la timp. */
    private function stages(): array
    {
        return $this->stagesMemo ??= (function () {
            $ended = $onTime = 0;
            $projectIds = $this->updates()->pluck('project_id')->filter()->unique();
            foreach (Project::whereIn('id', $projectIds)->whereNotNull('stages')->get() as $project) {
                foreach ($project->stagePeriods($this->today) as $stage) {
                    if ($stage['open'] || $stage['to'] >= $this->today) {
                        continue;
                    }
                    $worked = $this->updates()->contains(fn ($u) => $u->project_id === $project->id && $u->day >= $stage['from'] && $u->day <= $stage['to']);
                    if (! $worked) {
                        continue;
                    }
                    $ended++;
                    $due = Task::where('project_id', $project->id)->whereBetween('due_on', [$stage['from'], $stage['to']])->get(['status', 'done_at']);
                    $onTime += $due->count() >= 3 && $due->every(fn (Task $t) => $t->status === TaskStatus::Done && $t->done_at
                        && $t->done_at->copy()->setTimezone($this->tz)->toDateString() <= $stage['to']) ? 1 : 0;
                }
            }

            return ['ended' => $ended, 'on_time' => $onTime];
        })();
    }

    private ?array $stagesMemo = null;

    /**
     * Cea mai lungă serie a unui proiect la care omul a lucrat: zile de lucru la rând cu cel puțin un update pe proiect (de la oricine),
     * numărată doar dacă omul a raportat măcar o dată în timpul ei.
     */
    private function projectStreak(): int
    {
        $projects = $this->updates()->pluck('project_id')->filter()->unique();
        if ($projects->isEmpty()) {
            return 0;
        }
        $tz = (string) Settings::get('timezone');
        $best = 0;
        $rows = DB::table('updates')->whereIn('project_id', $projects)->get(['project_id', 'user_id', 'created_at'])->groupBy('project_id');
        foreach ($rows as $updates) {
            $days = $updates->groupBy(fn ($u) => Carbon::parse($u->created_at, 'UTC')->setTimezone($tz)->toDateString())
                ->map(fn (Collection $g) => $g->contains(fn ($u) => (int) $u->user_id === $this->user->id));
            $run = 0;
            $mine = false;
            $sorted = $days->keys()->sort()->values();
            for ($d = Carbon::parse($sorted->first()); $sorted->isNotEmpty() && $d->toDateString() <= $sorted->last(); $d->addDay()) {
                if (! $d->isWeekday()) {
                    continue;
                }
                if ($days->has($date = $d->toDateString())) {
                    $run++;
                    $mine = $mine || $days[$date];
                    $best = $mine ? max($best, $run) : $best;
                } else {
                    $run = 0;
                    $mine = false;
                }
            }
        }

        return $best;
    }

    /** Câți ani are cel mai vechi proiect la care omul încă lucrează (update în ultimele 90 de zile), de la primul update pe el. */
    private function projectYears(): int
    {
        $recent = $this->updates()->filter(fn ($u) => $u->project_id && $u->at->gte(now()->subDays(90)))->pluck('project_id')->unique();
        if ($recent->isEmpty()) {
            return 0;
        }
        $firsts = Update::whereIn('project_id', $recent)->groupBy('project_id')->selectRaw('project_id, min(created_at) as first')->pluck('first');

        return (int) $firsts->map(fn ($f) => Carbon::parse($f, 'UTC')->diffInYears(now()))->max();
    }
}
