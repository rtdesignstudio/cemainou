@props(['c', 'periodLabel', 'from', 'to'])
{{--
    Un card de proiect (App\Support\ProjectPortfolio), același pe Proiecte, pe Azi și pe pagina clientului: starea, timpul investit
    (total, perioada, 7 zile) cu graficul perioadei, cine a pus timpul, sarcinile, blocajul, accesul, ultimul update și ce urmează.
--}}
@php
    use App\Filament\Resources\Clients\ClientResource;
    use App\Filament\Resources\Projects\ProjectResource;
    use App\Filament\Resources\Tasks\TaskResource;
    use App\Filament\Resources\Updates\UpdateResource;
    use App\Filament\Resources\Users\UserResource;
    use App\Support\Markdown;
    use App\Support\WorkTime;

    $project = $c['project'];
    $isClient = auth()->user()->isClient();
    $initials = fn (string $name) => mb_strtoupper(collect(preg_split('/\s+/u', trim($name)))->filter()->take(2)->map(fn ($word) => mb_substr($word, 0, 1))->join(''));
    $tasksUrl = fn ($project) => TaskResource::getUrl('index', ['tab' => 'open', 'project' => $project->id]);
@endphp
<article class="wsa-pcard" wire:key="pcard-{{ $project->id }}">
    <header class="wsa-pcard-head">
        <div>
            <h2><a href="{{ ProjectResource::getUrl('view', ['record' => $project]) }}">{{ $project->name }}</a></h2>
            <p class="wsa-pcard-sub">
                @if ($project->client)
                    @if ($isClient){{ $project->client->name }}@else<a href="{{ ClientResource::getUrl('view', ['record' => $project->client]) }}">{{ $project->client->name }}</a>@endif
                @else{{ __('Internal') }}@endif
                @if ($project->lead) · {{ __('led by :name', ['name' => $project->lead->name]) }}@endif
            </p>
            @if (filled($project->categories))
                <div class="wsa-tags">@foreach ($project->categories as $category)<span class="wsa-tag is-soft">{{ $category->getLabel() }}</span>@endforeach</div>
            @endif
        </div>
        {{-- în dreapta sus: starea („În grafic", „Blocat") și demo-ul; eticheta „Acum" rămâne pe chenar, la stânga --}}
        <div class="wsa-pcard-side">
            <x-wsa.health :health="$c['health']" />
        @if ($c['demo'] ?? null)
            <a href="{{ $c['demo']['url'] }}" target="_blank" rel="noopener noreferrer" class="wsa-pcard-demo" data-tip="{{ $c['demo']['title'] }}">{{ __('Demo') }}<x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedArrowTopRightOnSquare" class="wsa-pcard-demo-icon" /></a>
        @endif
        @if ($c['people']->contains('now', true))
            <span class="wsa-presence is-now" tabindex="0" data-tip="{{ __('Working now: :names', ['names' => $c['people']->where('now', true)->map(fn ($p) => $p['user']->name)->join(', ')]) }}">{{ __('Now') }}</span>
        @elseif ($c['last_at'])
            <span class="wsa-presence" tabindex="0" data-tip="{{ __('Last activity on the project :time', ['time' => $c['last_at']->diffForHumans()]) }} · {{ \App\Support\Ago::exact($c['last_at']) }}">{{ \App\Support\Ago::short($c['last_at']) }}</span>
        @endif
        </div>
    </header>

    {{-- timpul: totalul mare, cu de când rulează; luna și ultimele 7 zile alături --}}
    @if ($h = $c['hours'])
        <div class="wsa-pcard-time">
            <div class="is-total">
                <span class="wsa-label">{{ __('Time invested') }}</span>
                <strong><x-wsa.duration :minutes="$h['total']" /></strong>
                <span class="wsa-pcard-since">{{ __('since :date', ['date' => $c['began']->locale(app()->getLocale())->isoFormat('D MMM YYYY')]) }}@if ($c['days_worked']) · {{ trans_choice(':count day of work|:count days of work', $c['days_worked']) }}@endif@if ($h['estimated']) · {{ __('~:time estimated from git', ['time' => \App\Support\WorkTime::format($h['estimated'])]) }}@endif</span>
            </div>
            <div><span class="wsa-label">{{ $periodLabel }}</span><strong><x-wsa.duration :minutes="$h['period']" /></strong></div>
            <div><span class="wsa-label">{{ __('Last 7 days') }}</span><strong><x-wsa.duration :minutes="$h['week']" /></strong></div>
        </div>
        <div>
            <div class="wsa-pcard-weeks" style="--bars: {{ count($h['weeks']) }}" role="img" aria-label="{{ __('Time in the period') }}">
                @foreach ($h['weeks'] as $w)
                    <span title="{{ $w['label'] }}"><i style="height: {{ $w['height'] }}%" @class(['is-empty' => ! $w['minutes']])></i></span>
                @endforeach
            </div>
            @php([$pf, $pt] = [$from, $to])
            <div class="wsa-pcard-scale"><span>{{ \Illuminate\Support\Carbon::parse($pf)->locale(app()->getLocale())->isoFormat('D MMM') }}</span><span>{{ $periodLabel }}</span><span>{{ \Illuminate\Support\Carbon::parse($pt)->locale(app()->getLocale())->isoFormat('D MMM') }}</span></div>
        </div>
    @else
        <div class="wsa-rhythm-wrap">
            <div class="wsa-rhythm" role="img" aria-label="{{ __('The last 14 days') }}">
                @foreach ($c['rhythm'] as $day)<i data-level="{{ $day['level'] }}" title="{{ $day['label'] }}"></i>@endforeach
            </div>
            <p class="wsa-pcard-since">{{ __('since :date', ['date' => $c['began']->locale(app()->getLocale())->isoFormat('D MMM YYYY')]) }}</p>
        </div>
    @endif

    {{-- două coloane: cine a pus timpul și sarcinile, fiecare cu bara ei (aceeași înălțime) --}}
    <div class="wsa-pcard-pair">
        @if ($c['hours'] && $c['hours']['share']->isNotEmpty())
            <div class="wsa-pcard-col">
                <span class="wsa-label">{{ __('Who put in the time') }} · {{ mb_strtolower($periodLabel) }}</span>
                <div class="wsa-pcard-sharebar" role="img" aria-label="{{ __('Who put in the time') }}">
                    @foreach ($c['hours']['share']->take(6) as $i => $p)
                        <i style="flex: {{ max(1, $p['percent']) }}" data-tone="{{ $i % 4 }}" title="{{ $p['user']->name }} · {{ WorkTime::format($p['minutes']) }} · {{ $p['percent'] }}%"></i>
                    @endforeach
                </div>
                <div class="wsa-pcard-people">
                    @foreach ($c['hours']['share']->take(6) as $i => $p)
                        <a href="{{ UserResource::getUrl('view', ['record' => $p['user']]) }}" title="{{ $p['user']->name }} · {{ WorkTime::format($p['minutes']) }}">
                            <i data-tone="{{ $i % 4 }}" aria-hidden="true"></i>{{ $initials($p['user']->name) }}<span>{{ $p['percent'] }}%</span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
        <div class="wsa-pcard-col">
            <span class="wsa-label">{{ $c['progress'] ? trans_choice(':done of :total task done|:done of :total tasks done', $c['progress']['total'], ['done' => $c['progress']['done'], 'total' => $c['progress']['total']]) : __('Tasks') }}</span>
            <div class="wsa-progress-bar" role="progressbar" aria-valuenow="{{ $c['progress']['percent'] ?? 0 }}" aria-valuemin="0" aria-valuemax="100"><span style="width: {{ $c['progress']['percent'] ?? 0 }}%"></span></div>
            <p class="wsa-pcard-counts">
                <a href="{{ $tasksUrl($project) }}">{{ trans_choice(':count open task|:count open tasks', $c['open']) }}</a>
                · <span @class(['is-blocked' => $c['blocked'] > 0])>{{ trans_choice(':count blocked task|:count blocked tasks', $c['blocked']) }}</span>
                @if ($c['ideas']) · {{ trans_choice(':count idea|:count ideas', $c['ideas']) }}@endif
                @if ($c['documents']) · {{ trans_choice(':count document|:count documents', $c['documents']) }}@endif
            </p>
        </div>
    </div>
    @if ($b = $c['blocker'])
        <p class="wsa-project-blocker"><i aria-hidden="true"></i>
            <a href="{{ isset($b['task']) ? TaskResource::getUrl('view', ['record' => $b['task']]) : UpdateResource::getUrl('view', ['record' => $b['update']]) }}">{{ Markdown::inline($b['text'], links: false) }}</a></p>
    @endif

    {{-- cine are acces: toată echipa (+ colaboratorii) sau doar oamenii aleși; plus oamenii clientului, dacă vede proiectul --}}
    @if ($a = $c['access'])
        <div class="wsa-pcard-access">
            <span class="wsa-label">{{ __('Access') }} · {{ $a['restricted'] ? __('only chosen people') : __('the whole team') }}@if ($a['restricted']) <span class="wsa-pcard-lock" title="{{ __('Admins and the project\'s lead always see it.') }}">{{ __('and admins') }}</span>@endif</span>
            @if ($a['people']->isNotEmpty() || $a['client']->isNotEmpty())
                <div class="wsa-initials">
                    @foreach ($a['people']->take(10) as $person)
                        <a href="{{ UserResource::getUrl('view', ['record' => $person]) }}" class="wsa-initial" title="{{ $person->name }} · {{ $person->role->getLabel() }}{{ $person->id === $project->lead_id ? ' · '.__('Lead') : '' }}">{{ $initials($person->name) }}</a>
                    @endforeach
                    @if ($a['people']->count() > 10)<span class="wsa-initial is-more" title="{{ $a['people']->slice(10)->pluck('name')->join(', ') }}">+{{ $a['people']->count() - 10 }}</span>@endif
                    @foreach ($a['client'] as $person)
                        <span class="wsa-initial is-client" title="{{ $person->name }} · {{ __('Client') }}">{{ $initials($person->name) }}</span>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    <div class="wsa-pcard-last">
        @if ($u = $c['last'])
            <div class="wsa-label">{{ __('Last update') }} · {{ $u->user?->name }} · <x-wsa.time :at="$u->created_at" /></div>
            <p><a href="{{ UpdateResource::getUrl('view', ['record' => $u]) }}">{{ Markdown::inline($u->summary, links: false) }}</a></p>
            @if ($c['next'])
                <div class="wsa-label">{{ __('Next') }}</div>
                <p class="is-next">{{ Markdown::inline($c['next'], links: false) }}</p>
            @endif
        @else
            <div class="wsa-label">{{ __('Last update') }}</div>
            <p class="is-empty">{{ __('No updates yet') }}</p>
        @endif
    </div>

    <footer class="wsa-pcard-foot">
        <a href="{{ ProjectResource::getUrl('view', ['record' => $project]) }}">{{ __('Open the project') }}<x-wsa.arrow /></a>
        @unless ($isClient)
            <a href="{{ TaskResource::getUrl('create', ['project' => $project->id]) }}">{{ __('New task') }}</a>
        @endunless
    </footer>
</article>
