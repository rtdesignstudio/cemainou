{{-- Blocajele: sarcinile blocate (spre sarcină: comentarii, cine o face, starea) și blocajele raportate doar ca text (spre update; „Fă din el o sarcină"). --}}
<x-filament-widgets::widget>
    <section class="wsa-block">
        <header class="wsa-block-head">
            <h2>{{ __('Blockers') }}</h2>
            <span>{{ __('blocked tasks and the last 7 days') }}</span>
        </header>
        @foreach ($tasks as $task)
            <a href="{{ \App\Filament\Resources\Tasks\TaskResource::getUrl('view', ['record' => $task]) }}" class="wsa-blocker is-link" wire:key="bt-{{ $task->id }}">
                <p><span class="wsa-id">#{{ $task->id }}</span>{{ $task->title }}</p>
                @if ($task->blocked_reason)<p class="wsa-blocker-reason">{{ __('Waiting for: :reason', ['reason' => $task->blocked_reason]) }}</p>@endif
                <div class="wsa-meta">{{ $task->assignee?->name ?? __('Free') }}@if ($task->project) · {{ $task->project->name }}@endif · <x-wsa.time :at="$task->updated_at" /></div>
            </a>
            {{-- blocată de peste 7 zile: încă e? --}}
            @if ($stale->has($task->id))
                <div class="wsa-item-actions wsa-stale" wire:key="st-{{ $task->id }}">
                    <span class="wsa-meta">{{ trans_choice('{1} Blocked for :count day. Still blocked?|[2,*] Blocked for :count days. Still blocked?', $stale[$task->id]) }}</span>
                    <button type="button" class="wsa-inline-btn" wire:click="stillBlocked({{ $task->id }})">{{ __('Yes, still') }}</button>
                    <button type="button" class="wsa-inline-btn is-strong" wire:click="unblock({{ $task->id }})">{{ __('Unblock it') }}</button>
                    <button type="button" class="wsa-inline-btn" wire:click="drop({{ $task->id }})" wire:confirm="{{ __('Drop #:id? It stays in the history.', ['id' => $task->id]) }}">{{ __('Not needed anymore') }}</button>
                </div>
            @endif
        @endforeach
        @foreach ($lines as $item)
            <div class="wsa-blocker" wire:key="bl-{{ $item->id }}">
                <p><a href="{{ \App\Filament\Resources\Updates\UpdateResource::getUrl('view', ['record' => $item->statusUpdate]) }}">{{ \App\Support\Markdown::inline($item->text, links: false) }}</a></p>
                <div class="wsa-meta">{{ $item->statusUpdate->user->name }}@if ($item->statusUpdate->project) · {{ $item->statusUpdate->project->name }}@endif · <x-wsa.time :at="$item->statusUpdate->created_at" /></div>
                {{-- Rezolvat (iese de aici), Fă sarcină, Comentează --}}
                <livewire:item-actions :item-id="$item->id" :item="$item" :wire:key="'bia-'.$item->id" />
            </div>
        @endforeach
        @if ($tasks->isEmpty() && $lines->isEmpty())
            <p class="wsa-empty">{{ __('Nothing is blocked.') }}</p>
        @endif
    </section>
</x-filament-widgets::widget>
