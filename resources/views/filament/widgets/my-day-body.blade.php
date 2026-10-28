{{-- „Ziua mea" (secțiune pe Azi, deasupra „Săptămânii mele"): cele 24 de ore ca bare, colorate pe proiecte, cifrele zilei și reușitele ei.
     Cu $chartOnly (pagina unui om, fila „Pe zile”): doar barele orelor și axa. --}}
@php
    $f = fn (int $m) => \App\Support\WorkTime::format($m);
    $delta = $day['previous'] > 0 ? (int) round(($day['minutes'] - $day['previous']) * 100 / $day['previous']) : null;
@endphp
    <section @class(['wsa-block wsa-week wsa-myhours', 'is-chart-only' => $chartOnly ?? false])>
        @unless ($chartOnly ?? false)
        <header @class(['wsa-block-head', 'wsa-day-head' => $nav ?? false])>
            <h2>{{ $title ?? __('My day') }}</h2>
            @if ($nav ?? false)
                <span class="wsa-meta wsa-day-nav">
                    <button type="button" wire:click="shiftDay(-1)" @disabled(! ($canGoBack ?? true)) aria-label="{{ __('Previous day') }}">{{ svg('heroicon-o-chevron-left', '', ['aria-hidden' => 'true']) }}</button>
                    <span>{{ \Illuminate\Support\Carbon::parse($day['date'])->locale(app()->getLocale())->isoFormat('dddd, D MMMM') }}</span>
                    <button type="button" wire:click="shiftDay(1)" @disabled($isToday ?? true) aria-label="{{ __('Next day') }}">{{ svg('heroicon-o-chevron-right', '', ['aria-hidden' => 'true']) }}</button>
                </span>
                @if ($actions ?? null)<div class="wsa-day-actions">{{ $actions }}</div>@endif
            @else
                <span class="wsa-meta">{{ \Illuminate\Support\Carbon::parse($day['date'])->locale(app()->getLocale())->isoFormat('dddd, D MMMM') }}</span>
            @endif
        </header>

        <div class="wsa-week-figures wsa-day-figures">
            <div>
                <span class="wsa-label">{{ __('Time worked') }}</span><b>{{ $f($day['minutes']) }}</b>
                @if ($delta !== null && $delta > 0)<span class="wsa-week-delta">{{ __(':delta vs yesterday', ['delta' => '+'.$delta.'%']) }}</span>
                @elseif ($day['previous'] > 0)<span class="wsa-week-delta">{{ __('yesterday: :time', ['time' => $f($day['previous'])]) }}</span>@endif
                @if ($day['corrected'] ?? 0)<span class="wsa-week-delta" title="{{ $day['correctionNote'] }}">{{ __('corrected :time', ['time' => ($day['corrected'] < 0 ? '−' : '+').$f(abs($day['corrected']))]) }}</span>@endif
            </div>
            <div><span class="wsa-label">{{ __('From - to') }}</span><b>{{ $day['start'] ? $day['start']->format('H:i').' - '.$day['end']->format('H:i') : '-' }}</b></div>
            <div><span class="wsa-label">{{ __('Longest stretch') }}</span><b>{{ $day['longest'] ? $f($day['longest']) : '-' }}</b></div>
            <div><span class="wsa-label">{{ __('Finished') }}</span><b>{{ $day['done'] }}</b></div>
            @if ($day['commits'])
                <div><span class="wsa-label">{{ __('Commits') }}</span><b>{{ $day['commits'] }}</b><span class="wsa-week-delta">+{{ number_format($day['lines'][0], 0, ',', '.') }} / −{{ number_format($day['lines'][1], 0, ',', '.') }}</span></div>
            @endif
        </div>
        @if ($day['unreported'] ?? false)
            <p class="wsa-day-nudge">{{ __('You have not reported today yet. Tell your agent /upd, or just "write today\'s update".') }}</p>
        @endif
        @endunless

        {{-- cele 24 de ore: înălțimea = minutele lucrate în ora aceea, din 60; culorile = proiectele, ca banda săptămânii --}}
        {{-- pe card: orele trase cu mouse-ul (sau atinse) deschid „Corectează timpul", completat cu ce s-a măsurat în ele --}}
        <div class="wsa-day-chart" role="img" aria-label="{{ __('Time worked by hour') }}"
             @if ($canSelect ?? false)
                 wire:key="my-day-chart-{{ $day['date'] }}-{{ $day['now'] }}"
                 x-data="{ a: null, b: null, on: false,
                     move(e) { if (! this.on) return; const el = document.elementFromPoint(e.clientX, e.clientY)?.closest('[data-hour]'); if (el) this.b = Math.min(+el.dataset.hour, {{ $day['now'] }}) },
                     start(h) { this.on = true; this.a = this.b = h },
                     end() { if (! this.on) return; this.on = false; $wire.selectHours(this.a, this.b); setTimeout(() => this.a = this.b = null, 400) },
                     sel(h) { return this.a !== null && h >= Math.min(this.a, this.b) && h <= Math.max(this.a, this.b) } }"
                 x-on:pointermove="move($event)" x-on:pointerup.window="end()"
                 data-selectable title="{{ __('Drag over the hours to correct them') }}"
             @endif>
            @foreach ($day['hours'] as $h)
                <div @class(['wsa-day-hour', 'is-now' => $h['hour'] === $day['now'], 'is-future' => $h['hour'] > $day['now']])
                     @if (($canSelect ?? false) && $h['hour'] <= $day['now']) data-hour="{{ $h['hour'] }}" x-on:pointerdown.prevent="start({{ $h['hour'] }})" x-bind:class="{ 'is-picked': sel({{ $h['hour'] }}) }" @endif
                     title="{{ sprintf('%02d:00-%02d:00', $h['hour'], ($h['hour'] + 1) % 24) }}{{ $h['minutes'] ? ' · '.$f($h['minutes']).collect($h['parts'])->map(fn ($p) => ' · '.$p['name'].' '.$f($p['minutes']))->join('') : '' }}{{ ($h['agents'] ?? []) ? ' · '.implode(', ', $h['agents']) : '' }}">
                    <div class="wsa-day-stack" style="height: {{ $h['minutes'] ? max(4, round($h['minutes'] * 100 / 60)) : 0 }}%">
                        @foreach (array_reverse($h['parts']) as $p)
                            <i data-shade="{{ $p['shade'] }}" style="flex-grow: {{ max(1, $p['minutes']) }}"></i>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
        <div class="wsa-day-axis" aria-hidden="true"><span>0</span><span>6</span><span>12</span><span>18</span><span>24</span></div>

        @if (($day['legend'] || ($day['added'] ?? 0) || ($day['corrected'] ?? 0)) && ! ($chartOnly ?? false))
            <ul class="wsa-week-legend wsa-day-legend">
                @foreach ($day['legend'] as $p)
                    <li><i data-shade="{{ $p['shade'] }}"></i><a href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('view', ['record' => $p['project']]) }}">{{ $p['project']->name }}</a><span>{{ $f($p['minutes']) }}</span></li>
                @endforeach
                @if ($day['rest'])<li><i data-shade="rest"></i><span class="wsa-week-other">{{ __('Other projects, or none') }}</span><span>{{ $f($day['rest']) }}</span></li>@endif
                @if ($day['removed'] ?? 0)<li title="{{ $day['correctionNote'] }}"><i data-shade="removed"></i><span class="wsa-week-other">{{ __('Removed by a correction') }}</span><span>−{{ $f($day['removed']) }}</span></li>@endif
                @if ($day['added'] ?? 0)<li><i data-shade="added"></i><span class="wsa-week-other">{{ $day['addedApprox'] ? __('Added by hand · time approximate') : __('Added by hand') }}</span><span>{{ $f($day['added']) }}</span></li>@endif
            </ul>
        @endif

        @if ($day['wins']->isNotEmpty() && ! ($chartOnly ?? false))
            <div class="wsa-week-split wsa-day-wins">
                <span class="wsa-label">{{ ($title ?? null) ? __('Done that day') : __('Done today') }}</span>
                <ul class="wsa-week-done">
                    @foreach ($day['wins'] as $win)
                        <li>
                            {{ svg('heroicon-o-check', '', ['aria-hidden' => 'true']) }}
                            @if ($win['task'])
                                <a href="{{ \App\Filament\Resources\Tasks\TaskResource::getUrl('view', ['record' => $win['task']]) }}">{{ $win['text'] }}</a>
                            @else
                                <span>{{ \App\Support\Markdown::inline($win['text']) }}</span>
                            @endif
                            <span class="wsa-meta">{{ $win['meta'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </section>
