<x-filament-panels::page>
    {{-- Cine ce vede: proiectele pe rânduri, oamenii pe coloane; în celulă cum îl vede omul și câte sarcini deschise are acolo. --}}
    @php
        $label = ['lead' => __('lead'), 'listed' => __('in the list'), 'team' => __('whole team'), 'none' => ''];
    @endphp
    <x-wsa.stats>
        <x-wsa.stat :label="__('Active projects')">{{ $rows->count() }}</x-wsa.stat>
        <x-wsa.stat :label="__('Restricted')" :meta="__('only the chosen people')">{{ $rows->filter(fn ($r) => $r['project']->restricted)->count() }}</x-wsa.stat>
        <x-wsa.stat :label="__('In a list, no tasks')" :meta="__('access perhaps forgotten')">{{ $forgotten }}</x-wsa.stat>
    </x-wsa.stats>

    <div class="wsa-access-matrix">
        <table>
            <thead>
                <tr>
                    <th scope="col">{{ __('Project') }}</th>
                    @foreach ($people as $u)
                        <th scope="col">{{ $u->name }}<small>{{ $u->role->getLabel() }}</small></th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    @php($p = $row['project'])
                    <tr wire:key="access-{{ $p->id }}">
                        <th scope="row">
                            <a href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('view', ['record' => $p]) }}">{{ $p->name }}</a>
                            <button type="button" class="wsa-access-mode-btn" wire:click="restrict({{ $p->id }}, {{ $p->restricted ? 'false' : 'true' }})"
                                @unless ($p->restricted) wire:confirm="{{ __('Only the chosen people? Everyone else in the team stops seeing this project: not in Projects, Today, Hours, search or their agents.') }}" @endunless>
                                {{ $p->restricted ? __('only chosen people') : __('the whole team') }}
                            </button>
                        </th>
                        @foreach ($row['cells'] as $c)
                            @php($tip = $c['forgotten'] ? __('In the list, but no task here.') : ($c['toggles'] ? ($c['state'] === 'listed' ? __('Click to take out of the list') : __('Click to add to the list')) : ($c['state'] === 'team' ? __('Sees it because the project is open to the whole team.') : null)))
                            <td @class(['is-'.$c['state'], 'is-forgotten' => $c['forgotten']])>
                                @if ($c['toggles'])
                                    <button type="button" wire:click="toggle({{ $p->id }}, {{ $c['user'] }})" title="{{ $tip }}" aria-pressed="{{ $c['state'] === 'listed' ? 'true' : 'false' }}">
                                @else
                                    <span @if ($tip) title="{{ $tip }}" @endif>
                                @endif
                                    @if ($c['state'] !== 'none')
                                        <b>{{ $c['open'] ?: '' }}</b><small>{{ $label[$c['state']] }}</small>
                                    @elseif ($c['toggles'])
                                        <small class="wsa-access-add-hint">+</small>
                                    @endif
                                @if ($c['toggles'])</button>@else</span>@endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <p class="wsa-meta">{{ __('The number is the person\'s open tasks on the project. On a project open to the whole team, only collaborators need to be in the list; to choose people one by one, switch it to only chosen people.') }}</p>
</x-filament-panels::page>
