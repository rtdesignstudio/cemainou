{{-- Cine vede proiectul: echipa, colaboratorii de aici (adăugați / scoși de lider sau admin) și oamenii clientului, dacă proiectul e vizibil lui. --}}
<section class="wsa-block wsa-section">
    <header class="wsa-block-head"><h2>{{ __('Who has access') }}</h2><span>{{ __('who can see this project') }}</span></header>

    @if ($canManage)
        <div class="wsa-access-mode" role="radiogroup" aria-label="{{ __('Who sees the project') }}">
            <button type="button" role="radio" aria-checked="{{ $project->restricted ? 'false' : 'true' }}" @class(['is-on' => ! $project->restricted]) wire:click="restrict(false)">{{ __('The whole team') }}</button>
            <button type="button" role="radio" aria-checked="{{ $project->restricted ? 'true' : 'false' }}" @class(['is-on' => $project->restricted]) wire:click="restrict(true)" wire:confirm="{{ __('Only the chosen people? Everyone else in the team stops seeing this project: not in Projects, Today, Hours, search or their agents.') }}">{{ __('Only chosen people') }}</button>
        </div>
    @endif

    <div class="wsa-access-row">
        <span class="wsa-label">{{ $project->restricted ? __('Always') : __('The team') }}</span>
        <p>{{ $team->pluck('name')->join(', ') }}</p>
        <p class="wsa-meta">{{ $project->restricted ? __('Admins and the project\'s lead always see it.') : __('Admins, leads and members see every project that is not restricted.') }}</p>
    </div>

    <div class="wsa-access-row">
        <span class="wsa-label">{{ $project->restricted ? __('The chosen people') : __('Collaborators') }}</span>
        @forelse ($members as $member)
            <div class="wsa-token" wire:key="member-{{ $member->id }}">
                <div>
                    <div class="wsa-token-name">{{ $member->name }}</div>
                    <div class="wsa-meta">{{ $member->role->getLabel() }} · {{ $member->email }}</div>
                </div>
                @if ($canManage)
                    <button type="button" class="wsa-take" wire:click="remove({{ $member->id }})" wire:confirm="{{ __('Remove :name from this project? They stop seeing it.', ['name' => $member->name]) }}">{{ __('Remove') }}</button>
                @endif
            </div>
        @empty
            <p class="wsa-meta">{{ $project->restricted ? __('Nobody chosen yet: only admins and the lead see it.') : __('No collaborators: only the team sees it.') }}</p>
        @endforelse
        @if ($canManage)
            @if ($candidates->isNotEmpty())
                <form wire:submit="add" class="wsa-access-add">
                    <select wire:model="adding" aria-label="{{ __('Collaborator') }}">
                        <option value="">{{ $project->restricted ? __('Add a person') : __('Add a collaborator') }}</option>
                        @foreach ($candidates as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                    <x-filament::button type="submit" size="sm" color="gray" outlined>{{ __('Add') }}</x-filament::button>
                </form>
            @else
                <p class="wsa-meta">{{ $project->restricted ? __('Everyone in the team is already on the list.') : __('To add a freelancer, invite them from People with the Collaborator role.') }}</p>
            @endif
        @endif
    </div>

    <div class="wsa-access-row">
        <span class="wsa-label">{{ __('The client') }}</span>
        @if ($clientPeople->isNotEmpty())
            <p>{{ $clientPeople->pluck('name')->join(', ') }}</p>
            <p class="wsa-meta">{{ __('They see the tasks, the blockers and the updates that are not internal; never hours, notes or documents.') }}</p>
        @else
            <p class="wsa-meta">{{ $project->client_visible ? __('Visible to the client, but nobody from the client has an account yet.') : __('The client does not see this project.') }}</p>
        @endif
    </div>
</section>
