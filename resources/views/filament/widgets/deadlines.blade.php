{{-- Azi → Termenele săptămânii: ale mele și cele libere, de azi până duminică. --}}
<x-filament-widgets::widget>
    <section class="wsa-block">
        <header class="wsa-block-head"><h2>{{ __('Due this week') }}</h2></header>
        @forelse ($tasks as $task)
            <a href="{{ \App\Filament\Resources\Tasks\TaskResource::getUrl('view', ['record' => $task]) }}" class="wsa-row has-mark">
                <span class="wsa-mark is-{{ $task->status->value }}" aria-hidden="true"></span>
                <span class="wsa-row-title"><span class="wsa-id">#{{ $task->id }}</span>{{ $task->title }}</span>
                <span class="wsa-row-meta"><b>{{ $task->due()['label'] ?? '' }}</b> · {{ $task->project?->name }}{{ $task->assignee ? '' : ' · '.__('free') }}</span>
            </a>
        @empty
            <p class="wsa-empty">{{ __('Nothing due until Sunday.') }}</p>
        @endforelse
    </section>
</x-filament-widgets::widget>
