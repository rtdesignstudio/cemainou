@php
    $f = fn (int $m) => \App\Support\WorkTime::total($m);                          // totalurile: în zile de lucru, dacă omul vrea
    $share = fn (int $m) => $hours['total'] ? round($m * 100 / $hours['total']) : 0;
    $pct = fn (int $m) => $m > 0 && $share($m) < 1 ? '<1%' : $share($m).'%';          // puțin, dar nu zero
    $initials = fn (string $name) => mb_strtoupper(collect(preg_split('/\s+/u', trim($name)))->filter()->take(2)->map(fn ($word) => mb_substr($word, 0, 1))->join(''));
    $delta = $hours['previous'] ? (int) round(($hours['total'] - $hours['previous']) * 100 / $hours['previous']) : null;
@endphp
<x-filament-panels::page>
    {{-- perioada (taburi), apoi bara de unelte: ‹ luna › sau datele intervalului, clientul, exportul; apoi banda de cifre --}}
    <nav class="wsa-tabs" aria-label="{{ __('Period') }}">
        @foreach ($ranges as $key => $name)
            <button type="button" wire:click="pick('{{ $key }}')" @class(['is-on' => $this->range === (string) $key]) @if ($this->range === (string) $key) aria-current="true" @endif>{{ $name }}</button>
        @endforeach
    </nav>
    <div class="wsa-toolbar">
        @if ($this->range === 'month')
            <x-wsa.period-nav :label="$label" previous="previous" :previous-label="__('Previous month')" :next="$isCurrent ? null : 'next'" :next-label="__('Next month')"
                :current="$isCurrent ? null : 'pick(\'month\')'" :current-label="__('Current month')" />
        @elseif ($this->range === 'custom')
            <div class="wsa-period">
                <label class="wsa-h-date"><span class="wsa-label">{{ __('From') }}</span><input type="date" wire:model.live.debounce.500ms="from" max="{{ $today }}"></label>
                <label class="wsa-h-date"><span class="wsa-label">{{ __('To') }}</span><input type="date" wire:model.live.debounce.500ms="to" max="{{ $today }}"></label>
            </div>
        @else
            <x-wsa.period-nav :label="$label" />
        @endif
        <div class="wsa-toolbar-tools">
            <a href="{{ $exportUrl }}" class="wsa-link-strong">{{ __('Download CSV (day by day)') }}</a>
        </div>
    </div>

    <div class="wsa-task-layers">
        <x-wsa.chips :items="$clients" model="client" :all="__('All clients')" :current="$this->client" :label="__('Clients')" />
    </div>

    @if ($hours['total'] === 0 && $hours['estimated'] === 0)
        <p class="wsa-empty">{{ $this->range === 'month' ? __('No time on your projects this month.') : __('No time on the projects in this period.') }}</p>
    @else
        <x-wsa.stats>
            <x-wsa.stat :label="__('Total')" main :meta="$hours['previous'] ? __(':month: :time', ['month' => ucfirst($previousLabel), 'time' => $f($hours['previous'])]).($delta !== null && ! $isCurrent ? ' · '.($delta > 0 ? '+' : '').$delta.'%' : '') : null"><x-wsa.duration :minutes="$hours['total']" /></x-wsa.stat>
            <x-wsa.stat :label="__('Measured')" :meta="collect([$hours['agent'] ? __('the agent worked :time', ['time' => $f($hours['agent'])]) : null, $hours['app'] ? __(':time in the app', ['time' => $f($hours['app'])]) : null])->filter()->join(' · ') ?: null"><x-wsa.duration :minutes="$hours['measured']" /></x-wsa.stat>
            <x-wsa.stat :label="__('Added')">{{ \App\Support\WorkTime::total($hours['added']) }}</x-wsa.stat>
            @if ($hours['estimated'])
                <x-wsa.stat :label="__('Estimated')" :meta="__('from git, in the total')">~{{ $f($hours['estimated']) }}</x-wsa.stat>
            @endif
            <x-wsa.stat :label="__('Days worked')">{{ $hours['worked_days'] }}</x-wsa.stat>
            @if ($hours['worked_days'])
                <x-wsa.stat :label="__('Per day worked')">{{ \App\Support\WorkTime::format(intdiv($hours['total'], $hours['worked_days'])) }}</x-wsa.stat>
            @endif
            <x-wsa.stat :label="__('People')">{{ $hours['people_count'] }}</x-wsa.stat>
            <x-wsa.stat :label="__('Projects')">{{ $hours['projects_count'] }}</x-wsa.stat>
        </x-wsa.stats>

        {{-- ziua cu ziua: o bară pe zi, măsurat (negru) peste adăugat (gri) și estimat din git (gri deschis); sâmbăta și duminica mai deschise --}}
        <section class="wsa-block">
            <header class="wsa-block-head"><h2>{{ $hours['weekly'] ? __('By week') : __('By day') }}</h2><span>{{ __('measured, added and estimated') }}</span></header>
            <div class="wsa-h-chart" role="img" aria-label="{{ __('Hours by day') }}">
                @foreach ($hours['by_day'] as $d)
                    @php $sum = $d['measured'] + $d['added'] + $d['estimated']; @endphp
                    <div @class(['wsa-h-col', 'is-weekend' => $d['weekend'], 'is-today' => $d['date'] === $today, 'is-future' => $d['date'] > $today, 'is-empty' => $sum === 0])
                         title="{{ ucfirst($d['label']) }} · {{ $sum ? $f($sum) : __('nothing') }}@if ($d['added']) ({{ __('added') }} {{ $f($d['added']) }})@endif @if ($d['estimated']) ({{ __('~:time estimated', ['time' => $f($d['estimated'])]) }})@endif">
                        <div class="wsa-h-bar" style="height: {{ round($sum * 100 / $hours['max_day'], 1) }}%">
                            @if ($d['estimated'])<span class="is-estimated" style="height: {{ round($d['estimated'] * 100 / max(1, $sum), 1) }}%"></span>@endif
                            @if ($d['added'])<span class="is-added" style="height: {{ round($d['added'] * 100 / max(1, $sum), 1) }}%"></span>@endif
                        </div>
                        <span @class(['wsa-h-day', 'is-today-label' => $d['tick'] === '' && $d['date'] === $today])>{{ $d['tick'] !== '' ? $d['tick'] : ($d['date'] === $today && ! $hours['weekly'] ? $d['day'] : '') }}</span>
                    </div>
                @endforeach
            </div>
            <p class="wsa-h-legend wsa-meta"><i class="is-measured"></i>{{ __('Measured') }} <i class="is-added"></i>{{ __('Added') }}@if ($hours['estimated']) <i class="is-estimated"></i>{{ __('Estimated from git') }}@endif · {{ $hours['weekly'] ? __('the busiest week: :time', ['time' => $f($hours['max_day'])]) : __('the busiest day: :time', ['time' => $f($hours['max_day'])]) }}</p>
        </section>

        {{-- pe proiecte, oameni sau clienți --}}
        <nav class="wsa-tabs wsa-h-view" aria-label="{{ __('View') }}">
            @foreach (['projects' => __('Projects'), 'people' => __('People'), 'clients' => __('Clients')] as $key => $name)
                <button type="button" wire:click="show('{{ $key }}')" @class(['is-on' => $tab === $key]) @if ($tab === $key) aria-current="true" @endif>{{ $name }}</button>
            @endforeach
        </nav>

        <div class="wsa-h-list">
            @if ($tab === 'projects')
                @foreach ($hours['projects'] as $row)
                    <details class="wsa-h-row" wire:key="hp-{{ $row['project']->id }}">
                        <summary>
                            <span class="wsa-h-swatch" style="background: {{ $row['project']->client?->color ?? '#a3a3a3' }}"></span>
                            <span class="wsa-h-name"><b>{{ $row['project']->name }}</b><span class="wsa-meta">{{ $row['project']->client?->name ?? __('No client') }} · {{ trans_choice(':count person|:count people', count($row['people'])) }}@if ($row['agent']) · {{ __('the agent worked :time', ['time' => $f($row['agent'])]) }}@endif @if ($row['app']) · {{ __(':time in the app', ['time' => $f($row['app'])]) }}@endif @if ($row['estimated']) · {{ __('~:time estimated', ['time' => $f($row['estimated'])]) }}@endif</span></span>
                            <span class="wsa-h-share"><span style="width: {{ $share($row['total']) }}%"></span></span>
                            <span class="wsa-h-pct">{{ $pct($row['total']) }}</span>
                            <span class="wsa-h-total">{{ $f($row['total']) }}</span>
                        </summary>
                        <div class="wsa-h-sub">
                            @foreach ($row['people'] as $p)
                                <div class="wsa-h-subrow">
                                    <a href="{{ \App\Filament\Resources\Users\UserResource::getUrl('view', ['record' => $p['person']]) }}">{{ $p['person']->name }}</a>
                                    <span class="wsa-h-share is-thin"><span style="width: {{ round($p['total'] * 100 / max(1, $row['total'])) }}%"></span></span>
                                    <span class="wsa-meta">{{ $f($p['measured']) }}@if ($p['added']) + {{ $f($p['added']) }}@endif</span>
                                    <b>{{ $f($p['total']) }}</b>
                                </div>
                            @endforeach
                            <a href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('view', ['record' => $row['project']]) }}" class="wsa-meta">{{ __('Open the project') }}<x-wsa.arrow /></a>
                        </div>
                    </details>
                @endforeach
            @elseif ($tab === 'people')
                @foreach ($hours['people'] as $row)
                    <details class="wsa-h-row" wire:key="hu-{{ $row['person']->id }}">
                        <summary>
                            <span class="wsa-h-initials">{{ $initials($row['person']->name) }}</span>
                            <span class="wsa-h-name"><b>{{ $row['person']->name }}</b><span class="wsa-meta">{{ trans_choice(':count day|:count days', $row['days']) }} · {{ __(':time per day worked', ['time' => $f(intdiv($row['total'], max(1, $row['days'])))]) }}@if ($row['estimated']) · {{ __('~:time estimated', ['time' => $f($row['estimated'])]) }}@endif</span></span>
                            <span class="wsa-h-share"><span style="width: {{ $share($row['total']) }}%"></span></span>
                            <span class="wsa-h-pct">{{ $pct($row['total']) }}</span>
                            <span class="wsa-h-total">{{ $f($row['total']) }}</span>
                        </summary>
                        <div class="wsa-h-sub">
                            {{-- zilele lunii: cât a lucrat în fiecare (mai închis = mai mult) --}}
                            <div class="wsa-h-strip" role="img" aria-label="{{ __('Hours by day') }}">
                                @foreach ($row['strip'] as $d)
                                    <span @class(['is-weekend' => $d['weekend']]) data-level="{{ $d['minutes'] >= 360 ? 4 : ($d['minutes'] >= 240 ? 3 : ($d['minutes'] >= 120 ? 2 : ($d['minutes'] > 0 ? 1 : 0))) }}"
                                          title="{{ ucfirst($d['label']) }} · {{ $d['minutes'] ? $f($d['minutes']) : __('nothing') }}"></span>
                                @endforeach
                            </div>
                            @foreach ($row['projects'] as $p)
                                <div class="wsa-h-subrow">
                                    <a href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('view', ['record' => $p['project']]) }}">{{ $p['project']->name }}</a>
                                    <span class="wsa-h-share is-thin"><span style="width: {{ round($p['total'] * 100 / max(1, $row['total'])) }}%"></span></span>
                                    <span class="wsa-meta">{{ $p['project']->client?->name }}</span>
                                    <b>{{ $f($p['total']) }}</b>
                                </div>
                            @endforeach
                            <a href="{{ \App\Filament\Resources\Users\UserResource::getUrl('view', ['record' => $row['person']]) }}" class="wsa-meta">{{ __('Open their page') }}<x-wsa.arrow /></a>
                        </div>
                    </details>
                @endforeach
            @else
                @foreach ($hours['clients'] as $row)
                    <div class="wsa-h-row is-flat" wire:key="hc-{{ $row['client']?->id ?? 0 }}">
                        <div class="wsa-h-summary">
                            <span class="wsa-h-swatch" style="background: {{ $row['client']?->color ?? '#a3a3a3' }}"></span>
                            <span class="wsa-h-name">
                                @if ($row['client'])<a href="{{ \App\Filament\Resources\Clients\ClientResource::getUrl('view', ['record' => $row['client']]) }}"><b>{{ $row['client']->name }}</b></a>@else<b>{{ __('No client') }}</b>@endif
                                <span class="wsa-meta">{{ trans_choice(':count project|:count projects', $row['projects']) }}</span>
                            </span>
                            <span class="wsa-h-share"><span style="width: {{ $share($row['total']) }}%"></span></span>
                            <span class="wsa-h-pct">{{ $pct($row['total']) }}</span>
                            <span class="wsa-h-total">{{ $f($row['total']) }}</span>
                        </div>
                    </div>
                @endforeach
            @endif
        </div>
    @endif
</x-filament-panels::page>
