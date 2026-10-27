<section class="wsa-block wsa-comments" id="comments">
    <header class="wsa-block-head">
        <h2>{{ __('Comments') }}</h2>
        <span>{{ trans_choice(':count comment|:count comments', $comments->count(), ['count' => $comments->count()]) }}</span>
    </header>

    @foreach ($comments as $comment)
        <div class="wsa-comment" wire:key="comment-{{ $comment->id }}">
            <div class="wsa-meta">
                <b>{{ $comment->author?->name ?? __('Someone') }}</b>@if ($comment->author?->isClient()) · {{ __('client') }}@endif · <x-wsa.time :at="$comment->created_at" />
                @if ($comment->user_id === auth()->id())
                    · <button type="button" class="wsa-inline-btn" wire:click="remove({{ $comment->id }})" wire:confirm="{{ __('Delete this comment?') }}">{{ __('Delete') }}</button>
                @endif
            </div>
            <div class="wsa-prose is-compact">{{ \App\Support\Markdown::html($comment->body, breaks: true) }}</div>
        </div>
    @endforeach

    @can('comment', $task)
        <form wire:submit="add" class="wsa-comment-add">
            {{ $this->form }}
            <x-filament::button type="submit" size="sm" wire:target="add">{{ __('Comment') }}</x-filament::button>
        </form>
    @endcan
</section>
