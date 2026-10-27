{{-- Ajută badge-urile? Înainte și după, pe aceleași cifre; ce s-a câștigat, pe categorii; câți le-au ascuns sau le arată echipei. --}}
<x-filament-panels::page>
    @if (! $report)
        <p class="wsa-meta">{{ __('No badge earned yet: the comparison starts with the first one.') }}</p>
    @else
        @php
            $show = fn ($v, string $format) => $v === null ? '-' : match ($format) {
                'percent' => round($v * 100).'%', 'decimal' => number_format($v, 2, ',', ''), default => (string) $v,
            };
        @endphp
        <section class="wsa-block">
            <header class="wsa-block-head">
                <h2>{{ __('Before and after') }}</h2>
                <span>{{ __('since :date · :days days after', ['date' => $report['since']->copy()->setTimezone(auth()->user()->tz())->locale(app()->getLocale())->isoFormat('D MMM YYYY'), 'days' => $report['days']]) }}</span>
            </header>
            @if ($report['days'] < 14)
                <p class="wsa-meta">{{ __('Too early to tell: give it at least two weeks.') }}</p>
            @endif
            <table class="wsa-effect">
                <thead><tr><th></th><th>{{ __('The month before') }}</th><th>{{ __('Since') }}</th></tr></thead>
                <tbody>
                    @foreach ($report['rows'] as $row)
                        <tr><th>{{ $row['label'] }}</th><td>{{ $show($row['before'], $row['format']) }}</td><td><b>{{ $show($row['after'], $row['format']) }}</b></td></tr>
                    @endforeach
                </tbody>
            </table>
        </section>
        <section class="wsa-block">
            <header class="wsa-block-head"><h2>{{ __('Earned') }}</h2><span>{{ __('from the start, without the ones from history') }}</span></header>
            <div class="wsa-effect-cols">
                <dl>@foreach ($report['earned'] as $name => $n)<div><dt>{{ $name }}</dt><dd>{{ $n }}</dd></div>@endforeach</dl>
                <dl>@foreach ($report['top'] as $name => $n)<div><dt>{{ $name }}</dt><dd>{{ $n }}</dd></div>@endforeach</dl>
            </div>
            <p class="wsa-meta">{{ __(':shared of :team show their badges to the team; :hidden turned them off.', ['shared' => $report['shared'], 'team' => $report['team'], 'hidden' => $report['hidden']]) }}</p>
        </section>
    @endif
</x-filament-panels::page>
