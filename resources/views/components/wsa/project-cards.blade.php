@props(['cards', 'since', 'client' => false])
{{-- Grila cardurilor de proiect (App\Support\ProjectCards): pe „Azi" și pe pagina unui client. Fiecare parte a cardului duce la pagina ei. --}}
@php
    use App\Filament\Resources\Clients\ClientResource;
    use App\Filament\Resources\Projects\ProjectResource;
    use App\Filament\Resources\Tasks\TaskResource;
    use App\Filament\Resources\Updates\UpdateResource;
    use App\Filament\Resources\Users\UserResource;
    use App\Support\Markdown;
    use App\Support\WorkTime;

    $initials = fn (string $name) => mb_strtoupper(collect(preg_split('/\s+/u', trim($name)))->filter()->take(2)->map(fn ($word) => mb_substr($word, 0, 1))->join(''));
    $tasksUrl = fn ($project) => TaskResource::getUrl('index', ['tab' => 'open', 'project' => $project->id]);
@endphp
<div class="wsa-team wsa-project-grid">
    @foreach ($cards as $c)
        @php($project = $c['project'])
        <article class="wsa-person wsa-project">
            @if ($c['people']->contains('now', true))
                <span class="wsa-presence is-now" title="{{ __('Working now: :names', ['names' => $c['people']->where('now', true)->map(fn ($p) => $p['user']->name)->join(', ')]) }}">{{ __('Now') }}</span>
            @elseif ($c['last_at'])
                <span class="wsa-presence" title="{{ __('Last activity :time', ['time' => $c['last_at']->diffForHumans()]) }} · {{ \App\Support\Ago::exact($c['last_at']) }}">{{ \App\Support\Ago::short($c['last_at']) }}</span>
            @endif
            <div class="wsa-person-top">
                <div>
                    <a href="{{ ProjectResource::getUrl('view', ['record' => $project]) }}" class="wsa-name">{{ $project->name }}</a>
                    <x-wsa.health :health="$c['health']" class="wsa-health-card" />
                    @if ($project->client || filled($project->categories))
                        <div class="wsa-tags">
                            @if ($project->client)
                                    @if ($client)<span class="wsa-tag">{{ $project->client->name }}</span>@else<a href="{{ ClientResource::getUrl('view', ['record' => $project->client]) }}" class="wsa-tag">{{ $project->client->name }}</a>@endif
                                @endif
                            @foreach ($project->categories ?? [] as $category)<span class="wsa-tag is-soft">{{ $category->getLabel() }}</span>@endforeach
                        </div>
                    @endif
                </div>
            </div>

            <div class="wsa-rhythm-wrap">
                <div class="wsa-rhythm" role="img" aria-label="{{ __('The last 14 days') }}">
                    @foreach ($c['rhythm'] as $day)
                        <i data-level="{{ $day['level'] }}" title="{{ $day['label'] }}"></i>
                    @endforeach
                </div>
                <div class="wsa-rhythm-scale"><span>{{ $since }}</span><span>{{ __('today') }}</span></div>
            </div>

            <x-wsa.progress :progress="$c['progress']" />

            <dl class="wsa-project-stats">
                <div><dt class="wsa-label">{{ __('Open') }}</dt><dd><a href="{{ $tasksUrl($project) }}">{{ $c['open'] }}</a></dd></div>
                <div @class(['is-blocked' => $c['blocked'] > 0])><dt class="wsa-label">{{ __('Blocked tasks') }}</dt><dd>@if ($c['blocked'])<i aria-hidden="true"></i><a href="{{ $tasksUrl($project) }}">{{ $c['blocked'] }}</a>@else{{ $c['blocked'] }}@endif</dd></div>
                <div><dt class="wsa-label">{{ __('Done, 7 days') }}</dt><dd>{{ $c['done'] }}</dd></div>
                @if ($c['minutes'] !== null)
                    <div><dt class="wsa-label">{{ __('Time, 7 days') }}</dt><dd><x-wsa.duration :minutes="$c['minutes']" /></dd></div>
                @endif
            </dl>

            @if ($b = $c['blocker'])
                <p class="wsa-project-blocker">
                    <i aria-hidden="true"></i>
                    <a href="{{ isset($b['task']) ? TaskResource::getUrl('view', ['record' => $b['task']]) : UpdateResource::getUrl('view', ['record' => $b['update']]) }}">{{ Markdown::inline($b['text'], links: false) }}</a>
                </p>
            @endif

            @if ($c['people']->isNotEmpty())
                <div>
                    <div class="wsa-label">{{ __('Worked on it in the last 7 days') }}</div>
                    <div class="wsa-initials">
                        @foreach ($c['people'] as $person)
                            <a href="{{ UserResource::getUrl('view', ['record' => $person['user']]) }}" @class(['wsa-initial', 'is-now' => $person['now']])
                               title="{{ $person['user']->name }}{{ $person['now'] ? ' · '.__('Active now') : '' }}">{{ $initials($person['user']->name) }}</a>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="wsa-last">
                @if ($u = $c['last'])
                    <div class="wsa-label">{{ __('Last update') }} · @if ($u->user && ! $client)<a href="{{ UserResource::getUrl('view', ['record' => $u->user]) }}">{{ $u->user->name }}</a>@else{{ $u->user?->name }}@endif · <x-wsa.time :at="$u->created_at" /></div>
                    <p><a href="{{ UpdateResource::getUrl('view', ['record' => $u]) }}">{{ Markdown::inline($u->summary, links: false) }}</a></p>
                @else
                    <div class="wsa-label">{{ __('Last update') }}</div>
                    <p class="is-empty">{{ __('No updates yet') }}</p>
                @endif
            </div>
        </article>
    @endforeach
</div>
