<section class="wsa-block" id="tasks">
    <header class="wsa-block-head">
        <h2>{{ __('Tasks') }}</h2>
        <span>{{ __(':open open · :free free', ['open' => $openCount, 'free' => $freeCount]) }}</span>
    </header>

    {{-- o sarcină nouă: primul rând e titlul, dedesubt detaliile; omul, fișierele (capturi, video), apoi „Adaugă" (⌘/Ctrl + Enter) --}}
    <form wire:submit="add" class="wsa-task-new">
        <div class="wsa-kind-switch" role="radiogroup" aria-label="{{ __('What is it') }}">
            <button type="button" role="radio" aria-checked="{{ $kind === 'task' ? 'true' : 'false' }}" @class(['is-on' => $kind === 'task']) wire:click="$set('kind', 'task')">{{ __('Task') }}</button>
            <button type="button" role="radio" aria-checked="{{ $kind === 'idea' ? 'true' : 'false' }}" @class(['is-on' => $kind === 'idea']) wire:click="$set('kind', 'idea')">{{ __('Idea') }}</button>
        </div>
        {{ $this->form }}
        <div @class(['wsa-task-new-row', 'is-single' => auth()->user()->isClient() || $kind === 'idea'])>
            @unless (auth()->user()->isClient() || $kind === 'idea')                    {{-- de la client, sarcina ajunge la liderul proiectului; ideea n-are om --}}
                <select wire:model="assignee" aria-label="{{ __('Who does it') }}">
                    <option value="">{{ __('Free') }}</option>
                    @foreach ($people as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            @endunless
            <label class="wsa-feedback-files">
                <input type="file" wire:model="files" multiple class="sr-only">
                <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedPaperClip" class="wsa-feedback-clip" aria-hidden="true" />
                <span>{{ __('Add screenshots, a video or a file') }}</span>
            </label>
        </div>
        <p class="wsa-meta" wire:loading wire:target="files">{{ __('Uploading…') }}</p>
        @foreach ($files as $i => $file)
            <p class="wsa-feedback-file" wire:key="task-file-{{ $i }}">
                <span>{{ $file->getClientOriginalName() }}</span>
                <button type="button" class="wsa-inline-btn" wire:click="removeFile({{ $i }})">{{ __('Remove') }}</button>
            </p>
        @endforeach
        @error('data.text') <p class="wsa-error">{{ $message }}</p> @enderror
        @error('files') <p class="wsa-error">{{ $message }}</p> @enderror
        @error('files.*') <p class="wsa-error">{{ $message }}</p> @enderror
        <x-filament::button type="submit" class="wsa-feedback-send" wire:target="add,files">{{ $kind === 'idea' ? __('Add the idea') : __('Add the task') }}</x-filament::button>
    </form>

    @foreach ($groups as $label => $items)
        @if ($items->isNotEmpty())
            <div class="wsa-group">{{ $label }}</div>
            @foreach ($items as $task)
                <div @class(['wsa-task', 'is-done' => $task->status === \App\Enums\TaskStatus::Done]) wire:key="task-{{ $task->id }}">
                    <span class="wsa-mark is-{{ $task->status->value }}" title="{{ $task->status->getLabel() }}"><span class="sr-only">{{ $task->status->getLabel() }}</span></span>
                    <a href="{{ \App\Filament\Resources\Tasks\TaskResource::getUrl('view', ['record' => $task]) }}" class="wsa-task-title">
                        <span class="wsa-id">#{{ $task->id }}</span>{{ $task->title }}
                        @if ($task->fromClient())
                            <span class="wsa-meta"> · {{ __('from the client') }}</span>
                        @endif
                        @if ($task->status === \App\Enums\TaskStatus::Blocked && $task->blocked_reason)
                            <span class="wsa-meta"> · {{ \App\Support\Markdown::inline($task->blocked_reason, links: false) }}</span>
                        @endif
                    </a>
                    @if ($task->status === \App\Enums\TaskStatus::Idea && auth()->user()->can('move', [$task, \App\Enums\TaskStatus::Todo]))
                        <button type="button" wire:click="promote({{ $task->id }})" wire:loading.attr="disabled" wire:target="promote({{ $task->id }})" class="wsa-take">{{ __('Make it a task') }}</button>
                    @elseif (! $task->assignee_id && $task->status->isOpen() && auth()->user()->can('take', $task))
                        <button type="button" wire:click="take({{ $task->id }})" wire:loading.attr="disabled" wire:target="take({{ $task->id }})" class="wsa-take">{{ __('Take it') }}</button>
                    @else
                        <span class="wsa-meta">@if ($due = $task->due())<span @class(['wsa-due', 'is-overdue' => $due['overdue']])>{{ $due['label'] }}</span> · @endif @if ($task->repeat){{ $task->repeat->short() }} · @endif @if ($list = $task->checklist()){{ $list['done'] }}/{{ $list['total'] }} · @endif{{ $task->assignee?->name }} · <x-wsa.time :at="($task->done_at ?? $task->created_at)" /></span>
                    @endif
                </div>
            @endforeach
        @endif
    @endforeach

    @if ($empty)
        <p class="wsa-empty">{{ __('No tasks yet. Add the first one above.') }}</p>
    @endif

    {{-- tot ce e al proiectului: încheiatele (aici doar cele din ultimele 7 zile) și ideile, în lista Sarcini filtrată pe el --}}
    @if ($doneTotal || ($ideasTotal && ! auth()->user()->isClient()))
        <p class="wsa-task-more">
            @if ($doneTotal)<a href="{{ \App\Filament\Resources\Tasks\TaskResource::getUrl('index', ['tab' => 'done', 'project' => $project->id]) }}" class="wsa-link">{{ __('All finished (:count)', ['count' => $doneTotal]) }}<x-wsa.arrow /></a>@endif
            @if ($ideasTotal && ! auth()->user()->isClient())<a href="{{ \App\Filament\Resources\Tasks\TaskResource::getUrl('index', ['tab' => 'ideas', 'project' => $project->id]) }}" class="wsa-link">{{ __('All ideas (:count)', ['count' => $ideasTotal]) }}<x-wsa.arrow /></a>@endif
        </p>
    @endif
</section>
