<?php

namespace App\Support;

use App\Enums\UpdateItemType;
use App\Models\Project;
use App\Models\Update;
use App\Models\UpdateItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Update-urile ca flux de citit (pagina Update-uri): filtrele rapide (toate, ale mele, cu blocaje, cu decizii), omul, proiectul, căutarea;
 * pe zile, cele mai noi primele; sus cifrele săptămânii (față de cea trecută).
 */
class UpdateFeed
{
    public const FILTERS = ['all', 'mine', 'blocked', 'decisions'];

    /** @return array{days: Collection<string, Collection<int, Update>>, more: bool, people: Collection<int, string>, projects: Collection<int, string>} */
    public static function build(User $viewer, string $filter, ?int $person, ?int $project, string $find, int $limit, ?string $from = null, ?string $until = null): array
    {
        $tz = $viewer->tz();
        $updates = self::query($viewer, $filter, $person, $project, $find, $from, $until)
            ->with(['user', 'project', 'items'])->withCount(['comments', 'acks'])->latest()->limit($limit + 1)->get();

        return [
            'days' => $updates->take($limit)->groupBy(fn (Update $u) => $u->created_at->copy()->setTimezone($tz)->toDateString()),
            'more' => $updates->count() > $limit,
            'people' => User::team()->visibleTo($viewer)->orderBy('name')->pluck('name', 'id'),   // colaboratorul: doar oamenii proiectelor lui
            'projects' => Project::visibleTo($viewer)->active()->orderBy('name')->pluck('name', 'id'),
        ];
    }

    public static function query(User $viewer, string $filter, ?int $person = null, ?int $project = null, string $find = '', ?string $from = null, ?string $until = null): Builder
    {
        $tz = $viewer->tz();

        return Update::visibleTo($viewer)
            ->when($from, fn ($q) => $q->where('created_at', '>=', Carbon::parse($from, $tz)->startOfDay()->utc()))      // zilele celui care se uită
            ->when($until, fn ($q) => $q->where('created_at', '<=', Carbon::parse($until, $tz)->endOfDay()->utc()))
            ->when($filter === 'mine', fn ($q) => $q->where('user_id', $viewer->id))
            ->when($filter === 'blocked', fn ($q) => $q->whereHas('items', fn ($i) => $i->where('type', UpdateItemType::Blocked->value)))
            ->when($filter === 'decisions', fn ($q) => $q->whereHas('items', fn ($i) => $i->where('type', UpdateItemType::Decision->value)))
            ->when($person, fn ($q) => $q->where('user_id', $person))
            ->when($project, fn ($q) => $q->where('project_id', $project))
            ->when(trim($find) !== '', fn ($q) => $q->where(fn ($w) => $w->where('summary', 'like', '%'.trim($find).'%')
                ->orWhereHas('items', fn ($i) => $i->where('text', 'like', '%'.trim($find).'%'))));
    }

    /** Cifrele săptămânii de acum (luni - azi), cu săptămâna trecută alături. @return array<string, int> */
    public static function summary(User $viewer): array
    {
        $tz = $viewer->tz();
        $start = now($tz)->startOfWeek()->utc();
        $previous = now($tz)->startOfWeek()->subWeek()->utc();
        $week = Update::visibleTo($viewer)->where('created_at', '>=', $start);
        $items = fn (UpdateItemType $type) => UpdateItem::where('type', $type->value)->whereHas('statusUpdate', fn ($u) => $u->visibleTo($viewer)->where('created_at', '>=', $start))->count();

        return [
            'updates' => (clone $week)->count(),
            'previous' => Update::visibleTo($viewer)->whereBetween('created_at', [$previous, $start])->count(),
            'people' => (clone $week)->distinct()->count('user_id'),
            'team' => $viewer->isClient() ? 0 : User::team()->visibleTo($viewer)->count(),
            'blocked' => $items(UpdateItemType::Blocked),
            'decisions' => $items(UpdateItemType::Decision),
        ];
    }
}
