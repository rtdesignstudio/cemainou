<x-filament-panels::page>
    {{-- Notificările: banda, filtrele (necitite / toate, felul, căutarea), lista pe zile; jos, ce primești --}}
    @php
        $icons = ['tasks' => 'heroicon-o-arrow-down-tray', 'done' => 'heroicon-o-check', 'comments' => 'heroicon-o-chat-bubble-left', 'mentions' => 'heroicon-o-at-symbol', 'morning' => 'heroicon-o-sun', 'feedback' => 'heroicon-o-megaphone'];
    @endphp
    <x-wsa.stats>
        <x-wsa.stat :label="__('Unread')">{{ $unread }}</x-wsa.stat>
        <x-wsa.stat :label="__('In the last :days days', ['days' => \App\Filament\Pages\Notifications::KEEP_DAYS])">{{ $total }}</x-wsa.stat>
    </x-wsa.stats>

    <div class="wsa-toolbar wsa-feed-bar">
        <nav class="wsa-tabs" aria-label="{{ __('Show') }}">
            <button type="button" wire:click="$set('show', 'all')" @class(['is-on' => $this->show === 'all'])>{{ __('All') }}</button>
            <button type="button" wire:click="$set('show', 'unread')" @class(['is-on' => $this->show === 'unread'])>{{ __('Unread') }}@if ($unread)<span class="wsa-tab-n">{{ $unread }}</span>@endif</button>
        </nav>
        <div class="wsa-toolbar-tools">
            <label class="wsa-cmds-search">
                <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedMagnifyingGlass" class="wsa-cmds-search-icon" />
                <input type="search" wire:model.live.debounce.300ms="find" placeholder="{{ __('Search the notifications') }}" aria-label="{{ __('Search') }}">
            </label>
            <select wire:model.live="type" class="wsa-portfolio-select" aria-label="{{ __('Kind') }}">
                <option value="">{{ __('All kinds') }}</option>
                @foreach ($types as $key => $t)<option value="{{ $key }}">{{ $t['label'] }}</option>@endforeach
            </select>
            @if ($unread)
                <button type="button" class="wsa-link-strong" wire:click="markAllRead">{{ __('Mark all read') }}</button>
            @endif
            <a href="{{ filament()->getProfileUrl() }}#notifications" class="wsa-meta">{{ __('What I receive') }}</a>
        </div>
    </div>

    @forelse ($days as $date => $items)
        <section class="wsa-feed-day" wire:key="nday-{{ $date }}">
            <header class="wsa-feed-dayhead"><h2>{{ $dayLabel($date) }}</h2><span class="wsa-meta">{{ trans_choice(':count notification|:count notifications', $items->count()) }}</span></header>
            @foreach ($items as $n)
                @php
                    $type = \App\Filament\Pages\Notifications::typeOf($n);
                    $unreadOne = $n->read_at === null;
                @endphp
                <article @class(['wsa-notice', 'is-unread' => $unreadOne]) wire:key="n-{{ $n->id }}">
                    <x-filament::icon :icon="$icons[$type] ?? 'heroicon-o-bell'" class="wsa-notice-icon" />
                    <button type="button" class="wsa-notice-main" wire:click="open('{{ $n->id }}')">
                        <b>{{ $n->data['title'] ?? '' }}</b>
                        @if (filled($n->data['body'] ?? null))<span class="wsa-notice-body">{{ $n->data['body'] }}</span>@endif
                        <span class="wsa-meta">{{ $types[$type]['label'] ?? '' }} · <x-wsa.time :at="$n->created_at" /></span>
                    </button>
                    @if ($unreadOne)
                        <button type="button" class="wsa-notice-dot" wire:click="markRead('{{ $n->id }}')" title="{{ __('Mark read') }}"><span class="sr-only">{{ __('Mark read') }}</span></button>
                    @else
                        <button type="button" class="wsa-notice-dot is-read" wire:click="markUnread('{{ $n->id }}')" title="{{ __('Mark unread') }}"><span class="sr-only">{{ __('Mark unread') }}</span></button>
                    @endif
                </article>
            @endforeach
        </section>
    @empty
        <p class="wsa-empty">{{ $this->show === 'unread' ? __('Nothing unread. You are up to date.') : (trim($this->find) !== '' || $this->type !== '' ? __('No notification matches.') : __('No notifications yet.')) }}</p>
    @endforelse

    @if ($more)
        <div class="wsa-more-auto" wire:key="nmore-{{ $this->limit }}" x-data x-intersect.margin.600px.once="$wire.more()">
            <span class="wsa-meta" wire:loading wire:target="more">{{ __('Loading older ones…') }}</span>
        </div>
    @endif

</x-filament-panels::page>
