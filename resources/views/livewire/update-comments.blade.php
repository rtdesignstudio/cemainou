<div @class(['wsa-update-comments', 'is-compact' => $compact]) id="{{ $compact ? 'comments-'.$update->id : 'comments' }}">
    @if ($compact)
        <button type="button" class="wsa-link wsa-comments-toggle" wire:click="toggle" aria-expanded="{{ $open ? 'true' : 'false' }}">
            {{ $total === 0 ? __('Comment') : trans_choice(':count comment|:count comments', $total, ['count' => $total]) }}
        </button>
    @else
        <header class="wsa-block-head">
            <h2>{{ __('Comments') }}</h2>
            <span>{{ $client ? __('only the team sees your comments') : __('the team sees them; a client sees only their own') }}</span>
        </header>
    @endif

    @if ($open)
        @foreach ($comments as $comment)
            <div class="wsa-comment" wire:key="update-comment-{{ $comment->id }}">
                <div class="wsa-meta">
                    <b>{{ $comment->author?->name ?? __('Someone') }}</b>@if ($comment->author?->isClient()) · {{ __('client') }}@endif · <x-wsa.time :at="$comment->created_at" />
                    @if ($comment->source !== \App\Enums\Source::Web) · {{ $comment->source->getLabel() }}@endif
                    @if ($comment->user_id === auth()->id() || auth()->user()->isAdmin())
                        · <button type="button" class="wsa-inline-btn" wire:click="remove({{ $comment->id }})" wire:confirm="{{ __('Delete this comment?') }}">{{ __('Delete') }}</button>
                    @endif
                </div>
                <div class="wsa-prose is-compact">{{ \App\Support\Markdown::html($comment->body, breaks: true) }}</div>
            </div>
        @endforeach

        @can('comment', $update)
            <form wire:submit="add" class="wsa-comment-add">
                {{ $this->form }}
                <x-filament::button type="submit" size="sm" wire:target="add">{{ __('Comment') }}</x-filament::button>
            </form>
        @endcan
    @endif
</div>
