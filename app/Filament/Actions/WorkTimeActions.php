<?php

namespace App\Filament\Actions;

use App\Actions\CorrectTime;
use App\Enums\WorkCategory;
use App\Models\Project;
use App\Models\User;
use App\Support\Settings;
use App\Support\WorkTime;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Fieldset;
use Illuminate\Support\Carbon;

/**
 * „Adaugă timp" și „Corectează timpul", la fel pe pagina unui om și pe cardul „Ziua mea": $person dă omul, $day ziua văzută (Y-m-d sau
 * null = azi), $done primește ziua după salvare (pagina o arată). Omul pentru el, adminul pentru oricine (manageWorkTime), în fereastra din Setări.
 */
class WorkTimeActions
{
    /** Timp pe care agentul nu-l vede (ședință, telefon), adăugat pe web. */
    public static function add(Closure $person, Closure $day, ?Closure $done = null): Action
    {
        return Action::make('addTime')->label(__('Add time'))->outlined()
            ->visible(fn () => self::allowed($person()))
            ->schema([
                Select::make('project_id')->label(__('Project'))->required()->searchable()->options(fn () => Project::active()->visibleTo($person())->orderBy('name')->pluck('name', 'id')),
                DatePicker::make('date')->label(__('Day'))->required()->native(false)->format('Y-m-d')->default(fn () => $day() ?: now($person()->tz())->toDateString())
                    ->minDate(fn () => self::earliest($person()))->maxDate(fn () => now($person()->tz())->endOfDay()),
                self::duration('hours', 'minutes', __('How long')),
                TextInput::make('start')->label(__('From (optional)'))->type('time')->rule('date_format:H:i')
                    ->helperText(__('Without it, the hourly bars place the time in the gaps of the day, hatched.')),
                Select::make('category')->label(__('Category'))->options(WorkCategory::class)->default(WorkCategory::Meeting->value),
                TextInput::make('note')->label(__('What it was'))->maxLength(200)->placeholder(__('E.g. meeting with the client')),
            ])
            ->action(function (array $data) use ($person, $done) {
                $user = $person();
                abort_unless(self::allowed($user), 403);
                $minutes = (int) ($data['hours'] ?? 0) * 60 + (int) ($data['minutes'] ?? 0);
                if ($minutes < 1 || $minutes > 720) {
                    Notification::make()->title(__('Write between 1 minute and 12 hours.'))->danger()->send();

                    return;
                }
                $user->timeEntries()->create([
                    'project_id' => Project::visibleTo($user)->findOrFail($data['project_id'])->id, 'date' => Carbon::parse($data['date'])->toDateString(),
                    'minutes' => $minutes, 'category' => $data['category'] ?? null, 'note' => filled($data['note'] ?? null) ? trim($data['note']) : null,
                    'start_minute' => self::minute($data['start'] ?? null),
                ]);
                $done && $done(Carbon::parse($data['date'])->toDateString());
                Notification::make()->title(__('Time added.'))->success()->send();
            });
    }

    /**
     * Cât a lucrat omul de fapt într-o zi pe un proiect, cu motivul (App\Actions\CorrectTime). Argumentele (project, actual, start, date)
     * completează fereastra: de la un clic pe un proiect sau de la orele alese pe graficul „Ziua mea".
     */
    public static function correct(Closure $person, Closure $day, ?Closure $done = null): Action
    {
        $total = fn ($project, $date) => $project && $date && ($p = Project::find($project)) ? CorrectTime::total($person(), $p, Carbon::parse($date)->toDateString()) : null;
        $fill = fn ($set, ?int $minutes) => [$set('actual_hours', $minutes === null ? null : intdiv($minutes, 60)), $set('actual_minutes', $minutes === null ? null : $minutes % 60)];

        return Action::make('correctTime')->label(__('Correct the time'))->outlined()
            ->visible(fn () => self::allowed($person()))
            ->fillForm(function (array $arguments) use ($day, $person, $total) {
                $date = $arguments['date'] ?? ($day() ?: now($person()->tz())->toDateString());
                $actual = isset($arguments['actual']) ? (int) $arguments['actual'] : $total($arguments['project'] ?? null, $date);

                return ['project_id' => $arguments['project'] ?? null, 'date' => $date, 'start' => $arguments['start'] ?? null,
                    'actual_hours' => $actual === null ? null : intdiv($actual, 60), 'actual_minutes' => $actual === null ? null : $actual % 60];
            })
            ->modalDescription(__('Write how long you really worked that day on the project. What was measured stays as it was; the difference is kept as a correction, with the reason, and the totals use it.'))
            ->schema([
                Select::make('project_id')->label(__('Project'))->required()->searchable()->options(fn () => Project::visibleTo($person())->orderBy('name')->pluck('name', 'id'))
                    ->live()->afterStateUpdated(fn ($state, $get, $set) => $fill($set, $total($state, $get('date')))),
                DatePicker::make('date')->label(__('Day'))->required()->native(false)->format('Y-m-d')->live()
                    ->minDate(fn () => self::earliest($person()))->maxDate(fn () => now($person()->tz())->endOfDay())
                    ->afterStateUpdated(fn ($state, $get, $set) => $fill($set, $total($get('project_id'), $state))),
                self::duration('actual_hours', 'actual_minutes', __('How long you really worked'), __('0 hours and 0 minutes if you did not work on it.')),
                TextInput::make('start')->label(__('The extra time started at (optional)'))->type('time')->rule('date_format:H:i')
                    ->helperText(__('When you take time out: from what time. My day then leaves that stretch out of the chart, too.')),
                Textarea::make('reason')->label(__('Why'))->required()->maxLength(200)->rows(2)->placeholder(__('E.g. the agent stayed open over lunch')),
            ])
            ->action(function (array $data) use ($person, $done) {
                $user = $person();
                abort_unless(self::allowed($user), 403);
                $project = Project::visibleTo($user)->findOrFail($data['project_id']);
                $minutes = (int) ($data['actual_hours'] ?? 0) * 60 + (int) ($data['actual_minutes'] ?? 0);
                $date = Carbon::parse($data['date'])->toDateString();
                $result = CorrectTime::apply($user, $project, $date, $minutes, $data['reason'], auth()->user(), self::minute($data['start'] ?? null));
                $done && $done($date);
                Notification::make()->title(__('Time corrected: :from → :to.', ['from' => WorkTime::format($result['before']), 'to' => WorkTime::format($result['after'])]))->success()->send();
            });
    }

    /** Durata în două câmpuri numerice, ore și minute: pe telefon, tastatura de cifre; fără un format de scris (2h 30m) greșit. */
    private static function duration(string $hours, string $minutes, string $label, ?string $help = null): Fieldset
    {
        return Fieldset::make($label)->columns(['default' => 2])->schema([
            TextInput::make($hours)->label(__('Hours'))->integer()->minValue(0)->maxValue(24)->default(0)->required()->inputMode('numeric'),
            TextInput::make($minutes)->label(__('Minutes'))->integer()->minValue(0)->maxValue(59)->default(0)->required()->inputMode('numeric'),
        ])->extraAttributes($help ? ['title' => $help] : []);
    }

    public static function allowed(User $person): bool
    {
        return (bool) auth()->user()?->can('manageWorkTime', $person) && ! $person->isClient();
    }

    private static function earliest(User $person): string
    {
        return now($person->tz())->subDays(Settings::get('report_days'))->toDateString();
    }

    /** „14:30" → minutul zilei (870); gol → null. */
    private static function minute(?string $time): ?int
    {
        return filled($time) ? (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2) : null;
    }
}
