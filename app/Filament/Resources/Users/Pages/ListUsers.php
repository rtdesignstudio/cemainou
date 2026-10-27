<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\InvitationActions;
use App\Filament\Resources\Users\UserResource;
use App\Models\Project;
use App\Support\PeopleCards;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Livewire\Attributes\Url;

/**
 * Oamenii, ca niște carduri (ca pe Azi), după regula unică (User::scopeVisibleTo): echipa îi vede pe toți, colaboratorul
 * oamenii proiectelor lui; clientul nu are compartimentul (nu vede activitatea echipei). Invitarea, rolurile, inactivii și starea uneltelor: doar adminul.
 */
class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected string $view = 'filament.resources.users.people';

    /** Rândul de sus: toți, echipa, colaboratorii, clienții. */
    #[Url(as: 'group')]
    public ?string $group = null;

    /** Doar oamenii unui proiect. */
    #[Url(as: 'project')]
    public ?int $projectId = null;

    public string $find = '';

    /** Adminul: și conturile dezactivate. */
    #[Url(as: 'inactive')]
    public bool $inactive = false;

    public function getSubheading(): ?string
    {
        return auth()->user()->seesWholeTeam() ? __('Who is on the team, what they work on and how their week goes.') : __('The people you work with on your projects.');
    }

    /** Lista o vede echipa și colaboratorii (ce conține hotărăște regula unică); clientul nu vede activitatea echipei (decizia din 15 oct. 2026). */
    public static function canAccess(array $parameters = []): bool
    {
        $user = auth()->user();

        return $user !== null && $user->is_active && ! $user->isClient();
    }

    protected function getHeaderActions(): array
    {
        return [
            InvitationActions::invite(),                                                // emailul cu linkul: omul își alege singur parola
            CreateAction::make()->label(__('Add with a password'))->outlined(),         // fără email: adminul îi pune parola
        ];
    }

    protected function getViewData(): array
    {
        $viewer = auth()->user();

        return [
            'cards' => PeopleCards::for($viewer, in_array($this->group, PeopleCards::GROUPS, true) ? $this->group : null, $this->projectId, $this->find, $this->inactive),
            'projects' => Project::visibleTo($viewer)->active()->orderBy('name')->pluck('name', 'id'),
            'groups' => array_filter(['team' => __('Team'), 'collaborators' => __('Collaborators'), 'clients' => __('Clients')]),
        ];
    }
}
