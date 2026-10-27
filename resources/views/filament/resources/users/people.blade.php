{{--
    Oamenii, ca niște carduri (aceeași grilă ca echipa de pe Azi): sus căutarea și filtrele la vedere (grupul, proiectul), adminul și conturile dezactivate;
    pe card rolul (și firma, la client), la ce lucrează azi, când a fost văzut, săptămâna (timpul, cine are voie; update-urile; sarcinile deschise),
    proiectele pe care e activ și, pentru admin, invitația neacceptată și starea uneltelor. Cardul duce la pagina omului (cine are voie s-o vadă).
--}}
<x-filament-panels::page>
    @php($viewer = auth()->user())
    <div class="wsa-toolbar wsa-people-bar">
        <label class="wsa-cmds-search">
            <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedMagnifyingGlass" class="wsa-cmds-search-icon" />
            <input type="search" wire:model.live.debounce.300ms="find" placeholder="{{ __('Search a person') }}" aria-label="{{ __('Search') }}">
        </label>
        @if ($viewer->isAdmin())
            <label class="wsa-layer-group"><input type="checkbox" wire:model.live="inactive"> {{ __('Deactivated accounts') }}</label>
        @endif
    </div>
    <div class="wsa-task-layers">
        <x-wsa.chips :items="$groups" model="group" :all="__('Everyone')" :current="$this->group" :label="__('Who')" />
        <x-wsa.chips :items="$projects" model="projectId" :all="__('All projects')" :current="$this->projectId" :label="__('Projects')" />
    </div>

    @if ($cards->isEmpty())
        <p class="wsa-empty">{{ trim($this->find) !== '' || $this->group || $this->projectId ? __('Nobody matches.') : __('Nobody here yet.') }}</p>
    @else
        <div class="wsa-team wsa-people">
            @foreach ($cards as $c)
                @php($u = $c['user'])
                <article class="wsa-person" wire:key="person-{{ $u->id }}">
                    @if ($c['active'])
                        <span class="wsa-presence is-now">{{ __('Now') }}</span>
                    @elseif ($c['seen'])
                        <span class="wsa-presence" title="{{ \App\Support\Ago::exact($c['seen']) }}">{{ \App\Support\Ago::short($c['seen']) }}</span>
                    @endif
                    <div class="wsa-person-top">
                        <div>
                            @if ($c['link'])
                                <a href="{{ \App\Filament\Resources\Users\UserResource::getUrl('view', ['record' => $u]) }}" class="wsa-name">{{ $u->name }}</a>
                            @else
                                <span class="wsa-name">{{ $u->name }}</span>
                            @endif
                            <div class="wsa-meta">{{ collect([$u->role->getLabel(), $c['company'], $c['invited'] ? __('invited, not joined yet') : null, ! $u->is_active ? __('deactivated') : null])->filter()->join(' · ') }}</div>
                        </div>
                    </div>

                    @if ($c['focus'])
                        <p class="wsa-people-focus"><span class="wsa-label">{{ __('Today') }}</span> {{ $c['focus'] }}</p>
                    @endif

                    @if (! $u->isClient())
                        <dl class="wsa-people-week">
                            @if ($c['time'] !== null)<div><dt class="wsa-label">{{ __('This week') }}</dt><dd>{{ \App\Support\WorkTime::format($c['time']) }}</dd></div>@endif
                            <div><dt class="wsa-label">{{ __('Updates') }}</dt><dd>{{ $c['updates'] }}</dd></div>
                            <div><dt class="wsa-label">{{ __('Open tasks') }}</dt><dd>{{ $c['open'] }}</dd></div>
                        </dl>
                    @endif

                    @if ($c['projects']->isNotEmpty())
                        <p class="wsa-people-projects">
                            @foreach ($c['projects'] as $p)<a href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('view', ['record' => $p]) }}">{{ $p->name }}</a>@endforeach
                        </p>
                    @endif

                    @if ($c['setup'])
                        <x-wsa.setup :items="$c['setup']" />
                    @endif
                </article>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
