<?php

namespace App\Filament\Widgets;

use App\Enums\TaskEventType;
use App\Enums\TaskStatus;
use App\Models\Dismissal;
use App\Models\TaskEvent;
use App\Models\Update;
use App\Models\User;
use App\Support\License;
use Filament\Widgets\Widget;

/**
 * „Susține cemainou", întrebat blând: doar adminilor, doar unei echipe de peste 5 oameni, după 30 de zile de folosire, fără cheie de susținător,
 * cel mult o dată pe lună („Nu acum" îl ascunde 30 de zile). Spune ce a făcut echipa cu cemainou luna asta; nimic nu se oprește fără susținere.
 */
class SupportNudge extends Widget
{
    protected string $view = 'filament.widgets.support-nudge';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        $user = auth()->user();
        if (! $user?->isAdmin() || License::supporter() || License::people() <= License::free()) {
            return false;
        }
        $since = User::min('created_at');

        return $since && now()->diffInDays($since, true) >= 30 && ! Dismissal::activeFor($user)->has('support');
    }

    public function later(): void
    {
        Dismissal::put(auth()->user(), 'support', now()->addDays(30));
        $this->dispatch('$refresh');
    }

    protected function getViewData(): array
    {
        $since = now()->subDays(30);

        return [
            'updates' => Update::where('created_at', '>=', $since)->count(),
            'done' => TaskEvent::where('type', TaskEventType::Status->value)->where('to', TaskStatus::Done->value)->where('created_at', '>=', $since)->distinct()->count('task_id'),
            'people' => License::people(),
            'url' => 'https://rt.md/cemainou/license/#support',
        ];
    }
}
