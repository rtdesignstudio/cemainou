<?php

namespace App\Filament\Widgets;

use App\Actions\CorrectTime;
use App\Filament\Actions\WorkTimeActions;
use App\Models\Project;
use App\Support\MyDay;
use App\Support\PageCache;
use App\Support\WorkTime;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/**
 * Ziua mea: cele 24 de ore cu timpul lucrat pe proiecte, cifrele zilei și reușitele ei. Azi sau o zi trecută (săgețile de lângă dată),
 * cu „Adaugă timp" și „Corectează timpul" pe card; orele alese pe grafic (trase cu mouse-ul) deschid corecția deja completată.
 */
class MyDayCard extends Widget implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    /** Cât de departe în urmă merg săgețile. */
    public const DAYS_BACK = 60;

    public static function canView(): bool
    {
        return auth()->check() && ! auth()->user()->isClient();
    }

    protected string $view = 'filament.widgets.my-day';

    protected static bool $isLazy = false;

    /** Ziua văzută (Y-m-d); null = azi. */
    public ?string $date = null;

    public function shiftDay(int $by): void
    {
        $tz = auth()->user()->tz();
        $day = Carbon::parse($this->date ?? now($tz)->toDateString(), $tz)->addDays(max(-1, min(1, $by)));
        $day = $day->max(now($tz)->subDays(self::DAYS_BACK)->startOfDay())->min(now($tz)->startOfDay());
        $this->date = $day->isSameDay(now($tz)) ? null : $day->toDateString();
    }

    public function addTimeAction(): Action
    {
        return WorkTimeActions::add(fn () => auth()->user(), fn () => $this->date);
    }

    public function correctTimeAction(): Action
    {
        return WorkTimeActions::correct(fn () => auth()->user(), fn () => $this->date);
    }

    /**
     * Orele alese pe grafic (de la $from la $to, inclusiv): proiectul cel mai lucrat în ele și cât s-a măsurat pe el acolo, ca fereastra
     * „Corectează timpul" să propună scoaterea lor, de la primul semnal din ele. Omul scrie doar motivul.
     */
    public function selectHours(int $from, int $to): void
    {
        $user = auth()->user();
        if (! WorkTimeActions::allowed($user)) {
            return;
        }
        [$from, $to] = [max(0, min($from, $to)), min(23, max($from, $to))];
        $tz = $user->tz();
        $day = Carbon::parse($this->date ?? now($tz)->toDateString(), $tz)->startOfDay();
        $date = $day->toDateString();
        $without = MyDay::removedStretches($user, $day);
        $hours = WorkTime::hourly($user, $day, $without)['hours'];

        $minutes = [];
        for ($h = $from; $h <= $to; $h++) {
            foreach ($hours[$h]['projects'] as $id => $m) {
                if ($id) {
                    $minutes[$id] = ($minutes[$id] ?? 0) + $m;
                }
            }
        }
        arsort($minutes);
        $project = $minutes ? Project::visibleTo($user)->find(array_key_first($minutes)) : null;
        if (! $project) {
            $this->mountAction('correctTime', ['date' => $date]);

            return;
        }
        $outside = fn ($t) => ! array_filter($without, fn (array $w) => $t >= $w[0] && $t < $w[1] + 60);
        $first = $user->activities()->where('project_id', $project->id)
            ->whereBetween('occurred_at', [$day->copy()->setTime($from, 0)->utc(), $day->copy()->setTime($to, 59, 59)->utc()])
            ->orderBy('occurred_at')->pluck('occurred_at')->first(fn ($t) => $outside(Carbon::parse($t)->getTimestamp()));
        $start = $first ? Carbon::parse($first)->setTimezone($tz)->format('H:i') : sprintf('%02d:00', $from);

        $this->mountAction('correctTime', [
            'project' => $project->id, 'date' => $date, 'start' => $start,
            'actual' => max(0, CorrectTime::total($user, $project, $date) - $minutes[$project->id]),
        ]);
    }

    protected function getViewData(): array
    {
        $user = auth()->user();
        $hour = (int) now($user->tz())->format('G');
        $actions = WorkTimeActions::allowed($user) ? new HtmlString($this->addTimeAction->toHtml().$this->correctTimeAction->toHtml()) : null;   // doar wire:click: se pot păstra cu restul

        return ['body' => PageCache::html('my-day', $user, [$hour, $this->date], fn () => view('filament.widgets.my-day-body', [
            'day' => MyDay::for($user, $this->date), 'nav' => true, 'isToday' => $this->date === null,
            'canGoBack' => ! $this->date || Carbon::parse($this->date, $user->tz())->gt(now($user->tz())->subDays(self::DAYS_BACK)->endOfDay()),
            'canSelect' => WorkTimeActions::allowed($user), 'actions' => $actions,
        ]))];   // ora: bara „acum" se mută
    }
}
