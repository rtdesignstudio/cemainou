{{-- Badge-urile: în profil, colecția cu progres și alegerile omului; pe pagina unui coleg, cele câștigate (dacă le arată), cu „bravo" --}}
<div>
    @if ($visible)
        @php
            $all = $categories->flatten(1);
            $earned = $all->where('tier', '>', 0)->sortByDesc(fn ($r) => sprintf('%d-%s', $r['tier'], $r['badge']?->earned_at?->format('YmdHis')))->values();
        @endphp
        {{-- implicit strânsă: titlul, câteva badge-uri (cele mai mari trepte, cele mai noi) și „Arată toate"; deschisă: alegerile, progresul și toate, pe un rând --}}
        <section @class(['wsa-block wsa-badges', 'is-person' => ! $profile]) id="badges"
            x-data="{ open: null, expanded: (() => { try { return localStorage.getItem('wsa-badges-expanded') === '1' } catch (e) { return false } })() || location.hash === '#badges' }"
            x-init="$watch('expanded', v => { try { localStorage.setItem('wsa-badges-expanded', v ? '1' : '0') } catch (e) {} })" @keydown.escape.window="open = null">
            <header class="wsa-block-head wsa-badges-head">
                <h2>{{ __('Badges') }}</h2>
                <div class="wsa-badges-peek" x-show="! expanded">
                    @foreach ($earned->take(5) as $row)
                        <button type="button" @click="open = '{{ $row['key'] }}'" data-tip="{{ \App\Support\Badges\BadgeSvg::tip($row['key'], $row['tier']) }}">{!! \App\Support\Badges\BadgeSvg::badge($row['key'], $row['tier'], compact: true) !!}</button>
                    @endforeach
                </div>
                <span>{{ __(':count of :total', ['count' => $count, 'total' => $total]) }}</span>
                <button type="button" class="wsa-inline-btn" @click="expanded = ! expanded" :aria-expanded="expanded" x-text="expanded ? @js(__('Show less')) : @js(__('Show all'))">{{ __('Show all') }}</button>
            </header>

            <div x-show="expanded" x-cloak>
                @if ($profile)
                    <div class="wsa-badges-prefs">
                        <label class="wsa-badges-opt"><input type="checkbox" wire:model.live="shared"> {{ __('Show my badges to the team') }}</label>
                        <label class="wsa-badges-opt"><input type="checkbox" wire:model.live="hidden"> {{ __('I don\'t want badges') }}</label>
                        <label class="wsa-badges-opt">{{ __('My evening starts at') }}
                            <select wire:model.live="eveningHour">@foreach (range(15, 23) as $h)<option value="{{ $h }}">{{ $h }}:00</option>@endforeach</select>
                        </label>
                    </div>
                @endif

                @if ($profile && $hidden)
                    <p class="wsa-meta">{{ __('Badges are hidden: you don\'t see them anywhere and nobody sees yours.') }}</p>
                @else
                    @if ($progress)
                        <div class="wsa-badges-progress">
                            @foreach ($progress as $p)
                                <div class="wsa-badges-prog">
                                    {!! \App\Support\Badges\BadgeSvg::badge($p['key'], max(1, $p['tier']), 'wsa-badge', $p['tier'] === 0) !!}
                                    <div>
                                        <strong>{{ $p['name'] }}</strong> · {{ __(':value of :target', ['value' => $p['value'], 'target' => $p['target']]) }}
                                        <span class="wsa-badges-bar"><i style="width: {{ round($p['ratio'] * 100) }}%"></i></span>
                                        <span class="wsa-meta">{{ $p['next'] === 1 ? __(':left more to get it', ['left' => $p['left']]) : __(':left more until :tier', ['left' => $p['left'], 'tier' => \App\Support\Badges\Badges::roman($p['next'])]) }}</span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    {{-- toate, pe un singur rând: câștigate întâi, apoi celelalte, în ordinea categoriilor; se derulează orizontal --}}
                    <div class="wsa-badges-row">
                        @foreach ($earned->merge($all->where('tier', 0)) as $row)
                            @php($locked = $row['tier'] === 0)
                            <button type="button" class="wsa-badges-item" @click="open = '{{ $row['key'] }}'" aria-haspopup="dialog" data-tip="{{ \App\Support\Badges\BadgeSvg::tip($row['key'], max(1, $row['tier']), $locked) }}">
                                {!! \App\Support\Badges\BadgeSvg::badge($row['key'], max(1, $row['tier']), 'wsa-badge', $locked) !!}
                                <span>{{ $locked && $row['secret'] ? __('Secret') : $row['name'] }}</span>
                            </button>
                        @endforeach
                    </div>
                    @if ($all->isEmpty())
                        <p class="wsa-meta">{{ __('No badges yet.') }}</p>
                    @endif
                @endif
            </div>

                {{-- pagina unui badge: când, pentru ce, treptele, cât e de rar, „bravo"-urile --}}
                @foreach ($categories->flatten(1) as $row)
                    @php($locked = $row['tier'] === 0)
                    <div class="wsa-badge-page" x-show="open === '{{ $row['key'] }}'" x-cloak role="dialog" aria-modal="true" aria-label="{{ $row['name'] }}" @click.self="open = null">
                        <div class="wsa-badge-page-box">
                            <button type="button" class="wsa-badge-page-close" @click="open = null" aria-label="{{ __('Close') }}">×</button>
                            <div class="wsa-badge-page-art">{!! \App\Support\Badges\BadgeSvg::badge($row['key'], max(1, $row['tier']), 'wsa-badge', $locked) !!}</div>
                            @if ($locked && $row['secret'])
                                <h3>{{ __('Secret') }}</h3>
                                <p>{{ __('It shows itself when you get it.') }}</p>
                            @else
                                <h3>{{ $row['name'] }}@if ($row['tier'] > 1) · {{ __('tier :tier', ['tier' => \App\Support\Badges\Badges::roman($row['tier'])]) }}@endif</h3>
                                <p>{{ $row['criterion'] }}</p>
                                <ol class="wsa-badge-page-tiers">
                                    @foreach ($row['levelLabels'] as $i => $label)
                                        @php($got = collect($row['history'])->firstWhere('tier', $i + 1))
                                        <li @class(['is-got' => $got])><b>{{ \App\Support\Badges\Badges::roman($i + 1) }}</b> {{ $label }}@if ($got)<span>{{ $got['when'] }}</span>@endif</li>
                                    @endforeach
                                </ol>
                                @if (! $locked)
                                    @php([$have, $of] = $rarity($row['key'], 1))
                                    <p class="wsa-meta">{{ __(':have of :of people in the team have it.', ['have' => $have, 'of' => $of]) }}</p>
                                @endif
                                @if ($row['kudos'])
                                    <ul class="wsa-badge-page-kudos">
                                        @foreach ($row['kudos'] as $k)<li><b>{{ $k['name'] }}</b>: {{ $k['note'] ?: __('well done') }}</li>@endforeach
                                    </ul>
                                @endif
                                @if (! $locked && $mine && $profile)
                                    <button type="button" class="wsa-inline-btn" @click="wsaBadgeImage($root.querySelector('[data-badge-art={{ $row['key'] }}] svg'), @js($row['name']), @js($row['tier'] > 1 ? __('tier :tier', ['tier' => \App\Support\Badges\Badges::roman($row['tier'])]).' · '.$row['levelLabels'][$row['tier'] - 1] : $row['levelLabels'][0]), @js($person->name), @js(collect($row['history'])->last()['when'] ?? ''))">{{ __('Download the image') }}</button>
                                    <span hidden data-badge-art="{{ $row['key'] }}">{!! \App\Support\Badges\BadgeSvg::badge($row['key'], $row['tier']) !!}</span>
                                @endif
                                @if (! $locked && ! $mine && $row['badge'])
                                    @if ($row['kudosFromMe'])
                                        <p class="wsa-meta">{{ __('You said well done.') }}</p>
                                    @else
                                        <form class="wsa-badge-kudos" x-data="{ note: '', sent: false }" @submit.prevent="fetch(@js(route('badges.kudos', $row['badge'])), { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content }, body: JSON.stringify({ note }) }).then(r => sent = r.ok)">
                                            <template x-if="! sent">
                                                <div><input type="text" x-model="note" maxlength="120" placeholder="{{ __('A word, if you want') }}"> <x-filament::button type="submit" size="sm">{{ __('Well done') }}</x-filament::button></div>
                                            </template>
                                            <p x-show="sent" class="wsa-meta">{{ __('Sent. :name will see it.', ['name' => \Illuminate\Support\Str::before($person->name, ' ')]) }}</p>
                                        </form>
                                    @endif
                                @endif
                            @endif
                        </div>
                    </div>
                @endforeach
        </section>
    @endif
</div>
