<x-filament-widgets::widget>
    <section class="wsa-block wsa-crew">
        <header class="wsa-block-head">
            <h2>{{ __('Team') }} <span class="wsa-head-count">{{ __(':active of :total active now', ['active' => $people->where('active', true)->count(), 'total' => $people->count()]) }}</span></h2>
            <a href="{{ \App\Filament\Pages\Week::getUrl() }}" class="wsa-link">{{ __('The team\'s week') }}<x-wsa.arrow /></a>
        </header>
        @if ($problems)
            <p class="wsa-setup-problems">{{ implode(' · ', $problems) }}</p>
        @endif
        <div class="wsa-team">
            @foreach ($people as $p)
                <article class="wsa-person">
                    @if ($p['active'])
                        <span class="wsa-presence is-now" tabindex="0" data-tip="{{ __('Active now') }}{{ $p['now_in'] ? ' · '.$p['now_in']->name : '' }}">{{ __('Now') }}</span>
                    @elseif ($p['last_seen'])
                        <span class="wsa-presence" tabindex="0" data-tip="{{ __('Last activity :time (an agent session or a visit to cemainou)', ['time' => $p['last_seen']->diffForHumans()]) }} · {{ \App\Support\Ago::exact($p['last_seen']) }}">{{ \App\Support\Ago::short($p['last_seen']) }}</span>
                    @endif
                    <div class="wsa-person-top">
                        <div>
                            <a href="{{ \App\Filament\Resources\Users\UserResource::getUrl('view', ['record' => $p['user']]) }}" class="wsa-name">{{ $p['user']->name }}</a>
                            <div class="wsa-meta">@if ($away->has($p['user']->id)){{ __('On time off until :date', ['date' => $away[$p['user']->id]->to->locale(app()->getLocale())->isoFormat('D MMM')]) }}@else{{ $p['user']->role->getLabel() }}@endif</div>
                        </div>
                        @if ($p['time'] && ($p['time']['start'] || $p['added']))
                            <dl class="wsa-time-inline">
                                @if ($p['time']['start'])<div><dt class="wsa-label">{{ __('Started') }}</dt><dd>{{ $p['start_local'] }}</dd></div>@endif
                                <div><dt class="wsa-label">{{ __('Active time') }}</dt><dd>{{ \App\Support\WorkTime::format($p['total']) }}@if ($p['added'])<small class="wsa-time-added" title="{{ __('Added by hand') }}">{{ $p['added'] > 0 ? '+' : '−' }}{{ \App\Support\WorkTime::format(abs($p['added'])) }}</small>@endif</dd></div>
                            </dl>
                        @endif
                    </div>

                    {{-- ziua pe ore, sub accentul de sub nume: o bară pe oră (din 60 de minute), măsurat plin, adăugat de mână hașurat, ora de acum marcată --}}
                    @if ($p['hours'])
                        <div class="wsa-mini-hours" role="img" aria-label="{{ __('Time worked by hour') }}">
                            @foreach ($p['hours']['measured'] as $h => $m)
                                @php($a = $p['hours']['added'][$h])
                                <span @class(['is-now' => $h === $p['hours']['now'], 'is-future' => $h > $p['hours']['now']]) title="{{ sprintf('%02d:00', $h) }}{{ $m + $a ? ' · '.\App\Support\WorkTime::format($m + $a).($a ? ' ('.__('added by hand').': '.\App\Support\WorkTime::format($a).')' : '') : '' }}">
                                    @if ($m)<i style="height: {{ max(8, round(min(60, $m) * 100 / 60)) }}%"></i>@endif
                                    @if ($a)<i class="is-added" style="height: {{ round(min(60, $a) * 100 / 60) }}%"></i>@endif
                                </span>
                            @endforeach
                        </div>
                    @endif

                    {{-- ce a făcut: ultimul update, mare (rezumatul, apoi proiectul · clientul · când; semn roșu dacă are blocaje) --}}
                    <div class="wsa-now">
                        @if ($u = $p['last_update'])
                            <div class="wsa-label">{{ __('Last update') }}</div>
                            <p class="wsa-now-text"><a href="{{ \App\Filament\Resources\Updates\UpdateResource::getUrl('view', ['record' => $u]) }}">{{ \App\Support\Markdown::inline($u->summary, links: false) }}</a></p>
                            <div class="wsa-meta">
                                @if ($u->project)<a href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('view', ['record' => $u->project]) }}">{{ $u->project->name }}</a> · @endif
                                @if ($u->project?->client)<a href="{{ \App\Filament\Resources\Clients\ClientResource::getUrl('view', ['record' => $u->project->client]) }}">{{ $u->project->client->name }}</a> · @endif
                                <x-wsa.time :at="$u->created_at" />
                                @if ($u->blocked_count)
                                    · <a href="{{ \App\Filament\Resources\Updates\UpdateResource::getUrl('view', ['record' => $u]) }}" class="wsa-now-blocked"><i aria-hidden="true"></i>{{ trans_choice(':count blocked|:count blocked', $u->blocked_count) }}</a>
                                @endif
                            </div>
                        @else
                            <div class="wsa-label">{{ __('Last update') }}</div>
                            <p class="wsa-now-text is-empty">{{ __('No updates yet') }}</p>
                        @endif
                    </div>

                    {{-- unde e acum (sesiune activă pe alt proiect), ce a spus azi că face, sarcinile în lucru: rânduri mici, doar când există --}}
                    @if ($p['elsewhere'] || $p['today'] || $p['doing'])
                        <div class="wsa-now-more">
                            @if ($p['elsewhere'])
                                <div class="wsa-meta">{{ __('Now in') }} <a href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('view', ['record' => $p['elsewhere']]) }}">{{ $p['elsewhere']->name }}</a>@if ($p['elsewhere']->client) · {{ $p['elsewhere']->client->name }}@endif</div>
                            @endif
                            @if ($p['today'])
                                <div class="wsa-meta">{{ __('Today') }}: @if ($p['today']['url'])<a href="{{ $p['today']['url'] }}">{{ \App\Support\Markdown::inline($p['today']['text'], links: false) }}</a>@else<b>{{ \App\Support\Markdown::inline($p['today']['text'], links: false) }}</b>@endif</div>
                            @endif
                            @if ($p['doing'])
                                <div class="wsa-meta"><a href="{{ \App\Filament\Resources\Tasks\TaskResource::getUrl('index', ['tab' => 'open', 'person' => $p['user']->id]) }}">{{ __(':count in progress', ['count' => $p['doing']]) }}</a></div>
                            @endif
                        </div>
                    @endif

                    {{-- uneltele: ok discret, ce lipsește gri, ce s-a stricat sau a rămas în urmă scos în evidență; la mine, gri duce la pornire --}}
                    @if ($p['setup'])
                        <x-wsa.setup :items="$p['setup']" :link="fn ($s) => $p['self'] && $s['state'] !== 'ok' ? match ($s['key']) { 'agent', 'app', 'time' => $profile.'?connect=1', 'push', 'phone', 'telegram' => $profile, 'upd' => \App\Filament\Pages\Commands::getUrl(), default => null } : null">
                            @if ($p['steps'] === 'send')
                                <button type="button" class="wsa-setup-send" wire:click="sendSteps({{ $p['user']->id }})" wire:loading.attr="disabled" title="{{ __('Only what is missing: the connect command, how /upd works, notifications. On Telegram if they linked it, otherwise by email.') }}">{{ __('Send them the steps') }}</button>
                            @elseif ($p['steps'] === 'sent')
                                <span class="wsa-setup-send is-sent">{{ __('Steps sent today') }}</span>
                            @endif
                        </x-wsa.setup>
                    @endif

                    @if ($p['time'] && ($p['time']['start'] || $p['added']))
                        <dl class="wsa-time">
                            @if ($p['time']['start'])<div><dt class="wsa-label">{{ __('Started') }}</dt><dd>{{ $p['start_local'] }}</dd></div>@endif
                            <div><dt class="wsa-label">{{ __('Active time') }}</dt><dd>{{ \App\Support\WorkTime::format($p['total']) }}@if ($p['added'])<small class="wsa-time-added" title="{{ __('Added by hand') }}">{{ $p['added'] > 0 ? '+' : '−' }}{{ \App\Support\WorkTime::format(abs($p['added'])) }}</small>@endif</dd></div>
                        </dl>
                    @endif
                </article>
            @endforeach
        </div>
    </section>
</x-filament-widgets::widget>
