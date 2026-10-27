<?php

namespace App\Filament\Resources\Updates\Pages;

use App\Actions\EditUpdate;
use App\Enums\Source;
use App\Enums\UpdateItemType;
use App\Filament\Resources\Updates\UpdateResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\Update;
use App\Models\UpdateRevision;
use App\Models\User;
use App\Support\BackLinks;
use App\Support\ClientSeen;
use App\Support\ContextDecisions;
use App\Support\Headline;
use App\Support\Inbox;
use App\Support\Markdown;
use App\Support\Settings;
use App\Support\UpdateLinks;
use App\Support\WorkTime;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Fieldset;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\On;

/** Pagina unui update, ca un articol: rândurile pe secțiuni în stânga, detaliile (timp, perioadă, linkuri) în dreapta. */
class ViewUpdate extends ViewRecord
{
    public function mount(int|string $record): void
    {
        parent::mount($record);
        Inbox::readAbout(auth()->user(), static::getResource()::getUrl('view', ['record' => $this->record]));   // notificările despre pagina asta: citite
        ClientSeen::record(auth()->user(), $this->record);                          // clientul l-a deschis: echipa vede „văzut de"
    }

    protected static string $resource = UpdateResource::class;

    protected string $view = 'filament.resources.updates.view-update';

    public function getTitle(): string|Htmlable
    {
        return Str::limit(Markdown::plain($this->record->summary), 70);
    }

    /** Rezumatul e titlul; cu cât e mai lung, cu atât mai mic (Headline). */
    public function getHeading(): string|Htmlable
    {
        return Headline::html($this->record->summary, markdown: true);
    }

    public function getSubheading(): ?string
    {
        $update = $this->record;

        $tz = auth()->user()->tz();
        $late = $update->reported_at && ! $update->reported_at->copy()->setTimezone($tz)->isSameDay($update->created_at->copy()->setTimezone($tz))
            ? __('reported on :date', ['date' => $update->reported_at->copy()->setTimezone($tz)->locale(app()->getLocale())->isoFormat('D MMMM')]) : null;   // pus pe ziua lui, trimis mai târziu

        return collect([$update->user?->name, $update->project?->name, $update->source?->getLabel(), $this->at($update->created_at), $late, ClientSeen::line($update, $update->project, auth()->user())])->filter()->join(' · ');
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->fixDaysAction(),
            EditAction::make()->outlined(),
            ActionGroup::make([$this->moveAction(), DeleteAction::make()->modalHeading(__('Delete this update?'))])
                ->label(__('More'))->icon(Heroicon::OutlinedChevronDown)->iconPosition(IconPosition::After)->button()->outlined()->color('gray'),
        ];
    }

    /** „Mută pe alt proiect": update-ul pus pe proiectul greșit, cu motivul în istorie (EditUpdate). */
    private function moveAction(): Action
    {
        $update = $this->record;

        return Action::make('moveProject')->label(__('Move to another project'))->icon(Heroicon::OutlinedArrowsRightLeft)
            ->visible(fn () => auth()->user()->can('update', $update))
            ->modalHeading(__('Move to another project'))
            ->modalDescription(__('The update, its notes and its estimated time go to the other project. Lines linked to tasks of this project stay as text, unlinked; the time measured from sessions stays where the work was done. The move and your reason stay in the update\'s history.'))
            ->schema([
                Select::make('project_id')->label(__('Project'))->required()->searchable()
                    ->options(fn () => Project::active()->visibleTo(auth()->user())->whereKeyNot($update->project_id ?? 0)->orderBy('name')->pluck('name', 'id')),
                TextInput::make('reason')->label(__('Why you change it'))->required()->maxLength(300)->placeholder(__('e.g. Reported on the wrong project')),
            ])
            ->action(function (array $data) use ($update) {
                abort_unless(auth()->user()->can('update', $update), 403);
                $project = Project::visibleTo(auth()->user())->findOrFail($data['project_id']);
                try {
                    app(EditUpdate::class)->apply(auth()->user(), $update, ['project' => $project], $data['reason'], Source::Web);
                } catch (InvalidArgumentException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }
                Notification::make()->title(__('Moved to :project.', ['project' => $project->name]))->success()->send();
                $this->redirect(UpdateResource::getUrl('view', ['record' => $update]), navigate: true);
            });
    }

    /**
     * „Corectează zilele": ziua update-ului și, rând cu rând, ziua în care s-a făcut de fapt (gol = rămâne cu update-ul). Rândurile mutate ajung în
     * update-ul omului din ziua aceea (creat dacă lipsește); totul trece prin EditUpdate, cu motivul, în istoria update-ului - ca edit_update din agent.
     */
    private function fixDaysAction(): Action
    {
        $update = $this->record;
        $tz = $update->user?->tz() ?? config('app.timezone');
        $current = $update->created_at->copy()->setTimezone($tz)->toDateString();
        $oldest = now($tz)->subDays(Settings::get('history_days'))->toDateString();
        $today = now($tz)->toDateString();

        return Action::make('fixDays')->label(__('Fix the days'))->outlined()
            ->visible(fn () => $update->project_id && auth()->user()->can('update', $update))
            ->modalHeading(__('Fix the days'))
            ->modalDescription(__('Put the update, or some of its lines, on the day the work was really done. Lines moved to another day go into that day\'s update, created if needed. The change and your reason stay in the update\'s history.'))
            ->modalSubmitActionLabel(__('Save the days'))
            ->fillForm(['date' => $current])
            ->schema(fn () => [
                DatePicker::make('date')->label(__('The update\'s day'))->minDate($oldest)->maxDate($today)->required(),
                Fieldset::make(__('Lines on another day (empty = with the update)'))->columns(1)->schema(
                    $update->items()->orderBy('position')->get()->map(fn ($item) => DatePicker::make("lines.{$item->id}")
                        ->label(Str::limit(Markdown::plain($item->text), 90))->minDate($oldest)->maxDate($today)
                        ->placeholder(__('with the update')))->all()
                ),
                TextInput::make('reason')->label(__('Why you change it'))->required()->maxLength(300)
                    ->placeholder(__('e.g. Date correction: it was done on 6 October')),
            ])
            ->action(function (array $data) use ($update, $tz) {
                abort_unless(auth()->user()->can('update', $update), 403);
                $date = $data['date'];
                $items = $update->items()->get()->keyBy('id');
                $split = collect($data['lines'] ?? [])->filter(fn ($lineDate, $id) => filled($lineDate) && $lineDate !== $date && $items->has($id))
                    ->groupBy(fn ($lineDate) => $lineDate, preserveKeys: true)
                    ->map(fn ($group, $lineDate) => ['date' => $lineDate, 'summary' => Str::limit(Markdown::plain($items[$group->keys()->first()]->text), 480),
                        'lines' => $group->keys()->map(fn ($id) => $items[$id]->text)->all()])->values()->all();
                $changes = array_filter(['date' => $date !== $update->created_at->copy()->setTimezone($tz)->toDateString() ? $date : null, 'split' => $split ?: null]);
                if (! $changes) {
                    Notification::make()->title(__('Nothing to change: every line is already on its day.'))->send();

                    return;
                }
                try {
                    app(EditUpdate::class)->apply(auth()->user(), $update, $changes, $data['reason'], Source::Web);
                } catch (InvalidArgumentException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }
                Notification::make()->title(__('The days are fixed; the change is in the update\'s history.'))->success()->send();
                $this->redirect(UpdateResource::getUrl('view', ['record' => $update]));
            });
    }

    /** Un rând „Urmează" devine sarcină pe proiectul update-ului (liberă), iar rândul trimite la ea. */
    /** Un rând „Blocat" / „Urmează" a fost rezolvat sau făcut sarcină (ItemActions): pagina se desenează din nou. */
    #[On('items-changed')]
    public function itemsChanged(): void
    {
        $this->record->refresh();
    }

    public function nextToTask(int $itemId): void
    {
        $update = $this->record;
        $item = $update->items()->where('type', UpdateItemType::Next->value)->whereNull('task_id')->findOrFail($itemId);
        abort_if(! $update->project || auth()->user()->isClient(), 403);
        Gate::authorize('create', Task::class);
        $task = Task::add($update->project, auth()->user(), Str::limit(Markdown::plain($item->text), 197));
        $item->forceFill(['task_id' => $task->id])->save();
        Notification::make()->title(__('Task #:id added on :project.', ['id' => $task->id, 'project' => $update->project->name]))->success()->send();
        $this->record->refresh();
    }

    /** O decizie din update, în contextul proiectului (/ctx): un rând datat sub „Decisions". */
    public function decisionToContext(int $itemId): void
    {
        $update = $this->record;
        $item = $update->items()->where('type', UpdateItemType::Decision->value)->findOrFail($itemId);
        $context = $update->project?->contextDocument();
        abort_unless($context && auth()->user()->can('update', $context), 403);
        $result = ContextDecisions::add($update->project, auth()->user(), [['date' => $update->created_at->copy()->setTimezone($update->user?->tz() ?? config('app.timezone'))->toDateString(), 'text' => $item->text]]);
        Notification::make()->title($result && $result['added'] ? __('Added to the project context (version :version).', ['version' => $result['version']]) : __('It is already in the context.'))->success()->send();
    }

    protected function getViewData(): array
    {
        $update = $this->record->loadMissing(['items.task', 'user', 'project']);
        $viewer = auth()->user();

        return [
            'context' => ! $viewer->isClient() && $update->items->contains('type', UpdateItemType::Decision) ? $update->project?->contextDocument() : null,
            'sections' => collect(UpdateItemType::cases())
                ->map(fn (UpdateItemType $type) => ['type' => $type, 'items' => $update->items->where('type', $type)->values()])
                ->filter(fn (array $section) => $section['items']->isNotEmpty())->values(),
            // cine vede ce (politicile): timpul - autorul, liderul proiectului, adminul; ora de început - autorul și adminul
            'minutes' => $update->minutes !== null && $viewer->can('viewMinutes', $update) ? WorkTime::format($update->minutes) : null,
            // ore estimate din commit-uri (zi fără timp măsurat), cu aceleași drepturi ca orele
            'estimated' => $viewer->can('viewMinutes', $update) && ($estimate = TimeEntry::where('update_id', $update->id)->where('is_estimate', true)->value('minutes'))
                ? WorkTime::format($estimate) : null,
            'started' => $update->started_at && $update->user && $viewer->can('viewWorkTime', $update->user)
                ? $update->started_at->copy()->setTimezone($update->user->tz())->format('H:i') : null,
            'covers' => $update->period_from ? $this->at($update->period_from).' - '.$this->at($update->period_to ?? $update->created_at) : null,
            'links' => UpdateLinks::resolve($update->links ?? [], $update->project),
            'days' => $this->days($update, $viewer),
            'revisions' => $viewer->isClient() ? collect() : $this->revisions($update, $viewer),   // corecturile de după trimitere: ale echipei, nu și ale clientului
        ];
    }

    /** Istoria modificărilor: cine, prin ce, când, de ce și ce s-a schimbat, pe înțeles (timpul doar pentru cine îl vede). */
    private function revisions(Update $update, User $viewer): Collection
    {
        $tz = $viewer->tz();
        $day = fn (?string $date) => $date ? Carbon::parse($date)->locale(app()->getLocale())->isoFormat('D MMMM') : '-';
        $link = fn (int $id) => UpdateResource::getUrl('view', ['record' => $id]);

        return $update->revisions()->with('user')->get()->map(function (UpdateRevision $revision) use ($update, $viewer, $tz, $day, $link) {
            $c = $revision->changes;
            $lines = [];
            if (isset($c['date'])) {
                $lines[] = ['text' => __('Day: :from → :to', ['from' => $day($c['date'][0]), 'to' => $day($c['date'][1])])];
            }
            if (isset($c['summary'])) {
                $lines[] = ['text' => __('Summary before: :text', ['text' => $c['summary'][0]])];
            }
            if (isset($c['project'])) {
                $lines[] = ['text' => __('Project: :from → :to', ['from' => $c['project'][0] ?? '-', 'to' => $c['project'][1] ?? '-'])];
            }
            if (isset($c['minutes']) && $viewer->can('viewMinutes', $update)) {
                $lines[] = ['text' => __('Time: :from → :to', ['from' => $c['minutes'][0] !== null ? WorkTime::format($c['minutes'][0]) : '-', 'to' => $c['minutes'][1] !== null ? WorkTime::format($c['minutes'][1]) : '-'])];
            }
            foreach ($c['lines']['added'] ?? [] as [, $text]) {
                $lines[] = ['text' => '+ '.$text];
            }
            foreach ($c['lines']['removed'] ?? [] as [, $text]) {
                $lines[] = ['text' => '− '.$text];
            }
            foreach ($c['moved_to'] ?? [] as $moved) {
                $lines[] = ['text' => __('Moved to :day: :lines', ['day' => $day($moved['date']), 'lines' => implode('; ', $moved['lines'])]), 'url' => $link($moved['update_id'])];
            }
            if (isset($c['moved_from'])) {
                $lines[] = ['text' => __('Moved here from :day: :lines', ['day' => $day($c['moved_from']['date']), 'lines' => implode('; ', $c['moved_from']['lines'])]), 'url' => $link($c['moved_from']['update_id'])];
            }

            return [
                'when' => $revision->created_at->copy()->setTimezone($tz)->locale(app()->getLocale())->isoFormat('D MMM, HH:mm'),
                'who' => ($revision->user?->name ?? __('Someone')).($revision->source !== Source::Web ? ' · '.$revision->source->getLabel() : ''),
                'reason' => $revision->reason,
                'lines' => $lines,
            ];
        });
    }

    /**
     * Toate update-urile proiectului (sau, fără proiect, ale omului), pe zile, cele mai noi primele, cu cel curent marcat:
     * de pe un update ajungi la tot ce s-a întâmplat pe proiect.
     */
    private function days(Update $update, User $viewer): Collection
    {
        $tz = $viewer->tz();
        $source = $update->project ? $update->project->updates() : $update->user->updates();

        return $source->visibleTo($viewer)->with(['user', 'project'])->latest()->limit(60)->get()   // clientul: fără cele interne
            ->groupBy(fn (Update $u) => $u->created_at->copy()->setTimezone($tz)->toDateString())
            ->map(fn (Collection $group, string $date) => [
                'label' => Carbon::parse($date, $tz)->locale(app()->getLocale())->isoFormat('dddd, D MMMM'),
                'rows' => $group->map(fn (Update $u) => ['update' => $u, 'time' => $u->created_at->copy()->setTimezone($tz)->format('H:i'), 'current' => $u->is($update)])->values(),
            ])->values();
    }

    /** O oră în fusul celui care se uită: „6 October, 18:24". */
    private function at(CarbonInterface $time): string
    {
        return $time->copy()->setTimezone(auth()->user()->tz())->locale(app()->getLocale())->isoFormat('D MMMM, HH:mm');
    }

    /** Înapoi: clientul și proiectul update-ului; fără proiect, omul (pentru echipă). */
    public function backLinks(): array
    {
        $update = $this->record;

        return $update->project ? BackLinks::project($update->project, auth()->user())
            : ($update->user && ! auth()->user()->isClient() ? BackLinks::to($update->user->name, UserResource::getUrl('view', ['record' => $update->user])) : []);
    }
}
