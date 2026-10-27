<x-filament-widgets::widget>
    <section class="wsa-block wsa-latest">
        <header class="wsa-block-head">
            <h2>{{ __('Latest updates') }}</h2>
            <a href="{{ $allUrl }}" class="wsa-link">{{ __('View all') }}<x-wsa.arrow /></a>
        </header>
        @forelse ($updates as $u)
                @php($day = \App\Support\Ago::day($u->created_at))
                @if ($day !== ($lastDay ?? null))<div class="wsa-daybreak">{{ $lastDay = $day }}</div>@endif
            <a href="{{ $viewUrl($u) }}" class="wsa-feed">
                <div class="wsa-feed-text"><x-wsa.new :at="$u->created_at" :by="$u->user_id" />{{ \App\Support\Markdown::inline($u->summary, links: false) }}</div>
                <div class="wsa-feed-meta"><b>{{ $u->user->name }}</b>@if ($u->project) · {{ $u->project->name }}@endif · <x-wsa.time :at="$u->created_at" />
                    @unless (auth()->user()->isClient())
                        @if ($u->acks_count)<span class="wsa-feed-count" title="{{ trans_choice('Seen by :count person|Seen by :count people', $u->acks_count) }}">{{ svg('heroicon-o-eye', '', ['aria-hidden' => 'true']) }}{{ $u->acks_count }}</span>@endif
                        @if ($u->comments_count)<span class="wsa-feed-count" title="{{ trans_choice(':count comment|:count comments', $u->comments_count) }}">{{ svg('heroicon-o-chat-bubble-left', '', ['aria-hidden' => 'true']) }}{{ $u->comments_count }}</span>@endif
                    @endunless
                </div>
            </a>
        @empty
            <p class="wsa-empty">{{ __('No updates yet. They appear here when someone runs /upd in their agent.') }}</p>
        @endforelse
    </section>
</x-filament-widgets::widget>
