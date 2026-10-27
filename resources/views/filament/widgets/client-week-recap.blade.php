{{-- Vinerea, la client: săptămâna pe proiectele lui. --}}
<x-filament-widgets::widget>
    <section class="wsa-block wsa-week is-recap">
        <header class="wsa-block-head"><h2>{{ __('Your week with the team') }}</h2><x-wsa.close wire:click="hide" :title="__('Close')" /></header>
        <p class="wsa-week-lead">{{ trans_choice('This week the team finished :count task for you|This week the team finished :count tasks for you', $done->count()) }}{{ count($projects) ? ', '.__('on :projects', ['projects' => implode(', ', array_slice($projects, 0, 3))]) : '' }}. {{ __('Have a good weekend.') }}</p>
        <a href="{{ route('story') }}" class="wsa-story-link">{{ __('Watch your week in 30 seconds') }}<x-wsa.arrow /></a>
        <div class="wsa-week-figures">
            <div><span class="wsa-label">{{ __('Finished') }}</span><b>{{ $done->count() }}</b></div>
            <div><span class="wsa-label">{{ __('Updates') }}</span><b>{{ $updates }}</b></div>
            <div><span class="wsa-label">{{ __('Projects') }}</span><b>{{ count($projects) }}</b></div>
        </div>
        <ul class="wsa-week-done">
            @foreach ($done->take(5) as $task)
                <li>
                    {{ svg('heroicon-o-check', '', ['aria-hidden' => 'true']) }}
                    <a href="{{ \App\Filament\Resources\Tasks\TaskResource::getUrl('view', ['record' => $task]) }}">{{ $task->title }}</a>
                    <span class="wsa-meta">{{ $task->project?->name }}</span>
                </li>
            @endforeach
        </ul>
    </section>
</x-filament-widgets::widget>
