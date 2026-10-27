{{-- Azi, la client: ce s-a făcut pe proiectele lui în ultimele 7 zile. --}}
<x-filament-widgets::widget>
    <section class="wsa-block">
        <header class="wsa-block-head"><h2>{{ __('Done this week') }}</h2><span>{{ __('the last 7 days') }}</span></header>
        @if ($done->isNotEmpty())
            <p class="wsa-week-lead">{{ trans_choice('The team finished :count task for you|The team finished :count tasks for you', $done->count()) }}{{ count($projects) ? ', '.__('on :projects', ['projects' => implode(', ', array_slice($projects, 0, 3))]) : '' }}.</p>
            <ul class="wsa-week-done">
                @foreach ($done->take(6) as $task)
                    <li>
                        {{ svg('heroicon-o-check', '', ['aria-hidden' => 'true']) }}
                        <a href="{{ \App\Filament\Resources\Tasks\TaskResource::getUrl('view', ['record' => $task]) }}">{{ $task->title }}</a>
                        <span class="wsa-meta"><x-wsa.time :at="$task->done_at" /></span>
                    </li>
                @endforeach
            </ul>
        @endif
        @foreach ($updates as $update)
            <a href="{{ \App\Filament\Resources\Updates\UpdateResource::getUrl('view', ['record' => $update]) }}" class="wsa-row">
                <span class="wsa-row-title">{{ \App\Support\Markdown::plain($update->summary) }}</span>
                <span class="wsa-row-meta">{{ $update->user?->name }} · {{ $update->project?->name }} · <x-wsa.time :at="$update->created_at" /></span>
            </a>
        @endforeach
        @if ($done->isEmpty() && $updates->isEmpty())
            <p class="wsa-empty">{{ __('Nothing reported this week yet. The team\'s updates and finished tasks show up here.') }}</p>
        @endif
    </section>
</x-filament-widgets::widget>
