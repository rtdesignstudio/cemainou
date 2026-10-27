{{--
    Clienții: banda de cifre, Active / Arhivați cu căutarea, apoi un rând pe client - culoarea, numele și contactul, proiectele,
    timpul lunii și totalul (cine are voie la ore), sarcinile deschise și blocate, ultima activitate; editarea, pentru admin.
--}}
@php
    use App\Filament\Resources\Clients\ClientResource;
    use App\Filament\Resources\Projects\ProjectResource;
    use App\Filament\Resources\Tasks\TaskResource;

    $data = $this->clients();
    $rows = $data['rows'];
    $summary = $data['summary'];
    $canEdit = auth()->user()->isAdmin();
@endphp
<div class="wsa-clients">
    @if ($this->status === 'active')
        <x-wsa.stats>
            <x-wsa.stat :label="__('Active clients')">{{ $summary['clients'] }}</x-wsa.stat>
            <x-wsa.stat :label="__('Active projects')">{{ $summary['projects'] }}</x-wsa.stat>
            @if ($summary['month'] !== null)
                <x-wsa.stat :label="__('Time, :month', ['month' => now(auth()->user()->tz())->locale(app()->getLocale())->isoFormat('MMMM')])"><x-wsa.duration :minutes="$summary['month']" /></x-wsa.stat>
            @endif
            <x-wsa.stat :label="__('Blocked tasks')" :blocked="$summary['blocked'] > 0">{{ $summary['blocked'] }}</x-wsa.stat>
        </x-wsa.stats>
    @endif

    <div class="wsa-toolbar wsa-clients-bar">
        <nav class="wsa-tabs" aria-label="{{ __('Clients') }}">
            @foreach (['active' => __('Active ones'), 'archived' => __('Archived ones')] as $value => $label)
                <button type="button" wire:click="$set('status', '{{ $value }}')" @class(['is-on' => $this->status === $value])>{{ $label }}</button>
            @endforeach
        </nav>
        <label class="wsa-cmds-search">
            <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedMagnifyingGlass" class="wsa-cmds-search-icon" />
            <input type="search" wire:model.live.debounce.300ms="find" placeholder="{{ __('Search a client or a contact') }}" aria-label="{{ __('Search') }}">
        </label>
    </div>

    @forelse ($rows as $row)
        @php $client = $row['client']; @endphp
        <article class="wsa-client" wire:key="client-{{ $client->id }}" style="--client: {{ $client->color ?? '#d4d4d4' }}">
            <div class="wsa-client-main">
                <h2><a href="{{ ClientResource::getUrl('view', ['record' => $client]) }}">{{ $client->name }}</a></h2>
                <p class="wsa-meta">
                    @if ($client->contact_name || $client->contact_email)
                        {{ $client->contact_name }}@if ($client->contact_name && $client->contact_email) · @endif
                        @if ($client->contact_email)<a href="mailto:{{ $client->contact_email }}">{{ $client->contact_email }}</a>@endif
                    @else
                        {{ __('No contact person yet') }}
                    @endif
                </p>
                <div class="wsa-client-projects">
                    @foreach ($row['projects']->take(4) as $project)
                        <a href="{{ ProjectResource::getUrl('view', ['record' => $project]) }}" class="wsa-chip">{{ $project->name }}</a>
                    @endforeach
                    @if ($row['projects']->count() > 4)<span class="wsa-meta">+{{ $row['projects']->count() - 4 }}</span>@endif
                    @if ($row['projects']->isEmpty())<span class="wsa-meta">{{ __('No active project') }}</span>@endif
                    @if ($row['archived_projects'])<span class="wsa-meta">· {{ trans_choice(':count archived|:count archived', $row['archived_projects']) }}</span>@endif
                </div>
            </div>
            <dl class="wsa-client-facts">
                @if ($row['month'] !== null)
                    <div><dt class="wsa-label">{{ __('This month') }}</dt><dd><x-wsa.duration :minutes="$row['month']" /></dd></div>
                    <div><dt class="wsa-label">{{ __('In total') }}</dt><dd><x-wsa.duration :minutes="$row['total']" /></dd></div>
                @endif
                <div>
                    <dt class="wsa-label">{{ __('Open tasks') }}</dt>
                    <dd>
                        @if ($row['open'])
                            <a href="{{ ClientResource::getUrl('view', ['record' => $client]) }}">{{ $row['open'] }}</a>
                        @else 0 @endif
                        @if ($row['blocked'])<span class="wsa-client-blocked"><i aria-hidden="true"></i>{{ trans_choice(':count blocked|:count blocked', $row['blocked']) }}</span>@endif
                    </dd>
                </div>
                <div><dt class="wsa-label">{{ __('Last activity') }}</dt><dd class="is-small">@if ($row['last'])<x-wsa.time :at="$row['last']" />@else - @endif</dd></div>
            </dl>
            @if ($canEdit)
                <a href="{{ ClientResource::getUrl('edit', ['record' => $client]) }}" class="wsa-client-edit" title="{{ __('Edit') }}">
                    <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedPencilSquare" /><span class="sr-only">{{ __('Edit') }}</span>
                </a>
            @endif
        </article>
    @empty
        <p class="wsa-empty">{{ trim($this->find) !== '' ? __('No client matches.') : ($this->status === 'archived' ? __('No archived clients.') : __('No clients yet. Add one with “New client”.')) }}</p>
    @endforelse
</div>
