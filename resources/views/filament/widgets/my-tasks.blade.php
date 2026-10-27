<x-filament-widgets::widget>
    <section class="wsa-block">
        <header class="wsa-block-head">
            <h2>{{ __('My tasks') }}</h2>
            <a href="{{ $allUrl }}" class="wsa-link">{{ __('All tasks') }}<x-wsa.arrow /></a>
        </header>
        @forelse ($tasks as $task)
            <a href="{{ \App\Filament\Resources\Tasks\TaskResource::getUrl('view', ['record' => $task]) }}" class="wsa-task">
                <span class="wsa-mark is-{{ $task->status->value }}" title="{{ $task->status->getLabel() }}"><span class="sr-only">{{ $task->status->getLabel() }}</span></span>
                <span class="wsa-task-title"><span class="wsa-id">#{{ $task->id }}</span>{{ $task->title }}@if ($task->isNew())<span class="wsa-new">{{ __('New') }}</span>@endif</span>
                <span class="wsa-meta">{{ $task->project?->name }}</span>
            </a>
        @empty
            <p class="wsa-empty">{{ __('No open tasks. Take a free one from a project or add your own.') }}</p>
        @endforelse
        @if ($tasks->isNotEmpty())
            <x-wsa.task-legend />
        @endif
    </section>
</x-filament-widgets::widget>
