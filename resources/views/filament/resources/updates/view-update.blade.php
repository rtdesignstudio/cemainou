<x-filament-panels::page>
    <div class="wsa-split wsa-update">
        <div>
            @forelse ($sections as $section)
                <section class="wsa-block wsa-section">
                    <header class="wsa-block-head">
                        <h2>{{ $section['type']->sectionLabel() }}</h2>
                        <span>{{ $section['items']->count() }}</span>
                    </header>
                    @foreach ($section['items'] as $item)
                        @php($actionable = in_array($section['type'], [\App\Enums\UpdateItemType::Blocked, \App\Enums\UpdateItemType::Next], true) && (! auth()->user()->isClient() || $this->record->user_id === auth()->id()))
                        <div @class(['wsa-item', 'is-blocked' => $section['type'] === \App\Enums\UpdateItemType::Blocked, 'is-done' => $section['type'] === \App\Enums\UpdateItemType::Done, 'is-resolved' => $item->resolved_at !== null])>
                            <p>
                                @if ($item->task_id)
                                    <a href="{{ \App\Filament\Resources\Tasks\TaskResource::getUrl('view', ['record' => $item->task_id]) }}" class="wsa-id" @if ($item->task) title="{{ $item->task->title }}" @endif>#{{ $item->task_id }}</a>
                                @endif
                                {{ \App\Support\Markdown::inline($item->text) }}
                                {{-- rândul legat de o sarcină: unde e ea acum --}}
                                @if ($item->task && $section['type'] !== \App\Enums\UpdateItemType::Done)
                                    <span class="wsa-task-state is-{{ $item->task->status->value }}">{{ $item->task->status->getLabel() }}</span>
                                @endif
                                @if ($section['type'] === \App\Enums\UpdateItemType::Decision && $context)
                                    @if (\App\Support\ContextDecisions::contains($context, $item->text))
                                        <a href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('document', ['record' => $this->record->project, 'document' => $context]) }}" class="wsa-meta wsa-in-context">{{ __('in the context') }}</a>
                                    @elseif (auth()->user()->can('update', $context))
                                        <button type="button" class="wsa-inline-btn wsa-next-task" wire:click="decisionToContext({{ $item->id }})" wire:loading.attr="disabled">{{ __('Add to the context') }}</button>
                                    @endif
                                @endif
                            </p>
                            {{-- „Blocat" / „Urmează": Rezolvat, Fă sarcină, Comentează --}}
                            @if ($actionable)
                                <livewire:item-actions :item-id="$item->id" :wire:key="'ia-'.$item->id" />
                            @endif
                        </div>
                    @endforeach
                </section>
            @empty
                <p class="wsa-empty">{{ __('This update has only a summary.') }}</p>
            @endforelse

            <section class="wsa-block wsa-section">
                <livewire:update-comments :update="$this->record" :key="'update-comments-'.$this->record->id" />
            </section>

            @if ($days->isNotEmpty())
                <section class="wsa-block wsa-days">
                    <header class="wsa-block-head">
                        <h2>{{ $this->record->project ? __('On :project', ['project' => $this->record->project->name]) : __('By :name', ['name' => $this->record->user?->name]) }}</h2>
                        <span>{{ __('all updates, by day') }}</span>
                    </header>
                    @foreach ($days as $day)
                        <div class="wsa-day">
                            <div class="wsa-day-label">{{ $day['label'] }}</div>
                            @foreach ($day['rows'] as $row)
                                @if ($row['current'])
                                    {{-- update-ul deschis acum: în listă, dar fără link, stins --}}
                                    <div class="wsa-day-row is-current" aria-current="page">
                                        <span class="wsa-day-time">{{ $row['time'] }}</span>
                                        <span class="wsa-day-text">{{ \App\Support\Markdown::inline($row['update']->summary, links: false) }}</span>
                                        <span class="wsa-day-who">{{ __('this update') }}</span>
                                    </div>
                                @else
                                    <a href="{{ \App\Filament\Resources\Updates\UpdateResource::getUrl('view', ['record' => $row['update']]) }}" class="wsa-day-row">
                                        <span class="wsa-day-time">{{ $row['time'] }}</span>
                                        <span class="wsa-day-text">{{ \App\Support\Markdown::inline($row['update']->summary, links: false) }}</span>
                                        <span class="wsa-day-who">{{ $row['update']->user?->name }}</span>
                                    </a>
                                @endif
                            @endforeach
                        </div>
                    @endforeach
                </section>
            @endif
        </div>

        <aside class="wsa-aside">
            <section class="wsa-block">
                <header class="wsa-block-head"><h2>{{ __('Details') }}</h2></header>
                @unless (auth()->user()->isClient())
                    <livewire:update-seen :update="$this->record" :key="'seen-'.$this->record->id" />
                @endunless
                <dl class="wsa-kv">
                    @if ($this->record->user)
                        <div><dt>{{ __('Who') }}</dt><dd>@can('view', $this->record->user)<a href="{{ \App\Filament\Resources\Users\UserResource::getUrl('view', ['record' => $this->record->user]) }}">{{ $this->record->user->name }}</a>@else{{ $this->record->user->name }}@endcan</dd></div>
                    @endif
                    @if ($covers)
                        <div><dt>{{ __('Covers') }}</dt><dd>{{ $covers }}</dd></div>
                    @endif
                    @if ($this->record->project)
                        <div><dt>{{ __('Project') }}</dt><dd><a href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('view', ['record' => $this->record->project]) }}">{{ $this->record->project->name }}</a></dd></div>
                    @endif
                    <div><dt>{{ __('Agent') }}</dt><dd>{{ $this->record->source?->getLabel() }}</dd></div>
                    {{-- timpul, cu aceleași drepturi ca orele; începutul zilei - autorul și adminul --}}
                    @if ($minutes)<div><dt>{{ __('Time') }}</dt><dd>{{ $minutes }}</dd></div>@endif
                    @if ($estimated)<div title="{{ __('Estimated from commit times: no measured time that day') }}"><dt>{{ __('Estimated') }}</dt><dd>~{{ $estimated }}</dd></div>@endif
                    @if ($started)<div><dt>{{ __('Started') }}</dt><dd>{{ $started }}</dd></div>@endif
                </dl>
            </section>

            @if ($links)
                <section class="wsa-block">
                    <header class="wsa-block-head"><h2>{{ __('Links') }}</h2>@if (collect($links)->every(fn ($l) => $l['commit']))<span>{{ trans_choice(':count commit|:count commits', count($links)) }}</span>@endif</header>
                    @foreach ($links as $link)
                        @if ($link['url'])
                            <a href="{{ $link['url'] }}" target="_blank" rel="noopener" class="wsa-link-row">
                                <span>@if ($link['commit'])<code class="wsa-link-hash">{{ $link['label'] }}</code>@else{{ $link['label'] }}@endif</span>
                                <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedArrowTopRightOnSquare" class="wsa-link-icon" />
                            </a>
                        @else
                            <div class="wsa-link-row"><span>@if ($link['commit'])<code class="wsa-link-hash">{{ $link['label'] }}</code>@else{{ $link['label'] }}@endif</span></div>
                        @endif
                    @endforeach
                </section>
            @endif

            @if ($revisions->isNotEmpty())
                {{-- corecturile de după trimitere: nimic nu se schimbă pe ascuns --}}
                <section class="wsa-block">
                    <header class="wsa-block-head"><h2>{{ __('Changes') }}</h2><span>{{ __('after it was sent') }}</span></header>
                    @foreach ($revisions as $revision)
                        <div class="wsa-revision">
                            <div class="wsa-revision-head">{{ $revision['when'] }} · {{ $revision['who'] }}</div>
                            <div class="wsa-revision-reason">{{ $revision['reason'] }}</div>
                            @foreach ($revision['lines'] as $line)
                                @if (isset($line['url']))
                                    <a href="{{ $line['url'] }}" class="wsa-revision-line">{{ $line['text'] }}</a>
                                @else
                                    <div class="wsa-revision-line">{{ $line['text'] }}</div>
                                @endif
                            @endforeach
                        </div>
                    @endforeach
                </section>
            @endif
        </aside>
    </div>
</x-filament-panels::page>
