{{-- Azi, la client: ce așteaptă echipa de la el (sarcinile date lui). --}}
<x-filament-widgets::widget>
    <section class="wsa-block">
        <header class="wsa-block-head"><h2>{{ __('Waiting for you') }}</h2>@if ($tasks->isNotEmpty())<span>{{ trans_choice(':count task|:count tasks', $tasks->count()) }}</span>@endif</header>
        @forelse ($tasks as $task)
            @php($due = $task->due())
            <a href="{{ \App\Filament\Resources\Tasks\TaskResource::getUrl('view', ['record' => $task]) }}" class="wsa-row has-mark">
                <span class="wsa-mark is-{{ $task->status->value }}" aria-hidden="true"></span>
                <span class="wsa-row-title"><span class="wsa-id">#{{ $task->id }}</span>{{ $task->title }}</span>
                <span class="wsa-row-meta">@if ($due)<b @class(['wsa-due', 'is-overdue' => $due['overdue']])>{{ $due['overdue'] ? __('overdue, :when', ['when' => $due['label']]) : $due['label'] }}</b> · @endif{{ $task->project?->name }}</span>
            </a>
        @empty
            <p class="wsa-empty">{{ __('Nothing is waiting for you. The team will add a task here when they need something from you.') }}</p>
        @endforelse
    </section>
</x-filament-widgets::widget>
