{{-- Conținutul paginii Săptămâna: desenat o dată pe minut (App\Support\PageCache), din Week. --}}
    @php
        $doneCount = $w['done']->sum(fn ($g) => $g['tasks']->count());
        $diff = fn (int $now, int $before) => $before === 0 && $now === 0 ? null : __('last week: :count', ['count' => $before]);
        $hasTotals = $w['grid']->contains(fn ($row) => $row['total'] !== null);
        // rezumatul de copiat (Slack, client): ce s-a terminat, deciziile, ce e blocat, ce urmează
        $summaryText = collect([__('Week :label', ['label' => $w['label']])])
            ->concat($doneCount ? ['', __('Done').':'] : [])->concat($w['done']->flatMap(fn ($g) => $g['tasks']->map(fn ($t) => '- '.($g['project']?->name ? $g['project']->name.': ' : '').$t->title)))
            ->concat($w['decisions']->isNotEmpty() ? ['', __('Decisions').':'] : [])->concat($w['decisions']->map(fn ($i) => '- '.\App\Support\Markdown::plain($i->text)))
            ->concat($w['blocked']->isNotEmpty() ? ['', __('Blocked').':'] : [])->concat($w['blocked']->map(fn ($t) => '- '.$t->title.($t->blocked_reason ? ': '.$t->blocked_reason : '')))
            ->concat($w['next']->isNotEmpty() ? ['', __('Next').':'] : [])->concat($w['next']->map(fn ($i) => '- '.\App\Support\Markdown::plain($i->text)))
            ->join("\n");
        $empty = collect([
            $doneCount ? null : __('no task finished'),
            $w['decisions']->isEmpty() ? __('no decisions') : null,
            $w['ideas']->isEmpty() ? __('no new ideas') : null,
            $w['blocked']->isEmpty() ? __('nothing blocked') : null,
        ])->filter();
    @endphp
    {{-- săptămâna: ‹ perioada ›, rezumatul de copiat; cifrele cu săptămâna trecută; grila om × zi; apoi doar secțiunile cu ceva în ele --}}
    <div class="wsa-toolbar">
        <x-wsa.period-nav :label="$w['label']" previous="previous" :previous-label="__('Previous week')" :next="$isCurrent ? null : 'next'" :next-label="__('Next week')"
            :current="$isCurrent ? null : 'current'" :current-label="__('This week')" />
        <div class="wsa-toolbar-tools" x-data="{ copied: false, text: @js($summaryText) }">
            <button type="button" class="wsa-link-strong" x-on:click="navigator.clipboard?.writeText(text).then(() => { copied = true; setTimeout(() => copied = false, 1800) })">
                <span x-show="! copied">{{ __('Copy the summary') }}</span><span x-show="copied" x-cloak>{{ __('Copied') }}</span>
            </button>
        </div>
    </div>

    <x-wsa.stats>
        @if ($w['minutes'] !== null)
            <x-wsa.stat :label="__('Team hours')" :meta="$w['previous']['minutes'] ? __('last week: :time', ['time' => \App\Support\WorkTime::total($w['previous']['minutes'])]) : null">@if ($w['minutes'])<x-wsa.duration :minutes="$w['minutes']" />@else 0m @endif</x-wsa.stat>
        @endif
        <x-wsa.stat :label="__('Updates')" :meta="$diff($w['updates'], $w['previous']['updates'])">{{ $w['updates'] }}</x-wsa.stat>
        <x-wsa.stat :label="__('Tasks done')" :meta="$diff($doneCount, $w['previous']['done'])">{{ $doneCount }}</x-wsa.stat>
        <x-wsa.stat :label="__('Blocked now')" :blocked="$w['blocked']->isNotEmpty()">{{ $w['blocked']->count() }}</x-wsa.stat>
        <x-wsa.stat :label="__('Decisions')">{{ $w['decisions']->count() }}</x-wsa.stat>
    </x-wsa.stats>

    <section class="wsa-block">
        <header class="wsa-block-head"><h2>{{ __('The team, day by day') }}</h2><span>{{ __('darker = more done') }}</span></header>
        <div @class(['wsa-week-grid', 'has-total' => $hasTotals]) style="--days: {{ count($w['days']) }}">
            <span></span>
            @foreach ($w['days'] as $d)
                <span @class(['wsa-week-day', 'is-today' => $d['today'], 'is-weekend' => $d['weekend']])>{{ $d['short'] }}</span>
            @endforeach
            @if ($hasTotals)<span class="wsa-week-day wsa-week-total-head">{{ __('Total') }}</span>@endif
            @foreach ($w['grid'] as $row)
                <a href="{{ \App\Filament\Resources\Users\UserResource::getUrl('view', ['record' => $row['person']]) }}" class="wsa-week-name">{{ $row['person']->name }}</a>
                @foreach ($row['cells'] as $cell)
                    @if ($cell['future'])
                        <span @class(['wsa-week-cell', 'is-future', 'is-weekend' => $cell['weekend']]) data-level="0"></span>
                    @else
                        {{-- ziua omului: pagina lui, deschisă la ziua asta (ce a făcut, pe ce proiecte, timpul pentru cine are voie) --}}
                        <a href="{{ \App\Filament\Resources\Users\UserResource::getUrl('view', ['record' => $row['person'], 'day' => $cell['date']]) }}" @class(['wsa-week-cell', 'is-weekend' => $cell['weekend']]) data-level="{{ $cell['level'] }}" title="{{ $cell['label'] }}">
                            @if ($cell['projects'])<span class="wsa-week-projects">{{ implode(', ', $cell['projects']) }}</span>@endif
                        </a>
                    @endif
                @endforeach
                @if ($hasTotals)<span class="wsa-week-total">@if ($row['total'] !== null)<x-wsa.duration :minutes="$row['total']" />@endif</span>@endif
            @endforeach
        </div>
    </section>

    <div class="wsa-split">
        <div>
            @if ($doneCount)
                <section class="wsa-block wsa-section">
                    <header class="wsa-block-head"><h2>{{ __('Done this week') }}</h2><span>{{ $doneCount }}</span></header>
                    @foreach ($w['done'] as $group)
                        <div class="wsa-group">{{ $group['project']?->name }}</div>
                        @foreach ($group['tasks'] as $task)
                            <div class="wsa-task is-done">
                                <span class="wsa-mark is-done"></span>
                                <a href="{{ \App\Filament\Resources\Tasks\TaskResource::getUrl('view', ['record' => $task]) }}" class="wsa-task-title"><span class="wsa-id">#{{ $task->id }}</span>{{ $task->title }}</a>
                                <span class="wsa-meta">{{ $task->assignee?->name }}</span>
                            </div>
                        @endforeach
                    @endforeach
                </section>
            @endif

            @if ($w['next']->isNotEmpty())
                <section class="wsa-block wsa-section">
                    <header class="wsa-block-head"><h2>{{ __('What is next') }}</h2><span>{{ __('from the updates of the week') }}</span></header>
                    @foreach ($w['next'] as $item)
                        {{-- linkul pe text; dedesubt Rezolvat / Fă sarcină (ItemActions) --}}
                        <div class="wsa-week-line" wire:key="wn-{{ $item->id }}">
                            <span class="wsa-mark is-todo" aria-hidden="true"></span>
                            <span><a href="{{ \App\Filament\Resources\Updates\UpdateResource::getUrl('view', ['record' => $item->statusUpdate]) }}" class="wsa-week-line-text">{{ \App\Support\Markdown::inline($item->text, links: false) }}</a>
                                <span class="wsa-meta">{{ $item->statusUpdate->user?->name }}@if ($item->statusUpdate->project) · {{ $item->statusUpdate->project->name }}@endif</span>
                                @unless (auth()->user()->isClient())
                                    <span class="wsa-item-actions">
                                        <button type="button" class="wsa-inline-btn" wire:click="resolveLine({{ $item->id }})">{{ __('Resolved') }}</button>
                                        @if ($item->statusUpdate->project)<button type="button" class="wsa-inline-btn" wire:click="lineToTask({{ $item->id }})">{{ __('Make it a task') }}</button>@endif
                                    </span>
                                @endunless</span>
                        </div>
                    @endforeach
                </section>
            @endif

            @if ($w['decisions']->isNotEmpty())
                <section class="wsa-block wsa-section">
                    <header class="wsa-block-head"><h2>{{ __('Decisions') }}</h2><span>{{ $w['decisions']->count() }}</span></header>
                    @foreach ($w['decisions'] as $item)
                        <a href="{{ \App\Filament\Resources\Updates\UpdateResource::getUrl('view', ['record' => $item->statusUpdate]) }}" class="wsa-week-line">
                            <span class="wsa-mark is-decision" aria-hidden="true"></span>
                            <span><span class="wsa-week-line-text">{{ \App\Support\Markdown::inline($item->text, links: false) }}</span>
                                <span class="wsa-meta">{{ $item->statusUpdate->user?->name }}@if ($item->statusUpdate->project) · {{ $item->statusUpdate->project->name }}@endif · <x-wsa.time :at="$item->statusUpdate->created_at" /></span></span>
                        </a>
                    @endforeach
                </section>
            @endif

            @if ($empty->isNotEmpty())
                <p class="wsa-meta wsa-week-empty">{{ __('This week: :list.', ['list' => $empty->join(', ')]) }}</p>
            @endif
        </div>
        <aside class="wsa-aside">
            @if ($w['blocked']->isNotEmpty())
                <section class="wsa-block">
                    <header class="wsa-block-head"><h2>{{ __('Blocked now') }}</h2><span>{{ $w['blocked']->count() }}</span></header>
                    @foreach ($w['blocked'] as $task)
                        <p class="wsa-project-blocker"><i aria-hidden="true"></i><a href="{{ \App\Filament\Resources\Tasks\TaskResource::getUrl('view', ['record' => $task]) }}">{{ $task->title }}@if ($task->blocked_reason): {{ $task->blocked_reason }}@endif</a></p>
                        <p class="wsa-meta wsa-week-under">{{ $task->project?->name }}@if ($task->assignee) · {{ $task->assignee->name }}@endif</p>
                    @endforeach
                </section>
            @endif

            @if ($w['ideas']->isNotEmpty())
                <section class="wsa-block wsa-section">
                    <header class="wsa-block-head"><h2>{{ __('New ideas') }}</h2><span>{{ $w['ideas']->count() }}</span></header>
                    @foreach ($w['ideas'] as $idea)
                        <div class="wsa-task">
                            <span class="wsa-mark is-idea"></span>
                            <a href="{{ \App\Filament\Resources\Tasks\TaskResource::getUrl('view', ['record' => $idea]) }}" class="wsa-task-title">{{ $idea->title }}</a>
                            <span class="wsa-meta">{{ $idea->project?->name }}</span>
                        </div>
                    @endforeach
                </section>
            @endif

            <section class="wsa-block wsa-section">
                <header class="wsa-block-head"><h2>{{ __('Quiet projects') }}</h2><span>{{ $w['quiet']->count() }}</span></header>
                @forelse ($w['quiet'] as $q)
                    <div class="wsa-week-quiet">
                        <a href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('view', ['record' => $q['project']]) }}">{{ $q['project']->name }}</a>
                        <span class="wsa-meta">{{ trans_choice('quiet for :count day|quiet for :count days', $q['health']['days']) }}@if ($q['project']->lead) · {{ $q['project']->lead->name }}@endif</span>
                    </div>
                @empty
                    <p class="wsa-empty">{{ __('Every active project had an update in the last week.') }}</p>
                @endforelse
            </section>
        </aside>
    </div>
