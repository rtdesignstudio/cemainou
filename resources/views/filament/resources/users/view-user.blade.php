<x-filament-panels::page>
    {{--
        Pagina unui om: starea uneltelor (adminul și omul), perioada (săptămâna, luna, 30 / 90 de zile sau un interval), cifrele ei (fiecare duce la fila ei),
        o bară pe zi, apoi filele: Pe zile (ziua aleasă: timpul în stânga, ce s-a făcut în dreapta), Update-uri, Sarcini, Proiecte și Timp (cine are voie).
    --}}
    @php
        $fmt = fn (string $date, string $format) => \Illuminate\Support\Carbon::parse($date)->locale(app()->getLocale())->isoFormat($format);
        $windowLabel = $fmt($period[0], substr($period[0], 0, 4) !== substr(min($period[1], $bars['to']), 0, 4) ? 'D MMM YYYY' : 'D MMM').' - '.$fmt(min($period[1], $bars['to']), 'D MMM YYYY');
    @endphp
    <div class="wsa-pp">
        @if ($setup)
            <x-wsa.setup :items="$setup" class="wsa-pp-setup" />
        @endif
        {{-- perioada: comandă cifrele, barele și filele --}}
        <nav class="wsa-tabs wsa-pp-ranges" aria-label="{{ __('Period') }}">
            @foreach (['week' => __('This week'), 'month' => __('This month'), '30' => __('Last 30 days'), '90' => __('Last 90 days'), '180' => __('6 months'), '365' => __('A year'), 'custom' => __('Chosen range')] as $key => $name)
                <button type="button" wire:click="pick('{{ $key }}')" @class(['is-on' => $this->range === (string) $key])>{{ $name }}</button>
            @endforeach
        </nav>
        <div class="wsa-toolbar">
            @if ($this->range === 'custom')
                <div class="wsa-period">
                    <label class="wsa-h-date"><span class="wsa-label">{{ __('From') }}</span><input type="date" wire:model.live.debounce.500ms="from" max="{{ now($this->record->tz())->toDateString() }}"></label>
                    <label class="wsa-h-date"><span class="wsa-label">{{ __('To') }}</span><input type="date" wire:model.live.debounce.500ms="to" max="{{ now($this->record->tz())->toDateString() }}"></label>
                </div>
            @else
                <x-wsa.period-nav :label="$windowLabel" previous="older" :previous-label="__('Earlier')" :next="$isLatest ? null : 'newer'" :next-label="__('Later')" />
            @endif
        </div>
        {{-- cifrele perioadei: fiecare duce la fila ei; sub ele, comparația cu perioada dinainte (aceeași lungime) --}}
        @php
            $versusLine = function (int $now, int $before, bool $timeKey = false) use ($versus) {
                if ($before === 0) {
                    return null;
                }
                $d = (int) round(($now - $before) * 100 / $before);
                if (abs($d) > 200) {                                        // de la aproape nimic: procentul nu spune nimic, cifra de atunci da
                    return __('the period before: :value', ['value' => $timeKey ? \App\Support\WorkTime::format($before) : $before]);
                }

                return ($d >= 0 ? '+'.$d : '−'.abs($d)).'% '.$versus;
            };
            [$cNow, $cBefore] = [$compare['now'], $compare['before']];
        @endphp
        <x-wsa.stats>
            <x-wsa.stat :label="__('Days with activity')" :href="$this->tabUrl('days')" :meta="$cNow['workdays'] ? __('of :total workdays', ['total' => $cNow['workdays']]) : null">{{ $totals['days'] }}</x-wsa.stat>
            @if ($withTime)
                <x-wsa.stat :label="__('Time worked')" :href="$this->tabUrl('time')" :meta="collect([$versusLine($cNow['minutes'], $cBefore['minutes'], true), $totals['estimated'] ? __('of it ~:time estimated from git', ['time' => \App\Support\WorkTime::format($totals['estimated'])]) : null])->filter()->join(' · ') ?: null">@if ($totals['minutes'])<x-wsa.duration :minutes="$totals['minutes']" />@else 0m @endif</x-wsa.stat>
            @endif
            <x-wsa.stat :label="__('Updates')" :href="$this->tabUrl('updates')" :meta="$versusLine($cNow['updates'], $cBefore['updates'])">{{ $totals['updates'] }}</x-wsa.stat>
            <x-wsa.stat :label="__('Tasks done')" :href="$this->tabUrl('tasks', 'done')" :meta="$versusLine($cNow['done'], $cBefore['done'])">{{ $totals['done'] }}</x-wsa.stat>
            <x-wsa.stat :label="__('Tasks created')" :href="$this->tabUrl('tasks', 'created')" :meta="$totals['given'] ? trans_choice(':count given to others|:count given to others', $totals['given']) : null">{{ $totals['created'] }}</x-wsa.stat>
            <x-wsa.stat :label="__('Projects')" :href="$this->tabUrl('projects')">{{ $totals['projects'] }}</x-wsa.stat>
        </x-wsa.stats>
        <livewire:badge-collection :user-id="$this->record->id" :key="'badges-'.$this->record->id" />
        {{-- o bară pe zi: zilele sub bare (puține zile) sau lunile; ziua aleasă are valoarea deasupra; linia punctată = media zilelor cu ceva --}}
        @php($dense = count($bars['days']) > 31)
        <div class="wsa-p-chart wsa-pp-chart" x-data x-init="const s = $el.querySelector('.is-selected'); $el.scrollLeft = s ? s.offsetLeft - $el.clientWidth / 2 : $el.scrollWidth">
            <div @class(['wsa-h-chart', 'is-dense' => $dense, 'has-selected' => $day !== '']) style="--n: {{ count($bars['days']) }}" role="group" aria-label="{{ __('Day by day') }}">
                @if ($bars['averageAt'] !== null)
                    <div class="wsa-pp-avg" style="--at: {{ $bars['averageAt'] / 100 }}" aria-hidden="true"><span>{{ __('average :value', ['value' => $bars['averageLabel']]) }}</span></div>
                @endif
                @foreach ($bars['days'] as $d)
                    <button type="button" wire:click="selectDay('{{ $d['date'] }}'); $set('tab', 'days')" title="{{ $d['label'] }}" aria-label="{{ $d['label'] }}"
                        @class(['wsa-h-col', 'is-weekend' => $d['weekend'], 'is-empty' => ! $d['active'], 'is-selected' => $d['date'] === $day])>
                        <span class="wsa-h-bar" style="height: {{ $d['height'] }}%">@if (($d['hatched'] ?? 0) > 0 && $d['value'] > 0)<span class="is-estimated" style="height: {{ round($d['hatched'] * 100 / $d['value'], 1) }}%"></span>@endif
                            @if ($d['date'] === $day && $d['active'])<span class="wsa-h-value">{{ $d['short'] }}</span>@endif</span>
                        <span class="wsa-h-day">{{ $d['tick'] }}</span>
                    </button>
                @endforeach
            </div>
        </div>

        <nav class="wsa-tabs wsa-pp-tabs" aria-label="{{ __('Show') }}">
            @foreach (array_filter(['days' => __('By day'), 'updates' => __('Updates'), 'tasks' => __('Tasks'), 'projects' => __('Projects'), 'time' => $withTime ? __('Time') : null]) as $key => $name)
                <button type="button" wire:click="$set('tab', '{{ $key }}')" @class(['is-on' => $this->tab === $key])>{{ $name }}</button>
            @endforeach
        </nav>

        @if ($this->tab !== 'days')
            @include('filament.resources.users.person-tab', ['tab' => $this->tab, 'data' => $tabData, 'withTime' => $withTime, 'format' => $format])
        @else
        <section class="wsa-pp-day">
            <header class="wsa-pp-day-head">
                <h2 class="wsa-first-cap">{{ $detail['label'] }}</h2>
                <span class="wsa-pp-nav">
                    <button type="button" class="wsa-inline-btn" wire:click="shiftDay(-1)" wire:loading.attr="disabled"><x-wsa.arrow to="left" />{{ __('Previous day') }}</button>
                    @unless ($isToday)
                        <button type="button" class="wsa-inline-btn" wire:click="shiftDay(1)" wire:loading.attr="disabled">{{ __('Next day') }}<x-wsa.arrow /></button>
                    @endunless
                </span>
            </header>
            @php($time = $withTime && $detail['active'])
            @if ($time || $detail['code'])
                <dl class="wsa-pp-daystats">
                    @if ($time)
                        <div><dt class="wsa-label">{{ __('From - to') }}</dt><dd>{{ $detail['start'] }} - {{ $detail['end'] }}</dd></div>
                        <div><dt class="wsa-label">{{ __('Active') }}</dt><dd>{{ $detail['active'] }}</dd></div>
                        @if ($detail['agent'])<div><dt class="wsa-label">{{ __('Claude worked') }}</dt><dd>{{ $detail['agent'] }}</dd></div>@endif
                    @endif
                    @if ($detail['code'])<div><dt class="wsa-label">{{ __('Code') }}</dt><dd class="is-small">{{ $detail['code'] }}</dd></div>@endif
                </dl>
            @endif

            {{-- ziua aleasă, pe ore (barele cardului „Ziua mea" de pe Azi), cu legenda dedesubt: proiectele în culorile barelor, cu timpul și corectarea --}}
            @if ($myDay)
                <div class="wsa-pp-myday" wire:key="myday-{{ $day }}">
                    @include('filament.widgets.my-day-body', ['day' => $myDay, 'chartOnly' => true])
                </div>
            @endif

            @php($shades = collect($myDay['legend'] ?? [])->mapWithKeys(fn ($l) => [$l['project']->id => $l['shade']]))
            @if ($withTime && ($detail['projects']->isNotEmpty() || $detail['manual']->isNotEmpty() || $detail['corrections']->isNotEmpty()))
                <ul class="wsa-pp-legend">
                    @foreach ($detail['projects'] as $row)
                        <li>
                            <i data-shade="{{ $shades[$row['project']->id] ?? 'rest' }}" aria-hidden="true"></i>
                            <a href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('view', ['record' => $row['project']]) }}">{{ $row['project']->name }}</a>
                            <span class="wsa-meta">@isset($row['measured'])<span title="{{ __('Measured') }}">{{ __('measured :time', ['time' => $format($row['measured'])]) }}</span> · @endisset<b>{{ $format($row['minutes']) }}</b>@can('manageWorkTime', $this->record) · <button type="button" class="wsa-inline-btn" wire:click="mountAction('correctTime', {{ json_encode(['project' => $row['project']->id]) }})">{{ __('Correct') }}</button>@endcan</span>
                        </li>
                    @endforeach
                    @foreach ($detail['corrections'] as $entry)
                        <li class="is-note">
                            <i aria-hidden="true"></i>
                            <span>{{ __('Corrected') }}: {{ $entry->note }} <span class="wsa-meta">· {{ $entry->project?->name }}@if ($entry->corrector && $entry->corrector->id !== $this->record->id) · {{ $entry->corrector->name }}@endif</span></span>
                            <span class="wsa-meta">{{ $entry->minutes > 0 ? '+' : '−' }}{{ $format(abs($entry->minutes)) }}@can('manageWorkTime', $this->record) · <button type="button" class="wsa-inline-btn" wire:click="removeCorrection({{ $entry->id }})" wire:confirm="{{ __('Undo this correction?') }}">{{ __('Undo the correction') }}</button>@endcan</span>
                        </li>
                    @endforeach
                    @foreach ($detail['manual'] as $entry)
                        <li class="is-note">
                            <i class="is-hatched" aria-hidden="true"></i>
                            <span>{{ __('Added time') }}: {{ $entry->note ?: ($entry->category?->getLabel() ?? __('Time')) }} <span class="wsa-meta">· {{ $entry->project?->name }} · {{ $entry->start_minute !== null ? __('from :time', ['time' => sprintf('%02d:%02d', intdiv($entry->start_minute, 60), $entry->start_minute % 60)]) : __('time approximate on the bars') }}</span></span>
                            <span class="wsa-meta">{{ $format($entry->minutes) }}@can('manageWorkTime', $this->record) · <button type="button" class="wsa-inline-btn" wire:click="removeTime({{ $entry->id }})" wire:confirm="{{ __('Delete this time?') }}">{{ __('Delete') }}</button>@endcan</span>
                        </li>
                    @endforeach
                </ul>
            @endif

            {{-- ziua ca jurnal: tot ce a făcut, într-un singur fir, după oră --}}
            @php($journal = \App\Support\PersonActivity::journal($detail))
            @php($at = fn ($t) => $t->copy()->setTimezone($this->record->tz())->format('H:i'))
            @if ($journal->isNotEmpty())
                <div class="wsa-group wsa-pp-journal-head">{{ __('What they did, hour by hour') }}</div>
                <ol class="wsa-pp-journal">
                    @foreach ($journal as $row)
                        @php($item = $row['item'])
                        <li class="is-{{ $row['kind'] }}">
                            <time>{{ $at($row['at']) }}</time>
                            <div>
                                @switch($row['kind'])
                                    @case('update')
                                        <span class="wsa-label">{{ __('Update') }}</span>
                                        <a href="{{ \App\Filament\Resources\Updates\UpdateResource::getUrl('view', ['record' => $item]) }}" class="wsa-pp-j-title">{{ \App\Support\Markdown::inline($item->summary, links: false) }}</a>
                                        <span class="wsa-meta">{{ $item->project?->name ?? __('No project') }}</span>
                                        @php($done = $item->items->where('type', \App\Enums\UpdateItemType::Done))
                                        @if ($done->isNotEmpty())
                                            <ul class="wsa-feed-lines">
                                                @foreach ($done as $line)<li><span class="wsa-mark is-done" aria-hidden="true"></span><span>{{ \App\Support\Markdown::inline($line->text) }}</span></li>@endforeach
                                            </ul>
                                        @endif
                                        @break
                                    @case('task')
                                        <span class="wsa-label">{{ __('Task') }}</span>
                                        <a href="{{ \App\Filament\Resources\Tasks\TaskResource::getUrl('view', ['record' => $item->task]) }}" class="wsa-pp-j-title"><span class="wsa-id">#{{ $item->task->id }}</span> {{ $item->task->title }}</a>
                                        <span class="wsa-meta">{{ ucfirst(collect($row['events'])->map(fn ($event) => $event->describe($this->record))->join(' · ')) }}@if ($item->task->project) · {{ $item->task->project->name }}@endif</span>
                                        @break
                                    @case('document')
                                        <span class="wsa-label">{{ __('Document') }}</span>
                                        <a href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('document', ['record' => $item->document->project_id, 'document' => $item->document_id, 'v' => $item->version]) }}" class="wsa-pp-j-title">{{ $item->title }}</a>
                                        <span class="wsa-meta">{{ $item->version === 1 ? __('created') : __('version :version', ['version' => $item->version]) }} · {{ $item->document->project?->name }}@if ($item->note) · {{ $item->note }}@endif</span>
                                        @break
                                    @case('note')
                                        <span class="wsa-label">{{ __('Note for the team') }}</span>
                                        <div class="wsa-prose is-compact">{{ \App\Support\Markdown::html($item->body, breaks: true) }}</div>
                                        <span class="wsa-meta">{{ $item->project?->name }}</span>
                                        @break
                                @endswitch
                            </div>
                        </li>
                    @endforeach
                </ol>
            @elseif (! ($withTime && $detail['active']))
                <p class="wsa-empty wsa-pp-empty">{{ $isToday ? __('Nothing recorded yet today.') : __('Nothing recorded on this day.') }}</p>
            @endif
        </section>
        @endif
    </div>
</x-filament-panels::page>
