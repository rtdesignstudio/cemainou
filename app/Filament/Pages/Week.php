<?php

namespace App\Filament\Pages;

use App\Models\UpdateItem;
use App\Support\LineActions;
use App\Support\PageCache;
use App\Support\TeamWeek;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;

/** Săptămâna echipei, pe un ecran: cine pe ce a lucrat în fiecare zi, ce s-a terminat, deciziile, blocajele, ideile noi și proiectele liniștite. */
class Week extends Page
{
    /** „Săptămâna echipei în 30 de secunde": povestea pe tot ecranul (StoryController). */
    protected function getHeaderActions(): array
    {
        return [Action::make('story')->label(__('The team\'s week in 30 seconds'))->icon(Heroicon::OutlinedPlay)
            ->url(route('story', ['team' => 1]))->visible(fn () => auth()->user()->seesWholeTeam())];
    }

    protected string $view = 'filament.pages.week';

    protected static ?string $slug = 'week';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 4;                                    // după Proiecte: ce folosește echipa zilnic (Azi, Sarcini, Update-uri, Proiecte) vine întâi, ca în bara de jos

    /** Lunea săptămânii (Y-m-d); gol = săptămâna de acum. */
    #[Url]
    public string $week = '';

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->seesWholeTeam();                 // săptămâna întregii echipe: nu clientul, nici colaboratorul
    }

    public static function getNavigationLabel(): string
    {
        return __('Week');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Week');
    }

    public function getSubheading(): ?string
    {
        return __('Who worked on what, what got done, decisions and blockers.');
    }

    public function mount(): void
    {
        $this->week = $this->monday()->toDateString();
    }

    public function previous(): void
    {
        $this->week = $this->monday()->subWeek()->toDateString();
    }

    public function next(): void
    {
        $next = $this->monday()->addWeek();
        if ($next->lte(now(auth()->user()->tz()))) {
            $this->week = $next->toDateString();
        }
    }

    /** „Săptămâna asta", când te-ai uitat înapoi. */
    public function current(): void
    {
        $this->week = now(auth()->user()->tz())->startOfWeek(Carbon::MONDAY)->toDateString();
    }

    private function monday(): Carbon
    {
        $tz = auth()->user()->tz();
        $base = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->week) ? Carbon::parse($this->week, $tz) : now($tz);

        return $base->startOfWeek(Carbon::MONDAY)->startOfDay();
    }

    protected function getViewData(): array
    {
        $monday = $this->monday();

        // desenată o dată pe minut (PageCache): se reface la orice update, sarcină sau timp adăugat
        $viewer = auth()->user();

        return ['body' => PageCache::html('week', $viewer, [$monday->toDateString()], fn () => view('filament.pages.week-body', [
            'w' => TeamWeek::build($viewer, $monday), 'isCurrent' => $monday->copy()->addWeek()->gt(now($viewer->tz())),
        ])->render())];
    }

    /** „Ce urmează": un pas rezolvat (iese din listă). */
    public function resolveLine(int $id): void
    {
        $item = UpdateItem::with('statusUpdate')->findOrFail($id);
        abort_unless(LineActions::allowed($item, auth()->user()), 404);
        $item->resolve(auth()->user());
        Notification::make()->title(__('Marked as resolved.'))->success()->send();
    }

    /** „Ce urmează": un pas făcut sarcină (liberă, pe proiectul update-ului). */
    public function lineToTask(int $id): void
    {
        $item = UpdateItem::with('statusUpdate.project', 'statusUpdate.user')->findOrFail($id);
        $task = LineActions::makeTask($item, auth()->user());
        Notification::make()->title(__('Task #:id added on :project.', ['id' => $task->id, 'project' => $item->statusUpdate->project->name]))->success()->send();
    }
}
