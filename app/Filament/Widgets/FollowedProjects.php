<?php

namespace App\Filament\Widgets;

use App\Models\Project;
use Filament\Widgets\Widget;

/** Proiectele pe care le urmăresc (și cele pe care le conduc): câte sarcini deschise au, câte blocate și când a raportat cineva ultima dată. */
class FollowedProjects extends Widget
{
    public static function canView(): bool
    {
        return auth()->check() && ! auth()->user()->isClient();
    }

    protected string $view = 'filament.widgets.followed-projects';

    protected static bool $isLazy = false;

    protected function getViewData(): array
    {
        $me = auth()->user();

        return ['projects' => Project::visibleTo($me)->where('is_archived', false)
            ->where(fn ($q) => $q->where('lead_id', $me->id)->orWhereIn('id', $me->followedProjects()->select('projects.id')))
            ->withCount(['tasks as open_count' => fn ($q) => $q->open(), 'tasks as blocked_count' => fn ($q) => $q->where('status', 'blocked')])
            ->withMax('updates', 'created_at')->orderByDesc('updates_max_created_at')->limit(8)->get()];
    }
}
