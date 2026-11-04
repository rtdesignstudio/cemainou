{{--
    Panoul sarcinilor: de făcut, blocate, în lucru, gata săptămâna asta. Ordinea e automată (termenul, apoi cele mai noi). Pe calculator se trag cardurile
    între coloane; blocat cere motivul, gata are dovada opțională (dropTask, cu ferestrele de pe „Ale mele"); după fiecare mutare, „Anulează" câteva secunde.
    Tastatura: săgețile mută selecția, 1-4 trimit cardul în coloana aceea, Enter îl deschide. Pe telefon: o coloană odată, cu file; ținut apăsat, cardul deschide jos panoul cu celelalte stări (sau butonul „Mută").
--}}
@php
    $me = auth()->user();
    $columns = $this->board();
    $workers = $this->boardWorkers(collect($columns)->flatMap(fn ($c) => $c['tasks']->pluck('id'))->all());   // cine a mai lucrat pe fiecare
    $labels = ['todo' => __('To do'), 'blocked' => __('Blocked'), 'doing' => __('In progress'), 'done' => __('Done this week')];
    $url = fn ($task) => \App\Filament\Resources\Tasks\TaskResource::getUrl('view', ['record' => $task]);
    $listUrl = fn (string $status) => \App\Filament\Resources\Tasks\TaskResource::getUrl('index', array_filter(['view' => 'list', 'tab' => $status === 'done' ? 'done' : 'open', 'person' => $this->person, 'project' => $this->projectPick]));
    $initials = fn ($u) => collect(preg_split('/\s+/', trim($u->name)))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->join('');
    $first = collect($columns)->filter(fn ($c) => $c['total'] > 0)->keys()->first() ?? 'todo';
@endphp
<div class="wsa-board" x-data="{ dragging: null, over: null, col: @js($first), labels: @js($labels),
        hint: (() => { try { return ! localStorage.getItem('wsa.board.moved') } catch (e) { return true } })(),
        sheet: { id: null, title: '', targets: [], hover: null }, timer: null, sx: 0, sy: 0,
        press(el, e) { const t = e.touches[0]; this.sx = t.clientX; this.sy = t.clientY; clearTimeout(this.timer);
            if (! el.dataset.targets || el.dataset.targets === '[]') return;
            this.timer = setTimeout(() => { this.sheet = { id: +el.dataset.id, title: el.dataset.title, targets: JSON.parse(el.dataset.targets), hover: null }; navigator.vibrate?.(12) }, 380) },
        slide(e) { const t = e.touches[0];
            if (! this.sheet.id) { if (Math.abs(t.clientX - this.sx) + Math.abs(t.clientY - this.sy) > 10) clearTimeout(this.timer); return }
            e.preventDefault(); this.sheet.hover = document.elementFromPoint(t.clientX, t.clientY)?.closest('[data-zone]')?.dataset.zone ?? null },
        lift() { clearTimeout(this.timer); if (this.sheet.id && this.sheet.hover) this.drop(this.sheet.hover) },
        cols: @js(array_keys($columns)), swipe: null, slideDir: 0,
        swipeStart(e) { if (this.sheet.id || innerWidth >= 640) return; const t = e.touches[0]; this.swipe = { x: t.clientX, y: t.clientY } },
        swipeEnd(e) { if (! this.swipe || this.sheet.id) { this.swipe = null; return } const t = e.changedTouches[0], dx = t.clientX - this.swipe.x, dy = t.clientY - this.swipe.y; this.swipe = null;
            if (Math.abs(dx) < 60 || Math.abs(dx) < Math.abs(dy) * 1.5) return; this.goCol(dx < 0 ? 1 : -1) },
        goCol(dir) { const i = this.cols.indexOf(this.col) + dir; if (i < 0 || i >= this.cols.length) return; this.slideDir = dir; this.col = this.cols[i];
            this.$nextTick(() => this.$root.querySelector('.wsa-board-tabs .is-on')?.scrollIntoView({ inline: 'center', block: 'nearest', behavior: 'smooth' })) },
        drop(to) { const id = this.sheet.id; this.sheet = { id: null, title: '', targets: [], hover: null }; if (! id) return; $wire.dropTask(id, to);
            this.hint = false; try { localStorage.setItem('wsa.board.moved', '1') } catch (e) {} },
        go(card, dir) { const cards = [...card.closest('.wsa-board-col').querySelectorAll('.wsa-card')]; const next = cards[cards.indexOf(card) + dir]; next?.focus() },
        side(card, dir) { const cols = [...this.$root.querySelectorAll('.wsa-board-col')]; const i = cols.indexOf(card.closest('.wsa-board-col')); for (let k = i + dir; k >= 0 && k < cols.length; k += dir) { const c = cols[k].querySelector('.wsa-card'); if (c) { c.focus(); return } } } }"
    :class="{ 'is-dragging': dragging }" x-on:dragend.window="dragging = null; over = null">

    <nav class="wsa-board-tabs" aria-label="{{ __('Columns') }}">
        @foreach ($columns as $status => $c)
            <button type="button" :class="{ 'is-on': col === '{{ $status }}' }" x-on:click="slideDir = cols.indexOf('{{ $status }}') > cols.indexOf(col) ? 1 : -1; col = '{{ $status }}'">{{ $labels[$status] }} <span>{{ $c['total'] }}</span></button>
        @endforeach
    </nav>

    {{-- telefon: până la prima mutare, un îndemn scurt (ținut minte în browser) --}}
    <p class="wsa-board-nudge" x-show="hint" x-cloak>
        {{ svg('heroicon-o-hand-raised', 'wsa-card-ico', ['aria-hidden' => 'true']) }}
        <span>{{ __('Hold a task card, then slide your finger onto its new state.') }}</span>
        <button type="button" x-on:click="hint = false; try { localStorage.setItem('wsa.board.moved', '1') } catch (e) {}" aria-label="{{ __('Close') }}">×</button>
    </p>

    {{-- telefon: glisarea stânga-dreapta trece la coloana următoare / anterioară (ținutul apăsat rămâne pentru mutarea cardului) --}}
    <div class="wsa-board-cols" x-on:touchstart.passive="swipeStart($event)" x-on:touchend="swipeEnd($event)" :data-slide="slideDir">
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
                        // ce contează în fiecare stare: cine a cerut-o (de făcut), de când așteaptă (blocată), cât s-a lucrat și ultima mișcare (în lucru),
                        // timpul consumat și în câte zile (gata); timpul măsurat vine din sesiunile pornite pe sarcină (/work)
                        $st = $task->status;
                        $asked = $task->creator && $task->created_by !== $task->assignee_id ? $task->creator : null;
                        $time = $st !== \App\Enums\TaskStatus::Todo && ($st !== \App\Enums\TaskStatus::Done || $task->time_minutes || $task->done_at?->gt(now()->subDays(89))) ? \App\Support\TaskTime::people($task) : null;
                        $spent = $time['total'] ?? null;
                        $byPerson = count($time['people'] ?? []) > 1 ? $time['people'] : [];    // cât a lucrat fiecare, când au fost mai mulți
                        $took = $st === \App\Enums\TaskStatus::Done && $task->done_at ? max(1, (int) ceil(($task->started_at ?? $task->created_at)->diffInHours($task->done_at) / 24)) : null;
                        $lastComment = $task->comments_max_created_at ? \Illuminate\Support\Carbon::parse($task->comments_max_created_at, 'UTC') : null;
                        // meniul „⋯": ce are sens în starea asta și ce are voie omul (aceleași drepturi ca pe pagina sarcinii)
                        $open = $st->isOpen();
                        $menu = array_values(array_filter([
                            $st === \App\Enums\TaskStatus::Todo && $targets->contains('doing') ? [__('Start'), "\$wire.dropTask({$task->id}, 'doing')"] : null,
                            $st === \App\Enums\TaskStatus::Blocked && $targets->contains('doing') ? [__('Unblock: back in progress'), "\$wire.dropTask({$task->id}, 'doing')"] : null,
                            $st === \App\Enums\TaskStatus::Blocked && $me->can('move', [$task, \App\Enums\TaskStatus::Blocked]) ? [__('Change what it waits for'), "\$wire.mountAction('mineBlock', { task: {$task->id} })"] : null,
                            $open && $targets->contains('done') ? [__('Mark done'), "\$wire.dropTask({$task->id}, 'done')"] : null,
                            $st === \App\Enums\TaskStatus::Doing && $targets->contains('todo') ? [__('Back to to do'), "\$wire.dropTask({$task->id}, 'todo')"] : null,
                            in_array($st, [\App\Enums\TaskStatus::Todo, \App\Enums\TaskStatus::Doing], true) && $targets->contains('blocked') ? [__('Block…'), "\$wire.dropTask({$task->id}, 'blocked')"] : null,
                            $open && $me->can('assign', $task) ? [__('Give it to someone…'), "\$wire.mountAction('boardAssign', { task: {$task->id} })"] : null,
                            $open && $me->can('edit', $task) ? [__('Due date…'), "\$wire.mountAction('boardDue', { task: {$task->id} })"] : null,
                            ! $open && $me->can('move', [$task, \App\Enums\TaskStatus::Doing]) ? [__('Reopen'), "\$wire.reopenTask({$task->id})"] : null,
                            [__('Copy the link'), "navigator.clipboard?.writeText(\$el.closest('.wsa-card').dataset.href.startsWith('http') ? \$el.closest('.wsa-card').dataset.href : location.origin + \$el.closest('.wsa-card').dataset.href)"],
                            $open && $me->can('move', [$task, \App\Enums\TaskStatus::Dropped]) ? [__('Drop…'), "\$wire.mountAction('boardDrop', { task: {$task->id} })", true] : null,
                        ]));
                    @endphp
                    @php($overdue = $due['overdue'] ?? false)
                    <article @class(['wsa-card', 'is-overdue' => $overdue, 'is-blocked' => $task->status === \App\Enums\TaskStatus::Blocked, 'is-done' => $task->status === \App\Enums\TaskStatus::Done]) wire:key="card-{{ $task->id }}" tabindex="0" data-href="{{ $url($task) }}" data-id="{{ $task->id }}" data-title="#{{ $task->id }} {{ $task->title }}" data-targets="{{ $targets->toJson() }}"
                        x-on:touchstart="press($el, $event)" x-on:touchmove="slide($event)" x-on:touchend="lift()" x-on:touchcancel="lift()"
                        @if ($targets->isNotEmpty()) draggable="true" x-on:dragstart="dragging = {{ $task->id }}; $event.dataTransfer.effectAllowed = 'move'" @endif
                        :class="{ 'is-dragged': dragging === {{ $task->id }} }"
                        x-on:keydown.enter.self.prevent="Livewire.navigate($el.dataset.href)"
                        x-on:keydown.arrow-down.self.prevent="go($el, 1)" x-on:keydown.arrow-up.self.prevent="go($el, -1)"
                        x-on:keydown.arrow-right.self.prevent="side($el, 1)" x-on:keydown.arrow-left.self.prevent="side($el, -1)"
                        x-on:keydown.self="const s = @js(\App\Filament\Resources\Tasks\Pages\ListTasks::BOARD)[+$event.key - 1]; if (s && @js($targets->all()).includes(s)) { $event.preventDefault(); $wire.dropTask({{ $task->id }}, s) }">
                        {{-- sus: numărul, de unde vine (client, nouă pentru tine) și termenul; depășită = bara neagră din stânga, ca în „Ale mele” (roșul e al blocajelor) --}}
                        <div class="wsa-card-top">
                            <span class="wsa-id">#{{ $task->id }}</span>
                            @if ($task->fromClient())<span class="wsa-card-tag">{{ __('from the client') }}</span>@endif
                            @if ($task->isNew() && $task->assignee_id === $me->id)<span class="wsa-card-tag is-new">{{ __('new') }}</span>@endif
                            @if ($due)<span @class(['wsa-card-due', 'is-overdue' => $overdue, 'is-today' => $due['today'] ?? false])>{{ $overdue ? trans_choice('Overdue by :count day|Overdue by :count days', abs($due['days'])) : (($due['today'] ?? false) ? __('Due today') : $due['label']) }}</span>@endif
                            <span class="wsa-card-menu" x-data="{ menu: false }" x-on:click.outside="menu = false" x-on:keydown.escape.stop="menu = false" x-on:touchstart.stop>
                                <button type="button" class="wsa-card-dots" x-on:click.stop="menu = ! menu" :aria-expanded="menu" aria-label="{{ __('Actions for #:id', ['id' => $task->id]) }}">{{ svg('heroicon-o-ellipsis-horizontal', 'wsa-card-ico', ['aria-hidden' => 'true']) }}</button>
                                <span class="wsa-card-menu-list" x-show="menu" x-cloak x-transition.opacity.duration.100ms role="menu">
                                    @foreach ($menu as $item)
                                        <button type="button" role="menuitem" @class(['is-danger' => $item[2] ?? false]) x-on:click.stop="menu = false; {{ $item[1] }}">{{ $item[0] }}</button>
                                    @endforeach
                                </span>
                            </span>
                        </div>
                        <a href="{{ $url($task) }}" class="wsa-card-title" draggable="false">@if ($task->status === \App\Enums\TaskStatus::Done){{ svg('heroicon-o-check', 'wsa-card-check', ['aria-hidden' => 'true']) }}@endif{{ $task->title }}</a>
                        @if ($task->status === \App\Enums\TaskStatus::Done && $task->proof)<p class="wsa-card-proof" title="{{ $task->proof }}">{{ __('Proof') }}: {{ \Illuminate\Support\Str::limit($task->proof, 80) }}</p>@endif
                        @if ($task->status === \App\Enums\TaskStatus::Blocked)
                            <p class="wsa-card-reason">
                                <span>{{ __('Waiting for') }}: {{ \Illuminate\Support\Str::limit($task->blocked_reason ?: __('not said'), 90) }}</span>
                                @php($days = (int) \App\Filament\Widgets\Blockers::blockedSince($task)->diffInDays(now()))
                                <small>{{ $days ? trans_choice('blocked for :count day|blocked for :count days', $days) : __('blocked today') }}</small>
                            </p>
                        @endif
                        @if ($steps)
                            <div class="wsa-card-steps" title="{{ $steps['done'] }}/{{ $steps['total'] }} {{ __('steps') }}">
                                <span class="wsa-card-bar"><i style="width: {{ $steps['total'] ? round($steps['done'] * 100 / $steps['total']) : 0 }}%"></i></span>
                                <small>{{ $steps['done'] }}/{{ $steps['total'] }} {{ __('steps') }}</small>
                            </div>
                        @endif
                        @if ($st === \App\Enums\TaskStatus::Done && ($spent || $took))
                            <p class="wsa-card-facts is-done">
                                @if ($spent)<span><b>{{ \App\Support\WorkTime::format($spent) }}</b> {{ __('worked') }}</span>@endif
                                @if ($took)<span>{{ trans_choice('in :count day|in :count days', $took) }}</span>@endif
                            </p>
                            @if ($byPerson)<p class="wsa-card-people">@foreach ($byPerson as $p)<span>{{ \Illuminate\Support\Str::before($p['name'].' ', ' ') }} <b>{{ \App\Support\WorkTime::format($p['minutes']) }}</b></span>@endforeach</p>@endif
                        @elseif ($st === \App\Enums\TaskStatus::Doing && ($spent || $lastComment))
                            <p class="wsa-card-facts">
                                @if ($spent)<span><b>{{ \App\Support\WorkTime::format($spent) }}</b> {{ __('worked so far') }}</span>@endif
                                @if ($lastComment)<span>{{ __('last comment :when', ['when' => $lastComment->diffForHumans()]) }}</span>@endif
                            </p>
                            @if ($byPerson)<p class="wsa-card-people">@foreach ($byPerson as $p)<span>{{ \Illuminate\Support\Str::before($p['name'].' ', ' ') }} <b>{{ \App\Support\WorkTime::format($p['minutes']) }}</b></span>@endforeach</p>@endif
                        @elseif ($st === \App\Enums\TaskStatus::Todo && $asked)
                            <p class="wsa-card-facts"><span>{{ __('asked by :name', ['name' => $asked->id === $me->id ? __('you') : $asked->name]) }}</span></p>
                        @endif
                        <div class="wsa-card-meta">
                            @unless ($this->projectPick)<span class="wsa-card-project">{{ $task->project?->name }}</span>@endunless
                            @if ($task->comments_count)<span title="{{ trans_choice(':count comment|:count comments', $task->comments_count) }}">{{ svg('heroicon-o-chat-bubble-left', 'wsa-card-ico', ['aria-hidden' => 'true']) }}{{ $task->comments_count }}</span>@endif
                            @if ($task->attachments_count)<span title="{{ trans_choice(':count file|:count files', $task->attachments_count) }}">{{ svg('heroicon-o-paper-clip', 'wsa-card-ico', ['aria-hidden' => 'true']) }}{{ $task->attachments_count }}</span>@endif
                        </div>
                        {{-- jos: omul și de cât timp e aici; la trecerea mouse-ului, pașii următori (doar ce are voie omul) --}}
                        <div class="wsa-card-foot">
                            @if ($task->assignee)
                                <span class="wsa-card-who" title="{{ $task->assignee->name }}"><b>{{ $initials($task->assignee) }}</b>{{ $task->assignee->id === $me->id ? __('Me') : \Illuminate\Support\Str::before($task->assignee->name, ' ') }}</span>
                            @else
                                <span class="wsa-card-who is-free"><b>–</b>{{ __('Free') }}</span>
                            @endif
                            @php($others = collect($workers[$task->id] ?? [])->reject(fn ($u) => $u->id === $task->assignee_id)->values())
                            @if ($others->isNotEmpty())
                                <span class="wsa-card-helpers" title="{{ __('Also worked on it: :names', ['names' => $others->pluck('name')->join(', ')]) }}">
                                    @foreach ($others->take(3) as $u)<b>{{ $initials($u) }}</b>@endforeach
                                    @if ($others->count() > 3)<b class="is-more">+{{ $others->count() - 3 }}</b>@endif
                                </span>
                            @endif
                            <small class="wsa-card-age">
                                @if ($task->status === \App\Enums\TaskStatus::Done){{ __('done :when', ['when' => $task->done_at->diffForHumans()]) }}
                                @elseif ($task->status === \App\Enums\TaskStatus::Doing && $task->started_at){{ __('in progress :when', ['when' => $task->started_at->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE)]) }}
                                @else{{ __('added :when', ['when' => $task->created_at->diffForHumans()]) }}@endif
                            </small>
                            <span class="wsa-card-acts">
                                @if ($targets->contains('doing') && $task->status === \App\Enums\TaskStatus::Todo)<button type="button" wire:click="dropTask({{ $task->id }}, 'doing')">{{ __('Start') }}</button>@endif
                                @if ($targets->contains('done'))<button type="button" class="is-done" wire:click="dropTask({{ $task->id }}, 'done')" title="{{ __('Mark done') }}">{{ svg('heroicon-o-check', 'wsa-card-ico', ['aria-hidden' => 'true']) }}</button>@endif
                            </span>
                        </div>
                        @if ($targets->isNotEmpty())
                            <button type="button" class="wsa-card-move" x-on:click="sheet = { id: {{ $task->id }}, title: $el.closest('.wsa-card').dataset.title, targets: @js($targets->all()), hover: null }">{{ __('Move') }}</button>
                        @endif
                    </article>
                @empty
                    <p class="wsa-board-empty">{{ $status === 'done' ? __('Nothing finished this week yet.') : __('Nothing here.') }}</p>
                @endforelse
                @if ($c['more'])<a class="wsa-board-more" href="{{ $listUrl($status) }}">{{ __(':count more in the list', ['count' => $c['more']]) }}</a>@endif
            </section>
        @endforeach
    </div>

    {{-- telefon: ținut apăsat pe un card, jos apar celelalte stări; tragi degetul pe una și-l ridici, sau o atingi --}}
    <div class="wsa-board-sheet" x-show="sheet.id" x-cloak x-transition.opacity x-on:click.self="sheet.id = null" x-on:keydown.escape.window="sheet.id = null">
        <div class="wsa-board-sheet-panel" role="dialog" aria-modal="true" :aria-label="sheet.title">
            <p class="wsa-board-sheet-title" x-text="sheet.title"></p>
            <div class="wsa-board-sheet-zones">
                <template x-for="t in sheet.targets" :key="t">
                    <button type="button" :data-zone="t" :class="{ 'is-over': sheet.hover === t, 'is-blocked': t === 'blocked', 'is-done': t === 'done' }" x-on:click="drop(t)" x-text="labels[t]"></button>
                </template>
            </div>
            <button type="button" class="wsa-board-sheet-cancel" x-on:click="sheet.id = null">{{ __('Cancel') }}</button>
        </div>
    </div>

    {{-- tabelul e ascuns pe panou, deci ferestrele acțiunilor (blocat, gata, cine o face, termenul) le desenează panoul, ca „Ale mele” --}}
    <x-filament-actions::modals />
    <p class="wsa-meta wsa-board-hint">{{ __('Drag a card to another column, or select it and press 1-4. Enter opens it.') }}</p>
</div>
