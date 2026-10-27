{{--
    „Întârziate": sarcinile cu termenul trecut, grupate pe om (ale mele; liderului, și ale colegilor de pe proiectele lui). Fiecare rând: titlul
    (cu #id la aceeași mărime), cât e de întârziată și proiectul, apoi mutarea termenului. Pe telefon, din fiecare grup se văd primele 3, restul la „Încă N".
--}}
<x-filament-widgets::widget>
    @if ($tasks->isNotEmpty())
        <section class="wsa-block wsa-late">
            <header class="wsa-block-head">
                <h2>{{ __('Late') }} <span class="wsa-late-count">{{ $tasks->count() }}</span></h2>
                <span>{{ __('past their due date') }}</span>
            </header>
            @foreach ($tasks->groupBy(fn ($t) => $t->assignee_id ?? 0)->sortBy(fn ($g, $id) => $id === $user->id ? 0 : 1) as $assigneeId => $group)   {{-- ale mele primele --}}
                <div class="wsa-late-group" x-data="{ open: false }" wire:key="late-group-{{ $assigneeId }}">
                    @if ($tasks->pluck('assignee_id')->unique()->count() > 1 || $assigneeId !== $user->id)
                        <p class="wsa-late-who">{{ $assigneeId === $user->id ? __('Yours') : ($group->first()->assignee?->name ?? __('Free')) }} · {{ trans_choice('{1} :count late|[2,*] :count late', $group->count()) }}</p>
                    @endif
                    @foreach ($group as $i => $task)
                        @php $days = max(1, (int) \Illuminate\Support\Carbon::parse($task->due_on->toDateString())->diffInDays(\Illuminate\Support\Carbon::parse($today->toDateString()))); @endphp
                        <div @class(['wsa-late-row', 'is-more' => $loop->index >= 3]) x-bind:class="{ 'is-open': open }" wire:key="late-{{ $task->id }}">
                            <a href="{{ \App\Filament\Resources\Tasks\TaskResource::getUrl('view', ['record' => $task]) }}" class="wsa-late-title">
                                <span class="wsa-late-name"><span class="wsa-late-id">#{{ $task->id }}</span> {{ $task->title }}</span>
                            </a>
                            <span class="wsa-late-meta"><b data-tip="{{ __('Due :date', ['date' => $task->due_on->locale(app()->getLocale())->isoFormat('dddd D MMMM')]) }}">{{ trans_choice('{1} late by a day|[2,*] late by :count days', $days) }}</b>@if ($task->project) · {{ $task->project->name }}@endif</span>
                            @if ($user->can('edit', $task) || $user->can('move', [$task, $task->status]))
                                <span class="wsa-late-actions">
                                    <button type="button" wire:click="reschedule({{ $task->id }}, 'tomorrow')" wire:loading.attr="disabled">{{ __('Tomorrow') }}</button>
                                    <button type="button" wire:click="reschedule({{ $task->id }}, 'monday')" wire:loading.attr="disabled">{{ __('Next Monday') }}</button>
                                    <button type="button" wire:click="reschedule({{ $task->id }}, 'none')" wire:loading.attr="disabled">{{ __('No due date') }}</button>
                                </span>
                            @endif
                        </div>
                    @endforeach
                    @if ($group->count() > 3)
                        <button type="button" class="wsa-late-more" x-show="! open" x-on:click="open = true">{{ trans_choice('{1} :count more|[2,*] :count more', $group->count() - 3) }}</button>
                    @endif
                </div>
            @endforeach
        </section>
    @endif
</x-filament-widgets::widget>
