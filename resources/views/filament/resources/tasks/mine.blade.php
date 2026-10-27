{{-- Fila „Ale mele" de pe Sarcini (omul ales deasupra, în layers):  active (în lucru, de făcut, blocate) și încheiate în ultimele 30 de zile, pe săptămâni. --}}
@php
    $data = $this->mine();
    $me = auth()->user();
    $person = $data['person'];
    $mineSelf = $person->is($me);
    $first = \Illuminate\Support\Str::before($person->name, ' ');
    $labels = ['doing' => __('In progress'), 'todo' => __('To do'), 'blocked' => __('Blocked ones')];
    $url = fn ($task) => \App\Support\TaskTrail::url($task);   // pagina sarcinii știe drumul înapoi la lista asta
    $activeCount = collect($data['groups'])->sum(fn ($g) => $g->count());
@endphp
{{-- trasul rândurilor între grupe (desktop): în lucru / de făcut pe loc; blocată și gata cu fereastra lor (dropTask); pe telefon rămân butoanele --}}
<div class="wsa-mine" x-data="{ dragging: null, over: null }" :class="{ 'is-dragging': dragging }"
    x-on:dragend.window="dragging = null; over = null">
    <section class="wsa-mine-section">
        <header class="wsa-mine-head">
            <h2>{{ $mineSelf ? __('Active ones') : __(':name\'s active tasks', ['name' => $first]) }}</h2>
            <span class="wsa-mine-count">{{ $activeCount }}</span>
        </header>
        @if ($activeCount === 0)
            <p class="wsa-empty">{{ $mineSelf ? __('Nothing open on you. Take a free one from “Free ones”, or enjoy the quiet.') : __(':name has nothing open.', ['name' => $first]) }}</p>
        @endif
        @foreach (['doing', 'todo', 'blocked'] as $status)
            @php($tasks = $data['groups'][$status] ?? collect())
            <div @class(['wsa-mine-drop', 'is-empty' => $tasks->isEmpty()]) :class="{ 'is-over': over === '{{ $status }}' }"
                x-on:dragover.prevent="if (dragging) over = '{{ $status }}'" x-on:drop.prevent="if (dragging) $wire.dropTask(dragging, '{{ $status }}'); dragging = null; over = null">
            <h3 class="wsa-mine-group">{{ $labels[$status] }} <span>{{ $tasks->count() }}</span></h3>
            @foreach ($tasks as $task)
                @php($due = $task->due())
                @php($list = $task->checklist())
                {{-- termenul, dintr-o privire: depășită = bară neagră în stânga și „Depășită de N zile" lângă număr; azi = „Azi" (fără roșu: roșul e al blocajelor) --}}
                <article @class(['wsa-mine-row', 'is-overdue' => $due['overdue'] ?? false, 'is-today' => $due['today'] ?? false]) wire:key="mine-{{ $task->id }}"
                    draggable="true" x-on:dragstart="dragging = {{ $task->id }}; $event.dataTransfer.effectAllowed = 'move'" :class="{ 'is-dragged': dragging === {{ $task->id }} }">
                    <span class="wsa-mark is-{{ $task->status->value }}" aria-hidden="true"></span>
                    <div class="wsa-mine-main">
                        <a href="{{ $url($task) }}" class="wsa-mine-title" draggable="false"><span class="wsa-id">#{{ $task->id }}@if ($due['overdue'] ?? false)<b class="wsa-due-flag is-overdue">{{ trans_choice('Overdue by :count day|Overdue by :count days', abs($due['days'])) }}</b>@elseif ($due['today'] ?? false)<b class="wsa-due-flag">{{ __('Due today') }}</b>@endif</span>{{ $task->title }}</a>
                        <p class="wsa-mine-meta">
                            <span>{{ $task->project?->name }}</span>
                            @if ($due && ! $due['overdue'] && ! $due['today'])<span class="wsa-due">{{ $due['label'] }}</span>@endif
                            @if ($list)<span>{{ $list['done'] }}/{{ $list['total'] }} {{ __('steps') }}</span>@endif
                            @if ($task->status === \App\Enums\TaskStatus::Blocked && $task->blocked_reason)<span class="wsa-mine-waiting">{{ __('Waiting for') }}: {{ \Illuminate\Support\Str::limit($task->blocked_reason, 80) }}</span>@endif
                        </p>
                    </div>
                    {{-- butoanele de pe rând: doar ce are sens și ce are voie omul (TaskPolicy), ca pe pagina sarcinii --}}
                    <div class="wsa-mine-actions">
                        <x-wsa.copy-task :url="\App\Filament\Resources\Tasks\TaskResource::getUrl('view', ['record' => $task])" :label="false" class="wsa-mine-btn is-icon" />
                        @if ($task->status === \App\Enums\TaskStatus::Todo && $me->can('move', [$task, \App\Enums\TaskStatus::Doing]))
                            <button type="button" class="wsa-mine-btn" wire:click="startTask({{ $task->id }})">{{ __('Start') }}</button>
                        @endif
                        @if ($task->status !== \App\Enums\TaskStatus::Blocked && $me->can('move', [$task, \App\Enums\TaskStatus::Blocked]))
                            <button type="button" class="wsa-mine-btn" wire:click="mountAction('mineBlock', { task: {{ $task->id }} })">{{ __('Block') }}</button>
                        @endif
                        @if ($me->can('move', [$task, \App\Enums\TaskStatus::Done]))
                            <button type="button" class="wsa-mine-btn is-done" wire:click="mountAction('mineDone', { task: {{ $task->id }} })">
                                {{ svg('heroicon-o-check', '', ['aria-hidden' => 'true']) }}{{ __('Mark done') }}
                            </button>
                        @endif
                    </div>
                </article>
            @endforeach
            </div>
        @endforeach
    </section>

    <section class="wsa-mine-section wsa-mine-drop is-finished" :class="{ 'is-over': over === 'done' }"
        x-on:dragover.prevent="if (dragging) over = 'done'" x-on:drop.prevent="if (dragging) $wire.dropTask(dragging, 'done'); dragging = null; over = null">
        <header class="wsa-mine-head">
            <h2>{{ __('Finished') }}</h2>
            <span class="wsa-mine-count">{{ $data['closed']->flatten()->count() }}</span>
            @if ($data['week'])
                <p class="wsa-mine-week">{{ $mineSelf ? trans_choice('You finished :count task this week.|You finished :count tasks this week.', $data['week']) : trans_choice(':name finished :count task this week.|:name finished :count tasks this week.', $data['week'], ['name' => $first]) }}</p>
            @endif
        </header>
        @forelse ($data['closed'] as $week => $tasks)
            <h3 class="wsa-mine-group">{{ $week }} <span>{{ $tasks->count() }}</span></h3>
            @foreach ($tasks as $task)
                <article @class(['wsa-mine-row', 'is-done', 'is-just-done' => $task->id === $this->justDone]) wire:key="mine-done-{{ $task->id }}"
                    draggable="true" x-on:dragstart="dragging = {{ $task->id }}; $event.dataTransfer.effectAllowed = 'move'" :class="{ 'is-dragged': dragging === {{ $task->id }} }">
                    {{ svg('heroicon-o-check', 'wsa-mine-check', ['aria-hidden' => 'true']) }}
                    <div class="wsa-mine-main">
                        <a href="{{ $url($task) }}" class="wsa-mine-title" draggable="false"><span class="wsa-id">#{{ $task->id }}</span>{{ $task->title }}</a>
                        <p class="wsa-mine-meta">
                            <span>{{ $task->project?->name }}</span>
                            <span><x-wsa.time :at="$task->done_at" /></span>
                            @if ($task->proof)<span class="wsa-mine-proof">{{ \App\Support\Markdown::inline(\Illuminate\Support\Str::limit($task->proof, 90)) }}</span>@endif
                            @if ($u = $data['inUpdate'][$task->id] ?? null)<a href="{{ \App\Filament\Resources\Updates\UpdateResource::getUrl('view', ['record' => $u]) }}" class="wsa-mine-update">{{ __('in the update of :date', ['date' => $u->created_at->copy()->setTimezone(auth()->user()->tz())->locale(app()->getLocale())->isoFormat('D MMM')]) }}</a>@endif
                        </p>
                    </div>
                    <div class="wsa-mine-actions">
                        @if ($me->can('move', [$task, \App\Enums\TaskStatus::Doing]))
                            <button type="button" class="wsa-mine-btn" wire:click="reopenTask({{ $task->id }})">{{ __('Reopen') }}</button>
                        @endif
                    </div>
                </article>
            @endforeach
        @empty
            <p class="wsa-empty">{{ __('Nothing finished in the last 30 days yet.') }}</p>
        @endforelse
    </section>

    {{-- ferestrele acțiunilor (Gata, Blochez): pe o pagină cu tabel le randează tabelul, care aici e ascuns --}}
    <x-filament-actions::modals />
</div>
