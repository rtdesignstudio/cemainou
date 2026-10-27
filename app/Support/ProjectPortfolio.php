<?php

namespace App\Support;

use App\Enums\AttachmentRole;
use App\Enums\TaskStatus;
use App\Enums\UpdateItemType;
use App\Models\Attachment;
use App\Models\Document;
use App\Models\Project;
use App\Models\ProjectDay;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\Update;
use App\Models\UpdateItem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pagina Proiecte, în carduri: tot ce spune cardul de pe „Azi" (ProjectCards: starea, sarcinile, blocajul, ultimul update, cine lucrează acum)
 * plus timpul investit - de la început, luna asta, ultimele 7 zile, pe săptămâni (12) și partea fiecărui om -, de când rulează proiectul,
 * în câte zile s-a lucrat, ideile, documentele și primul „Urmează". Orele le vede toată echipa, clientul nu (viewMinutes).
 * Timpul = măsurat (rezumatele zilelor; azi și ieri, din semnale) + adăugat de oameni + estimat din git (zilele refăcute cu /history, fără timp
 * măsurat), ca „Timp investit" să fie tot timpul proiectului, ca pe pagina lui; partea estimată se arată separat. Interogările sunt pe grupuri.
 */
class ProjectPortfolio
{
    /**
     * @param  Collection<int, Project>  $projects  proiectele pe care le vede omul (cu client și lider încărcați)
     * @return Collection<int, array<string, mixed>>
     */
    public static function build(User $viewer, Collection $projects, ?string $from = null, ?string $to = null): Collection
    {
        $cards = ProjectCards::build($viewer, $projects, recentOnly: false)->keyBy(fn (array $c) => $c['project']->id);
        $ids = $cards->keys();
        $tz = $viewer->tz();
        $today = now($tz)->startOfDay();
        $yesterday = $today->copy()->subDay()->toDateString();
        // perioada aleasă (implicit luna curentă): cifra de perioadă, graficul și cine cât a lucrat; „Timp investit" e totalul de la început
        $from = $from ?? $today->copy()->startOfMonth()->toDateString();
        $to = $to ?? $today->toDateString();
        $week = $today->copy()->subDays(6)->toDateString();
        $window = min($from, $week);                                                          // zilele citite pe rând; restul, doar ca sumă
        $withHours = $projects->isNotEmpty() && $viewer->can('viewMinutes', $projects->first());

        $hours = $withHours ? self::rows($ids, $window, $yesterday, $today->toDateString()) : collect();
        $before = $withHours ? self::before($ids, $window) : collect();
        $buckets = self::buckets($from, $to);
        // de când rulează și în câte zile s-a lucrat: zilele măsurate, orele adăugate sau estimate și primul update (și cele refăcute din git)
        $dates = ProjectDay::whereIn('project_id', $ids)->distinct()->get(['project_id', 'date'])->toBase()->map(fn ($d) => [$d->project_id, substr((string) $d->date, 0, 10)])
            ->concat(TimeEntry::whereIn('project_id', $ids)->get(['project_id', 'date'])->toBase()->map(fn ($e) => [$e->project_id, $e->date->toDateString()]))
            ->groupBy(0)->map(fn (Collection $rows) => $rows->pluck(1)->unique());
        $firstUpdate = Update::whereIn('project_id', $ids)->groupBy('project_id')->selectRaw('project_id, min(coalesce(period_from, created_at)) as first')->pluck('first', 'project_id');
        $firstDay = $ids->mapWithKeys(fn (int $id) => [$id => collect([($dates[$id] ?? collect())->min(), isset($firstUpdate[$id]) ? Carbon::parse($firstUpdate[$id], 'UTC')->setTimezone($tz)->toDateString() : null])->filter()->min()]);
        $daysWorked = $dates->map->count();
        $ideas = Task::whereIn('project_id', $ids)->where('status', TaskStatus::Idea->value)->groupBy('project_id')->selectRaw('project_id, count(*) as n')->pluck('n', 'project_id');
        $documents = $viewer->isClient() ? collect() : Document::whereIn('project_id', $ids)->active()->where('version', '>', 0)
            ->groupBy('project_id')->selectRaw('project_id, count(*) as n')->pluck('n', 'project_id');
        $lastIds = $cards->map(fn (array $c) => $c['last']?->id)->filter();
        // demo-ul fiecărui proiect (cel mai nou), pentru „Vezi demo" pe card; clientul doar ce i s-a arătat
        $demos = Attachment::whereIn('project_id', $ids)->whereNull('task_id')->where('role', AttachmentRole::Demo->value)
            ->when($viewer->isClient(), fn ($q) => $q->where('client_visible', true))->latest()->get()->unique('project_id')->keyBy('project_id');
        $next = UpdateItem::whereIn('update_id', $lastIds)->where('type', UpdateItemType::Next->value)->orderBy('position')->get(['update_id', 'text'])
            ->groupBy('update_id')->map(fn (Collection $items) => $items->first()->text);
        $people = User::whereIn('id', $hours->pluck('user_id')->concat($before->pluck('user_id'))->unique())->get()->keyBy('id');
        // cine are acces (doar pentru echipă): pe un proiect restrâns, liderul și cei aleși; pe unul al întregii echipe, colaboratorii adăugați
        $members = $viewer->seesWholeTeam() ? DB::table('project_members')->whereIn('project_id', $ids)->get(['project_id', 'user_id'])->groupBy('project_id') : collect();
        $accessPeople = $members->isEmpty() ? collect() : User::where('is_active', true)->whereIn('id', $members->flatten()->pluck('user_id')->unique())->get()->keyBy('id');
        $team = $viewer->seesWholeTeam() ? User::query()->team()->where('role', '!=', 'collaborator')->orderBy('name')->get() : collect();
        $clientPeople = $viewer->seesWholeTeam() ? User::where('role', 'client')->where('is_active', true)->whereIn('client_id', $projects->where('client_visible', true)->pluck('client_id')->filter())->get()->groupBy('client_id') : collect();

        return $cards->map(function (array $card, int $id) use ($viewer, $withHours, $hours, $before, $buckets, $from, $to, $week, $firstDay, $daysWorked, $ideas, $documents, $next, $people, $tz, $members, $accessPeople, $clientPeople, $team, $demos) {
            $mine = $hours->where('project_id', $id);
            $old = $before->where('project_id', $id);
            $inPeriod = $mine->filter(fn (array $r) => $r['date'] >= $from && $r['date'] <= $to);
            $bars = array_map(function (array $b) use ($inPeriod) {
                $minutes = (int) $inPeriod->filter(fn (array $r) => $r['date'] >= $b['from'] && $r['date'] <= $b['to'])->sum('minutes');

                return ['minutes' => $minutes, 'label' => $b['label'].' · '.($minutes ? WorkTime::format($minutes) : __('nothing'))];
            }, $buckets);
            $max = max(1, ...array_column($bars, 'minutes'));
            $bars = array_map(fn (array $b) => $b + ['height' => $b['minutes'] ? max(6, (int) round($b['minutes'] * 100 / $max)) : 0], $bars);
            $total = (int) $mine->sum('minutes') + (int) $old->sum('minutes');
            $estimated = (int) $mine->sum('estimated') + (int) $old->sum('estimated');
            $period = (int) $inPeriod->sum('minutes');
            $share = $inPeriod->groupBy('user_id')->map(fn (Collection $rows) => (int) $rows->sum('minutes'))->filter()->sortDesc()
                ->map(fn (int $minutes, int $userId) => ['user' => $people->get($userId), 'minutes' => $minutes, 'percent' => $period ? (int) round($minutes * 100 / $period) : 0])
                ->filter(fn (array $p) => $p['user'])->values();
            $first = $firstDay[$id] ?? null;
            $began = $first ? Carbon::parse($first, $tz) : $card['project']->created_at->copy()->setTimezone($tz);

            return $card + [
                'demo' => ($demo = $demos->get($id)) ? ['url' => $demo->href(), 'title' => $demo->title] : null,
                'hours' => $withHours ? [
                    'total' => $total,
                    'estimated' => $estimated,
                    'period' => $period,
                    'week' => (int) $mine->filter(fn (array $r) => $r['date'] >= $week)->sum('minutes'),
                    'weeks' => $bars,
                    'share' => $share,
                ] : null,
                'began' => $began,
                'days_worked' => (int) ($daysWorked[$id] ?? 0),
                'ideas' => (int) ($ideas[$id] ?? 0),
                'documents' => (int) ($documents[$id] ?? 0),
                'next' => $card['last'] ? ($next[$card['last']->id] ?? null) : null,
                'access' => $viewer->seesWholeTeam() ? (function () use ($card, $id, $members, $accessPeople, $clientPeople, $team) {
                    $project = $card['project'];
                    $chosen = ($members->get($id) ?? collect())->map(fn ($m) => $accessPeople->get($m->user_id))->filter();
                    $people = $project->restricted
                        ? collect([$project->lead])->filter()->concat($chosen)->unique('id')->values()
                        : $team->concat($chosen->filter(fn (User $u) => $u->isCollaborator()))->values();       // toată echipa, plus colaboratorii de aici

                    return ['restricted' => (bool) $project->restricted, 'people' => $people,
                        'client' => $project->client_visible && $project->client_id ? ($clientPeople->get($project->client_id) ?? collect()) : collect()];
                })() : null,
            ];
        })->values();
    }

    /**
     * Coloanele graficului pentru perioadă: pe zile până la 45 de zile, pe săptămâni până la ~7 luni, apoi pe luni.
     *
     * @return list<array{from: string, to: string, label: string}>
     */
    public static function buckets(string $from, string $to): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        $days = (int) $start->diffInDays($end) + 1;
        $unit = $days <= 45 ? 'day' : ($days <= 215 ? 'week' : 'month');
        $locale = app()->getLocale();
        $cursor = match ($unit) {
            'week' => $start->copy()->startOfWeek(), 'month' => $start->copy()->startOfMonth(), default => $start->copy()
        };
        $out = [];
        while ($cursor->lte($end)) {
            $last = match ($unit) {
                'week' => $cursor->copy()->endOfWeek()->startOfDay(), 'month' => $cursor->copy()->endOfMonth()->startOfDay(), default => $cursor->copy()
            };
            $out[] = ['from' => max($cursor, $start)->toDateString(), 'to' => min($last, $end)->toDateString(), 'label' => match ($unit) {
                'week' => __('week of :date', ['date' => $cursor->copy()->locale($locale)->isoFormat('D MMM')]),
                'month' => ucfirst($cursor->copy()->locale($locale)->isoFormat('MMMM YYYY')),
                default => $cursor->copy()->locale($locale)->isoFormat('dddd, D MMM'),
            }];
            $cursor = match ($unit) {
                'week' => $cursor->addWeek(), 'month' => $cursor->addMonthNoOverflow()->startOfMonth(), default => $cursor->addDay()
            };
        }

        return $out;
    }

    /** Zi cu zi, pe proiect și om: din rezumate și orele adăugate sau estimate până ieri exclusiv, iar ieri și azi din Hours::between (semnale, încă nerezumate). */
    private static function rows(Collection $ids, string $from, string $yesterday, string $today): Collection
    {
        $summed = ProjectDay::whereIn('project_id', $ids)->where('date', '>=', $from)->where('date', '<', $yesterday)->get(['project_id', 'user_id', 'date', 'active_minutes'])->toBase()
            ->map(fn (ProjectDay $d) => ['project_id' => $d->project_id, 'user_id' => $d->user_id, 'date' => (string) $d->date, 'minutes' => (int) $d->active_minutes, 'estimated' => 0]);
        $added = TimeEntry::whereIn('project_id', $ids)->whereDate('date', '>=', $from)->whereDate('date', '<', $yesterday)->get()->toBase()
            ->map(fn (TimeEntry $e) => ['project_id' => $e->project_id, 'user_id' => $e->user_id, 'date' => $e->date->toDateString(), 'minutes' => (int) $e->minutes, 'estimated' => $e->is_estimate ? (int) $e->minutes : 0]);
        $live = Hours::between($ids, $yesterday, $today)->map(fn (array $r) => ['project_id' => $r['project_id'], 'user_id' => $r['user_id'], 'date' => $r['date'],
            'minutes' => $r['measured'] + $r['added'] + $r['estimated'], 'estimated' => $r['estimated']]);

        return $summed->concat($added)->concat($live)->filter(fn (array $r) => $r['minutes'] > 0)->values();
    }

    /** Totalul dinaintea ferestrei citite pe zile, pe proiect și om (rezumatele zilelor, orele adăugate și cele estimate). */
    private static function before(Collection $ids, string $until): Collection
    {
        $summed = ProjectDay::whereIn('project_id', $ids)->where('date', '<', $until)->groupBy('project_id', 'user_id')
            ->selectRaw('project_id, user_id, sum(active_minutes) as minutes')->get()->toBase()
            ->map(fn ($r) => ['project_id' => (int) $r->project_id, 'user_id' => (int) $r->user_id, 'minutes' => (int) $r->minutes, 'estimated' => 0]);
        $added = TimeEntry::whereIn('project_id', $ids)->whereDate('date', '<', $until)->groupBy('project_id', 'user_id')
            ->selectRaw('project_id, user_id, sum(minutes) as minutes, sum(case when is_estimate then minutes else 0 end) as estimated')->get()->toBase()
            ->map(fn ($r) => ['project_id' => (int) $r->project_id, 'user_id' => (int) $r->user_id, 'minutes' => (int) $r->minutes, 'estimated' => (int) $r->estimated]);

        return $summed->concat($added)->values();
    }

    /**
     * Cifrele portofoliului, deasupra cardurilor.
     *
     * @param  Collection<int, array<string, mixed>>  $cards
     * @return array{active: int, period: ?int, blocked: int, quiet: int}
     */
    public static function summary(Collection $cards): array
    {
        $active = $cards->filter(fn (array $c) => ! $c['project']->is_archived);

        return [
            'active' => $active->count(),
            'period' => $active->first() && $active->first()['hours'] !== null ? (int) $active->sum(fn (array $c) => $c['hours']['period']) : null,
            'blocked' => $active->where('health.state', 'blocked')->count(),
            'quiet' => $active->where('health.state', 'quiet')->count(),
        ];
    }
}
