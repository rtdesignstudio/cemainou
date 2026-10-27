{{-- Erorile serverului: pe zile, cele mai noi întâi; în fiecare zi, grupurile (mesajul, de câte ori, unde în cod, pe ce pagină, ultima dată). --}}
<x-filament-panels::page>
    @forelse ($days as $date => $groups)
        <section class="wsa-block">
            <header class="wsa-block-head">
                <h2>{{ \Illuminate\Support\Str::ucfirst(\Illuminate\Support\Carbon::parse($date)->locale(app()->getLocale())->isoFormat('dddd, D MMMM')) }}</h2>
                <span>{{ trans_choice(':count error|:count errors', array_sum(array_column($groups, 'count'))) }}</span>
            </header>
            @foreach ($groups as $g)
                <div class="wsa-row wsa-error">
                    <span class="wsa-error-count">×{{ $g['count'] }}</span>
                    <span class="wsa-row-title">{{ $g['message'] }}</span>
                    <span class="wsa-row-meta">{{ collect([$g['where'], implode(', ', $g['pages']), __('last :time', ['time' => $g['last']->copy()->setTimezone(auth()->user()->tz())->format('H:i')])])->filter()->join(' · ') }}</span>
                </div>
            @endforeach
        </section>
    @empty
        <p class="wsa-meta">{{ __('No errors in the last :days days.', ['days' => \App\Support\ErrorLog::DAYS]) }}</p>
    @endforelse
</x-filament-panels::page>
