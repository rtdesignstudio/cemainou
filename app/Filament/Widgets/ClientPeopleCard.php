<?php

namespace App\Filament\Widgets;

use App\Models\Project;
use App\Models\User;
use App\Support\ClientPeople;
use App\Support\Invitations;
use App\Support\PageCache;
use App\Support\TeamMail;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;

/** Azi, pentru echipă: oamenii clienților (ClientPeople) - cine e, ce a făcut, ce cere reacție, cât de pornit e pe cemainou. */
class ClientPeopleCard extends Widget
{
    protected string $view = 'filament.widgets.client-people';

    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        $user = auth()->user();
        if (! $user || $user->isClient()) {
            return false;
        }
        $clients = Project::visibleTo($user)->where('is_archived', false)->whereNotNull('client_id')->select('client_id');   // ieftin: doar dacă există oameni de-ai clienților

        return User::where('role', 'client')->where('is_active', true)->whereIn('client_id', $clients)->exists();
    }

    /** „Retrimite invitația" (adminul, ca pe pagina omului): un link nou, valabil 7 zile. */
    public function resend(int $id): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);
        $person = User::where('role', 'client')->findOrFail($id);
        abort_unless(Invitations::pending($person), 422);
        [, $sent, $error] = Invitations::send($person, auth()->user());
        Notification::make()->title($sent ? __('Invitation sent to :email.', ['email' => $person->email]) : ($error ? __('The email did not go: :error', ['error' => $error]) : __('A new link is ready on their page.')))
            ->{$sent ? 'success' : 'warning'}()->send();
    }

    protected function getViewData(): array
    {
        $viewer = auth()->user();

        return ['body' => PageCache::html('client-people', $viewer, [$viewer->isAdmin(), TeamMail::ready()], fn () => view('filament.widgets.client-people-body', ['people' => ClientPeople::for($viewer), 'admin' => $viewer->isAdmin()]))];
    }
}
