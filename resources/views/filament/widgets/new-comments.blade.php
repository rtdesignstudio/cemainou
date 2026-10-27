{{-- Azi → Comentarii noi pentru mine: pe sarcinile și update-urile mele, din ultimele 7 zile. --}}
<x-filament-widgets::widget>
    <section class="wsa-block">
        <header class="wsa-block-head"><h2>{{ __('New comments for me') }}</h2></header>
        @forelse ($comments as $comment)
            @php
                $onTask = $comment instanceof \App\Models\TaskComment;
                $url = $onTask ? \App\Filament\Resources\Tasks\TaskResource::getUrl('view', ['record' => $comment->task]) : \App\Filament\Resources\Updates\UpdateResource::getUrl('view', ['record' => $comment->statusUpdate]).'#comments';
            @endphp
            <a href="{{ $url }}" class="wsa-comment-row">
                <span class="wsa-comment-who">{{ $comment->author?->name ?? __('Someone') }} <span class="wsa-meta">· <x-wsa.time :at="$comment->created_at" /></span></span>
                <span class="wsa-comment-text">{{ \Illuminate\Support\Str::limit(\App\Support\Markdown::plain($comment->body), 140) }}</span>
                <span class="wsa-meta">{{ $onTask ? '#'.$comment->task->id.' '.$comment->task->title : __('On your update').($comment->statusUpdate->project ? ' · '.$comment->statusUpdate->project->name : '') }}</span>
            </a>
        @empty
            <p class="wsa-empty">{{ __('No new comments on your tasks or updates this week.') }}</p>
        @endforelse
    </section>
</x-filament-widgets::widget>
