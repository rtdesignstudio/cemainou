{{--
    Panoul sarcinilor: de făcut, în lucru, blocate, gata săptămâna asta. Ordinea e automată (termenul, apoi cele mai noi). Pe calculator se trag cardurile
    între coloane; blocat cere motivul, gata are dovada opțională (dropTask, cu ferestrele de pe „Ale mele"); după fiecare mutare, „Anulează" câteva secunde.
    Tastatura: săgețile mută selecția, 1-4 trimit cardul în coloana aceea, Enter îl deschide. Pe telefon: o coloană odată, cu file, iar mutarea din meniul cardului.
--}}
@php
    $me = auth()->user();
    $columns = $this->board();
    $labels = ['todo' => __('To do'), 'doing' => __('In progress'), 'blocked' => __('Blocked'), 'done' => __('Done this week')];
    $url = fn ($task) => \App\Filament\Resources\Tasks\TaskResource::getUrl('view', ['record' => $task]);
    $listUrl = fn (string $status) => \App\Filament\Resources\Tasks\TaskResource::getUrl('index', array_filter(['view' => 'list', 'tab' => $status === 'done' ? 'done' : 'open', 'person' => $this->person, 'project' => $this->projectPick]));
    $initials = fn ($u) => collect(preg_split('/\s+/', trim($u->name)))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->join('');
    $first = collect($columns)->filter(fn ($c) => $c['total'] > 0)->keys()->first() ?? 'todo';
@endphp
<div class="wsa-board" x-data="{ dragging: null, over: null, col: @js($first),
        go(card, dir) { const cards = [...card.closest('.wsa-board-col').querySelectorAll('.wsa-card')]; const next = cards[cards.indexOf(card) + dir]; next?.focus() },
        side(card, dir) { const cols = [...this.$root.querySelectorAll('.wsa-board-col')]; const i = cols.indexOf(card.closest('.wsa-board-col')); for (let k = i + dir; k >= 0 && k < cols.length; k += dir) { const c = cols[k].querySelector('.wsa-card'); if (c) { c.focus(); return } } } }"
    :class="{ 'is-dragging': dragging }" x-on:dragend.window="dragging = null; over = null">

    <nav class="wsa-board-tabs" aria-label="{{ __('Columns') }}">
        @foreach ($columns as $status => $c)
            <button type="button" :class="{ 'is-on': col === '{{ $status }}' }" x-on:click="col = '{{ $status }}'">{{ $labels[$status] }} <span>{{ $c['total'] }}</span></button>
        @endforeach
    </nav>

    <div class="wsa-board-cols">
        @foreach ($columns as $status => $c)
            <section @class(['wsa-board-col', 'is-blocked' => $status === 'blocked']) :class="{ 'is-shown': col === '{{ $status }}', 'is-over': over === '{{ $status }}' }"
                x-on:dragover.prevent="if (dragging) over = '{{ $status }}'" x-on:dragleave.self="over = null"
                x-on:drop.prevent="if (dragging) $wire.dropTask(dragging, '{{ $status }}'); dragging = null; over = null" aria-label="{{ $labels[$status] }}">
                <header class="wsa-board-head"><h3>{{ $labels[$status] }}</h3><span>{{ $c['total'] }}</span></header>
                @forelse ($c['tasks'] as $task)
                    @php
                        $targets = collect(\App\Filament\Resources\Tasks\Pages\ListTasks::BOARD)->reject(fn ($s) => $s === $task->status->value)
                            ->filter(fn ($s) => $me->can('move', [$task, \App\Enums\TaskStatus::from($s)]))->values();
                        $due = $task->status->isOpen() ? $task->due($me) : null;
                        $steps = $task->checklist();
                    @endphp
                    <article class="wsa-card" wire:key="card-{{ $task->id }}" tabindex="0" data-href="{{ $url($task) }}"
                        @if ($targets->isNotEmpty()) draggable="true" x-on:dragstart="dragging = {{ $task->id }}; $event.dataTransfer.effectAllowed = 'move'" @endif
                        :class="{ 'is-dragged': dragging === {{ $task->id }} }"
                        x-on:keydown.enter.self.prevent="Livewire.navigate($el.dataset.href)"
                        x-on:keydown.arrow-down.self.prevent="go($el, 1)" x-on:keydown.arrow-up.self.prevent="go($el, -1)"
                        x-on:keydown.arrow-right.self.prevent="side($el, 1)" x-on:keydown.arrow-left.self.prevent="side($el, -1)"
                        x-on:keydown.self="const s = @js(\App\Filament\Resources\Tasks\Pages\ListTasks::BOARD)[+$event.key - 1]; if (s && @js($targets->all()).includes(s)) { $event.preventDefault(); $wire.dropTask({{ $task->id }}, s) }">
                        <div class="wsa-card-top">
                            <span class="wsa-id">#{{ $task->id }}</span>
                            @if ($due)<span @class(['wsa-card-due', 'is-overdue' => $due['overdue']])>{{ $due['overdue'] ? trans_choice('Overdue by :count day|Overdue by :count days', abs($due['days'])) : $due['label'] }}</span>@endif
                        </div>
                        <a href="{{ $url($task) }}" class="wsa-card-title" draggable="false">{{ $task->title }}</a>
                        @if ($task->status === \App\Enums\TaskStatus::Blocked && $task->blocked_reason)<p class="wsa-card-reason">{{ $task->blocked_reason }}</p>@endif
                        <div class="wsa-card-meta">
                            @unless ($this->projectPick)<span class="wsa-card-project">{{ $task->project?->name }}</span>@endunless
                            @if ($steps)<span title="{{ __('Steps') }}">{{ $steps['done'] }}/{{ $steps['total'] }}</span>@endif
                            @if ($task->attachments_count)<span class="wsa-card-files" title="{{ trans_choice(':count file|:count files', $task->attachments_count) }}"><x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedPaperClip" />{{ $task->attachments_count }}</span>@endif
                            @if ($task->assignee)<span class="wsa-card-who" title="{{ $task->assignee->name }}">{{ $initials($task->assignee) }}</span>@else<span class="wsa-card-who is-free" title="{{ __('Free') }}">–</span>@endif
                        </div>
                        @if ($targets->isNotEmpty())
                            <label class="wsa-card-move">
                                <span class="sr-only">{{ __('Move to') }}</span>
                                <select x-on:change="if ($event.target.value) $wire.dropTask({{ $task->id }}, $event.target.value); $event.target.value = ''">
                                    <option value="">{{ __('Move to…') }}</option>
                                    @foreach ($targets as $t)<option value="{{ $t }}">{{ $labels[$t] }}</option>@endforeach
                                </select>
                            </label>
                        @endif
                    </article>
                @empty
                    <p class="wsa-board-empty">{{ $status === 'done' ? __('Nothing finished this week yet.') : __('Nothing here.') }}</p>
                @endforelse
                @if ($c['more'])<a class="wsa-board-more" href="{{ $listUrl($status) }}">{{ __(':count more in the list', ['count' => $c['more']]) }}</a>@endif
            </section>
        @endforeach
    </div>

    @if ($this->lastMove)
        <div class="wsa-board-undo" wire:key="undo-{{ implode('-', $this->lastMove) }}" x-data="{ show: true }" x-init="setTimeout(() => { show = false; $wire.set('lastMove', null, false) }, 6000)" x-show="show" x-transition.opacity role="status">
            <span>{{ __('Moved.') }}</span>
            <button type="button" wire:click="undoMove">{{ __('Undo the move') }}</button>
        </div>
    @endif
    <p class="wsa-meta wsa-board-hint">{{ __('Drag a card to another column, or select it and press 1-4. Enter opens it.') }}</p>
</div>
