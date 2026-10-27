{{-- „Săptămâna mea" (secțiune pe Azi) și, vinerea, „Săptămâna ta" sus (WeekRecap): o privire de ansamblu - timpul față de săptămâna trecută,
     zilele, ce s-a încheiat, pe ce proiecte s-a dus timpul și ce a reușit echipa. Fără lista sarcinilor. --}}
@php
    $f = fn (int $m) => \App\Support\WorkTime::format($m);
    $delta = $week['previous'] > 0 ? (int) round(($week['minutes'] - $week['previous']) * 100 / $week['previous']) : null;
    $count = count($week['projects']);
@endphp
    <section @class(['wsa-block', 'wsa-week', 'is-recap' => $recap])>
        <header class="wsa-block-head">
            <h2>{{ $recap ? __('Your week') : __('My week') }}</h2>
            @if ($recap)
                <x-wsa.close wire:click="hide" :title="__('Close')" />
            @else
                <span class="wsa-meta">{{ __('since Monday') }}</span>
            @endif
        </header>
        @if ($recap)
            <p class="wsa-week-lead">
                @if ($week['minutes'] >= 60)
                    {{ __('You worked :time on :projects', ['time' => $f($week['minutes']), 'projects' => trans_choice(':count project|:count projects', max(1, $count))]) }}@if ($week['done']->isNotEmpty()){{ ' '.trans_choice('and finished :count task.|and finished :count tasks.', $week['done']->count()) }}@else.@endif
                    {{ __('Well done.') }}
                @else
                    {{ trans_choice('You finished :count task this week.|You finished :count tasks this week.', $week['done']->count()) }} {{ __('Well done.') }}
                @endif
            </p>
            <a href="{{ route('story') }}" class="wsa-story-link">{{ __('Watch your week in 30 seconds') }}<x-wsa.arrow /></a>
        @endif
        <div class="wsa-week-figures">
            <div>
                <span class="wsa-label">{{ __('Time worked') }}</span><b>{{ $f($week['minutes']) }}</b>
                {{-- mai mult ca săptămâna trecută (până în aceeași zi): procentul; mai puțin: doar cât a fost, fără un minus descurajant --}}
                @if ($delta !== null && $delta > 0)<span class="wsa-week-delta">{{ __(':delta vs last week', ['delta' => '+'.$delta.'%']) }}</span>
                @elseif ($week['previous'] > 0)<span class="wsa-week-delta">{{ __('last week by now: :time', ['time' => $f($week['previous'])]) }}</span>@endif
            </div>
            <div><span class="wsa-label">{{ __('Days worked') }}</span><b>{{ $week['days'] }}</b></div>
            <div><span class="wsa-label">{{ __('Finished') }}</span><b>{{ $week['done']->count() }}</b></div>
            <div><span class="wsa-label">{{ __('Projects') }}</span><b>{{ $count }}</b></div>
        </div>

        {{-- unde s-a dus timpul: o bandă împărțită pe proiecte, cu numele și timpul dedesubt --}}
        @if ($week['by_project']->isNotEmpty())
            <div class="wsa-week-split">
                <span class="wsa-label">{{ __('Where the time went') }}</span>
                <div class="wsa-week-bar" role="img" aria-label="{{ __('Where the time went') }}">
                    @foreach ($week['by_project'] as $i => $p)
                        <i style="flex-grow: {{ max(1, $p['percent']) }}" data-shade="{{ $i }}" title="{{ $p['project']->name }} · {{ $f($p['minutes']) }}"></i>
                    @endforeach
                    @if ($week['rest'])<i style="flex-grow: {{ max(1, (int) round($week['rest'] * 100 / max(1, $week['by_project']->sum('minutes') + $week['rest']))) }}" data-shade="rest"></i>@endif
                </div>
                <ul class="wsa-week-legend">
                    @foreach ($week['by_project'] as $i => $p)
                        <li><i data-shade="{{ $i }}"></i><a href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('view', ['record' => $p['project']]) }}">{{ $p['project']->name }}</a><span>{{ $f($p['minutes']) }}</span></li>
                    @endforeach
                    @if ($week['rest'])<li><i data-shade="rest"></i><span class="wsa-week-other">{{ __('Other projects') }}</span><span>{{ $f($week['rest']) }}</span></li>@endif
                </ul>
            </div>
        @endif

        {{-- echipa, în aceeași săptămână --}}
        <div class="wsa-week-team">
            <span class="wsa-label">{{ __('The team this week') }}</span>
            <p>
                <b>{{ trans_choice(':count task finished|:count tasks finished', $week['team']['done']) }}</b>
                @if ($week['team']['minutes']) · {{ __(':time of work', ['time' => $f($week['team']['minutes'])]) }}@endif
                · {{ trans_choice(':count update|:count updates', $week['team']['updates']) }}
                · {{ trans_choice(':count person|:count people', $week['team']['people']) }}
            </p>
        </div>
    </section>
