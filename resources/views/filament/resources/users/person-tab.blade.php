{{--
    Pagina unui om, filele de sub cifre (pe perioada aleasă): Update-uri (ca pe pagina Update-uri: făcut, blocaje, decizii pe un rând),
    Sarcini (în lucru acum: întârziatele și blocatele întâi, create de el - cine le are și cum stau -, gata în perioadă), Proiecte (timpul, update-urile, ultimul lui update acolo)
    și Timp (pe proiecte). Doar ce vede cel care se uită.
--}}
@php
    use App\Enums\UpdateItemType;
    use App\Filament\Resources\Projects\ProjectResource;
    use App\Filament\Resources\Tasks\TaskResource;
    use App\Filament\Resources\Updates\UpdateResource;
    use App\Support\Markdown;
    $tz = $this->record->tz();
    $when = fn ($t) => $t->copy()->setTimezone($tz)->locale(app()->getLocale())->isoFormat('D MMM, HH:mm');
@endphp
<section class="wsa-pp-tab">
    @if ($tab === 'updates')
        @forelse ($data['updates'] as $update)
            @php
                $byType = $update->items->groupBy(fn ($item) => $item->type->value);
                $done = $byType->get(UpdateItemType::Done->value, collect());
                $blocked = $byType->get(UpdateItemType::Blocked->value, collect());
                $decisions = $byType->get(UpdateItemType::Decision->value, collect());
            @endphp
            <article class="wsa-feed-item wsa-pp-update" wire:key="pu-{{ $update->id }}">
                <div class="wsa-feed-body">
                    <div class="wsa-feed-meta">{{ $when($update->created_at) }}@if ($update->project) · <a href="{{ ProjectResource::getUrl('view', ['record' => $update->project]) }}">{{ $update->project->name }}</a>@endif</div>
                    <a href="{{ UpdateResource::getUrl('view', ['record' => $update]) }}" class="wsa-feed-summary">{{ Markdown::inline($update->summary, links: false) }}</a>
                    @if ($done->isNotEmpty() || $blocked->isNotEmpty() || $decisions->isNotEmpty())
                        <ul class="wsa-feed-lines">
                            @foreach ($blocked->take(3) as $item)<li><span class="wsa-mark is-blocked" aria-hidden="true"></span><span>{{ Markdown::inline($item->text) }}</span></li>@endforeach
                            @foreach ($done->take(3) as $item)<li><span class="wsa-mark is-done" aria-hidden="true"></span><span>{{ Markdown::inline($item->text) }}</span></li>@endforeach
                            @if ($done->count() > 3)<li class="wsa-feed-more-lines"><a href="{{ UpdateResource::getUrl('view', ['record' => $update]) }}">{{ __('+ :count more done', ['count' => $done->count() - 3]) }}</a></li>@endif
                            @if ($decisions->isNotEmpty())<li class="wsa-feed-decisions"><span class="wsa-mark is-decision" aria-hidden="true"></span><span><strong>{{ __('Decisions') }}:</strong> {{ $decisions->map(fn ($item) => Markdown::plain($item->text))->join(' · ') }}</span></li>@endif
                        </ul>
                    @endif
                </div>
            </article>
        @empty
            <p class="wsa-empty">{{ __('No updates in this period.') }}</p>
        @endforelse

    @elseif ($tab === 'tasks')
        @php
            $row = function ($task, ?string $extra = null) {
                return '<div class="wsa-day-project wsa-pp-task"><a href="'.e(TaskResource::getUrl('view', ['record' => $task])).'"><span class="wsa-id">#'.$task->id.'</span> '.e($task->title).'</a>'
                    .'<span class="wsa-meta">'.e($task->project?->name).'</span><span class="wsa-meta">'.($extra ?? e($task->status->getLabel())).'</span></div>';
            };
        @endphp
        <div class="wsa-group" id="now">{{ __('In progress now') }} <span class="wsa-meta">{{ $data['now']->count() }}</span></div>
        @php($today = now($tz)->toDateString())
        @forelse ($data['now'] as $task)
            @php($late = $task->due_on && $task->due_on->toDateString() < $today)
            {!! $row($task, ($late ? '<span class="wsa-tag is-alert">'.e(__('late (one)')).'</span> ' : '')
                .($task->status === \App\Enums\TaskStatus::Blocked ? '<span class="wsa-tag">'.e($task->status->getLabel()).'</span>' : e($task->status->getLabel()))
                .($task->due_on ? ' · '.e($task->due_on->locale(app()->getLocale())->isoFormat('D MMM')) : '')) !!}
        @empty
            <p class="wsa-empty">{{ __('Nothing open right now.') }}</p>
        @endforelse

        <div class="wsa-group" id="created">{{ __('Created in this period') }} <span class="wsa-meta">{{ $data['created']->count() }}</span></div>
        @forelse ($data['created'] as $task)
            {!! $row($task, e($task->assignee ? ($task->assignee->is($this->record) ? __('for themselves') : $task->assignee->name) : __('free')).' · '.e($task->status->getLabel())) !!}
        @empty
            <p class="wsa-empty">{{ __('No tasks created in this period.') }}</p>
        @endforelse

        <div class="wsa-group" id="done">{{ __('Done in this period') }} <span class="wsa-meta">{{ $data['done']->count() }}</span></div>
        @forelse ($data['done'] as $task)
            {!! $row($task, $task->done_at ? e($when($task->done_at)) : null) !!}
        @empty
            <p class="wsa-empty">{{ __('No tasks done in this period.') }}</p>
        @endforelse

    @elseif ($tab === 'projects')
        @php($max = max(1, $data['projects']->max('minutes') ?? 1))
        @forelse ($data['projects'] as $p)
            <div class="wsa-pp-project">
                <div class="wsa-pp-project-head">
                    <a href="{{ ProjectResource::getUrl('view', ['record' => $p['project']]) }}" class="wsa-name">{{ $p['project']->name }}</a>
                    @if ($p['leads'])<span class="wsa-tag is-soft">{{ __('leads it') }}</span>@endif
                    <span class="wsa-meta">{{ collect([$withTime && $p['minutes'] ? $format($p['minutes']) : null, $p['updates'] ? trans_choice(':count update|:count updates', $p['updates']) : null])->filter()->join(' · ') }}</span>
                </div>
                @if ($withTime && $p['minutes'])<span class="wsa-day-bar" style="--share: {{ max(4, round($p['minutes'] / $max * 100)) }}%">@if ($p['hatched'])<i class="is-hatched" style="width: {{ round(min($p['hatched'], $p['minutes']) * 100 / $p['minutes'], 1) }}%"></i>@endif</span>@endif
                @if ($p['last'])
                    <a href="{{ UpdateResource::getUrl('view', ['record' => $p['last']->id]) }}" class="wsa-pp-project-last"><span class="wsa-meta">{{ $when($p['last']->created_at) }}</span> {{ Markdown::plain($p['last']->summary) }}</a>
                @endif
            </div>
        @empty
            <p class="wsa-empty">{{ __('No project in this period.') }}</p>
        @endforelse

    @elseif ($tab === 'time')
        @php($total = $data['projects']->sum('minutes'))
        @forelse ($data['projects'] as $p)
            <div class="wsa-day-project">
                <a href="{{ ProjectResource::getUrl('view', ['record' => $p['project']]) }}">{{ $p['project']->name }}</a>
                <span class="wsa-day-bar" style="--share: {{ max(4, round($p['minutes'] / max(1, $data['projects']->max('minutes')) * 100)) }}%">@if ($p['hatched'])<i class="is-hatched" style="width: {{ round(min($p['hatched'], $p['minutes']) * 100 / $p['minutes'], 1) }}%"></i>@endif</span>
                <span class="wsa-meta">{{ $format($p['minutes']) }} · {{ $total ? round($p['minutes'] * 100 / $total) : 0 }}%</span>
            </div>
        @empty
            <p class="wsa-empty">{{ __('No time in this period.') }}</p>
        @endforelse
        @if ($total)<p class="wsa-meta wsa-pp-time-note">{{ __('Measured, added by hand, corrected and estimated from git; the hatched part is added by hand or estimated. The bars above show it day by day.') }}</p>@endif
    @endif
</section>
