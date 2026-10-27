<?php

namespace App\Filament\Widgets;

use App\Models\TaskComment;
use App\Models\UpdateComment;
use Filament\Widgets\Widget;

/** Comentarii noi pentru mine, din ultimele 7 zile: pe sarcinile mele (pe care le fac sau le-am creat) și pe update-urile mele, scrise de alții. */
class NewComments extends Widget
{
    public static function canView(): bool
    {
        return auth()->check();                                                 // și clientul: comentariile la sarcinile și update-urile lui
    }

    protected string $view = 'filament.widgets.new-comments';

    protected static bool $isLazy = false;

    protected function getViewData(): array
    {
        $me = auth()->user();
        $since = now()->subDays(7);
        $onTasks = TaskComment::with(['task', 'author'])->where('user_id', '!=', $me->id)->where('created_at', '>=', $since)
            ->whereHas('task', fn ($q) => $q->visibleTo($me)->where(fn ($q) => $q->where('assignee_id', $me->id)->orWhere('created_by', $me->id)))->latest()->limit(8)->get();
        $onUpdates = UpdateComment::with(['statusUpdate.project', 'author'])->where('user_id', '!=', $me->id)->where('created_at', '>=', $since)
            ->whereHas('statusUpdate', fn ($q) => $q->where('user_id', $me->id))->latest()->limit(8)->get();

        return ['comments' => $onTasks->concat($onUpdates)->sortByDesc('created_at')->take(8)->values()];
    }
}
