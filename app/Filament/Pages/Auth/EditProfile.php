<?php

namespace App\Filament\Pages\Auth;

use App\Http\Middleware\SetLocale;
use App\Support\Demo;
use App\Support\Settings;
use App\Support\Timezones;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

/** Profilul: „You" (nume, e-mail, fus orar, limbă, parolă) și „Agents" (cheile agenților, în componenta AgentTokens). */
class EditProfile extends BaseEditProfile
{
    protected string $view = 'filament.pages.auth.edit-profile';

    public function defaultForm(Schema $schema): Schema
    {
        return parent::defaultForm($schema)->inlineLabel(false);         // etichetele deasupra câmpurilor, în coloana îngustă
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getNameFormComponent()->autofocus(false),             // fără focus la deschidere: pe telefon ar porni tastatura (feedback #25)
            $this->getEmailFormComponent(),
            Select::make('timezone')->label(__('Timezone'))->required()->searchable()
                ->options(fn () => Timezones::options()),
            Select::make('locale')->label(__('Language'))->required()->options(SetLocale::LOCALES),
            Toggle::make('wants_digest')->label(__('The weekly summary by email'))->helperText(__('What happened during the week on the projects you see.')),
            Toggle::make('track_app')->label(__('Measure the time in the app'))->hidden(fn () => auth()->user()?->isClient())
                ->helperText(__('While cemainou is open and you work in it: on the project of the page (a project, a task, an update); on the general pages, as time of the day without a project. Also while the Feedback widget is open on a client\'s site, on its project. Shown apart as "in the app".')),
            Toggle::make('wants_update_nudge')->label(__('My agent offers to report'))->hidden(fn () => ! auth()->user()?->usesAgents())
                ->helperText(__('In the afternoon, after an hour of work on a project where you reported before, your agent offers once to draft today\'s update. Off: only when you type /upd.')),
            Toggle::make('time_in_days')->label(__('Totals in work days'))->hidden(fn () => auth()->user()?->isClient())
                ->helperText(fn () => __('A project\'s or a month\'s time as 3 days 4h 20m (a day = :hours h). Off: only hours, 28h 20m. The exact hours show on hover anyway.', ['hours' => Settings::get('workday_hours')])),
            Toggle::make('wants_task_emails')->label(__('Emails about tasks'))->helperText(fn () => auth()->user()?->isClient()
                ? __('When a task of yours is done or the team answers you.')
                : __('When a client adds a task on a project you lead, or writes on one of your tasks.')),
            $this->getPasswordFormComponent()->hidden(fn () => Demo::on()),               // demo: oricine intră ca oricine, deci parola rămâne
            $this->getPasswordConfirmationFormComponent()->hidden(fn () => Demo::on()),
            $this->getCurrentPasswordFormComponent()->hidden(fn () => Demo::on()),
        ]);
    }

    protected function getFormActions(): array
    {
        return [$this->getSaveFormAction()];
    }

    public function getTitle(): string|Htmlable
    {
        return __('Profile');
    }

    public function getSubheading(): ?string
    {
        $user = $this->getUser();

        return $user->name.' · '.$user->role->getLabel();
    }

    /** După salvare, pagina se reîncarcă: limba nouă se aplică imediat. */
    protected function getRedirectUrl(): ?string
    {
        return static::getUrl();
    }
}
