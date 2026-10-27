{{-- Azi → Radar (lider): clienții care așteaptă, termenele fără mișcare, gata fără dovadă - fiecare cu ce se poate face pe loc --}}
<x-filament-widgets::widget>
    <section class="wsa-block wsa-radar">
        <header class="wsa-block-head"><h2>{{ __('Radar') }}</h2><span>{{ __('on the projects you lead') }}</span></header>
        @if ($waiting->isEmpty() && $still->isEmpty() && $unproven->isEmpty())
            <p class="wsa-empty">{{ __('Nothing needs you: clients answered, deadlines moving, tasks closed with proof.') }}</p>
        @endif
        @if ($waiting->isNotEmpty())
            <div class="wsa-radar-group">
                <span class="wsa-label">{{ __('Clients waiting for an answer') }}</span>
                @foreach ($waiting as $task)
                    <div class="wsa-radar-row" wire:key="rw-{{ $task->id }}">
                        <a href="{{ \App\Filament\Resources\Tasks\TaskResource::getUrl('view', ['record' => $task]) }}#comments" class="wsa-radar-title"><span class="wsa-id">#{{ $task->id }}</span>{{ $task->title }}</a>
                        <span class="wsa-meta">{{ $task->project?->name }} · {{ ($task->comments->last() ?? $task)->created_at->diffForHumans() }}</span>
                        <span class="wsa-item-actions">
                            <a href="{{ \App\Filament\Resources\Tasks\TaskResource::getUrl('view', ['record' => $task]) }}#comments" class="wsa-inline-btn is-strong">{{ __('Answer') }}</a>
                            <button type="button" class="wsa-inline-btn" wire:click="answered({{ $task->id }})">{{ __('I answered') }}</button>
                        </span>
                    </div>
                @endforeach
            </div>
        @endif
        @if ($still->isNotEmpty())
            <div class="wsa-radar-group">
                <span class="wsa-label">{{ __('Deadlines this week, nothing moving for 5 days') }}</span>
                @foreach ($still as $project)
                    <div class="wsa-radar-row" wire:key="rs-{{ $project->id }}">
                        <a href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('view', ['record' => $project]) }}" class="wsa-radar-title">{{ $project->name }}</a>
                        <span class="wsa-item-actions">
                            <button type="button" class="wsa-inline-btn" wire:click="snooze({{ $project->id }})">{{ __('Remind me in 3 days') }}</button>
                        </span>
                    </div>
                @endforeach
            </div>
        @endif
        @if ($unproven->isNotEmpty())
            <div class="wsa-radar-group">
                <span class="wsa-label">{{ __('Done without proof') }}</span>
                @foreach ($unproven as $task)
                    <div class="wsa-radar-row" wire:key="ru-{{ $task->id }}">
                        <a href="{{ \App\Filament\Resources\Tasks\TaskResource::getUrl('view', ['record' => $task]) }}" class="wsa-radar-title"><span class="wsa-id">#{{ $task->id }}</span>{{ $task->title }}</a>
                        <span class="wsa-meta">{{ $task->assignee?->name }} · {{ $task->project?->name }} · <x-wsa.time :at="$task->done_at" /></span>
                        <span class="wsa-item-actions">
                            <a href="{{ \App\Filament\Resources\Tasks\TaskResource::getUrl('view', ['record' => $task]) }}#comments" class="wsa-inline-btn is-strong">{{ __('Ask for the proof') }}</a>
                            <button type="button" class="wsa-inline-btn" wire:click="fine({{ $task->id }})">{{ __('It is fine') }}</button>
                        </span>
                    </div>
                @endforeach
            </div>
        @endif
    </section>
</x-filament-widgets::widget>
