{{-- Azi, la client: cererile lui, cu starea fiecăreia. --}}
<x-filament-widgets::widget>
    <section class="wsa-block">
        <header class="wsa-block-head">
            <h2>{{ __('Your requests') }}</h2>
            <button type="button" class="wsa-link" x-data x-on:click="$dispatch('wsa-capture', { kind: 'task' })">{{ __('New request') }}<x-wsa.arrow /></button>
        </header>
        @forelse ($tasks as $task)
            <a href="{{ \App\Filament\Resources\Tasks\TaskResource::getUrl('view', ['record' => $task]) }}" class="wsa-row has-mark">
                <span class="wsa-mark is-{{ $task->status->value }}" aria-hidden="true"></span>
                <span class="wsa-row-title"><span class="wsa-id">#{{ $task->id }}</span>{{ $task->title }}</span>
                <span class="wsa-row-meta"><b>{{ $task->status->getLabel() }}</b>{{ $task->assignee && $task->status->isOpen() ? ' · '.$task->assignee->name : '' }} · {{ $task->project?->name }}</span>
            </a>
        @empty
            <p class="wsa-empty">{{ __('No requests yet. Something to change on the site? Add it with “New request”: it goes straight to the project lead.') }}</p>
        @endforelse
    </section>
</x-filament-widgets::widget>
