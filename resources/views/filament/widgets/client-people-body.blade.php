{{-- Oamenii clienților: cine e și unde stă, ce a făcut (ultima acțiune, 7 zile), ce cere reacție, primii pași pe cemainou. --}}
@php
    $user = fn ($u) => \App\Filament\Resources\Users\UserResource::getUrl('view', ['record' => $u]);
    $task = fn ($t) => \App\Filament\Resources\Tasks\TaskResource::getUrl('view', ['record' => $t]);
    $days = fn ($at) => \App\Support\ClientPeople::days($at);
@endphp
<section class="wsa-block wsa-crew wsa-clientppl">
    <header class="wsa-block-head">
        <h2>{{ __('Clients') }} <span class="wsa-head-count">{{ trans_choice(':count person|:count people', $people->count()) }} · {{ __(':count waiting for us', ['count' => $people->sum(fn ($p) => $p['waiting']->count())]) }}</span></h2>
    </header>
    <div class="wsa-team">
        @foreach ($people as $p)
            <article class="wsa-person wsa-clientp">
                @if ($p['now'])
                    <span class="wsa-presence is-now">{{ __('Now') }}</span>
                @elseif ($p['seen'])
                    <span class="wsa-presence" title="{{ \App\Support\Ago::exact($p['seen']) }}">{{ $p['seen']->isToday() ? __('today at :time', ['time' => $p['seen']->copy()->setTimezone(auth()->user()->tz())->format('H:i')]) : \App\Support\Ago::short($p['seen']) }}</span>
                @endif
                <div class="wsa-person-top">
                    <div>
                        <a href="{{ $user($p['user']) }}" class="wsa-name">{{ $p['user']->name }}</a>
                        <div class="wsa-meta">{{ $p['company'] }}@if ($p['projects']) · {{ implode(', ', $p['projects']) }}@endif</div>
                    </div>
                </div>

                @if ($p['never'])
                    <p class="wsa-clientp-never">{{ __('Invited, has not signed in yet') }}
                        @if ($admin)<button type="button" class="wsa-inline-btn is-strong" wire:click="resend({{ $p['user']->id }})" wire:confirm="{{ __('Send the invitation again? The old link stops working.') }}">{{ __('Send the invitation again') }}</button>@endif
                    </p>
                @endif

                {{-- ce a făcut --}}
                <div class="wsa-now">
                    @if ($a = $p['last'])
                        <div class="wsa-label">{{ __('Last action') }}</div>
                        <p class="wsa-now-text"><a href="{{ $a['url'] }}">{{ $a['text'] }}</a>@if ($a['title']) <span class="wsa-meta">· {{ \Illuminate\Support\Str::limit(\App\Support\Markdown::plain($a['title']), 70) }}</span>@endif</p>
                        <div class="wsa-meta"><x-wsa.time :at="$a['at']" /></div>
                    @else
                        <p class="wsa-meta">{{ __('Nothing in the last 7 days.') }}</p>
                    @endif
                    <ol class="wsa-clientp-week" aria-label="{{ __('Active days, the last 7') }}">
                        @foreach ($p['week'] as $d)<li @class(['is-on' => $d['on']]) title="{{ $d['label'] }}"></li>@endforeach
                    </ol>
                </div>

                {{-- ce cere reacție --}}
                @if ($p['waiting']->isNotEmpty() || $p['on_them']->isNotEmpty())
                    <div class="wsa-clientp-react">
                        @if ($p['waiting']->isNotEmpty())
                            <div><span class="wsa-label">{{ __('Waiting for us') }}</span>
                                @foreach ($p['waiting']->take(3) as $w)
                                    <a href="{{ $task($w['task']) }}" class="wsa-clientp-line"><span class="wsa-id">#{{ $w['task']->id }}</span>{{ $w['task']->title }} <span class="wsa-meta">· {{ $days($w['since']) ? trans_choice(':count day|:count days', $days($w['since'])) : __('today') }}</span></a>
                                @endforeach
                            </div>
                        @endif
                        @if ($p['on_them']->isNotEmpty())
                            <div><span class="wsa-label">{{ __('Waiting for them') }}</span>
                                @foreach ($p['on_them']->take(3) as $t)
                                    <a href="{{ $task($t) }}" class="wsa-clientp-line"><span class="wsa-id">#{{ $t->id }}</span>{{ $t->title }} <span class="wsa-meta">· {{ $days($t->created_at) ? trans_choice(':count day|:count days', $days($t->created_at)) : __('today') }}</span></a>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif

                {{-- primii pași --}}
                <div class="wsa-clientp-steps" title="{{ __('Getting started: :done of :total', ['done' => $p['steps_done'], 'total' => count($p['steps'])]) }}">
                    @foreach ($p['steps'] as $s)<span @class(['is-done' => $s['done']])>{{ $s['label'] }}</span>@endforeach
                </div>
            </article>
        @endforeach
    </div>
</section>
