<section class="wsa-block" id="knowledge">
    <header class="wsa-block-head">
        <h2>{{ __('Knowledge') }}</h2>
        <span>{{ __('what colleagues should know') }}</span>
    </header>

    <form wire:submit="add" class="wsa-connect wsa-note-add">
        <input type="text" wire:model="note" maxlength="1000" placeholder="{{ __('E.g. staging is reachable only through the office VPN') }}" aria-label="{{ __('New note') }}" wire:loading.attr="disabled" wire:target="add">
        @error('note') <p class="wsa-error">{{ $message }}</p> @enderror
    </form>

    @forelse ($notes as $note)
        <div class="wsa-note" wire:key="note-{{ $note->id }}">
            <div class="wsa-prose is-compact">{{ \App\Support\Markdown::html($note->body, breaks: true) }}</div>
            <div class="wsa-meta">
                {{ $note->author?->name ?? __('Someone') }} · <x-wsa.time :at="$note->created_at" />
                @can('obsolete', $note)
                    · <button type="button" class="wsa-inline-btn" wire:click="obsolete({{ $note->id }})" wire:loading.attr="disabled" wire:target="obsolete({{ $note->id }})" wire:confirm="{{ __('Mark this note as no longer valid?') }}">{{ __('No longer valid') }}</button>
                @endcan
            </div>
        </div>
    @empty
        <p class="wsa-empty">{{ __('No notes yet. Agents add them at /upd, when you agree; you can add one above.') }}</p>
    @endforelse

    @if ($decisions->isNotEmpty())
        <div class="wsa-group">{{ __('Recent decisions') }}</div>
        @foreach ($decisions as $item)
            <a href="{{ \App\Filament\Resources\Updates\UpdateResource::getUrl('view', ['record' => $item->update_id]) }}" class="wsa-note wsa-note-link">
                <p class="wsa-note-text">{{ \App\Support\Markdown::inline($item->text, links: false) }}</p>
                <div class="wsa-meta">{{ $item->statusUpdate->user?->name }} · <x-wsa.time :at="$item->statusUpdate->created_at" /></div>
            </a>
        @endforeach
    @endif
</section>
