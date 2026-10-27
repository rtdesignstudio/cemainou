<?php

namespace App\Filament\Resources\Users;

use App\Enums\Role;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Support\Invitations;
use App\Support\TeamMail;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Js;

/**
 * Invitațiile pe web (doar adminul): „Invită pe cineva" în lista oamenilor, „Linkul invitației" (de dat și în altă zi, cât e valabil) și
 * „Trimite din nou invitația" pe pagina celui care n-a intrat încă și în listă.
 * Fără email configurat nu se promite niciun email: fereastra vorbește doar despre link, iar linkul apare pe ecran, cu „Copiază linkul".
 */
class InvitationActions
{
    public static function invite(): Action
    {
        return Action::make('invite')->label(__('Invite someone'))
            ->visible(fn () => auth()->user()?->isAdmin())
            ->modalHeading(__('Invite someone'))
            ->modalDescription(fn () => TeamMail::ready() ? __('They get an email with a link to choose their password (valid 7 days).') : __('Email is not set up, so nothing is sent: you get a link to give them yourself, by chat or message (valid 7 days).'))
            ->schema([
                TextInput::make('name')->label(__('Name'))->required()->maxLength(255),
                TextInput::make('email')->label(__('Email'))->email()->required()->maxLength(255)->unique(User::class, 'email')
                    ->helperText(fn () => TeamMail::ready() ? null : __('The address they will sign in with; no email is sent to it.')),
                Select::make('role')->label(__('Role'))->options(Role::class)->default(Role::Member->value)->required()->native(false)->live(),
                Select::make('client_id')->label(__('Client'))->options(fn () => Client::visibleTo(auth()->user())->orderBy('name')->pluck('name', 'id'))->searchable()
                    ->visible(fn ($get) => self::isClientRole($get('role')))->required(fn ($get) => self::isClientRole($get('role')))
                    ->helperText(__('They see only this client\'s projects that are visible to the client.')),
                Select::make('projects')->label(__('Their projects'))->multiple()->searchable()
                    ->options(fn () => Project::active()->orderBy('name')->pluck('name', 'id'))
                    ->visible(fn ($get) => self::isRole($get('role'), Role::Collaborator))->required(fn ($get) => self::isRole($get('role'), Role::Collaborator))
                    ->helperText(__('A collaborator sees only these projects; you can add more later from each project\'s page.')),
            ])
            ->modalSubmitActionLabel(fn () => TeamMail::ready() ? __('Invite') : __('Create the link'))
            ->action(function (array $data) {
                abort_unless(auth()->user()?->isAdmin(), 403);
                $role = $data['role'] instanceof Role ? $data['role'] : Role::from($data['role']);
                [$person, $url, $sent, $error] = Invitations::invite(auth()->user(), $data['name'], $data['email'], $role, $role === Role::Client ? ['client_id' => $data['client_id'] ?? null] : []);
                if ($role === Role::Collaborator && $person) {
                    $person->memberProjects()->syncWithPivotValues(array_map('intval', $data['projects'] ?? []), ['added_by' => auth()->id()]);
                }
                self::notify($person, $url, $sent, $error);
            });
    }

    public static function resend(): Action
    {
        return Action::make('resendInvitation')->label(fn () => TeamMail::ready() ? __('Send the invitation again') : __('New invitation link'))->outlined()
            ->visible(fn (User $record) => auth()->user()?->isAdmin() && Invitations::pending($record))
            ->requiresConfirmation()->modalHeading(fn () => TeamMail::ready() ? __('Send the invitation again?') : __('A new invitation link?'))
            ->modalDescription(fn () => TeamMail::ready() ? __('A new link, valid 7 days; the old one stops working.') : __('A new link to give them yourself, valid 7 days; the old one stops working. Nothing is sent by email.'))
            ->modalSubmitActionLabel(fn () => TeamMail::ready() ? __('Send') : __('Create the link'))
            ->action(function (User $record) {
                abort_unless(auth()->user()?->isAdmin(), 403);
                [$url, $sent, $error] = Invitations::send($record, auth()->user());
                self::notify($record, $url, $sent, $error);
            });
    }

    /** Linkul de acum, de copiat, cu data până la care merge; expirat: spune asta și trimite la „Link nou". */
    public static function showLink(): Action
    {
        return Action::make('invitationLink')->label(__('Invitation link'))->outlined()->color('gray')
            ->visible(fn (User $record) => auth()->user()?->isAdmin() && Invitations::pending($record))
            ->modalHeading(fn (User $record) => __('The invitation link for :name', ['name' => $record->name]))
            ->modalDescription(fn (User $record) => Invitations::link($record)
                ? __('Give it to them by chat or message. It works once, until :date.', ['date' => Invitations::until($record)->setTimezone(auth()->user()->tz())->locale(app()->getLocale())->isoFormat('D MMMM, HH:mm')])
                : __('The link expired or was never kept. Create a new one with “New invitation link”.'))
            ->schema(fn (User $record) => Invitations::link($record) ? [
                TextInput::make('link')->hiddenLabel()->readOnly()->default(Invitations::link($record))->extraInputAttributes(['onclick' => 'this.select()']),
            ] : [])
            ->modalSubmitAction(false)                                       // nu un buton de trimis formularul (fereastra se închidea înainte de copiere)
            ->extraModalFooterActions(fn (User $record) => Invitations::link($record)
                ? [Action::make('copyLink')->label(__('Copy the link'))->alpineClickHandler(self::copyJs(Invitations::link($record)))]
                : [])
            ->modalCancelActionLabel(__('Close'))
            ->action(fn () => null);
    }

    /**
     * Copierea unui text, și pe telefon: clipboard-ul browserului, iar unde nu merge (Android în ferestre, http) un câmp ascuns, selectat și copiat;
     * câmpul stă în fereastra deschisă, nu în pagină, altfel capcana de focus a ferestrei nu-l lasă selectat. Butonul spune „Copiat".
     */
    public static function copyJs(string $text): string
    {
        // Js::from: textul fără ghilimele duble (JSON.parse('...')), altfel închide atributul x-on:click al butonului și copierea nu mai pornește deloc
        return '(t => { const done = () => { $el.innerText = '.Js::from(__('Copied')).' }; const fallback = () => { const box = $el.closest(\'.fi-modal-window, .fi-no-notification\') ?? document.body;'
            .' const a = document.createElement(\'textarea\'); a.value = t; a.setAttribute(\'readonly\', \'\'); a.style.position = \'fixed\'; a.style.top = \'0\'; a.style.opacity = \'0\';'
            .' box.appendChild(a); a.focus(); a.select(); a.setSelectionRange(0, t.length); try { if (document.execCommand(\'copy\')) done() } catch (e) {} a.remove() };'
            .' navigator.clipboard && window.isSecureContext ? navigator.clipboard.writeText(t).then(done, fallback) : fallback() })('.Js::from($text).')';
    }

    private static function isRole(mixed $value, Role $role): bool
    {
        return ($value instanceof Role ? $value : Role::tryFrom((string) $value)) === $role;
    }

    private static function isClientRole(mixed $role): bool
    {
        return ($role instanceof Role ? $role : Role::tryFrom((string) $role)) === Role::Client;
    }

    /** A plecat emailul: o confirmare. Nu: linkul, de copiat (rămâne pe ecran până îl închizi); avertisment doar dacă emailul trebuia să plece și n-a plecat. */
    private static function notify(User $person, string $url, bool $sent, ?string $error): void
    {
        if ($sent) {
            Notification::make()->title(__('Invitation sent to :email.', ['email' => $person->email]))->success()->send();

            return;
        }

        Notification::make()
            ->title($error ? __('The email could not be sent; give :name this link yourself (valid 7 days):', ['name' => $person->name]) : __('The invitation link for :name (valid 7 days):', ['name' => $person->name]))
            ->body($url)->status($error ? 'warning' : 'success')->persistent()
            ->actions([Action::make('copy')->label(__('Copy the link'))->alpineClickHandler(self::copyJs($url))])
            ->send();
    }
}
